<?php
/**
 * Default category for post-kind content.
 *
 * @package PostKindsForIndieWeb
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a site-configured default category to any post that carries a post
 * kind. The setting (Post Kinds → General → Default category) is empty by
 * default, so the behaviour is opt-in.
 *
 * The category is applied once, on `wp_after_insert_post` — the same hook the
 * kind taxonomy is synced on, so the kind is guaranteed to be present, and it
 * fires for the editor, the REST API, and Micropub (so Outpost's kind content
 * inherits the default without Outpost needing its own setting). A per-post
 * marker means it applies as a default, not a lock: remove it from a post and
 * it stays removed.
 *
 * @since 1.3.0
 */
class Default_Category {

	/** Settings key (inside the `post_kinds_indieweb_settings` option) holding the term id. */
	public const SETTING_KEY = 'default_category';

	/** Per-post marker set once the default has been applied. */
	public const APPLIED_META = '_pkiw_default_category_applied';

	/**
	 * Hook the apply pass. Priority 20 runs after Taxonomy::sync_kind_from_first_block
	 * (priority 10 on the same hook), so the post's kind term is already set.
	 */
	public function __construct() {
		add_action( 'wp_after_insert_post', [ $this, 'maybe_apply' ], 20, 2 );
	}

	/**
	 * Apply the default category to a freshly saved post when appropriate.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post   Post object.
	 */
	public function maybe_apply( int $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// Categories only attach to post types that support them.
		if ( ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
			return;
		}
		$this->apply_to_post( $post_id );
	}

	/**
	 * Add the configured default category to a post if it carries a kind and
	 * hasn't already been processed.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Ignore the per-post applied marker (used by backfill for
	 *                      pre-feature posts). Manual removals still win because the
	 *                      marker is checked before this is called in backfill().
	 * @return bool True when the category was added.
	 */
	public function apply_to_post( int $post_id, bool $force = false ): bool {
		$configured = self::configured_category_id();
		if ( $configured <= 0 ) {
			return false;
		}

		$kinds = get_the_terms( $post_id, 'kind' );
		if ( empty( $kinds ) || is_wp_error( $kinds ) ) {
			return false;
		}

		if ( ! $force && get_post_meta( $post_id, self::APPLIED_META, true ) ) {
			return false;
		}

		/**
		 * Filter the default category term id applied to a kind post.
		 *
		 * Return 0 (or a non-positive value) to skip this post entirely, or a
		 * different term id to override the configured default per post.
		 *
		 * @param int $configured Configured default category term id.
		 * @param int $post_id     Post being processed.
		 */
		$term_id = (int) apply_filters( 'pkiw_default_category', $configured, $post_id );
		if ( $term_id <= 0 ) {
			return false;
		}

		// Append — never clobber categories the author already set.
		$result = wp_set_object_terms( $post_id, [ $term_id ], 'category', true );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		update_post_meta( $post_id, self::APPLIED_META, 1 );
		return true;
	}

	/**
	 * The configured default category term id, or 0 when unset.
	 */
	public static function configured_category_id(): int {
		$settings = get_option( 'post_kinds_indieweb_settings', [] );
		if ( ! is_array( $settings ) || ! isset( $settings[ self::SETTING_KEY ] ) ) {
			return 0;
		}
		return max( 0, (int) $settings[ self::SETTING_KEY ] );
	}

	/**
	 * Apply the default category to every existing kind-bearing post that
	 * hasn't been processed. Skips posts already marked (so deliberate removals
	 * stay removed). Idempotent; safe to re-run.
	 *
	 * @param bool $dry_run When true, count what would change without writing.
	 * @return array{scanned:int,updated:int,skipped:int,would_update:int}
	 */
	public function backfill( bool $dry_run = false ): array {
		$stats = [
			'scanned'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'would_update' => 0,
		];

		if ( self::configured_category_id() <= 0 ) {
			return $stats;
		}

		$paged = 1;
		do {
			$query = new \WP_Query(
				[
					'post_type'           => 'any',
					'post_status'         => 'any',
					'posts_per_page'      => 100,
					'paged'               => $paged,
					'fields'              => 'ids',
					'ignore_sticky_posts' => true,
					'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						[
							'taxonomy' => 'kind',
							'operator' => 'EXISTS',
						],
					],
				]
			);

			foreach ( $query->posts as $post_id ) {
				$post_id = (int) $post_id;
				++$stats['scanned'];

				$post = get_post( $post_id );
				if ( ! $post instanceof \WP_Post || ! is_object_in_taxonomy( $post->post_type, 'category' ) ) {
					++$stats['skipped'];
					continue;
				}
				if ( get_post_meta( $post_id, self::APPLIED_META, true ) ) {
					++$stats['skipped'];
					continue;
				}
				if ( $dry_run ) {
					++$stats['would_update'];
					continue;
				}
				if ( $this->apply_to_post( $post_id ) ) {
					++$stats['updated'];
				} else {
					++$stats['skipped'];
				}
			}

			++$paged;
		} while ( $paged <= (int) $query->max_num_pages );

		return $stats;
	}
}

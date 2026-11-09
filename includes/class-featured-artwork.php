<?php
/**
 * Featured image from kind artwork.
 *
 * @package PKIW
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets a kind post's featured image from its representative artwork
 * (album cover, movie poster, book cover, game art, checkin photo)
 * when the post has none. Remote artwork is sideloaded into the media
 * library once per URL; artwork that already resolves to a local
 * attachment is reused without any network request.
 *
 * Ownership rules keep editorial choice in charge:
 *
 * - A featured image this class did not set is never replaced.
 * - Removing the auto-set image is respected — the same artwork URL is
 *   never re-applied (the attempt marker survives the removal).
 * - A failed fetch is not retried on every save; a new artwork URL
 *   resets the attempt.
 * - Disable entirely with `add_filter( 'pkiw_set_featured_from_artwork',
 *   '__return_false' )`.
 *
 * @since 1.3.0
 */
class Featured_Artwork {

	/**
	 * Meta key recording the last artwork URL attempted (set on both
	 * success and failure — it's the "don't repeat yourself" marker).
	 */
	public const SOURCE_META = '_pkiw_featured_artwork_source';

	/**
	 * Meta key recording the attachment ID this class set as the
	 * featured image (the ownership marker: only this attachment is
	 * ever replaced when artwork changes).
	 */
	public const ATTACHMENT_META = '_pkiw_featured_artwork_attachment';

	/** Retry state for failed guarded-artwork sideloads. */
	public const RETRY_META = '_pkiw_featured_artwork_retry';

	/** Prefix for the atomic per-post retry lock. */
	public const RETRY_LOCK_PREFIX = 'pkiw_artwork_retry_lock_';

	/** Cron hook for one scheduled guarded-artwork retry. */
	public const RETRY_HOOK = 'pkiw_retry_featured_artwork';

	/**
	 * Constructor.
	 *
	 * Hooked after Card_Meta_Sync (25) and Book_Completion (30) so the
	 * normalized meta this reads is current for the same save.
	 */
	public function __construct() {
		add_action( 'save_post', [ $this, 'maybe_set_featured' ], 35, 2 );
		add_action( self::RETRY_HOOK, [ self::class, 'run_retry' ], 10, 1 );
		add_action( 'wp_trash_post', [ self::class, 'clear_retry' ] );
		add_action( 'before_delete_post', [ self::class, 'clear_retry' ] );
	}

	/**
	 * Set the featured image from kind artwork when appropriate.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function maybe_set_featured( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}

		// Sideloading mints media library attachments, so a user-context
		// save requires the upload_files capability (a contributor can
		// edit their own drafts but must not create attachments this
		// way). System contexts — cron, WP-CLI — carry no user and are
		// deliberate operator actions. Checked before any state is
		// written so a blocked save leaves no attempt marker and a
		// capable user's later save still applies the artwork.
		if ( get_current_user_id() > 0 && ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$url   = self::artwork_url( $post_id );
		$retry = get_post_meta( $post_id, self::RETRY_META, true );
		if ( is_array( $retry ) && (string) ( $retry['url'] ?? '' ) !== (string) $url ) {
			self::clear_retry( $post_id );
		}
		if ( null === $url ) {
			return; // No real artwork — never invent or remove anything.
		}
		/**
		 * Filters whether kind artwork may become the featured image.
		 *
		 * @since 1.3.0
		 *
		 * @param bool   $enabled Default true.
		 * @param int    $post_id Post ID.
		 * @param string $url     Resolved artwork URL.
		 */
		if ( ! apply_filters( 'pkiw_set_featured_from_artwork', true, $post_id, $url ) ) {
			return;
		}

		$attempted      = (string) get_post_meta( $post_id, self::SOURCE_META, true );
		$our_attachment = (int) get_post_meta( $post_id, self::ATTACHMENT_META, true );
		$thumbnail_id   = (int) get_post_thumbnail_id( $post_id );

		if ( $thumbnail_id > 0 ) {
			// Replace only an image we set ourselves, and only because
			// the artwork actually changed.
			if ( $thumbnail_id !== $our_attachment || $url === $attempted ) {
				return;
			}
		} elseif ( $url === $attempted ) {
			// Same URL as the last attempt: either the fetch failed (don't
			// hammer it on every save) or the user removed the image we
			// set (respect the removal).
			return;
		}

		self::apply( $post_id, $url );
	}

	/**
	 * Apply an artwork URL as the featured image.
	 *
	 * Records the attempt first so a failing URL is only fetched once,
	 * reuses an existing local attachment when the URL is our own, and
	 * sideloads otherwise.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Validated artwork URL.
	 * @param bool   $schedule Whether a failed guarded sideload should schedule a retry.
	 * @return int|\WP_Error Attachment ID, or the sideload error.
	 */
	public static function apply( int $post_id, string $url, bool $schedule = true ) {
		update_post_meta( $post_id, self::SOURCE_META, $url );

		$attachment_id = attachment_url_to_postid( $url );
		if ( 0 === $attachment_id ) {
			$attachment_id = self::sideload( $post_id, $url );
		}

		if ( is_wp_error( $attachment_id ) ) {
			delete_post_meta( $post_id, self::ATTACHMENT_META );
			if ( $schedule && self::is_guarded_sideload( $post_id ) ) {
				self::schedule_retry( $post_id, $url, $attachment_id );
			}
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, self::ATTACHMENT_META, $attachment_id );

		// Give the artwork a usable alt if it has none — the post title
		// is the entity name for every kind card ("American Obituary"),
		// but the bare title alone doesn't say what the image shows.
		if ( '' === (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta(
				$attachment_id,
				'_wp_attachment_image_alt',
				sprintf(
					/* translators: %s: post title */
					__( 'Poster for %s', 'post-kinds-for-indieweb-in-block-themes' ),
					get_the_title( $post_id )
				)
			);
		}

		if ( self::is_guarded_sideload( $post_id ) ) {
			self::clear_retry( $post_id );
		}

		return $attachment_id;
	}

	/**
	 * Sideload a remote artwork URL into the media library.
	 *
	 * @param int    $post_id Post the attachment is for.
	 * @param string $url     Remote image URL.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	private static function sideload( int $post_id, string $url ) {
		// save_post can fire on the front end (Micropub, REST); the
		// sideload stack lives in admin includes.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$kind = self::guarded_artwork_kind( $post_id );
		if ( '' === $kind ) {
			return media_sideload_image( $url, $post_id, get_the_title( $post_id ), 'id' );
		}

		$check = Artwork_Fetch_Guard::check( $url, $kind );
		if ( ! $check['ok'] ) {
			return new \WP_Error(
				$check['code'],
				__( 'The artwork could not be fetched safely.', 'post-kinds-for-indieweb-in-block-themes' )
			);
		}

		return Artwork_Fetch_Guard::with_pinned_request(
			$url,
			$check['ip'],
			static fn() => media_sideload_image( $url, $post_id, get_the_title( $post_id ), 'id' )
		);
	}

	/**
	 * Whether a post is a watch whose poster setting is Sideload.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_watch_sideload( int $post_id ): bool {
		if ( ! self::is_watch_post( $post_id ) ) {
			return false;
		}

		return self::uses_sideload_setting();
	}

	/**
	 * Whether a guarded watch or Metron comic uses Sideload.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function is_guarded_sideload( int $post_id ): bool {
		if ( '' === self::guarded_artwork_kind( $post_id ) ) {
			return false;
		}

		return self::uses_sideload_setting();
	}

	/**
	 * Whether Image Handling resolves to Sideload.
	 */
	private static function uses_sideload_setting(): bool {

		$settings = get_option( 'pkiw_settings', [] );
		$handling = is_array( $settings ) ? (string) ( $settings['image_handling'] ?? 'sideload' ) : 'sideload';

		return ! in_array( $handling, [ 'hotlink', 'none' ], true );
	}

	/**
	 * Resolve artwork without letting core DNS pre-empt the fetch guard.
	 *
	 * Other kinds retain Kind_Artwork's existing validation. Saved watch and
	 * digit-tagged Metron comic cards are authoritative, so removing their
	 * artwork clears stale retry state; cardless watch posts use normalized meta.
	 *
	 * @internal
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Artwork URL.
	 */
	public static function artwork_url( int $post_id ): ?string {
		$kind = self::artwork_kind( $post_id );
		if ( ! in_array( $kind, [ 'watch', 'comics' ], true ) ) {
			return Kind_Artwork::get_representative_image_url( $post_id );
		}
		if ( 'comics' === $kind && ! self::comic_has_metron_id( $post_id ) ) {
			return null;
		}

		$post       = get_post( $post_id );
		$block_name = 'watch' === $kind ? 'post-kinds-indieweb/watch-card' : 'post-kinds-indieweb/comic-card';
		$attribute  = 'watch' === $kind ? 'posterImage' : 'coverImage';
		$meta_key   = 'watch' === $kind ? 'watch_poster' : 'comic_cover';
		if ( $post instanceof \WP_Post && has_block( $block_name, $post ) ) {
			$url = (string) self::artwork_from_blocks( parse_blocks( $post->post_content ), $block_name, $attribute );
		} else {
			$url = (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $meta_key, true );
		}

		$url    = esc_url_raw( trim( $url ), [ 'http', 'https' ] );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

		return '' !== $url && '' !== $host && in_array( $scheme, [ 'http', 'https' ], true ) ? $url : null;
	}

	/**
	 * Whether the post is watch, including its first save before auto-kind assignment.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function is_watch_post( int $post_id ): bool {
		return 'watch' === self::artwork_kind( $post_id );
	}

	/**
	 * Resolve the guarded artwork kind, including the first save before kind sync.
	 *
	 * @param int $post_id Post ID.
	 * @return string `watch`, `comics`, or an empty string.
	 */
	private static function artwork_kind( int $post_id ): string {
		$terms = get_the_terms( $post_id, Taxonomy::TAXONOMY );
		if ( is_array( $terms ) && [] !== $terms ) {
			if ( in_array( $terms[0]->slug, [ 'watch', 'comics' ], true ) ) {
				return $terms[0]->slug;
			}
			if ( (string) get_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, true ) !== $terms[0]->slug ) {
				return '';
			}
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		if ( has_block( 'post-kinds-indieweb/watch-card', $post ) ) {
			return 'watch';
		}
		if ( has_block( 'post-kinds-indieweb/comic-card', $post ) ) {
			return 'comics';
		}

		return '';
	}

	/**
	 * Kind accepted by the guard and retry pipeline.
	 *
	 * @param int $post_id Post ID.
	 * @return string `watch`, `comics`, or an empty string.
	 */
	private static function guarded_artwork_kind( int $post_id ): string {
		$kind = self::artwork_kind( $post_id );
		if ( 'comics' === $kind && ! self::comic_has_metron_id( $post_id ) ) {
			return '';
		}

		return in_array( $kind, [ 'watch', 'comics' ], true ) ? $kind : '';
	}

	/**
	 * Whether the first comic card carries a digit-only Metron ID.
	 *
	 * @internal
	 *
	 * @param int $post_id Post ID.
	 */
	public static function comic_has_metron_id( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! has_block( 'post-kinds-indieweb/comic-card', $post ) ) {
			return false;
		}

		$id = (string) self::artwork_from_blocks( parse_blocks( $post->post_content ), 'post-kinds-indieweb/comic-card', 'metronId' );

		return 1 === preg_match( '/^[0-9]+$/', $id );
	}

	/**
	 * Find the first stored artwork attribute on a named card.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param string                         $block_name Card block name.
	 * @param string                         $attribute  Attribute name.
	 * @return string|null Attribute value, or null when no matching card exists.
	 */
	private static function artwork_from_blocks( array $blocks, string $block_name, string $attribute ): ?string {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $block_name ) {
				return is_string( $block['attrs'][ $attribute ] ?? null ) ? $block['attrs'][ $attribute ] : '';
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$value = self::artwork_from_blocks( $block['innerBlocks'], $block_name, $attribute );
				if ( null !== $value ) {
					return $value;
				}
			}
		}

		return null;
	}

	/**
	 * Record a failed fetch and schedule its next retry when appropriate.
	 *
	 * @param int       $post_id Post ID.
	 * @param string    $url     Failed poster URL.
	 * @param \WP_Error $error   Fetch error.
	 */
	private static function schedule_retry( int $post_id, string $url, \WP_Error $error ): void {
		$stored   = get_post_meta( $post_id, self::RETRY_META, true );
		$attempts = is_array( $stored ) && (string) ( $stored['url'] ?? '' ) === $url
			? (int) ( $stored['attempts'] ?? 0 ) + 1
			: 1;
		$code     = (string) $error->get_error_code();
		$state    = self::is_retryable( $error ) ? 'scheduled' : 'permanent';
		$next     = 0;

		wp_clear_scheduled_hook( self::RETRY_HOOK, [ $post_id ] );
		if ( 'scheduled' === $state && $attempts >= 4 ) {
			$state = 'exhausted';
		} elseif ( 'scheduled' === $state ) {
			$delays = [
				1 => HOUR_IN_SECONDS,
				2 => 6 * HOUR_IN_SECONDS,
				3 => DAY_IN_SECONDS,
			];
			$next   = time() + $delays[ $attempts ];
			wp_schedule_single_event( $next, self::RETRY_HOOK, [ $post_id ] );
		}

		update_post_meta(
			$post_id,
			self::RETRY_META,
			[
				'url'      => $url,
				'attempts' => min( 4, $attempts ),
				'next'     => $next,
				'state'    => $state,
				'code'     => $code,
			]
		);
	}

	/**
	 * Whether a fetch error can succeed later.
	 *
	 * @param \WP_Error $error Fetch error.
	 */
	private static function is_retryable( \WP_Error $error ): bool {
		$code = (string) $error->get_error_code();
		if ( in_array( $code, [ 'http_request_failed', 'pkiw_artwork_unresolved' ], true ) ) {
			return true;
		}
		if ( 'http_404' !== $code ) {
			return false;
		}

		$data   = $error->get_error_data( 'http_404' );
		$status = is_array( $data ) ? (int) ( $data['code'] ?? 0 ) : 0;

		return 429 === $status || ( $status >= 500 && $status <= 599 );
	}

	/**
	 * Clear retry state, its lock and its scheduled event.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function clear_retry( int $post_id ): void {
		global $wpdb;

		if ( ! metadata_exists( 'post', $post_id, self::RETRY_META ) ) {
			$has_lock = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retry locks intentionally bypass the options cache.
				$wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name = %s", self::lock_name( $post_id ) )
			);
			if ( null === $has_lock ) {
				return;
			}
		}

		delete_post_meta( $post_id, self::RETRY_META );
		wp_clear_scheduled_hook( self::RETRY_HOOK, [ $post_id ] );
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retry locks intentionally bypass the options cache.
			$wpdb->options,
			[ 'option_name' => self::lock_name( $post_id ) ],
			[ '%s' ]
		);
	}

	/**
	 * Run one scheduled retry.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function run_retry( int $post_id ): void {
		$retry = get_post_meta( $post_id, self::RETRY_META, true );
		if ( ! is_array( $retry ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post
			|| 'post' !== $post->post_type
			|| in_array( $post->post_status, [ 'trash', 'auto-draft' ], true )
			|| ! self::is_guarded_sideload( $post_id )
			|| get_post_thumbnail_id( $post_id ) > 0 ) {
			self::clear_retry( $post_id );
			return;
		}

		$url = self::artwork_url( $post_id );
		if ( ! is_string( $url )
			|| ! apply_filters( 'pkiw_set_featured_from_artwork', true, $post_id, $url )
			|| (string) ( $retry['url'] ?? '' ) !== $url ) {
			self::clear_retry( $post_id );
			return;
		}

		if ( ! self::retry_is_due( $retry ) ) {
			return;
		}

		$token = self::acquire_retry_lock( $post_id );
		if ( null === $token ) {
			return;
		}

		try {
			wp_cache_delete( $post_id, 'post_meta' );
			if ( get_post_thumbnail_id( $post_id ) > 0 ) {
				self::clear_retry( $post_id );
				return;
			}
			$retry = get_post_meta( $post_id, self::RETRY_META, true );
			if ( ! is_array( $retry ) || ! self::retry_is_due( $retry ) ) {
				return;
			}
			self::apply( $post_id, (string) $retry['url'], true );
		} finally {
			self::release_retry_lock( $post_id, $token );
		}
	}

	/**
	 * Take the atomic retry lock, including stale-lock takeover.
	 *
	 * @internal
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Lock token, or null when another run owns it.
	 */
	public static function acquire_retry_lock( int $post_id ): ?string {
		global $wpdb;

		$name  = self::lock_name( $post_id );
		$token = time() . ':' . wp_generate_password( 12, false );
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT IGNORE on option_name is the atomic lock.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$name,
				$token
			)
		);
		if ( 1 === $rows ) {
			return $token;
		}

		$stored = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retry locks intentionally bypass the options cache.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
		);
		if ( ! is_string( $stored ) || (int) strtok( $stored, ':' ) >= time() - 300 ) {
			return null;
		}

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Token comparison makes stale-lock takeover atomic.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$token,
				$name,
				$stored
			)
		);

		return 1 === $rows ? $token : null;
	}

	/**
	 * Release only the lock token this run acquired.
	 *
	 * @internal
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Owned lock token.
	 */
	public static function release_retry_lock( int $post_id, string $token ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retry locks intentionally bypass the options cache.
			$wpdb->options,
			[
				'option_name'  => self::lock_name( $post_id ),
				'option_value' => $token,
			],
			[ '%s', '%s' ]
		);
	}

	/**
	 * Whether retry state represents a due scheduled attempt.
	 *
	 * @param array<string,mixed> $retry Retry state.
	 */
	private static function retry_is_due( array $retry ): bool {
		return 'scheduled' === (string) ( $retry['state'] ?? '' )
			&& (int) ( $retry['next'] ?? 0 ) <= time() + 60;
	}

	/**
	 * Lock option name for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function lock_name( int $post_id ): string {
		return self::RETRY_LOCK_PREFIX . $post_id;
	}

	/**
	 * Backfill featured images for existing kind posts.
	 *
	 * Skips posts that already have a featured image and posts whose
	 * artwork URL was already attempted (so removals stay removed and
	 * dead URLs aren't re-fetched).
	 *
	 * @param bool $dry_run When true, count without writing.
	 * @return array{scanned: int, updated: int, skipped: int, failed: int} Stats.
	 */
	public function backfill( bool $dry_run = false ): array {
		$stats = [
			'scanned' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		];

		$post_ids = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One-shot CLI backfill, not a front-end query.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => array_values( array_unique( array_merge( array_keys( Kind_Artwork::KIND_IMAGE_META ), [ 'comics' ] ) ) ),
					],
				],
			]
		);

		foreach ( $post_ids as $post_id ) {
			++$stats['scanned'];

			$url = in_array( self::artwork_kind( $post_id ), [ 'watch', 'comics' ], true )
				? self::artwork_url( $post_id )
				: Kind_Artwork::get_representative_image_url( $post_id );
			if ( null === $url
				|| get_post_thumbnail_id( $post_id ) > 0
				|| (string) get_post_meta( $post_id, self::SOURCE_META, true ) === $url
				|| ! apply_filters( 'pkiw_set_featured_from_artwork', true, $post_id, $url ) ) {
				++$stats['skipped'];
				continue;
			}

			if ( $dry_run ) {
				++$stats['updated'];
				continue;
			}

			$result = self::apply( $post_id, $url, false );
			if ( is_wp_error( $result ) ) {
				++$stats['failed'];
			} else {
				++$stats['updated'];
			}
		}

		return $stats;
	}
}

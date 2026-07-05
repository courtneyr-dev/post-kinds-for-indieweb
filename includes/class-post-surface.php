<?php
/**
 * Post Surface classifier.
 *
 * Classifies a post as belonging to the ephemeral "stream" surface or the
 * "main" surface, based on a site-configurable set of kinds and a per-post
 * promote override. This class only produces the signal; it never filters a
 * site's queries.
 *
 * @package PostKindsForIndieWeb
 * @since 1.3.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes and caches the surface ('stream' | 'main') for a post.
 */
final class Post_Surface {

	public const STREAM = 'stream';
	public const MAIN   = 'main';

	/**
	 * Wire meta registration and the save-time cache.
	 *
	 * @return void
	 */
	public function register(): void {
		// Components are wired during the plugin's own init pass, so init may
		// already be running/done — register meta directly in that case,
		// otherwise defer to init for callers that wire up earlier.
		if ( did_action( 'init' ) ) {
			$this->register_meta();
		} else {
			add_action( 'init', [ $this, 'register_meta' ] );
		}
		add_action( 'save_post', [ $this, 'on_save' ], 20, 1 );
	}

	/**
	 * Register the promote override meta and the derived surface meta.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		register_post_meta(
			'post',
			'pkiw_promote',
			[
				'type'          => 'boolean',
				'single'        => true,
				'default'       => false,
				'show_in_rest'  => true,
				'auth_callback' => static fn() => current_user_can( 'edit_posts' ),
			]
		);
		register_post_meta(
			'post',
			'_pkiw_surface',
			[
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => '__return_false',
			]
		);
	}

	/**
	 * Recompute and cache the surface when a post is saved.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_pkiw_surface', self::get( $post_id ) );
	}

	/**
	 * Compute the surface for a post.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 * @return string Post_Surface::STREAM or Post_Surface::MAIN.
	 */
	public static function get( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return self::MAIN;
		}

		/**
		 * Filters the set of kind slugs treated as the ephemeral "stream" surface.
		 *
		 * @since 1.3.0
		 *
		 * @param string[] $stream_kinds Kind slugs. Default empty (opt-in).
		 */
		$stream_kinds = (array) apply_filters( 'pkiw_stream_kinds', [] );

		$surface = self::MAIN;

		if ( ! empty( $stream_kinds ) && ! self::is_promoted( $post->ID ) ) {
			$kinds = wp_get_object_terms( $post->ID, 'kind', [ 'fields' => 'slugs' ] );
			if ( ! is_wp_error( $kinds ) && array_intersect( $kinds, $stream_kinds ) ) {
				$surface = self::STREAM;
			}
		}

		/**
		 * Filters the computed surface for a post.
		 *
		 * @since 1.3.0
		 *
		 * @param string   $surface 'stream' or 'main'.
		 * @param \WP_Post $post    The post being classified.
		 */
		return (string) apply_filters( 'pkiw_post_surface', $surface, $post );
	}

	/**
	 * Recompute and cache the surface for every post.
	 *
	 * @return int Number of posts processed.
	 */
	public static function backfill(): int {
		$ids = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, '_pkiw_surface', self::get( (int) $id ) );
		}
		return count( $ids );
	}

	/**
	 * Whether a post is explicitly promoted to the main surface.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_promoted( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, 'pkiw_promote', true );
	}
}

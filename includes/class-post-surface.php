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
		$stream_kinds = (array) apply_filters( 'pk_stream_kinds', [] );

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
		return (string) apply_filters( 'pk_post_surface', $surface, $post );
	}

	/**
	 * Whether a post is explicitly promoted to the main surface.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_promoted( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, 'pk_promote', true );
	}
}

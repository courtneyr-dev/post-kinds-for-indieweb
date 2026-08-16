<?php
/**
 * Kind artwork resolution.
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
 * Resolves a kind post's representative media (album cover, movie
 * poster, book cover, game art, checkin photo) from normalized post
 * meta, with a block-attribute fallback for posts saved before
 * Card_Meta_Sync covered their kind. Shared by the Yoast schema
 * integration and the featured-artwork behavior so both expose the
 * same image for the same post.
 *
 * @since 1.3.0
 */
final class Kind_Artwork {

	/**
	 * Kind slug => meta suffix (appended to Meta_Fields::PREFIX) that
	 * stores the kind's representative image URL.
	 *
	 * Only kinds whose card defines real entity media belong here; a
	 * kind without an entry truthfully contributes no image.
	 *
	 * @var array<string, string>
	 */
	public const KIND_IMAGE_META = [
		'listen'  => 'listen_cover',
		'watch'   => 'watch_poster',
		'read'    => 'read_cover',
		'jam'     => 'jam_cover',
		'play'    => 'play_cover',
		'checkin' => 'checkin_photo',
	];

	/**
	 * Card block name => attribute holding the representative image
	 * URL. Fallback for posts saved before Card_Meta_Sync mirrored
	 * these blocks into meta (the attrs are the source the sync reads,
	 * so both paths yield the same value).
	 *
	 * @var array<string, string>
	 */
	public const BLOCK_IMAGE_ATTR = [
		'post-kinds-indieweb/listen-card'  => 'coverImage',
		'post-kinds-indieweb/watch-card'   => 'posterImage',
		'post-kinds-indieweb/read-card'    => 'coverImage',
		'post-kinds-indieweb/jam-card'     => 'cover',
		'post-kinds-indieweb/play-card'    => 'cover',
		'post-kinds-indieweb/checkin-card' => 'photo',
	];

	/**
	 * Not instantiable — static resolver only.
	 */
	private function __construct() {
	}

	/**
	 * Resolve the representative image URL for a kind post.
	 *
	 * Normalized post meta first (written by Card_Meta_Sync on save);
	 * falls back to the first kind card block's image attribute for
	 * posts saved before the sync covered their block. Returns null
	 * when the post's kind defines no representative media or the
	 * stored value isn't a usable http(s) URL.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Validated image URL, or null.
	 */
	public static function get_representative_image_url( int $post_id ): ?string {
		$url = '';

		$terms = get_the_terms( $post_id, Taxonomy::TAXONOMY );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$suffix = self::KIND_IMAGE_META[ $terms[0]->slug ] ?? null;
			if ( null !== $suffix ) {
				$url = (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true );
			}
		}

		if ( '' === $url ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || ! has_blocks( $post->post_content ) ) {
				return null;
			}
			$url = (string) self::first_card_image_attr( parse_blocks( $post->post_content ) );
		}

		return self::validate_image_url( $url );
	}

	/**
	 * Depth-first search for the first kind card block carrying an
	 * image attribute (cards sit inside an h-entry group in
	 * Micropub-generated content, so nesting is the common case).
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string|null Image attribute value, or null.
	 */
	private static function first_card_image_attr( array $blocks ): ?string {
		foreach ( $blocks as $block ) {
			$attr = self::BLOCK_IMAGE_ATTR[ $block['blockName'] ?? '' ] ?? null;
			if ( null !== $attr ) {
				$value = $block['attrs'][ $attr ] ?? null;
				return ( is_string( $value ) && '' !== $value ) ? $value : null;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::first_card_image_attr( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Validate a candidate image URL.
	 *
	 * Accepts only well-formed absolute http(s) URLs; anything else
	 * (relative paths, data:/javascript: URIs, malformed values)
	 * returns null so the image is omitted rather than used broken.
	 *
	 * @param string $url Candidate URL.
	 * @return string|null Sanitized URL, or null.
	 */
	public static function validate_image_url( string $url ): ?string {
		$url = esc_url_raw( trim( $url ), [ 'http', 'https' ] );
		if ( '' === $url || false === wp_http_validate_url( $url ) ) {
			return null;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}

		return $url;
	}
}

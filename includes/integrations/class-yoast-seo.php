<?php
/**
 * Yoast SEO Integration
 *
 * Exposes a kind post's representative media (album cover, movie
 * poster, book cover, game art, checkin photo) to Yoast SEO's
 * schema.org graph when the post has no image Yoast can find on its
 * own. Kind cards are dynamic blocks — their artwork lives in block
 * attributes and post meta, not as an `<img>` in raw post_content — so
 * Yoast's featured-image / first-content-image resolution never sees
 * it, and Article schema on kind micro-posts loses its (optional but
 * recommended) `image` property.
 *
 * @package PKIW
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO schema integration.
 *
 * Fills the schema graph's primary image from normalized kind meta —
 * never overriding an image Yoast already resolved (featured image or
 * first content image), never inventing media, and never emitting a
 * competing graph. Inert unless Yoast SEO is active.
 *
 * @since 1.3.0
 */
class Yoast_SEO {

	/**
	 * Schema @id fragment Yoast uses for the page's primary image
	 * (mirrors Yoast\WP\SEO\Config\Schema_IDs::PRIMARY_IMAGE_HASH).
	 * Reusing it makes the injected node indistinguishable from a
	 * natively-resolved main image for consuming parsers.
	 */
	private const PRIMARY_IMAGE_HASH = '#primaryimage';

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
	 * Register hooks. No-op unless Yoast SEO is active.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		// Priority 11: after Yoast's own pieces and default-priority
		// third parties have shaped the graph.
		add_filter( 'wpseo_schema_graph', [ $this, 'filter_schema_graph' ], 11, 2 );
	}

	/**
	 * Add the kind's representative image to the schema graph.
	 *
	 * Runs only when Yoast found no image of its own; adds one
	 * ImageObject node and wires it into the Article and WebPage
	 * pieces exactly the way Yoast wires a featured image.
	 *
	 * @param array<int, array<string, mixed>>|mixed $graph   Schema graph pieces.
	 * @param object                                 $context Yoast Meta_Tags_Context.
	 * @return array<int, array<string, mixed>>|mixed Filtered graph.
	 */
	public function filter_schema_graph( $graph, $context ) {
		if ( ! is_array( $graph ) || ! is_singular( 'post' ) ) {
			return $graph;
		}

		// Yoast already resolved a main image (featured image, or the
		// first image in the raw post content). Never compete with it.
		if ( ! empty( $context->main_image_url ) ) {
			return $graph;
		}

		$post_id = get_queried_object_id();
		if ( $post_id < 1 ) {
			return $graph;
		}

		$image_url = self::get_representative_image_url( $post_id );
		if ( null === $image_url ) {
			return $graph;
		}

		$canonical = ( isset( $context->canonical ) && is_string( $context->canonical ) && '' !== $context->canonical )
			? $context->canonical
			: get_permalink( $post_id );
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return $graph;
		}

		$image_schema_id = $canonical . self::PRIMARY_IMAGE_HASH;
		$wired           = false;

		foreach ( $graph as &$piece ) {
			if ( ! is_array( $piece ) || ! isset( $piece['@type'] ) ) {
				continue;
			}

			$types = (array) $piece['@type'];

			// Article piece: mirror Article::add_image().
			if ( array_intersect( $types, [ 'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle', 'TechArticle', 'Report' ] )
				&& ! isset( $piece['image'] ) ) {
				$piece['image']        = [ '@id' => $image_schema_id ];
				$piece['thumbnailUrl'] = $image_url;
				$wired                 = true;
			}

			// WebPage piece: mirror WebPage's primaryImageOfPage wiring.
			if ( in_array( 'WebPage', $types, true ) && ! isset( $piece['primaryImageOfPage'] ) ) {
				$piece['image']              = [ '@id' => $image_schema_id ];
				$piece['thumbnailUrl']       = $image_url;
				$piece['primaryImageOfPage'] = [ '@id' => $image_schema_id ];
				$wired                       = true;
			}
		}
		unset( $piece );

		if ( ! $wired ) {
			return $graph;
		}

		$graph[] = self::generate_image_object( $image_schema_id, $image_url );

		return $graph;
	}

	/**
	 * Resolve the representative image URL for a kind post.
	 *
	 * Normalized post meta first (written by Card_Meta_Sync on save);
	 * falls back to the first kind card block's image attribute for
	 * posts saved before the sync covered their block. Returns null
	 * when the post's kind defines no representative media or the
	 * stored value isn't a usable http(s) URL — the schema image is
	 * then truthfully omitted.
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
	 * Validate a candidate image URL for schema output.
	 *
	 * Accepts only well-formed absolute http(s) URLs; anything else
	 * (relative paths, data:/javascript: URIs, malformed values)
	 * returns null so the image is omitted rather than emitted broken.
	 *
	 * @param string $url Candidate URL.
	 * @return string|null Sanitized URL, or null.
	 */
	private static function validate_image_url( string $url ): ?string {
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

	/**
	 * Build the ImageObject node.
	 *
	 * Uses Yoast's own schema image helper when available so the node
	 * carries the same shape (inLanguage, resolved dimensions for
	 * local attachments) as a native main image; falls back to the
	 * minimal valid ImageObject otherwise.
	 *
	 * @param string $image_schema_id Schema @id for the node.
	 * @param string $image_url       Image URL.
	 * @return array<string, mixed> ImageObject piece.
	 */
	private static function generate_image_object( string $image_schema_id, string $image_url ): array {
		if ( function_exists( 'YoastSEO' ) ) {
			$helpers = YoastSEO()->helpers ?? null;
			if ( is_object( $helpers )
				&& isset( $helpers->schema->image )
				&& method_exists( $helpers->schema->image, 'generate_from_url' ) ) {
				$generated = $helpers->schema->image->generate_from_url( $image_schema_id, $image_url );
				if ( is_array( $generated ) && ! empty( $generated ) ) {
					return $generated;
				}
			}
		}

		return [
			'@type'      => 'ImageObject',
			'@id'        => $image_schema_id,
			'url'        => $image_url,
			'contentUrl' => $image_url,
		];
	}
}

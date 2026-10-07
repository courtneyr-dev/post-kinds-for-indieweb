<?php
/**
 * Standard.site document enrichment for Post Kinds.
 *
 * Hooks ATmosphere's `atmosphere_transform_document` filter — which the
 * `?atproto` preview shares with the publish path, so preview and written
 * records always agree — and only fills gaps ATmosphere cannot: a derived
 * title for intentionally untitled kinds, and the kind slug as a tag so a
 * listen, review, or check-in stays discoverable as such. Fields
 * ATmosphere already maps from native WordPress data (description,
 * textContent, coverImage, path, timestamps) are never replaced, with one
 * cut: ATmosphere's excerpt reads raw post_content, so a private
 * acquisition cost (issue 239) comes out of the document description, the
 * Bluesky link card's description and the Bluesky post text.
 *
 * Deliberately not mapped, and why:
 * - `links`: the lexicon's links union has no interoperable members yet;
 *   a private shape would only look complete. Kind subject URLs already
 *   ride in the rendered card content.
 * - `description`: ATmosphere's excerpt mapping stands. Cited-page
 *   summaries (`_pkiw_cite_summary`) are third-party text and do not
 *   belong in a first-party record field.
 * - `contributors`: requires verified author DIDs, which WordPress users
 *   do not have.
 *
 * @package PKIW
 * @since   1.6.0
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
 * Document-record enrichment.
 *
 * @since 1.6.0
 */
class Atmosphere_Document_Map {

	/**
	 * Register the record filter.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'atmosphere_transform_document', [ $this, 'enrich' ], 10, 2 );
		add_filter( 'atmosphere_post_embed', [ $this, 'embed_without_private_cost' ], 10, 2 );
		add_filter( 'atmosphere_transform_bsky_post', [ $this, 'bsky_post_without_private_cost' ], 10, 2 );
	}

	/**
	 * Remove the record filter.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'atmosphere_transform_document', [ $this, 'enrich' ], 10 );
		remove_filter( 'atmosphere_post_embed', [ $this, 'embed_without_private_cost' ], 10 );
		remove_filter( 'atmosphere_transform_bsky_post', [ $this, 'bsky_post_without_private_cost' ], 10 );
	}

	/**
	 * Enrich a site.standard.document record with Post Kind knowledge.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $record The document record.
	 * @param \WP_Post             $post   The post being transformed.
	 * @return array<string, mixed> The enriched record.
	 */
	public function enrich( $record, $post ): array {
		if ( ! is_array( $record ) || ! $post instanceof \WP_Post ) {
			return is_array( $record ) ? $record : [];
		}

		if ( isset( $record['description'] ) && is_string( $record['description'] ) ) {
			$record['description'] = self::without_private_cost( $record['description'], [], $post )[0];
		}

		if ( empty( $record['title'] ) ) {
			$derived = Atmosphere_Titles::derive( $post );

			if ( '' !== $derived ) {
				$record['title'] = $derived;
			}
		}

		$kind = $this->kind_slug( $post );
		if ( null !== $kind ) {
			$record = $this->append_kind_tag( $record, $post, $kind );
		}

		return $record;
	}

	/**
	 * Append the kind slug to the record's tags.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $record The document record.
	 * @param \WP_Post             $post   The post.
	 * @param string               $kind   Kind slug.
	 * @return array<string, mixed>
	 */
	private function append_kind_tag( array $record, \WP_Post $post, string $kind ): array {
		/**
		 * Filters the kind tag added to a Standard.site document record.
		 *
		 * Return null or '' to add no kind tag for this post.
		 *
		 * @since 1.6.0
		 *
		 * @param string|null $tag  Tag to add. Default: the kind slug.
		 * @param \WP_Post    $post The post.
		 * @param string      $kind The post's kind slug.
		 */
		$tag = apply_filters( 'pkiw_atmosphere_document_kind_tag', $kind, $post, $kind );

		if ( ! is_string( $tag ) || '' === $tag ) {
			return $record;
		}

		$tags = isset( $record['tags'] ) && is_array( $record['tags'] ) ? $record['tags'] : [];

		$existing = array_map(
			static fn( $value ): string => is_string( $value ) ? strtolower( $value ) : '',
			$tags
		);

		if ( ! in_array( strtolower( $tag ), $existing, true ) ) {
			$tags[] = $tag;
		}

		if ( ! empty( $tags ) ) {
			$record['tags'] = $tags;
		}

		return $record;
	}

	/**
	 * Take private acquisition cost out of a Bluesky link card. ATmosphere
	 * builds the card's description from raw post_content.
	 *
	 * @param mixed $embed The embed record, or null.
	 * @param mixed $post  The post being transformed.
	 * @return mixed
	 */
	public function embed_without_private_cost( $embed, $post ) {
		if ( ! is_array( $embed ) || ! $post instanceof \WP_Post || ! is_string( $embed['external']['description'] ?? null ) ) {
			return $embed;
		}

		$embed['external']['description'] = self::without_private_cost( $embed['external']['description'], [], $post )[0];

		return $embed;
	}

	/**
	 * Take private acquisition cost out of a Bluesky post's text, which
	 * ATmosphere builds from the title, a raw post_content excerpt and the
	 * permalink. Facets after a removed cost move back with the text.
	 *
	 * @param mixed $record The app.bsky.feed.post record.
	 * @param mixed $post   The post being transformed.
	 * @return mixed
	 */
	public function bsky_post_without_private_cost( $record, $post ) {
		if ( ! is_array( $record ) || ! $post instanceof \WP_Post || ! is_string( $record['text'] ?? null ) ) {
			return $record;
		}

		$facets = isset( $record['facets'] ) && is_array( $record['facets'] ) ? $record['facets'] : [];

		[ $record['text'], $facets ] = self::without_private_cost( $record['text'], $facets, $post );
		if ( isset( $record['facets'] ) ) {
			$record['facets'] = $facets;
		}

		return $record;
	}

	/**
	 * Remove each private acquisition cost on $post from $text. When a cost
	 * stands between two spaces, one space goes with it. Facet byte ranges
	 * after a removal shift back; a facet over the removed bytes is dropped.
	 *
	 * @param string                   $text   Text derived from the post.
	 * @param array<int|string, mixed> $facets Bluesky facets indexed into $text by UTF-8 byte.
	 * @param \WP_Post                 $post   The post.
	 * @return array{0: string, 1: array<int|string, mixed>}
	 */
	private static function without_private_cost( string $text, array $facets, \WP_Post $post ): array {
		foreach ( Meta_Fields::private_costs( (string) $post->post_content, (int) $post->ID ) as $cost ) {
			$at = strpos( $text, $cost );
			while ( false !== $at ) {
				$length = strlen( $cost );
				if ( $at > 0 && ' ' === $text[ $at - 1 ] && ' ' === substr( $text, $at + $length, 1 ) ) {
					++$length;
				}

				$text   = substr_replace( $text, '', $at, $length );
				$facets = self::shift_facets( $facets, $at, $length );
				$at     = strpos( $text, $cost, $at );
			}
		}

		return [ $text, $facets ];
	}

	/**
	 * Facets after $length bytes were removed from the text at $at.
	 *
	 * @param array<int|string, mixed> $facets Bluesky facets.
	 * @param int                      $at     Byte offset of the removal.
	 * @param int                      $length Bytes removed.
	 * @return array<int|string, mixed>
	 */
	private static function shift_facets( array $facets, int $at, int $length ): array {
		$kept = [];
		foreach ( $facets as $facet ) {
			$start = is_array( $facet ) ? ( $facet['index']['byteStart'] ?? null ) : null;
			$end   = is_array( $facet ) ? ( $facet['index']['byteEnd'] ?? null ) : null;

			if ( ! is_int( $start ) || ! is_int( $end ) || $end <= $at ) {
				$kept[] = $facet;
			} elseif ( $start >= $at + $length ) {
				$facet['index']['byteStart'] = $start - $length;
				$facet['index']['byteEnd']   = $end - $length;
				$kept[]                      = $facet;
			}
		}

		return $kept;
	}

	/**
	 * The post's kind slug.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string|null
	 */
	private function kind_slug( \WP_Post $post ): ?string {
		$terms = get_the_terms( $post->ID, Taxonomy::TAXONOMY );

		if ( is_array( $terms ) && isset( $terms[0]->slug ) ) {
			return (string) $terms[0]->slug;
		}

		return null;
	}
}

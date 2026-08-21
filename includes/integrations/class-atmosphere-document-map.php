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
 * textContent, coverImage, path, timestamps) are never replaced.
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

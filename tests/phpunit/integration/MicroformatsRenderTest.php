<?php
/**
 * Parsed microformats2 coverage for rendered kind cards.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies that published kind cards expose their canonical properties.
 *
 * TODO: Follow has no card; u-follow-of comes from the Micropub content
 * builder and needs a later Micropub-output test. RSVP is omitted here: its
 * card roots as its own h-entry and emits p-rsvp inside the nested h-event,
 * while the entry-level p-rsvp comes from add_hidden_mf2_data() on the
 * the_content filter (which do_blocks() alone does not run) — so RSVP needs a
 * dedicated test that exercises the full published pipeline, not the card
 * render. The experimental eat, drink, jam, checkin, and acquisition kinds are
 * omitted while their microformats2 vocabulary remains unsettled.
 *
 * @group integration
 */
final class MicroformatsRenderTest extends WP_UnitTestCase {

	/**
	 * Card-backed kinds and their canonical target properties.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function kind_cards(): array {
		return [
			'like'     => [ 'like', 'like-of', 'https://example.com/targets/like' ],
			'reply'    => [ 'reply', 'in-reply-to', 'https://example.com/targets/reply' ],
			'repost'   => [ 'repost', 'repost-of', 'https://example.com/targets/repost' ],
			'bookmark' => [ 'bookmark', 'bookmark-of', 'https://example.com/targets/bookmark' ],
			'favorite' => [ 'favorite', 'favorite-of', 'https://example.com/targets/favorite' ],
			'listen'   => [ 'listen', 'listen-of', 'https://example.com/targets/listen' ],
			'watch'    => [ 'watch', 'watch-of', 'https://example.com/targets/watch' ],
			'read'     => [ 'read', 'read-of', 'https://example.com/targets/read' ],
		];
	}

	/**
	 * Render a published kind card and parse its canonical microformats2 data.
	 *
	 * @dataProvider kind_cards
	 *
	 * @param string $kind               Post kind and card slug.
	 * @param string $canonical_property Canonical parsed property name.
	 * @param string $target_url         Expected target URL.
	 */
	public function test_kind_card_parses_to_canonical_microformats(
		string $kind,
		string $canonical_property,
		string $target_url
	): void {
		$attributes = $this->card_attributes( $kind, $target_url );
		$block      = sprintf(
			'<!-- wp:post-kinds-indieweb/%s-card %s /-->',
			$kind,
			wp_json_encode( $attributes )
		);
		$post_id    = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $block,
			]
		);

		$term_result = wp_set_object_terms( $post_id, $kind, 'kind' );
		$this->assertNotWPError( $term_result );

		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		// Simulate the theme's post_class h-entry wrapper around the card h-cite.
		$html = '<div class="h-entry">' . $html . '</div>';

		$entry      = $this->top_level_h_entry( \Mf2\parse( $html ) );
		$properties = $entry['properties'] ?? [];

		$this->assertArrayHasKey( $canonical_property, $properties );
		$this->assertPropertyContainsTarget( $properties[ $canonical_property ], $target_url );
	}

	/**
	 * Card-backed kinds that render a star rating, and the minimal
	 * attributes each render.php needs to render at all.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function rating_cards(): array {
		return [
			'listen' => [ 'listen', [ 'trackTitle' => 'Test track', 'listenUrl' => 'https://example.com/listen' ] ],
			'watch'  => [ 'watch', [ 'mediaTitle' => 'Test film', 'watchUrl' => 'https://example.com/watch' ] ],
			'read'   => [ 'read', [ 'bookTitle' => 'Test book', 'bookUrl' => 'https://example.com/read' ] ],
			'play'   => [ 'play', [ 'title' => 'Test game' ] ],
			'eat'    => [ 'eat', [ 'name' => 'Test dish' ] ],
			'drink'  => [ 'drink', [ 'name' => 'Test drink' ] ],
		];
	}

	/**
	 * `.pk-stars` holds only `aria-hidden` SVG stars, so mf2 parsers
	 * previously read `rating` as an empty string with no value. Each of
	 * the six cards must expose the numeric rating via a sibling, empty
	 * `<data class="p-rating" value="N" hidden>` — the same pattern
	 * `class-microformats.php`'s review case already uses — kept off the
	 * visible `.pk-stars` element entirely so the digit never lands in
	 * plain-text extraction (feed readers, excerpts, ATmosphere/
	 * Standard.site document text) that does not respect `hidden`.
	 *
	 * @dataProvider rating_cards
	 *
	 * @param string               $kind            Post kind and card slug.
	 * @param array<string, mixed> $base_attributes Minimal attributes the card needs to render.
	 */
	public function test_kind_card_rating_parses_as_numeric_value( string $kind, array $base_attributes ): void {
		$with_rating    = $this->render_card_html( $kind, array_merge( $base_attributes, [ 'rating' => 4 ] ) );
		$without_rating = $this->render_card_html( $kind, $base_attributes );

		$entry     = $this->top_level_h_entry( \Mf2\parse( $with_rating ) );
		$card_item = $this->find_item_with_property( [ $entry ], 'rating' );

		$this->assertNotNull( $card_item, "No parsed item carried a rating property for the {$kind} card." );
		$this->assertSame( [ '4' ], $card_item['properties']['rating'] );

		// The rating digit must not leak into plain-text extraction: the
		// stripped text of the card is identical whether or not a rating is
		// present. wp_strip_all_tags() (and any similar strip_tags-based
		// extraction a feed reader or ATmosphere/Standard.site document
		// might do) does not consult the `hidden` attribute, so if the
		// digit ever sat in a visible element's text content it would show
		// up here even though it is invisible in a browser.
		$this->assertSame(
			$this->strip_and_normalize( $without_rating ),
			$this->strip_and_normalize( $with_rating ),
			"Rendering the {$kind} card with a rating changed its plain-text content — the rating value leaked into extractable text."
		);

		$this->assertMatchesRegularExpression(
			'/<data class="p-rating" value="4" hidden><\/data>/',
			$with_rating,
			"Expected an empty <data class=\"p-rating\"> element (no text content) for the {$kind} card."
		);

		$this->assertMatchesRegularExpression(
			'/<div class="pk-stars" role="img"[^>]*>\s*(?:<svg[^>]*>.*?<\/svg>\s*)+<\/div>/s',
			$with_rating,
			"Expected .pk-stars to contain only SVG star children (no text node) for the {$kind} card."
		);
	}

	/**
	 * Control: a card with no rating attribute renders no `.pk-stars` or
	 * `.p-rating` markup at all (every render.php guards the whole block
	 * behind `$pkiw_rating > 0`), so no item in the parsed tree should
	 * carry a rating property.
	 */
	public function test_kind_card_without_rating_emits_no_rating_property(): void {
		$html = $this->render_card_html(
			'listen',
			[
				'trackTitle' => 'Test track',
				'listenUrl'  => 'https://example.com/listen',
			]
		);

		$entry     = $this->top_level_h_entry( \Mf2\parse( $html ) );
		$card_item = $this->find_item_with_property( [ $entry ], 'rating' );

		$this->assertNull( $card_item, 'A card with no rating attribute must not emit a rating property.' );
		$this->assertStringNotContainsString( 'p-rating', $html );
		$this->assertStringNotContainsString( 'pk-stars', $html );
	}

	/**
	 * Render a card block for the given kind/attributes through the full
	 * dynamic-render pipeline, wrapped in a synthetic `h-entry` the way the
	 * theme wraps a published post's content.
	 *
	 * @param string               $kind       Post kind and card slug.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_card_html( string $kind, array $attributes ): string {
		$block   = sprintf(
			'<!-- wp:post-kinds-indieweb/%s-card %s /-->',
			$kind,
			wp_json_encode( $attributes )
		);
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $block,
			]
		);

		$term_result = wp_set_object_terms( $post_id, $kind, 'kind' );
		$this->assertNotWPError( $term_result );

		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		return '<div class="h-entry">' . $html . '</div>';
	}

	/**
	 * wp_strip_all_tags(), with whitespace collapsed, so plain-text output
	 * can be compared between two renders regardless of incidental
	 * whitespace shifts from the markup difference itself.
	 *
	 * @param string $html Rendered HTML.
	 */
	private function strip_and_normalize( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	/**
	 * Attributes each render.php reads to emit its target URL.
	 *
	 * @param string $kind       Card kind.
	 * @param string $target_url Target URL.
	 * @return array<string, string>
	 */
	private function card_attributes( string $kind, string $target_url ): array {
		switch ( $kind ) {
			case 'listen':
				return [
					'trackTitle' => 'Test track',
					'listenUrl'  => $target_url,
				];
			case 'watch':
				return [
					'mediaTitle' => 'Test film',
					'watchUrl'   => $target_url,
				];
			case 'read':
				return [
					'bookTitle' => 'Test book',
					'bookUrl'   => $target_url,
				];
			case 'rsvp':
				return [
					'eventName'  => 'Test event',
					'eventUrl'   => $target_url,
					'rsvpStatus' => 'yes',
				];
			default:
				return [
					'title' => 'Test target',
					'url'   => $target_url,
				];
		}
	}

	/**
	 * Find the top-level h-entry item in parsed microformats2 data.
	 *
	 * @param array<string, mixed> $parsed Parsed microformats2 document.
	 * @return array<string, mixed>
	 */
	private function top_level_h_entry( array $parsed ): array {
		foreach ( $parsed['items'] ?? [] as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				return $item;
			}
		}

		$this->fail( 'No top-level h-entry item was parsed.' );
	}

	/**
	 * Recursively search parsed microformats2 items — including nested
	 * items embedded as property values (e.g. a `u-listen-of h-cite` card)
	 * and as unclaimed children (e.g. a bare `h-cite` card with no matching
	 * `u-*-of` property on the entry) — for the first item exposing
	 * $property.
	 *
	 * @param array<int, array<string, mixed>> $items    Parsed mf2 items to search.
	 * @param string                            $property Property name to find.
	 * @return array<string, mixed>|null The first matching item, or null.
	 */
	private function find_item_with_property( array $items, string $property ): ?array {
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( isset( $item['properties'][ $property ] ) ) {
				return $item;
			}

			foreach ( $item['properties'] ?? [] as $values ) {
				if ( ! is_array( $values ) ) {
					continue;
				}

				$nested_items = array_values(
					array_filter(
						$values,
						static fn( $value ) => is_array( $value ) && isset( $value['type'] )
					)
				);

				if ( $nested_items ) {
					$found = $this->find_item_with_property( $nested_items, $property );
					if ( null !== $found ) {
						return $found;
					}
				}
			}

			if ( ! empty( $item['children'] ) ) {
				$found = $this->find_item_with_property( $item['children'], $property );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Assert that a parsed property contains the expected target URL.
	 *
	 * @param array<int, mixed> $values     Parsed property values.
	 * @param string            $target_url Expected target URL.
	 */
	private function assertPropertyContainsTarget( array $values, string $target_url ): void {
		foreach ( $values as $value ) {
			if ( $target_url === $value ) {
				$this->addToAssertionCount( 1 );
				return;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value['properties']['url'] ?? [] as $url ) {
				if ( $target_url === $url || ( is_array( $url ) && $target_url === ( $url['value'] ?? null ) ) ) {
					$this->addToAssertionCount( 1 );
					return;
				}
			}
		}

		$this->fail( 'Canonical property did not contain target URL ' . $target_url );
	}
}

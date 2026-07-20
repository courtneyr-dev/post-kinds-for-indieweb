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

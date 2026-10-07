<?php
/**
 * Stored card date rendering coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class CardDateRenderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'America/Chicago' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * @dataProvider calendar_date_cards
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $published  Whether the card marks the date dt-published.
	 */
	public function test_calendar_date_cards_keep_the_stored_day( string $kind, array $attributes, bool $published = true ): void {
		$html = $this->render_card( $kind, $attributes );

		$this->assertStringContainsString( 'datetime="2026-09-24"', $html );
		$this->assertStringContainsString( 'September 24, 2026', $html );
		$this->assertStringNotContainsString( '10:30 pm', $html );
		if ( $published ) {
			$this->assertParsedPropertyContains( $html, 'published', '2026-09-24' );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2?: bool}>
	 */
	public function calendar_date_cards(): array {
		return [
			'like'         => [ 'like', [ 'title' => 'Example', 'url' => 'https://example.com/like', 'likedAt' => '2026-09-24T22:30:00' ] ],
			'bookmark'     => [ 'bookmark', [ 'title' => 'Example', 'url' => 'https://example.com/bookmark', 'bookmarkedAt' => '2026-09-24T22:30:00' ] ],
			'favorite'     => [ 'favorite', [ 'title' => 'Example', 'url' => 'https://example.com/favorite', 'favoritedAt' => '2026-09-24T22:30:00' ] ],
			'wish'         => [ 'wish', [ 'title' => 'Example', 'url' => 'https://example.com/wish', 'wishedAt' => '2026-09-24T22:30:00' ] ],
			'acquisition'  => [ 'acquisition', [ 'title' => 'Example', 'acquiredAt' => '2026-09-24T22:30:00' ] ],
			'jam'          => [ 'jam', [ 'title' => 'Example song', 'url' => 'https://example.com/jam', 'jammedAt' => '2026-09-24T22:30:00' ] ],
			'play'         => [ 'play', [ 'title' => 'Example game', 'playedAt' => '2026-09-24T22:30:00' ] ],
			// The started date prints as a plain <time>, not dt-published.
			'read started' => [ 'read', [ 'bookTitle' => 'Example book', 'startedAt' => '2026-09-24T22:30:00' ], false ],
			'read finished' => [ 'read', [ 'bookTitle' => 'Example book', 'readStatus' => 'finished', 'finishedAt' => '2026-09-24T22:30:00' ] ],
		];
	}

	/**
	 * @dataProvider wall_clock_cards
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function test_wall_clock_cards_keep_the_stored_time( string $kind, array $attributes, string $display ): void {
		$html = $this->render_card( $kind, $attributes );

		$this->assertStringContainsString( 'datetime="2026-09-24T22:30:00-05:00"', $html );
		$this->assertStringContainsString( $display, $html );
		$this->assertParsedPropertyContains( $html, 'published', '2026-09-24T22:30:00-05:00' );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function wall_clock_cards(): array {
		return [
			'reply'   => [ 'reply', [ 'title' => 'Example', 'url' => 'https://example.com/reply', 'repliedAt' => '2026-09-24T22:30:00' ], 'September 24, 2026 10:30 pm' ],
			'repost'  => [ 'repost', [ 'title' => 'Example', 'url' => 'https://example.com/repost', 'repostedAt' => '2026-09-24T22:30:00' ], 'September 24, 2026 10:30 pm' ],
			'watch'   => [ 'watch', [ 'mediaTitle' => 'Example film', 'watchedAt' => '2026-09-24T22:30:00' ], 'September 24, 2026 10:30 pm' ],
			'checkin' => [ 'checkin', [ 'venueName' => 'Example Cafe', 'checkinAt' => '2026-09-24T22:30:00' ], 'September 24, 2026 10:30 pm' ],
			'mood'    => [ 'mood', [ 'mood' => 'Calm', 'moodAt' => '2026-09-24T22:30:00' ], 'September 24, 2026 10:30 pm' ],
			'drink'   => [ 'drink', [ 'name' => 'Coffee', 'drankAt' => '2026-09-24T22:30:00' ], 'September 24, 2026 10:30 pm' ],
			'eat'     => [ 'eat', [ 'name' => 'Soup', 'ateAt' => '2026-09-24T22:30:00' ], 'September 24, 2026 10:30 pm' ],
			'listen'  => [ 'listen', [ 'trackTitle' => 'Example song', 'listenedAt' => '2026-09-24T22:30:00' ], 'September 24, 2026' ],
			'rsvp'    => [ 'rsvp', [ 'eventName' => 'Example event', 'rsvpAt' => '2026-09-24T22:30:00' ], 'September 24, 2026' ],
		];
	}

	/**
	 * @dataProvider event_cards
	 */
	public function test_event_ranges_use_site_local_times( string $kind ): void {
		$html = $this->render_card(
			$kind,
			[
				'eventName'  => 'Example event',
				'eventStart' => '2026-10-08T18:00:00',
				'eventEnd'   => '2026-10-08T20:00:00',
			]
		);

		$this->assertStringContainsString( 'October 8, 2026 6:00 pm – 8:00 pm', $html );
		$this->assertStringContainsString( 'datetime="2026-10-08T18:00:00-05:00"', $html );
		$this->assertStringContainsString( 'value="2026-10-08T20:00:00-05:00"', $html );
		$this->assertParsedPropertyContains( $html, 'start', '2026-10-08T18:00:00-05:00' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function event_cards(): array {
		return [
			'event' => [ 'event' ],
			'rsvp'  => [ 'rsvp' ],
		];
	}

	public function test_listen_release_year_uses_only_a_leading_year(): void {
		$valid = $this->render_card( 'listen', [ 'trackTitle' => 'Example song', 'albumTitle' => 'Example album', 'releaseDate' => '1999' ] );
		$bad   = $this->render_card( 'listen', [ 'trackTitle' => 'Example song', 'albumTitle' => 'Example album', 'releaseDate' => 'last year' ] );

		$this->assertStringContainsString( '(1999)', $valid );
		$this->assertStringNotContainsString( '(1970)', $bad );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_card( string $kind, array $attributes ): string {
		return do_blocks(
			sprintf(
				'<!-- wp:post-kinds-indieweb/%s-card %s /-->',
				$kind,
				wp_json_encode( $attributes )
			)
		);
	}

	private function assertParsedPropertyContains( string $html, string $property, string $expected ): void {
		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$values = $this->collectPropertyValues( $parsed['items'] ?? [], $property );

		$this->assertContains( $expected, $values );
	}

	/**
	 * @param array<int, mixed> $items Parsed microformats items.
	 * @return array<int, string>
	 */
	private function collectPropertyValues( array $items, string $property ): array {
		$found = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $item['properties'][ $property ] ?? [] as $value ) {
				$found[] = is_array( $value ) ? (string) ( $value['value'] ?? '' ) : (string) $value;
			}
			foreach ( $item['properties'] ?? [] as $values ) {
				if ( is_array( $values ) ) {
					$found = array_merge( $found, $this->collectPropertyValues( $values, $property ) );
				}
			}
			$found = array_merge( $found, $this->collectPropertyValues( $item['children'] ?? [], $property ) );
		}

		return $found;
	}
}

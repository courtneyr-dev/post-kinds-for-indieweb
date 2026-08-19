<?php
/**
 * Coverage for the event-card block render.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;

/**
 * The event card roots as an h-event (p-name, dt-start, dt-end, p-location,
 * u-url) and exposes the EVENT start date in a dedicated .pk-event-date
 * element the theme can promote to the big gig-poster date — the post's own
 * publish date is never the card's date, and the post is never future-dated.
 *
 * Calendar plugin data (The Events Calendar, My Calendar) overrides the
 * block's attributes when available and degrades to them when not.
 *
 * @covers \PKIW\Integrations\Calendar_Events
 */
final class EventCardRenderTest extends WP_UnitTestCase {

	/**
	 * Render the event-card block with the given attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render_event_card( array $attributes ): string {
		return render_block(
			[
				'blockName'    => 'post-kinds-indieweb/event-card',
				'attrs'        => $attributes,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Full attribute set renders canonical h-event microformats.
	 */
	public function test_renders_h_event_microformats(): void {
		$html = $this->render_event_card(
			[
				'eventName'        => 'IndieWebCamp 2026',
				'eventStart'       => '2026-09-12T09:00:00',
				'eventEnd'         => '2026-09-13T17:00:00',
				'eventLocation'    => 'Portland, OR',
				'eventUrl'         => 'https://example.com/events/iwc-2026',
				'eventDescription' => 'Two days of IndieWeb sessions.',
			]
		);

		$parsed = \Mf2\parse( $html );
		$event  = $parsed['items'][0] ?? [];

		$this->assertContains( 'h-event', $event['type'] ?? [] );

		$properties = $event['properties'] ?? [];
		$this->assertSame( [ 'IndieWebCamp 2026' ], $properties['name'] ?? [] );
		$this->assertSame( [ '2026-09-12T09:00:00+00:00' ], $properties['start'] ?? [] );
		$this->assertSame( [ '2026-09-13T17:00:00+00:00' ], $properties['end'] ?? [] );
		$this->assertSame( [ 'Portland, OR' ], $properties['location'] ?? [] );
		$this->assertSame( [ 'https://example.com/events/iwc-2026' ], $properties['url'] ?? [] );
	}

	/**
	 * The event start date renders inside a dedicated .pk-event-date element
	 * (dt-start), and the card emits no dt-published of its own — so a theme
	 * can make the EVENT date the big date without the post being
	 * future-dated.
	 */
	public function test_event_start_renders_in_dedicated_event_date_element(): void {
		$html = $this->render_event_card(
			[
				'eventName'  => 'Homebrew Website Club',
				'eventStart' => '2026-11-04T18:00:00',
			]
		);

		$this->assertStringContainsString( 'pk-card k-event h-event', $html );
		$this->assertStringContainsString( 'class="pk-sub pk-event-date"', $html );
		$this->assertMatchesRegularExpression(
			'#<p class="pk-sub pk-event-date">\s*<time class="dt-start" datetime="2026-11-04T18:00:00\+00:00"#',
			$html
		);
		$this->assertStringNotContainsString( 'dt-published', $html );
	}

	/**
	 * A calendar reference whose source plugin is inactive falls back to the
	 * block's own attributes — no fatal, no blank card.
	 */
	public function test_falls_back_to_attributes_when_calendar_plugin_absent(): void {
		add_filter( 'pkiw_calendar_source_active', '__return_false' );

		$html = $this->render_event_card(
			[
				'eventName'       => 'Fallback Event',
				'eventStart'      => '2026-12-01T10:00:00',
				'eventLocation'   => 'Fallback Hall',
				'calendarSource'  => 'the-events-calendar',
				'calendarEventId' => 987654,
			]
		);

		$this->assertStringContainsString( 'Fallback Event', $html );
		$this->assertStringContainsString( '2026-12-01T10:00:00+00:00', $html );
		$this->assertStringContainsString( 'Fallback Hall', $html );
	}

	/**
	 * An unknown calendar source is ignored and the attributes render.
	 */
	public function test_unknown_calendar_source_renders_attributes(): void {
		$html = $this->render_event_card(
			[
				'eventName'       => 'Unknown Source Event',
				'calendarSource'  => 'some-future-calendar',
				'calendarEventId' => 5,
			]
		);

		$this->assertStringContainsString( 'Unknown Source Event', $html );
	}

	/**
	 * Calendar data overrides the block's attributes where present; fields
	 * the calendar omits keep their attribute values.
	 */
	public function test_calendar_data_overrides_attributes(): void {
		add_filter(
			'pkiw_pre_calendar_event',
			static function ( $pre, string $source, int $event_id ) {
				if ( 'the-events-calendar' === $source && 42 === $event_id ) {
					return [
						'name'  => 'Calendar Name Wins',
						'start' => '2027-01-15 19:00:00',
						'url'   => 'https://example.com/calendar/42',
					];
				}
				return $pre;
			},
			10,
			3
		);

		$html = $this->render_event_card(
			[
				'eventName'       => 'Stale Snapshot Name',
				'eventStart'      => '2026-01-01T00:00:00',
				'eventLocation'   => 'Attribute Venue',
				'calendarSource'  => 'the-events-calendar',
				'calendarEventId' => 42,
			]
		);

		$this->assertStringContainsString( 'Calendar Name Wins', $html );
		$this->assertStringNotContainsString( 'Stale Snapshot Name', $html );
		$this->assertStringContainsString( '2027-01-15T19:00:00+00:00', $html );
		$this->assertStringContainsString( 'https://example.com/calendar/42', $html );
		// The calendar returned no location — the attribute fills the gap.
		$this->assertStringContainsString( 'Attribute Venue', $html );
	}

	/**
	 * Markup in attribute values is escaped on output.
	 */
	public function test_escapes_markup_in_event_name(): void {
		$html = $this->render_event_card(
			[
				'eventName' => '<script>alert(1)</script>Party',
			]
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * Without a start date, no .pk-event-date element renders.
	 */
	public function test_no_date_renders_without_event_date_element(): void {
		$html = $this->render_event_card(
			[
				'eventName' => 'Dateless Gathering',
			]
		);

		$this->assertStringContainsString( 'Dateless Gathering', $html );
		$this->assertStringNotContainsString( 'pk-event-date', $html );
	}
}

<?php
/**
 * Coverage for the My Calendar integration.
 *
 * My Calendar stores events in its own mc_events table, so the integration
 * only ever talks to it through mc_get_event_core() / mc_get_event(). The
 * test defines a global mc_get_event_core() stub backed by fixture data —
 * the real plugin is never installed and no table is touched.
 *
 * This file is intentionally un-namespaced so the stub lands in the global
 * namespace where the integration's function_exists() checks look.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Calendar_Events;

if ( ! function_exists( 'mc_get_event_core' ) ) {
	/**
	 * Test stub for My Calendar's event lookup.
	 *
	 * Reads from the pkiw_test_mc_events registry seeded by the test.
	 *
	 * @param int $event_id Event ID.
	 * @return object|false Event object, or false when unknown.
	 */
	function mc_get_event_core( $event_id ) {
		$events = $GLOBALS['pkiw_test_mc_events'] ?? [];

		return $events[ (int) $event_id ] ?? false;
	}
}

/**
 * Fixture-driven mapping tests for the My Calendar read path.
 *
 * @covers \PKIW\Integrations\My_Calendar
 * @covers \PKIW\Integrations\Calendar_Events
 */
final class MyCalendarIntegrationTest extends WP_UnitTestCase {

	/**
	 * Clear the stubbed event registry.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['pkiw_test_mc_events'] );

		parent::tear_down();
	}

	/**
	 * Seed the mc_get_event_core() stub from the fixture.
	 *
	 * @return int Fixture event ID.
	 */
	private function seed_fixture_event(): int {
		$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/my-calendar/event.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertIsString( $json );

		$fixture  = json_decode( $json );
		$event_id = (int) $fixture->event_id;

		$GLOBALS['pkiw_test_mc_events'] = [ $event_id => $fixture ];

		return $event_id;
	}

	/**
	 * A My Calendar event row maps to the normalized event array, with the
	 * split date/time columns combined.
	 */
	public function test_get_event_maps_my_calendar_fixture(): void {
		$event_id = $this->seed_fixture_event();

		$event = Calendar_Events::get_event( Calendar_Events::SOURCE_MY_CALENDAR, $event_id );

		$this->assertIsArray( $event );
		$this->assertSame( 'Block Editor Meetup', $event['name'] );
		$this->assertSame( '2026-10-12 18:30:00', $event['start'] );
		$this->assertSame( '2026-10-12 20:00:00', $event['end'] );
		$this->assertSame( 'Happy Valley Library', $event['location'] );
		$this->assertSame( 'https://example.com/events/block-editor-meetup', $event['url'] );
	}

	/**
	 * A zeroed-out date column maps to an empty string, not "0000-00-00".
	 */
	public function test_zero_date_maps_to_empty_string(): void {
		$event_id = $this->seed_fixture_event();

		$GLOBALS['pkiw_test_mc_events'][ $event_id ]->event_end     = '0000-00-00';
		$GLOBALS['pkiw_test_mc_events'][ $event_id ]->event_endtime = '00:00:00';

		$event = Calendar_Events::get_event( Calendar_Events::SOURCE_MY_CALENDAR, $event_id );

		$this->assertIsArray( $event );
		$this->assertSame( '', $event['end'] );
	}

	/**
	 * An unapproved (draft/trash) event never resolves — the card renders to
	 * anonymous visitors, so referencing an unpublished row must not leak it.
	 */
	public function test_returns_null_for_unapproved_event(): void {
		$event_id = $this->seed_fixture_event();

		$GLOBALS['pkiw_test_mc_events'][ $event_id ]->event_approved = 0;

		$this->assertNull(
			Calendar_Events::get_event( Calendar_Events::SOURCE_MY_CALENDAR, $event_id )
		);
	}

	/**
	 * When detection says the plugin is inactive, the lookup returns null —
	 * even though the stub function exists.
	 */
	public function test_returns_null_when_inactive(): void {
		$event_id = $this->seed_fixture_event();

		add_filter( 'pkiw_calendar_source_active', '__return_false' );

		$this->assertNull(
			Calendar_Events::get_event( Calendar_Events::SOURCE_MY_CALENDAR, $event_id )
		);
	}

	/**
	 * An unknown event ID resolves to null.
	 */
	public function test_returns_null_for_unknown_event(): void {
		$this->seed_fixture_event();

		$this->assertNull(
			Calendar_Events::get_event( Calendar_Events::SOURCE_MY_CALENDAR, 999999 )
		);
	}
}

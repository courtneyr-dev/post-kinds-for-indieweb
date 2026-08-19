<?php
/**
 * Coverage for The Events Calendar integration.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use PKIW\Integrations\Calendar_Events;
use WP_UnitTestCase;

/**
 * Fixture-driven mapping tests for the tribe_events read path. The plugin
 * itself is never installed: the tribe_events / tribe_venue post types are
 * registered by the test (which also satisfies detection), and the fixture
 * supplies the meta The Events Calendar would have written.
 *
 * @covers \PKIW\Integrations\The_Events_Calendar
 * @covers \PKIW\Integrations\Calendar_Events
 */
final class TheEventsCalendarIntegrationTest extends WP_UnitTestCase {

	/**
	 * Register the tribe post types the fixture posts need.
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_type( 'tribe_events', [ 'public' => true ] );
		register_post_type( 'tribe_venue', [ 'public' => false ] );
	}

	/**
	 * Unregister the stub post types.
	 */
	public function tear_down(): void {
		unregister_post_type( 'tribe_events' );
		unregister_post_type( 'tribe_venue' );

		parent::tear_down();
	}

	/**
	 * Load the fixture describing a tribe event.
	 *
	 * @return array<string, mixed> Decoded fixture.
	 */
	private function fixture(): array {
		$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/the-events-calendar/event.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertIsString( $json );

		return json_decode( $json, true );
	}

	/**
	 * Create a tribe_events post (plus venue) from the fixture.
	 *
	 * @return int Event post ID.
	 */
	private function create_fixture_event(): int {
		$fixture = $this->fixture();

		$venue_id = self::factory()->post->create(
			[
				'post_type'   => 'tribe_venue',
				'post_title'  => $fixture['venue']['title'],
				'post_status' => 'publish',
			]
		);

		$event_id = self::factory()->post->create(
			[
				'post_type'   => 'tribe_events',
				'post_title'  => $fixture['title'],
				'post_status' => 'publish',
			]
		);

		foreach ( $fixture['meta'] as $key => $value ) {
			update_post_meta( $event_id, $key, $value );
		}
		update_post_meta( $event_id, '_EventVenueID', $venue_id );

		return $event_id;
	}

	/**
	 * A tribe event's title, dates, venue, and permalink map to the
	 * normalized event array.
	 */
	public function test_get_event_maps_tribe_event_fixture(): void {
		$event_id = $this->create_fixture_event();

		$event = Calendar_Events::get_event( Calendar_Events::SOURCE_THE_EVENTS_CALENDAR, $event_id );

		$this->assertIsArray( $event );
		$this->assertSame( 'WordCamp US 2026', $event['name'] );
		$this->assertSame( '2026-09-01 09:00:00', $event['start'] );
		$this->assertSame( '2026-09-03 17:00:00', $event['end'] );
		$this->assertSame( 'Oregon Convention Center', $event['location'] );
		$this->assertSame( get_permalink( $event_id ), $event['url'] );
	}

	/**
	 * An event with no venue meta maps to an empty location, not an error.
	 */
	public function test_missing_venue_maps_to_empty_location(): void {
		$fixture  = $this->fixture();
		$event_id = self::factory()->post->create(
			[
				'post_type'   => 'tribe_events',
				'post_title'  => $fixture['title'],
				'post_status' => 'publish',
			]
		);
		update_post_meta( $event_id, '_EventStartDate', $fixture['meta']['_EventStartDate'] );

		$event = Calendar_Events::get_event( Calendar_Events::SOURCE_THE_EVENTS_CALENDAR, $event_id );

		$this->assertIsArray( $event );
		$this->assertSame( '', $event['location'] );
		$this->assertSame( '', $event['end'] );
	}

	/**
	 * When detection says the plugin is inactive, the lookup returns null.
	 */
	public function test_returns_null_when_inactive(): void {
		$event_id = $this->create_fixture_event();

		add_filter( 'pkiw_calendar_source_active', '__return_false' );

		$this->assertNull(
			Calendar_Events::get_event( Calendar_Events::SOURCE_THE_EVENTS_CALENDAR, $event_id )
		);
	}

	/**
	 * A post that isn't a tribe event resolves to null.
	 */
	public function test_returns_null_for_non_event_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->assertNull(
			Calendar_Events::get_event( Calendar_Events::SOURCE_THE_EVENTS_CALENDAR, $post_id )
		);
	}

	/**
	 * A nonexistent event ID resolves to null.
	 */
	public function test_returns_null_for_missing_event(): void {
		$this->assertNull(
			Calendar_Events::get_event( Calendar_Events::SOURCE_THE_EVENTS_CALENDAR, 999999 )
		);
	}
}

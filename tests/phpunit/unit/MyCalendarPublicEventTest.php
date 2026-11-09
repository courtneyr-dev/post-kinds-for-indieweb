<?php
/**
 * Public-event rules for the My Calendar provider.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Calendar_Events;

if ( ! function_exists( 'mc_get_event_core' ) ) {
	function mc_get_event_core( $event_id ) {
		return $GLOBALS['pkiw_test_mc_events'][ (int) $event_id ] ?? false;
	}
}

if ( ! function_exists( 'mc_get_event' ) ) {
	function mc_get_event( $occurrence_id ) {
		return $GLOBALS['pkiw_test_mc_occurrences'][ (int) $occurrence_id ] ?? false;
	}
}

if ( ! function_exists( 'mc_get_private_categories' ) ) {
	function mc_get_private_categories() {
		return $GLOBALS['pkiw_test_mc_private_categories'] ?? [];
	}
}

if ( ! function_exists( 'mc_event_states_type' ) ) {
	function mc_event_states_type( $state ) {
		$types = [
			'0' => 'hidden',
			'1' => 'public',
			'2' => 'hidden',
			'3' => 'public',
			'4' => 'private',
			'5' => 'private',
			'6' => 'hidden',
		];

		return apply_filters( 'mc_event_states_type', $types[ (string) $state ] ?? 'hidden', $state );
	}
}

/**
 * @covers \PKIW\Integrations\My_Calendar
 * @covers \PKIW\Integrations\Calendar_Events
 */
final class MyCalendarPublicEventTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset(
			$GLOBALS['pkiw_test_mc_events'],
			$GLOBALS['pkiw_test_mc_occurrences'],
			$GLOBALS['pkiw_test_mc_private_categories']
		);
		remove_all_filters( 'mc_private_event' );
		remove_all_filters( 'mc_event_states_type' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function event( int $id = 231 ): object {
		return (object) [
			'event_id'        => $id,
			'event_title'     => 'Public calendar event',
			'event_begin'     => '2026-11-11',
			'event_time'      => '18:00:00',
			'event_end'       => '2026-11-11',
			'event_endtime'   => '19:00:00',
			'event_link'      => 'https://example.com/event',
			'event_approved'  => 1,
			'event_flagged'   => 0,
			'event_category'  => 1,
			'category_name'   => 'General',
			'category_private' => 0,
			'categories'      => [
				(object) [
					'category_id'      => 1,
					'category_name'    => 'General',
					'category_private' => 0,
				],
			],
			'event_label'     => 'Example Hall',
			'event_location'  => 0,
		];
	}

	private function resolve( object $event ): ?array {
		$GLOBALS['pkiw_test_mc_events'] = [ (int) $event->event_id => $event ];

		return Calendar_Events::get_event( Calendar_Events::SOURCE_MY_CALENDAR, (int) $event->event_id );
	}

	public function test_private_category_event_does_not_resolve(): void {
		$event                              = $this->event();
		$event->event_title                 = 'SENTINEL_PRIVATE_CAL';
		$event->category_private            = 1;
		$event->categories[0]->category_private = 1;

		$this->assertNull( $this->resolve( $event ) );
	}

	public function test_joined_private_category_column_blocks_resolution_by_itself(): void {
		$event                   = $this->event();
		$event->category_private = 1;

		$this->assertSame( 0, $event->categories[0]->category_private );
		$this->assertNull( $this->resolve( $event ) );
	}

	public function test_private_secondary_category_blocks_public_primary_category(): void {
		$event               = $this->event();
		$event->categories[] = (object) [
			'category_id'      => 2,
			'category_name'    => 'Members',
			'category_private' => 1,
		];

		$this->assertSame( 0, $event->category_private );
		$this->assertSame( 0, $event->categories[0]->category_private );
		$this->assertNull( $this->resolve( $event ) );
	}

	public function test_approved_event_resolves(): void {
		$resolved = $this->resolve( $this->event() );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'Public calendar event', $resolved['name'] );
	}

	public function test_draft_trash_scheduled_private_personal_do_not_resolve(): void {
		foreach ( [ 0, 2, 4, 5, 6 ] as $state ) {
			$event                 = $this->event( 231 + $state );
			$event->event_approved = $state;
			$this->assertNull( $this->resolve( $event ), 'State ' . $state . ' must not resolve.' );
		}
	}

	public function test_spam_flagged_event_does_not_resolve(): void {
		$event                = $this->event();
		$event->event_flagged = 1;

		$this->assertNull( $this->resolve( $event ) );
	}

	public function test_cancelled_event_resolves_with_status(): void {
		$event                 = $this->event();
		$event->event_approved = 3;

		$resolved = $this->resolve( $event );

		$this->assertIsArray( $resolved );
		$this->assertSame( 'cancelled', $resolved['status'] );
	}

	public function test_occurrence_id_is_never_used_as_event_id(): void {
		$occurrence_id                              = 999;
		$GLOBALS['pkiw_test_mc_events']              = [];
		$GLOBALS['pkiw_test_mc_occurrences'][999]    = $this->event( 231 );

		$this->assertNull(
			Calendar_Events::get_event( Calendar_Events::SOURCE_MY_CALENDAR, $occurrence_id )
		);
	}

	public function test_private_category_registry_and_private_filter_each_block_resolution(): void {
		$event                                      = $this->event();
		$GLOBALS['pkiw_test_mc_private_categories'] = [ 1 ];
		$this->assertNull( $this->resolve( $event ) );

		$GLOBALS['pkiw_test_mc_private_categories'] = [];
		add_filter( 'mc_private_event', '__return_true' );
		$this->assertNull( $this->resolve( $event ) );
	}

	public function test_logged_in_administrator_gets_the_anonymous_public_rule(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$event                 = $this->event();
		$event->event_approved = 4;

		$this->assertNull( $this->resolve( $event ) );
	}
}

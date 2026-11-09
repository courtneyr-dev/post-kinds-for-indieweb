<?php
/**
 * Location privacy filters for My Calendar surfaces.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\My_Calendar_Privacy;

/**
 * @covers \PKIW\Integrations\My_Calendar_Privacy
 */
final class MyCalendarLocationPrivacyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'pkiw_settings' );
		wp_set_current_user( 0 );
		My_Calendar_Privacy::register();
	}

	public function tear_down(): void {
		delete_option( 'pkiw_settings' );
		remove_all_filters( 'pkiw_my_calendar_use_optional_helper' );
		unset( $GLOBALS['pkiw_test_mc_categories'], $GLOBALS['pkiw_test_mc_locations'] );
		$this->reset_needles();
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	private function fixture(): object {
		$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/my-calendar/sentinel-event.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertIsString( $json );

		return json_decode( $json );
	}

	private function tags(): array {
		return [
			'title'            => 'Sentinel Hidden Meetup',
			'excerpt'          => '',
			'description'      => '',
			'shortdesc'        => '',
			'image_url'        => '',
			'linking'          => 'https://example.com/sentinel-hidden-meetup',
			'dtstart'          => '2026-11-11T18:00:00',
			'dtend'            => '2026-11-11T19:00:00',
			'duration'         => 'PT1H',
			'location'         => 'Sentinel Hall',
			'street'           => '1 Sentinel Way',
			'street2'          => 'Suite 231',
			'city'             => 'Example City',
			'state'            => 'PA',
			'postcode'         => '19000',
			'region'           => 'Example Region',
			'country'          => 'US',
			'phone'            => '555-0231',
			'phone2'           => '555-0232',
			'ical_location'    => 'Sentinel Hall 1 Sentinel Way',
			'location_maptype' => 'roadmap',
			'gcal'             => 'https://calendar.example/?location=' . urlencode( 'Sentinel Hall 1 Sentinel Way' ),
			'outlook'          => 'https://calendar.example/?location=' . rawurlencode( 'Sentinel Hall 1 Sentinel Way' ),
			'hcard'            => '<span>Sentinel Hall, 1 Sentinel Way</span>',
			'map'              => '<div data-lat="0.123456" data-lng="0.654321"></div>',
		];
	}

	private function schema(): array {
		return [
			'@type'               => 'Event',
			'name'                => 'Sentinel Hidden Meetup',
			'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
			'location'            => [
				'@type'   => 'Place',
				'name'    => 'Sentinel Hall',
				'address' => [ 'streetAddress' => '1 Sentinel Way' ],
				'geo'     => [ 'latitude' => '0.123456', 'longitude' => '0.654321' ],
			],
		];
	}

	private function reset_needles(): void {
		( new ReflectionProperty( My_Calendar_Privacy::class, 'needles' ) )->setValue( null, [] );
	}

	private function assert_no_sentinel( $value ): void {
		$json    = wp_json_encode( $value );
		$needles = [ 'Sentinel Hall', 'Sentinel Way', '0.123456', '0.654321' ];
		$this->assertIsString( $json );
		foreach ( $needles as $needle ) {
			foreach ( [ $needle, urlencode( $needle ), rawurlencode( $needle ), esc_html( $needle ) ] as $form ) {
				$this->assertStringNotContainsString( $form, $json );
			}
		}
	}

	public function test_00_object_without_optional_helpers_raises_no_error(): void {
		add_filter( 'pkiw_my_calendar_use_optional_helper', '__return_false' );
		update_option( 'pkiw_settings', [ 'event_hide_locations' => true ] );
		$event = (object) [ 'event_id' => 1, 'event_category' => 1, 'event_location' => 999999 ];

		$filtered = My_Calendar_Privacy::filter_event_object( $event );

		$this->assertNotSame( $event, $filtered );
		$this->assertTrue( $filtered->pkiw_location_hidden );
		$this->assertSame( 0, $filtered->event_location );
		$this->assertObjectNotHasProperty( 'categories', $filtered );
		$this->assertObjectNotHasProperty( 'location', $filtered );
	}

	public function test_optional_provider_helpers_use_guarded_registries(): void {
		if ( ! function_exists( 'mc_get_categories' ) ) {
			function mc_get_categories( $event, $type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return $GLOBALS['pkiw_test_mc_categories'][ (int) $event->event_id ] ?? [];
			}
		}
		if ( ! function_exists( 'mc_get_location' ) ) {
			function mc_get_location( $location_id ) {
				return $GLOBALS['pkiw_test_mc_locations'][ (int) $location_id ] ?? false;
			}
		}

		$event = $this->fixture();
		unset( $event->categories, $event->category_name, $event->location );
		foreach ( [ 'event_label', 'event_street', 'event_street2', 'event_latitude', 'event_longitude' ] as $field ) {
			$event->{$field} = '';
		}
		$GLOBALS['pkiw_test_mc_categories'][231231] = [ (object) [ 'category_name' => 'Hide location' ] ];
		$GLOBALS['pkiw_test_mc_locations'][999999]  = (object) [
			'location_label'     => 'Sentinel Hall',
			'location_street'    => '1 Sentinel Way',
			'location_street2'   => '',
			'location_latitude'  => '0.123456',
			'location_longitude' => '0.654321',
		];

		$clone = My_Calendar_Privacy::filter_event_object( $event );
		$tags  = My_Calendar_Privacy::filter_shortcodes( $this->tags(), $clone );

		$this->assertTrue( $clone->pkiw_location_hidden );
		$this->assert_no_sentinel( $tags );
	}

	public function test_category_and_switch_hide_location_across_object_tags_schema_and_visibility(): void {
		foreach ( [ 'category', 'switch' ] as $case ) {
			$event = $this->fixture();
			if ( 'switch' === $case ) {
				$event->category_name                       = 'General';
				$event->categories[0]->category_name        = 'General';
				update_option( 'pkiw_settings', [ 'event_hide_locations' => true ] );
			}

			$before = wp_json_encode( $event );
			$clone  = apply_filters( 'mc_event_object', $event );
			$tags   = apply_filters( 'mc_filter_shortcodes', $this->tags(), $clone );
			$this->reset_needles();
			$cached = apply_filters( 'mc_filter_shortcodes', $this->tags(), $event );
			$schema = apply_filters( 'mc_event_schema', $this->schema(), $clone, $this->tags() );

			$this->assertNotSame( $event, $clone );
			$this->assertSame( $before, wp_json_encode( $event ) );
			$this->assertTrue( $clone->pkiw_location_hidden );
			$this->assertSame( 0, $clone->event_location );
			$this->assertObjectNotHasProperty( 'location', $clone );
			$this->assertSame( '', $tags['location_maptype'] );
			$this->assertArrayNotHasKey( 'location', $schema );
			$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $schema['eventAttendanceMode'] );

			$this->assert_no_sentinel( $clone );
			$this->assert_no_sentinel( $tags );
			$this->assert_no_sentinel( $cached );
			$this->assert_no_sentinel( $schema );

			foreach ( [ 'address', 'gmap', 'gmap_link', 'map' ] as $feature ) {
				$this->assertFalse( apply_filters( 'mc_output_is_visible', true, $feature, 'main', $clone ) );
			}
			delete_option( 'pkiw_settings' );
		}
	}

	public function test_positive_control_keeps_allowed_location(): void {
		$event                              = $this->fixture();
		$event->category_name               = 'General';
		$event->categories[0]->category_name = 'General';

		$object = apply_filters( 'mc_event_object', $event );
		$tags   = apply_filters( 'mc_filter_shortcodes', $this->tags(), $object );

		$this->assertSame( $event, $object );
		$this->assertSame( 'Sentinel Hall', $tags['location'] );
		$this->assertTrue( apply_filters( 'mc_output_is_visible', true, 'address', 'main', $object ) );
	}

	public function test_admin_non_ajax_callbacks_preserve_inputs(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit.php' );
		$event  = $this->fixture();
		$tags   = $this->tags();
		$schema = $this->schema();
		$args   = [ 'ltype' => 'name', 'lvalue' => [ 'Sentinel Hall' ] ];
		$limit  = "location_label = 'Sentinel Hall'";
		$cats   = $event->categories;

		$this->assertSame( $event, apply_filters( 'mc_event_object', $event ) );
		$this->assertSame( $tags, apply_filters( 'mc_filter_shortcodes', $tags, $event ) );
		$this->assertSame( $schema, apply_filters( 'mc_event_schema', $schema, $event, $tags ) );
		$this->assertTrue( apply_filters( 'mc_output_is_visible', true, 'address', 'main', $event ) );
		$this->assertSame( $args, apply_filters( 'my_calendar_events_args', $args ) );
		$this->assertSame( $limit, apply_filters( 'mc_location_limit_sql', $limit, 'name', [ 'Sentinel Hall' ] ) );
		$this->assertSame( $cats, apply_filters( 'mc_category_key_array', $cats, '', '' ) );
	}

	public function test_location_queries_and_hide_category_leave_no_public_oracle(): void {
		foreach ( [ 'name' => 'Sentinel Hall', 'location_street' => '1 Sentinel Way' ] as $type => $value ) {
			$args = apply_filters( 'my_calendar_events_args', [ 'ltype' => $type, 'lvalue' => [ $value ], 'limit' => 10 ] );
			$this->assertArrayNotHasKey( 'ltype', $args );
			$this->assertArrayNotHasKey( 'lvalue', $args );
			$this->assertSame( 10, $args['limit'] );
		}

		$this->assertSame( '', apply_filters( 'mc_location_limit_sql', "location_label = 'Sentinel Hall'", 'name', [ 'Sentinel Hall' ] ) );
		$this->assertSame( '', apply_filters( 'mc_location_limit_sql', "location_street = '1 Sentinel Way'", 'location_street', [ '1 Sentinel Way' ] ) );
		// $_GET goes through map_deep(), so ?ltype[]=name reaches the filter as an array.
		$this->assertSame( '', apply_filters( 'mc_location_limit_sql', "location_label = 'Sentinel Hall'", [ 'name' ], [ 'Sentinel Hall' ] ) );

		$rest_limit = null;
		$rest_probe = static function ( $result ) use ( &$rest_limit ) {
			$rest_limit = apply_filters( 'mc_location_limit_sql', "location_label = 'Sentinel Hall'", 'name', [ 'Sentinel Hall' ] );

			return $result;
		};
		add_filter( 'rest_pre_dispatch', $rest_probe );
		rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' ) );
		remove_filter( 'rest_pre_dispatch', $rest_probe );
		$this->assertSame( '', $rest_limit );

		$categories = apply_filters(
			'mc_category_key_array',
			[
				(object) [ 'category_id' => 1, 'category_name' => 'General' ],
				(object) [ 'category_id' => 2, 'category_name' => 'Hide location' ],
			],
			'',
			''
		);
		$this->assertCount( 1, $categories );
		$this->assertSame( 'General', $categories[0]->category_name );
	}

	public function test_shortcode_scrubber_runs_after_my_calendar_callbacks(): void {
		$this->assertSame( 99, has_filter( 'mc_filter_shortcodes', [ My_Calendar_Privacy::class, 'filter_shortcodes' ] ) );
	}

	public function test_register_is_idempotent(): void {
		My_Calendar_Privacy::register();

		$hook = $GLOBALS['wp_filter']['mc_event_object'];
		$this->assertCount( 1, $hook->callbacks[10] );
	}
}

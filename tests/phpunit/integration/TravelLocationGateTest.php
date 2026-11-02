<?php
/** Travel suppression of Simple Location surfaces. @package PKIW */

declare(strict_types=1);

use PKIW\Taxonomy;
use PKIW\Travel_Location;

/** @group integration */
final class TravelLocationGateTest extends WP_UnitTestCase {

	/** @var array<int, array{0:string,1:callable,2:int}> */
	private array $probes = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		Travel_Location::reset();
		( new Travel_Location() )->register();
	}

	public function tear_down(): void {
		foreach ( $this->probes as [ $hook, $callback, $priority ] ) {
			remove_filter( $hook, $callback, $priority );
		}
		foreach ( [ [ 'the_content', 'Geo_Data', 'content_map', 11 ], [ 'the_content', 'Geo_Data', 'location_content', 12 ], [ 'wp_head', 'Geo_Base', 'meta_tags', 10 ] ] as $row ) {
			remove_filter( $row[0], [ $row[1], $row[2] ], $row[3] );
		}
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			remove_filter( $hook, [ 'Geo_Base', 'georss_item' ], 10 );
		}
		Travel_Location::reset();
		wp_reset_postdata();
		parent::tear_down();
	}

	private function post( string $kind ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<p>Body</p>' ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		return (int) $id;
	}

	private function setPost( int $id ): void {
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
	}

	private function registerSimpleLocation(): void {
		add_filter( 'the_content', [ 'Geo_Data', 'content_map' ], 11 );
		add_filter( 'the_content', [ 'Geo_Data', 'location_content' ], 12 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, [ 'Geo_Base', 'georss_item' ], 10 );
		}
		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
	}

	/** @dataProvider travelKinds */
	public function test_content_and_feed_callbacks_are_removed_and_exactly_restored( string $kind ): void {
		$this->registerSimpleLocation();
		$this->setPost( $this->post( $kind ) );
		Travel_Location::gate_content( 'body' );
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertFalse( has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
		Travel_Location::restore_content( 'body' );
		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );

		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			$GLOBALS['wp_current_filter'][] = $hook;
			try {
				Travel_Location::gate_feed_item();
				$this->assertFalse( has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ), $hook );
				Travel_Location::restore_feed_item();
			} finally {
				array_pop( $GLOBALS['wp_current_filter'] );
			}
			$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ), $hook );
		}
	}

	public static function travelKinds(): array {
		return [ 'trip' => [ 'trip' ], 'itinerary' => [ 'itinerary' ] ];
	}

	public function test_note_keeps_content_feed_and_head_callbacks(): void {
		$this->registerSimpleLocation();
		$id = $this->post( 'note' );
		$this->setPost( $id );
		Travel_Location::gate_content( 'body' );
		$this->assertSame( 11, has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ) );
		$this->assertSame( 12, has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) );
		Travel_Location::restore_content( 'body' );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			$GLOBALS['wp_current_filter'][] = $hook;
			try {
				Travel_Location::gate_feed_item();
				$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ), $hook );
				Travel_Location::restore_feed_item();
			} finally {
				array_pop( $GLOBALS['wp_current_filter'] );
			}
		}
		$this->go_to( get_permalink( $id ) );
		Travel_Location::suppress_head_meta();
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	/** @dataProvider travelKinds */
	public function test_head_meta_is_suppressed_on_travel_singles( string $kind ): void {
		$this->registerSimpleLocation();
		$id = $this->post( $kind );
		$this->go_to( get_permalink( $id ) );

		Travel_Location::suppress_head_meta();

		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_note_is_the_control_for_json_rest_and_place_queries(): void {
		$note = get_post( $this->post( 'note' ) );
		$item = [ 'geo' => [ 'latitude' => 1 ] ];
		$this->assertSame( $item, Travel_Location::strip_json_feed_geo( $item, $note ) );

		$response = new WP_REST_Response( [ 'latitude' => 1, 'longitude' => 2, 'geo_address' => 'Home', 'timezone' => 'UTC', 'meta' => [ 'geo_public' => '1' ] ] );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $note->ID );
		$this->assertSame( $response->get_data(), Travel_Location::strip_rest_location( $response, $note, $request )->get_data() );
	}

	/** @dataProvider travelKinds */
	public function test_travel_json_rest_and_place_queries_strip_location( string $kind ): void {
		$post = get_post( $this->post( $kind ) );
		$this->assertArrayNotHasKey( 'geo', Travel_Location::strip_json_feed_geo( [ 'geo' => [ 'latitude' => 1 ] ], $post ) );

		$response = new WP_REST_Response( [ 'latitude' => 1, 'longitude' => 2, 'geo_address' => 'Home', 'timezone' => 'UTC', 'indieblocks_location' => [ 'name' => 'Home' ], 'meta' => [ 'geo_public' => '1', '_pkiw_geo_privacy' => 'public' ], 'date' => 'x', 'modified' => 'x' ] );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post->ID );
		$data = Travel_Location::strip_rest_location( $response, $post, $request )->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone', 'indieblocks_location' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $data );
		}
		$this->assertSame( [], $data['meta'] );
		$this->assertSame( mysql_to_rfc3339( $post->post_date ), $data['date'] );
		$this->assertSame( mysql_to_rfc3339( $post->post_modified ), $data['modified'] );

		$query = $this->getMockBuilder( WP_Query::class )->onlyMethods( [ 'is_main_query' ] )->getMock();
		$query->method( 'is_main_query' )->willReturn( true );
		$query->set( 'map', 1 );
		Travel_Location::exclude_from_place_listings( $query );
		$this->assertSame( [ 'trip', 'itinerary' ], $query->get( 'tax_query' )[0]['terms'] );
	}

	public function test_every_place_listing_trigger_excludes_only_trip_and_itinerary(): void {
		foreach ( [ 'map', 'geo', 'location', 'pkiw_venue' ] as $trigger ) {
			$query = $this->getMockBuilder( WP_Query::class )->onlyMethods( [ 'is_main_query', 'is_tax' ] )->getMock();
			$query->method( 'is_main_query' )->willReturn( true );
			$query->method( 'is_tax' )->willReturnCallback( static fn( string $taxonomy ): bool => $taxonomy === $trigger );
			if ( in_array( $trigger, [ 'map', 'geo' ], true ) ) {
				$query->set( $trigger, 1 );
			}

			Travel_Location::exclude_from_place_listings( $query );

			$this->assertSame( [ 'trip', 'itinerary' ], $query->get( 'tax_query' )[0]['terms'], $trigger );
			$this->assertSame( 'NOT IN', $query->get( 'tax_query' )[0]['operator'], $trigger );
		}
	}

	/** @dataProvider travelKinds */
	public function test_all_date_getters_keep_travel_in_site_timezone_and_leave_note_as_control( string $kind ): void {
		update_option( 'timezone_string', 'America/New_York' );
		$tokyo = static function ( $value, $format = '', $post = null ) {
			$post = get_post( $post );
			if ( ! $post instanceof WP_Post ) {
				return $value;
			}
			$hook     = current_filter();
			$modified = str_contains( $hook, 'modified' );
			$moment   = get_post_datetime( $post, $modified ? 'modified' : 'date' );
			return $moment ? wp_date( (string) $format, $moment->getTimestamp(), new DateTimeZone( 'Asia/Tokyo' ) ) : $value;
		};
		foreach ( [ 'get_the_date', 'get_the_time', 'get_the_modified_date', 'get_the_modified_time' ] as $hook ) {
			add_filter( $hook, $tokyo, 9, 3 );
			$this->probes[] = [ $hook, $tokyo, 9 ];
		}

		$args = [
			'post_status'       => 'publish',
			'post_date'         => '2000-01-02 03:04:05',
			'post_date_gmt'     => '2000-01-02 08:04:05',
			'post_modified'     => '2000-01-03 04:05:06',
			'post_modified_gmt' => '2000-01-03 09:05:06',
		];
		$travel = self::factory()->post->create( $args );
		$note   = self::factory()->post->create( $args );
		wp_set_object_terms( $travel, $kind, Taxonomy::TAXONOMY );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );

		foreach ( [ 'get_the_date', 'get_the_time', 'get_the_modified_date', 'get_the_modified_time' ] as $getter ) {
			$travel_value = $getter( 'c', $travel );
			$note_value   = $getter( 'c', $note );
			$this->assertStringContainsString( '-05:00', (string) $travel_value, "{$getter} keeps {$kind} in the site timezone." );
			$this->assertStringContainsString( '+09:00', (string) $note_value, "{$getter} leaves the note to Simple Location." );
		}
	}
}

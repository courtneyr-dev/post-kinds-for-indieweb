<?php
/** Travel map points, pins, routes and summaries. @package PKIW */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;
use PKIW\Travel_Archive;
use PKIW\Travel_Map;

/** @group integration */
final class TravelMapTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	private function place( string $name, float $lat, float $lng ): array {
		return [
			'name' => $name, 'locality' => 'Portland', 'region' => 'Oregon', 'country' => 'US',
			'street' => '', 'postal_code' => '', 'url' => '', 'osm_id' => '', 'venue_id' => '',
			'lat' => $lat, 'lng' => $lng,
		];
	}

	private function trip( string $title, array $stops, string $privacy = 'public' ): WP_Post {
		$id = self::factory()->post->create( [
			'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2000-05-01 09:00:00', 'post_excerpt' => '',
		] );
		wp_set_object_terms( $id, Travel::TRIP, Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', $privacy );
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( $stops ) );
		return get_post( $id );
	}

	private function itinerary( array $legs ): WP_Post {
		$id = self::factory()->post->create( [
			'post_status' => 'publish', 'post_title' => 'Rail itinerary', 'post_date' => '2000-05-01 09:00:00', 'post_excerpt' => '',
		] );
		wp_set_object_terms( $id, Travel::ITINERARY, Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $id, Travel::LEGS_KEY, wp_json_encode( $legs ) );
		return get_post( $id );
	}

	private function stop( string $name, float $lat, float $lng, string $privacy = 'public' ): array {
		return $this->place( $name, $lat, $lng ) + [ 'date' => '2000-05-04', 'privacy' => $privacy ];
	}

	public function test_points_keep_order_and_itinerary_collapses_consecutive_places(): void {
		$trip = $this->trip( 'Coast trip', [
			$this->stop( 'Portland', 45.5152, -122.6784 ),
			$this->stop( 'Seattle', 47.6062, -122.3321 ),
		] );
		$this->assertSame( [ 1, 2 ], array_column( Travel_Map::points_for( $trip ), 'number' ) );

		$portland = $this->place( 'Portland', 45.5152, -122.6784 );
		$seattle  = $this->place( 'Seattle', 47.6062, -122.3321 );
		$vancouver = $this->place( 'Vancouver', 49.2827, -123.1207 );
		$post = $this->itinerary( [
			[ 'from' => $portland, 'to' => $seattle, 'departure' => '2000-05-04T08:00:00-07:00', 'arrival' => '2000-05-04T12:00:00-07:00', 'carrier' => 'Rail', 'privacy' => 'public' ],
			[ 'from' => $seattle, 'to' => $vancouver, 'departure' => '2000-05-05T08:00:00-07:00', 'arrival' => '2000-05-05T12:00:00-07:00', 'carrier' => 'Rail', 'privacy' => 'public' ],
		] );
		$points = Travel_Map::points_for( $post );
		$this->assertCount( 3, $points );
		$this->assertSame( [ 45.5152, 47.6062, 49.2827 ], array_column( $points, 'lat' ) );
	}

	public function test_pins_merge_at_four_decimals_and_routes_need_two_points(): void {
		$one = $this->trip( 'First trip', [ $this->stop( 'One', 40.111111, -75.111111 ), $this->stop( 'Two', 41.2, -76.2 ) ] );
		$two = $this->trip( 'Second trip', [ $this->stop( 'Same', 40.111119, -75.111118 ) ] );
		$points = array_merge( Travel_Map::points_for( $one ), Travel_Map::points_for( $two ) );
		$pins = Travel_Map::pins( $points );
		$this->assertCount( 2, $pins );
		$this->assertSame( [ $one->ID, $two->ID ], $pins[0]['ids'] );
		$this->assertStringStartsWith( '2 trips at this location', $pins[0]['label'] );
		$single_pin = Travel_Map::pins( [
			[ 'lat' => 40.1, 'lng' => -75.1, 'entry' => $one->ID, 'number' => 1, 'title' => 'First trip' ],
			[ 'lat' => 40.1, 'lng' => -75.1, 'entry' => $one->ID, 'number' => 3, 'title' => 'First trip' ],
		] );
		$this->assertSame( [ 1, 3 ], $single_pin[0]['numbers'], 'A single map keeps its visible stop numbers when stops share a place.' );

		$routes = Travel_Map::routes( [ $one, $two ] );
		$this->assertCount( 1, $routes );
		$this->assertSame( $one->ID, $routes[0]['entry'] );
		$this->assertCount( 2, $routes[0]['points'] );
	}

	public function test_page_numbers_skip_unmapped_posts_and_summaries_name_the_kind(): void {
		$mapped   = $this->trip( 'Mapped', [ $this->stop( 'Map', 40.1, -75.1 ) ] );
		$unmapped = $this->trip( 'Unmapped', [ $this->stop( 'Text', 41.1, -76.1, 'approximate' ) ] );
		$mapped2  = $this->trip( 'Mapped again', [ $this->stop( 'Map 2', 42.1, -77.1 ) ] );
		$this->assertSame( [ $mapped->ID => 1, $mapped2->ID => 2 ], Travel_Map::page_numbers( [ $mapped, $unmapped, $mapped2 ] ) );
		$this->assertSame( '6 trips · 4 mapped', Travel_Map::summary( Travel::TRIP, 6, 4 ) );
		$this->assertSame( '1 itinerary', Travel_Map::summary( Travel::ITINERARY, 1, 0 ) );
	}

	public function test_page_numbers_change_when_visibility_changes_within_one_request(): void {
		$first  = $this->trip( 'First mapped trip', [ $this->stop( 'First', 40.1, -75.1 ) ] );
		$second = $this->trip( 'Second mapped trip', [ $this->stop( 'Second', 41.1, -76.1 ) ] );
		$this->assertSame( [ $first->ID => 1, $second->ID => 2 ], Travel_Map::page_numbers( [ $first, $second ] ) );

		update_post_meta( $first->ID, '_pkiw_geo_privacy', 'private' );
		$this->assertSame( [ $second->ID => 1 ], Travel_Map::page_numbers( [ $first, $second ] ) );
	}

	public function test_page_numbers_match_the_numbers_printed_in_entries(): void {
		$first  = $this->trip( 'First entry', [ $this->stop( 'First', 40.1, -75.1 ) ] );
		$second = $this->trip( 'Second entry', [ $this->stop( 'Second', 41.1, -76.1 ) ] );
		$posts  = [ $first, $second ];
		$this->assertSame( [ $first->ID => 1, $second->ID => 2 ], Travel_Map::page_numbers( $posts ) );

		$points = [];
		foreach ( $posts as $post ) {
			foreach ( Travel_Map::points_for( $post ) as $point ) {
				$point['number'] = Travel_Map::number_for( $post->ID );
				$points[]        = $point;
			}
		}
		Travel_Map::figure( Travel_Map::pins( $points ), [], 'Map', 'summary' );

		foreach ( $posts as $expected => $post ) {
			$html = Travel_Archive::entry_html( $post, Travel::TRIP, [ 'headingLevel' => 2 ], true );
			$this->assertStringContainsString( '>' . ( $expected + 1 ) . '</span>', $html );
		}
	}

	public function test_map_view_stays_a_classic_script(): void {
		$script = (string) file_get_contents( PKIW_PATH . 'assets/js/map-view.js' );
		$this->assertDoesNotMatchRegularExpression( '/^\s*(?:import|export)\b/m', $script );
	}

	public function test_figure_is_empty_without_pins_and_prints_visible_route_data(): void {
		$this->assertSame( '', Travel_Map::figure( [], [], 'Map', 'summary' ) );
		$pins = [ [ 'lat' => 40.1, 'lng' => -75.1, 'ids' => [ 244 ], 'numbers' => [ 1 ], 'label' => '1: Trip' ] ];
		$routes = [ [ 'entry' => 244, 'points' => [ [ 40.1, -75.1 ], [ 41.1, -76.1 ] ] ] ];
		$html = Travel_Map::figure( $pins, $routes, 'Map of trips', 'summary-244' );
		$this->assertStringContainsString( 'class="pkiw-travel-map"', $html );
		$this->assertStringContainsString( 'aria-describedby="summary-244"', $html );
		$this->assertStringContainsString( 'data-routes=', $html );
		$this->assertStringContainsString( 'Map of trips', $html );
		$this->assertTrue( wp_script_is( 'pkiw-checkin-map', 'enqueued' ) );
	}
}

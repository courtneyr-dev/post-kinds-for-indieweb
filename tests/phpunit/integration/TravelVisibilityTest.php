<?php
/** Travel visibility filtering. @package PKIW */

declare(strict_types=1);

use PKIW\Travel;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/** @group integration */
final class TravelVisibilityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
	}

	private function fields( array $visible ): array {
		return array_merge(
			array_fill_keys( [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'coordinates', 'map', 'url', 'osm_id', 'venue_id' ], false ),
			array_fill_keys( $visible, true )
		);
	}

	private function stop( string $privacy = 'public' ): array {
		return [
			'name' => 'Contract Harbor', 'locality' => 'Portland', 'region' => 'Oregon', 'country' => 'US',
			'street' => '2 Contract Street', 'postal_code' => '97205', 'url' => 'https://example.test/place',
			'osm_id' => 'osm-1', 'venue_id' => 'venue-1', 'lat' => 45.5152, 'lng' => -122.6784,
			'date' => '2000-05-04', 'privacy' => $privacy,
		];
	}

	public function test_all_item_and_real_post_tiers_combine_per_field(): void {
		$all = $this->fields( [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'coordinates', 'map', 'url', 'osm_id', 'venue_id' ] );
		$approx = $this->fields( [ 'name', 'locality', 'region', 'country' ] );
		$text = $this->fields( [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'url' ] );
		$none = array_fill_keys( array_keys( $all ), false );
		$posts = [
			'unset'       => [ null, null, $approx ],
			'private'     => [ 'private', null, $none ],
			'approximate' => [ 'approximate', null, $approx ],
			'public'      => [ 'public', null, $all ],
			'geo public 0' => [ 'public', '0', $none ],
			'geo public 1' => [ 'public', '1', $all ],
			'geo public 2' => [ 'public', '2', $text ],
		];

		foreach ( $posts as $label => [ $privacy, $geo_public, $post_fields ] ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $id, 'trip', Taxonomy::TAXONOMY );
			if ( null !== $privacy ) {
				update_post_meta( $id, '_pkiw_geo_privacy', $privacy );
			}
			if ( null !== $geo_public ) {
				update_post_meta( $id, 'geo_public', $geo_public );
			}
			$this->assertSame( $post_fields, Meta_Fields::get_public_location_fields( $id ), $label );
			$this->assertSame( $none, Travel::item_fields( 'private', $post_fields ), "private item, {$label} post" );
			$expected_approximate = [];
			foreach ( $approx as $key => $visible ) {
				$expected_approximate[ $key ] = $visible && $post_fields[ $key ];
			}
			$this->assertSame( $expected_approximate, Travel::item_fields( 'approximate', $post_fields ), "approximate item, {$label} post" );
			$this->assertSame( $post_fields, Travel::item_fields( 'public', $post_fields ), "public item, {$label} post" );
		}
	}

	public function test_unset_post_caps_public_stop_to_approximate_text_without_coordinates(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2000-05-01 09:00:00' ] );
		wp_set_object_terms( $id, 'trip', Taxonomy::TAXONOMY );
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( [ $this->stop() ] ) );

		$visible = Travel::visible_stops( $id );
		$this->assertSame( 'Portland', $visible[0]['place']['locality'] );
		$this->assertArrayNotHasKey( 'street', $visible[0]['place'] );
		$this->assertNull( $visible[0]['lat'] );
		$this->assertNull( $visible[0]['lng'] );
	}

	public function test_approximate_stop_with_only_hidden_street_keeps_its_past_date(): void {
		$stop = array_fill_keys( Travel::PLACE_FIELDS, '' ) + [
			'street' => '1 Sentinel Way',
			'lat' => null,
			'lng' => null,
			'date' => '2000-05-04',
			'privacy' => 'approximate',
		];
		$visible = Travel::filter_items( 'trip', [ $stop ], $this->fields( [ 'name', 'locality', 'region', 'country' ] ), '2000-01-01', time(), [ 'date' => 'F j, Y', 'time' => 'g:i a' ] );

		$this->assertCount( 1, $visible );
		$this->assertSame( [], $visible[0]['place'] );
		$html = Travel::render_stop_list( $visible, 244 );
		$this->assertSame( 1, substr_count( $html, '<li ' ) );
		$this->assertStringContainsString( 'May 4, 2000', $html );
		$this->assertStringNotContainsString( '1 Sentinel Way', $html );
	}

	public function test_filter_strips_fields_coordinates_and_stored_positions_then_renumbers(): void {
		$raw = [ $this->stop( 'private' ), $this->stop( 'approximate' ), $this->stop( 'public' ) ];
		$all = $this->fields( [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'coordinates', 'map', 'url', 'osm_id', 'venue_id' ] );
		$visible = Travel::filter_items( 'trip', $raw, $all, '2000-05-01', time(), [ 'date' => 'Y-m-d', 'time' => 'H:i' ] );

		$this->assertCount( 2, $visible );
		$this->assertSame( [ 1, 2 ], array_column( $visible, 'number' ) );
		$this->assertArrayNotHasKey( 'privacy', $visible[0] );
		$this->assertArrayNotHasKey( 'date', $visible[0] );
		$this->assertArrayNotHasKey( 'street', $visible[0]['place'] );
		$this->assertNull( $visible[0]['lat'] );
		$this->assertSame( 45.5152, $visible[1]['lat'] );
	}

	public function test_coordinates_need_both_coordinate_and_map_permissions(): void {
		foreach ( [ [ 'coordinates' ], [ 'map' ] ] as $visible_fields ) {
			$visible = Travel::filter_items( 'trip', [ $this->stop() ], $this->fields( array_merge( [ 'name' ], $visible_fields ) ), '2000-01-01', time(), [ 'date' => 'Y-m-d', 'time' => 'H:i' ] );
			$this->assertNull( $visible[0]['lat'] );
			$this->assertNull( $visible[0]['lng'] );
		}
	}

	public function test_explicit_formats_make_option_changes_irrelevant(): void {
		$all = $this->fields( [ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'coordinates', 'map', 'url', 'osm_id', 'venue_id' ] );
		$leg = [ 'from' => $this->stop(), 'to' => $this->stop(), 'departure' => '2000-05-04T08:15:00', 'arrival' => '', 'carrier' => 'Rail', 'privacy' => 'public' ];
		$formats = [ 'date' => 'Y-m-d', 'time' => 'H:i' ];
		$before = get_option( 'date_format' );
		try {
			update_option( 'date_format', 'F j, Y' );
			$a = Travel::filter_items( 'itinerary', [ $leg ], $all, '2000-01-01', time(), $formats );
			update_option( 'date_format', 'd/m/Y' );
			$b = Travel::filter_items( 'itinerary', [ $leg ], $all, '2000-01-01', time(), $formats );
		} finally {
			update_option( 'date_format', $before );
		}
		$this->assertSame( $a, $b );
	}
}

<?php
/**
 * Travel meta sanitization.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Travel;

/**
 * @group integration
 */
final class TravelSanitizeTest extends WP_UnitTestCase {

	private function place( array $overrides = [] ): array {
		return array_merge(
			[
				'name'        => 'Harbor',
				'locality'    => 'Portland',
				'region'      => 'Oregon',
				'country'     => 'US',
				'street'      => '2 Contract Street',
				'postal_code' => '97205',
				'url'         => 'https://example.test/place',
				'osm_id'      => 'osm-1',
				'venue_id'    => 'venue-1',
				'lat'         => 45.51521234,
				'lng'         => -122.67841234,
			],
			$overrides
		);
	}

	public function test_bad_json_non_list_and_empty_list_store_empty_string(): void {
		$this->assertSame( '', Travel::sanitize_stops( '{' ) );
		$this->assertSame( '', Travel::sanitize_stops( wp_json_encode( [ 'name' => 'not a list' ] ) ) );
		$this->assertSame( '', Travel::sanitize_legs( '[]' ) );
	}

	public function test_stops_are_capped_canonical_and_sanitized(): void {
		$items = array_fill( 0, 51, $this->place() + [ 'date' => '2026-05-04', 'privacy' => 'public' ] );
		$items[0]['name'] = '<b>' . str_repeat( 'x', 205 ) . '</b>';
		$items[0]['url'] = 'javascript:alert(1)';
		$items[0]['lat'] = '45.12345678';
		$items[0]['lng'] = '-122.87654321';
		$items[0]['privacy'] = 'unknown';
		$items[1]['date'] = '2026-02-30';

		$decoded = json_decode( Travel::sanitize_stops( wp_json_encode( $items ) ), true );

		$this->assertCount( Travel::MAX_ITEMS, $decoded );
		$this->assertSame( Travel::PLACE_FIELDS + [], array_keys( array_intersect_key( $decoded[0], array_flip( Travel::PLACE_FIELDS ) ) ) );
		$this->assertSame(
			[ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'url', 'osm_id', 'venue_id', 'lat', 'lng', 'date', 'privacy' ],
			array_keys( $decoded[0] )
		);
		$this->assertSame( 200, mb_strlen( $decoded[0]['name'] ) );
		$this->assertSame( '', $decoded[0]['url'] );
		$this->assertSame( 45.123457, $decoded[0]['lat'] );
		$this->assertSame( -122.876543, $decoded[0]['lng'] );
		$this->assertSame( 'approximate', $decoded[0]['privacy'] );
		$this->assertSame( '', $decoded[1]['date'] );
	}

	public function test_one_invalid_coordinate_nulls_both_and_empty_places_drop(): void {
		$valid = $this->place( [ 'lat' => 90, 'lng' => 180 ] ) + [ 'date' => '', 'privacy' => 'public' ];
		$bad = $this->place( [ 'lat' => 91, 'lng' => 12 ] ) + [ 'date' => '', 'privacy' => 'public' ];
		$empty = array_fill_keys( Travel::PLACE_FIELDS, '' ) + [ 'lat' => null, 'lng' => null, 'date' => '2026-05-04', 'privacy' => 'public' ];

		$decoded = json_decode( Travel::sanitize_stops( wp_json_encode( [ $valid, $bad, $empty ] ) ), true );

		$this->assertCount( 2, $decoded );
		$this->assertSame( 90.0, $decoded[0]['lat'] );
		$this->assertSame( 180.0, $decoded[0]['lng'] );
		$this->assertNull( $decoded[1]['lat'] );
		$this->assertNull( $decoded[1]['lng'] );
	}

	public function test_legs_drop_date_only_and_unparseable_times_and_keep_offset_values_verbatim(): void {
		$legs = [
			[
				'from'      => $this->place(),
				'to'        => $this->place( [ 'name' => 'Terminal' ] ),
				'departure' => '2026-05-04',
				'arrival'   => 'not a date',
				'carrier'   => '<b>Contract Rail</b>',
				'privacy'   => 'public',
			],
			[
				'from'      => $this->place(),
				'to'        => [],
				'departure' => '2026-05-04T08:15:00-04:00',
				'arrival'   => '',
				'carrier'   => '',
				'privacy'   => 'private',
			],
			[
				'from' => [],
				'to'   => [],
			],
		];

		$decoded = json_decode( Travel::sanitize_legs( wp_json_encode( $legs ) ), true );

		$this->assertCount( 2, $decoded );
		$this->assertSame( '', $decoded[0]['departure'] );
		$this->assertSame( '', $decoded[0]['arrival'] );
		$this->assertSame( 'Contract Rail', $decoded[0]['carrier'] );
		$this->assertSame( '2026-05-04T08:15:00-04:00', $decoded[1]['departure'] );
		$this->assertSame( array_merge( array_fill_keys( Travel::PLACE_FIELDS, '' ), [ 'lat' => null, 'lng' => null ] ), $decoded[1]['to'] );
	}
}

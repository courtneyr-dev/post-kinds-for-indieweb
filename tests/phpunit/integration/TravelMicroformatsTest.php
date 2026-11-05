<?php
/** Travel h-adr output. @package PKIW */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;

/** @group integration */
final class TravelMicroformatsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Travel() )->register();
		Travel::register_blocks();
		update_option( 'date_format', 'F j, Y' );
	}

	private function post( string $kind, string $key, array $items ): int {
		$block = 'trip' === $kind ? Travel::STOP_BLOCK : Travel::LEG_BLOCK;
		$id    = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2000-01-01 09:00:00', 'post_content' => '<!-- wp:' . $block . ' /-->' ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $id, $key, wp_json_encode( $items ) );
		return (int) $id;
	}

	private function render( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	public function test_real_trip_card_parses_only_visible_address_children_and_geo(): void {
		$id = $this->post(
			'trip',
			Travel::STOPS_KEY,
			[
				[ 'name' => 'Harbor', 'street' => '2 Contract Street', 'locality' => 'Portland', 'region' => 'Oregon', 'country' => 'US', 'postal_code' => '97205', 'lat' => 45.5152, 'lng' => -122.6784, 'date' => '2000-05-04', 'privacy' => 'public' ],
				[ 'name' => 'Approximate', 'street' => '1 Hidden Street', 'locality' => 'Seattle', 'region' => 'Washington', 'country' => 'US', 'postal_code' => '98101', 'lat' => 47.6062, 'lng' => -122.3321, 'date' => '2000-05-05', 'privacy' => 'approximate' ],
				[ 'name' => 'Private', 'locality' => 'Hidden city', 'date' => '2000-05-06', 'privacy' => 'private' ],
			]
		);
		$card   = $this->render( $id );
		$parsed = Mf2\parse( '<article class="h-entry"><span class="p-trip">Trip</span>' . $card . '</article>', get_permalink( $id ) );
		$entry  = $parsed['items'][0];
		$adrs   = array_values( array_filter( $entry['children'], static fn( array $child ): bool => in_array( 'h-adr', $child['type'], true ) ) );

		$this->assertSame( [ 'Trip' ], $entry['properties']['trip'] );
		$this->assertArrayNotHasKey( 'location', $entry['properties'] );
		$this->assertCount( 2, $adrs );
		$this->assertSame( [ '2 Contract Street' ], $adrs[0]['properties']['street-address'] );
		$this->assertSame( [ 'Portland' ], $adrs[0]['properties']['locality'] );
		$this->assertSame( [ 'Oregon' ], $adrs[0]['properties']['region'] );
		$this->assertSame( [ 'US' ], $adrs[0]['properties']['country-name'] );
		$this->assertSame( [ '97205' ], $adrs[0]['properties']['postal-code'] );
		$this->assertSame( [ '45.5152' ], $adrs[0]['properties']['geo'][0]['properties']['latitude'] );
		$this->assertSame( [ '-122.6784' ], $adrs[0]['properties']['geo'][0]['properties']['longitude'] );
		$this->assertSame( [ 'Seattle' ], $adrs[1]['properties']['locality'] );
		$this->assertArrayNotHasKey( 'street-address', $adrs[1]['properties'] );
		$this->assertArrayNotHasKey( 'postal-code', $adrs[1]['properties'] );
		$this->assertArrayNotHasKey( 'geo', $adrs[1]['properties'] );
		$this->assertStringContainsString( '</span> <span class="p-locality">', $card );
		$this->assertStringContainsString( '</span>, <span class="p-region">', $card );
		$this->assertStringContainsString( '</span>, <span class="p-country-name">', $card );
		$this->assertStringContainsString( '</span> <span class="p-postal-code">', $card );
		$this->assertStringContainsString( '</span><data class="p-geo h-geo"', $card );
		$this->assertStringNotContainsString( 'p-location', $card );
	}

	public function test_real_itinerary_card_keeps_the_root_itinerary_property(): void {
		$id = $this->post(
			'itinerary',
			Travel::LEGS_KEY,
			[
				[
					'from' => [ 'name' => 'Station', 'locality' => 'Portland' ],
					'to' => [ 'name' => 'Terminal', 'locality' => 'Seattle' ],
					'departure' => '2000-05-04T08:15:00-04:00', 'arrival' => '2000-05-04T10:15:00-04:00', 'carrier' => 'Rail', 'privacy' => 'approximate',
				],
			]
		);
		$parsed = Mf2\parse( '<article class="h-entry"><span class="p-itinerary">Itinerary</span>' . $this->render( $id ) . '</article>', get_permalink( $id ) );
		$entry  = $parsed['items'][0];
		$adrs   = array_values( array_filter( $entry['children'], static fn( array $child ): bool => in_array( 'h-adr', $child['type'], true ) ) );

		$this->assertSame( [ 'Itinerary' ], $entry['properties']['itinerary'] );
		$this->assertArrayNotHasKey( 'location', $entry['properties'] );
		$this->assertCount( 2, $adrs );
		$this->assertSame( [ 'Portland' ], $adrs[0]['properties']['locality'] );
		$this->assertSame( [ 'Seattle' ], $adrs[1]['properties']['locality'] );
		$this->assertArrayNotHasKey( 'geo', $adrs[0]['properties'] );
		$this->assertArrayNotHasKey( 'geo', $adrs[1]['properties'] );
	}
}

<?php
/** Travel ability registration and visitor-safe reads. @package PKIW */

declare(strict_types=1);

use PKIW\Abilities\Travel_Abilities;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;

/** @group integration */
final class TravelAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Travel() )->register();
	}

	private function post( string $kind, array $post = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish', 'post_date' => '2000-01-01 09:00:00' ], $post ) );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		return (int) $id;
	}

	public function test_both_reads_register_public_with_closed_schemas(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable.' );
		}
		foreach ( [ 'post-kinds/get-trip-facts' => 'stops', 'post-kinds/get-itinerary-facts' => 'legs' ] as $name => $list ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability );
			$this->assertTrue( $ability->get_meta()['mcp']['public'] ?? false );
			$this->assertTrue( $ability->get_meta()['show_in_rest'] ?? false );
			$this->assertSame( [ 'post_id' ], $ability->get_input_schema()['required'] );
			$this->assertFalse( $ability->get_output_schema()['additionalProperties'] );
			$this->assertArrayHasKey( $list, $ability->get_output_schema()['properties'] );
		}
	}

	public function test_trip_read_returns_the_frozen_shape(): void {
		$id = $this->post( 'trip' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( [ [ 'name' => 'Harbor', 'locality' => 'Portland', 'date' => '2000-01-01', 'privacy' => 'public' ] ] ) );
		$result = Travel_Abilities::instance()->execute_get_trip_facts( [ 'post_id' => $id ] );
		$this->assertSame( [ 'post_id', 'count', 'mapped', 'range', 'origin', 'destination', 'stops' ], array_keys( $result ) );
		$this->assertSame( $id, $result['post_id'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'Harbor', $result['origin'] );
		$ability = wp_get_ability( 'post-kinds/get-trip-facts' );
		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema() ) );
	}

	public function test_itinerary_read_returns_and_validates_the_frozen_shape(): void {
		$id = $this->post( 'itinerary' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $id, Travel::LEGS_KEY, wp_json_encode( [ [ 'from' => [ 'name' => 'Station' ], 'to' => [ 'name' => 'Terminal' ], 'departure' => '2000-01-01T08:15:00-04:00', 'arrival' => '2000-01-01T10:15:00-04:00', 'carrier' => 'Rail', 'privacy' => 'public' ] ] ) );
		$result  = Travel_Abilities::instance()->execute_get_itinerary_facts( [ 'post_id' => $id ] );
		$ability = wp_get_ability( 'post-kinds/get-itinerary-facts' );

		$this->assertSame( [ 'post_id', 'count', 'mapped', 'range', 'origin', 'destination', 'legs' ], array_keys( $result ) );
		$this->assertTrue( rest_validate_value_from_schema( $result, $ability->get_output_schema() ) );
	}

	public function test_every_non_public_or_wrong_kind_post_is_rejected_for_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$ids = [
			$this->post( 'trip', [ 'post_status' => 'draft' ] ),
			$this->post( 'trip', [ 'post_status' => 'private' ] ),
			$this->post( 'trip', [ 'post_password' => 'secret' ] ),
			$this->post( 'note' ),
		];
		foreach ( $ids as $id ) {
			$result = Travel_Abilities::instance()->execute_get_trip_facts( [ 'post_id' => $id ] );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'pkiw_not_public', $result->get_error_code() );
		}

		$itinerary = $this->post( 'itinerary' );
		$trip      = $this->post( 'trip' );
		$this->assertSame( 'pkiw_not_public', Travel_Abilities::instance()->execute_get_trip_facts( [ 'post_id' => $itinerary ] )->get_error_code() );
		$this->assertSame( 'pkiw_not_public', Travel_Abilities::instance()->execute_get_itinerary_facts( [ 'post_id' => $trip ] )->get_error_code() );
	}
}

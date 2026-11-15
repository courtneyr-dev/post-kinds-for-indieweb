<?php
/**
 * Public ability eligibility coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Abilities\Operations_Abilities;
use PKIW\Abilities\Rsvp_Abilities;
use PKIW\Abilities\Tag_Abilities;
use PKIW\Abilities\Travel_Abilities;
use PKIW\Taxonomy;

/** @group integration */
final class PublicAbilitiesEligibilityTest extends WP_UnitTestCase {

	public function test_all_nine_public_id_abilities_reuse_missing_results_with_positive_controls(): void {
		( new Taxonomy() )->create_default_terms();
		$hidden_ids = [];
		foreach ( [ [ 'publish', 'secret' ], [ 'private', '' ], [ 'draft', '' ], [ 'trash', '' ] ] as [ $status, $password ] ) {
			$id = self::factory()->post->create( [ 'post_status' => $status, 'post_password' => $password ] );
			wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
			$hidden_ids[] = $id;
		}
		$missing = 987654;
		$cases   = [
			[ Core_Abilities::instance(), 'execute_get_kind' ],
			[ Core_Abilities::instance(), 'execute_get_post_meta' ],
			[ Operations_Abilities::instance(), 'execute_get_surface' ],
			[ Tag_Abilities::instance(), 'execute_get_tag_facts' ],
			[ Rsvp_Abilities::instance(), 'execute_get_rsvp_facts' ],
			[ Travel_Abilities::instance(), 'execute_get_trip_facts' ],
			[ Travel_Abilities::instance(), 'execute_get_itinerary_facts' ],
			[ Operations_Abilities::instance(), 'execute_get_public_location_fields' ],
		];
		foreach ( [ 'administrator', 'subscriber' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			foreach ( $cases as [ $provider, $method ] ) {
				$expected = $provider->{$method}( [ 'post_id' => $missing ] );
				foreach ( $hidden_ids as $hidden ) {
					$actual = $provider->{$method}( [ 'post_id' => $hidden ] );
					$this->assertWPError( $expected );
					$this->assertWPError( $actual );
					$this->assertSame( $expected->get_error_code(), $actual->get_error_code(), $method . ' ' . $role );
					$this->assertSame( $expected->get_error_message(), $actual->get_error_message(), $method . ' ' . $role );
					$this->assertSame( $expected->get_error_data(), $actual->get_error_data(), $method . ' ' . $role );
				}
			}
		}

		$eligible = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<!-- wp:post-kinds-indieweb/tag-card {"url":"https://ability-positive.example/","title":"Ability positive","labels":["ability-positive"]} /-->' ] );
		wp_set_object_terms( $eligible, 'tag', Taxonomy::TAXONOMY );
		foreach ( array_slice( $cases, 0, 4 ) as [ $provider, $method ] ) {
			$this->assertIsArray( $provider->{$method}( [ 'post_id' => $eligible ] ), $method );
		}
		$this->assertIsArray( Operations_Abilities::instance()->execute_get_public_location_fields( [ 'post_id' => $eligible ] ) );

		$rsvp = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $rsvp, 'rsvp', Taxonomy::TAXONOMY );
		$rsvp_result = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $rsvp ] );
		$this->assertTrue( is_array( $rsvp_result ) || ( is_wp_error( $rsvp_result ) && 'invalid_post' !== $rsvp_result->get_error_code() ) );
		foreach ( [ 'trip' => 'execute_get_trip_facts', 'itinerary' => 'execute_get_itinerary_facts' ] as $kind => $method ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
			$this->assertIsArray( Travel_Abilities::instance()->{$method}( [ 'post_id' => $id ] ), $method );
		}

		if ( function_exists( 'wp_get_ability' ) ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			$ability = wp_get_ability( 'post-kinds/get-collection-items' );
			$this->assertNotNull( $ability );
			$expected = $ability->execute( [ 'post_id' => $missing ] );
			$this->assertWPError( $expected );
			foreach ( $hidden_ids as $hidden ) {
				$actual = $ability->execute( [ 'post_id' => $hidden ] );
				$this->assertWPError( $actual );
				$this->assertSame( $expected->get_error_code(), $actual->get_error_code() );
				$this->assertSame( $expected->get_error_message(), $actual->get_error_message() );
				$this->assertSame( $expected->get_error_data(), $actual->get_error_data() );
			}
			$collection = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $collection, 'collection', Taxonomy::TAXONOMY );
			$positive = $ability->execute( [ 'post_id' => $collection ] );
			$this->assertIsArray( $positive );
			$this->assertSame( $collection, $positive['post_id'] );
		}
	}
}

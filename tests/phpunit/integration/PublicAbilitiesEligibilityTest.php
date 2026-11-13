<?php
/**
 * Public ability eligibility coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Abilities\Operations_Abilities;
use PKIW\Taxonomy;

/** @group integration */
final class PublicAbilitiesEligibilityTest extends WP_UnitTestCase {

	public function test_hidden_governed_ids_reuse_each_abilitys_missing_error(): void {
		( new Taxonomy() )->create_default_terms();
		$hidden = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_object_terms( $hidden, 'tag', Taxonomy::TAXONOMY );
		$missing = 987654;
		$cases   = [
			[ Core_Abilities::instance(), 'execute_get_kind' ],
			[ Core_Abilities::instance(), 'execute_get_post_meta' ],
			[ Operations_Abilities::instance(), 'execute_get_surface' ],
			[ Operations_Abilities::instance(), 'execute_get_public_location_fields' ],
		];
		foreach ( $cases as [ $provider, $method ] ) {
			$expected = $provider->{$method}( [ 'post_id' => $missing ] );
			$actual   = $provider->{$method}( [ 'post_id' => $hidden ] );
			$this->assertWPError( $expected );
			$this->assertWPError( $actual );
			$this->assertSame( $expected->get_error_code(), $actual->get_error_code(), $method );
			$this->assertSame( $expected->get_error_message(), $actual->get_error_message(), $method );
			$this->assertSame( $expected->get_error_data(), $actual->get_error_data(), $method );
		}
	}
}

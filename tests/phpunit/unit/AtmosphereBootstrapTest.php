<?php
/**
 * Tests for the ATmosphere integration bootstrap.
 *
 * The coordinator must fail safely in every degraded state: ATmosphere
 * missing, the feature flag off, or register() called twice. These tests
 * run without ATmosphere loaded and pin the inert behavior; the
 * integration suite (group atmosphere-live) covers the wired behavior.
 *
 * @package PKIW
 * @group   atmosphere
 */

namespace PKIW\Tests\Unit;

use PKIW\Integrations\Atmosphere;

/**
 * Atmosphere bootstrap tests.
 *
 * @group atmosphere
 */
class AtmosphereBootstrapTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( defined( 'ATMOSPHERE_VERSION' ) ) {
			$this->markTestSkipped( 'These tests pin behavior when ATmosphere is absent.' );
		}
	}

	public function test_register_without_atmosphere_adds_only_the_notice() {
		$integration = new Atmosphere();
		$integration->register();

		$this->assertNotFalse( has_action( 'admin_notices', [ $integration, 'dependency_notice' ] ) );

		// The eligibility default must not be active: a listen post stays
		// shareable because the integration never wired its policy.
		$post_id = self::factory()->post->create();
		wp_set_object_terms( $post_id, 'listen', 'kind' );

		$this->assertSame( '', (string) get_post_meta( $post_id, 'atmosphere_disabled', true ) );

		$integration->unregister();
	}

	public function test_feature_flag_off_makes_register_a_no_op() {
		add_filter( 'pkiw_feature_flag_atmosphere_integration', '__return_false' );

		$integration = new Atmosphere();
		$integration->register();

		$this->assertFalse( has_action( 'admin_notices', [ $integration, 'dependency_notice' ] ) );

		remove_filter( 'pkiw_feature_flag_atmosphere_integration', '__return_false' );
		$integration->unregister();
	}

	public function test_register_twice_registers_hooks_once() {
		$integration = new Atmosphere();
		$integration->register();
		$integration->register();

		remove_action( 'admin_notices', [ $integration, 'dependency_notice' ] );

		$this->assertFalse(
			has_action( 'admin_notices', [ $integration, 'dependency_notice' ] ),
			'a second register() must not stack a second notice hook'
		);

		$integration->unregister();
	}

	public function test_status_reports_absent_dependency() {
		$status = Atmosphere::status();

		$this->assertFalse( $status['active'] );
		$this->assertFalse( $status['compatible'] );
		$this->assertFalse( $status['connected'] );
		$this->assertNull( $status['version'] );
	}

	public function test_dependency_notice_requires_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$integration = new Atmosphere();

		ob_start();
		$integration->dependency_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		ob_start();
		$integration->dependency_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ATmosphere', $output );
	}
}

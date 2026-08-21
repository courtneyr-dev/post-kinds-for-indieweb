<?php
/**
 * Tests for the ATmosphere integration bootstrap.
 *
 * ATmosphere is an optional companion: with it absent, the coordinator
 * must register nothing but the settings-tab status surface, keep every
 * other Post Kinds feature untouched, and never fatal. These tests run
 * without ATmosphere loaded and pin that inert behavior; the live suite
 * covers the wired behavior.
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

	public function test_register_without_atmosphere_wires_only_the_status_surface() {
		$integration = new Atmosphere();
		$integration->register();

		$this->assertNotFalse(
			has_action( 'admin_init', [ $integration, 'register_settings_ui' ] ),
			'the status surface must exist in every state'
		);

		// The eligibility default must not be active: a listen post stays
		// shareable because the publishing policy never wired.
		$post_id = self::factory()->post->create();
		wp_set_object_terms( $post_id, 'listen', 'kind' );

		$this->assertSame( '', (string) get_post_meta( $post_id, 'atmosphere_disabled', true ) );

		$integration->unregister();
	}

	public function test_no_admin_notice_is_registered_when_absent() {
		global $wp_filter;

		$before = isset( $wp_filter['admin_notices'] ) ? count( $wp_filter['admin_notices']->callbacks ) : 0;

		$integration = new Atmosphere();
		$integration->register();

		$after = isset( $wp_filter['admin_notices'] ) ? count( $wp_filter['admin_notices']->callbacks ) : 0;

		$this->assertSame( $before, $after, 'an optional companion must not add site-wide notices' );

		$integration->unregister();
	}

	public function test_feature_flag_off_makes_register_a_no_op() {
		add_filter( 'pkiw_feature_flag_atmosphere_integration', '__return_false' );

		$integration = new Atmosphere();
		$integration->register();

		$this->assertFalse( has_action( 'admin_init', [ $integration, 'register_settings_ui' ] ) );

		remove_filter( 'pkiw_feature_flag_atmosphere_integration', '__return_false' );
		$integration->unregister();
	}

	public function test_register_twice_registers_hooks_once() {
		$integration = new Atmosphere();
		$integration->register();
		$integration->register();

		remove_action( 'admin_init', [ $integration, 'register_settings_ui' ], 11 );

		$this->assertFalse(
			has_action( 'admin_init', [ $integration, 'register_settings_ui' ] ),
			'a second register() must not stack a second settings hook'
		);

		$integration->unregister();
	}

	public function test_status_reports_absent_companion() {
		$status = Atmosphere::status();

		$this->assertFalse( $status['active'] );
		$this->assertFalse( $status['compatible'] );
		$this->assertFalse( $status['connected'] );
		$this->assertNull( $status['version'] );
	}

	public function test_settings_field_offers_a_recommendation_when_absent() {
		$integration = new Atmosphere();

		ob_start();
		$integration->render_settings_field();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Optional', $output );
		$this->assertStringContainsString( 'ATmosphere', $output );
		$this->assertStringNotContainsString( 'notice-warning', $output, 'the recommendation is not an error' );
	}
}

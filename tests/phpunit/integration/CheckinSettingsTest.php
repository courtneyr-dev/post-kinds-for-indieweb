<?php
/**
 * Integration tests for the Checkin settings tab.
 *
 * The Coordinate Handling field promised "never shown publicly" by default,
 * but nothing read checkin_coordinate_handling. Each post's location privacy
 * decides what visitors see (Meta_Fields::get_visible_location_fields()), so
 * the field is gone and the section says so (#318).
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Integration;

use PKIW\Admin\Admin;
use PKIW\Admin\Settings_Page;
use PKIW\Plugin;
use WP_UnitTestCase;

class CheckinSettingsTest extends WP_UnitTestCase {

	private Settings_Page $page;

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->page = new Settings_Page( new Admin( Plugin::get_instance() ) );
	}

	public function tear_down(): void {
		global $wp_settings_fields, $wp_settings_sections;
		unset( $wp_settings_fields['pkiw_checkin'], $wp_settings_sections['pkiw_checkin'] );
		delete_option( 'pkiw_settings' );
		parent::tear_down();
	}

	public function test_checkin_tab_has_no_coordinate_handling_field(): void {
		global $wp_settings_fields;

		$this->page->register_sections_and_fields();
		$fields = $wp_settings_fields['pkiw_checkin']['pkiw_checkin_section'] ?? [];

		$this->assertArrayHasKey( 'checkin_default_privacy', $fields );
		$this->assertArrayNotHasKey( 'checkin_coordinate_handling', $fields );
		$this->assertFalse( method_exists( $this->page, 'render_coordinate_handling_field' ) );
	}

	public function test_checkin_section_points_to_each_posts_location_privacy(): void {
		ob_start();
		$this->page->render_checkin_section();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString(
			'What visitors see of a check-in&#039;s location, coordinates included, follows each post&#039;s Location Privacy setting in the block editor: Public (exact location), Approximate or Private (hidden).',
			$html
		);
	}

	public function test_saving_the_checkin_tab_does_not_keep_a_coordinate_handling_value(): void {
		update_option( 'pkiw_settings', [ 'checkin_coordinate_handling' => 'discard' ] );

		$admin     = new Admin( Plugin::get_instance() );
		$sanitized = $admin->sanitize_general_settings(
			[
				'_active_tab'                 => 'checkin',
				'checkin_coordinate_handling' => 'store_hide',
			]
		);

		$this->assertArrayNotHasKey( 'checkin_coordinate_handling', $sanitized );
	}
}

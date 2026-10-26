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
		unregister_setting( 'pkiw_general', 'pkiw_settings' );
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

	/**
	 * Save settings the way options.php does: register the setting so its
	 * sanitize callback is attached, then update_option() runs it.
	 *
	 * @param array<string, mixed> $input Posted pkiw_settings values.
	 * @return void
	 */
	private function save_through_settings_api( array $input ): void {
		( new Admin( Plugin::get_instance() ) )->register_settings();
		update_option( 'pkiw_settings', $input );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function default_privacy_values(): array {
		return [
			'public'      => [ 'public' ],
			'approximate' => [ 'approximate' ],
			'private'     => [ 'private' ],
		];
	}

	/**
	 * @dataProvider default_privacy_values
	 */
	public function test_default_location_privacy_saves_and_reads_back( string $value ): void {
		$this->save_through_settings_api(
			[
				'_active_tab'             => 'checkin',
				'checkin_default_privacy' => $value,
			]
		);

		$stored = get_option( 'pkiw_settings' );

		$this->assertArrayHasKey( 'checkin_default_privacy', $stored );
		$this->assertSame( $value, $stored['checkin_default_privacy'] );
	}

	public function test_saved_default_location_privacy_is_the_radio_that_renders_checked(): void {
		$this->save_through_settings_api(
			[
				'_active_tab'             => 'checkin',
				'checkin_default_privacy' => 'private',
			]
		);

		ob_start();
		$this->page->render_privacy_field( [ 'id' => 'checkin_default_privacy' ] );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<input type="radio" name="pkiw_settings\[checkin_default_privacy\]" value="private"\s+checked=\'checked\'>/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/value="approximate"\s+checked=/',
			$html
		);
	}

	public function test_default_location_privacy_rejects_an_unknown_value(): void {
		$this->save_through_settings_api(
			[
				'_active_tab'             => 'checkin',
				'checkin_default_privacy' => 'everyone',
			]
		);

		$this->assertSame(
			'approximate',
			get_option( 'pkiw_settings' )['checkin_default_privacy']
		);
	}

	public function test_default_location_privacy_keeps_the_stored_value_when_given_an_unknown_one(): void {
		update_option( 'pkiw_settings', [ 'checkin_default_privacy' => 'private' ] );

		$this->save_through_settings_api(
			[
				'_active_tab'             => 'checkin',
				'checkin_default_privacy' => 'everyone',
			]
		);

		$this->assertSame(
			'private',
			get_option( 'pkiw_settings' )['checkin_default_privacy']
		);
	}

	public function test_saving_another_tab_keeps_the_stored_default_location_privacy(): void {
		update_option( 'pkiw_settings', [ 'checkin_default_privacy' => 'public' ] );

		$this->save_through_settings_api(
			[
				'_active_tab'         => 'general',
				'default_post_status' => 'draft',
			]
		);

		$this->assertSame(
			'public',
			get_option( 'pkiw_settings' )['checkin_default_privacy']
		);
	}

	public function test_default_location_privacy_defaults_to_approximate(): void {
		$defaults = ( new Admin( Plugin::get_instance() ) )->get_default_settings();

		$this->assertSame( 'approximate', $defaults['checkin_default_privacy'] );
	}
}

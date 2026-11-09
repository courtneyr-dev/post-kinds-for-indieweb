<?php
/**
 * Event calendar settings defaults, fields, and sanitation.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Admin\Admin;
use PKIW\Admin\Settings_Page;
use PKIW\Event_Calendar;
use PKIW\Integrations\My_Calendar_Privacy;
use PKIW\Plugin;

/**
 * @group integration
 */
final class EventSettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		delete_option( 'pkiw_settings' );
		add_filter( 'pkiw_calendar_source_active', '__return_true' );
	}

	public function tear_down(): void {
		global $wp_settings_fields, $wp_settings_sections;
		unset( $wp_settings_fields['pkiw_general'], $wp_settings_sections['pkiw_general'] );
		delete_option( 'pkiw_settings' );
		remove_all_filters( 'pkiw_calendar_source_active' );
		parent::tear_down();
	}

	public function test_defaults_apply_with_no_option_row_and_with_missing_keys(): void {
		$admin    = new Admin( Plugin::get_instance() );
		$defaults = $admin->get_default_settings();

		$this->assertTrue( $defaults[ Event_Calendar::SETTING_CALENDAR ] );
		$this->assertFalse( $defaults[ Event_Calendar::SETTING_HIDE_ALL ] );
		$this->assertTrue( Event_Calendar::will_render() );

		update_option( 'pkiw_settings', [ 'default_post_status' => 'draft' ] );
		$this->assertTrue( Event_Calendar::will_render() );
		$this->assertFalse(
			My_Calendar_Privacy::hides(
				(object) [
					'event_id'     => 231,
					'category_name' => 'General',
				]
			)
		);
	}

	public function test_general_settings_sanitize_both_event_checkboxes_to_bool(): void {
		$admin = new Admin( Plugin::get_instance() );
		$on    = $admin->sanitize_general_settings(
			[
				'_active_tab'                             => 'general',
				Event_Calendar::SETTING_CALENDAR           => '1',
				Event_Calendar::SETTING_HIDE_ALL           => '1',
			]
		);
		$off = $admin->sanitize_general_settings( [ '_active_tab' => 'general' ] );

		$this->assertTrue( $on[ Event_Calendar::SETTING_CALENDAR ] );
		$this->assertTrue( $on[ Event_Calendar::SETTING_HIDE_ALL ] );
		$this->assertFalse( $off[ Event_Calendar::SETTING_CALENDAR ] );
		$this->assertFalse( $off[ Event_Calendar::SETTING_HIDE_ALL ] );
	}

	public function test_general_settings_page_registers_both_event_fields(): void {
		global $wp_settings_fields;
		$page = new Settings_Page( new Admin( Plugin::get_instance() ) );
		$page->register_sections_and_fields();
		$fields = $wp_settings_fields['pkiw_general']['pkiw_general_section'] ?? [];

		$this->assertArrayHasKey( Event_Calendar::SETTING_CALENDAR, $fields );
		$this->assertArrayHasKey( Event_Calendar::SETTING_HIDE_ALL, $fields );
		$this->assertStringContainsString( 'Hide location', $fields[ Event_Calendar::SETTING_HIDE_ALL ]['args']['desc'] );
	}
}

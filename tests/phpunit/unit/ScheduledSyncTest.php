<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Scheduled_Sync;
use PostKindsForIndieWeb\Import_Manager;

class ScheduledSyncTest extends WP_UnitTestCase {

	private Scheduled_Sync $sync;
	private Import_Manager $import_manager;

	public function set_up(): void {
		parent::set_up();
		$this->import_manager = new Import_Manager();
		$this->sync           = new Scheduled_Sync( $this->import_manager );
		$this->sync->init();

		delete_option( 'post_kinds_indieweb_settings' );
		delete_option( 'post_kinds_indieweb_last_sync' );
	}

	public function tear_down(): void {
		// Clean up any scheduled events.
		$this->sync->unschedule_cron();
		delete_option( 'post_kinds_indieweb_settings' );
		delete_option( 'post_kinds_indieweb_last_sync' );
		parent::tear_down();
	}

	public function test_schedule_cron_registers_event() {
		$this->sync->schedule_cron();

		$next = wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' );
		$this->assertNotFalse( $next );
	}

	public function test_schedule_cron_uses_hourly_interval() {
		$this->sync->schedule_cron();

		$crons = _get_cron_array();
		$found = false;

		foreach ( $crons as $timestamp => $hooks ) {
			if ( isset( $hooks['post_kinds_indieweb_scheduled_sync'] ) ) {
				foreach ( $hooks['post_kinds_indieweb_scheduled_sync'] as $event ) {
					$this->assertSame( 'hourly', $event['schedule'] );
					$found = true;
					break 2;
				}
			}
		}

		$this->assertTrue( $found, 'Cron event not found in cron array' );
	}

	public function test_schedule_cron_does_not_duplicate() {
		$this->sync->schedule_cron();
		$first = wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' );

		$this->sync->schedule_cron();
		$second = wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' );

		$this->assertSame( $first, $second );
	}

	public function test_unschedule_cron_removes_event() {
		$this->sync->schedule_cron();
		$this->assertNotFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );

		$this->sync->unschedule_cron();
		$this->assertFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );
	}

	public function test_unschedule_cron_when_not_scheduled() {
		// Should not error when nothing is scheduled.
		$this->sync->unschedule_cron();
		$this->assertFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );
	}

	public function test_ensure_scheduled_creates_cron_when_auto_sync_enabled() {
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => true,
			'listen_auto_import'     => true,
		] );

		$this->sync->ensure_scheduled();

		$this->assertNotFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );
	}

	public function test_ensure_scheduled_does_not_create_when_disabled() {
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => false,
		] );

		$this->sync->ensure_scheduled();

		$this->assertFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );
	}

	public function test_ensure_scheduled_requires_individual_auto_import() {
		// background_sync enabled but no individual auto-import keys set.
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => true,
		] );

		$this->sync->ensure_scheduled();

		$this->assertFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );
	}

	public function test_get_last_sync_time_returns_null_when_never_synced() {
		$this->assertNull( $this->sync->get_last_sync_time() );
	}

	public function test_get_last_sync_time_returns_timestamp() {
		$now = time();
		update_option( 'post_kinds_indieweb_last_sync', $now );

		$this->assertSame( $now, $this->sync->get_last_sync_time() );
	}

	public function test_get_next_sync_time_returns_null_when_not_scheduled() {
		$this->assertNull( $this->sync->get_next_sync_time() );
	}

	public function test_get_next_sync_time_returns_timestamp_when_scheduled() {
		$this->sync->schedule_cron();

		$next = $this->sync->get_next_sync_time();
		$this->assertIsInt( $next );
		$this->assertGreaterThan( 0, $next );
	}

	public function test_run_scheduled_sync_skips_when_background_sync_disabled() {
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => false,
			'listen_auto_import'     => true,
		] );

		$this->sync->run_scheduled_sync();

		// last_sync should not be updated.
		$this->assertFalse( get_option( 'post_kinds_indieweb_last_sync' ) );
	}

	public function test_run_scheduled_sync_updates_last_sync_time() {
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => true,
			'listen_auto_import'     => true,
			'listen_import_source'   => 'lastfm',
		] );

		$before = time();
		$this->sync->run_scheduled_sync();
		$after = time();

		$last_sync = get_option( 'post_kinds_indieweb_last_sync' );
		$this->assertGreaterThanOrEqual( $before, (int) $last_sync );
		$this->assertLessThanOrEqual( $after, (int) $last_sync );
	}

	public function test_trigger_sync_calls_run_scheduled_sync() {
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => true,
			'watch_auto_import'      => true,
			'watch_import_source'    => 'trakt',
		] );

		$this->sync->trigger_sync();

		$this->assertNotFalse( get_option( 'post_kinds_indieweb_last_sync' ) );
	}

	public function test_maybe_schedule_cron_schedules_when_enabled() {
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => true,
			'read_auto_import'       => true,
		] );

		$this->sync->maybe_schedule_cron( [], get_option( 'post_kinds_indieweb_settings' ) );

		$this->assertNotFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );
	}

	public function test_maybe_schedule_cron_unschedules_when_disabled() {
		// First schedule it.
		$this->sync->schedule_cron();
		$this->assertNotFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );

		// Then disable auto-sync.
		update_option( 'post_kinds_indieweb_settings', [
			'enable_background_sync' => false,
		] );

		$this->sync->maybe_schedule_cron( [], get_option( 'post_kinds_indieweb_settings' ) );

		$this->assertFalse( wp_next_scheduled( 'post_kinds_indieweb_scheduled_sync' ) );
	}
}

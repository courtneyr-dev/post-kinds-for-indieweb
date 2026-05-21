<?php
/**
 * Test the Admin class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Admin\Admin;
use PostKindsForIndieWeb\Plugin;
use WP_UnitTestCase;

/**
 * Test the Admin controller.
 *
 * @covers \PostKindsForIndieWeb\Admin\Admin
 */
class AdminTest extends WP_UnitTestCase {

	/**
	 * Admin instance.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$plugin      = Plugin::get_instance();
		$this->admin = new Admin( $plugin );
	}

	// ─── get_default_settings ───

	/**
	 * Test get_default_settings returns array.
	 */
	public function test_get_default_settings_returns_array(): void {
		$defaults = $this->admin->get_default_settings();

		$this->assertIsArray( $defaults );
		$this->assertNotEmpty( $defaults );
	}

	/**
	 * Test get_default_settings has required keys.
	 */
	public function test_get_default_settings_keys(): void {
		$defaults = $this->admin->get_default_settings();

		$expected_keys = [
			'default_post_status',
			'default_post_format',
			'enable_microformats',
			'enable_syndication',
			'auto_fetch_metadata',
			'cache_duration',
			'image_handling',
			'listen_default_rating',
			'watch_default_rating',
			'rate_limit_delay',
			'batch_size',
			'checkin_privacy',
			'format_kind_mappings',
		];

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "Missing key: $key" );
		}
	}

	/**
	 * Test get_default_settings value types.
	 */
	public function test_get_default_settings_types(): void {
		$defaults = $this->admin->get_default_settings();

		$this->assertSame( 'publish', $defaults['default_post_status'] );
		$this->assertTrue( $defaults['enable_microformats'] );
		$this->assertSame( 86400, $defaults['cache_duration'] );
		$this->assertSame( 'sideload', $defaults['image_handling'] );
		$this->assertIsArray( $defaults['format_kind_mappings'] );
	}

	// ─── get_post_kinds ───

	/**
	 * Test get_post_kinds returns kinds array.
	 */
	public function test_get_post_kinds(): void {
		$kinds = $this->admin->get_post_kinds();

		$this->assertIsArray( $kinds );
		$this->assertArrayHasKey( 'listen', $kinds );
		$this->assertArrayHasKey( 'watch', $kinds );
		$this->assertArrayHasKey( 'read', $kinds );
		$this->assertArrayHasKey( 'checkin', $kinds );
		$this->assertArrayHasKey( 'bookmark', $kinds );
	}

	/**
	 * Test get_post_kinds each kind has label and icon.
	 */
	public function test_get_post_kinds_structure(): void {
		$kinds = $this->admin->get_post_kinds();

		foreach ( $kinds as $slug => $kind ) {
			$this->assertArrayHasKey( 'label', $kind, "Kind '$slug' missing label" );
			$this->assertArrayHasKey( 'icon', $kind, "Kind '$slug' missing icon" );
			$this->assertStringStartsWith( 'dashicons-', $kind['icon'], "Kind '$slug' icon invalid" );
		}
	}

	// ─── plugin_action_links ───

	/**
	 * Test plugin_action_links adds settings link.
	 */
	public function test_plugin_action_links(): void {
		$links = $this->admin->plugin_action_links( [ '<a href="#">Deactivate</a>' ] );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertStringContainsString( 'post-kinds-for-indieweb', $links[0] );
	}

	/**
	 * Test plugin_action_links prepends (not appends).
	 */
	public function test_plugin_action_links_prepends(): void {
		$links = $this->admin->plugin_action_links( [ 'existing' ] );

		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertSame( 'existing', $links[1] );
	}

	// ─── get_setting ───

	/**
	 * Test get_setting returns default when no option set.
	 */
	public function test_get_setting_default(): void {
		delete_option( 'post_kinds_indieweb_settings' );

		$value = $this->admin->get_setting( 'default_post_status' );

		$this->assertSame( 'publish', $value );
	}

	/**
	 * Test get_setting returns stored value.
	 */
	public function test_get_setting_stored(): void {
		update_option( 'post_kinds_indieweb_settings', [
			'default_post_status' => 'draft',
		] );

		$value = $this->admin->get_setting( 'default_post_status' );

		$this->assertSame( 'draft', $value );
	}

	/**
	 * Test get_setting returns custom default for missing key.
	 */
	public function test_get_setting_custom_default(): void {
		$value = $this->admin->get_setting( 'nonexistent_key', 'fallback' );

		$this->assertSame( 'fallback', $value );
	}

	// ─── sanitize_general_settings ───

	/**
	 * Test sanitize_general_settings with string fields.
	 */
	public function test_sanitize_general_settings_strings(): void {
		$input = [
			'default_post_status' => 'draft',
			'image_handling'      => 'hotlink',
			'checkin_privacy'     => 'private',
		];

		$result = $this->admin->sanitize_general_settings( $input );

		$this->assertSame( 'draft', $result['default_post_status'] );
		$this->assertSame( 'hotlink', $result['image_handling'] );
		$this->assertSame( 'private', $result['checkin_privacy'] );
	}

	/**
	 * Test sanitize_general_settings sanitizes HTML in strings.
	 */
	public function test_sanitize_general_settings_html_stripped(): void {
		$input = [
			'default_post_status' => '<script>alert("xss")</script>publish',
		];

		$result = $this->admin->sanitize_general_settings( $input );

		$this->assertStringNotContainsString( '<script>', $result['default_post_status'] );
	}

	/**
	 * Test sanitize_general_settings boolean fields from active tab.
	 */
	public function test_sanitize_general_settings_booleans_active_tab(): void {
		$input = [
			'_active_tab'        => 'general',
			'enable_microformats' => '1',
			// enable_syndication not set = unchecked = false.
		];

		$result = $this->admin->sanitize_general_settings( $input );

		$this->assertTrue( $result['enable_microformats'] );
		$this->assertFalse( $result['enable_syndication'] );
	}

	/**
	 * Test sanitize_general_settings preserves booleans from other tabs.
	 */
	public function test_sanitize_general_settings_preserves_other_tab(): void {
		update_option( 'post_kinds_indieweb_settings', [
			'listen_auto_import' => true,
		] );

		$input = [
			'_active_tab' => 'general', // Not the listen tab.
		];

		$result = $this->admin->sanitize_general_settings( $input );

		// listen_auto_import preserved from old settings.
		$this->assertTrue( $result['listen_auto_import'] );
	}

	/**
	 * Test sanitize_general_settings integer clamping.
	 */
	public function test_sanitize_general_settings_int_clamping(): void {
		$input = [
			'cache_duration'        => '999999', // Max 604800.
			'listen_default_rating' => '15',     // Max 10.
			'batch_size'            => '0',      // Min 1.
			'rate_limit_delay'      => '-5',     // absint makes this 5.
		];

		$result = $this->admin->sanitize_general_settings( $input );

		$this->assertSame( 604800, $result['cache_duration'] );
		$this->assertSame( 10, $result['listen_default_rating'] );
		$this->assertSame( 1, $result['batch_size'] );
		$this->assertSame( 5, $result['rate_limit_delay'] ); // absint(-5) = 5.
	}

	/**
	 * Test sanitize_general_settings format_kind_mappings.
	 */
	public function test_sanitize_general_settings_format_mappings(): void {
		$input = [
			'format_kind_mappings' => [
				'standard' => 'article',
				'aside'    => 'note',
				'video'    => 'watch',
				'audio'    => 'invalid_kind', // Should be filtered.
			],
		];

		$result = $this->admin->sanitize_general_settings( $input );

		$this->assertSame( 'article', $result['format_kind_mappings']['standard'] );
		$this->assertSame( 'note', $result['format_kind_mappings']['aside'] );
		$this->assertSame( 'watch', $result['format_kind_mappings']['video'] );
		$this->assertArrayNotHasKey( 'audio', $result['format_kind_mappings'] );
	}

	/**
	 * Test sanitize_general_settings sync start dates.
	 */
	public function test_sanitize_general_settings_sync_dates(): void {
		$input = [
			'sync_start_dates' => [
				'lastfm'        => '2024-01-15',
				'trakt_movies'  => '',
				'invalid_source' => '2024-01-01',
			],
		];

		$result = $this->admin->sanitize_general_settings( $input );

		$this->assertArrayHasKey( 'lastfm', $result['sync_start_dates'] );
		$this->assertStringContainsString( '2024-01-15', $result['sync_start_dates']['lastfm'] );
		$this->assertSame( '', $result['sync_start_dates']['trakt_movies'] );
		$this->assertArrayNotHasKey( 'invalid_source', $result['sync_start_dates'] );
	}

	/**
	 * Test sanitize_general_settings triggers rewrite flush on storage mode change.
	 */
	public function test_sanitize_general_settings_storage_mode_change(): void {
		update_option( 'post_kinds_indieweb_settings', [
			'import_storage_mode' => 'standard',
		] );

		$input = [
			'import_storage_mode' => 'cpt',
		];

		$this->admin->sanitize_general_settings( $input );

		$this->assertTrue( (bool) get_option( 'post_kinds_indieweb_flush_rewrite' ) );
	}

	// ─── sanitize_api_credentials ───

	/**
	 * Test sanitize_api_credentials basic fields.
	 */
	public function test_sanitize_api_credentials(): void {
		$input = [
			'lastfm' => [
				'enabled'    => '1',
				'api_key'    => 'my-key-123',
				'api_secret' => 'my-secret-456',
			],
		];

		$result = $this->admin->sanitize_api_credentials( $input );

		$this->assertTrue( $result['lastfm']['enabled'] );
		$this->assertSame( 'my-key-123', $result['lastfm']['api_key'] );
		$this->assertSame( 'my-secret-456', $result['lastfm']['api_secret'] );
	}

	/**
	 * Test sanitize_api_credentials preserves existing on asterisks.
	 */
	public function test_sanitize_api_credentials_asterisk_mask(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'lastfm' => [
				'api_key' => 'real-key-value',
			],
		] );

		$input = [
			'lastfm' => [
				'api_key' => '********',
			],
		];

		$result = $this->admin->sanitize_api_credentials( $input );

		$this->assertSame( 'real-key-value', $result['lastfm']['api_key'] );
	}

	/**
	 * Test sanitize_api_credentials skips missing APIs.
	 */
	public function test_sanitize_api_credentials_skips_missing(): void {
		$result = $this->admin->sanitize_api_credentials( [] );

		$this->assertSame( [], $result );
	}

	/**
	 * Test sanitize_api_credentials strips HTML.
	 */
	public function test_sanitize_api_credentials_strips_html(): void {
		$input = [
			'tmdb' => [
				'api_key' => '<b>key</b>',
			],
		];

		$result = $this->admin->sanitize_api_credentials( $input );

		$this->assertSame( 'key', $result['tmdb']['api_key'] );
	}

	/**
	 * Test sanitize_api_credentials multiple APIs.
	 */
	public function test_sanitize_api_credentials_multiple(): void {
		$input = [
			'tmdb' => [
				'api_key' => 'tmdb-key',
			],
			'trakt' => [
				'client_id'     => 'trakt-id',
				'client_secret' => 'trakt-secret',
			],
		];

		$result = $this->admin->sanitize_api_credentials( $input );

		$this->assertSame( 'tmdb-key', $result['tmdb']['api_key'] );
		$this->assertSame( 'trakt-id', $result['trakt']['client_id'] );
		$this->assertSame( 'trakt-secret', $result['trakt']['client_secret'] );
	}

	// ─── sanitize_webhook_settings ───

	/**
	 * Test sanitize_webhook_settings basic structure.
	 */
	public function test_sanitize_webhook_settings(): void {
		$input = [
			'plex' => [
				'enabled'     => '1',
				'auto_post'   => '',
				'post_status' => 'draft',
				'secret'      => 'my-secret',
			],
		];

		$result = $this->admin->sanitize_webhook_settings( $input );

		$this->assertTrue( $result['plex']['enabled'] );
		$this->assertFalse( $result['plex']['auto_post'] );
		$this->assertSame( 'draft', $result['plex']['post_status'] );
		$this->assertSame( 'my-secret', $result['plex']['secret'] );
	}

	/**
	 * Test sanitize_webhook_settings plex min_watch_percent clamping.
	 */
	public function test_sanitize_webhook_settings_watch_percent(): void {
		$input = [
			'plex' => [
				'min_watch_percent' => '150', // Max 100.
			],
			'jellyfin' => [
				'min_watch_percent' => '-10', // absint makes this 10.
			],
		];

		$result = $this->admin->sanitize_webhook_settings( $input );

		$this->assertSame( 100, $result['plex']['min_watch_percent'] );
		$this->assertSame( 10, $result['jellyfin']['min_watch_percent'] ); // absint(-10) = 10.
	}

	/**
	 * Test sanitize_webhook_settings preserves secret on asterisks.
	 */
	public function test_sanitize_webhook_settings_asterisk_secret(): void {
		update_option( 'post_kinds_indieweb_webhook_settings', [
			'plex' => [
				'secret' => 'real-secret',
			],
		] );

		$input = [
			'plex' => [
				'secret' => '********',
			],
		];

		$result = $this->admin->sanitize_webhook_settings( $input );

		$this->assertSame( 'real-secret', $result['plex']['secret'] );
	}

	/**
	 * Test sanitize_webhook_settings skips unknown types.
	 */
	public function test_sanitize_webhook_settings_unknown_type(): void {
		$input = [
			'unknown_webhook' => [
				'enabled' => '1',
			],
		];

		$result = $this->admin->sanitize_webhook_settings( $input );

		$this->assertArrayNotHasKey( 'unknown_webhook', $result );
	}

	// ─── get_plugin ───

	/**
	 * Test get_plugin returns plugin instance.
	 */
	public function test_get_plugin(): void {
		$this->assertInstanceOf( Plugin::class, $this->admin->get_plugin() );
	}
}

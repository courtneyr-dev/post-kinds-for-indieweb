<?php
/**
 * Test the API Settings class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Admin\Admin;
use PostKindsForIndieWeb\Admin\API_Settings;
use PostKindsForIndieWeb\Plugin;
use WP_UnitTestCase;

/**
 * Test the API Settings page.
 *
 * @covers \PostKindsForIndieWeb\Admin\API_Settings
 */
class ApiSettingsTest extends WP_UnitTestCase {

	/**
	 * API Settings instance.
	 *
	 * @var API_Settings
	 */
	private API_Settings $settings;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$plugin         = Plugin::get_instance();
		$admin          = new Admin( $plugin );
		$this->settings = new API_Settings( $admin );
	}

	// ─── get_oauth_redirect_uri ───

	/**
	 * Test get_oauth_redirect_uri returns admin-post URL.
	 */
	public function test_get_oauth_redirect_uri_trakt(): void {
		$uri = $this->settings->get_oauth_redirect_uri( 'trakt' );

		$this->assertStringContainsString( 'admin-post.php', $uri );
		$this->assertStringContainsString( 'action=post_kinds_trakt_oauth', $uri );
	}

	/**
	 * Test get_oauth_redirect_uri with different API.
	 */
	public function test_get_oauth_redirect_uri_foursquare(): void {
		$uri = $this->settings->get_oauth_redirect_uri( 'foursquare' );

		$this->assertStringContainsString( 'action=post_kinds_foursquare_oauth', $uri );
	}

	// ─── get_oauth_url ───

	/**
	 * Test get_oauth_url returns null without credentials.
	 */
	public function test_get_oauth_url_null_without_creds(): void {
		delete_option( 'post_kinds_indieweb_api_credentials' );

		$this->assertNull( $this->settings->get_oauth_url( 'trakt' ) );
	}

	/**
	 * Test get_oauth_url for Trakt.
	 */
	public function test_get_oauth_url_trakt(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'trakt' => [ 'client_id' => 'test-trakt-id' ],
		] );

		$url = $this->settings->get_oauth_url( 'trakt' );

		$this->assertStringContainsString( 'trakt.tv/oauth/authorize', $url );
		$this->assertStringContainsString( 'test-trakt-id', $url );
		$this->assertStringContainsString( 'response_type=code', $url );
	}

	/**
	 * Test get_oauth_url for Simkl.
	 */
	public function test_get_oauth_url_simkl(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'simkl' => [ 'client_id' => 'test-simkl-id' ],
		] );

		$url = $this->settings->get_oauth_url( 'simkl' );

		$this->assertStringContainsString( 'simkl.com/oauth/authorize', $url );
		$this->assertStringContainsString( 'test-simkl-id', $url );
	}

	/**
	 * Test get_oauth_url for Foursquare.
	 */
	public function test_get_oauth_url_foursquare(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'foursquare' => [ 'client_id' => 'test-fsq-id' ],
		] );

		$url = $this->settings->get_oauth_url( 'foursquare' );

		$this->assertStringContainsString( 'foursquare.com/oauth2/authenticate', $url );
		$this->assertStringContainsString( 'test-fsq-id', $url );
	}

	/**
	 * Test get_oauth_url for Untappd.
	 */
	public function test_get_oauth_url_untappd(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'untappd' => [ 'client_id' => 'test-untappd-id' ],
		] );

		$url = $this->settings->get_oauth_url( 'untappd' );

		$this->assertStringContainsString( 'untappd.com/oauth/authenticate', $url );
		$this->assertStringContainsString( 'test-untappd-id', $url );
		// Untappd uses redirect_url not redirect_uri.
		$this->assertStringContainsString( 'redirect_url', $url );
	}

	/**
	 * Test get_oauth_url for unknown API returns null.
	 */
	public function test_get_oauth_url_unknown(): void {
		$this->assertNull( $this->settings->get_oauth_url( 'nonexistent' ) );
	}

	/**
	 * Test get_oauth_url returns null for Trakt without client_id.
	 */
	public function test_get_oauth_url_trakt_no_client_id(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'trakt' => [ 'client_secret' => 'secret-only' ],
		] );

		$this->assertNull( $this->settings->get_oauth_url( 'trakt' ) );
	}
}

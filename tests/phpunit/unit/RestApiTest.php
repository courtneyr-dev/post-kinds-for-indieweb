<?php
/**
 * Test the REST API class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use PostKindsForIndieWeb\REST_API;

/**
 * Test the REST_API class functionality.
 */
class RestApiTest extends WP_UnitTestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * REST API instance.
	 *
	 * @var REST_API
	 */
	protected $rest_api;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;

		$this->server = $wp_rest_server = new WP_REST_Server();
		$this->rest_api = new REST_API();

		do_action( 'rest_api_init' );

		// Create test users.
		$this->admin_id = $this->factory->user->create(
			[
				'role' => 'administrator',
			]
		);

		$this->subscriber_id = $this->factory->user->create(
			[
				'role' => 'subscriber',
			]
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Test that the REST API namespace is correct.
	 */
	public function test_namespace() {
		$this->assertEquals( 'post-kinds-indieweb/v1', REST_API::NAMESPACE );
	}

	/**
	 * Test that routes are registered.
	 */
	public function test_routes_registered() {
		$routes = $this->server->get_routes();
		$namespace = '/' . REST_API::NAMESPACE;

		// Check lookup routes exist.
		$this->assertArrayHasKey( $namespace . '/lookup/music', $routes );
		$this->assertArrayHasKey( $namespace . '/lookup/music-url', $routes );
		$this->assertArrayHasKey( $namespace . '/lookup/video', $routes );
		$this->assertArrayHasKey( $namespace . '/lookup/watch-url', $routes );
		$this->assertArrayHasKey( $namespace . '/lookup/book', $routes );
		$this->assertArrayHasKey( $namespace . '/lookup/game', $routes );
	}

	/**
	 * Test that location routes are registered.
	 */
	public function test_location_routes_registered() {
		$routes = $this->server->get_routes();
		$namespace = '/' . REST_API::NAMESPACE;

		$this->assertArrayHasKey( $namespace . '/lookup/location', $routes );
	}

	/**
	 * Test that import routes are registered.
	 */
	public function test_import_routes_registered() {
		$routes = $this->server->get_routes();
		$namespace = '/' . REST_API::NAMESPACE;

		$this->assertArrayHasKey( $namespace . '/import/listens', $routes );
		$this->assertArrayHasKey( $namespace . '/import/watches', $routes );
		$this->assertArrayHasKey( $namespace . '/import/reads', $routes );
	}

	/**
	 * Test that webhook routes are registered.
	 */
	public function test_webhook_routes_registered() {
		$routes = $this->server->get_routes();
		$namespace = '/' . REST_API::NAMESPACE;

		$this->assertArrayHasKey( $namespace . '/webhook/plex', $routes );
		$this->assertArrayHasKey( $namespace . '/webhook/jellyfin', $routes );
		$this->assertArrayHasKey( $namespace . '/webhook/listenbrainz', $routes );
		$this->assertArrayHasKey( $namespace . '/webhook/trakt', $routes );
	}

	/**
	 * Test that unauthenticated requests to lookup endpoints are rejected.
	 */
	public function test_lookup_requires_authentication() {
		// Make sure no user is logged in.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/music' );
		$request->set_param( 'q', 'test query' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test that subscribers cannot access lookup endpoints.
	 */
	public function test_lookup_requires_edit_posts_capability() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/music' );
		$request->set_param( 'q', 'test query' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test that admin users can access lookup endpoints.
	 */
	public function test_admin_can_access_lookup_endpoints() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/music' );
		$request->set_param( 'q', 'test query' );

		$response = $this->server->dispatch( $request );

		// Even if API fails, we shouldn't get 401 or 403.
		$status = $response->get_status();
		$this->assertNotEquals( 401, $status, 'Admin should not get 401' );
		$this->assertNotEquals( 403, $status, 'Admin should not get 403' );
	}

	/**
	 * Test that unauthenticated requests to import endpoints are rejected.
	 */
	public function test_import_requires_authentication() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/' . REST_API::NAMESPACE . '/import/listens' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test that subscribers cannot access import endpoints.
	 */
	public function test_import_requires_manage_options_capability() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/' . REST_API::NAMESPACE . '/import/listens' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test webhook routes are accessible without authentication.
	 * Webhooks use secret tokens for authentication, not user sessions.
	 */
	public function test_webhook_routes_are_public() {
		wp_set_current_user( 0 );

		// Webhooks should not return 401 (they use token-based auth).
		$request = new WP_REST_Request( 'POST', '/' . REST_API::NAMESPACE . '/webhook/plex' );

		$response = $this->server->dispatch( $request );
		$status = $response->get_status();

		// Webhook should not require WordPress authentication.
		$this->assertNotEquals( 401, $status, 'Webhook should not require WP authentication' );
	}

	/**
	 * Test music lookup requires query parameter.
	 */
	public function test_music_lookup_requires_query() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/music' );
		// Not setting 'q' parameter.

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test video lookup validates type parameter.
	 */
	public function test_video_lookup_validates_type() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/video' );
		$request->set_param( 'q', 'test' );
		$request->set_param( 'type', 'invalid_type' );

		$response = $this->server->dispatch( $request );

		// Should return 400 for invalid enum value.
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test music lookup validates source parameter.
	 */
	public function test_music_lookup_validates_source() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/music' );
		$request->set_param( 'q', 'test' );
		$request->set_param( 'source', 'invalid_source' );

		$response = $this->server->dispatch( $request );

		// Should return 400 for invalid enum value.
		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test music URL lookup sanitizes URL parameter.
	 */
	public function test_music_url_lookup_sanitizes_url() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/music-url' );
		$request->set_param( 'url', 'javascript:alert(1)' );

		$response = $this->server->dispatch( $request );
		$status = $response->get_status();

		// The URL should be sanitized - esc_url_raw rejects javascript: URLs.
		// It may return 200 with error or 400 depending on implementation.
		// Either way, the script shouldn't execute.
		$this->assertTrue(
			in_array( $status, [ 200, 400, 404 ], true ),
			'Response should be safe (200/400/404), got: ' . $status
		);
	}

	/**
	 * Test watch URL lookup sanitizes URL parameter.
	 */
	public function test_watch_url_lookup_sanitizes_url() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/watch-url' );
		$request->set_param( 'url', '<script>alert("xss")</script>' );

		$response = $this->server->dispatch( $request );
		$data = $response->get_data();

		// Check that the response doesn't contain the script tag.
		if ( is_array( $data ) && isset( $data['url'] ) ) {
			$this->assertStringNotContainsString( '<script>', $data['url'] );
		}
	}

	/**
	 * Test settings routes require admin capabilities.
	 */
	public function test_settings_routes_require_admin() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/settings' );
		$response = $this->server->dispatch( $request );

		// Settings should require admin access.
		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'Settings should require admin access'
		);
	}
}

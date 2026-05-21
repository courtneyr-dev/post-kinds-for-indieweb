<?php
/**
 * Test the Webhook Handler class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use PostKindsForIndieWeb\Webhook_Handler;

/**
 * Test the Webhook_Handler class functionality.
 *
 * @covers \PostKindsForIndieWeb\Webhook_Handler
 */
class WebhookHandlerTest extends WP_UnitTestCase {

	/**
	 * Handler instance.
	 *
	 * @var Webhook_Handler
	 */
	protected $handler;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->handler = new Webhook_Handler();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'post_kinds_webhook_token_plex' );
		delete_option( 'post_kinds_webhook_token_jellyfin' );
		delete_option( 'post_kinds_webhook_token_listenbrainz' );
		delete_option( 'post_kinds_webhook_token_generic' );
		delete_option( 'post_kinds_pending_scrobbles' );
		delete_option( 'post_kinds_webhook_auto_post' );
		delete_option( 'post_kinds_webhook_log' );
		delete_option( 'post_kinds_raw_webhooks' );
		parent::tear_down();
	}

	// ------------------------------------------------------------------
	// get_endpoints
	// ------------------------------------------------------------------

	/**
	 * Data provider for endpoint keys.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function data_endpoint_keys(): array {
		return [
			'plex'         => [ 'plex' ],
			'jellyfin'     => [ 'jellyfin' ],
			'trakt'        => [ 'trakt' ],
			'listenbrainz' => [ 'listenbrainz' ],
			'generic'      => [ 'generic' ],
		];
	}

	/**
	 * Test that get_endpoints returns all five expected endpoints.
	 */
	public function test_get_endpoints_returns_five_endpoints() {
		$endpoints = $this->handler->get_endpoints();
		$this->assertCount( 5, $endpoints );
	}

	/**
	 * Test each expected endpoint key exists.
	 *
	 * @dataProvider data_endpoint_keys
	 *
	 * @param string $key Endpoint key.
	 */
	public function test_endpoint_exists( string $key ) {
		$endpoints = $this->handler->get_endpoints();
		$this->assertArrayHasKey( $key, $endpoints );
	}

	// ------------------------------------------------------------------
	// Auth types and content types
	// ------------------------------------------------------------------

	/**
	 * Test Plex uses token auth and multipart content type.
	 */
	public function test_plex_uses_token_auth_and_multipart() {
		$endpoints = $this->handler->get_endpoints();
		$plex = $endpoints['plex'];

		$this->assertSame( 'token', $plex['auth_type'] );
		$this->assertSame( 'multipart/form-data', $plex['content_type'] );
	}

	/**
	 * Test Trakt uses no auth.
	 */
	public function test_trakt_uses_no_auth() {
		$endpoints = $this->handler->get_endpoints();
		$this->assertSame( 'none', $endpoints['trakt']['auth_type'] );
	}

	/**
	 * Test Jellyfin uses token auth and JSON content type.
	 */
	public function test_jellyfin_uses_token_auth_and_json() {
		$endpoints = $this->handler->get_endpoints();
		$jellyfin = $endpoints['jellyfin'];

		$this->assertSame( 'token', $jellyfin['auth_type'] );
		$this->assertSame( 'application/json', $jellyfin['content_type'] );
	}

	// ------------------------------------------------------------------
	// handle_request: unknown service
	// ------------------------------------------------------------------

	/**
	 * Test handle_request with unknown service returns WP_Error.
	 */
	public function test_handle_request_unknown_service_returns_error() {
		$request = new WP_REST_Request( 'POST' );
		$result  = $this->handler->handle_request( $request, 'spotify' );

		$this->assertWPError( $result );
		$this->assertSame( 'unknown_service', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 404, $data['status'] );
	}

	// ------------------------------------------------------------------
	// Token authentication
	// ------------------------------------------------------------------

	/**
	 * Test missing token returns 401 for token-auth service.
	 */
	public function test_missing_token_returns_401() {
		update_option( 'post_kinds_webhook_token_plex', 'expected-token-value' );

		$request = new WP_REST_Request( 'POST' );
		// No token header or param set.
		$result = $this->handler->handle_request( $request, 'plex' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_token', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * Test invalid token returns 403 for token-auth service.
	 */
	public function test_invalid_token_returns_403() {
		update_option( 'post_kinds_webhook_token_plex', 'correct-token' );

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', 'wrong-token' );

		$result = $this->handler->handle_request( $request, 'plex' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	/**
	 * Test valid X-Webhook-Token passes auth and processes Plex scrobble.
	 */
	public function test_valid_token_passes_auth_plex_scrobble() {
		$token = 'my-valid-token-1234';
		update_option( 'post_kinds_webhook_token_plex', $token );

		$payload = wp_json_encode( [
			'event'    => 'media.scrobble',
			'Metadata' => [
				'type'  => 'movie',
				'title' => 'Inception',
				'year'  => 2010,
				'key'   => '/library/metadata/12345',
			],
			'Account'  => [ 'title' => 'TestUser' ],
			'Player'   => [ 'title' => 'Chrome' ],
		] );

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		// Plex uses multipart, payload comes via 'payload' param.
		$request->set_param( 'payload', $payload );

		$result = $this->handler->handle_request( $request, 'plex' );

		$this->assertNotWPError( $result );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );

		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'queued', $data['data']['action'] );
	}

	/**
	 * Test Authorization Bearer token works for Jellyfin.
	 */
	public function test_bearer_token_auth_works() {
		$token = 'bearer-test-token-5678';
		update_option( 'post_kinds_webhook_token_jellyfin', $token );

		$body = wp_json_encode( [
			'NotificationType'     => 'PlaybackStop',
			'PlayedToCompletion'   => true,
			'ItemType'             => 'Movie',
			'Name'                 => 'Interstellar',
			'Year'                 => 2014,
			'ItemId'               => 'jf-001',
			'NotificationUsername' => 'TestUser',
			'DeviceName'           => 'Firefox',
		] );

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$request->set_body( $body );

		$result = $this->handler->handle_request( $request, 'jellyfin' );

		$this->assertNotWPError( $result );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );

		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Test token query param auth works.
	 */
	public function test_token_query_param_auth_works() {
		$token = 'query-param-token-9012';
		update_option( 'post_kinds_webhook_token_generic', $token );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'token', $token );
		// Generic with empty body returns params (no JSON parse error).
		$request->set_body( '' );

		$result = $this->handler->handle_request( $request, 'generic' );

		$this->assertNotWPError( $result );
		$this->assertSame( 200, $result->get_status() );
	}

	// ------------------------------------------------------------------
	// Plex: ignores non-scrobble events
	// ------------------------------------------------------------------

	/**
	 * Test Plex ignores non-scrobble events.
	 */
	public function test_plex_ignores_non_scrobble_events() {
		$token = 'plex-token';
		update_option( 'post_kinds_webhook_token_plex', $token );

		$payload = wp_json_encode( [
			'event'    => 'media.play',
			'Metadata' => [ 'type' => 'movie', 'title' => 'Test' ],
		] );

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		$request->set_param( 'payload', $payload );

		$result = $this->handler->handle_request( $request, 'plex' );

		$this->assertNotWPError( $result );
		$data = $result->get_data();
		$this->assertSame( 'ignored', $data['data']['action'] );
		$this->assertSame( 'media.play', $data['data']['event'] );
	}

	// ------------------------------------------------------------------
	// generate_token
	// ------------------------------------------------------------------

	/**
	 * Test generate_token creates a 32-char hex token and stores it.
	 */
	public function test_generate_token_creates_32_char_token_and_stores() {
		$token = $this->handler->generate_token( 'plex' );

		$this->assertSame( 32, strlen( $token ) );
		$this->assertSame( $token, get_option( 'post_kinds_webhook_token_plex' ) );
	}

	// ------------------------------------------------------------------
	// get_webhook_url
	// ------------------------------------------------------------------

	/**
	 * Test get_webhook_url contains correct REST route.
	 */
	public function test_get_webhook_url_contains_correct_route() {
		$url = $this->handler->get_webhook_url( 'jellyfin' );

		$this->assertStringContainsString( 'post-kinds-indieweb/v1/webhook/jellyfin', $url );
	}

	// ------------------------------------------------------------------
	// Pending scrobbles CRUD
	// ------------------------------------------------------------------

	/**
	 * Test get_pending_scrobbles returns empty array by default.
	 */
	public function test_pending_scrobbles_empty_by_default() {
		$this->assertSame( [], $this->handler->get_pending_scrobbles() );
	}

	/**
	 * Test scrobble is queued when auto_post is off.
	 */
	public function test_scrobble_is_queued_when_auto_post_off() {
		$token = 'test-token';
		update_option( 'post_kinds_webhook_token_plex', $token );
		update_option( 'post_kinds_webhook_auto_post', false );

		$payload = wp_json_encode( [
			'event'    => 'media.scrobble',
			'Metadata' => [
				'type'  => 'movie',
				'title' => 'The Matrix',
				'year'  => 1999,
				'key'   => '/library/metadata/999',
			],
			'Account'  => [ 'title' => 'Neo' ],
			'Player'   => [ 'title' => 'Zion' ],
		] );

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		$request->set_param( 'payload', $payload );

		$this->handler->handle_request( $request, 'plex' );

		$pending = $this->handler->get_pending_scrobbles();
		$this->assertCount( 1, $pending );
		$this->assertSame( 'movie', $pending[0]['type'] );
		$this->assertSame( 'The Matrix', $pending[0]['title'] );
	}

	/**
	 * Test reject_scrobble removes item from pending.
	 */
	public function test_reject_scrobble_removes_from_pending() {
		// Seed a pending scrobble.
		update_option( 'post_kinds_pending_scrobbles', [
			[
				'source' => 'plex',
				'type'   => 'movie',
				'title'  => 'Old Movie',
			],
		] );

		$result = $this->handler->reject_scrobble( 0 );

		$this->assertTrue( $result );
		$this->assertCount( 0, $this->handler->get_pending_scrobbles() );
	}

	/**
	 * Test reject_scrobble returns false for non-existent index.
	 */
	public function test_reject_scrobble_returns_false_for_missing_index() {
		$this->assertFalse( $this->handler->reject_scrobble( 99 ) );
	}

	/**
	 * Test pending scrobbles cap at 100.
	 */
	public function test_pending_scrobbles_cap_at_100() {
		// Seed 105 items.
		$items = [];
		for ( $i = 0; $i < 105; $i++ ) {
			$items[] = [
				'source' => 'plex',
				'type'   => 'movie',
				'title'  => "Movie {$i}",
			];
		}
		update_option( 'post_kinds_pending_scrobbles', $items );

		// Trigger store_pending_scrobble via a new webhook.
		$token = 'cap-token';
		update_option( 'post_kinds_webhook_token_plex', $token );
		update_option( 'post_kinds_webhook_auto_post', false );

		$payload = wp_json_encode( [
			'event'    => 'media.scrobble',
			'Metadata' => [
				'type'  => 'movie',
				'title' => 'Latest Movie',
				'year'  => 2026,
				'key'   => '/library/metadata/9999',
			],
			'Account'  => [ 'title' => 'User' ],
			'Player'   => [ 'title' => 'Device' ],
		] );

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		$request->set_param( 'payload', $payload );

		$this->handler->handle_request( $request, 'plex' );

		$pending = $this->handler->get_pending_scrobbles();
		$this->assertLessThanOrEqual( 100, count( $pending ) );
		// The newest item should be last.
		$last = end( $pending );
		$this->assertSame( 'Latest Movie', $last['title'] );
	}

	// ------------------------------------------------------------------
	// Invalid JSON
	// ------------------------------------------------------------------

	/**
	 * Test invalid JSON body returns 400 for JSON content type endpoint.
	 */
	public function test_invalid_json_returns_400() {
		// Trakt uses 'none' auth, so no token needed.
		$request = new WP_REST_Request( 'POST' );
		$request->set_body( '{not valid json' );

		$result = $this->handler->handle_request( $request, 'trakt' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_json', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	/**
	 * Test invalid JSON in Plex multipart payload param returns 400.
	 */
	public function test_invalid_json_in_plex_payload_param_returns_400() {
		$token = 'plex-json-test';
		update_option( 'post_kinds_webhook_token_plex', $token );

		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'X-Webhook-Token', $token );
		$request->set_param( 'payload', '{broken json here' );

		$result = $this->handler->handle_request( $request, 'plex' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_json', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	// ------------------------------------------------------------------
	// Trakt passes auth with none type
	// ------------------------------------------------------------------

	/**
	 * Test Trakt processes scrobble without requiring a token.
	 */
	public function test_trakt_no_auth_processes_scrobble() {
		$body = wp_json_encode( [
			'action' => 'scrobble',
			'movie'  => [
				'title' => 'Blade Runner 2049',
				'year'  => 2017,
				'ids'   => [
					'trakt' => 'trakt-123',
					'imdb'  => 'tt1856101',
					'tmdb'  => '335984',
				],
			],
		] );

		$request = new WP_REST_Request( 'POST' );
		$request->set_body( $body );

		$result = $this->handler->handle_request( $request, 'trakt' );

		$this->assertNotWPError( $result );
		$this->assertSame( 200, $result->get_status() );

		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'queued', $data['data']['action'] );
	}

	// ------------------------------------------------------------------
	// Approve scrobble
	// ------------------------------------------------------------------

	/**
	 * Test approve_scrobble returns error for non-existent index.
	 */
	public function test_approve_scrobble_returns_error_for_missing_index() {
		$result = $this->handler->approve_scrobble( 42 );

		$this->assertWPError( $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// get_log
	// ------------------------------------------------------------------

	/**
	 * Test get_log returns empty array by default.
	 */
	public function test_get_log_returns_empty_by_default() {
		$this->assertSame( [], $this->handler->get_log() );
	}
}

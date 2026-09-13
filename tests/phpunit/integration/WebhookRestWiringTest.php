<?php
/**
 * The REST webhook callbacks must reach Webhook_Handler::handle_request().
 *
 * Regression for the audit finding R-10: every authenticated webhook POST
 * returned 500 because the REST callbacks called process_*() methods that
 * do not exist on the handler.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\REST_API;
use PKIW\Webhook_Handler;
use WP_REST_Request;
use WP_UnitTestCase;

final class WebhookRestWiringTest extends WP_UnitTestCase {

	/**
	 * Every webhook route resolves to a response from the handler, never a fatal.
	 *
	 * @dataProvider services
	 *
	 * @param string $service Service segment.
	 */
	public function test_webhook_route_dispatches_to_handler( string $service ): void {
		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$server = rest_get_server();
		remove_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$routes = array_keys( $server->get_routes() );
		$route  = null;
		foreach ( $routes as $candidate ) {
			if ( false !== strpos( $candidate, '/webhook/' ) && false !== strpos( $candidate, $service ) ) {
				$route = $candidate;
				break;
			}
		}
		$this->assertNotNull( $route, "No REST route registered for the {$service} webhook." );

		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body( '{"event":"probe"}' );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = $server->dispatch( $request );
		$status   = $response->get_status();

		$this->assertNotSame( 500, $status, "The {$service} webhook callback still fails before reaching the handler." );
		$this->assertContains( $status, array( 200, 400, 401, 403, 404, 422 ), "Unexpected status {$status} for {$service}." );
	}

	/**
	 * The callbacks call a method that exists.
	 */
	public function test_handler_exposes_handle_request(): void {
		$this->assertTrue( method_exists( Webhook_Handler::class, 'handle_request' ) );
		foreach ( array( 'listenbrainz', 'trakt', 'plex', 'jellyfin', 'generic' ) as $service ) {
			$this->assertFalse( method_exists( Webhook_Handler::class, 'process_' . $service ), "process_{$service}() does not exist; the callbacks must not call it." );
		}
	}

	/**
	 * Services wired in the REST layer.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function services(): array {
		return array(
			'listenbrainz' => array( 'listenbrainz' ),
			'trakt'        => array( 'trakt' ),
			'plex'         => array( 'plex' ),
			'jellyfin'     => array( 'jellyfin' ),
			'generic'      => array( 'generic' ),
		);
	}

	/**
	 * A POST signed with the site webhook secret reaches the handler and succeeds
	 * without a second per-service token (the two layers used to disagree).
	 */
	public function test_signed_listenbrainz_post_is_accepted(): void {
		update_option( 'pkiw_webhook_secret', 'unit-secret' );
		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$server = rest_get_server();
		remove_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		$body    = wp_json_encode( array( 'listen_type' => 'single', 'payload' => array( array( 'listened_at' => 1726000000, 'track_metadata' => array( 'artist_name' => 'Unit Artist', 'track_name' => 'Unit Track' ) ) ) ) );
		$request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/webhook/listenbrainz' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Webhook-Signature', hash_hmac( 'sha256', $body, 'unit-secret' ) );
		$request->set_body( $body );
		$status = $server->dispatch( $request )->get_status();

		$this->assertGreaterThanOrEqual( 200, $status );
		$this->assertLessThan( 300, $status, 'a correctly signed ListenBrainz POST must succeed' );
	}

	/**
	 * A tampered signature is still refused before the handler runs.
	 */
	public function test_tampered_signature_is_refused(): void {
		update_option( 'pkiw_webhook_secret', 'unit-secret' );
		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$server = rest_get_server();
		remove_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		$request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/webhook/listenbrainz' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Webhook-Signature', hash_hmac( 'sha256', '{"other":1}', 'unit-secret' ) );
		$request->set_body( '{"listen_type":"single"}' );

		$this->assertContains( $server->dispatch( $request )->get_status(), array( 401, 403 ) );
	}

	/**
	 * The generic route accepts the site secret as its token.
	 */
	public function test_generic_post_with_site_token_is_accepted(): void {
		update_option( 'pkiw_webhook_secret', 'unit-secret' );
		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$server = rest_get_server();
		remove_action( 'rest_api_init', array( $rest, 'register_routes' ) );

		$request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/webhook/generic' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Webhook-Token', 'unit-secret' );
		$request->set_body( wp_json_encode( array( 'kind' => 'listen', 'title' => 'Unit Generic' ) ) );
		$status = $server->dispatch( $request )->get_status();

		$this->assertNotContains( $status, array( 401, 403, 500 ), 'a valid site token must pass both layers' );
	}
}

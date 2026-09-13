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
}

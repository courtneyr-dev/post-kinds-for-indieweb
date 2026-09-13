<?php
/**
 * Plex and Jellyfin webhook routes authorize with per-service tokens.
 *
 * Plex Media Server posts multipart/form-data and cannot add headers or sign
 * the body. The Jellyfin Webhook plugin can add request headers but cannot
 * sign the body. Both routes used to demand the site HMAC signature, so every
 * real delivery got a 401. Every test here dispatches through the REST server,
 * so the route's permission_callback runs before the handler.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\REST_API;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * REST-level authorization for the Plex and Jellyfin webhook routes.
 */
final class PlexJellyfinWebhookAuthTest extends WP_UnitTestCase {

	private const NS             = '/post-kinds-indieweb/v1';
	private const PLEX_TOKEN     = 'fake-plex-token-0123456789abcdef';
	private const JELLYFIN_TOKEN = 'fake-jellyfin-token-0123456789ab';
	private const SITE_SECRET    = 'fake-site-webhook-secret-0123456';

	/**
	 * REST server with the plugin routes registered.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Outbound HTTP attempts made during a test.
	 *
	 * @var string[]
	 */
	private array $outbound = [];

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );

		update_option( 'pkiw_webhook_token_plex', self::PLEX_TOKEN );
		update_option( 'pkiw_webhook_token_jellyfin', self::JELLYFIN_TOKEN );
		update_option( 'pkiw_webhook_secret', self::SITE_SECRET );
		delete_option( 'pkiw_pending_scrobbles' );
		delete_option( 'pkiw_webhook_auto_post' );

		$this->outbound = [];
		add_filter( 'pre_http_request', [ $this, 'block_outbound_http' ], 1, 3 );

		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		$this->server = rest_get_server();
		remove_action( 'rest_api_init', [ $rest, 'register_routes' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_outbound_http' ], 1 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Record and refuse any outbound HTTP request.
	 *
	 * @param false|array<string, mixed> $pre  Short-circuit value.
	 * @param array<string, mixed>       $args Request args.
	 * @param string                     $url  Request URL.
	 * @return \WP_Error
	 */
	public function block_outbound_http( $pre, $args, $url ) {
		$this->outbound[] = $url;
		return new \WP_Error( 'pkiw_test_http_blocked', 'Outbound HTTP is blocked in this test.' );
	}

	// ------------------------------------------------------------------
	// Plex
	// ------------------------------------------------------------------

	public function test_plex_multipart_scrobble_with_valid_query_token_is_queued(): void {
		$response = $this->server->dispatch( $this->plex_request( [ 'token' => self::PLEX_TOKEN ] ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'queued', $data['data']['action'] );
		$this->assertSame( 'Inception', $data['data']['title'] );

		$pending = get_option( 'pkiw_pending_scrobbles' );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'plex', $pending[0]['source'] );
		$this->assertSame( 'tt1375666', $pending[0]['imdb_id'] );
		$this->assertSame( 27205, $pending[0]['tmdb_id'] );
		$this->assertSame( [], $this->outbound );
	}

	public function test_plex_scrobble_with_auto_post_creates_watch_post_without_outbound_http(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );

		$response = $this->server->dispatch( $this->plex_request( [ 'token' => self::PLEX_TOKEN ] ) );

		$this->assertSame( 200, $response->get_status() );
		$data    = $response->get_data();
		$post_id = $data['data']['post_id'];
		$this->assertSame( 'created', $data['data']['action'] );
		$this->assertSame( 'Watched Inception', get_the_title( $post_id ) );
		$this->assertSame( 'tt1375666', get_post_meta( $post_id, '_pkiw_watch_imdb', true ) );
		$this->assertSame( 'plex', get_post_meta( $post_id, '_pkiw_webhook_source', true ) );
		$this->assertTrue( has_term( 'watch', 'kind', $post_id ) );
		$this->assertSame( [], $this->outbound );
	}

	/**
	 * @dataProvider data_rejected_plex_queries
	 *
	 * @param array<string, mixed> $query Query parameters.
	 */
	public function test_plex_rejects_bad_query_tokens( array $query ): void {
		$response = $this->server->dispatch( $this->plex_request( $query ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( get_option( 'pkiw_pending_scrobbles' ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function data_rejected_plex_queries(): array {
		return [
			'wrong token'       => [ [ 'token' => 'fake-plex-token-wrong-000000000' ] ],
			'missing token'     => [ [] ],
			'empty token'       => [ [ 'token' => '' ] ],
			'token as an array' => [ [ 'token' => [ self::PLEX_TOKEN ] ] ],
			'jellyfin token'    => [ [ 'token' => self::JELLYFIN_TOKEN ] ],
			'site secret'       => [ [ 'token' => self::SITE_SECRET ] ],
		];
	}

	/**
	 * Plex can only use the query string, so no other location is read.
	 *
	 * @dataProvider data_plex_token_outside_query
	 *
	 * @param string $where Where the valid token is placed.
	 */
	public function test_plex_ignores_valid_token_outside_query_string( string $where ): void {
		$request = $this->plex_request( [] );

		if ( 'header' === $where ) {
			$request->set_header( 'X-Webhook-Token', self::PLEX_TOKEN );
		} elseif ( 'bearer' === $where ) {
			$request->set_header( 'Authorization', 'Bearer ' . self::PLEX_TOKEN );
		} else {
			$request->set_body_params( [ 'payload' => $this->fixture( 'plex/media-scrobble-movie.json' ), 'token' => self::PLEX_TOKEN ] );
		}

		$this->assertSame( 401, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function data_plex_token_outside_query(): array {
		return [
			'X-Webhook-Token header' => [ 'header' ],
			'Bearer header'          => [ 'bearer' ],
			'multipart form field'   => [ 'form' ],
		];
	}

	public function test_plex_no_longer_accepts_site_hmac_signature(): void {
		$request = $this->plex_request( [] );
		$request->set_header( 'X-Webhook-Signature', hash_hmac( 'sha256', $request->get_body(), self::SITE_SECRET ) );

		$this->assertSame( 401, $this->server->dispatch( $request )->get_status() );
	}

	public function test_plex_refuses_everything_when_token_unset_and_does_not_create_one(): void {
		delete_option( 'pkiw_webhook_token_plex' );

		foreach ( [ [], [ 'token' => '' ], [ 'token' => self::PLEX_TOKEN ] ] as $query ) {
			$this->assertSame( 401, $this->server->dispatch( $this->plex_request( $query ) )->get_status() );
		}
		$this->assertFalse( get_option( 'pkiw_webhook_token_plex' ) );
	}

	public function test_plex_refuses_empty_token_when_stored_token_is_empty(): void {
		update_option( 'pkiw_webhook_token_plex', '' );

		$this->assertSame( 401, $this->server->dispatch( $this->plex_request( [ 'token' => '' ] ) )->get_status() );
	}

	// ------------------------------------------------------------------
	// Jellyfin
	// ------------------------------------------------------------------

	public function test_jellyfin_playback_stop_with_header_token_is_queued(): void {
		$request  = $this->jellyfin_request( [ 'X-Webhook-Token' => self::JELLYFIN_TOKEN ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'queued', $data['data']['action'] );
		$this->assertSame( 'Interstellar', $data['data']['title'] );

		$pending = get_option( 'pkiw_pending_scrobbles' );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'jellyfin', $pending[0]['source'] );
		$this->assertSame( 'tt0816692', $pending[0]['imdb_id'] );
	}

	public function test_jellyfin_bearer_token_is_accepted(): void {
		$response = $this->server->dispatch( $this->jellyfin_request( [ 'Authorization' => 'Bearer ' . self::JELLYFIN_TOKEN ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['data']['action'] );
	}

	/**
	 * The Generic destination posts text/plain unless the admin adds a Content-Type header.
	 */
	public function test_jellyfin_default_text_plain_body_is_parsed(): void {
		$request  = $this->jellyfin_request( [ 'X-Webhook-Token' => self::JELLYFIN_TOKEN ], 'jellyfin/playback-stop-movie.json', 'text/plain; charset=utf-8' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Interstellar', $response->get_data()['data']['title'] );
	}

	public function test_jellyfin_marked_played_event_is_authorized_but_not_queued(): void {
		$request  = $this->jellyfin_request( [ 'X-Webhook-Token' => self::JELLYFIN_TOKEN ], 'jellyfin/user-data-saved-toggle-played-episode.json' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ignored', $response->get_data()['data']['action'] );
		$this->assertSame( 'UserDataSaved', $response->get_data()['data']['event'] );
		$this->assertFalse( get_option( 'pkiw_pending_scrobbles' ) );
	}

	/**
	 * @dataProvider data_rejected_jellyfin_headers
	 *
	 * @param array<string, string> $headers Request headers.
	 */
	public function test_jellyfin_rejects_bad_tokens( array $headers ): void {
		$response = $this->server->dispatch( $this->jellyfin_request( $headers ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( get_option( 'pkiw_pending_scrobbles' ) );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function data_rejected_jellyfin_headers(): array {
		return [
			'wrong header token'                => [ [ 'X-Webhook-Token' => 'fake-jellyfin-token-wrong-000000' ] ],
			'missing token'                     => [ [] ],
			'empty header token'                => [ [ 'X-Webhook-Token' => '' ] ],
			'wrong bearer token'                => [ [ 'Authorization' => 'Bearer fake-jellyfin-token-wrong-000000' ] ],
			'empty bearer token'                => [ [ 'Authorization' => 'Bearer ' ] ],
			'token without Bearer scheme'       => [ [ 'Authorization' => self::JELLYFIN_TOKEN ] ],
			'plex token in header'              => [ [ 'X-Webhook-Token' => self::PLEX_TOKEN ] ],
			'plex token as bearer'              => [ [ 'Authorization' => 'Bearer ' . self::PLEX_TOKEN ] ],
			'wrong header beats a valid bearer' => [
				[
					'X-Webhook-Token' => 'fake-jellyfin-token-wrong-000000',
					'Authorization'   => 'Bearer ' . self::JELLYFIN_TOKEN,
				],
			],
		];
	}

	public function test_jellyfin_ignores_valid_token_in_query_string(): void {
		$request = $this->jellyfin_request( [] );
		$request->set_query_params( [ 'token' => self::JELLYFIN_TOKEN ] );

		$this->assertSame( 401, $this->server->dispatch( $request )->get_status() );
	}

	public function test_jellyfin_no_longer_accepts_site_hmac_signature(): void {
		$request = $this->jellyfin_request( [] );
		$request->set_header( 'X-Webhook-Signature', hash_hmac( 'sha256', $request->get_body(), self::SITE_SECRET ) );

		$this->assertSame( 401, $this->server->dispatch( $request )->get_status() );
	}

	public function test_jellyfin_refuses_when_token_unset(): void {
		delete_option( 'pkiw_webhook_token_jellyfin' );

		$this->assertSame( 401, $this->server->dispatch( $this->jellyfin_request( [ 'X-Webhook-Token' => '' ] ) )->get_status() );
		$this->assertSame( 401, $this->server->dispatch( $this->jellyfin_request( [ 'X-Webhook-Token' => self::JELLYFIN_TOKEN ] ) )->get_status() );
		$this->assertFalse( get_option( 'pkiw_webhook_token_jellyfin' ) );
	}

	// ------------------------------------------------------------------
	// ListenBrainz, Trakt and generic stay on the site secret
	// ------------------------------------------------------------------

	/**
	 * @dataProvider data_signed_services
	 *
	 * @param string $service Route segment.
	 * @param string $body    JSON body.
	 */
	public function test_signed_service_accepts_site_hmac( string $service, string $body ): void {
		$request = $this->json_request( $service, $body );
		$request->set_header( 'X-Webhook-Signature', hash_hmac( 'sha256', $body, self::SITE_SECRET ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * @dataProvider data_signed_services
	 *
	 * @param string $service Route segment.
	 * @param string $body    JSON body.
	 */
	public function test_signed_service_rejects_missing_tampered_and_service_tokens( string $service, string $body ): void {
		$this->assertSame( 401, $this->server->dispatch( $this->json_request( $service, $body ) )->get_status(), 'unsigned' );

		$tampered = $this->json_request( $service, $body );
		$tampered->set_header( 'X-Webhook-Signature', hash_hmac( 'sha256', $body . ' ', self::SITE_SECRET ) );
		$this->assertSame( 401, $this->server->dispatch( $tampered )->get_status(), 'tampered' );

		$plex = $this->json_request( $service, $body );
		$plex->set_query_params( [ 'token' => self::PLEX_TOKEN ] );
		$plex->set_header( 'X-Webhook-Token', self::PLEX_TOKEN );
		$this->assertSame( 401, $this->server->dispatch( $plex )->get_status(), 'plex token' );

		$jellyfin = $this->json_request( $service, $body );
		$jellyfin->set_header( 'Authorization', 'Bearer ' . self::JELLYFIN_TOKEN );
		$jellyfin->set_header( 'X-Webhook-Signature', hash_hmac( 'sha256', $body, self::JELLYFIN_TOKEN ) );
		$this->assertSame( 401, $this->server->dispatch( $jellyfin )->get_status(), 'jellyfin token' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_signed_services(): array {
		return [
			'listenbrainz' => [
				'listenbrainz',
				'{"listen_type":"single","payload":[{"listened_at":1726246800,"track_metadata":{"artist_name":"Fixture Artist","track_name":"Fixture Track"}}]}',
			],
			'trakt'        => [
				'trakt',
				'{"action":"scrobble","movie":{"title":"Fixture Movie","year":2010,"ids":{"trakt":1,"imdb":"tt1375666","tmdb":27205}}}',
			],
		];
	}

	public function test_generic_still_accepts_site_secret_token_only(): void {
		$body = '{"kind":"listen","title":"Fixture Generic"}';

		$query = $this->json_request( 'generic', $body );
		$query->set_query_params( [ 'token' => self::SITE_SECRET ] );
		$this->assertSame( 200, $this->server->dispatch( $query )->get_status(), 'site secret in query' );

		$header = $this->json_request( 'generic', $body );
		$header->set_header( 'X-Webhook-Token', self::SITE_SECRET );
		$this->assertSame( 200, $this->server->dispatch( $header )->get_status(), 'site secret in header' );

		foreach ( [ 'fake-generic-wrong-token-0000000', self::PLEX_TOKEN, self::JELLYFIN_TOKEN ] as $token ) {
			$request = $this->json_request( 'generic', $body );
			$request->set_header( 'X-Webhook-Token', $token );
			$this->assertSame( 401, $this->server->dispatch( $request )->get_status() );
		}

		$this->assertSame( 401, $this->server->dispatch( $this->json_request( 'generic', $body ) )->get_status(), 'missing' );
	}

	// ------------------------------------------------------------------
	// Settings endpoint
	// ------------------------------------------------------------------

	public function test_settings_endpoint_returns_plex_url_with_token_and_plain_jellyfin_url(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/settings/webhooks' ) )->get_data();

		$this->assertSame( add_query_arg( 'token', self::PLEX_TOKEN, rest_url( 'post-kinds-indieweb/v1/webhook/plex' ) ), $data['plex'] );
		$this->assertSame( rest_url( 'post-kinds-indieweb/v1/webhook/jellyfin' ), $data['jellyfin'] );
	}

	public function test_settings_endpoint_does_not_create_a_plex_token(): void {
		delete_option( 'pkiw_webhook_token_plex' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', self::NS . '/settings/webhooks' ) )->get_data();

		$this->assertSame( rest_url( 'post-kinds-indieweb/v1/webhook/plex' ), $data['plex'] );
		$this->assertFalse( get_option( 'pkiw_webhook_token_plex' ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build a Plex delivery as WordPress sees it after PHP parses the multipart body.
	 *
	 * PHP moves a multipart body into $_POST and $_FILES and leaves no raw body;
	 * the REST server copies those into body and file params.
	 *
	 * @param array<string, mixed> $query Query parameters.
	 * @return WP_REST_Request
	 */
	private function plex_request( array $query ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::NS . '/webhook/plex' );
		$request->set_header( 'Content-Type', 'multipart/form-data; boundary=------------------------pkiwfixture0001' );
		$request->set_query_params( $query );
		$request->set_body_params( [ 'payload' => $this->fixture( 'plex/media-scrobble-movie.json' ) ] );
		$request->set_file_params(
			[
				'thumb' => [
					'name'     => 'thumb.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => '',
					'error'    => UPLOAD_ERR_NO_FILE,
					'size'     => 0,
				],
			]
		);

		return $request;
	}

	/**
	 * Build a Jellyfin Generic destination delivery.
	 *
	 * @param array<string, string> $headers      Request headers.
	 * @param string                $fixture      Fixture path.
	 * @param string                $content_type Content-Type header.
	 * @return WP_REST_Request
	 */
	private function jellyfin_request( array $headers, string $fixture = 'jellyfin/playback-stop-movie.json', string $content_type = 'application/json' ): WP_REST_Request {
		$request = $this->json_request( 'jellyfin', $this->fixture( $fixture ), $content_type );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * Build a POST with a raw body.
	 *
	 * @param string $service      Route segment.
	 * @param string $body         Body.
	 * @param string $content_type Content-Type header.
	 * @return WP_REST_Request
	 */
	private function json_request( string $service, string $body, string $content_type = 'application/json' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::NS . '/webhook/' . $service );
		$request->set_header( 'Content-Type', $content_type );
		$request->set_body( $body );

		return $request;
	}

	/**
	 * Read a fixture file.
	 *
	 * @param string $name Path under tests/phpunit/fixtures.
	 * @return string
	 */
	private function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name );
	}
}

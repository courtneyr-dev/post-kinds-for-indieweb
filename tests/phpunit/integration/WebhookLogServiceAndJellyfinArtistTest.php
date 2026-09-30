<?php
/**
 * Webhook log shows the service; Jellyfin audio keeps its artist (issue #214).
 *
 * Webhook_Handler::log_webhook() writes `service`, but the Webhooks admin page
 * read `source`, so every row said "Unknown" and PHP warned on the missing
 * key. The Jellyfin Webhook plugin sends a single `Artist` string for audio
 * (DataObjectHelpers.cs, `dataObject["Artist"] = audio.Artists[0]`), but the
 * handler read `Artists[0]`, so listen posts lost the artist.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use DOMDocument;
use DOMXPath;
use PKIW\Admin\Admin;
use PKIW\Admin\Webhooks_Page;
use PKIW\Plugin;
use PKIW\REST_API;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Admin log rendering and Jellyfin audio mapping.
 */
final class WebhookLogServiceAndJellyfinArtistTest extends WP_UnitTestCase {

	private const NS             = '/post-kinds-indieweb/v1';
	private const JELLYFIN_TOKEN = 'fake-jellyfin-token-0123456789ab';

	/**
	 * REST server with the plugin routes registered.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );

		update_option( 'pkiw_webhook_token_jellyfin', self::JELLYFIN_TOKEN );
		delete_option( 'pkiw_pending_scrobbles' );
		delete_option( 'pkiw_webhook_auto_post' );
		delete_option( 'pkiw_webhook_log' );
		add_filter( 'pre_http_request', [ $this, 'block_http' ], 1 );

		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		$this->server = rest_get_server();
		remove_action( 'rest_api_init', [ $rest, 'register_routes' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], 1 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Refuse outbound HTTP.
	 *
	 * @return \WP_Error
	 */
	public function block_http() {
		return new \WP_Error( 'pkiw_test_http_blocked', 'Outbound HTTP is blocked in this test.' );
	}

	// ------------------------------------------------------------------
	// Admin log
	// ------------------------------------------------------------------

	public function test_log_row_written_by_handler_shows_service_name(): void {
		$this->assertTrue( WP_DEBUG, 'log_webhook() only writes when WP_DEBUG is on.' );

		$response = $this->server->dispatch( $this->jellyfin_request( 'jellyfin/playback-stop-movie.json' ) );
		$this->assertSame( 200, $response->get_status() );

		$log = get_option( 'pkiw_webhook_log' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'jellyfin', $log[0]['service'] );

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Jellyfin', $rows[0][1] );
		$this->assertSame( 'Success', $rows[0][2] );
		$this->assertSame( 'Scrobble queued for review', $rows[0][3] );
	}

	public function test_log_renders_legacy_source_key_and_unknown_without_warnings(): void {
		update_option(
			'pkiw_webhook_log',
			[
				[
					'source'    => 'plex',
					'status'    => 'error',
					'message'   => 'Legacy message',
					'timestamp' => 300,
				],
				[
					'service'   => 'listenbrainz',
					'status'    => 'auth_failed',
					'data'      => [ 'message' => 'Webhook token required' ],
					'timestamp' => 200,
				],
				[
					'status'    => 'parse_failed',
					'timestamp' => 100,
				],
				[
					'service'   => 'custom-service',
					'status'    => 'success',
					'timestamp' => 50,
				],
			],
			false
		);

		$rows = $this->log_rows();

		$this->assertSame( [ 'Plex', 'ListenBrainz', 'Unknown', 'custom-service' ], array_column( $rows, 1 ) );
		$this->assertSame( [ 'Legacy message', 'Webhook token required', '', '' ], array_column( $rows, 3 ) );
	}

	public function test_pending_row_shows_service_name(): void {
		$this->server->dispatch( $this->jellyfin_request( 'jellyfin/playback-stop-movie.json' ) );

		$xpath = $this->render_page();
		$cells = $xpath->query( '//div[contains(@class,"pending-scrobbles")]//tbody/tr/td[2]' );

		$this->assertSame( 1, $cells->length );
		$this->assertSame( 'Jellyfin', trim( $cells->item( 0 )->textContent ) );
	}

	// ------------------------------------------------------------------
	// Jellyfin audio
	// ------------------------------------------------------------------

	public function test_jellyfin_audio_saves_artist_from_upstream_artist_field(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );

		$response = $this->server->dispatch( $this->jellyfin_request( 'jellyfin/playback-stop-audio.json' ) );

		$this->assertSame( 200, $response->get_status() );
		$data    = $response->get_data()['data'];
		$post_id = (int) $data['post_id'];
		$this->assertSame( 'created', $data['action'] );
		$this->assertSame( 'Fixture Artist', get_post_meta( $post_id, '_pkiw_listen_artist', true ) );
		$this->assertSame( 'Fixture Track', get_post_meta( $post_id, '_pkiw_listen_track', true ) );
		$this->assertSame( 'Fixture Album', get_post_meta( $post_id, '_pkiw_listen_album', true ) );
		$this->assertTrue( has_term( 'listen', 'kind', $post_id ) );
	}

	public function test_jellyfin_audio_falls_back_to_artists_array(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );
		$payload = json_decode( $this->fixture( 'jellyfin/playback-stop-audio.json' ), true );
		unset( $payload['Artist'] );
		$payload['Artists'] = [ 'Array Artist', 'Second Artist' ];

		$request = $this->jellyfin_request( 'jellyfin/playback-stop-audio.json' );
		$request->set_body( (string) wp_json_encode( $payload ) );
		$post_id = (int) $this->server->dispatch( $request )->get_data()['data']['post_id'];

		$this->assertSame( 'Array Artist', get_post_meta( $post_id, '_pkiw_listen_artist', true ) );
	}

	public function test_jellyfin_audio_prefers_artist_over_artists(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );
		$payload            = json_decode( $this->fixture( 'jellyfin/playback-stop-audio.json' ), true );
		$payload['Artists'] = [ 'Array Artist' ];

		$request = $this->jellyfin_request( 'jellyfin/playback-stop-audio.json' );
		$request->set_body( (string) wp_json_encode( $payload ) );
		$post_id = (int) $this->server->dispatch( $request )->get_data()['data']['post_id'];

		$this->assertSame( 'Fixture Artist', get_post_meta( $post_id, '_pkiw_listen_artist', true ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Render the admin page as an administrator and return the log table cells.
	 *
	 * @return array<int, array<int, string>>
	 */
	private function log_rows(): array {
		$xpath = $this->render_page();
		$rows  = [];

		foreach ( $xpath->query( '//h2[contains(., "Webhook Log")]/following-sibling::table[1]/tbody/tr' ) as $tr ) {
			$cells = [];
			foreach ( $xpath->query( './td', $tr ) as $td ) {
				$cells[] = trim( $td->textContent );
			}
			$rows[] = $cells;
		}

		return $rows;
	}

	/**
	 * Render the Webhooks page.
	 *
	 * @return DOMXPath
	 */
	private function render_page(): DOMXPath {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$page = new Webhooks_Page( new Admin( Plugin::get_instance() ) );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Build a Jellyfin Generic destination delivery.
	 *
	 * @param string $fixture Fixture path.
	 * @return WP_REST_Request
	 */
	private function jellyfin_request( string $fixture ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::NS . '/webhook/jellyfin' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Webhook-Token', self::JELLYFIN_TOKEN );
		$request->set_body( $this->fixture( $fixture ) );

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

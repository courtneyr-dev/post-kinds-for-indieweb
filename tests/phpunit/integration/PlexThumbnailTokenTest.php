<?php
/**
 * Plex artwork never stores the Plex server token (issue #213).
 *
 * Plex webhooks name artwork by a server path such as
 * /library/metadata/48213/thumb/1726240000. Fetching it needs the Plex
 * server token. The handler used to glue `?X-Plex-Token=<token>` onto that
 * path and store the result as the poster or cover URL, which put the token
 * in post meta, in the pending-scrobble queue, and in every rendered image.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\REST_API;
use PKIW\Webhook_Handler;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Plex thumbnail download, storage and the token-purge migration.
 */
final class PlexThumbnailTokenTest extends WP_UnitTestCase {

	private const NS           = '/post-kinds-indieweb/v1';
	private const WEBHOOK_KEY  = 'fake-plex-webhook-token-00000000';
	private const PLEX_TOKEN   = 'test-plex-token-123';
	private const PLEX_URL     = 'http://plex.fixture.test:32400';
	private const MOVIE_THUMB  = '/library/metadata/48213/thumb/1726240000';
	private const TRACK_THUMB  = '/library/metadata/90200/thumb/1726240100';
	private const TOKEN_MARKER = 'X-Plex-Token=';

	/**
	 * REST server with the plugin routes registered.
	 *
	 * @var WP_REST_Server
	 */
	private WP_REST_Server $server;

	/**
	 * Outbound requests: url and headers.
	 *
	 * @var array<int, array{url: string, headers: array<string, string>, redirection: mixed}>
	 */
	private array $requests = [];

	/**
	 * What the mocked Plex server answers: 'image', '404', 'wp_error' or 'html'.
	 *
	 * @var string
	 */
	private string $plex_answer = 'image';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );

		update_option( 'pkiw_webhook_token_plex', self::WEBHOOK_KEY );
		update_option( 'pkiw_plex_url', self::PLEX_URL );
		update_option( 'pkiw_plex_token', self::PLEX_TOKEN );
		delete_option( 'pkiw_pending_scrobbles' );
		delete_option( 'pkiw_webhook_auto_post' );
		delete_option( 'pkiw_webhook_log' );

		$this->requests    = [];
		$this->plex_answer = 'image';
		add_filter( 'pre_http_request', [ $this, 'mock_http' ], 1, 3 );

		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		$this->server = rest_get_server();
		remove_action( 'rest_api_init', [ $rest, 'register_routes' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'mock_http' ], 1 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Answer requests to the fixture Plex host; refuse everything else.
	 *
	 * @param false|array<string, mixed> $pre  Short-circuit value.
	 * @param array<string, mixed>       $args Request args.
	 * @param string                     $url  Request URL.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = [
			'url'         => $url,
			'headers'     => (array) ( $args['headers'] ?? [] ),
			'redirection' => $args['redirection'] ?? null,
		];

		if ( 'plex.fixture.test' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new \WP_Error( 'pkiw_test_http_blocked', 'Outbound HTTP is blocked in this test.' );
		}

		switch ( $this->plex_answer ) {
			case 'wp_error':
				return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to ' . $url );
			case '404':
				return $this->response( 404, 'text/plain', 'Not Found' );
			case 'html':
				return $this->response( 200, 'text/html', '<html><body>Unauthorized</body></html>' );
			default:
				return $this->response( 200, 'image/jpeg', (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) );
		}
	}

	// ------------------------------------------------------------------
	// Webhook time
	// ------------------------------------------------------------------

	public function test_movie_scrobble_downloads_poster_with_token_header_and_stores_local_url(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );

		$post_id = $this->dispatch_plex_and_get_post_id( 'plex/media-scrobble-movie.json' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( self::PLEX_URL . self::MOVIE_THUMB, $this->requests[0]['url'] );
		$this->assertSame( self::PLEX_TOKEN, $this->requests[0]['headers']['X-Plex-Token'] ?? null );
		$this->assertSame( 0, $this->requests[0]['redirection'] );

		$poster = (string) get_post_meta( $post_id, '_pkiw_watch_poster', true );
		$this->assertNotSame( '', $poster );
		$attachment_id = attachment_url_to_postid( $poster );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertStringStartsWith( 'image/', (string) get_post_mime_type( $attachment_id ) );

		$this->assert_token_stored_nowhere();
		$this->assertStringNotContainsString( self::PLEX_TOKEN, (string) get_post_field( 'post_content', $post_id ) );

		$html = $this->render_bound_artwork( $post_id );
		$this->assertStringContainsString( esc_url( $poster ), $html );
		$this->assertStringNotContainsString( self::PLEX_TOKEN, $html );
		$this->assertStringNotContainsString( self::TOKEN_MARKER, $html );
	}

	public function test_track_scrobble_stores_local_cover_without_token(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );

		$post_id = $this->dispatch_plex_and_get_post_id( 'plex/media-scrobble-track.json' );

		$this->assertSame( self::PLEX_URL . self::TRACK_THUMB, $this->requests[0]['url'] );
		$this->assertSame( self::PLEX_TOKEN, $this->requests[0]['headers']['X-Plex-Token'] ?? null );

		$cover = (string) get_post_meta( $post_id, '_pkiw_listen_cover', true );
		$this->assertGreaterThan( 0, attachment_url_to_postid( $cover ) );
		$this->assert_token_stored_nowhere();

		$html = $this->render_bound_artwork( $post_id );
		$this->assertStringContainsString( esc_url( $cover ), $html );
		$this->assertStringNotContainsString( self::PLEX_TOKEN, $html );
	}

	public function test_queued_scrobble_keeps_token_out_of_pending_queue(): void {
		$response = $this->server->dispatch( $this->plex_request( 'plex/media-scrobble-movie.json' ) );

		$this->assertSame( 'queued', $response->get_data()['data']['action'] );
		$pending = get_option( 'pkiw_pending_scrobbles' );
		$this->assertCount( 1, $pending );
		$this->assertGreaterThan( 0, attachment_url_to_postid( (string) $pending[0]['poster'] ) );
		$this->assert_token_stored_nowhere();
	}

	/**
	 * @dataProvider data_failed_downloads
	 *
	 * @param string $answer Mocked Plex answer.
	 */
	public function test_failed_download_stores_empty_poster( string $answer ): void {
		update_option( 'pkiw_webhook_auto_post', 1 );
		$this->plex_answer = $answer;

		$post_id = $this->dispatch_plex_and_get_post_id( 'plex/media-scrobble-movie.json' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_watch_poster', true ) );
		$this->assertSame( [], get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids' ] ) );
		$this->assert_token_stored_nowhere();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function data_failed_downloads(): array {
		return [
			'HTTP 404'           => [ '404' ],
			'transport error'    => [ 'wp_error' ],
			'non-image response' => [ 'html' ],
		];
	}

	public function test_failed_download_leaves_pending_poster_empty(): void {
		$this->plex_answer = '404';

		$this->server->dispatch( $this->plex_request( 'plex/media-scrobble-movie.json' ) );

		$pending = get_option( 'pkiw_pending_scrobbles' );
		$this->assertCount( 1, $pending );
		$this->assertEmpty( $pending[0]['poster'] ?? '' );
		$this->assert_token_stored_nowhere();
	}

	public function test_no_download_when_plex_server_is_not_configured(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );
		delete_option( 'pkiw_plex_token' );

		$post_id = $this->dispatch_plex_and_get_post_id( 'plex/media-scrobble-movie.json' );

		$this->assertSame( [], $this->requests );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_watch_poster', true ) );
	}

	/**
	 * A thumb path from the payload must not move the request, and the
	 * token header with it, to another host.
	 *
	 * @dataProvider data_hostile_thumb_paths
	 *
	 * @param string $thumb Thumb value in the payload.
	 */
	public function test_hostile_thumb_path_is_never_fetched( string $thumb ): void {
		update_option( 'pkiw_webhook_auto_post', 1 );
		$payload                      = json_decode( $this->fixture( 'plex/media-scrobble-movie.json' ), true );
		$payload['Metadata']['thumb'] = $thumb;

		$request = $this->plex_request( 'plex/media-scrobble-movie.json' );
		$request->set_body_params( [ 'payload' => wp_json_encode( $payload ) ] );
		$post_id = $this->server->dispatch( $request )->get_data()['data']['post_id'];

		$this->assertSame( [], $this->requests );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_watch_poster', true ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function data_hostile_thumb_paths(): array {
		return [
			'userinfo host swap'   => [ '@evil.fixture.test/steal' ],
			'protocol-relative'    => [ '//evil.fixture.test/steal' ],
			'absolute URL'         => [ 'https://evil.fixture.test/steal' ],
			'backslash host swap'  => [ '/\\evil.fixture.test/steal' ],
			'parent traversal'     => [ '/library/../../steal' ],
			'whitespace injection' => [ "/library/metadata/1/thumb/1\r\nHost: evil.fixture.test" ],
		];
	}

	public function test_rewatch_reuses_the_downloaded_attachment(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );

		$first  = $this->dispatch_plex_and_get_post_id( 'plex/media-scrobble-movie.json' );
		$second = $this->dispatch_plex_and_get_post_id( 'plex/media-scrobble-movie.json' );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( get_post_meta( $first, '_pkiw_watch_poster', true ), get_post_meta( $second, '_pkiw_watch_poster', true ) );
		$this->assertCount( 1, get_posts( [ 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids' ] ) );
	}

	public function test_failure_log_does_not_contain_token_or_url_query(): void {
		update_option( 'pkiw_webhook_auto_post', 1 );
		$this->plex_answer = 'wp_error';

		$this->dispatch_plex_and_get_post_id( 'plex/media-scrobble-movie.json' );

		$log = (string) wp_json_encode( get_option( 'pkiw_webhook_log', [] ) );
		$this->assertStringNotContainsString( self::PLEX_TOKEN, $log );
		$this->assertStringNotContainsString( self::TOKEN_MARKER, $log );
	}

	// ------------------------------------------------------------------
	// Migration
	// ------------------------------------------------------------------

	public function test_migration_removes_token_bearing_values_and_keeps_clean_ones(): void {
		$token_url = self::PLEX_URL . self::MOVIE_THUMB . '?' . self::TOKEN_MARKER . self::PLEX_TOKEN;
		$clean_url = 'https://image.tmdb.org/t/p/w500/clean.jpg';

		$dirty_watch  = self::factory()->post->create();
		$dirty_listen = self::factory()->post->create();
		$clean_watch  = self::factory()->post->create();
		$attachment   = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		update_post_meta( $dirty_watch, '_pkiw_watch_poster', $token_url );
		update_post_meta( $dirty_watch, '_pkiw_featured_artwork_source', $token_url );
		update_post_meta( $dirty_listen, '_pkiw_listen_cover', $token_url );
		update_post_meta( $attachment, '_source_url', $token_url );
		update_post_meta( $clean_watch, '_pkiw_watch_poster', $clean_url );
		update_post_meta( $clean_watch, '_pkiw_featured_artwork_source', $clean_url );
		update_post_meta( $dirty_watch, '_pkiw_watch_title', 'Inception' );
		update_option(
			'pkiw_pending_scrobbles',
			[
				[
					'source' => 'plex',
					'type'   => 'movie',
					'title'  => 'Inception',
					'poster' => $token_url,
				],
				[
					'source' => 'plex',
					'type'   => 'track',
					'track'  => 'Fixture Track',
					'cover'  => $clean_url,
				],
			],
			false
		);
		delete_option( 'pkiw_plex_token_purge_version' );

		$this->expectOutputString( '' );
		Webhook_Handler::maybe_purge_plex_tokens();

		$this->assertSame( '', get_post_meta( $dirty_watch, '_pkiw_watch_poster', true ) );
		$this->assertSame( '', get_post_meta( $dirty_watch, '_pkiw_featured_artwork_source', true ) );
		$this->assertSame( '', get_post_meta( $dirty_listen, '_pkiw_listen_cover', true ) );
		$this->assertSame( '', get_post_meta( $attachment, '_source_url', true ) );
		$this->assertSame( 'Inception', get_post_meta( $dirty_watch, '_pkiw_watch_title', true ) );
		$this->assertSame( $clean_url, get_post_meta( $clean_watch, '_pkiw_watch_poster', true ) );
		$this->assertSame( $clean_url, get_post_meta( $clean_watch, '_pkiw_featured_artwork_source', true ) );

		$pending = get_option( 'pkiw_pending_scrobbles' );
		$this->assertCount( 2, $pending );
		$this->assertSame( '', $pending[0]['poster'] );
		$this->assertSame( 'Inception', $pending[0]['title'] );
		$this->assertSame( $clean_url, $pending[1]['cover'] );

		$this->assert_token_stored_nowhere();
		$this->assertSame( PKIW_VERSION, get_option( 'pkiw_plex_token_purge_version' ) );
	}

	public function test_migration_is_idempotent(): void {
		$clean_url = 'https://image.tmdb.org/t/p/w500/clean.jpg';
		$post_id   = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_watch_poster', $clean_url );
		delete_option( 'pkiw_plex_token_purge_version' );

		Webhook_Handler::maybe_purge_plex_tokens();
		Webhook_Handler::maybe_purge_plex_tokens();
		delete_option( 'pkiw_plex_token_purge_version' );
		Webhook_Handler::maybe_purge_plex_tokens();

		$this->assertSame( $clean_url, get_post_meta( $post_id, '_pkiw_watch_poster', true ) );
		$this->assertSame( PKIW_VERSION, get_option( 'pkiw_plex_token_purge_version' ) );
	}

	public function test_migration_skips_work_when_already_stamped_for_this_version(): void {
		$token_url = self::PLEX_URL . self::MOVIE_THUMB . '?' . self::TOKEN_MARKER . self::PLEX_TOKEN;
		$post_id   = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_watch_poster', $token_url );
		update_option( 'pkiw_plex_token_purge_version', PKIW_VERSION );

		Webhook_Handler::maybe_purge_plex_tokens();

		$this->assertSame( $token_url, get_post_meta( $post_id, '_pkiw_watch_poster', true ) );
	}

	public function test_migration_runs_from_the_versioned_init_upgrade(): void {
		$this->assertNotFalse( has_action( 'init', [ Webhook_Handler::class, 'maybe_purge_plex_tokens' ] ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Fail when the Plex token appears in any post meta, post, or plugin option
	 * other than the setting that holds it.
	 */
	private function assert_token_stored_nowhere(): void {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( self::PLEX_TOKEN ) . '%';

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", $like ) ), 'post meta' );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s OR guid LIKE %s OR post_title LIKE %s", $like, $like, $like ) ), 'posts' );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name <> 'pkiw_plex_token' AND option_value LIKE %s", $like ) ), 'options' );
	}

	/**
	 * Dispatch a Plex scrobble and return the created post ID.
	 *
	 * @param string $fixture Fixture path.
	 * @return int
	 */
	private function dispatch_plex_and_get_post_id( string $fixture ): int {
		$response = $this->server->dispatch( $this->plex_request( $fixture ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'created', $data['data']['action'] );

		return (int) $data['data']['post_id'];
	}

	/**
	 * Render an image block whose URL is bound to the post's kind artwork
	 * through the post-kinds/kind-meta source (reads the poster or cover meta).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function render_bound_artwork( int $post_id ): string {
		$this->assertNotNull( get_block_bindings_source( 'post-kinds/kind-meta' ) );

		$markup = '<!-- wp:image {"metadata":{"bindings":{"url":{"source":"post-kinds/kind-meta","args":{"key":"cover_image"}}}}} -->'
			. '<figure class="wp-block-image"><img alt=""/></figure>'
			. '<!-- /wp:image -->';
		$block  = new \WP_Block(
			parse_blocks( $markup )[0],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		);

		return $block->render();
	}

	/**
	 * Build a mocked HTTP response.
	 *
	 * @param int    $code         Status code.
	 * @param string $content_type Content-Type.
	 * @param string $body         Body.
	 * @return array<string, mixed>
	 */
	private function response( int $code, string $content_type, string $body ): array {
		return [
			'headers'  => [ 'content-type' => $content_type ],
			'body'     => $body,
			'response' => [
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * Build a Plex delivery as WordPress sees it after PHP parses the multipart body.
	 *
	 * @param string $fixture Fixture path.
	 * @return WP_REST_Request
	 */
	private function plex_request( string $fixture ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', self::NS . '/webhook/plex' );
		$request->set_header( 'Content-Type', 'multipart/form-data; boundary=------------------------pkiwfixture0002' );
		$request->set_query_params( [ 'token' => self::WEBHOOK_KEY ] );
		$request->set_body_params( [ 'payload' => $this->fixture( $fixture ) ] );

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

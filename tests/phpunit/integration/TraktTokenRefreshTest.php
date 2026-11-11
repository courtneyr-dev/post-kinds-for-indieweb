<?php
/**
 * Trakt connections survive past the 7-day access token.
 *
 * Trakt access tokens last 7 days and refresh tokens are single-use: every
 * refresh returns a new pair and kills the old refresh token at once. Nothing
 * in PKIW refreshed, so every Trakt connection died a week after Connect.
 * These tests pin the refresher: it runs before token_expires, saves the new
 * pair in one write, never sends a client secret, never spends one refresh
 * token twice, and turns invalid_grant into a "Connect again" prompt.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Admin\Admin;
use PKIW\Admin\API_Settings;
use PKIW\Plugin;
use PKIW\Trakt_Token_Refresher;

/**
 * @group integration
 */
final class TraktTokenRefreshTest extends WP_UnitTestCase {

	private const CLIENT_ID = 'trakt-refresh-fixture-client-id-not-real';

	private const OLD_ACCESS  = 'trakt-refresh-fixture-old-access';
	private const OLD_REFRESH = 'trakt-refresh-fixture-old-refresh';
	private const NEW_ACCESS  = 'trakt-refresh-fixture-new-access';
	private const NEW_REFRESH = 'trakt-refresh-fixture-new-refresh';

	private Trakt_Token_Refresher $refresher;

	/** @var array<int, array<string, mixed>> Bodies of every token request sent. */
	private array $token_requests = [];

	/** @var array<string, mixed>|\WP_Error Response the stubbed token endpoint returns. */
	private $token_response = [
		'access_token'  => self::NEW_ACCESS,
		'refresh_token' => self::NEW_REFRESH,
		'expires_in'    => 604800,
		'token_type'    => 'bearer',
	];

	private int $token_status = 200;

	/** @var callable|null Runs inside the HTTP stub, while the refresh lock is held. */
	private $during_request = null;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->store_credentials( time() + DAY_IN_SECONDS );

		$this->refresher = new Trakt_Token_Refresher();

		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				if ( 'https://api.trakt.tv/oauth/token' !== $url ) {
					return $preempt;
				}
				$this->token_requests[] = json_decode( (string) $args['body'], true );
				if ( $this->during_request ) {
					( $this->during_request )();
				}
				if ( is_wp_error( $this->token_response ) ) {
					return $this->token_response;
				}
				return [
					'headers'  => [],
					'body'     => wp_json_encode( $this->token_response ),
					'response' => [ 'code' => $this->token_status, 'message' => '' ],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Trakt_Token_Refresher::CRON_HOOK );
		delete_option( Trakt_Token_Refresher::LOCK_OPTION );
		delete_option( Trakt_Token_Refresher::REAUTH_OPTION );
		delete_option( 'pkiw_api_credentials' );
		parent::tear_down();
	}

	/**
	 * Saves a connected Trakt account whose access token expires at $expires.
	 *
	 * @param int|null             $expires Expiry timestamp, or null to omit it.
	 * @param array<string, mixed> $extra   Extra Trakt fields.
	 */
	private function store_credentials( ?int $expires, array $extra = [] ): void {
		$trakt = array_merge(
			[
				'enabled'       => true,
				'client_id'     => self::CLIENT_ID,
				'access_token'  => self::OLD_ACCESS,
				'refresh_token' => self::OLD_REFRESH,
			],
			$extra
		);
		if ( null !== $expires ) {
			$trakt['token_expires'] = $expires;
		}
		update_option(
			'pkiw_api_credentials',
			[
				'trakt' => $trakt,
				'tmdb'  => [
					'enabled' => true,
					'api_key' => 'tmdb-fixture-key',
				],
			]
		);
	}

	/** @return array<string, mixed> */
	private function trakt(): array {
		return get_option( 'pkiw_api_credentials' )['trakt'];
	}

	public function test_a_token_inside_the_refresh_window_is_refreshed_and_the_new_pair_saved(): void {
		$this->assertSame( 'refreshed', $this->refresher->refresh() );

		$this->assertCount( 1, $this->token_requests );
		$trakt = $this->trakt();
		$this->assertSame( self::NEW_ACCESS, $trakt['access_token'] );
		$this->assertSame( self::NEW_REFRESH, $trakt['refresh_token'] );
		$this->assertEqualsWithDelta( time() + 604800, $trakt['token_expires'], 5 );
		$this->assertSame( self::CLIENT_ID, $trakt['client_id'] );
		$this->assertTrue( $trakt['enabled'] );
		$this->assertSame( 'tmdb-fixture-key', get_option( 'pkiw_api_credentials' )['tmdb']['api_key'], 'other services survive the write' );
	}

	public function test_refresh_request_is_a_public_client_refresh_grant_with_no_secret(): void {
		$this->store_credentials( time() + HOUR_IN_SECONDS, [ 'client_secret' => 'trakt-refresh-fixture-stale-secret' ] );

		$this->refresher->refresh();

		$settings = new API_Settings( new Admin( Plugin::get_instance() ) );
		$this->assertSame(
			[
				'client_id'     => self::CLIENT_ID,
				'grant_type'    => 'refresh_token',
				'redirect_uri'  => $settings->get_oauth_redirect_uri( 'trakt' ),
				'refresh_token' => self::OLD_REFRESH,
			],
			self::sorted( $this->token_requests[0] )
		);
	}

	public function test_a_token_outside_the_refresh_window_is_left_alone(): void {
		$this->store_credentials( time() + 5 * DAY_IN_SECONDS );

		$this->assertSame( 'not_due', $this->refresher->refresh() );

		$this->assertSame( [], $this->token_requests );
		$this->assertSame( self::OLD_REFRESH, $this->trakt()['refresh_token'] );
	}

	public function test_force_refreshes_a_token_outside_the_window(): void {
		$this->store_credentials( time() + 5 * DAY_IN_SECONDS );

		$this->assertSame( 'refreshed', $this->refresher->refresh( true ) );
		$this->assertSame( self::NEW_REFRESH, $this->trakt()['refresh_token'] );
	}

	public function test_an_unknown_expiry_counts_as_due(): void {
		$this->store_credentials( null );

		$this->assertSame( 'refreshed', $this->refresher->refresh() );
		$this->assertEqualsWithDelta( time() + 604800, $this->trakt()['token_expires'], 5 );
	}

	public function test_an_already_expired_token_is_still_refreshed(): void {
		$this->store_credentials( time() - DAY_IN_SECONDS );

		$this->assertSame( 'refreshed', $this->refresher->refresh() );
		$this->assertSame( self::NEW_ACCESS, $this->trakt()['access_token'] );
	}

	public function test_nothing_is_sent_without_a_refresh_token_or_client_id(): void {
		$this->store_credentials( time(), [ 'refresh_token' => '' ] );
		$this->assertSame( 'not_connected', $this->refresher->refresh( true ) );

		$this->store_credentials( time(), [ 'client_id' => '' ] );
		$this->assertSame( 'not_connected', $this->refresher->refresh( true ) );

		$this->assertSame( [], $this->token_requests );
	}

	public function test_each_refresh_spends_the_newest_refresh_token(): void {
		$this->refresher->refresh( true );
		$this->token_response['refresh_token'] = 'trakt-refresh-fixture-third-refresh';
		$this->refresher->refresh( true );

		$this->assertSame( self::OLD_REFRESH, $this->token_requests[0]['refresh_token'] );
		$this->assertSame( self::NEW_REFRESH, $this->token_requests[1]['refresh_token'] );
		$this->assertSame( 'trakt-refresh-fixture-third-refresh', $this->trakt()['refresh_token'] );
	}

	public function test_a_concurrent_refresh_is_locked_out_and_sends_nothing(): void {
		$inner                = null;
		$this->during_request = function () use ( &$inner ): void {
			$this->during_request = null;
			$inner                = ( new Trakt_Token_Refresher() )->refresh( true );
		};

		$this->assertSame( 'refreshed', $this->refresher->refresh() );

		$this->assertSame( 'locked', $inner );
		$this->assertCount( 1, $this->token_requests, 'the old refresh token is spent once' );
		$this->assertFalse( get_option( Trakt_Token_Refresher::LOCK_OPTION ), 'lock released after the refresh' );
	}

	public function test_a_held_lock_blocks_refresh(): void {
		add_option( Trakt_Token_Refresher::LOCK_OPTION, (string) time(), '', false );

		$this->assertSame( 'locked', $this->refresher->refresh( true ) );
		$this->assertSame( [], $this->token_requests );
	}

	public function test_a_stale_lock_from_a_crashed_run_is_taken_over(): void {
		add_option( Trakt_Token_Refresher::LOCK_OPTION, (string) ( time() - 10 * MINUTE_IN_SECONDS ), '', false );

		$this->assertSame( 'refreshed', $this->refresher->refresh() );
		$this->assertFalse( get_option( Trakt_Token_Refresher::LOCK_OPTION ) );
	}

	public function test_invalid_grant_disconnects_and_asks_for_connect_again(): void {
		$this->token_status   = 400;
		$this->token_response = [
			'error'             => 'invalid_grant',
			'error_description' => 'session not found',
		];

		$this->assertSame( 'reauth_required', $this->refresher->refresh() );

		$trakt = $this->trakt();
		$this->assertSame( '', $trakt['access_token'] );
		$this->assertSame( '', $trakt['refresh_token'] );
		$this->assertArrayNotHasKey( 'token_expires', $trakt );
		$this->assertSame( self::CLIENT_ID, $trakt['client_id'], 'the app registration is kept' );
		$this->assertSame( 'session not found', Trakt_Token_Refresher::get_reauth_reason() );
	}

	public function test_api_connections_shows_the_reconnect_prompt_after_invalid_grant(): void {
		$this->token_status   = 400;
		$this->token_response = [
			'error'             => 'invalid_grant',
			'error_description' => '<b>session not found</b>',
		];
		$this->refresher->refresh();

		$html = $this->render_trakt_oauth_section();

		$this->assertStringContainsString( 'oauth-connect', $html, 'Connect button is offered' );
		$this->assertStringContainsString( 'Trakt ended this connection', $html );
		$this->assertStringContainsString( 'session not found', $html );
		$this->assertStringNotContainsString( '<b>', $html, 'Trakt text is escaped' );
	}

	public function test_connecting_again_clears_the_reconnect_prompt(): void {
		update_option( Trakt_Token_Refresher::REAUTH_OPTION, 'session not found', false );
		$this->store_credentials( null, [ 'access_token' => '', 'refresh_token' => '' ] );

		$settings = new API_Settings( new Admin( Plugin::get_instance() ) );
		$settings->init();
		$url = (string) $settings->get_oauth_url( 'trakt' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new RuntimeException( $location );
			}
		);
		$_GET['state'] = $params['state'];
		$_GET['code']  = 'trakt-refresh-fixture-code';
		try {
			do_action( 'admin_post' );
		} catch ( RuntimeException $redirect ) {
			$this->assertStringContainsString( 'oauth_success=1', $redirect->getMessage() );
		} finally {
			unset( $_GET['state'], $_GET['code'] );
		}

		$this->assertNull( Trakt_Token_Refresher::get_reauth_reason() );
		$this->assertNotFalse( wp_next_scheduled( Trakt_Token_Refresher::CRON_HOOK ), 'refresh is scheduled on Connect' );
	}

	public function test_network_failure_keeps_the_tokens_for_the_next_run(): void {
		$this->token_response = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$this->assertSame( 'failed', $this->refresher->refresh() );

		$this->assertSame( self::OLD_REFRESH, $this->trakt()['refresh_token'] );
		$this->assertNull( Trakt_Token_Refresher::get_reauth_reason() );
		$this->assertFalse( get_option( Trakt_Token_Refresher::LOCK_OPTION ) );
	}

	public function test_server_error_keeps_the_tokens_for_the_next_run(): void {
		$this->token_status   = 503;
		$this->token_response = [ 'error' => 'temporarily_unavailable' ];

		$this->assertSame( 'failed', $this->refresher->refresh() );

		$this->assertSame( self::OLD_ACCESS, $this->trakt()['access_token'] );
		$this->assertSame( self::OLD_REFRESH, $this->trakt()['refresh_token'] );
		$this->assertNull( Trakt_Token_Refresher::get_reauth_reason() );
	}

	public function test_the_cron_hook_runs_the_refresh(): void {
		( new Trakt_Token_Refresher() )->init();

		do_action( Trakt_Token_Refresher::CRON_HOOK );

		$this->assertSame( self::NEW_REFRESH, $this->trakt()['refresh_token'] );
	}

	public function test_refresh_is_scheduled_only_while_connected(): void {
		$this->refresher->ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( Trakt_Token_Refresher::CRON_HOOK ) );

		$this->store_credentials( null, [ 'access_token' => '', 'refresh_token' => '' ] );
		$this->refresher->ensure_scheduled();
		$this->assertFalse( wp_next_scheduled( Trakt_Token_Refresher::CRON_HOOK ) );
	}

	public function test_the_trakt_api_class_refresh_uses_the_refresher(): void {
		$this->store_credentials( time() + 5 * DAY_IN_SECONDS, [ 'client_secret' => 'trakt-refresh-fixture-stale-secret' ] );

		$tokens = ( new \PKIW\APIs\Trakt() )->refresh_access_token();

		$this->assertSame( self::NEW_ACCESS, $tokens['access_token'] ?? null );
		$this->assertSame( self::NEW_REFRESH, $this->trakt()['refresh_token'], 'saved, not just returned' );
		$this->assertArrayNotHasKey( 'client_secret', $this->token_requests[0] );
	}

	public function test_saving_api_connections_keeps_a_token_refreshed_after_the_page_loaded(): void {
		// The page renders while the old pair is current...
		$html = $this->render_trakt_oauth_section();
		$this->assertStringNotContainsString( self::OLD_ACCESS, $html, 'tokens are not printed into the page' );
		$this->assertStringNotContainsString( self::OLD_REFRESH, $html );
		$posted = self::hidden_token_fields( $html );

		// ...cron refreshes before the admin clicks Save...
		$this->refresher->refresh( true );

		// ...and the form posts back what the page rendered.
		$admin     = new Admin( Plugin::get_instance() );
		$sanitized = $admin->sanitize_api_credentials(
			[
				'trakt' => array_merge(
					[
						'enabled'   => '1',
						'client_id' => self::CLIENT_ID,
					],
					$posted
				),
			]
		);

		$this->assertSame( self::NEW_ACCESS, $sanitized['trakt']['access_token'] );
		$this->assertSame( self::NEW_REFRESH, $sanitized['trakt']['refresh_token'] );
		$this->assertEqualsWithDelta( time() + 604800, $sanitized['trakt']['token_expires'], 5 );
	}

	public function test_disconnect_still_clears_the_tokens(): void {
		$admin     = new Admin( Plugin::get_instance() );
		$sanitized = $admin->sanitize_api_credentials(
			[
				'trakt' => [
					'enabled'       => '1',
					'client_id'     => self::CLIENT_ID,
					'access_token'  => '',
					'refresh_token' => '',
					'token_expires' => '',
				],
			]
		);

		$this->assertSame( '', $sanitized['trakt']['access_token'] );
		$this->assertSame( '', $sanitized['trakt']['refresh_token'] );
		$this->assertSame( 0, $sanitized['trakt']['token_expires'] );
	}

	private function render_trakt_oauth_section(): string {
		$settings = new API_Settings( new Admin( Plugin::get_instance() ) );
		$configs  = ( new ReflectionMethod( API_Settings::class, 'get_api_configs' ) )->invoke( $settings );
		$render   = new ReflectionMethod( API_Settings::class, 'render_oauth_section' );

		ob_start();
		$render->invoke( $settings, 'trakt', $configs['trakt'], $this->trakt() );
		return (string) ob_get_clean();
	}

	/**
	 * Values the form would post for the hidden token fields.
	 *
	 * @return array<string, string>
	 */
	private static function hidden_token_fields( string $html ): array {
		preg_match_all( '/name="pkiw_api_credentials\[trakt\]\[(access_token|refresh_token|token_expires)\]"\s+value="([^"]*)"/', $html, $matches, PREG_SET_ORDER );
		$fields = [];
		foreach ( $matches as $match ) {
			$fields[ $match[1] ] = html_entity_decode( $match[2] );
		}
		self::assertArrayHasKey( 'access_token', $fields );
		self::assertArrayHasKey( 'refresh_token', $fields );
		self::assertArrayHasKey( 'token_expires', $fields );
		return $fields;
	}

	/**
	 * @param array<string, mixed> $value
	 * @return array<string, mixed>
	 */
	private static function sorted( array $value ): array {
		ksort( $value );
		return $value;
	}
}

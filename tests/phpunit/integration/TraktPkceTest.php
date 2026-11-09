<?php
/**
 * Trakt sign-in uses PKCE.
 *
 * Trakt's developer portal now creates apps that sign users in with PKCE and
 * issues no client secret ("Not issued"), so the old flow — which required a
 * Client Secret before Connect and sent it to /oauth/token — could not
 * connect. PKIW now sends an S256 code challenge to auth.trakt.tv and the
 * matching code verifier, without a client secret, to the token endpoint.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Admin\Admin;
use PKIW\Admin\API_Settings;
use PKIW\Plugin;

/**
 * @group integration
 */
final class TraktPkceTest extends WP_UnitTestCase {

	private const CLIENT_ID = 'trakt-pkce-fixture-client-id-not-real';

	private API_Settings $settings;

	/** @var array<string, mixed>|null Body of the captured token request. */
	private ?array $token_request = null;

	/** @var array<string, mixed> Response body the stubbed token endpoint returns. */
	private array $token_response = [
		'access_token'  => 'trakt-pkce-fixture-access-token',
		'refresh_token' => 'trakt-pkce-fixture-refresh-token',
		'expires_in'    => 604800,
	];

	private int $token_status = 200;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		update_option(
			'pkiw_api_credentials',
			[
				'trakt' => [
					'enabled'   => true,
					'client_id' => self::CLIENT_ID,
				],
			]
		);

		$this->settings = new API_Settings( new Admin( Plugin::get_instance() ) );
		$this->settings->init();

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new RuntimeException( $location );
			}
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				if ( 'https://api.trakt.tv/oauth/token' !== $url ) {
					return $preempt;
				}
				$this->token_request = json_decode( (string) $args['body'], true );
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
		unset( $_GET['state'], $_GET['code'], $_GET['error'] );
		delete_transient( 'pkiw_admin_oauth_state_trakt' );
		delete_transient( 'pkiw_oauth_error' );
		delete_transient( 'pkiw_oauth_success' );
		delete_option( 'pkiw_api_credentials' );
		parent::tear_down();
	}

	/**
	 * Starts a sign-in and returns the authorize URL's query parameters.
	 *
	 * @return array<string, string>
	 */
	private function start_sign_in(): array {
		$url = (string) $this->settings->get_oauth_url( 'trakt' );
		$this->assertStringStartsWith( 'https://auth.trakt.tv/oauth/authorize?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		return $params;
	}

	/** Simulates Trakt redirecting back to bare admin-post.php. */
	private function return_from_trakt( string $state, string $code = 'trakt-pkce-fixture-code' ): ?string {
		$_GET['state'] = $state;
		$_GET['code']  = $code;
		try {
			do_action( 'admin_post' );
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		return null;
	}

	private static function s256( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	public function test_authorize_url_carries_an_s256_challenge(): void {
		$params = $this->start_sign_in();

		$this->assertSame( 'S256', $params['code_challenge_method'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $params['code_challenge'], 'base64url SHA-256, no padding' );
		$this->assertSame( 'code', $params['response_type'] );
		$this->assertSame( self::CLIENT_ID, $params['client_id'] );
		$this->assertSame( admin_url( 'admin-post.php' ), $params['redirect_uri'] );
	}

	public function test_each_sign_in_gets_a_new_challenge(): void {
		$first  = $this->start_sign_in();
		$second = $this->start_sign_in();

		$this->assertNotSame( $first['code_challenge'], $second['code_challenge'] );
	}

	public function test_token_exchange_sends_the_matching_verifier_and_no_secret(): void {
		$params   = $this->start_sign_in();
		$location = $this->return_from_trakt( $params['state'] );

		$this->assertSame( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_success=1' ), $location );
		$verifier = $this->token_request['code_verifier'] ?? '';
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier );
		$this->assertSame( $params['code_challenge'], self::s256( $verifier ) );
		$this->assertArrayNotHasKey( 'client_secret', $this->token_request );
		$this->assertSame( 'authorization_code', $this->token_request['grant_type'] );
		$this->assertSame( self::CLIENT_ID, $this->token_request['client_id'] );
		$this->assertSame( admin_url( 'admin-post.php' ), $this->token_request['redirect_uri'] );

		$credentials = get_option( 'pkiw_api_credentials' )['trakt'];
		$this->assertSame( 'trakt-pkce-fixture-access-token', $credentials['access_token'] );
		$this->assertSame( 'trakt-pkce-fixture-refresh-token', $credentials['refresh_token'] );
		$this->assertEqualsWithDelta( time() + 604800, $credentials['token_expires'], 5 );
	}

	public function test_a_saved_secret_from_an_older_app_is_not_sent(): void {
		$credentials                           = get_option( 'pkiw_api_credentials' );
		$credentials['trakt']['client_secret'] = 'trakt-pkce-fixture-stale-secret';
		update_option( 'pkiw_api_credentials', $credentials );

		$params = $this->start_sign_in();
		$this->return_from_trakt( $params['state'] );

		$this->assertIsArray( $this->token_request );
		$this->assertArrayNotHasKey( 'client_secret', $this->token_request );
	}

	public function test_verifier_is_single_use(): void {
		$params = $this->start_sign_in();
		$this->return_from_trakt( $params['state'] );
		$this->token_request = null;

		// Replaying the same callback: state is gone, so the router ignores it.
		$this->assertNull( $this->return_from_trakt( $params['state'] ) );
		$this->assertNull( $this->token_request );
	}

	public function test_missing_verifier_fails_without_calling_trakt(): void {
		$params = $this->start_sign_in();
		$this->assertNotFalse( get_transient( 'pkiw_admin_oauth_pkce_trakt' ) );
		delete_transient( 'pkiw_admin_oauth_pkce_trakt' );

		$location = $this->return_from_trakt( $params['state'] );

		$this->assertSame( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ), $location );
		$this->assertNull( $this->token_request );
		$this->assertIsString( get_transient( 'pkiw_oauth_error' ) );
		$this->assertArrayNotHasKey( 'access_token', get_option( 'pkiw_api_credentials' )['trakt'] );
	}

	public function test_trakt_error_description_reaches_the_settings_notice(): void {
		$this->token_status   = 400;
		$this->token_response = [
			'error'             => 'invalid_grant',
			'error_description' => 'code verifier mismatch',
		];

		$params   = $this->start_sign_in();
		$location = $this->return_from_trakt( $params['state'] );

		$this->assertSame( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ), $location );
		$this->assertStringContainsString( 'code verifier mismatch', (string) get_transient( 'pkiw_oauth_error' ) );
		$this->assertArrayNotHasKey( 'access_token', get_option( 'pkiw_api_credentials' )['trakt'] );
	}

	public function test_client_secret_is_optional_for_trakt(): void {
		$configs = ( new ReflectionMethod( API_Settings::class, 'get_api_configs' ) )->invoke( $this->settings );

		$this->assertEmpty( $configs['trakt']['fields']['client_secret']['required'] ?? false );
		$this->assertSame( 'https://auth.trakt.tv/oauth/authorize', $configs['trakt']['oauth_url'] );
	}
}

<?php
/**
 * Trakt OAuth uses a redirect URI without a query string.
 *
 * Trakt's developer portal rejects redirect URIs that contain a query or
 * fragment, so `admin-post.php?action=pkiw_trakt_oauth` can't be registered
 * and the Trakt connection could never be made. Trakt now redirects to the
 * bare `admin-post.php`, which fires the generic `admin_post` hook; PKIW
 * routes that request by the single-use OAuth state it issued.
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
final class TraktQueryFreeRedirectTest extends WP_UnitTestCase {

	private const CLIENT_ID     = 'trakt-redirect-fixture-client-id-not-real';
	private const CLIENT_SECRET = 'trakt-redirect-fixture-client-secret-not-real';
	private const STATE         = 'trakt-redirect-fixture-state-0123456789ab';

	private API_Settings $settings;

	/** @var array<string, mixed>|null Body of the captured token request. */
	private ?array $token_request = null;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		update_option(
			'pkiw_api_credentials',
			[
				'trakt' => [
					'enabled'       => true,
					'client_id'     => self::CLIENT_ID,
					'client_secret' => self::CLIENT_SECRET,
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
	 * Runs the bare admin-post.php hook and returns the redirect target,
	 * or null when nothing redirected.
	 */
	private function run_admin_post(): ?string {
		try {
			do_action( 'admin_post' );
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		return null;
	}

	private function stub_trakt_token_endpoint(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				if ( 'https://api.trakt.tv/oauth/token' !== $url ) {
					return $preempt;
				}
				$this->token_request = json_decode( (string) $args['body'], true );
				return [
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'access_token'  => 'trakt-fixture-access-token',
							'refresh_token' => 'trakt-fixture-refresh-token',
							'expires_in'    => 7776000,
						]
					),
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);
	}

	public function test_trakt_redirect_uri_has_no_query_or_fragment(): void {
		$uri = $this->settings->get_oauth_redirect_uri( 'trakt' );

		$this->assertSame( admin_url( 'admin-post.php' ), $uri );
		$this->assertNull( wp_parse_url( $uri, PHP_URL_QUERY ) );
		$this->assertNull( wp_parse_url( $uri, PHP_URL_FRAGMENT ) );
	}

	public function test_other_providers_keep_their_registered_redirect_uri(): void {
		$this->assertSame(
			admin_url( 'admin-post.php?action=pkiw_foursquare_oauth' ),
			$this->settings->get_oauth_redirect_uri( 'foursquare' )
		);
		$this->assertSame(
			admin_url( 'admin-post.php?action=pkiw_simkl_oauth' ),
			$this->settings->get_oauth_redirect_uri( 'simkl' )
		);
	}

	public function test_authorize_url_sends_query_free_redirect_uri_and_issued_state(): void {
		$url = (string) $this->settings->get_oauth_url( 'trakt' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertStringStartsWith( 'https://auth.trakt.tv/oauth/authorize?', $url );
		$this->assertSame( admin_url( 'admin-post.php' ), $params['redirect_uri'] );
		$this->assertSame( get_transient( 'pkiw_admin_oauth_state_trakt' ), $params['state'] );
	}

	public function test_init_registers_the_bare_admin_post_router(): void {
		$this->assertNotFalse(
			has_action( 'admin_post', [ $this->settings, 'route_query_free_oauth_callback' ] )
		);
	}

	public function test_matching_state_exchanges_code_and_stores_tokens(): void {
		$this->stub_trakt_token_endpoint();
		parse_str( (string) wp_parse_url( (string) $this->settings->get_oauth_url( 'trakt' ), PHP_URL_QUERY ), $params );
		$_GET['state'] = $params['state'];
		$_GET['code']  = 'trakt-fixture-auth-code';

		$location = $this->run_admin_post();

		$this->assertSame( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_success=1' ), $location );
		$this->assertSame( admin_url( 'admin-post.php' ), $this->token_request['redirect_uri'] ?? null );
		$this->assertSame( 'trakt-fixture-auth-code', $this->token_request['code'] ?? null );
		$credentials = get_option( 'pkiw_api_credentials' );
		$this->assertSame( 'trakt-fixture-access-token', $credentials['trakt']['access_token'] );
		$this->assertSame( 'trakt-fixture-refresh-token', $credentials['trakt']['refresh_token'] );
		$this->assertFalse( get_transient( 'pkiw_admin_oauth_state_trakt' ), 'State is single-use.' );
	}

	public function test_provider_error_with_matching_state_reports_the_error(): void {
		set_transient( 'pkiw_admin_oauth_state_trakt', self::STATE, HOUR_IN_SECONDS );
		$_GET['state'] = self::STATE;
		$_GET['error'] = 'access_denied';

		$location = $this->run_admin_post();

		$this->assertSame( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ), $location );
		$this->assertSame( 'access_denied', get_transient( 'pkiw_oauth_error' ) );
		$this->assertArrayNotHasKey( 'access_token', get_option( 'pkiw_api_credentials' )['trakt'] );
	}

	public function test_unmatched_state_leaves_the_request_alone(): void {
		$this->stub_trakt_token_endpoint();
		set_transient( 'pkiw_admin_oauth_state_trakt', self::STATE, HOUR_IN_SECONDS );
		$_GET['state'] = 'some-other-plugins-state';
		$_GET['code']  = 'trakt-fixture-auth-code';

		$this->assertNull( $this->run_admin_post() );
		$this->assertNull( $this->token_request, 'No token request for a state PKIW did not issue.' );
		$this->assertSame( self::STATE, get_transient( 'pkiw_admin_oauth_state_trakt' ), 'Pending state survives.' );
	}

	public function test_request_without_state_leaves_the_request_alone(): void {
		set_transient( 'pkiw_admin_oauth_state_trakt', self::STATE, HOUR_IN_SECONDS );
		$_GET['code'] = 'trakt-fixture-auth-code';

		$this->assertNull( $this->run_admin_post() );
		$this->assertSame( self::STATE, get_transient( 'pkiw_admin_oauth_state_trakt' ) );
	}

	public function test_non_string_state_is_ignored_without_fatal(): void {
		set_transient( 'pkiw_admin_oauth_state_trakt', self::STATE, HOUR_IN_SECONDS );
		$_GET['state'] = [ self::STATE ];

		$this->assertNull( $this->run_admin_post() );
		$this->assertSame( self::STATE, get_transient( 'pkiw_admin_oauth_state_trakt' ) );
	}
}

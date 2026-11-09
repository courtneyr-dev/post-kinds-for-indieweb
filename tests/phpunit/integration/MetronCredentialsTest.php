<?php
/**
 * Metron token privacy coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Admin\Admin;
use PKIW\Admin\API_Settings;
use PKIW\APIs\API_Base;
use PKIW\APIs\Metron;
use PKIW\Plugin;
use PKIW\REST_API;

/**
 * @group integration
 */
final class MetronCredentialsTest extends WP_UnitTestCase {

	private const TOKEN = 'metron-credentials-fixture-token-not-real';

	/** @var array<int, array<string, mixed>> */
	private array $requests = [];

	/** @var array<int, array<string, mixed>> */
	private array $logs = [];

	private bool $fail_requests = false;

	public function set_up(): void {
		parent::set_up();
		$this->requests      = [];
		$this->logs          = [];
		$this->fail_requests = false;
		update_option(
			'pkiw_api_credentials',
			[
				'metron' => [ 'enabled' => true, 'api_token' => self::TOKEN ],
				'lastfm' => [ 'enabled' => true, 'api_secret' => 'lastfm-fixture-secret-not-real' ],
			]
		);
		add_filter( 'pre_http_request', [ $this, 'http_response' ], 10, 3 );
		add_action( 'pkiw_api_error', [ $this, 'record_log' ], 10, 3 );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param mixed                $preempt Existing preempt value.
	 * @param array<string, mixed> $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return array<string, mixed>
	 */
	public function http_response( $preempt, array $args, string $url ): array {
		unset( $preempt );
		$this->requests[] = [ 'url' => $url, 'headers' => $args['headers'] ?? [] ];

		if ( $this->fail_requests ) {
			return [
				'response' => [ 'code' => 503, 'message' => 'Unavailable' ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => '{"detail":"fixture failure"}',
				'cookies'  => [],
			];
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => (string) file_get_contents( __DIR__ . '/../fixtures/metron/issue-list.json' ),
			'cookies'  => [],
		];
	}

	/** @param array<string, mixed> $context */
	public function record_log( string $api, string $message, array $context ): void {
		$this->logs[] = [ 'api' => $api, 'message' => $message, 'context' => $context ];
	}

	private function settings(): API_Settings {
		return new API_Settings( new Admin( Plugin::get_instance() ) );
	}

	public function test_settings_page_masks_saved_token_and_registers_metron(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		$this->settings()->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Metron', $html );
		$this->assertStringContainsString( 'value="************"', $html );
		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringNotContainsString( 'lastfm-fixture-secret-not-real', $html );
	}

	public function test_submitting_the_mask_keeps_the_saved_token(): void {
		$admin     = new Admin( Plugin::get_instance() );
		$sanitized = $admin->sanitize_api_credentials(
			[ 'metron' => [ 'enabled' => '1', 'api_token' => '************' ] ]
		);

		$this->assertSame( self::TOKEN, $sanitized['metron']['api_token'] ?? null );
	}

	public function test_token_is_only_sent_in_the_authorization_header(): void {
		$results = ( new Metron() )->search( 'Privacy Fixture' );
		$this->assertCount( 2, $results );
		$this->assertSame( 'Bearer ' . self::TOKEN, $this->requests[0]['headers']['Authorization'] ?? null );

		$urls = array_column( $this->requests, 'url' );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $urls ) );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $results ) );
	}

	public function test_rest_settings_lookup_and_ability_outputs_do_not_expose_token(): void {
		$administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $administrator );

		$settings_response = rest_do_request( new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/settings/apis' ) );
		$this->assertSame( 200, $settings_response->get_status() );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $settings_response->get_data() ) );

		$lookup = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/comic' );
		$lookup->set_param( 'q', 'Privacy REST Fixture' );
		$response = rest_do_request( $lookup );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $response->get_data() ) );

		if ( function_exists( 'wp_get_ability' ) ) {
			( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );
			$ability = wp_get_ability( 'post-kinds/lookup-comic' );
			$this->assertNotNull( $ability );
			$result = $ability->execute( [ 'query' => 'Privacy Ability Fixture' ] );
			$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $result ) );
		}
	}

	public function test_failures_do_not_put_token_in_errors_logs_or_urls(): void {
		$this->fail_requests = true;

		try {
			( new Metron() )->search( 'Failure Privacy Fixture' );
			$this->fail( 'Expected the Metron request to fail.' );
		} catch ( Exception $error ) {
			$this->assertStringNotContainsString( self::TOKEN, $error->getMessage() );
		}

		$this->assertNotEmpty( $this->logs );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $this->logs ) );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( array_column( $this->requests, 'url' ) ) );
	}
}

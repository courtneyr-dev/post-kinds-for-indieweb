<?php
/**
 * Comic lookup REST and Abilities API coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities_Manager;
use PKIW\APIs\API_Base;
use PKIW\REST_API;

/**
 * @group integration
 */
final class LookupComicAbilityTest extends WP_UnitTestCase {

	private const TOKEN = 'metron-lookup-fixture-token-not-real';

	/** @var string[] */
	private array $urls = [];

	private bool $fail_requests = false;

	public function set_up(): void {
		parent::set_up();
		$this->urls          = [];
		$this->fail_requests = false;
		update_option( 'pkiw_api_credentials', [ 'metron' => [ 'enabled' => true, 'api_token' => self::TOKEN ] ] );
		add_filter( 'pre_http_request', [ $this, 'http_response' ], 10, 3 );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param mixed                $preempt Existing preempt value.
	 * @param array<string, mixed> $args    HTTP arguments.
	 * @param string               $url     Request URL.
	 * @return array<string, mixed>
	 */
	public function http_response( $preempt, array $args, string $url ): array {
		unset( $preempt, $args );
		$this->urls[] = $url;
		if ( $this->fail_requests ) {
			return [
				'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => '{"detail":"private upstream body"}',
				'cookies'  => [],
			];
		}

		$fixture = str_contains( $url, '/issue/101/' ) ? 'issue-detail.json' : 'issue-list.json';
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => (string) file_get_contents( __DIR__ . '/../fixtures/metron/' . $fixture ),
			'cookies'  => [],
		];
	}

	private function request( array $params = [], int $user_id = 0 ): WP_REST_Response {
		wp_set_current_user( $user_id );
		( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );
		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . '/lookup/comic' );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	public function test_ability_is_registered_public_and_has_the_amended_schema(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available.' );
		}

		$ability = wp_get_ability( 'post-kinds/lookup-comic' );
		$this->assertNotNull( $ability );
		$this->assertContains( 'post-kinds/lookup-comic', Abilities_Manager::MCP_PUBLIC_ABILITIES );
		$this->assertTrue( $ability->get_meta()['mcp']['public'] ?? false );
		$this->assertTrue( $ability->get_meta()['show_in_rest'] ?? false );
		$this->assertSame( [ 'query', 'number', 'upc', 'id' ], array_keys( $ability->get_input_schema()['properties'] ?? [] ) );
		$this->assertArrayNotHasKey( 'required', $ability->get_input_schema() );
		$this->assertStringNotContainsString( 'ISBN', $ability->get_description() );
	}

	public function test_route_requires_edit_posts_for_anonymous_and_subscriber(): void {
		$anonymous = $this->request( [ 'q' => 'Sentinel' ] );
		$this->assertSame( 401, $anonymous->get_status() );
		$this->assertSame( 'rest_forbidden', $anonymous->get_data()['code'] ?? null );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$response   = $this->request( [ 'q' => 'Sentinel' ], $subscriber );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] ?? null );
		$this->assertSame( [], $this->urls );
	}

	public function test_rest_precedence_is_id_then_upc_then_query_with_number(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );

		$response = $this->request( [ 'id' => '101', 'upc' => '75960601234500111', 'q' => 'Ignored', 'number' => '1' ], $editor );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '/issue/101/', end( $this->urls ) );

		$response = $this->request( [ 'upc' => '75960601234500111', 'q' => 'Ignored', 'number' => '1' ], $editor );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'issue/?upc=75960601234500111', end( $this->urls ) );

		$response = $this->request( [ 'q' => 'Sentinel Comics', 'number' => '7' ], $editor );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'series_name=Sentinel%20Comics&number=7', end( $this->urls ) );
	}

	public function test_missing_query_and_missing_configuration_fail_before_request(): void {
		$editor   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$response = $this->request( [], $editor );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pkiw_metron_missing_query', $response->get_data()['code'] ?? null );

		update_option( 'pkiw_api_credentials', [] );
		$response = $this->request( [ 'q' => 'Sentinel' ], $editor );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pkiw_metron_not_configured', $response->get_data()['code'] ?? null );
		$this->assertStringContainsString( 'Settings → APIs', $response->get_data()['message'] ?? '' );
		$this->assertSame( [], $this->urls );
	}

	public function test_api_failure_returns_only_the_generic_error(): void {
		$editor              = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->fail_requests = true;
		$response            = $this->request( [ 'q' => 'Sentinel' ], $editor );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'lookup_failed', $response->get_data()['code'] ?? null );
		$this->assertSame( 'Comic lookup failed.', $response->get_data()['message'] ?? null );
		$this->assertStringNotContainsString( 'private upstream body', wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( self::TOKEN, wp_json_encode( $response->get_data() ) );
	}

	public function test_ability_executes_the_rest_lookup_for_an_editor(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available.' );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$ability = wp_get_ability( 'post-kinds/lookup-comic' );
		$this->assertNotNull( $ability );
		$result = $ability->execute( [ 'query' => 'Sentinel Comics', 'number' => '7' ] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['results'] ?? [] );
		$this->assertSame( '101', $result['results'][0]['metronId'] ?? null );
	}
}

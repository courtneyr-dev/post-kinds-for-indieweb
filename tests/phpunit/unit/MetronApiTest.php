<?php
/**
 * Metron API client coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use PKIW\APIs\API_Base;
use PKIW\APIs\Metron;
use PKIW\Tests\ApiTestCase;

/**
 * @covers \PKIW\APIs\Metron
 */
final class MetronApiTest extends ApiTestCase {

	private const TOKEN = 'metron-fixture-token-not-real';

	private Metron $api;

	/** @var array<int, array<string, mixed>> */
	private array $request_headers = [];

	public function set_up(): void {
		parent::set_up();
		update_option(
			'pkiw_api_credentials',
			[ 'metron' => [ 'enabled' => true, 'api_token' => self::TOKEN ] ]
		);
		$this->request_headers = [];
		add_filter( 'pre_http_request', [ $this, 'record_request_headers' ], 9, 3 );
		$this->api = new Metron();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'record_request_headers' ], 9 );
		( new \ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );
		parent::tear_down();
	}

	/**
	 * Record request headers without preempting the shared stand-in.
	 *
	 * @param mixed                $preempt Existing result.
	 * @param array<string, mixed> $args    HTTP arguments.
	 * @param string               $url     Request URL.
	 * @return mixed
	 */
	public function record_request_headers( $preempt, array $args, string $url ) {
		unset( $url );
		$this->request_headers[] = is_array( $args['headers'] ?? null ) ? $args['headers'] : [];
		return $preempt;
	}

	public function test_search_uses_documented_filters_and_list_shape(): void {
		$this->mock_http_response( 'series_name=Sentinel%20Comics&number=7', 'metron/issue-list.json' );

		$results = $this->api->search( 'Sentinel Comics', '7' );

		$this->assertCount( 2, $results );
		$this->assertSame( '101', $results[0]['metronId'] );
		$this->assertSame( 'Sentinel Comics', $results[0]['series'] );
		$this->assertSame( '2', $results[0]['volume'] );
		$this->assertSame( '7', $results[0]['issueNumber'] );
		$this->assertSame( 'https://static.metron.cloud/media/issue/2026/11/15/sentinel-07.jpg', $results[0]['coverImage'] );
		$this->assertSame( '', $results[0]['publisher'] );
		$this->assertSame( '', $results[0]['creators'] );
		$this->assertSame( '', $results[0]['isbn'] );
		$this->assertSame( '', $results[0]['sourceUrl'] );
		$this->assertArrayNotHasKey( 'title', $results[0] );
	}

	public function test_search_keeps_an_ampersand_inside_the_series_name_filter(): void {
		$this->mock_http_response( 'series_name=Batman%20%26%20Robin', 'metron/issue-list-empty.json' );

		$this->assertSame( [], $this->api->search( 'Batman & Robin' ) );
		$this->assert_api_request_made( 'series_name=Batman%20%26%20Robin' );
	}

	public function test_upc_lookup_uses_the_documented_exact_filter(): void {
		$this->mock_http_response( 'issue/?upc=75960601234500111', 'metron/issue-list.json' );

		$results = $this->api->get_by_upc( '75960601234500111' );

		$this->assertCount( 2, $results );
		$this->assert_api_request_made( 'issue/?upc=75960601234500111' );
	}

	public function test_empty_list_and_removed_isbn_lookup_follow_the_documented_api(): void {
		$this->mock_http_response( 'series_name=No%20Matches', 'metron/issue-list-empty.json' );

		$this->assertSame( [], $this->api->search( 'No Matches' ) );
		$this->assertFalse( method_exists( $this->api, 'get_by_isbn' ) );
	}

	public function test_id_lookup_normalizes_detail_only_fields(): void {
		$this->mock_http_response( 'issue/101/', 'metron/issue-detail.json' );

		$result = $this->api->get_by_id( '101' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Ada Example, Grace Example', $result['creators'] );
		$this->assertSame( '9798368809267', $result['isbn'] );
		$this->assertSame( 'https://metron.cloud/issue/sentinel-comics-7/', $result['sourceUrl'] );
		$this->assertSame( '', $result['publisher'] );
	}

	public function test_normalizer_deduplicates_and_caps_creators_and_validates_urls(): void {
		$raw = [
			'id'           => 201,
			'series'       => [
				'name'      => '<b>Sentinel</b>',
				'volume'    => 3,
				'publisher' => [ 'name' => 'Example Press' ],
			],
			'number'       => '9',
			'credits'      => [
				[ 'creator' => [ 'name' => 'Ada Example' ] ],
				[ 'creator' => [ 'name' => 'Ada Example' ] ],
				[ 'creator' => 'Grace Example' ],
				[ 'creator' => 'Lin Example' ],
				[ 'creator' => 'Mary Example' ],
				[ 'creator' => 'Evelyn Example' ],
				[ 'creator' => 'Annie Example' ],
				[ 'creator' => 'Katherine Example' ],
			],
			'resource_url' => 'https://www.metron.cloud/issue/201/',
			'isbn'         => '979-8-3688-0926-7',
			'image'        => 'http://static.metron.cloud/media/issue/201.jpg',
		];

		$result = $this->normalize( $raw );

		$this->assertSame( 'Sentinel', $result['series'] );
		$this->assertSame( 'Example Press', $result['publisher'] );
		$this->assertSame( 'Ada Example, Grace Example, Lin Example, Mary Example, Evelyn Example, Annie Example', $result['creators'] );
		$this->assertSame( '', $result['sourceUrl'] );
		$this->assertSame( '', $result['coverImage'] );
		$this->assertSame( '9798368809267', $result['isbn'] );
	}

	public function test_list_results_are_capped_at_twenty(): void {
		$fixture = $this->load_fixture( 'metron/issue-list.json' );
		$fixture['results'] = array_fill( 0, 22, $fixture['results'][0] );
		foreach ( $fixture['results'] as $index => &$issue ) {
			$issue['id'] = 1000 + $index;
		}
		unset( $issue );
		$this->mock_http_response( 'series_name=Twenty', $fixture );

		$this->assertCount( 20, $this->api->search( 'Twenty' ) );
	}

	public function test_404_detail_returns_null(): void {
		$this->mock_http_response( 'issue/404/', 'metron/error-401.json', 404 );

		$this->assertNull( $this->api->get_by_id( '404' ) );
	}

	public function test_401_throws_without_exposing_token_and_uses_header_authentication(): void {
		$this->mock_http_response( 'series_name=Unauthorized', 'metron/error-401.json', 401 );

		try {
			$this->api->search( 'Unauthorized' );
			$this->fail( 'Expected a Metron API exception.' );
		} catch ( \Exception $error ) {
			$this->assertStringNotContainsString( self::TOKEN, $error->getMessage() );
		}

		$this->assertSame( 'Bearer ' . self::TOKEN, $this->request_headers[0]['Authorization'] ?? null );
		$this->assertStringNotContainsString( self::TOKEN, implode( '\n', $this->get_recorded_request_urls() ) );
	}

	public function test_429_honors_retry_after_and_retries_once(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10 );
		$calls = 0;
		$body  = wp_json_encode( $this->load_fixture( 'metron/issue-list.json' ) );
		$queue = static function ( $preempt, array $args, string $url ) use ( &$calls, $body ) {
			unset( $preempt, $args, $url );
			++$calls;
			return [
				'response' => [ 'code' => 1 === $calls ? 429 : 200, 'message' => 1 === $calls ? 'Too Many Requests' : 'OK' ],
				'headers'  => 1 === $calls ? [ 'retry-after' => '1' ] : [ 'content-type' => 'application/json' ],
				'body'     => 1 === $calls ? '{"detail":"Rate limited."}' : $body,
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $queue, 10, 3 );

		$started = microtime( true );
		try {
			$results = $this->api->search( 'Rate Limit' );
		} finally {
			remove_filter( 'pre_http_request', $queue, 10 );
			add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );
		}
		$elapsed = microtime( true ) - $started;

		$this->assertCount( 2, $results );
		$this->assertSame( 2, $calls );
		$this->assertGreaterThanOrEqual( 1.0, $elapsed );
	}

	public function test_connection_uses_the_documented_issue_endpoint_without_unknown_filters(): void {
		$this->mock_http_response( 'https://metron.cloud/api/issue/', 'metron/issue-list.json' );

		$this->assertTrue( $this->api->test_connection() );
		$this->assertSame( [ 'https://metron.cloud/api/issue/' ], $this->get_recorded_request_urls() );
	}

	public function test_503_retries_once_before_succeeding(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10 );
		$calls = 0;
		$body  = wp_json_encode( $this->load_fixture( 'metron/issue-list.json' ) );
		$queue = static function ( $preempt, array $args, string $url ) use ( &$calls, $body ) {
			unset( $preempt, $args, $url );
			++$calls;
			return [
				'response' => [ 'code' => 1 === $calls ? 503 : 200, 'message' => 1 === $calls ? 'Unavailable' : 'OK' ],
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => 1 === $calls ? '{"detail":"Unavailable"}' : $body,
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $queue, 10, 3 );

		try {
			$results = $this->api->search( 'Server Retry' );
		} finally {
			remove_filter( 'pre_http_request', $queue, 10 );
			add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );
		}

		$this->assertCount( 2, $results );
		$this->assertSame( 2, $calls );
	}

	public function test_contract_configuration_values(): void {
		$this->assertSame( 0.33, $this->property( 'rate_limit' ) );
		$this->assertSame( 2, $this->property( 'max_retries' ) );
		$this->assertSame( DAY_IN_SECONDS, $this->property( 'cache_duration' ) );
		$this->assertSame( 'https://metron.cloud/api/', $this->property( 'base_url' ) );
	}

	public function test_no_token_throws_before_any_request(): void {
		update_option( 'pkiw_api_credentials', [] );
		$api = new Metron();

		try {
			$api->search( 'Sentinel Comics' );
			$this->fail( 'Expected a configuration exception.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringNotContainsString( self::TOKEN, $error->getMessage() );
		}

		$this->assertSame( [], $this->get_recorded_request_urls() );
	}

	/** @param array<string, mixed> $raw */
	private function normalize( array $raw ): array {
		$method = new \ReflectionMethod( Metron::class, 'normalize_result' );
		return $method->invoke( $this->api, $raw );
	}

	/** @return mixed */
	private function property( string $name ) {
		$property = new \ReflectionProperty( API_Base::class, $name );
		return $property->getValue( $this->api );
	}
}

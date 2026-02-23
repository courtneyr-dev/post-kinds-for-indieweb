<?php
/**
 * Test the API_Base abstract class via a concrete stub.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\API_Base;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Concrete stub exposing API_Base's protected methods for testing.
 */
class Test_API_Stub extends API_Base {

	protected string $api_name  = 'test_api';
	protected string $base_url  = 'https://api.example.com/v1/';
	protected int $max_retries  = 1;

	public function test_connection(): bool {
		try {
			$this->get( 'ping' );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	public function search( string $query, ...$args ): array {
		try {
			return $this->cached_get( 'search', [ 'q' => $query ] );
		} catch ( \Exception $e ) {
			return [];
		}
	}

	public function get_by_id( string $id ): ?array {
		try {
			return $this->get( 'items/' . $id );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	protected function normalize_result( array $raw_result ): array {
		return $raw_result;
	}

	// Expose protected methods for testing.

	public function exposed_build_url( string $endpoint, array $params = [] ): string {
		return $this->build_url( $endpoint, $params );
	}

	public function exposed_get_cache_key( string $key ): string {
		return $this->get_cache_key( $key );
	}

	public function exposed_get( string $endpoint, array $params = [] ): array {
		return $this->get( $endpoint, $params );
	}

	public function exposed_cached_get( string $endpoint, array $params = [] ): array {
		return $this->cached_get( $endpoint, $params );
	}

	public function exposed_set_cache( string $key, $data ): bool {
		return $this->set_cache( $key, $data );
	}

	public function exposed_get_cache( string $key ) {
		return $this->get_cache( $key );
	}
}

/**
 * Test API_Base through the concrete stub.
 *
 * @covers \PostKindsForIndieWeb\APIs\API_Base
 */
class ApiBaseTest extends ApiTestCase {

	/**
	 * Stub instance.
	 *
	 * @var Test_API_Stub
	 */
	private Test_API_Stub $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new Test_API_Stub();
	}

	/**
	 * Test build_url with endpoint only.
	 */
	public function test_build_url_endpoint_only(): void {
		$url = $this->api->exposed_build_url( 'items/123' );

		$this->assertSame( 'https://api.example.com/v1/items/123', $url );
	}

	/**
	 * Test build_url strips leading slash from endpoint.
	 */
	public function test_build_url_strips_leading_slash(): void {
		$url = $this->api->exposed_build_url( '/items/123' );

		$this->assertSame( 'https://api.example.com/v1/items/123', $url );
	}

	/**
	 * Test build_url with query parameters.
	 */
	public function test_build_url_with_params(): void {
		$url = $this->api->exposed_build_url( 'search', [ 'q' => 'test', 'limit' => '10' ] );

		$this->assertStringContainsString( 'api.example.com/v1/search', $url );
		$this->assertStringContainsString( 'q=test', $url );
		$this->assertStringContainsString( 'limit=10', $url );
	}

	/**
	 * Test build_url with empty params omits query string.
	 */
	public function test_build_url_empty_params(): void {
		$url = $this->api->exposed_build_url( 'items', [] );

		$this->assertSame( 'https://api.example.com/v1/items', $url );
		$this->assertStringNotContainsString( '?', $url );
	}

	/**
	 * Test cache key format.
	 */
	public function test_cache_key_format(): void {
		$key = $this->api->exposed_get_cache_key( 'my_key' );

		$this->assertStringStartsWith( 'post_kinds_test_api_', $key );
		$this->assertSame( 'post_kinds_test_api_' . md5( 'my_key' ), $key );
	}

	/**
	 * Test successful GET request.
	 */
	public function test_get_request_success(): void {
		$this->mock_http_response( 'api.example.com', [ 'id' => '123', 'name' => 'Test' ] );

		$result = $this->api->exposed_get( 'items/123' );

		$this->assertSame( '123', $result['id'] );
		$this->assertSame( 'Test', $result['name'] );
		$this->assert_api_request_made( 'api.example.com/v1/items/123' );
	}

	/**
	 * Test GET request with network error throws exception.
	 */
	public function test_get_request_network_error(): void {
		$this->mock_http_error( 'api.example.com', 'Connection refused' );

		$this->expectException( \Exception::class );

		$this->api->exposed_get( 'items/123' );
	}

	/**
	 * Test GET request with 4xx error throws exception.
	 */
	public function test_get_request_client_error(): void {
		$this->mock_http_response(
			'api.example.com',
			[ 'error' => 'Not Found' ],
			404
		);

		$this->expectException( \Exception::class );

		$this->api->exposed_get( 'items/999' );
	}

	/**
	 * Test cached_get stores result in transient.
	 */
	public function test_cached_get_stores_in_transient(): void {
		$this->mock_http_response( 'api.example.com', [ 'results' => [ 'a', 'b' ] ] );

		$first = $this->api->exposed_cached_get( 'search', [ 'q' => 'hello' ] );

		$this->assertSame( [ 'results' => [ 'a', 'b' ] ], $first );

		// Verify data is now in transient cache.
		$cache_key     = 'search_' . wp_json_encode( [ 'q' => 'hello' ] );
		$cached_result = $this->api->exposed_get_cache( $cache_key );

		$this->assertNotNull( $cached_result );
		$this->assertSame( $first, $cached_result );
	}

	/**
	 * Test cached_get uses transient on second call.
	 */
	public function test_cached_get_uses_transient(): void {
		$this->mock_http_response( 'api.example.com', [ 'data' => 'fresh' ] );

		$first = $this->api->exposed_cached_get( 'endpoint', [ 'x' => '1' ] );
		$this->assertSame( [ 'data' => 'fresh' ], $first );

		// Second call on a new instance should use transient, not HTTP.
		$api2   = new Test_API_Stub();
		$second = $api2->exposed_cached_get( 'endpoint', [ 'x' => '1' ] );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test is_configured returns true by default.
	 */
	public function test_is_configured_default(): void {
		$this->assertTrue( $this->api->is_configured() );
	}

	/**
	 * Test get_docs_url returns empty string by default.
	 */
	public function test_get_docs_url_default(): void {
		$this->assertSame( '', $this->api->get_docs_url() );
	}

	/**
	 * Test get_config_fields returns empty array by default.
	 */
	public function test_get_config_fields_default(): void {
		$this->assertSame( [], $this->api->get_config_fields() );
	}

	/**
	 * Test set_cache and get_cache round-trip.
	 */
	public function test_cache_round_trip(): void {
		$data = [ 'foo' => 'bar', 'count' => 42 ];

		$this->api->exposed_set_cache( 'test_key', $data );
		$result = $this->api->exposed_get_cache( 'test_key' );

		$this->assertSame( $data, $result );
	}

	/**
	 * Test get_cache returns null for missing key.
	 */
	public function test_get_cache_returns_null_for_missing(): void {
		$result = $this->api->exposed_get_cache( 'nonexistent_key' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_by_id returns null on error.
	 */
	public function test_get_by_id_returns_null_on_error(): void {
		$this->mock_http_error( 'api.example.com', 'Server down' );

		$result = $this->api->get_by_id( '123' );

		$this->assertNull( $result );
	}

	/**
	 * Test search returns empty array on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.example.com', 'Timeout' );

		$results = $this->api->search( 'test' );

		$this->assertSame( [], $results );
	}
}

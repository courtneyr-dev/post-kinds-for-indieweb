<?php
/**
 * Abstract API test case with HTTP mocking infrastructure.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests;

use WP_UnitTestCase;

/**
 * Base class for testing API clients with HTTP mocking.
 */
abstract class ApiTestCase extends WP_UnitTestCase {

	/**
	 * Mocked HTTP responses keyed by URL pattern.
	 *
	 * @var array
	 */
	private array $mocked_responses = [];

	/**
	 * Recorded HTTP request URLs.
	 *
	 * @var array
	 */
	private array $recorded_requests = [];

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->mocked_responses = [];
		$this->recorded_requests = [];
		add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10 );
		parent::tear_down();
	}

	/**
	 * Load a JSON fixture file.
	 *
	 * @param string $path Fixture path relative to fixtures directory.
	 * @return array Decoded fixture data.
	 */
	protected function load_fixture( string $path ): array {
		$file = __DIR__ . '/fixtures/' . ltrim( $path, '/' );
		$this->assertFileExists( $file, "Fixture not found: $path" );
		$json = file_get_contents( $file );
		$data = json_decode( $json, true );
		$this->assertNotNull( $data, "Invalid JSON in fixture: $path" );
		return $data;
	}

	/**
	 * Mock an HTTP response for URLs matching a pattern.
	 *
	 * @param string       $url_pattern Substring to match in request URL.
	 * @param string|array $fixture     Fixture path or inline data.
	 * @param int          $status      HTTP status code.
	 * @param array        $headers     Response headers.
	 */
	protected function mock_http_response(
		string $url_pattern,
		$fixture,
		int $status = 200,
		array $headers = []
	): void {
		if ( is_string( $fixture ) ) {
			$body = wp_json_encode( $this->load_fixture( $fixture ) );
		} else {
			$body = wp_json_encode( $fixture );
		}

		$this->mocked_responses[ $url_pattern ] = [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => array_merge( [ 'content-type' => 'application/json' ], $headers ),
		];
	}

	/**
	 * Mock an HTTP response with a raw (non-JSON) body.
	 *
	 * @param string $url_pattern  Substring to match in request URL.
	 * @param string $body         Raw response body.
	 * @param int    $status       HTTP status code.
	 * @param array  $headers      Response headers.
	 */
	protected function mock_http_raw_response(
		string $url_pattern,
		string $body,
		int $status = 200,
		array $headers = []
	): void {
		$this->mocked_responses[ $url_pattern ] = [
			'response' => [ 'code' => $status, 'message' => 'OK' ],
			'body'     => $body,
			'headers'  => array_merge( [ 'content-type' => 'application/xml' ], $headers ),
		];
	}

	/**
	 * Mock an HTTP error (WP_Error) for URLs matching a pattern.
	 *
	 * @param string $url_pattern   Substring to match in request URL.
	 * @param string $error_message Error message.
	 */
	protected function mock_http_error( string $url_pattern, string $error_message = 'Connection failed' ): void {
		$this->mocked_responses[ $url_pattern ] = new \WP_Error( 'http_request_failed', $error_message );
	}

	/**
	 * Intercept HTTP requests and return mocked responses.
	 *
	 * @param mixed  $preempt     Preempt value.
	 * @param array  $parsed_args Request arguments.
	 * @param string $url         Request URL.
	 * @return mixed Mocked response or test failure.
	 */
	public function intercept_http_request( $preempt, $parsed_args, $url ) {
		$this->recorded_requests[] = $url;

		foreach ( $this->mocked_responses as $pattern => $response ) {
			if ( str_contains( $url, $pattern ) ) {
				return $response;
			}
		}

		$this->fail( "Unmocked HTTP request to: $url" );
	}

	/**
	 * Assert that an HTTP request matching a pattern was made.
	 *
	 * @param string $url_pattern Substring to match.
	 */
	protected function assert_api_request_made( string $url_pattern ): void {
		foreach ( $this->recorded_requests as $url ) {
			if ( str_contains( $url, $url_pattern ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}
		$this->fail( "Expected HTTP request matching '$url_pattern' was not made." );
	}

	/**
	 * Assert that no HTTP request matching a pattern was made.
	 *
	 * @param string $url_pattern Substring to match.
	 */
	protected function assert_no_api_request( string $url_pattern ): void {
		foreach ( $this->recorded_requests as $url ) {
			if ( str_contains( $url, $url_pattern ) ) {
				$this->fail( "Unexpected HTTP request matching '$url_pattern': $url" );
			}
		}
		$this->addToAssertionCount( 1 );
	}
}

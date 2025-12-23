<?php
/**
 * Abstract API Base Class
 *
 * Provides common functionality for all external API integrations including
 * caching, rate limiting, error handling, and HTTP requests.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\APIs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for API integrations.
 *
 * @since 1.0.0
 */
abstract class API_Base {

	/**
	 * API name for identification.
	 *
	 * @var string
	 */
	protected string $api_name = '';

	/**
	 * Base URL for the API.
	 *
	 * @var string
	 */
	protected string $base_url = '';

	/**
	 * Default cache duration in seconds.
	 *
	 * @var int
	 */
	protected int $cache_duration = DAY_IN_SECONDS;

	/**
	 * Rate limit (requests per second).
	 *
	 * @var float
	 */
	protected float $rate_limit = 1.0;

	/**
	 * Minimum time between requests in microseconds.
	 *
	 * @var int
	 */
	protected int $min_request_interval = 1000000;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected int $timeout = 15;

	/**
	 * Maximum retry attempts.
	 *
	 * @var int
	 */
	protected int $max_retries = 3;

	/**
	 * User agent string.
	 *
	 * @var string
	 */
	protected string $user_agent = '';

	/**
	 * Last request timestamp.
	 *
	 * @var float
	 */
	private static array $last_request_times = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->user_agent = sprintf(
			'ReactionsForIndieWeb/%s (WordPress/%s; +%s)',
			REACTIONS_INDIEWEB_VERSION,
			get_bloginfo( 'version' ),
			home_url()
		);

		// Calculate minimum interval from rate limit.
		if ( $this->rate_limit > 0 ) {
			$this->min_request_interval = (int) ( 1000000 / $this->rate_limit );
		}
	}

	/**
	 * Make an HTTP GET request.
	 *
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $params   Query parameters.
	 * @param array<string, mixed> $headers  Additional headers.
	 * @return array<string, mixed> Response data.
	 * @throws \Exception On request failure.
	 */
	protected function get( string $endpoint, array $params = array(), array $headers = array() ): array {
		return $this->request( 'GET', $endpoint, $params, array(), $headers );
	}

	/**
	 * Make an HTTP POST request.
	 *
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $body     Request body.
	 * @param array<string, mixed> $headers  Additional headers.
	 * @return array<string, mixed> Response data.
	 * @throws \Exception On request failure.
	 */
	protected function post( string $endpoint, array $body = array(), array $headers = array() ): array {
		return $this->request( 'POST', $endpoint, array(), $body, $headers );
	}

	/**
	 * Make an HTTP request.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $params   Query parameters.
	 * @param array<string, mixed> $body     Request body.
	 * @param array<string, mixed> $headers  Additional headers.
	 * @return array<string, mixed> Response data.
	 * @throws \Exception On request failure.
	 */
	protected function request(
		string $method,
		string $endpoint,
		array $params = array(),
		array $body = array(),
		array $headers = array()
	): array {
		// Respect rate limiting.
		$this->respect_rate_limit();

		// Build URL.
		$url = $this->build_url( $endpoint, $params );

		// Build request args.
		$args = array(
			'method'     => $method,
			'timeout'    => $this->timeout,
			'user-agent' => $this->user_agent,
			'headers'    => array_merge(
				array(
					'Accept' => 'application/json',
				),
				$this->get_default_headers(),
				$headers
			),
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		// Make request with retry logic.
		$response = $this->make_request_with_retry( $url, $args );

		// Record request time.
		self::$last_request_times[ $this->api_name ] = microtime( true );

		// Parse response.
		return $this->parse_response( $response );
	}

	/**
	 * Make request with retry logic.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return array|\WP_Error Response or error.
	 * @throws \Exception On failure after retries.
	 */
	private function make_request_with_retry( string $url, array $args ) {
		$last_error = null;

		for ( $attempt = 1; $attempt <= $this->max_retries; $attempt++ ) {
			$response = wp_safe_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response;

				// Log the error.
				$this->log_error( 'Request failed', array(
					'url'     => $url,
					'attempt' => $attempt,
					'error'   => $response->get_error_message(),
				) );

				// Exponential backoff.
				if ( $attempt < $this->max_retries ) {
					usleep( (int) pow( 2, $attempt ) * 100000 ); // 200ms, 400ms, 800ms...
				}

				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// Success.
			if ( $code >= 200 && $code < 300 ) {
				return $response;
			}

			// Rate limited - wait and retry.
			if ( 429 === $code ) {
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$wait_time   = $retry_after ? (int) $retry_after : pow( 2, $attempt );

				$this->log_error( 'Rate limited', array(
					'url'        => $url,
					'attempt'    => $attempt,
					'retry_after'=> $wait_time,
				) );

				if ( $attempt < $this->max_retries ) {
					sleep( min( $wait_time, 30 ) ); // Cap at 30 seconds.
				}

				continue;
			}

			// Server error - retry.
			if ( $code >= 500 ) {
				$this->log_error( 'Server error', array(
					'url'     => $url,
					'attempt' => $attempt,
					'code'    => $code,
				) );

				if ( $attempt < $this->max_retries ) {
					usleep( (int) pow( 2, $attempt ) * 100000 );
				}

				continue;
			}

			// Client error - don't retry.
			return $response;
		}

		if ( $last_error ) {
			throw new \Exception(
				sprintf(
					/* translators: 1: API name, 2: Error message */
					__( '%1$s API error: %2$s', 'reactions-indieweb' ),
					$this->api_name,
					$last_error->get_error_message()
				)
			);
		}

		throw new \Exception(
			sprintf(
				/* translators: %s: API name */
				__( '%s API request failed after multiple attempts.', 'reactions-indieweb' ),
				$this->api_name
			)
		);
	}

	/**
	 * Parse API response.
	 *
	 * @param array|\WP_Error $response HTTP response.
	 * @return array<string, mixed> Parsed response data.
	 * @throws \Exception On error response.
	 */
	protected function parse_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		// Try to decode JSON.
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Not JSON, return raw body.
			$data = array( 'raw' => $body );
		}

		// Handle error responses.
		if ( $code >= 400 ) {
			$error_message = $this->extract_error_message( $data, $code );

			throw new \Exception( $error_message );
		}

		return $data ?? array();
	}

	/**
	 * Extract error message from response.
	 *
	 * @param array<string, mixed>|null $data Response data.
	 * @param int                       $code HTTP status code.
	 * @return string Error message.
	 */
	protected function extract_error_message( ?array $data, int $code ): string {
		// Common error message fields.
		$error_fields = array( 'error', 'message', 'error_message', 'error_description', 'status_message' );

		if ( $data ) {
			foreach ( $error_fields as $field ) {
				if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
					return $data[ $field ];
				}
			}

			// Nested error object.
			if ( isset( $data['error'] ) && is_array( $data['error'] ) ) {
				foreach ( $error_fields as $field ) {
					if ( isset( $data['error'][ $field ] ) ) {
						return $data['error'][ $field ];
					}
				}
			}
		}

		// Default error message.
		return sprintf(
			/* translators: 1: API name, 2: HTTP status code */
			__( '%1$s API returned error code %2$d', 'reactions-indieweb' ),
			$this->api_name,
			$code
		);
	}

	/**
	 * Build full URL with query parameters.
	 *
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $params   Query parameters.
	 * @return string Full URL.
	 */
	protected function build_url( string $endpoint, array $params = array() ): string {
		$url = $this->base_url . ltrim( $endpoint, '/' );

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		return $url;
	}

	/**
	 * Get default headers for requests.
	 *
	 * @return array<string, string> Headers.
	 */
	protected function get_default_headers(): array {
		return array();
	}

	/**
	 * Respect rate limiting.
	 *
	 * @return void
	 */
	protected function respect_rate_limit(): void {
		if ( ! isset( self::$last_request_times[ $this->api_name ] ) ) {
			return;
		}

		$elapsed = ( microtime( true ) - self::$last_request_times[ $this->api_name ] ) * 1000000;

		if ( $elapsed < $this->min_request_interval ) {
			usleep( (int) ( $this->min_request_interval - $elapsed ) );
		}
	}

	/**
	 * Get cached data.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null Cached data or null.
	 */
	protected function get_cache( string $key ) {
		$cache_key = $this->get_cache_key( $key );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		return null;
	}

	/**
	 * Set cached data.
	 *
	 * @param string $key      Cache key.
	 * @param mixed  $data     Data to cache.
	 * @param int    $duration Cache duration in seconds.
	 * @return bool True on success.
	 */
	protected function set_cache( string $key, $data, ?int $duration = null ): bool {
		$cache_key = $this->get_cache_key( $key );
		$duration  = $duration ?? $this->cache_duration;

		return set_transient( $cache_key, $data, $duration );
	}

	/**
	 * Delete cached data.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success.
	 */
	protected function delete_cache( string $key ): bool {
		$cache_key = $this->get_cache_key( $key );

		return delete_transient( $cache_key );
	}

	/**
	 * Get full cache key.
	 *
	 * @param string $key Partial key.
	 * @return string Full cache key.
	 */
	protected function get_cache_key( string $key ): string {
		return 'reactions_' . $this->api_name . '_' . md5( $key );
	}

	/**
	 * Make a cached GET request.
	 *
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $params   Query parameters.
	 * @param int|null             $duration Cache duration.
	 * @return array<string, mixed> Response data.
	 * @throws \Exception On request failure.
	 */
	protected function cached_get( string $endpoint, array $params = array(), ?int $duration = null ): array {
		$cache_key = $endpoint . '_' . wp_json_encode( $params );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$data = $this->get( $endpoint, $params );

		$this->set_cache( $cache_key, $data, $duration );

		return $data;
	}

	/**
	 * Log an error.
	 *
	 * @param string               $message Error message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	protected function log_error( string $message, array $context = array() ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[Reactions for IndieWeb] %s API: %s | Context: %s',
					$this->api_name,
					$message,
					wp_json_encode( $context )
				)
			);
		}

		/**
		 * Fires when an API error occurs.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $api_name API name.
		 * @param string               $message  Error message.
		 * @param array<string, mixed> $context  Additional context.
		 */
		do_action( 'reactions_indieweb_api_error', $this->api_name, $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string               $message Debug message.
	 * @param array<string, mixed> $context Additional context.
	 * @return void
	 */
	protected function log_debug( string $message, array $context = array() ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'REACTIONS_INDIEWEB_DEBUG' ) && REACTIONS_INDIEWEB_DEBUG ) {
			error_log(
				sprintf(
					'[Reactions for IndieWeb] %s API DEBUG: %s | Context: %s',
					$this->api_name,
					$message,
					wp_json_encode( $context )
				)
			);
		}
	}

	/**
	 * Test API connection.
	 *
	 * @return bool True if connection is successful.
	 */
	abstract public function test_connection(): bool;

	/**
	 * Search the API.
	 *
	 * @param string $query  Search query.
	 * @param mixed  ...$args Additional arguments.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	abstract public function search( string $query, ...$args ): array;

	/**
	 * Get item by ID.
	 *
	 * @param string $id Item ID.
	 * @return array<string, mixed>|null Item data or null.
	 */
	abstract public function get_by_id( string $id ): ?array;

	/**
	 * Normalize result to standard format.
	 *
	 * Each API should implement this to return consistent data structure.
	 *
	 * @param array<string, mixed> $raw_result Raw API result.
	 * @return array<string, mixed> Normalized result.
	 */
	abstract protected function normalize_result( array $raw_result ): array;

	/**
	 * Get option value.
	 *
	 * @param string $key     Option key (without prefix).
	 * @param mixed  $default Default value.
	 * @return mixed Option value.
	 */
	protected function get_option( string $key, $default = '' ) {
		return get_option( 'reactions_indieweb_' . $key, $default );
	}

	/**
	 * Check if API is configured.
	 *
	 * @return bool True if API has required configuration.
	 */
	public function is_configured(): bool {
		return true; // Override in subclasses that require API keys.
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string Documentation URL.
	 */
	public function get_docs_url(): string {
		return '';
	}

	/**
	 * Get required configuration fields.
	 *
	 * @return array<string, array<string, mixed>> Configuration fields.
	 */
	public function get_config_fields(): array {
		return array();
	}
}

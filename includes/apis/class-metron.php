<?php
/**
 * Metron API integration.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\APIs;

use PKIW\Meta_Fields;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks up comic issues in Metron.
 */
class Metron extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'metron';

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://metron.cloud/api/';

	/**
	 * Requests per second.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.33;

	/**
	 * Maximum request attempts.
	 *
	 * @var int
	 */
	protected int $max_retries = 2;

	/**
	 * Cache duration.
	 *
	 * @var int
	 */
	protected int $cache_duration = DAY_IN_SECONDS;

	/**
	 * Metron API token.
	 *
	 * @var string
	 */
	private string $api_token = '';

	/**
	 * Read the saved token once for this client instance.
	 */
	public function __construct() {
		parent::__construct();
		$credentials     = $this->get_option( 'api_credentials', [] );
		$metron          = is_array( $credentials ) ? ( $credentials['metron'] ?? [] ) : [];
		$this->api_token = is_array( $metron ) ? (string) ( $metron['api_token'] ?? '' ) : '';
	}

	/**
	 * Whether a token is saved.
	 */
	public function is_configured(): bool {
		return '' !== $this->api_token;
	}

	/**
	 * Add token authentication without putting the token in the URL.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		return [ 'Authorization' => 'Bearer ' . $this->api_token ];
	}

	/**
	 * Search issues by series and optional issue number.
	 *
	 * @param string $query   Series name.
	 * @param mixed  ...$args `$args[0]` is an optional issue number.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $query, ...$args ): array {
		$this->require_configuration();

		$params = [ 'series_name' => $query ];
		if ( isset( $args[0] ) && '' !== (string) $args[0] ) {
			$params['number'] = (string) $args[0];
		}

		return $this->issue_list( $params );
	}

	/**
	 * Find issues by UPC.
	 *
	 * @param string $upc UPC value.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_upc( string $upc ): array {
		$this->require_configuration();
		if ( '' === $upc ) {
			return [];
		}

		return $this->issue_list( [ 'upc' => $upc ] );
	}

	/**
	 * Get one issue by Metron ID.
	 *
	 * @param string $id Metron issue ID.
	 * @return array<string, mixed>|null
	 * @throws \Exception When the request fails for a reason other than a missing issue.
	 */
	public function get_by_id( string $id ): ?array {
		$this->require_configuration();
		$id = absint( $id );
		if ( 0 === $id ) {
			return null;
		}

		try {
			$raw = $this->cached_get( 'issue/' . $id . '/' );
		} catch ( \Exception $error ) {
			if ( 404 === $error->getCode() ) {
				return null;
			}
			throw $error;
		}

		return $this->normalize_result( $raw );
	}

	/**
	 * Test the saved token with a one-row request.
	 */
	public function test_connection(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		try {
			$this->get( 'issue/' );
			return true;
		} catch ( \Exception $error ) {
			return false;
		}
	}

	/**
	 * Preserve the response status needed for the detail endpoint's 404 contract.
	 *
	 * @param array|\WP_Error $response HTTP response.
	 * @return array<string, mixed>
	 * @throws \Exception On a non-success response.
	 */
	protected function parse_response( $response ): array {
		if ( ! is_wp_error( $response ) && 404 === wp_remote_retrieve_response_code( $response ) ) {
			throw new \Exception( 'Metron issue not found.', 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed internal sentinel, never rendered.
		}

		return parent::parse_response( $response );
	}

	/**
	 * Normalize a Metron list or detail record for the comic card.
	 *
	 * @param array<string, mixed> $raw Raw issue record.
	 * @return array<string, mixed>
	 */
	protected function normalize_result( array $raw ): array {
		$series    = is_array( $raw['series'] ?? null ) ? $raw['series'] : [];
		$publisher = is_array( $raw['publisher'] ?? null ) ? $raw['publisher'] : [];
		if ( '' === (string) ( $publisher['name'] ?? '' ) && is_array( $series['publisher'] ?? null ) ) {
			$publisher = $series['publisher'];
		}

		$creators = [];
		foreach ( is_array( $raw['credits'] ?? null ) ? $raw['credits'] : [] as $credit ) {
			if ( ! is_array( $credit ) ) {
				continue;
			}
			$creator = $credit['creator'] ?? '';
			$name    = is_array( $creator ) ? (string) ( $creator['name'] ?? '' ) : (string) $creator;
			if ( '' !== $name && ! in_array( $name, $creators, true ) ) {
				$creators[] = $name;
			}
			if ( 6 === count( $creators ) ) {
				break;
			}
		}

		$source_url = (string) ( $raw['resource_url'] ?? '' );
		if ( ! $this->is_metron_url( $source_url ) ) {
			$source_url = '';
		}

		$cover = (string) ( $raw['image'] ?? '' );
		if ( 'https' !== strtolower( (string) wp_parse_url( $cover, PHP_URL_SCHEME ) ) ) {
			$cover = '';
		}

		return $this->sanitize_normalized_result(
			[
				'metronId'      => isset( $raw['id'] ) ? (string) absint( $raw['id'] ) : '',
				'series'        => (string) ( $series['name'] ?? '' ),
				'volume'        => isset( $series['volume'] ) ? (string) $series['volume'] : '',
				'issueNumber'   => (string) ( $raw['number'] ?? '' ),
				'publisher'     => (string) ( $publisher['name'] ?? '' ),
				'creators'      => implode( ', ', $creators ),
				'sourceUrl'     => $source_url,
				'isbn'          => Meta_Fields::normalize_isbn( $raw['isbn'] ?? '' ),
				'coverImage'    => $cover,
				'coverImageAlt' => '',
			],
			[ 'sourceUrl', 'coverImage' ]
		);
	}

	/**
	 * Fetch and normalize one page of issue list results.
	 *
	 * @param array<string, string> $params Issue filters.
	 * @return array<int, array<string, mixed>>
	 */
	private function issue_list( array $params ): array {
		$params   = array_map( 'rawurlencode', $params );
		$response = $this->cached_get( 'issue/', $params );
		$results  = is_array( $response['results'] ?? null ) ? array_slice( $response['results'], 0, 20 ) : [];

		return array_values(
			array_map(
				fn( array $issue ): array => $this->normalize_result( $issue ),
				array_filter( $results, 'is_array' )
			)
		);
	}

	/**
	 * Refuse lookups before any request when the token is absent.
	 *
	 * @throws \RuntimeException When Metron isn't configured.
	 */
	private function require_configuration(): void {
		if ( ! $this->is_configured() ) {
			throw new \RuntimeException( 'Metron API is not configured.' );
		}
	}

	/**
	 * Whether a source URL is HTTPS on Metron's exact web host.
	 *
	 * @param string $url Candidate URL.
	 */
	private function is_metron_url( string $url ): bool {
		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) )
			&& 'metron.cloud' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}
}

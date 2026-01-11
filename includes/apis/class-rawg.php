<?php
/**
 * RAWG API Integration
 *
 * Provides integration with RAWG.io API for video game lookups.
 *
 * @package ReactionsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\APIs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RAWG API class.
 *
 * Handles communication with RAWG.io API for searching
 * and retrieving video game metadata.
 *
 * @since 1.1.0
 */
class RAWG extends API_Base {

	/**
	 * API name for identification.
	 *
	 * @var string
	 */
	protected string $api_name = 'rawg';

	/**
	 * Base URL for the API.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.rawg.io/api/';

	/**
	 * Rate limit - RAWG is generous but respect limits.
	 *
	 * @var float
	 */
	protected float $rate_limit = 1.0;

	/**
	 * Cache duration - 1 week for game data.
	 *
	 * @var int
	 */
	protected int $cache_duration = WEEK_IN_SECONDS;

	/**
	 * Get API credentials.
	 *
	 * @return array<string, string> Credentials.
	 */
	private function get_credentials(): array {
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
		return $credentials['rawg'] ?? array();
	}

	/**
	 * Get API key.
	 *
	 * @return string API key.
	 */
	private function get_api_key(): string {
		$creds = $this->get_credentials();
		return $creds['api_key'] ?? '';
	}

	/**
	 * Test API connection.
	 *
	 * @return bool True if connection successful.
	 */
	public function test_connection(): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		$response = $this->search( 'Mario' );
		return ! empty( $response );
	}

	/**
	 * Check if API is configured.
	 *
	 * @return bool True if configured.
	 */
	public function is_configured(): bool {
		return ! empty( $this->get_api_key() );
	}

	/**
	 * Search for games.
	 *
	 * @param string $query    Search query.
	 * @param int    $page     Page number.
	 * @param int    $per_page Results per page.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, int $page = 1, int $per_page = 20 ): array {
		if ( empty( $query ) || ! $this->is_configured() ) {
			return array();
		}

		$cache_key = $this->get_cache_key( 'search', array( $query, $page, $per_page ) );
		$cached    = $this->get_cache( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->get(
			'games',
			array(
				'key'       => $this->get_api_key(),
				'search'    => $query,
				'page'      => $page,
				'page_size' => $per_page,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['results'] ) ) {
			return array();
		}

		$results = array_map(
			function( $item ) {
				return $this->normalize_search_result( $item );
			},
			$response['results']
		);

		$this->set_cache( $cache_key, $results );

		return $results;
	}

	/**
	 * Get game by ID.
	 *
	 * @param string $id Game ID or slug.
	 * @return array<string, mixed>|null Game data or null.
	 */
	public function get_by_id( string $id ): ?array {
		if ( empty( $id ) || ! $this->is_configured() ) {
			return null;
		}

		$cache_key = $this->get_cache_key( 'game', array( $id ) );
		$cached    = $this->get_cache( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->get(
			'games/' . rawurlencode( $id ),
			array( 'key' => $this->get_api_key() )
		);

		if ( is_wp_error( $response ) || empty( $response ) ) {
			return null;
		}

		$result = $this->normalize_result( $response );
		$this->set_cache( $cache_key, $result );

		return $result;
	}

	/**
	 * Normalize search result to simpler format.
	 *
	 * @param array<string, mixed> $item Raw item from API.
	 * @return array<string, mixed> Normalized search result.
	 */
	private function normalize_search_result( array $item ): array {
		$platforms = array();
		if ( ! empty( $item['platforms'] ) ) {
			foreach ( $item['platforms'] as $platform ) {
				if ( isset( $platform['platform']['name'] ) ) {
					$platforms[] = $platform['platform']['name'];
				}
			}
		}

		return array(
			'id'        => $item['id'] ?? 0,
			'slug'      => $item['slug'] ?? '',
			'name'      => $item['name'] ?? '',
			'year'      => ! empty( $item['released'] ) ? substr( $item['released'], 0, 4 ) : '',
			'cover'     => $item['background_image'] ?? '',
			'rating'    => $item['rating'] ?? 0,
			'metacritic'=> $item['metacritic'] ?? null,
			'platforms' => $platforms,
			'source'    => 'rawg',
		);
	}

	/**
	 * Normalize full result to standard format.
	 *
	 * @param array<string, mixed> $item Raw item from API.
	 * @return array<string, mixed> Normalized result.
	 */
	public function normalize_result( array $item ): array {
		$platforms = array();
		if ( ! empty( $item['platforms'] ) ) {
			foreach ( $item['platforms'] as $platform ) {
				if ( isset( $platform['platform']['name'] ) ) {
					$platforms[] = $platform['platform']['name'];
				}
			}
		}

		$genres = array();
		if ( ! empty( $item['genres'] ) ) {
			foreach ( $item['genres'] as $genre ) {
				$genres[] = $genre['name'] ?? '';
			}
		}

		$developers = array();
		if ( ! empty( $item['developers'] ) ) {
			foreach ( $item['developers'] as $dev ) {
				$developers[] = $dev['name'] ?? '';
			}
		}

		$publishers = array();
		if ( ! empty( $item['publishers'] ) ) {
			foreach ( $item['publishers'] as $pub ) {
				$publishers[] = $pub['name'] ?? '';
			}
		}

		$stores = array();
		if ( ! empty( $item['stores'] ) ) {
			foreach ( $item['stores'] as $store ) {
				if ( isset( $store['store']['name'] ) ) {
					$stores[] = array(
						'name' => $store['store']['name'],
						'url'  => $store['url'] ?? '',
					);
				}
			}
		}

		return array(
			'id'          => $item['id'] ?? 0,
			'slug'        => $item['slug'] ?? '',
			'title'       => $item['name'] ?? '',
			'year'        => ! empty( $item['released'] ) ? substr( $item['released'], 0, 4 ) : '',
			'released'    => $item['released'] ?? '',
			'cover'       => $item['background_image'] ?? '',
			'description' => $this->strip_html( $item['description'] ?? $item['description_raw'] ?? '' ),
			'rating'      => $item['rating'] ?? 0,
			'rating_count'=> $item['ratings_count'] ?? 0,
			'metacritic'  => $item['metacritic'] ?? null,
			'playtime'    => $item['playtime'] ?? 0,
			'platforms'   => $platforms,
			'genres'      => $genres,
			'developers'  => $developers,
			'publishers'  => $publishers,
			'stores'      => $stores,
			'website'     => $item['website'] ?? '',
			'url'         => ! empty( $item['slug'] ) ? 'https://rawg.io/games/' . $item['slug'] : '',
			'source'      => 'rawg',
		);
	}

	/**
	 * Strip HTML tags from description.
	 *
	 * @param string $html HTML string.
	 * @return string Plain text.
	 */
	private function strip_html( string $html ): string {
		// Remove HTML tags but preserve line breaks.
		$text = preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$text = preg_replace( '/<\/p>/i', "\n\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5 );
		return trim( $text );
	}

	/**
	 * Make GET request to API.
	 *
	 * @param string               $endpoint API endpoint.
	 * @param array<string, mixed> $params   Query parameters.
	 * @return array<string, mixed>|\WP_Error Response data or error.
	 */
	public function get( string $endpoint, array $params = array() ) {
		$url = $this->base_url . $endpoint;

		if ( ! empty( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$this->rate_limit();

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => $this->timeout,
				'user-agent' => $this->user_agent,
				'headers'    => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'rawg_api_error',
				sprintf( 'RAWG API returned status %d', $code )
			);
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'rawg_json_error',
				'Failed to parse RAWG API response'
			);
		}

		return $data;
	}

	/**
	 * Get games by platform.
	 *
	 * @param int $platform_id Platform ID.
	 * @param int $page        Page number.
	 * @param int $per_page    Results per page.
	 * @return array<int, array<string, mixed>> Games.
	 */
	public function get_by_platform( int $platform_id, int $page = 1, int $per_page = 20 ): array {
		if ( ! $this->is_configured() ) {
			return array();
		}

		$response = $this->get(
			'games',
			array(
				'key'       => $this->get_api_key(),
				'platforms' => $platform_id,
				'page'      => $page,
				'page_size' => $per_page,
				'ordering'  => '-rating',
			)
		);

		if ( is_wp_error( $response ) || empty( $response['results'] ) ) {
			return array();
		}

		return array_map(
			function( $item ) {
				return $this->normalize_search_result( $item );
			},
			$response['results']
		);
	}

	/**
	 * Get available platforms.
	 *
	 * @return array<int, array<string, mixed>> Platforms.
	 */
	public function get_platforms(): array {
		if ( ! $this->is_configured() ) {
			return array();
		}

		$cache_key = $this->get_cache_key( 'platforms', array() );
		$cached    = $this->get_cache( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->get(
			'platforms',
			array(
				'key'       => $this->get_api_key(),
				'page_size' => 50,
			)
		);

		if ( is_wp_error( $response ) || empty( $response['results'] ) ) {
			return array();
		}

		$platforms = array_map(
			function( $platform ) {
				return array(
					'id'   => $platform['id'],
					'name' => $platform['name'],
					'slug' => $platform['slug'],
				);
			},
			$response['results']
		);

		$this->set_cache( $cache_key, $platforms, MONTH_IN_SECONDS );

		return $platforms;
	}

	/**
	 * Get documentation URL.
	 *
	 * @return string Documentation URL.
	 */
	public function get_docs_url(): string {
		return 'https://rawg.io/apidocs';
	}

	/**
	 * Get signup URL.
	 *
	 * @return string Signup URL.
	 */
	public function get_signup_url(): string {
		return 'https://rawg.io/apidocs';
	}
}

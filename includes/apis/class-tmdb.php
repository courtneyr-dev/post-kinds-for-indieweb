<?php
/**
 * TMDB (The Movie Database) API Integration
 *
 * Provides movie and TV show metadata from TMDB.
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\APIs;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TMDB API class.
 *
 * @since 1.0.0
 */
class TMDB extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'tmdb';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.themoviedb.org/3/';

	/**
	 * Image base URL.
	 *
	 * @var string
	 */
	private string $image_base_url = 'https://image.tmdb.org/t/p/';

	/**
	 * Rate limit: 40 requests per 10 seconds.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.25;

	/**
	 * Cache duration: 1 week.
	 *
	 * @var int
	 */
	protected int $cache_duration = WEEK_IN_SECONDS;

	/**
	 * API key.
	 *
	 * @var string|null
	 */
	private ?string $api_key = null;

	/**
	 * API read access token (v4).
	 *
	 * @var string|null
	 */
	private ?string $access_token = null;

	/**
	 * Default language.
	 *
	 * @var string
	 */
	private string $language = 'en-US';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials        = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$tmdb_creds         = $credentials['tmdb'] ?? [];
		$this->api_key      = $tmdb_creds['api_key'] ?? '';
		$this->access_token = $tmdb_creds['access_token'] ?? '';
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		$headers = [
			'Accept' => 'application/json',
		];

		if ( $this->access_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->access_token;
		}

		return $headers;
	}

	/**
	 * Build URL with API key.
	 *
	 * @param string               $endpoint Endpoint.
	 * @param array<string, mixed> $params   Parameters.
	 * @return string Full URL.
	 */
	protected function build_url( string $endpoint, array $params = [] ): string {
		// Add API key if not using access token.
		if ( ! $this->access_token && $this->api_key ) {
			$params['api_key'] = $this->api_key;
		}

		// Add default language.
		if ( ! isset( $params['language'] ) ) {
			$params['language'] = $this->language;
		}

		$url = $this->base_url . ltrim( $endpoint, '/' );

		if ( ! empty( $params ) ) {
			$url .= '?' . http_build_query( $params );
		}

		return $url;
	}

	/**
	 * Make API request.
	 *
	 * @param string               $endpoint Endpoint.
	 * @param array<string, mixed> $params   Parameters.
	 * @return array<string, mixed> Response.
	 * @throws \Exception On error.
	 */
	private function api_get( string $endpoint, array $params = [] ): array {
		$url = $this->build_url( $endpoint, $params );

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 30,
				'headers' => $this->get_default_headers(),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			$message = $data['status_message'] ?? 'API error';
			throw new \Exception( esc_html( $message ), (int) $code );
		}

		return $data ?? [];
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 * @throws \Exception If credentials are missing or API request fails.
	 */
	public function test_connection(): bool {
		if ( ! $this->api_key && ! $this->access_token ) {
			// Debug: show what credentials we actually have.
			$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
			$tmdb_creds  = $credentials['tmdb'] ?? [];
			$has_token   = ! empty( $tmdb_creds['access_token'] );
			$has_key     = ! empty( $tmdb_creds['api_key'] );
			$is_enabled  = ! empty( $tmdb_creds['enabled'] );

			throw new \Exception(
				sprintf(
					/* translators: 1: enabled status, 2: has token status, 3: has key status */
					__( 'No API credentials loaded. Debug: enabled=%1$s, has_token=%2$s, has_key=%3$s', 'post-kinds-for-indieweb' ),
					$is_enabled ? 'yes' : 'no',
					$has_token ? 'yes' : 'no',
					$has_key ? 'yes' : 'no'
				)
			);
		}

		try {
			$this->api_get( 'configuration' );
			return true;
		} catch ( \Exception $e ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: Error message */
					__( 'API request failed: %s', 'post-kinds-for-indieweb' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Multi-search (movies, TV, people).
	 *
	 * @param string      $query Search query.
	 * @param string|null $type  Optional type filter: movie, tv, person.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$type = $args[0] ?? null;

		$cache_key = 'search_' . md5( $query . ( $type ?? 'multi' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$endpoint = $type ? "search/{$type}" : 'search/multi';

			$response = $this->api_get( $endpoint, [ 'query' => $query ] );

			$results = [];

			if ( isset( $response['results'] ) && is_array( $response['results'] ) ) {
				foreach ( $response['results'] as $item ) {
					$normalized = $this->normalize_result( $item );
					if ( $normalized ) {
						$results[] = $normalized;
					}
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Search failed',
				[
					'query' => $query,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Search movies.
	 *
	 * @param string $query Search query.
	 * @param int    $year  Optional release year.
	 * @return array<int, array<string, mixed>> Movie results.
	 */
	public function search_movies( string $query, int $year = 0 ): array {
		$cache_key = 'movie_search_' . md5( $query . $year );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = [ 'query' => $query ];

			if ( $year > 0 ) {
				$params['year'] = $year;
			}

			$response = $this->api_get( 'search/movie', $params );

			$results = [];

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $movie ) {
					$results[] = $this->normalize_movie( $movie );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Movie search failed',
				[
					'query' => $query,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Search TV shows.
	 *
	 * @param string $query Search query.
	 * @param int    $year  Optional first air date year.
	 * @return array<int, array<string, mixed>> TV show results.
	 */
	public function search_tv( string $query, int $year = 0 ): array {
		$cache_key = 'tv_search_' . md5( $query . $year );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = [ 'query' => $query ];

			if ( $year > 0 ) {
				$params['first_air_date_year'] = $year;
			}

			$response = $this->api_get( 'search/tv', $params );

			$results = [];

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $show ) {
					$results[] = $this->normalize_tv( $show );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'TV search failed',
				[
					'query' => $query,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get movie or TV by ID.
	 *
	 * @param string $id ID in format "movie:123" or "tv:456".
	 * @return array<string, mixed>|null Result.
	 */
	public function get_by_id( string $id ): ?array {
		if ( strpos( $id, ':' ) !== false ) {
			list( $type, $tmdb_id ) = explode( ':', $id, 2 );

			if ( 'movie' === $type ) {
				return $this->get_movie( (int) $tmdb_id );
			} elseif ( 'tv' === $type ) {
				return $this->get_tv( (int) $tmdb_id );
			}
		}

		// Assume movie if no prefix.
		return $this->get_movie( (int) $id );
	}

	/**
	 * Get movie details.
	 *
	 * @param int $id TMDB movie ID.
	 * @return array<string, mixed>|null Movie data.
	 */
	public function get_movie( int $id ): ?array {
		$cache_key = 'movie_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				"movie/{$id}",
				[ 'append_to_response' => 'credits,external_ids,videos,watch/providers' ]
			);

			$result = $this->normalize_movie( $response, true );

			// Add credits.
			if ( isset( $response['credits'] ) ) {
				$result['cast']     = $this->normalize_cast( $response['credits']['cast'] ?? [] );
				$result['crew']     = $this->normalize_crew( $response['credits']['crew'] ?? [] );
				$result['director'] = $this->get_director( $response['credits']['crew'] ?? [] );
			}

			// Add external IDs.
			if ( isset( $response['external_ids'] ) ) {
				$result['imdb_id']  = $response['external_ids']['imdb_id'] ?? '';
				$result['wikidata'] = $response['external_ids']['wikidata_id'] ?? '';
			}

			// Add trailer.
			if ( isset( $response['videos']['results'] ) ) {
				$result['trailer'] = $this->get_trailer( $response['videos']['results'] );
			}

			// Add watch providers.
			if ( isset( $response['watch/providers']['results'] ) ) {
				$result['watch_providers'] = $response['watch/providers']['results'];
			}

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get movie failed',
				[
					'id'    => $id,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get TV show details.
	 *
	 * @param int $id TMDB TV show ID.
	 * @return array<string, mixed>|null TV show data.
	 */
	public function get_tv( int $id ): ?array {
		$cache_key = 'tv_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				"tv/{$id}",
				[ 'append_to_response' => 'credits,external_ids,videos,watch/providers' ]
			);

			$result = $this->normalize_tv( $response, true );

			// Add credits.
			if ( isset( $response['credits'] ) ) {
				$result['cast'] = $this->normalize_cast( $response['credits']['cast'] ?? [] );
				$result['crew'] = $this->normalize_crew( $response['credits']['crew'] ?? [] );
			}

			// Add creators.
			if ( isset( $response['created_by'] ) ) {
				$result['creators'] = array_map(
					function ( $creator ) {
						return [
							'id'    => $creator['id'],
							'name'  => $creator['name'],
							'image' => $this->get_image_url( $creator['profile_path'] ?? '', 'w185' ),
						];
					},
					$response['created_by']
				);
			}

			// Add external IDs.
			if ( isset( $response['external_ids'] ) ) {
				$result['imdb_id']  = $response['external_ids']['imdb_id'] ?? '';
				$result['tvdb_id']  = $response['external_ids']['tvdb_id'] ?? '';
				$result['wikidata'] = $response['external_ids']['wikidata_id'] ?? '';
			}

			// Add trailer.
			if ( isset( $response['videos']['results'] ) ) {
				$result['trailer'] = $this->get_trailer( $response['videos']['results'] );
			}

			// Add watch providers.
			if ( isset( $response['watch/providers']['results'] ) ) {
				$result['watch_providers'] = $response['watch/providers']['results'];
			}

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get TV failed',
				[
					'id'    => $id,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get TV season details.
	 *
	 * @param int $tv_id        TV show ID.
	 * @param int $season_number Season number.
	 * @return array<string, mixed>|null Season data.
	 */
	public function get_season( int $tv_id, int $season_number ): ?array {
		$cache_key = "tv_{$tv_id}_season_{$season_number}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( "tv/{$tv_id}/season/{$season_number}" );

			$result = [
				'id'            => $response['id'] ?? 0,
				'name'          => $response['name'] ?? '',
				'overview'      => $response['overview'] ?? '',
				'poster'        => $this->get_image_url( $response['poster_path'] ?? '', 'w342' ),
				'air_date'      => $response['air_date'] ?? '',
				'season_number' => $response['season_number'] ?? $season_number,
				'episode_count' => count( $response['episodes'] ?? [] ),
				'episodes'      => [],
				'type'          => 'season',
				'source'        => 'tmdb',
			];

			if ( isset( $response['episodes'] ) ) {
				foreach ( $response['episodes'] as $episode ) {
					$result['episodes'][] = $this->normalize_episode( $episode, $tv_id );
				}
			}

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get season failed',
				[
					'tv_id'  => $tv_id,
					'season' => $season_number,
					'error'  => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get TV episode details.
	 *
	 * @param int $tv_id          TV show ID.
	 * @param int $season_number  Season number.
	 * @param int $episode_number Episode number.
	 * @return array<string, mixed>|null Episode data.
	 */
	public function get_episode( int $tv_id, int $season_number, int $episode_number ): ?array {
		$cache_key = "tv_{$tv_id}_s{$season_number}_e{$episode_number}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get(
				"tv/{$tv_id}/season/{$season_number}/episode/{$episode_number}",
				[ 'append_to_response' => 'credits' ]
			);

			$result = $this->normalize_episode( $response, $tv_id );

			// Add episode-specific credits.
			if ( isset( $response['credits'] ) ) {
				$result['guest_stars'] = $this->normalize_cast( $response['credits']['guest_stars'] ?? [] );
				$result['crew']        = $this->normalize_crew( $response['credits']['crew'] ?? [] );
			}

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get episode failed',
				[
					'tv_id'   => $tv_id,
					'season'  => $season_number,
					'episode' => $episode_number,
					'error'   => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get popular movies.
	 *
	 * @param int $page Page number.
	 * @return array<int, array<string, mixed>> Movies.
	 */
	public function get_popular_movies( int $page = 1 ): array {
		$cache_key = 'popular_movies_' . $page;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( 'movie/popular', [ 'page' => $page ] );

			$results = [];

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $movie ) {
					$results[] = $this->normalize_movie( $movie );
				}
			}

			$this->set_cache( $cache_key, $results, DAY_IN_SECONDS );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get popular movies failed', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}

	/**
	 * Get popular TV shows.
	 *
	 * @param int $page Page number.
	 * @return array<int, array<string, mixed>> TV shows.
	 */
	public function get_popular_tv( int $page = 1 ): array {
		$cache_key = 'popular_tv_' . $page;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( 'tv/popular', [ 'page' => $page ] );

			$results = [];

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $show ) {
					$results[] = $this->normalize_tv( $show );
				}
			}

			$this->set_cache( $cache_key, $results, DAY_IN_SECONDS );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get popular TV failed', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}

	/**
	 * Get trending content.
	 *
	 * @param string $type   Content type: movie, tv, all.
	 * @param string $window Time window: day, week.
	 * @return array<int, array<string, mixed>> Trending items.
	 */
	public function get_trending( string $type = 'all', string $window = 'week' ): array {
		$cache_key = "trending_{$type}_{$window}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( "trending/{$type}/{$window}" );

			$results = [];

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $item ) {
					$normalized = $this->normalize_result( $item );
					if ( $normalized ) {
						$results[] = $normalized;
					}
				}
			}

			$this->set_cache( $cache_key, $results, 'day' === $window ? HOUR_IN_SECONDS * 6 : DAY_IN_SECONDS );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get trending failed',
				[
					'type'  => $type,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get movie genres.
	 *
	 * @return array<int, array<string, mixed>> Genres.
	 */
	public function get_movie_genres(): array {
		$cache_key = 'movie_genres';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( 'genre/movie/list' );

			$genres = $response['genres'] ?? [];

			$this->set_cache( $cache_key, $genres, MONTH_IN_SECONDS );

			return $genres;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get movie genres failed', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}

	/**
	 * Get TV genres.
	 *
	 * @return array<int, array<string, mixed>> Genres.
	 */
	public function get_tv_genres(): array {
		$cache_key = 'tv_genres';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( 'genre/tv/list' );

			$genres = $response['genres'] ?? [];

			$this->set_cache( $cache_key, $genres, MONTH_IN_SECONDS );

			return $genres;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get TV genres failed', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}

	/**
	 * Discover movies.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>> Movies.
	 */
	public function discover_movies( array $filters = [] ): array {
		$cache_key = 'discover_movies_' . md5( wp_json_encode( $filters ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->api_get( 'discover/movie', $filters );

			$results = [];

			if ( isset( $response['results'] ) ) {
				foreach ( $response['results'] as $movie ) {
					$results[] = $this->normalize_movie( $movie );
				}
			}

			$this->set_cache( $cache_key, $results, DAY_IN_SECONDS );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Discover movies failed',
				[
					'filters' => $filters,
					'error'   => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Normalize search result.
	 *
	 * @param array<string, mixed> $raw_result Raw result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		$media_type = $raw_result['media_type'] ?? 'movie';

		if ( 'movie' === $media_type ) {
			return $this->normalize_movie( $raw_result );
		} elseif ( 'tv' === $media_type ) {
			return $this->normalize_tv( $raw_result );
		} elseif ( 'person' === $media_type ) {
			return $this->normalize_person( $raw_result );
		}

		return [];
	}

	/**
	 * Normalize movie data.
	 *
	 * @param array<string, mixed> $movie    Movie data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized movie.
	 */
	private function normalize_movie( array $movie, bool $detailed = false ): array {
		$result = [
			'id'             => $movie['id'] ?? 0,
			'tmdb_id'        => $movie['id'] ?? 0,
			'title'          => $movie['title'] ?? '',
			'original_title' => $movie['original_title'] ?? '',
			'overview'       => $movie['overview'] ?? '',
			'poster'         => $this->get_image_url( $movie['poster_path'] ?? '', 'w342' ),
			'backdrop'       => $this->get_image_url( $movie['backdrop_path'] ?? '', 'w1280' ),
			'release_date'   => $movie['release_date'] ?? '',
			'year'           => $movie['release_date'] ? substr( $movie['release_date'], 0, 4 ) : '',
			'vote_average'   => $movie['vote_average'] ?? 0,
			'vote_count'     => $movie['vote_count'] ?? 0,
			'popularity'     => $movie['popularity'] ?? 0,
			'type'           => 'movie',
			'source'         => 'tmdb',
		];

		if ( $detailed ) {
			$result['runtime']  = $movie['runtime'] ?? null;
			$result['tagline']  = $movie['tagline'] ?? '';
			$result['status']   = $movie['status'] ?? '';
			$result['budget']   = $movie['budget'] ?? 0;
			$result['revenue']  = $movie['revenue'] ?? 0;
			$result['homepage'] = $movie['homepage'] ?? '';

			$result['genres'] = array_map(
				function ( $genre ) {
					return $genre['name'];
				},
				$movie['genres'] ?? []
			);

			$result['production_companies'] = array_map(
				function ( $company ) {
					return [
						'id'   => $company['id'],
						'name' => $company['name'],
						'logo' => $this->get_image_url( $company['logo_path'] ?? '', 'w92' ),
					];
				},
				$movie['production_companies'] ?? []
			);

			$result['spoken_languages'] = array_map(
				function ( $lang ) {
					return $lang['english_name'] ?? $lang['name'];
				},
				$movie['spoken_languages'] ?? []
			);
		} else {
			$result['genre_ids'] = $movie['genre_ids'] ?? [];
		}

		return $result;
	}

	/**
	 * Normalize TV show data.
	 *
	 * @param array<string, mixed> $show     TV show data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized TV show.
	 */
	private function normalize_tv( array $show, bool $detailed = false ): array {
		$result = [
			'id'             => $show['id'] ?? 0,
			'tmdb_id'        => $show['id'] ?? 0,
			'title'          => $show['name'] ?? '',
			'original_title' => $show['original_name'] ?? '',
			'overview'       => $show['overview'] ?? '',
			'poster'         => $this->get_image_url( $show['poster_path'] ?? '', 'w342' ),
			'backdrop'       => $this->get_image_url( $show['backdrop_path'] ?? '', 'w1280' ),
			'first_air_date' => $show['first_air_date'] ?? '',
			'year'           => $show['first_air_date'] ? substr( $show['first_air_date'], 0, 4 ) : '',
			'vote_average'   => $show['vote_average'] ?? 0,
			'vote_count'     => $show['vote_count'] ?? 0,
			'popularity'     => $show['popularity'] ?? 0,
			'type'           => 'tv',
			'source'         => 'tmdb',
		];

		if ( $detailed ) {
			$result['last_air_date']      = $show['last_air_date'] ?? '';
			$result['tagline']            = $show['tagline'] ?? '';
			$result['status']             = $show['status'] ?? '';
			$result['homepage']           = $show['homepage'] ?? '';
			$result['in_production']      = $show['in_production'] ?? false;
			$result['number_of_seasons']  = $show['number_of_seasons'] ?? 0;
			$result['number_of_episodes'] = $show['number_of_episodes'] ?? 0;
			$result['episode_run_time']   = $show['episode_run_time'] ?? [];

			$result['genres'] = array_map(
				function ( $genre ) {
					return $genre['name'];
				},
				$show['genres'] ?? []
			);

			$result['networks'] = array_map(
				function ( $network ) {
					return [
						'id'   => $network['id'],
						'name' => $network['name'],
						'logo' => $this->get_image_url( $network['logo_path'] ?? '', 'w92' ),
					];
				},
				$show['networks'] ?? []
			);

			// Normalize seasons.
			$result['seasons'] = [];
			if ( isset( $show['seasons'] ) ) {
				foreach ( $show['seasons'] as $season ) {
					$result['seasons'][] = [
						'id'            => $season['id'] ?? 0,
						'name'          => $season['name'] ?? '',
						'season_number' => $season['season_number'] ?? 0,
						'episode_count' => $season['episode_count'] ?? 0,
						'air_date'      => $season['air_date'] ?? '',
						'poster'        => $this->get_image_url( $season['poster_path'] ?? '', 'w185' ),
					];
				}
			}

			// Last episode.
			if ( isset( $show['last_episode_to_air'] ) ) {
				$result['last_episode'] = $this->normalize_episode( $show['last_episode_to_air'], $show['id'] );
			}

			// Next episode.
			if ( isset( $show['next_episode_to_air'] ) ) {
				$result['next_episode'] = $this->normalize_episode( $show['next_episode_to_air'], $show['id'] );
			}
		} else {
			$result['genre_ids'] = $show['genre_ids'] ?? [];
		}

		return $result;
	}

	/**
	 * Normalize episode data.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param int                  $tv_id   TV show ID.
	 * @return array<string, mixed> Normalized episode.
	 */
	private function normalize_episode( array $episode, int $tv_id ): array {
		return [
			'id'             => $episode['id'] ?? 0,
			'tv_id'          => $tv_id,
			'name'           => $episode['name'] ?? '',
			'overview'       => $episode['overview'] ?? '',
			'still'          => $this->get_image_url( $episode['still_path'] ?? '', 'w300' ),
			'air_date'       => $episode['air_date'] ?? '',
			'episode_number' => $episode['episode_number'] ?? 0,
			'season_number'  => $episode['season_number'] ?? 0,
			'runtime'        => $episode['runtime'] ?? null,
			'vote_average'   => $episode['vote_average'] ?? 0,
			'vote_count'     => $episode['vote_count'] ?? 0,
			'type'           => 'episode',
			'source'         => 'tmdb',
		];
	}

	/**
	 * Normalize person data.
	 *
	 * @param array<string, mixed> $person Person data.
	 * @return array<string, mixed> Normalized person.
	 */
	private function normalize_person( array $person ): array {
		return [
			'id'                   => $person['id'] ?? 0,
			'name'                 => $person['name'] ?? '',
			'image'                => $this->get_image_url( $person['profile_path'] ?? '', 'w185' ),
			'known_for_department' => $person['known_for_department'] ?? '',
			'popularity'           => $person['popularity'] ?? 0,
			'type'                 => 'person',
			'source'               => 'tmdb',
		];
	}

	/**
	 * Normalize cast list.
	 *
	 * @param array<int, array<string, mixed>> $cast Cast array.
	 * @param int                              $limit Max entries.
	 * @return array<int, array<string, mixed>> Normalized cast.
	 */
	private function normalize_cast( array $cast, int $limit = 15 ): array {
		$result = [];

		foreach ( array_slice( $cast, 0, $limit ) as $member ) {
			$result[] = [
				'id'        => $member['id'] ?? 0,
				'name'      => $member['name'] ?? '',
				'character' => $member['character'] ?? '',
				'image'     => $this->get_image_url( $member['profile_path'] ?? '', 'w185' ),
				'order'     => $member['order'] ?? 0,
			];
		}

		return $result;
	}

	/**
	 * Normalize crew list.
	 *
	 * @param array<int, array<string, mixed>> $crew Crew array.
	 * @return array<int, array<string, mixed>> Normalized crew.
	 */
	private function normalize_crew( array $crew ): array {
		$result = [];
		$seen   = [];

		// Prioritize key roles.
		$priority_jobs = [ 'Director', 'Writer', 'Screenplay', 'Producer', 'Executive Producer', 'Composer', 'Director of Photography' ];

		usort(
			$crew,
			function ( $a, $b ) use ( $priority_jobs ) {
				$a_priority = array_search( $a['job'] ?? '', $priority_jobs, true );
				$b_priority = array_search( $b['job'] ?? '', $priority_jobs, true );

				$a_priority = false === $a_priority ? 999 : $a_priority;
				$b_priority = false === $b_priority ? 999 : $b_priority;

				return $a_priority - $b_priority;
			}
		);

		foreach ( $crew as $member ) {
			$id  = $member['id'] ?? 0;
			$job = $member['job'] ?? '';

			// Skip duplicates.
			$key = "{$id}_{$job}";
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			if ( count( $result ) >= 20 ) {
				break;
			}

			$result[] = [
				'id'         => $id,
				'name'       => $member['name'] ?? '',
				'job'        => $job,
				'department' => $member['department'] ?? '',
				'image'      => $this->get_image_url( $member['profile_path'] ?? '', 'w185' ),
			];
		}

		return $result;
	}

	/**
	 * Get director from crew.
	 *
	 * @param array<int, array<string, mixed>> $crew Crew array.
	 * @return string|null Director name.
	 */
	private function get_director( array $crew ): ?string {
		foreach ( $crew as $member ) {
			if ( 'Director' === ( $member['job'] ?? '' ) ) {
				return $member['name'] ?? null;
			}
		}

		return null;
	}

	/**
	 * Get trailer from videos.
	 *
	 * @param array<int, array<string, mixed>> $videos Videos array.
	 * @return array<string, mixed>|null Trailer data.
	 */
	private function get_trailer( array $videos ): ?array {
		// Prefer official YouTube trailers.
		foreach ( $videos as $video ) {
			if ( 'Trailer' === ( $video['type'] ?? '' ) && 'YouTube' === ( $video['site'] ?? '' ) && ( $video['official'] ?? false ) ) {
				return [
					'key'  => $video['key'],
					'name' => $video['name'],
					'url'  => 'https://www.youtube.com/watch?v=' . $video['key'],
				];
			}
		}

		// Fallback to any YouTube trailer.
		foreach ( $videos as $video ) {
			if ( 'Trailer' === ( $video['type'] ?? '' ) && 'YouTube' === ( $video['site'] ?? '' ) ) {
				return [
					'key'  => $video['key'],
					'name' => $video['name'],
					'url'  => 'https://www.youtube.com/watch?v=' . $video['key'],
				];
			}
		}

		return null;
	}

	/**
	 * Get full image URL.
	 *
	 * @param string $path Image path.
	 * @param string $size Image size.
	 * @return string|null Full URL or null.
	 */
	private function get_image_url( string $path, string $size = 'w342' ): ?string {
		if ( empty( $path ) ) {
			return null;
		}

		return $this->image_base_url . $size . $path;
	}

	/**
	 * Set API key.
	 *
	 * @param string $key API key.
	 * @return void
	 */
	public function set_api_key( string $key ): void {
		$this->api_key = $key;
	}

	/**
	 * Set language.
	 *
	 * @param string $language Language code.
	 * @return void
	 */
	public function set_language( string $language ): void {
		$this->language = $language;
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://developer.themoviedb.org/docs';
	}
}

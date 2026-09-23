<?php
/**
 * TMDB (The Movie Database) API Integration
 *
 * Provides movie and TV show metadata from TMDB.
 *
 * @package PKIW
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PKIW\APIs;

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
		$credentials        = get_option( 'pkiw_api_credentials', [] );
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
			$credentials = get_option( 'pkiw_api_credentials', [] );
			$tmdb_creds  = $credentials['tmdb'] ?? [];
			$has_token   = ! empty( $tmdb_creds['access_token'] );
			$has_key     = ! empty( $tmdb_creds['api_key'] );
			$is_enabled  = ! empty( $tmdb_creds['enabled'] );

			throw new \Exception(
				sprintf(
					/* translators: 1: enabled status, 2: has token status, 3: has key status */
					esc_html__( 'No API credentials loaded. Debug: enabled=%1$s, has_token=%2$s, has_key=%3$s', 'post-kinds-for-indieweb-in-block-themes' ),
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
					esc_html__( 'API request failed: %s', 'post-kinds-for-indieweb-in-block-themes' ),
					esc_html( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Multi-search (movies, TV, people).
	 *
	 * @param string $query   Search query.
	 * @param mixed  ...$args Additional arguments; `$args[0]` is an optional type filter ('movie', 'tv', or 'person').
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
					// Type-specific endpoints (search/tv, search/movie) omit
					// media_type — only search/multi includes it. Without this,
					// TV results fall through to the movie normalizer and come
					// back with empty titles.
					if ( $type && ! isset( $item['media_type'] ) ) {
						$item['media_type'] = $type;
					}
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

			// normalize_movie() sanitizes its own fields, but cast, crew,
			// director, trailer and watch_providers are merged in after
			// it returns, so they need their own pass (review round 2,
			// BGG-class audit finding — merged-in fields bypassed
			// sanitization even after the round-1 TMDB fix).
			$result = $this->sanitize_normalized_result( $result, [ 'poster', 'backdrop', 'url' ] );

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

			// normalize_tv() sanitizes its own fields, but cast, crew,
			// creators, trailer and watch_providers are merged in after
			// it returns, so they need their own pass (review round 2,
			// BGG-class audit finding).
			$result = $this->sanitize_normalized_result( $result, [ 'poster', 'backdrop', 'url', 'image' ] );

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

			// get_season() builds its own top-level fields inline and
			// never went through any normalize_* helper (review round 2,
			// BGG-class audit finding).
			$result = $this->sanitize_normalized_result( $result, [ 'poster' ] );

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
		$url_keys   = [ 'poster', 'backdrop', 'image' ];

		if ( 'movie' === $media_type ) {
			return $this->sanitize_normalized_result( $this->normalize_movie( $raw_result ), $url_keys );
		} elseif ( 'tv' === $media_type ) {
			return $this->sanitize_normalized_result( $this->normalize_tv( $raw_result ), $url_keys );
		} elseif ( 'person' === $media_type ) {
			return $this->sanitize_normalized_result( $this->normalize_person( $raw_result ), $url_keys );
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
		$movie = wp_parse_args(
			$movie,
			[
				'id'                   => 0,
				'title'                => '',
				'original_title'       => '',
				'overview'             => '',
				'poster_path'          => '',
				'backdrop_path'        => '',
				'release_date'         => '',
				'vote_average'         => 0,
				'vote_count'           => 0,
				'popularity'           => 0,
				'runtime'              => null,
				'tagline'              => '',
				'status'               => '',
				'budget'               => 0,
				'revenue'              => 0,
				'homepage'             => '',
				'genres'               => [],
				'genre_ids'            => [],
				'production_companies' => [],
				'spoken_languages'     => [],
			]
		);

		$result = [
			'id'             => $movie['id'],
			'tmdb_id'        => $movie['id'],
			'title'          => $movie['title'],
			'original_title' => $movie['original_title'],
			'overview'       => $movie['overview'],
			'poster'         => $this->get_image_url( $movie['poster_path'], 'w342' ),
			'backdrop'       => $this->get_image_url( $movie['backdrop_path'], 'w1280' ),
			'release_date'   => $movie['release_date'],
			'year'           => $this->get_year_from_date( $movie['release_date'] ),
			'vote_average'   => $movie['vote_average'],
			'vote_count'     => $movie['vote_count'],
			'popularity'     => $movie['popularity'],
			'type'           => 'movie',
			'source'         => 'tmdb',
		];

		if ( $detailed ) {
			$result['runtime']              = $movie['runtime'];
			$result['tagline']              = $movie['tagline'];
			$result['status']               = $movie['status'];
			$result['budget']               = $movie['budget'];
			$result['revenue']              = $movie['revenue'];
			$result['homepage']             = $movie['homepage'];
			$result['genres']               = $this->normalize_named_items( $movie['genres'] );
			$result['production_companies'] = $this->normalize_companies( $movie['production_companies'] );
			$result['spoken_languages']     = $this->normalize_language_names( $movie['spoken_languages'] );
		} else {
			$result['genre_ids'] = is_array( $movie['genre_ids'] ) ? $movie['genre_ids'] : [];
		}

		// Sanitize here, not just in normalize_result(): search_movies(),
		// get_popular_movies(), get_trending() and get_movie() all call
		// this method directly, bypassing normalize_result() (review
		// round 1, Important 2 — the admin movie lookup path returned
		// unstripped provider titles).
		return $this->sanitize_normalized_result( $result, [ 'poster', 'backdrop' ] );
	}

	/**
	 * Normalize TV show data.
	 *
	 * @param array<string, mixed> $show     TV show data.
	 * @param bool                 $detailed Whether this is detailed data.
	 * @return array<string, mixed> Normalized TV show.
	 */
	private function normalize_tv( array $show, bool $detailed = false ): array {
		$show = wp_parse_args(
			$show,
			[
				'id'                  => 0,
				'name'                => '',
				'original_name'       => '',
				'overview'            => '',
				'poster_path'         => '',
				'backdrop_path'       => '',
				'first_air_date'      => '',
				'vote_average'        => 0,
				'vote_count'          => 0,
				'popularity'          => 0,
				'last_air_date'       => '',
				'tagline'             => '',
				'status'              => '',
				'homepage'            => '',
				'in_production'       => false,
				'number_of_seasons'   => 0,
				'number_of_episodes'  => 0,
				'episode_run_time'    => [],
				'genres'              => [],
				'genre_ids'           => [],
				'networks'            => [],
				'seasons'             => [],
				'last_episode_to_air' => null,
				'next_episode_to_air' => null,
			]
		);

		$result = [
			'id'             => $show['id'],
			'tmdb_id'        => $show['id'],
			'title'          => $show['name'],
			'original_title' => $show['original_name'],
			'overview'       => $show['overview'],
			'poster'         => $this->get_image_url( $show['poster_path'], 'w342' ),
			'backdrop'       => $this->get_image_url( $show['backdrop_path'], 'w1280' ),
			'first_air_date' => $show['first_air_date'],
			'year'           => $this->get_year_from_date( $show['first_air_date'] ),
			'vote_average'   => $show['vote_average'],
			'vote_count'     => $show['vote_count'],
			'popularity'     => $show['popularity'],
			'type'           => 'tv',
			'source'         => 'tmdb',
		];

		if ( $detailed ) {
			$result['last_air_date']      = $show['last_air_date'];
			$result['tagline']            = $show['tagline'];
			$result['status']             = $show['status'];
			$result['homepage']           = $show['homepage'];
			$result['in_production']      = $show['in_production'];
			$result['number_of_seasons']  = $show['number_of_seasons'];
			$result['number_of_episodes'] = $show['number_of_episodes'];
			$result['episode_run_time']   = is_array( $show['episode_run_time'] ) ? $show['episode_run_time'] : [];
			$result['genres']             = $this->normalize_named_items( $show['genres'] );
			$result['networks']           = $this->normalize_companies( $show['networks'] );
			$result['seasons']            = $this->normalize_seasons( $show['seasons'] );

			if ( is_array( $show['last_episode_to_air'] ) ) {
				$result['last_episode'] = $this->normalize_episode( $show['last_episode_to_air'], (int) $show['id'] );
			}

			if ( is_array( $show['next_episode_to_air'] ) ) {
				$result['next_episode'] = $this->normalize_episode( $show['next_episode_to_air'], (int) $show['id'] );
			}
		} else {
			$result['genre_ids'] = is_array( $show['genre_ids'] ) ? $show['genre_ids'] : [];
		}

		// Sanitize here, not just in normalize_result(): search_tv(),
		// get_popular_tv(), get_trending() and get_tv() all call this
		// method directly, bypassing normalize_result() (review round 1,
		// Important 2).
		return $this->sanitize_normalized_result( $result, [ 'poster', 'backdrop' ] );
	}

	/**
	 * Get a year from a date string.
	 *
	 * @param string $date Date string.
	 * @return string
	 */
	private function get_year_from_date( string $date ): string {
		if ( '' === $date ) {
			return '';
		}

		return substr( $date, 0, 4 );
	}

	/**
	 * Normalize arrays of named items.
	 *
	 * @param array<int, array<string, mixed>> $items Item arrays.
	 * @return array<int, string>
	 */
	private function normalize_named_items( array $items ): array {
		return array_map(
			static function ( array $item ): string {
				return (string) ( $item['name'] ?? '' );
			},
			$items
		);
	}

	/**
	 * Normalize company-like items with logos.
	 *
	 * @param array<int, array<string, mixed>> $items Item arrays.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_companies( array $items ): array {
		return array_map(
			function ( array $item ): array {
				return [
					'id'   => $item['id'] ?? 0,
					'name' => $item['name'] ?? '',
					'logo' => $this->get_image_url( $item['logo_path'] ?? '', 'w92' ),
				];
			},
			$items
		);
	}

	/**
	 * Normalize language names.
	 *
	 * @param array<int, array<string, mixed>> $languages Language arrays.
	 * @return array<int, string>
	 */
	private function normalize_language_names( array $languages ): array {
		return array_map(
			static function ( array $language ): string {
				if ( ! empty( $language['english_name'] ) ) {
					return (string) $language['english_name'];
				}

				return (string) ( $language['name'] ?? '' );
			},
			$languages
		);
	}

	/**
	 * Normalize season data.
	 *
	 * @param array<int, array<string, mixed>> $seasons Season arrays.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_seasons( array $seasons ): array {
		$result = [];

		foreach ( $seasons as $season ) {
			$result[] = [
				'id'            => $season['id'] ?? 0,
				'name'          => $season['name'] ?? '',
				'season_number' => $season['season_number'] ?? 0,
				'episode_count' => $season['episode_count'] ?? 0,
				'air_date'      => $season['air_date'] ?? '',
				'poster'        => $this->get_image_url( $season['poster_path'] ?? '', 'w185' ),
			];
		}

		// Sanitize here: normalize_tv()'s detailed branch merges this in
		// after its own sanitize call already ran (review round 2,
		// BGG-class audit finding).
		return $this->sanitize_normalized_result( $result, [ 'poster' ] );
	}

	/**
	 * Normalize episode data.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @param int                  $tv_id   TV show ID.
	 * @return array<string, mixed> Normalized episode.
	 */
	private function normalize_episode( array $episode, int $tv_id ): array {
		// Sanitize here: get_season(), get_episode() and normalize_tv()'s
		// last_episode/next_episode call this method directly, never
		// normalize_result() (review round 2, BGG-class audit finding).
		return $this->sanitize_normalized_result(
			[
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
			],
			[ 'still' ]
		);
	}

	/**
	 * Normalize person data.
	 *
	 * @param array<string, mixed> $person Person data.
	 * @return array<string, mixed> Normalized person.
	 */
	private function normalize_person( array $person ): array {
		return $this->sanitize_normalized_result(
			[
				'id'                   => $person['id'] ?? 0,
				'name'                 => $person['name'] ?? '',
				'image'                => $this->get_image_url( $person['profile_path'] ?? '', 'w185' ),
				'known_for_department' => $person['known_for_department'] ?? '',
				'popularity'           => $person['popularity'] ?? 0,
				'type'                 => 'person',
				'source'               => 'tmdb',
			],
			[ 'image' ]
		);
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

		// Sanitize here: get_movie()/get_tv()/get_episode() call this
		// method directly and merge its output in after normalize_movie()/
		// normalize_tv() already sanitized theirs (review round 2,
		// BGG-class audit finding).
		return $this->sanitize_normalized_result( $result, [ 'image' ] );
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

		// Sanitize here: get_movie()/get_tv()/get_episode() call this
		// method directly (review round 2, BGG-class audit finding).
		return $this->sanitize_normalized_result( $result, [ 'image' ] );
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

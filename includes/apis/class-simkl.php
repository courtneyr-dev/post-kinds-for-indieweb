<?php
/**
 * Simkl API Integration
 *
 * Provides watch history and tracking from Simkl.
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
 * Simkl API class.
 *
 * @since 1.0.0
 */
class Simkl extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'simkl';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.simkl.com/';

	/**
	 * Rate limit: 100 requests per minute.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.6;

	/**
	 * Cache duration: 1 hour.
	 *
	 * @var int
	 */
	protected int $cache_duration = HOUR_IN_SECONDS;

	/**
	 * Client ID.
	 *
	 * @var string|null
	 */
	private ?string $client_id = null;

	/**
	 * Client secret.
	 *
	 * @var string|null
	 */
	private ?string $client_secret = null;

	/**
	 * Access token.
	 *
	 * @var string|null
	 */
	private ?string $access_token = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials         = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$simkl_creds         = $credentials['simkl'] ?? [];
		$this->client_id     = $simkl_creds['client_id'] ?? '';
		$this->client_secret = $simkl_creds['client_secret'] ?? '';
		$this->access_token  = $simkl_creds['access_token'] ?? '';
	}

	/**
	 * Check if API is configured with valid credentials.
	 *
	 * @return bool True if configured.
	 */
	public function is_configured(): bool {
		return ! empty( $this->client_id ) && ! empty( $this->access_token );
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		$headers = [
			'Content-Type'  => 'application/json',
			'simkl-api-key' => $this->client_id ?? '',
		];

		if ( $this->access_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->access_token;
		}

		return $headers;
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		if ( ! $this->client_id ) {
			return false;
		}

		try {
			$this->get(
				'search/movie',
				[
					'q'     => 'test',
					'limit' => 1,
				]
			);
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check if authenticated.
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool {
		return ! empty( $this->access_token );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @return string Authorization URL.
	 */
	public function get_authorization_url( string $redirect_uri ): string {
		return 'https://simkl.com/oauth/authorize?' . http_build_query(
			[
				'response_type' => 'code',
				'client_id'     => $this->client_id,
				'redirect_uri'  => $redirect_uri,
			]
		);
	}

	/**
	 * Exchange authorization code for token.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri Redirect URI.
	 * @return array<string, mixed>|null Token data.
	 */
	public function exchange_code( string $code, string $redirect_uri ): ?array {
		try {
			$response = wp_remote_post(
				'https://api.simkl.com/oauth/token',
				[
					'timeout' => 30,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode(
						[
							'code'          => $code,
							'client_id'     => $this->client_id,
							'client_secret' => $this->client_secret,
							'redirect_uri'  => $redirect_uri,
							'grant_type'    => 'authorization_code',
						]
					),
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new \Exception( esc_html( $response->get_error_message() ) );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['access_token'] ) ) {
				$this->access_token = $body['access_token'];

				return [
					'access_token' => $body['access_token'],
				];
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Token exchange failed', [ 'error' => $e->getMessage() ] );
			return null;
		}
	}

	/**
	 * Search for movies, shows, or anime.
	 *
	 * @param string      $query Search query.
	 * @param string|null $type  Type: movie, tv, anime.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$type = $args[0] ?? null;

		$cache_key = 'search_' . md5( $query . ( $type ?? 'all' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$endpoint = 'search/' . ( $type ?? 'movie,tv,anime' );

			$response = $this->get(
				$endpoint,
				[
					'q'     => $query,
					'limit' => 25,
				]
			);

			$results = [];

			foreach ( $response as $item ) {
				$results[] = $this->normalize_result( $item );
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
	 * @return array<int, array<string, mixed>> Movie results.
	 */
	public function search_movies( string $query ): array {
		return $this->search( $query, 'movie' );
	}

	/**
	 * Search TV shows.
	 *
	 * @param string $query Search query.
	 * @return array<int, array<string, mixed>> Show results.
	 */
	public function search_tv( string $query ): array {
		return $this->search( $query, 'tv' );
	}

	/**
	 * Search anime.
	 *
	 * @param string $query Search query.
	 * @return array<int, array<string, mixed>> Anime results.
	 */
	public function search_anime( string $query ): array {
		return $this->search( $query, 'anime' );
	}

	/**
	 * Get by Simkl ID.
	 *
	 * @param string $id ID in format "movie:123" or "tv:456".
	 * @return array<string, mixed>|null Result.
	 */
	public function get_by_id( string $id ): ?array {
		if ( strpos( $id, ':' ) !== false ) {
			list( $type, $simkl_id ) = explode( ':', $id, 2 );

			if ( 'movie' === $type ) {
				return $this->get_movie( (int) $simkl_id );
			} elseif ( 'tv' === $type || 'show' === $type ) {
				return $this->get_show( (int) $simkl_id );
			} elseif ( 'anime' === $type ) {
				return $this->get_anime( (int) $simkl_id );
			}
		}

		return null;
	}

	/**
	 * Get movie details.
	 *
	 * @param int $id Simkl movie ID.
	 * @return array<string, mixed>|null Movie data.
	 */
	public function get_movie( int $id ): ?array {
		$cache_key = 'movie_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "movies/{$id}", [ 'extended' => 'full' ] );

			$result = $this->normalize_movie( $response );

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
	 * @param int $id Simkl show ID.
	 * @return array<string, mixed>|null Show data.
	 */
	public function get_show( int $id ): ?array {
		$cache_key = 'show_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "tv/{$id}", [ 'extended' => 'full' ] );

			$result = $this->normalize_show( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get show failed',
				[
					'id'    => $id,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get anime details.
	 *
	 * @param int $id Simkl anime ID.
	 * @return array<string, mixed>|null Anime data.
	 */
	public function get_anime( int $id ): ?array {
		$cache_key = 'anime_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "anime/{$id}", [ 'extended' => 'full' ] );

			$result = $this->normalize_anime( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get anime failed',
				[
					'id'    => $id,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get show episodes.
	 *
	 * @param int $show_id Simkl show ID.
	 * @return array<int, array<string, mixed>> Episodes.
	 */
	public function get_episodes( int $show_id ): array {
		$cache_key = 'episodes_' . $show_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "tv/{$show_id}/episodes", [ 'extended' => 'full' ] );

			$episodes = [];

			foreach ( $response as $episode ) {
				$episodes[] = $this->normalize_episode( $episode );
			}

			$this->set_cache( $cache_key, $episodes );

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get episodes failed',
				[
					'show_id' => $show_id,
					'error'   => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get user's watch history.
	 *
	 * @param string $type Type: movies, shows, anime.
	 * @return array<int, array<string, mixed>> Watch history.
	 */
	public function get_history( string $type = 'all' ): array {
		if ( ! $this->is_authenticated() ) {
			return [];
		}

		$cache_key = 'history_' . $type;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'sync/all-items/' . $type, [ 'extended' => 'full' ] );

			$items = [];

			if ( isset( $response['movies'] ) ) {
				foreach ( $response['movies'] as $item ) {
					$movie                 = $this->normalize_movie( $item['movie'] ?? $item );
					$movie['status']       = $item['status'] ?? 'completed';
					$movie['last_watched'] = $item['last_watched_at'] ?? '';
					$items[]               = $movie;
				}
			}

			if ( isset( $response['shows'] ) ) {
				foreach ( $response['shows'] as $item ) {
					$show                     = $this->normalize_show( $item['show'] ?? $item );
					$show['status']           = $item['status'] ?? 'watching';
					$show['last_watched']     = $item['last_watched_at'] ?? '';
					$show['watched_episodes'] = $item['watched_episodes_count'] ?? 0;
					$show['total_episodes']   = $item['total_episodes_count'] ?? 0;
					$items[]                  = $show;
				}
			}

			if ( isset( $response['anime'] ) ) {
				foreach ( $response['anime'] as $item ) {
					$anime                     = $this->normalize_anime( $item['show'] ?? $item );
					$anime['status']           = $item['status'] ?? 'watching';
					$anime['last_watched']     = $item['last_watched_at'] ?? '';
					$anime['watched_episodes'] = $item['watched_episodes_count'] ?? 0;
					$anime['total_episodes']   = $item['total_episodes_count'] ?? 0;
					$items[]                   = $anime;
				}
			}

			$this->set_cache( $cache_key, $items, 300 ); // 5 min cache.

			return $items;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get history failed',
				[
					'type'  => $type,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get recent activity.
	 *
	 * @return array<int, array<string, mixed>> Recent activity.
	 */
	public function get_recent_activity(): array {
		if ( ! $this->is_authenticated() ) {
			return [];
		}

		try {
			$response = $this->get( 'sync/activities' );

			return $response;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get recent activity failed', [ 'error' => $e->getMessage() ] );
			return [];
		}
	}

	/**
	 * Add to history.
	 *
	 * @param array<string, mixed> $items Items to add.
	 * @return bool Success.
	 */
	public function add_to_history( array $items ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		try {
			$this->post( 'sync/history', $items );
			$this->delete_cache( 'history_all' );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Add to history failed', [ 'error' => $e->getMessage() ] );
			return false;
		}
	}

	/**
	 * Add movie to history.
	 *
	 * @param int    $movie_id   Simkl movie ID.
	 * @param string $watched_at Watched at timestamp.
	 * @return bool Success.
	 */
	public function add_movie_to_history( int $movie_id, string $watched_at = '' ): bool {
		$payload = [
			'movies' => [
				[
					'ids'        => [ 'simkl' => $movie_id ],
					'watched_at' => $watched_at ?: gmdate( 'c' ),
				],
			],
		];

		return $this->add_to_history( $payload );
	}

	/**
	 * Add episode to history.
	 *
	 * @param int    $show_id        Simkl show ID.
	 * @param int    $season_number  Season number.
	 * @param int    $episode_number Episode number.
	 * @param string $watched_at     Watched at timestamp.
	 * @return bool Success.
	 */
	public function add_episode_to_history( int $show_id, int $season_number, int $episode_number, string $watched_at = '' ): bool {
		$payload = [
			'shows' => [
				[
					'ids'     => [ 'simkl' => $show_id ],
					'seasons' => [
						[
							'number'   => $season_number,
							'episodes' => [
								[
									'number'     => $episode_number,
									'watched_at' => $watched_at ?: gmdate( 'c' ),
								],
							],
						],
					],
				],
			],
		];

		return $this->add_to_history( $payload );
	}

	/**
	 * Get user ratings.
	 *
	 * @param string $type Type: movies, shows, anime.
	 * @return array<int, array<string, mixed>> Rated items.
	 */
	public function get_ratings( string $type = 'all' ): array {
		if ( ! $this->is_authenticated() ) {
			return [];
		}

		try {
			$response = $this->get( 'sync/ratings/' . $type );

			$items = [];

			foreach ( $response as $item ) {
				$normalized             = $this->normalize_result( $item );
				$normalized['rating']   = $item['rating'] ?? 0;
				$normalized['rated_at'] = $item['rated_at'] ?? '';
				$items[]                = $normalized;
			}

			return $items;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get ratings failed',
				[
					'type'  => $type,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Add rating.
	 *
	 * @param string $type   Type: movie, show, anime.
	 * @param int    $id     Simkl ID.
	 * @param int    $rating Rating 1-10.
	 * @return bool Success.
	 */
	public function add_rating( string $type, int $id, int $rating ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		$payload = [
			$type . 's' => [
				[
					'ids'      => [ 'simkl' => $id ],
					'rating'   => min( 10, max( 1, $rating ) ),
					'rated_at' => gmdate( 'c' ),
				],
			],
		];

		try {
			$this->post( 'sync/ratings', $payload );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Add rating failed',
				[
					'type'   => $type,
					'id'     => $id,
					'rating' => $rating,
					'error'  => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Get user's watchlist/plan to watch.
	 *
	 * @param string $type Type: movies, shows, anime.
	 * @return array<int, array<string, mixed>> Watchlist items.
	 */
	public function get_watchlist( string $type = 'all' ): array {
		if ( ! $this->is_authenticated() ) {
			return [];
		}

		try {
			$response = $this->get( 'sync/plantowatch/' . $type );

			$items = [];

			foreach ( $response as $item ) {
				$normalized             = $this->normalize_result( $item );
				$normalized['added_at'] = $item['added_at'] ?? '';
				$items[]                = $normalized;
			}

			return $items;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get watchlist failed',
				[
					'type'  => $type,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get user stats.
	 *
	 * @return array<string, mixed>|null Stats.
	 */
	public function get_stats(): ?array {
		if ( ! $this->is_authenticated() ) {
			return null;
		}

		$cache_key = 'user_stats';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'users/settings' );

			$stats = [
				'user'        => $response['user'] ?? [],
				'account'     => $response['account'] ?? [],
				'connections' => $response['connections'] ?? [],
			];

			$this->set_cache( $cache_key, $stats, HOUR_IN_SECONDS );

			return $stats;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get stats failed', [ 'error' => $e->getMessage() ] );
			return null;
		}
	}

	/**
	 * Lookup by external ID.
	 *
	 * @param string $source Source: imdb, tmdb, tvdb, mal, anidb.
	 * @param string $id     External ID.
	 * @return array<string, mixed>|null Result.
	 */
	public function lookup( string $source, string $id ): ?array {
		$cache_key = "lookup_{$source}_{$id}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'search/id', [ $source => $id ] );

			if ( ! empty( $response ) ) {
				$result = $this->normalize_result( $response[0] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Lookup failed',
				[
					'source' => $source,
					'id'     => $id,
					'error'  => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Normalize search result.
	 *
	 * @param array<string, mixed> $raw_result Raw result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		$type = $raw_result['type'] ?? 'movie';

		if ( 'movie' === $type ) {
			return $this->normalize_movie( $raw_result );
		} elseif ( 'tv' === $type || 'show' === $type ) {
			return $this->normalize_show( $raw_result );
		} elseif ( 'anime' === $type ) {
			return $this->normalize_anime( $raw_result );
		}

		return $this->normalize_movie( $raw_result );
	}

	/**
	 * Normalize movie data.
	 *
	 * @param array<string, mixed> $movie Movie data.
	 * @return array<string, mixed> Normalized movie.
	 */
	private function normalize_movie( array $movie ): array {
		$ids = $movie['ids'] ?? [];

		return [
			'id'            => $ids['simkl'] ?? 0,
			'simkl_id'      => $ids['simkl'] ?? 0,
			'imdb_id'       => $ids['imdb'] ?? '',
			'tmdb_id'       => $ids['tmdb'] ?? null,
			'slug'          => $ids['slug'] ?? '',
			'title'         => $movie['title'] ?? '',
			'year'          => $movie['year'] ?? null,
			'poster'        => $this->get_poster_url( $movie['poster'] ?? '' ),
			'fanart'        => $movie['fanart'] ?? '',
			'runtime'       => $movie['runtime'] ?? null,
			'overview'      => $movie['overview'] ?? '',
			'genres'        => $movie['genres'] ?? [],
			'certification' => $movie['certification'] ?? '',
			'released'      => $movie['released'] ?? '',
			'trailer'       => $movie['trailer'] ?? '',
			'ratings'       => $movie['ratings'] ?? [],
			'type'          => 'movie',
			'source'        => 'simkl',
		];
	}

	/**
	 * Normalize show data.
	 *
	 * @param array<string, mixed> $show Show data.
	 * @return array<string, mixed> Normalized show.
	 */
	private function normalize_show( array $show ): array {
		$ids = $show['ids'] ?? [];

		return [
			'id'             => $ids['simkl'] ?? 0,
			'simkl_id'       => $ids['simkl'] ?? 0,
			'imdb_id'        => $ids['imdb'] ?? '',
			'tmdb_id'        => $ids['tmdb'] ?? null,
			'tvdb_id'        => $ids['tvdb'] ?? null,
			'slug'           => $ids['slug'] ?? '',
			'title'          => $show['title'] ?? '',
			'year'           => $show['year'] ?? null,
			'poster'         => $this->get_poster_url( $show['poster'] ?? '' ),
			'fanart'         => $show['fanart'] ?? '',
			'runtime'        => $show['runtime'] ?? null,
			'overview'       => $show['overview'] ?? '',
			'genres'         => $show['genres'] ?? [],
			'certification'  => $show['certification'] ?? '',
			'first_aired'    => $show['first_aired'] ?? '',
			'network'        => $show['network'] ?? '',
			'status'         => $show['status'] ?? '',
			'total_episodes' => $show['total_episodes'] ?? 0,
			'aired_episodes' => $show['aired_episodes'] ?? 0,
			'ratings'        => $show['ratings'] ?? [],
			'type'           => 'tv',
			'source'         => 'simkl',
		];
	}

	/**
	 * Normalize anime data.
	 *
	 * @param array<string, mixed> $anime Anime data.
	 * @return array<string, mixed> Normalized anime.
	 */
	private function normalize_anime( array $anime ): array {
		$ids = $anime['ids'] ?? [];

		return [
			'id'             => $ids['simkl'] ?? 0,
			'simkl_id'       => $ids['simkl'] ?? 0,
			'mal_id'         => $ids['mal'] ?? null,
			'anidb_id'       => $ids['anidb'] ?? null,
			'anilist_id'     => $ids['anilist'] ?? null,
			'kitsu_id'       => $ids['kitsu'] ?? null,
			'imdb_id'        => $ids['imdb'] ?? '',
			'slug'           => $ids['slug'] ?? '',
			'title'          => $anime['title'] ?? '',
			'title_romaji'   => $anime['title_romaji'] ?? '',
			'title_en'       => $anime['title_en'] ?? '',
			'year'           => $anime['year'] ?? null,
			'poster'         => $this->get_poster_url( $anime['poster'] ?? '' ),
			'fanart'         => $anime['fanart'] ?? '',
			'runtime'        => $anime['runtime'] ?? null,
			'overview'       => $anime['overview'] ?? '',
			'genres'         => $anime['genres'] ?? [],
			'first_aired'    => $anime['first_aired'] ?? '',
			'anime_type'     => $anime['anime_type'] ?? '',
			'status'         => $anime['status'] ?? '',
			'total_episodes' => $anime['total_episodes'] ?? 0,
			'aired_episodes' => $anime['aired_episodes'] ?? 0,
			'ratings'        => $anime['ratings'] ?? [],
			'type'           => 'anime',
			'source'         => 'simkl',
		];
	}

	/**
	 * Normalize episode data.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return array<string, mixed> Normalized episode.
	 */
	private function normalize_episode( array $episode ): array {
		return [
			'id'          => $episode['ids']['simkl'] ?? 0,
			'title'       => $episode['title'] ?? '',
			'season'      => $episode['season'] ?? 0,
			'episode'     => $episode['episode'] ?? 0,
			'description' => $episode['description'] ?? '',
			'img'         => $episode['img'] ?? '',
			'date'        => $episode['date'] ?? '',
			'type'        => 'episode',
			'source'      => 'simkl',
		];
	}

	/**
	 * Get full poster URL.
	 *
	 * @param string $poster Poster path.
	 * @return string|null Full URL.
	 */
	private function get_poster_url( string $poster ): ?string {
		if ( empty( $poster ) ) {
			return null;
		}

		// Simkl provides full URLs or paths.
		if ( strpos( $poster, 'http' ) === 0 ) {
			return $poster;
		}

		return 'https://simkl.in/posters/' . $poster . '_m.jpg';
	}

	/**
	 * Set access token.
	 *
	 * @param string $token Access token.
	 * @return void
	 */
	public function set_access_token( string $token ): void {
		$this->access_token = $token;
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://simkl.docs.apiary.io/';
	}
}

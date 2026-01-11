<?php
/**
 * Trakt API Integration
 *
 * Provides watch history import and tracking from Trakt.tv.
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
 * Trakt API class.
 *
 * @since 1.0.0
 */
class Trakt extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'trakt';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.trakt.tv/';

	/**
	 * Rate limit: 1000 requests per 5 minutes.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.3;

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
	 * Refresh token.
	 *
	 * @var string|null
	 */
	private ?string $refresh_token = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials         = get_option( 'reactions_indieweb_api_credentials', array() );
		$trakt_creds         = $credentials['trakt'] ?? array();
		$this->client_id     = $trakt_creds['client_id'] ?? '';
		$this->client_secret = $trakt_creds['client_secret'] ?? '';
		$this->access_token  = $trakt_creds['access_token'] ?? '';
		$this->refresh_token = $trakt_creds['refresh_token'] ?? '';
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
		$headers = array(
			'Content-Type'      => 'application/json',
			'trakt-api-version' => '2',
			'trakt-api-key'     => $this->client_id ?? '',
		);

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
			$this->get( 'users/settings' );
			return true;
		} catch ( \Exception $e ) {
			// Try without auth.
			try {
				$this->get( 'movies/trending', array( 'limit' => 1 ) );
				return true;
			} catch ( \Exception $e ) {
				return false;
			}
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
	 * @param string $state        State parameter.
	 * @return string Authorization URL.
	 */
	public function get_authorization_url( string $redirect_uri, string $state = '' ): string {
		$params = array(
			'response_type' => 'code',
			'client_id'     => $this->client_id,
			'redirect_uri'  => $redirect_uri,
		);

		if ( $state ) {
			$params['state'] = $state;
		}

		return 'https://trakt.tv/oauth/authorize?' . http_build_query( $params );
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri Redirect URI.
	 * @return array<string, mixed>|null Token data.
	 */
	public function exchange_code( string $code, string $redirect_uri ): ?array {
		try {
			$response = wp_remote_post(
				'https://api.trakt.tv/oauth/token',
				array(
					'timeout' => 30,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'code'          => $code,
							'client_id'     => $this->client_id,
							'client_secret' => $this->client_secret,
							'redirect_uri'  => $redirect_uri,
							'grant_type'    => 'authorization_code',
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['access_token'] ) ) {
				$this->access_token  = $body['access_token'];
				$this->refresh_token = $body['refresh_token'] ?? '';

				return array(
					'access_token'  => $body['access_token'],
					'refresh_token' => $body['refresh_token'] ?? '',
					'expires_in'    => $body['expires_in'] ?? 7776000,
					'created_at'    => $body['created_at'] ?? time(),
				);
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Token exchange failed', array( 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Refresh access token.
	 *
	 * @return array<string, mixed>|null New token data.
	 */
	public function refresh_access_token(): ?array {
		if ( ! $this->refresh_token ) {
			return null;
		}

		try {
			$response = wp_remote_post(
				'https://api.trakt.tv/oauth/token',
				array(
					'timeout' => 30,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'refresh_token' => $this->refresh_token,
							'client_id'     => $this->client_id,
							'client_secret' => $this->client_secret,
							'grant_type'    => 'refresh_token',
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['access_token'] ) ) {
				$this->access_token  = $body['access_token'];
				$this->refresh_token = $body['refresh_token'] ?? $this->refresh_token;

				return array(
					'access_token'  => $body['access_token'],
					'refresh_token' => $body['refresh_token'] ?? $this->refresh_token,
					'expires_in'    => $body['expires_in'] ?? 7776000,
					'created_at'    => $body['created_at'] ?? time(),
				);
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Token refresh failed', array( 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Search for movies and shows.
	 *
	 * @param string      $query Search query.
	 * @param string|null $type  Type filter: movie, show, episode.
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
			$endpoint = 'search/' . ( $type ?? 'movie,show' );

			$response = $this->get(
				$endpoint,
				array(
					'query' => $query,
					'limit' => 25,
				)
			);

			$results = array();

			foreach ( $response as $item ) {
				$normalized = $this->normalize_result( $item );
				if ( $normalized ) {
					$results[] = $normalized;
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Search failed', array( 'query' => $query, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get by Trakt ID.
	 *
	 * @param string $id ID in format "movie:123" or "show:456".
	 * @return array<string, mixed>|null Result.
	 */
	public function get_by_id( string $id ): ?array {
		if ( strpos( $id, ':' ) !== false ) {
			list( $type, $trakt_id ) = explode( ':', $id, 2 );

			if ( 'movie' === $type ) {
				return $this->get_movie( $trakt_id );
			} elseif ( 'show' === $type ) {
				return $this->get_show( $trakt_id );
			}
		}

		return null;
	}

	/**
	 * Get movie details.
	 *
	 * @param string $id Trakt slug or ID.
	 * @return array<string, mixed>|null Movie data.
	 */
	public function get_movie( string $id ): ?array {
		$cache_key = 'movie_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "movies/{$id}", array( 'extended' => 'full' ) );

			$result = $this->normalize_movie( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get movie failed', array( 'id' => $id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get TV show details.
	 *
	 * @param string $id Trakt slug or ID.
	 * @return array<string, mixed>|null Show data.
	 */
	public function get_show( string $id ): ?array {
		$cache_key = 'show_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$id}", array( 'extended' => 'full' ) );

			$result = $this->normalize_show( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get show failed', array( 'id' => $id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get show seasons.
	 *
	 * @param string $show_id Show Trakt ID or slug.
	 * @return array<int, array<string, mixed>> Seasons.
	 */
	public function get_seasons( string $show_id ): array {
		$cache_key = 'show_seasons_' . $show_id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/seasons", array( 'extended' => 'full' ) );

			$seasons = array();

			foreach ( $response as $season ) {
				$seasons[] = array(
					'number'        => $season['number'] ?? 0,
					'ids'           => $season['ids'] ?? array(),
					'title'         => $season['title'] ?? '',
					'overview'      => $season['overview'] ?? '',
					'first_aired'   => $season['first_aired'] ?? '',
					'episode_count' => $season['episode_count'] ?? 0,
					'aired_episodes'=> $season['aired_episodes'] ?? 0,
					'rating'        => $season['rating'] ?? 0,
					'votes'         => $season['votes'] ?? 0,
				);
			}

			$this->set_cache( $cache_key, $seasons );

			return $seasons;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get seasons failed', array( 'show_id' => $show_id, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get season episodes.
	 *
	 * @param string $show_id       Show Trakt ID or slug.
	 * @param int    $season_number Season number.
	 * @return array<int, array<string, mixed>> Episodes.
	 */
	public function get_episodes( string $show_id, int $season_number ): array {
		$cache_key = "show_{$show_id}_season_{$season_number}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/seasons/{$season_number}", array( 'extended' => 'full' ) );

			$episodes = array();

			foreach ( $response as $episode ) {
				$episodes[] = $this->normalize_episode( $episode );
			}

			$this->set_cache( $cache_key, $episodes );

			return $episodes;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episodes failed', array( 'show_id' => $show_id, 'season' => $season_number, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get single episode.
	 *
	 * @param string $show_id        Show Trakt ID or slug.
	 * @param int    $season_number  Season number.
	 * @param int    $episode_number Episode number.
	 * @return array<string, mixed>|null Episode data.
	 */
	public function get_episode( string $show_id, int $season_number, int $episode_number ): ?array {
		$cache_key = "show_{$show_id}_s{$season_number}_e{$episode_number}";
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( "shows/{$show_id}/seasons/{$season_number}/episodes/{$episode_number}", array( 'extended' => 'full' ) );

			$result = $this->normalize_episode( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get episode failed', array( 'show_id' => $show_id, 'season' => $season_number, 'episode' => $episode_number, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get user watch history.
	 *
	 * @param string $type       Type: movies, shows, episodes.
	 * @param int    $page       Page number.
	 * @param int    $limit      Items per page.
	 * @param string $start_at   Start date (ISO 8601).
	 * @param string $end_at     End date (ISO 8601).
	 * @return array<string, mixed> History with pagination.
	 */
	public function get_history( string $type = 'all', int $page = 1, int $limit = 25, string $start_at = '', string $end_at = '' ): array {
		if ( ! $this->is_authenticated() ) {
			return array( 'items' => array(), 'page' => 1, 'limit' => $limit, 'total' => 0 );
		}

		$params = array(
			'page'  => $page,
			'limit' => min( $limit, 100 ),
		);

		if ( $start_at ) {
			$params['start_at'] = $start_at;
		}

		if ( $end_at ) {
			$params['end_at'] = $end_at;
		}

		$endpoint = 'users/me/history';
		if ( 'all' !== $type ) {
			$endpoint .= '/' . $type;
		}

		try {
			$response = $this->get( $endpoint, $params );

			$items = array();

			foreach ( $response as $item ) {
				$items[] = $this->normalize_history_item( $item );
			}

			return array(
				'items'     => $items,
				'page'      => $page,
				'limit'     => $limit,
				'total'     => count( $items ), // Would need headers for real total.
			);
		} catch ( \Exception $e ) {
			$this->log_error( 'Get history failed', array( 'type' => $type, 'error' => $e->getMessage() ) );
			return array( 'items' => array(), 'page' => $page, 'limit' => $limit, 'total' => 0 );
		}
	}

	/**
	 * Get all history for import.
	 *
	 * @param string $type     Type: movies, shows, episodes.
	 * @param string $start_at Start date.
	 * @return \Generator History items.
	 */
	public function get_all_history( string $type = 'all', string $start_at = '' ): \Generator {
		$page = 1;
		$limit = 100;

		do {
			$result = $this->get_history( $type, $page, $limit, $start_at );
			$items  = $result['items'];

			foreach ( $items as $item ) {
				yield $item;
			}

			++$page;

			// Rate limit.
			usleep( 300000 ); // 300ms.

		} while ( count( $items ) >= $limit );
	}

	/**
	 * Add to history.
	 *
	 * @param array<string, mixed> $item Item data with 'type', 'ids', 'watched_at'.
	 * @return bool Success.
	 */
	public function add_to_history( array $item ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		$type = $item['type'] ?? 'movie';
		$ids  = $item['ids'] ?? array();

		$payload = array();

		if ( 'movie' === $type ) {
			$payload['movies'] = array(
				array(
					'ids'        => $ids,
					'watched_at' => $item['watched_at'] ?? gmdate( 'c' ),
				),
			);
		} elseif ( 'episode' === $type ) {
			$payload['episodes'] = array(
				array(
					'ids'        => $ids,
					'watched_at' => $item['watched_at'] ?? gmdate( 'c' ),
				),
			);
		} elseif ( 'show' === $type ) {
			$payload['shows'] = array(
				array(
					'ids'        => $ids,
					'watched_at' => $item['watched_at'] ?? gmdate( 'c' ),
				),
			);
		}

		try {
			$this->post( 'sync/history', $payload );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Add to history failed', array( 'item' => $item, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Remove from history.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return bool Success.
	 */
	public function remove_from_history( array $item ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		$type = $item['type'] ?? 'movie';
		$ids  = $item['ids'] ?? array();

		$payload = array();

		if ( 'movie' === $type ) {
			$payload['movies'] = array( array( 'ids' => $ids ) );
		} elseif ( 'episode' === $type ) {
			$payload['episodes'] = array( array( 'ids' => $ids ) );
		}

		try {
			$this->post( 'sync/history/remove', $payload );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Remove from history failed', array( 'item' => $item, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Get user watchlist.
	 *
	 * @param string $type Type: movies, shows.
	 * @return array<int, array<string, mixed>> Watchlist items.
	 */
	public function get_watchlist( string $type = 'all' ): array {
		if ( ! $this->is_authenticated() ) {
			return array();
		}

		$cache_key = 'watchlist_' . $type;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$endpoint = 'users/me/watchlist';
		if ( 'all' !== $type ) {
			$endpoint .= '/' . $type;
		}

		try {
			$response = $this->get( $endpoint, array( 'extended' => 'full' ) );

			$items = array();

			foreach ( $response as $item ) {
				$normalized = $this->normalize_result( $item );
				if ( $normalized ) {
					$normalized['listed_at'] = $item['listed_at'] ?? '';
					$items[] = $normalized;
				}
			}

			$this->set_cache( $cache_key, $items, 300 ); // 5 min cache.

			return $items;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get watchlist failed', array( 'type' => $type, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Add to watchlist.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return bool Success.
	 */
	public function add_to_watchlist( array $item ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		$type = $item['type'] ?? 'movie';
		$ids  = $item['ids'] ?? array();

		$payload = array();

		if ( 'movie' === $type ) {
			$payload['movies'] = array( array( 'ids' => $ids ) );
		} elseif ( 'show' === $type ) {
			$payload['shows'] = array( array( 'ids' => $ids ) );
		}

		try {
			$this->post( 'sync/watchlist', $payload );
			$this->delete_cache( 'watchlist_' . $type );
			$this->delete_cache( 'watchlist_all' );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Add to watchlist failed', array( 'item' => $item, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Get user ratings.
	 *
	 * @param string $type Type: movies, shows, episodes.
	 * @return array<int, array<string, mixed>> Rated items.
	 */
	public function get_ratings( string $type = 'all' ): array {
		if ( ! $this->is_authenticated() ) {
			return array();
		}

		$cache_key = 'ratings_' . $type;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		$endpoint = 'users/me/ratings';
		if ( 'all' !== $type ) {
			$endpoint .= '/' . $type;
		}

		try {
			$response = $this->get( $endpoint, array( 'extended' => 'full' ) );

			$items = array();

			foreach ( $response as $item ) {
				$normalized = $this->normalize_result( $item );
				if ( $normalized ) {
					$normalized['rating']   = $item['rating'] ?? 0;
					$normalized['rated_at'] = $item['rated_at'] ?? '';
					$items[] = $normalized;
				}
			}

			$this->set_cache( $cache_key, $items, 300 );

			return $items;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get ratings failed', array( 'type' => $type, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Add rating.
	 *
	 * @param array<string, mixed> $item   Item data.
	 * @param int                  $rating Rating 1-10.
	 * @return bool Success.
	 */
	public function add_rating( array $item, int $rating ): bool {
		if ( ! $this->is_authenticated() ) {
			return false;
		}

		$type = $item['type'] ?? 'movie';
		$ids  = $item['ids'] ?? array();

		$payload = array();

		$rating_item = array(
			'ids'      => $ids,
			'rating'   => min( 10, max( 1, $rating ) ),
			'rated_at' => gmdate( 'c' ),
		);

		if ( 'movie' === $type ) {
			$payload['movies'] = array( $rating_item );
		} elseif ( 'show' === $type ) {
			$payload['shows'] = array( $rating_item );
		} elseif ( 'episode' === $type ) {
			$payload['episodes'] = array( $rating_item );
		}

		try {
			$this->post( 'sync/ratings', $payload );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Add rating failed', array( 'item' => $item, 'rating' => $rating, 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Get trending movies.
	 *
	 * @return array<int, array<string, mixed>> Trending movies.
	 */
	public function get_trending_movies(): array {
		$cache_key = 'trending_movies';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'movies/trending', array( 'extended' => 'full', 'limit' => 25 ) );

			$movies = array();

			foreach ( $response as $item ) {
				$movie = $this->normalize_movie( $item['movie'] ?? $item );
				$movie['watchers'] = $item['watchers'] ?? 0;
				$movies[] = $movie;
			}

			$this->set_cache( $cache_key, $movies, HOUR_IN_SECONDS * 6 );

			return $movies;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get trending movies failed', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get trending shows.
	 *
	 * @return array<int, array<string, mixed>> Trending shows.
	 */
	public function get_trending_shows(): array {
		$cache_key = 'trending_shows';
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'shows/trending', array( 'extended' => 'full', 'limit' => 25 ) );

			$shows = array();

			foreach ( $response as $item ) {
				$show = $this->normalize_show( $item['show'] ?? $item );
				$show['watchers'] = $item['watchers'] ?? 0;
				$shows[] = $show;
			}

			$this->set_cache( $cache_key, $shows, HOUR_IN_SECONDS * 6 );

			return $shows;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get trending shows failed', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get user stats.
	 *
	 * @return array<string, mixed>|null User stats.
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
			$response = $this->get( 'users/me/stats' );

			$this->set_cache( $cache_key, $response, HOUR_IN_SECONDS );

			return $response;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get stats failed', array( 'error' => $e->getMessage() ) );
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
		$type = $raw_result['type'] ?? '';

		if ( 'movie' === $type && isset( $raw_result['movie'] ) ) {
			return $this->normalize_movie( $raw_result['movie'] );
		} elseif ( 'show' === $type && isset( $raw_result['show'] ) ) {
			return $this->normalize_show( $raw_result['show'] );
		} elseif ( 'episode' === $type && isset( $raw_result['episode'] ) ) {
			$episode = $this->normalize_episode( $raw_result['episode'] );
			if ( isset( $raw_result['show'] ) ) {
				$episode['show'] = $this->normalize_show( $raw_result['show'] );
			}
			return $episode;
		}

		// Direct item.
		if ( isset( $raw_result['ids']['trakt'] ) ) {
			if ( isset( $raw_result['title'] ) ) {
				return $this->normalize_movie( $raw_result );
			}
		}

		return array();
	}

	/**
	 * Normalize movie data.
	 *
	 * @param array<string, mixed> $movie Movie data.
	 * @return array<string, mixed> Normalized movie.
	 */
	private function normalize_movie( array $movie ): array {
		return array(
			'id'           => $movie['ids']['trakt'] ?? 0,
			'trakt_id'     => $movie['ids']['trakt'] ?? 0,
			'tmdb_id'      => $movie['ids']['tmdb'] ?? null,
			'imdb_id'      => $movie['ids']['imdb'] ?? '',
			'slug'         => $movie['ids']['slug'] ?? '',
			'title'        => $movie['title'] ?? '',
			'year'         => $movie['year'] ?? null,
			'overview'     => $movie['overview'] ?? '',
			'runtime'      => $movie['runtime'] ?? null,
			'tagline'      => $movie['tagline'] ?? '',
			'released'     => $movie['released'] ?? '',
			'certification'=> $movie['certification'] ?? '',
			'trailer'      => $movie['trailer'] ?? '',
			'homepage'     => $movie['homepage'] ?? '',
			'rating'       => $movie['rating'] ?? 0,
			'votes'        => $movie['votes'] ?? 0,
			'genres'       => $movie['genres'] ?? array(),
			'language'     => $movie['language'] ?? '',
			'country'      => $movie['country'] ?? '',
			'type'         => 'movie',
			'source'       => 'trakt',
		);
	}

	/**
	 * Normalize show data.
	 *
	 * @param array<string, mixed> $show Show data.
	 * @return array<string, mixed> Normalized show.
	 */
	private function normalize_show( array $show ): array {
		return array(
			'id'              => $show['ids']['trakt'] ?? 0,
			'trakt_id'        => $show['ids']['trakt'] ?? 0,
			'tmdb_id'         => $show['ids']['tmdb'] ?? null,
			'imdb_id'         => $show['ids']['imdb'] ?? '',
			'tvdb_id'         => $show['ids']['tvdb'] ?? null,
			'slug'            => $show['ids']['slug'] ?? '',
			'title'           => $show['title'] ?? '',
			'year'            => $show['year'] ?? null,
			'overview'        => $show['overview'] ?? '',
			'runtime'         => $show['runtime'] ?? null,
			'first_aired'     => $show['first_aired'] ?? '',
			'certification'   => $show['certification'] ?? '',
			'network'         => $show['network'] ?? '',
			'trailer'         => $show['trailer'] ?? '',
			'homepage'        => $show['homepage'] ?? '',
			'status'          => $show['status'] ?? '',
			'rating'          => $show['rating'] ?? 0,
			'votes'           => $show['votes'] ?? 0,
			'aired_episodes'  => $show['aired_episodes'] ?? 0,
			'genres'          => $show['genres'] ?? array(),
			'language'        => $show['language'] ?? '',
			'country'         => $show['country'] ?? '',
			'type'            => 'tv',
			'source'          => 'trakt',
		);
	}

	/**
	 * Normalize episode data.
	 *
	 * @param array<string, mixed> $episode Episode data.
	 * @return array<string, mixed> Normalized episode.
	 */
	private function normalize_episode( array $episode ): array {
		return array(
			'id'             => $episode['ids']['trakt'] ?? 0,
			'trakt_id'       => $episode['ids']['trakt'] ?? 0,
			'tmdb_id'        => $episode['ids']['tmdb'] ?? null,
			'imdb_id'        => $episode['ids']['imdb'] ?? '',
			'tvdb_id'        => $episode['ids']['tvdb'] ?? null,
			'title'          => $episode['title'] ?? '',
			'season'         => $episode['season'] ?? 0,
			'number'         => $episode['number'] ?? 0,
			'overview'       => $episode['overview'] ?? '',
			'runtime'        => $episode['runtime'] ?? null,
			'first_aired'    => $episode['first_aired'] ?? '',
			'rating'         => $episode['rating'] ?? 0,
			'votes'          => $episode['votes'] ?? 0,
			'type'           => 'episode',
			'source'         => 'trakt',
		);
	}

	/**
	 * Normalize history item.
	 *
	 * @param array<string, mixed> $item History item.
	 * @return array<string, mixed> Normalized item.
	 */
	private function normalize_history_item( array $item ): array {
		$normalized = $this->normalize_result( $item );

		$normalized['history_id'] = $item['id'] ?? 0;
		$normalized['watched_at'] = $item['watched_at'] ?? '';
		$normalized['action']     = $item['action'] ?? 'watch';

		return $normalized;
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
		return 'https://trakt.docs.apiary.io/';
	}
}

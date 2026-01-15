<?php
/**
 * ListenBrainz API Integration
 *
 * Provides scrobble import and listening history from ListenBrainz.
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
 * ListenBrainz API class.
 *
 * @since 1.0.0
 */
class ListenBrainz extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'listenbrainz';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://api.listenbrainz.org/1/';

	/**
	 * Rate limit: 10 requests per 10 seconds.
	 *
	 * @var float
	 */
	protected float $rate_limit = 1.0;

	/**
	 * Cache duration: 5 minutes for recent listens.
	 *
	 * @var int
	 */
	protected int $cache_duration = 300;

	/**
	 * User token for authenticated requests.
	 *
	 * @var string|null
	 */
	private ?string $user_token = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials      = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$lb_creds         = $credentials['listenbrainz'] ?? [];
		$this->user_token = $lb_creds['token'] ?? '';
	}

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		$headers = [
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		];

		if ( $this->user_token ) {
			$headers['Authorization'] = 'Token ' . $this->user_token;
		}

		return $headers;
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		if ( ! $this->user_token ) {
			return false;
		}

		try {
			$response = $this->get( 'validate-token' );
			return isset( $response['valid'] ) && true === $response['valid'];
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Validate user token and get username.
	 *
	 * @return array<string, mixed>|null Token info or null.
	 */
	public function validate_token(): ?array {
		if ( ! $this->user_token ) {
			return null;
		}

		try {
			$response = $this->get( 'validate-token' );

			if ( isset( $response['valid'] ) && true === $response['valid'] ) {
				return [
					'valid'    => true,
					'username' => $response['user_name'] ?? '',
				];
			}

			return [ 'valid' => false ];
		} catch ( \Exception $e ) {
			$this->log_error( 'Token validation failed', [ 'error' => $e->getMessage() ] );
			return null;
		}
	}

	/**
	 * Search is not supported by ListenBrainz.
	 * Use MusicBrainz for searching.
	 *
	 * @param string $query Search query.
	 * @return array<int, array<string, mixed>> Empty array.
	 */
	public function search( string $query, ...$args ): array {
		// ListenBrainz doesn't have search - use MusicBrainz instead.
		return [];
	}

	/**
	 * Get by ID is not applicable for ListenBrainz.
	 *
	 * @param string $id ID.
	 * @return array<string, mixed>|null Null.
	 */
	public function get_by_id( string $id ): ?array {
		return null;
	}

	/**
	 * Get recent listens for a user.
	 *
	 * @param string $username ListenBrainz username.
	 * @param int    $count    Number of listens to retrieve.
	 * @param int    $max_ts   Maximum timestamp (listens before this).
	 * @param int    $min_ts   Minimum timestamp (listens after this).
	 * @return array<int, array<string, mixed>> Recent listens.
	 */
	public function get_listens( string $username, int $count = 25, int $max_ts = 0, int $min_ts = 0 ): array {
		$params = [ 'count' => min( $count, 100 ) ];

		if ( $max_ts > 0 ) {
			$params['max_ts'] = $max_ts;
		}

		if ( $min_ts > 0 ) {
			$params['min_ts'] = $min_ts;
		}

		$cache_key = 'listens_' . md5( $username . wp_json_encode( $params ) );

		// Only cache if not paginating.
		if ( 0 === $max_ts && 0 === $min_ts ) {
			$cached = $this->get_cache( $cache_key );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		try {
			$response = $this->get( 'user/' . rawurlencode( $username ) . '/listens', $params );

			$listens = [];

			if ( isset( $response['payload']['listens'] ) && is_array( $response['payload']['listens'] ) ) {
				foreach ( $response['payload']['listens'] as $listen ) {
					$listens[] = $this->normalize_listen( $listen );
				}
			}

			if ( 0 === $max_ts && 0 === $min_ts ) {
				$this->set_cache( $cache_key, $listens );
			}

			return $listens;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get listens failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get playing now for a user.
	 *
	 * @param string $username ListenBrainz username.
	 * @return array<string, mixed>|null Currently playing track or null.
	 */
	public function get_playing_now( string $username ): ?array {
		try {
			$response = $this->get( 'user/' . rawurlencode( $username ) . '/playing-now' );

			if ( isset( $response['payload']['listens'][0] ) ) {
				return $this->normalize_listen( $response['payload']['listens'][0] );
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get playing now failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Submit a listen.
	 *
	 * @param array<string, mixed> $track Track data.
	 * @param int                  $listened_at Unix timestamp.
	 * @return bool Success.
	 */
	public function submit_listen( array $track, int $listened_at = 0 ): bool {
		if ( ! $this->user_token ) {
			$this->log_error( 'Cannot submit listen without token' );
			return false;
		}

		$payload = [
			'listen_type' => $listened_at > 0 ? 'single' : 'playing_now',
			'payload'     => [
				[
					'track_metadata' => [
						'artist_name'  => $track['artist'] ?? '',
						'track_name'   => $track['track'] ?? '',
						'release_name' => $track['album'] ?? '',
					],
				],
			],
		];

		if ( $listened_at > 0 ) {
			$payload['payload'][0]['listened_at'] = $listened_at;
		}

		// Add additional metadata if available.
		$additional_info = [];

		if ( ! empty( $track['duration'] ) ) {
			$additional_info['duration_ms'] = (int) $track['duration'] * 1000;
		}

		if ( ! empty( $track['mbid'] ) ) {
			$additional_info['recording_mbid'] = $track['mbid'];
		}

		if ( ! empty( $track['artist_mbid'] ) ) {
			$additional_info['artist_mbids'] = [ $track['artist_mbid'] ];
		}

		if ( ! empty( $track['album_mbid'] ) ) {
			$additional_info['release_mbid'] = $track['album_mbid'];
		}

		if ( ! empty( $additional_info ) ) {
			$payload['payload'][0]['track_metadata']['additional_info'] = $additional_info;
		}

		try {
			$this->post( 'submit-listens', $payload );
			return true;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Submit listen failed',
				[
					'track' => $track,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Import listens in bulk.
	 *
	 * @param array<int, array<string, mixed>> $listens Array of listen data.
	 * @return array<string, mixed> Import result.
	 */
	public function import_listens( array $listens ): array {
		if ( ! $this->user_token ) {
			return [
				'success'  => false,
				'imported' => 0,
				'error'    => 'No user token configured',
			];
		}

		$payload_items = [];

		foreach ( $listens as $listen ) {
			$item = [
				'listened_at'    => $listen['listened_at'] ?? time(),
				'track_metadata' => [
					'artist_name'  => $listen['artist'] ?? '',
					'track_name'   => $listen['track'] ?? '',
					'release_name' => $listen['album'] ?? '',
				],
			];

			$additional_info = [];

			if ( ! empty( $listen['duration'] ) ) {
				$additional_info['duration_ms'] = (int) $listen['duration'] * 1000;
			}

			if ( ! empty( $listen['mbid'] ) ) {
				$additional_info['recording_mbid'] = $listen['mbid'];
			}

			if ( ! empty( $additional_info ) ) {
				$item['track_metadata']['additional_info'] = $additional_info;
			}

			$payload_items[] = $item;
		}

		// ListenBrainz accepts up to 1000 listens per request.
		$chunks   = array_chunk( $payload_items, 1000 );
		$imported = 0;
		$errors   = [];

		foreach ( $chunks as $chunk ) {
			$payload = [
				'listen_type' => 'import',
				'payload'     => $chunk,
			];

			try {
				$this->post( 'submit-listens', $payload );
				$imported += count( $chunk );
			} catch ( \Exception $e ) {
				$errors[] = $e->getMessage();
			}
		}

		return [
			'success'  => empty( $errors ),
			'imported' => $imported,
			'total'    => count( $listens ),
			'errors'   => $errors,
		];
	}

	/**
	 * Get user stats.
	 *
	 * @param string $username ListenBrainz username.
	 * @param string $range    Time range: this_week, this_month, this_year, week, month, year, all_time.
	 * @return array<string, mixed>|null Stats or null.
	 */
	public function get_stats_artists( string $username, string $range = 'this_week' ): ?array {
		$cache_key = 'stats_artists_' . md5( $username . $range );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'stats/user/' . rawurlencode( $username ) . '/artists',
				[
					'range' => $range,
					'count' => 25,
				]
			);

			if ( isset( $response['payload']['artists'] ) ) {
				$stats = [
					'artists'     => $response['payload']['artists'],
					'total_count' => $response['payload']['total_artist_count'] ?? 0,
					'range'       => $response['payload']['range'] ?? $range,
					'from_ts'     => $response['payload']['from_ts'] ?? null,
					'to_ts'       => $response['payload']['to_ts'] ?? null,
				];

				$this->set_cache( $cache_key, $stats, HOUR_IN_SECONDS );
				return $stats;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get artist stats failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get user top recordings.
	 *
	 * @param string $username ListenBrainz username.
	 * @param string $range    Time range.
	 * @return array<string, mixed>|null Top recordings or null.
	 */
	public function get_stats_recordings( string $username, string $range = 'this_week' ): ?array {
		$cache_key = 'stats_recordings_' . md5( $username . $range );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'stats/user/' . rawurlencode( $username ) . '/recordings',
				[
					'range' => $range,
					'count' => 25,
				]
			);

			if ( isset( $response['payload']['recordings'] ) ) {
				$stats = [
					'recordings'  => $response['payload']['recordings'],
					'total_count' => $response['payload']['total_recording_count'] ?? 0,
					'range'       => $response['payload']['range'] ?? $range,
				];

				$this->set_cache( $cache_key, $stats, HOUR_IN_SECONDS );
				return $stats;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get recording stats failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get user listen count.
	 *
	 * @param string $username ListenBrainz username.
	 * @return int|null Listen count or null.
	 */
	public function get_listen_count( string $username ): ?int {
		$cache_key = 'listen_count_' . $username;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'user/' . rawurlencode( $username ) . '/listen-count' );

			if ( isset( $response['payload']['count'] ) ) {
				$count = (int) $response['payload']['count'];
				$this->set_cache( $cache_key, $count, HOUR_IN_SECONDS );
				return $count;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get listen count failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Delete a listen.
	 *
	 * @param string $listened_at Listened at timestamp.
	 * @param string $recording_msid Recording MSID.
	 * @return bool Success.
	 */
	public function delete_listen( string $listened_at, string $recording_msid ): bool {
		if ( ! $this->user_token ) {
			return false;
		}

		try {
			$this->post(
				'delete-listen',
				[
					'listened_at'    => (int) $listened_at,
					'recording_msid' => $recording_msid,
				]
			);
			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Delete listen failed', [ 'error' => $e->getMessage() ] );
			return false;
		}
	}

	/**
	 * Get similar users.
	 *
	 * @param string $username ListenBrainz username.
	 * @return array<int, array<string, mixed>> Similar users.
	 */
	public function get_similar_users( string $username ): array {
		$cache_key = 'similar_users_' . $username;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get( 'user/' . rawurlencode( $username ) . '/similar-users' );

			$users = [];

			if ( isset( $response['payload'] ) && is_array( $response['payload'] ) ) {
				foreach ( $response['payload'] as $user ) {
					$users[] = [
						'username'   => $user['user_name'] ?? '',
						'similarity' => $user['similarity'] ?? 0,
					];
				}
			}

			$this->set_cache( $cache_key, $users, DAY_IN_SECONDS );
			return $users;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get similar users failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Normalize a listen object.
	 *
	 * @param array<string, mixed> $raw_listen Raw listen data.
	 * @return array<string, mixed> Normalized listen.
	 */
	protected function normalize_result( array $raw_listen ): array {
		return $this->normalize_listen( $raw_listen );
	}

	/**
	 * Normalize a listen object.
	 *
	 * @param array<string, mixed> $listen Raw listen data.
	 * @return array<string, mixed> Normalized listen.
	 */
	private function normalize_listen( array $listen ): array {
		$metadata        = $listen['track_metadata'] ?? [];
		$additional_info = $metadata['additional_info'] ?? [];

		$mbid        = $additional_info['recording_mbid'] ?? '';
		$artist_mbid = '';
		$album_mbid  = $additional_info['release_mbid'] ?? '';

		if ( isset( $additional_info['artist_mbids'][0] ) ) {
			$artist_mbid = $additional_info['artist_mbids'][0];
		}

		return [
			'track'          => $metadata['track_name'] ?? '',
			'artist'         => $metadata['artist_name'] ?? '',
			'album'          => $metadata['release_name'] ?? '',
			'listened_at'    => $listen['listened_at'] ?? null,
			'recording_msid' => $listen['recording_msid'] ?? '',
			'mbid'           => $mbid,
			'artist_mbid'    => $artist_mbid,
			'album_mbid'     => $album_mbid,
			'duration'       => isset( $additional_info['duration_ms'] ) ? (int) ( $additional_info['duration_ms'] / 1000 ) : null,
			'spotify_id'     => $additional_info['spotify_id'] ?? '',
			'origin_url'     => $additional_info['origin_url'] ?? '',
			'source'         => 'listenbrainz',
		];
	}

	/**
	 * Set user token.
	 *
	 * @param string $token User token.
	 * @return void
	 */
	public function set_token( string $token ): void {
		$this->user_token = $token;
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://listenbrainz.readthedocs.io/';
	}
}

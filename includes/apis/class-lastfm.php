<?php
/**
 * Last.fm API Integration
 *
 * Provides scrobble import and music metadata from Last.fm.
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
 * Last.fm API class.
 *
 * @since 1.0.0
 */
class LastFM extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'lastfm';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://ws.audioscrobbler.com/2.0/';

	/**
	 * Rate limit: 5 requests per second.
	 *
	 * @var float
	 */
	protected float $rate_limit = 0.2;

	/**
	 * Cache duration: 1 hour.
	 *
	 * @var int
	 */
	protected int $cache_duration = HOUR_IN_SECONDS;

	/**
	 * API key.
	 *
	 * @var string|null
	 */
	private ?string $api_key = null;

	/**
	 * API secret.
	 *
	 * @var string|null
	 */
	private ?string $api_secret = null;

	/**
	 * Session key for authenticated requests.
	 *
	 * @var string|null
	 */
	private ?string $session_key = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$credentials       = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$lastfm_creds      = $credentials['lastfm'] ?? [];
		$this->api_key     = $lastfm_creds['api_key'] ?? '';
		$this->api_secret  = $lastfm_creds['api_secret'] ?? '';
		$this->session_key = $lastfm_creds['session_key'] ?? '';
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		if ( ! $this->api_key ) {
			return false;
		}

		try {
			// Test with a simple artist info request.
			$this->call_method( 'artist.getInfo', [ 'artist' => 'Radiohead' ] );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Call a Last.fm API method.
	 *
	 * @param string               $method API method.
	 * @param array<string, mixed> $params Parameters.
	 * @param bool                 $signed Whether to sign the request.
	 * @return array<string, mixed> Response.
	 * @throws \Exception On API error.
	 */
	private function call_method( string $method, array $params = [], bool $signed = false ): array {
		$params['method']  = $method;
		$params['api_key'] = $this->api_key;
		$params['format']  = 'json';

		if ( $signed && $this->session_key ) {
			$params['sk']      = $this->session_key;
			$params['api_sig'] = $this->generate_signature( $params );
		}

		return $this->get( '', $params );
	}

	/**
	 * Generate API signature.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return string Signature.
	 */
	private function generate_signature( array $params ): string {
		unset( $params['format'] );
		ksort( $params );

		$signature = '';
		foreach ( $params as $key => $value ) {
			$signature .= $key . $value;
		}

		$signature .= $this->api_secret;

		return md5( $signature );
	}

	/**
	 * Search for tracks.
	 *
	 * @param string      $query  Search query.
	 * @param string|null $artist Artist name to filter by.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$artist = $args[0] ?? null;

		$cache_key = 'search_' . md5( $query . ( $artist ?? '' ) );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$params = [
				'track' => $query,
				'limit' => 25,
			];

			if ( $artist ) {
				$params['artist'] = $artist;
			}

			$response = $this->call_method( 'track.search', $params );

			$results = [];

			if ( isset( $response['results']['trackmatches']['track'] ) ) {
				$tracks = $response['results']['trackmatches']['track'];

				// Ensure it's an array of tracks.
				if ( isset( $tracks['name'] ) ) {
					$tracks = [ $tracks ];
				}

				foreach ( $tracks as $track ) {
					$results[] = $this->normalize_result( $track );
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
	 * Get track info by name and artist.
	 *
	 * @param string $id Track name.
	 * @return array<string, mixed>|null Track data.
	 */
	public function get_by_id( string $id ): ?array {
		// For Last.fm, we need track and artist.
		// ID format: "track_name|artist_name" or just mbid.
		if ( preg_match( '/^[a-f0-9-]{36}$/', $id ) ) {
			return $this->get_track_by_mbid( $id );
		}

		$parts = explode( '|', $id, 2 );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		return $this->get_track_info( $parts[0], $parts[1] );
	}

	/**
	 * Get track info.
	 *
	 * @param string $track  Track name.
	 * @param string $artist Artist name.
	 * @return array<string, mixed>|null Track data.
	 */
	public function get_track_info( string $track, string $artist ): ?array {
		$cache_key = 'track_' . md5( $track . $artist );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->call_method(
				'track.getInfo',
				[
					'track'       => $track,
					'artist'      => $artist,
					'autocorrect' => 1,
				]
			);

			if ( isset( $response['track'] ) ) {
				$result = $this->normalize_track_info( $response['track'] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get track info failed',
				[
					'track'  => $track,
					'artist' => $artist,
					'error'  => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get track by MBID.
	 *
	 * @param string $mbid MusicBrainz ID.
	 * @return array<string, mixed>|null Track data.
	 */
	public function get_track_by_mbid( string $mbid ): ?array {
		$cache_key = 'track_mbid_' . $mbid;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->call_method( 'track.getInfo', [ 'mbid' => $mbid ] );

			if ( isset( $response['track'] ) ) {
				$result = $this->normalize_track_info( $response['track'] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get track by MBID failed',
				[
					'mbid'  => $mbid,
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get recent tracks for a user.
	 *
	 * @param string $username Last.fm username.
	 * @param int    $limit    Number of tracks.
	 * @param int    $page     Page number.
	 * @param int    $from     Start timestamp.
	 * @param int    $to       End timestamp.
	 * @return array<string, mixed> Recent tracks with pagination info.
	 */
	public function get_recent_tracks( string $username, int $limit = 50, int $page = 1, int $from = 0, int $to = 0 ): array {
		$params = [
			'user'     => $username,
			'limit'    => min( $limit, 200 ),
			'page'     => $page,
			'extended' => 1,
		];

		if ( $from > 0 ) {
			$params['from'] = $from;
		}

		if ( $to > 0 ) {
			$params['to'] = $to;
		}

		// Don't cache paginated results.
		$cache_key = null;
		if ( 1 === $page && 0 === $from && 0 === $to ) {
			$cache_key = 'recent_' . md5( $username . $limit );
			$cached    = $this->get_cache( $cache_key );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		try {
			$response = $this->call_method( 'user.getRecentTracks', $params );

			$result = [
				'tracks'      => [],
				'total'       => 0,
				'page'        => $page,
				'per_page'    => $limit,
				'total_pages' => 0,
			];

			if ( isset( $response['recenttracks'] ) ) {
				$attr                  = $response['recenttracks']['@attr'] ?? [];
				$result['total']       = (int) ( $attr['total'] ?? 0 );
				$result['page']        = (int) ( $attr['page'] ?? 1 );
				$result['per_page']    = (int) ( $attr['perPage'] ?? $limit );
				$result['total_pages'] = (int) ( $attr['totalPages'] ?? 0 );

				$tracks = $response['recenttracks']['track'] ?? [];

				// Ensure it's an array of tracks.
				if ( isset( $tracks['name'] ) ) {
					$tracks = [ $tracks ];
				}

				foreach ( $tracks as $track ) {
					// Skip "now playing" tracks if we want historical.
					if ( isset( $track['@attr']['nowplaying'] ) ) {
						continue;
					}

					$result['tracks'][] = $this->normalize_scrobble( $track );
				}
			}

			if ( $cache_key ) {
				$this->set_cache( $cache_key, $result, 300 ); // 5 minutes.
			}

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get recent tracks failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return [
				'tracks'      => [],
				'total'       => 0,
				'page'        => $page,
				'per_page'    => $limit,
				'total_pages' => 0,
			];
		}
	}

	/**
	 * Get currently playing track.
	 *
	 * @param string $username Last.fm username.
	 * @return array<string, mixed>|null Now playing track or null.
	 */
	public function get_now_playing( string $username ): ?array {
		try {
			$response = $this->call_method(
				'user.getRecentTracks',
				[
					'user'  => $username,
					'limit' => 1,
				]
			);

			if ( isset( $response['recenttracks']['track'][0] ) ) {
				$track = $response['recenttracks']['track'][0];

				if ( isset( $track['@attr']['nowplaying'] ) && 'true' === $track['@attr']['nowplaying'] ) {
					return $this->normalize_scrobble( $track );
				}
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get now playing failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get user top artists.
	 *
	 * @param string $username Last.fm username.
	 * @param string $period   Period: overall, 7day, 1month, 3month, 6month, 12month.
	 * @param int    $limit    Number of artists.
	 * @return array<int, array<string, mixed>> Top artists.
	 */
	public function get_top_artists( string $username, string $period = '7day', int $limit = 25 ): array {
		$cache_key = 'top_artists_' . md5( $username . $period . $limit );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->call_method(
				'user.getTopArtists',
				[
					'user'   => $username,
					'period' => $period,
					'limit'  => min( $limit, 100 ),
				]
			);

			$artists = [];

			if ( isset( $response['topartists']['artist'] ) ) {
				$list = $response['topartists']['artist'];

				// Ensure it's an array.
				if ( isset( $list['name'] ) ) {
					$list = [ $list ];
				}

				foreach ( $list as $artist ) {
					$artists[] = [
						'name'      => $artist['name'] ?? '',
						'playcount' => (int) ( $artist['playcount'] ?? 0 ),
						'mbid'      => $artist['mbid'] ?? '',
						'url'       => $artist['url'] ?? '',
						'image'     => $this->get_best_image( $artist['image'] ?? [] ),
					];
				}
			}

			$this->set_cache( $cache_key, $artists );

			return $artists;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get top artists failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get user top tracks.
	 *
	 * @param string $username Last.fm username.
	 * @param string $period   Period.
	 * @param int    $limit    Number of tracks.
	 * @return array<int, array<string, mixed>> Top tracks.
	 */
	public function get_top_tracks( string $username, string $period = '7day', int $limit = 25 ): array {
		$cache_key = 'top_tracks_' . md5( $username . $period . $limit );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->call_method(
				'user.getTopTracks',
				[
					'user'   => $username,
					'period' => $period,
					'limit'  => min( $limit, 100 ),
				]
			);

			$tracks = [];

			if ( isset( $response['toptracks']['track'] ) ) {
				$list = $response['toptracks']['track'];

				if ( isset( $list['name'] ) ) {
					$list = [ $list ];
				}

				foreach ( $list as $track ) {
					$tracks[] = [
						'track'     => $track['name'] ?? '',
						'artist'    => $track['artist']['name'] ?? '',
						'playcount' => (int) ( $track['playcount'] ?? 0 ),
						'mbid'      => $track['mbid'] ?? '',
						'url'       => $track['url'] ?? '',
						'image'     => $this->get_best_image( $track['image'] ?? [] ),
					];
				}
			}

			$this->set_cache( $cache_key, $tracks );

			return $tracks;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get top tracks failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return [];
		}
	}

	/**
	 * Get user info.
	 *
	 * @param string $username Last.fm username.
	 * @return array<string, mixed>|null User info.
	 */
	public function get_user_info( string $username ): ?array {
		$cache_key = 'user_' . $username;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->call_method( 'user.getInfo', [ 'user' => $username ] );

			if ( isset( $response['user'] ) ) {
				$user = $response['user'];

				$result = [
					'username'   => $user['name'] ?? '',
					'real_name'  => $user['realname'] ?? '',
					'url'        => $user['url'] ?? '',
					'country'    => $user['country'] ?? '',
					'playcount'  => (int) ( $user['playcount'] ?? 0 ),
					'registered' => $user['registered']['unixtime'] ?? null,
					'image'      => $this->get_best_image( $user['image'] ?? [] ),
				];

				$this->set_cache( $cache_key, $result, DAY_IN_SECONDS );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get user info failed',
				[
					'username' => $username,
					'error'    => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get album info.
	 *
	 * @param string $album  Album name.
	 * @param string $artist Artist name.
	 * @return array<string, mixed>|null Album info.
	 */
	public function get_album_info( string $album, string $artist ): ?array {
		$cache_key = 'album_' . md5( $album . $artist );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->call_method(
				'album.getInfo',
				[
					'album'       => $album,
					'artist'      => $artist,
					'autocorrect' => 1,
				]
			);

			if ( isset( $response['album'] ) ) {
				$data = $response['album'];

				$result = [
					'album'  => $data['name'] ?? '',
					'artist' => $data['artist'] ?? '',
					'mbid'   => $data['mbid'] ?? '',
					'url'    => $data['url'] ?? '',
					'image'  => $this->get_best_image( $data['image'] ?? [] ),
					'tracks' => [],
					'tags'   => [],
				];

				// Parse tracks.
				if ( isset( $data['tracks']['track'] ) ) {
					$tracks = $data['tracks']['track'];
					if ( isset( $tracks['name'] ) ) {
						$tracks = [ $tracks ];
					}

					foreach ( $tracks as $track ) {
						$result['tracks'][] = [
							'name'     => $track['name'] ?? '',
							'duration' => (int) ( $track['duration'] ?? 0 ),
							'rank'     => (int) ( $track['@attr']['rank'] ?? 0 ),
						];
					}
				}

				// Parse tags.
				if ( isset( $data['tags']['tag'] ) ) {
					$tags = $data['tags']['tag'];
					if ( isset( $tags['name'] ) ) {
						$tags = [ $tags ];
					}

					foreach ( $tags as $tag ) {
						$result['tags'][] = $tag['name'] ?? '';
					}
				}

				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get album info failed',
				[
					'album'  => $album,
					'artist' => $artist,
					'error'  => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Get artist info.
	 *
	 * @param string $artist Artist name.
	 * @return array<string, mixed>|null Artist info.
	 */
	public function get_artist_info( string $artist ): ?array {
		$cache_key = 'artist_' . md5( $artist );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->call_method(
				'artist.getInfo',
				[
					'artist'      => $artist,
					'autocorrect' => 1,
				]
			);

			if ( isset( $response['artist'] ) ) {
				$data = $response['artist'];

				$result = [
					'name'      => $data['name'] ?? '',
					'mbid'      => $data['mbid'] ?? '',
					'url'       => $data['url'] ?? '',
					'image'     => $this->get_best_image( $data['image'] ?? [] ),
					'listeners' => (int) ( $data['stats']['listeners'] ?? 0 ),
					'playcount' => (int) ( $data['stats']['playcount'] ?? 0 ),
					'bio'       => $data['bio']['summary'] ?? '',
					'tags'      => [],
					'similar'   => [],
				];

				// Parse tags.
				if ( isset( $data['tags']['tag'] ) ) {
					$tags = $data['tags']['tag'];
					if ( isset( $tags['name'] ) ) {
						$tags = [ $tags ];
					}

					foreach ( $tags as $tag ) {
						$result['tags'][] = $tag['name'] ?? '';
					}
				}

				// Parse similar artists.
				if ( isset( $data['similar']['artist'] ) ) {
					$similar = $data['similar']['artist'];
					if ( isset( $similar['name'] ) ) {
						$similar = [ $similar ];
					}

					foreach ( $similar as $sim ) {
						$result['similar'][] = [
							'name'  => $sim['name'] ?? '',
							'url'   => $sim['url'] ?? '',
							'image' => $this->get_best_image( $sim['image'] ?? [] ),
						];
					}
				}

				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Get artist info failed',
				[
					'artist' => $artist,
					'error'  => $e->getMessage(),
				]
			);
			return null;
		}
	}

	/**
	 * Scrobble a track (requires authentication).
	 *
	 * @param array<string, mixed> $track       Track data.
	 * @param int                  $timestamp   Unix timestamp.
	 * @return bool Success.
	 */
	public function scrobble( array $track, int $timestamp = 0 ): bool {
		if ( ! $this->session_key ) {
			$this->log_error( 'Cannot scrobble without session key' );
			return false;
		}

		try {
			$params = [
				'artist'    => $track['artist'] ?? '',
				'track'     => $track['track'] ?? '',
				'timestamp' => $timestamp > 0 ? $timestamp : time(),
			];

			if ( ! empty( $track['album'] ) ) {
				$params['album'] = $track['album'];
			}

			if ( ! empty( $track['duration'] ) ) {
				$params['duration'] = (int) $track['duration'];
			}

			if ( ! empty( $track['mbid'] ) ) {
				$params['mbid'] = $track['mbid'];
			}

			$this->post(
				'',
				array_merge(
					$params,
					[
						'method'  => 'track.scrobble',
						'api_key' => $this->api_key,
						'sk'      => $this->session_key,
						'api_sig' => $this->generate_signature(
							array_merge(
								$params,
								[
									'method'  => 'track.scrobble',
									'api_key' => $this->api_key,
									'sk'      => $this->session_key,
								]
							)
						),
					]
				)
			);

			return true;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Scrobble failed',
				[
					'track' => $track,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Update "now playing" (requires authentication).
	 *
	 * @param array<string, mixed> $track Track data.
	 * @return bool Success.
	 */
	public function update_now_playing( array $track ): bool {
		if ( ! $this->session_key ) {
			return false;
		}

		try {
			$params = [
				'artist' => $track['artist'] ?? '',
				'track'  => $track['track'] ?? '',
			];

			if ( ! empty( $track['album'] ) ) {
				$params['album'] = $track['album'];
			}

			if ( ! empty( $track['duration'] ) ) {
				$params['duration'] = (int) $track['duration'];
			}

			$this->post(
				'',
				array_merge(
					$params,
					[
						'method'  => 'track.updateNowPlaying',
						'api_key' => $this->api_key,
						'sk'      => $this->session_key,
						'api_sig' => $this->generate_signature(
							array_merge(
								$params,
								[
									'method'  => 'track.updateNowPlaying',
									'api_key' => $this->api_key,
									'sk'      => $this->session_key,
								]
							)
						),
					]
				)
			);

			return true;
		} catch ( \Exception $e ) {
			$this->log_error(
				'Update now playing failed',
				[
					'track' => $track,
					'error' => $e->getMessage(),
				]
			);
			return false;
		}
	}

	/**
	 * Normalize search result.
	 *
	 * @param array<string, mixed> $raw_result Raw result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		return [
			'track'  => $raw_result['name'] ?? '',
			'artist' => $raw_result['artist'] ?? '',
			'mbid'   => $raw_result['mbid'] ?? '',
			'url'    => $raw_result['url'] ?? '',
			'image'  => $this->get_best_image( $raw_result['image'] ?? [] ),
			'source' => 'lastfm',
		];
	}

	/**
	 * Normalize track info response.
	 *
	 * @param array<string, mixed> $track Track data.
	 * @return array<string, mixed> Normalized track.
	 */
	private function normalize_track_info( array $track ): array {
		return [
			'track'       => $track['name'] ?? '',
			'artist'      => $track['artist']['name'] ?? '',
			'album'       => $track['album']['title'] ?? '',
			'mbid'        => $track['mbid'] ?? '',
			'artist_mbid' => $track['artist']['mbid'] ?? '',
			'album_mbid'  => $track['album']['mbid'] ?? '',
			'url'         => $track['url'] ?? '',
			'image'       => $this->get_best_image( $track['album']['image'] ?? [] ),
			'duration'    => isset( $track['duration'] ) ? (int) ( $track['duration'] / 1000 ) : null,
			'listeners'   => (int) ( $track['listeners'] ?? 0 ),
			'playcount'   => (int) ( $track['playcount'] ?? 0 ),
			'tags'        => $this->extract_tags( $track['toptags']['tag'] ?? [] ),
			'source'      => 'lastfm',
		];
	}

	/**
	 * Normalize scrobble data.
	 *
	 * @param array<string, mixed> $scrobble Scrobble data.
	 * @return array<string, mixed> Normalized scrobble.
	 */
	private function normalize_scrobble( array $scrobble ): array {
		return [
			'track'       => $scrobble['name'] ?? '',
			'artist'      => $scrobble['artist']['name'] ?? ( $scrobble['artist']['#text'] ?? '' ),
			'album'       => $scrobble['album']['#text'] ?? '',
			'mbid'        => $scrobble['mbid'] ?? '',
			'artist_mbid' => $scrobble['artist']['mbid'] ?? '',
			'album_mbid'  => $scrobble['album']['mbid'] ?? '',
			'url'         => $scrobble['url'] ?? '',
			'image'       => $this->get_best_image( $scrobble['image'] ?? [] ),
			'listened_at' => isset( $scrobble['date']['uts'] ) ? (int) $scrobble['date']['uts'] : null,
			'loved'       => isset( $scrobble['loved'] ) && '1' === $scrobble['loved'],
			'source'      => 'lastfm',
		];
	}

	/**
	 * Get best image from array.
	 *
	 * @param array<int, array<string, mixed>> $images Images array.
	 * @return string|null Best image URL.
	 */
	private function get_best_image( array $images ): ?string {
		if ( empty( $images ) ) {
			return null;
		}

		// Prefer extralarge, then large, then medium.
		$preferred = [ 'extralarge', 'large', 'medium', 'small' ];

		foreach ( $preferred as $size ) {
			foreach ( $images as $image ) {
				if ( isset( $image['size'] ) && $size === $image['size'] && ! empty( $image['#text'] ) ) {
					return $image['#text'];
				}
			}
		}

		// Fallback to first available.
		foreach ( $images as $image ) {
			if ( ! empty( $image['#text'] ) ) {
				return $image['#text'];
			}
		}

		return null;
	}

	/**
	 * Extract tags from response.
	 *
	 * @param array<int, array<string, mixed>> $tags Tags array.
	 * @return array<int, string> Tag names.
	 */
	private function extract_tags( array $tags ): array {
		if ( empty( $tags ) ) {
			return [];
		}

		// Handle single tag.
		if ( isset( $tags['name'] ) ) {
			return [ $tags['name'] ];
		}

		$result = [];
		foreach ( $tags as $tag ) {
			if ( isset( $tag['name'] ) ) {
				$result[] = $tag['name'];
			}
		}

		return $result;
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
	 * Set session key.
	 *
	 * @param string $key Session key.
	 * @return void
	 */
	public function set_session_key( string $key ): void {
		$this->session_key = $key;
	}

	/**
	 * Check if authenticated (has session key).
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool {
		return ! empty( $this->session_key );
	}

	/**
	 * Get authentication URL for Last.fm.
	 *
	 * Users must visit this URL to authorize the application.
	 *
	 * @param string $callback_url URL to redirect to after auth.
	 * @return string|null Auth URL or null if not configured.
	 */
	public function get_auth_url( string $callback_url ): ?string {
		if ( empty( $this->api_key ) ) {
			return null;
		}

		return add_query_arg(
			[
				'api_key' => $this->api_key,
				'cb'      => $callback_url,
			],
			'https://www.last.fm/api/auth/'
		);
	}

	/**
	 * Exchange auth token for session key.
	 *
	 * Called after user authorizes at Last.fm and is redirected back with a token.
	 *
	 * @param string $token Auth token from callback.
	 * @return array{session_key: string, username: string}|null Session data or null on failure.
	 */
	public function get_session( string $token ): ?array {
		if ( empty( $this->api_key ) || empty( $this->api_secret ) ) {
			return null;
		}

		try {
			$params = [
				'method'  => 'auth.getSession',
				'api_key' => $this->api_key,
				'token'   => $token,
			];

			$params['api_sig'] = $this->generate_signature( $params );
			$params['format']  = 'json';

			$response = wp_remote_get(
				add_query_arg( $params, 'https://ws.audioscrobbler.com/2.0/' ),
				[ 'timeout' => 30 ]
			);

			if ( is_wp_error( $response ) ) {
				$this->log_error( 'Get session failed', [ 'error' => $response->get_error_message() ] );
				return null;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( isset( $body['session']['key'] ) ) {
				return [
					'session_key' => $body['session']['key'],
					'username'    => $body['session']['name'] ?? '',
				];
			}

			$this->log_error( 'Get session failed', [ 'response' => $body ] );
			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get session exception', [ 'error' => $e->getMessage() ] );
			return null;
		}
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://www.last.fm/api';
	}
}

<?php
/**
 * Last.fm API Integration
 *
 * Provides scrobble import and music metadata from Last.fm.
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
		$this->api_key     = $this->get_option( 'lastfm_api_key' );
		$this->api_secret  = $this->get_option( 'lastfm_api_secret' );
		$this->session_key = $this->get_option( 'lastfm_session_key' );
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
			$this->call_method( 'artist.getInfo', array( 'artist' => 'Radiohead' ) );
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
	private function call_method( string $method, array $params = array(), bool $signed = false ): array {
		$params['method']  = $method;
		$params['api_key'] = $this->api_key;
		$params['format']  = 'json';

		if ( $signed && $this->session_key ) {
			$params['sk'] = $this->session_key;
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
			$params = array(
				'track' => $query,
				'limit' => 25,
			);

			if ( $artist ) {
				$params['artist'] = $artist;
			}

			$response = $this->call_method( 'track.search', $params );

			$results = array();

			if ( isset( $response['results']['trackmatches']['track'] ) ) {
				$tracks = $response['results']['trackmatches']['track'];

				// Ensure it's an array of tracks.
				if ( isset( $tracks['name'] ) ) {
					$tracks = array( $tracks );
				}

				foreach ( $tracks as $track ) {
					$results[] = $this->normalize_result( $track );
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
				array(
					'track'       => $track,
					'artist'      => $artist,
					'autocorrect' => 1,
				)
			);

			if ( isset( $response['track'] ) ) {
				$result = $this->normalize_track_info( $response['track'] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get track info failed', array( 'track' => $track, 'artist' => $artist, 'error' => $e->getMessage() ) );
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
			$response = $this->call_method( 'track.getInfo', array( 'mbid' => $mbid ) );

			if ( isset( $response['track'] ) ) {
				$result = $this->normalize_track_info( $response['track'] );
				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get track by MBID failed', array( 'mbid' => $mbid, 'error' => $e->getMessage() ) );
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
		$params = array(
			'user'     => $username,
			'limit'    => min( $limit, 200 ),
			'page'     => $page,
			'extended' => 1,
		);

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

			$result = array(
				'tracks'      => array(),
				'total'       => 0,
				'page'        => $page,
				'per_page'    => $limit,
				'total_pages' => 0,
			);

			if ( isset( $response['recenttracks'] ) ) {
				$attr = $response['recenttracks']['@attr'] ?? array();
				$result['total']       = (int) ( $attr['total'] ?? 0 );
				$result['page']        = (int) ( $attr['page'] ?? 1 );
				$result['per_page']    = (int) ( $attr['perPage'] ?? $limit );
				$result['total_pages'] = (int) ( $attr['totalPages'] ?? 0 );

				$tracks = $response['recenttracks']['track'] ?? array();

				// Ensure it's an array of tracks.
				if ( isset( $tracks['name'] ) ) {
					$tracks = array( $tracks );
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
			$this->log_error( 'Get recent tracks failed', array( 'username' => $username, 'error' => $e->getMessage() ) );
			return array(
				'tracks'      => array(),
				'total'       => 0,
				'page'        => $page,
				'per_page'    => $limit,
				'total_pages' => 0,
			);
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
				array(
					'user'  => $username,
					'limit' => 1,
				)
			);

			if ( isset( $response['recenttracks']['track'][0] ) ) {
				$track = $response['recenttracks']['track'][0];

				if ( isset( $track['@attr']['nowplaying'] ) && 'true' === $track['@attr']['nowplaying'] ) {
					return $this->normalize_scrobble( $track );
				}
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get now playing failed', array( 'username' => $username, 'error' => $e->getMessage() ) );
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
				array(
					'user'   => $username,
					'period' => $period,
					'limit'  => min( $limit, 100 ),
				)
			);

			$artists = array();

			if ( isset( $response['topartists']['artist'] ) ) {
				$list = $response['topartists']['artist'];

				// Ensure it's an array.
				if ( isset( $list['name'] ) ) {
					$list = array( $list );
				}

				foreach ( $list as $artist ) {
					$artists[] = array(
						'name'       => $artist['name'] ?? '',
						'playcount'  => (int) ( $artist['playcount'] ?? 0 ),
						'mbid'       => $artist['mbid'] ?? '',
						'url'        => $artist['url'] ?? '',
						'image'      => $this->get_best_image( $artist['image'] ?? array() ),
					);
				}
			}

			$this->set_cache( $cache_key, $artists );

			return $artists;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get top artists failed', array( 'username' => $username, 'error' => $e->getMessage() ) );
			return array();
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
				array(
					'user'   => $username,
					'period' => $period,
					'limit'  => min( $limit, 100 ),
				)
			);

			$tracks = array();

			if ( isset( $response['toptracks']['track'] ) ) {
				$list = $response['toptracks']['track'];

				if ( isset( $list['name'] ) ) {
					$list = array( $list );
				}

				foreach ( $list as $track ) {
					$tracks[] = array(
						'track'      => $track['name'] ?? '',
						'artist'     => $track['artist']['name'] ?? '',
						'playcount'  => (int) ( $track['playcount'] ?? 0 ),
						'mbid'       => $track['mbid'] ?? '',
						'url'        => $track['url'] ?? '',
						'image'      => $this->get_best_image( $track['image'] ?? array() ),
					);
				}
			}

			$this->set_cache( $cache_key, $tracks );

			return $tracks;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get top tracks failed', array( 'username' => $username, 'error' => $e->getMessage() ) );
			return array();
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
			$response = $this->call_method( 'user.getInfo', array( 'user' => $username ) );

			if ( isset( $response['user'] ) ) {
				$user = $response['user'];

				$result = array(
					'username'    => $user['name'] ?? '',
					'real_name'   => $user['realname'] ?? '',
					'url'         => $user['url'] ?? '',
					'country'     => $user['country'] ?? '',
					'playcount'   => (int) ( $user['playcount'] ?? 0 ),
					'registered'  => $user['registered']['unixtime'] ?? null,
					'image'       => $this->get_best_image( $user['image'] ?? array() ),
				);

				$this->set_cache( $cache_key, $result, DAY_IN_SECONDS );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get user info failed', array( 'username' => $username, 'error' => $e->getMessage() ) );
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
				array(
					'album'       => $album,
					'artist'      => $artist,
					'autocorrect' => 1,
				)
			);

			if ( isset( $response['album'] ) ) {
				$data = $response['album'];

				$result = array(
					'album'    => $data['name'] ?? '',
					'artist'   => $data['artist'] ?? '',
					'mbid'     => $data['mbid'] ?? '',
					'url'      => $data['url'] ?? '',
					'image'    => $this->get_best_image( $data['image'] ?? array() ),
					'tracks'   => array(),
					'tags'     => array(),
				);

				// Parse tracks.
				if ( isset( $data['tracks']['track'] ) ) {
					$tracks = $data['tracks']['track'];
					if ( isset( $tracks['name'] ) ) {
						$tracks = array( $tracks );
					}

					foreach ( $tracks as $track ) {
						$result['tracks'][] = array(
							'name'     => $track['name'] ?? '',
							'duration' => (int) ( $track['duration'] ?? 0 ),
							'rank'     => (int) ( $track['@attr']['rank'] ?? 0 ),
						);
					}
				}

				// Parse tags.
				if ( isset( $data['tags']['tag'] ) ) {
					$tags = $data['tags']['tag'];
					if ( isset( $tags['name'] ) ) {
						$tags = array( $tags );
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
			$this->log_error( 'Get album info failed', array( 'album' => $album, 'artist' => $artist, 'error' => $e->getMessage() ) );
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
				array(
					'artist'      => $artist,
					'autocorrect' => 1,
				)
			);

			if ( isset( $response['artist'] ) ) {
				$data = $response['artist'];

				$result = array(
					'name'      => $data['name'] ?? '',
					'mbid'      => $data['mbid'] ?? '',
					'url'       => $data['url'] ?? '',
					'image'     => $this->get_best_image( $data['image'] ?? array() ),
					'listeners' => (int) ( $data['stats']['listeners'] ?? 0 ),
					'playcount' => (int) ( $data['stats']['playcount'] ?? 0 ),
					'bio'       => $data['bio']['summary'] ?? '',
					'tags'      => array(),
					'similar'   => array(),
				);

				// Parse tags.
				if ( isset( $data['tags']['tag'] ) ) {
					$tags = $data['tags']['tag'];
					if ( isset( $tags['name'] ) ) {
						$tags = array( $tags );
					}

					foreach ( $tags as $tag ) {
						$result['tags'][] = $tag['name'] ?? '';
					}
				}

				// Parse similar artists.
				if ( isset( $data['similar']['artist'] ) ) {
					$similar = $data['similar']['artist'];
					if ( isset( $similar['name'] ) ) {
						$similar = array( $similar );
					}

					foreach ( $similar as $sim ) {
						$result['similar'][] = array(
							'name'  => $sim['name'] ?? '',
							'url'   => $sim['url'] ?? '',
							'image' => $this->get_best_image( $sim['image'] ?? array() ),
						);
					}
				}

				$this->set_cache( $cache_key, $result );
				return $result;
			}

			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get artist info failed', array( 'artist' => $artist, 'error' => $e->getMessage() ) );
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
			$params = array(
				'artist'    => $track['artist'] ?? '',
				'track'     => $track['track'] ?? '',
				'timestamp' => $timestamp > 0 ? $timestamp : time(),
			);

			if ( ! empty( $track['album'] ) ) {
				$params['album'] = $track['album'];
			}

			if ( ! empty( $track['duration'] ) ) {
				$params['duration'] = (int) $track['duration'];
			}

			if ( ! empty( $track['mbid'] ) ) {
				$params['mbid'] = $track['mbid'];
			}

			$this->post( '', array_merge( $params, array(
				'method'  => 'track.scrobble',
				'api_key' => $this->api_key,
				'sk'      => $this->session_key,
				'api_sig' => $this->generate_signature( array_merge( $params, array(
					'method'  => 'track.scrobble',
					'api_key' => $this->api_key,
					'sk'      => $this->session_key,
				) ) ),
			) ) );

			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Scrobble failed', array( 'track' => $track, 'error' => $e->getMessage() ) );
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
			$params = array(
				'artist' => $track['artist'] ?? '',
				'track'  => $track['track'] ?? '',
			);

			if ( ! empty( $track['album'] ) ) {
				$params['album'] = $track['album'];
			}

			if ( ! empty( $track['duration'] ) ) {
				$params['duration'] = (int) $track['duration'];
			}

			$this->post( '', array_merge( $params, array(
				'method'  => 'track.updateNowPlaying',
				'api_key' => $this->api_key,
				'sk'      => $this->session_key,
				'api_sig' => $this->generate_signature( array_merge( $params, array(
					'method'  => 'track.updateNowPlaying',
					'api_key' => $this->api_key,
					'sk'      => $this->session_key,
				) ) ),
			) ) );

			return true;
		} catch ( \Exception $e ) {
			$this->log_error( 'Update now playing failed', array( 'track' => $track, 'error' => $e->getMessage() ) );
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
		return array(
			'track'   => $raw_result['name'] ?? '',
			'artist'  => $raw_result['artist'] ?? '',
			'mbid'    => $raw_result['mbid'] ?? '',
			'url'     => $raw_result['url'] ?? '',
			'image'   => $this->get_best_image( $raw_result['image'] ?? array() ),
			'source'  => 'lastfm',
		);
	}

	/**
	 * Normalize track info response.
	 *
	 * @param array<string, mixed> $track Track data.
	 * @return array<string, mixed> Normalized track.
	 */
	private function normalize_track_info( array $track ): array {
		return array(
			'track'      => $track['name'] ?? '',
			'artist'     => $track['artist']['name'] ?? '',
			'album'      => $track['album']['title'] ?? '',
			'mbid'       => $track['mbid'] ?? '',
			'artist_mbid'=> $track['artist']['mbid'] ?? '',
			'album_mbid' => $track['album']['mbid'] ?? '',
			'url'        => $track['url'] ?? '',
			'image'      => $this->get_best_image( $track['album']['image'] ?? array() ),
			'duration'   => isset( $track['duration'] ) ? (int) ( $track['duration'] / 1000 ) : null,
			'listeners'  => (int) ( $track['listeners'] ?? 0 ),
			'playcount'  => (int) ( $track['playcount'] ?? 0 ),
			'tags'       => $this->extract_tags( $track['toptags']['tag'] ?? array() ),
			'source'     => 'lastfm',
		);
	}

	/**
	 * Normalize scrobble data.
	 *
	 * @param array<string, mixed> $scrobble Scrobble data.
	 * @return array<string, mixed> Normalized scrobble.
	 */
	private function normalize_scrobble( array $scrobble ): array {
		return array(
			'track'       => $scrobble['name'] ?? '',
			'artist'      => $scrobble['artist']['name'] ?? ( $scrobble['artist']['#text'] ?? '' ),
			'album'       => $scrobble['album']['#text'] ?? '',
			'mbid'        => $scrobble['mbid'] ?? '',
			'artist_mbid' => $scrobble['artist']['mbid'] ?? '',
			'album_mbid'  => $scrobble['album']['mbid'] ?? '',
			'url'         => $scrobble['url'] ?? '',
			'image'       => $this->get_best_image( $scrobble['image'] ?? array() ),
			'listened_at' => isset( $scrobble['date']['uts'] ) ? (int) $scrobble['date']['uts'] : null,
			'loved'       => isset( $scrobble['loved'] ) && '1' === $scrobble['loved'],
			'source'      => 'lastfm',
		);
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
		$preferred = array( 'extralarge', 'large', 'medium', 'small' );

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
			return array();
		}

		// Handle single tag.
		if ( isset( $tags['name'] ) ) {
			return array( $tags['name'] );
		}

		$result = array();
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
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://www.last.fm/api';
	}
}

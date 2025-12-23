<?php
/**
 * MusicBrainz API Integration
 *
 * Provides music metadata lookup via the MusicBrainz API and Cover Art Archive.
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
 * MusicBrainz API class.
 *
 * @since 1.0.0
 */
class MusicBrainz extends API_Base {

	/**
	 * API name.
	 *
	 * @var string
	 */
	protected string $api_name = 'musicbrainz';

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	protected string $base_url = 'https://musicbrainz.org/ws/2/';

	/**
	 * Cover Art Archive base URL.
	 *
	 * @var string
	 */
	protected string $cover_art_url = 'https://coverartarchive.org/';

	/**
	 * Rate limit: 1 request per second.
	 *
	 * @var float
	 */
	protected float $rate_limit = 1.0;

	/**
	 * Cache duration: 1 week.
	 *
	 * @var int
	 */
	protected int $cache_duration = WEEK_IN_SECONDS;

	/**
	 * Get default headers.
	 *
	 * @return array<string, string>
	 */
	protected function get_default_headers(): array {
		return array(
			'Accept' => 'application/json',
		);
	}

	/**
	 * Test API connection.
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		try {
			$this->get( 'artist/5b11f4ce-a62d-471e-81fc-a69a8278c7da', array( 'fmt' => 'json' ) );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Search for recordings (tracks).
	 *
	 * @param string      $query  Search query.
	 * @param string|null $artist Artist name to filter by.
	 * @return array<int, array<string, mixed>> Search results.
	 */
	public function search( string $query, ...$args ): array {
		$artist = $args[0] ?? null;

		// Build Lucene query.
		$lucene_query = 'recording:"' . $this->escape_lucene( $query ) . '"';

		if ( $artist ) {
			$lucene_query .= ' AND artist:"' . $this->escape_lucene( $artist ) . '"';
		}

		$cache_key = 'search_' . md5( $lucene_query );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'recording',
				array(
					'query' => $lucene_query,
					'fmt'   => 'json',
					'limit' => 25,
				)
			);

			$results = array();

			if ( isset( $response['recordings'] ) && is_array( $response['recordings'] ) ) {
				foreach ( $response['recordings'] as $recording ) {
					$results[] = $this->normalize_result( $recording );
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
	 * Search by artist name.
	 *
	 * @param string $artist Artist name.
	 * @return array<int, array<string, mixed>> Artist results.
	 */
	public function search_artist( string $artist ): array {
		$cache_key = 'artist_search_' . md5( $artist );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'artist',
				array(
					'query' => 'artist:"' . $this->escape_lucene( $artist ) . '"',
					'fmt'   => 'json',
					'limit' => 10,
				)
			);

			$results = array();

			if ( isset( $response['artists'] ) && is_array( $response['artists'] ) ) {
				foreach ( $response['artists'] as $artist_data ) {
					$results[] = array(
						'id'       => $artist_data['id'],
						'name'     => $artist_data['name'],
						'sort_name'=> $artist_data['sort-name'] ?? $artist_data['name'],
						'type'     => $artist_data['type'] ?? 'Unknown',
						'country'  => $artist_data['country'] ?? '',
						'score'    => $artist_data['score'] ?? 0,
					);
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Artist search failed', array( 'artist' => $artist, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Search for releases (albums).
	 *
	 * @param string      $query  Search query.
	 * @param string|null $artist Artist name.
	 * @return array<int, array<string, mixed>> Release results.
	 */
	public function search_release( string $query, ?string $artist = null ): array {
		$lucene_query = 'release:"' . $this->escape_lucene( $query ) . '"';

		if ( $artist ) {
			$lucene_query .= ' AND artist:"' . $this->escape_lucene( $artist ) . '"';
		}

		$cache_key = 'release_search_' . md5( $lucene_query );
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'release',
				array(
					'query' => $lucene_query,
					'fmt'   => 'json',
					'limit' => 25,
				)
			);

			$results = array();

			if ( isset( $response['releases'] ) && is_array( $response['releases'] ) ) {
				foreach ( $response['releases'] as $release ) {
					$results[] = $this->normalize_release( $release );
				}
			}

			$this->set_cache( $cache_key, $results );

			return $results;
		} catch ( \Exception $e ) {
			$this->log_error( 'Release search failed', array( 'query' => $query, 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Get recording by ID.
	 *
	 * @param string $id Recording MBID.
	 * @return array<string, mixed>|null Recording data.
	 */
	public function get_by_id( string $id ): ?array {
		$cache_key = 'recording_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'recording/' . $id,
				array(
					'fmt' => 'json',
					'inc' => 'artists+releases+release-groups',
				)
			);

			$result = $this->normalize_result( $response );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get recording failed', array( 'id' => $id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get release by ID.
	 *
	 * @param string $id Release MBID.
	 * @return array<string, mixed>|null Release data.
	 */
	public function get_release( string $id ): ?array {
		$cache_key = 'release_' . $id;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$response = $this->get(
				'release/' . $id,
				array(
					'fmt' => 'json',
					'inc' => 'artists+recordings+release-groups',
				)
			);

			$result = $this->normalize_release( $response );

			// Try to get cover art.
			$result['cover'] = $this->get_cover_art( $id );

			$this->set_cache( $cache_key, $result );

			return $result;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get release failed', array( 'id' => $id, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Get cover art for a release.
	 *
	 * @param string $release_mbid Release MBID.
	 * @param string $size         Size: small, large, or empty for original.
	 * @return string|null Cover art URL or null.
	 */
	public function get_cover_art( string $release_mbid, string $size = '' ): ?string {
		$cache_key = 'cover_' . $release_mbid . '_' . $size;
		$cached    = $this->get_cache( $cache_key );

		if ( null !== $cached ) {
			return $cached ?: null;
		}

		try {
			// Cover Art Archive returns redirects to the actual image.
			$endpoint = 'release/' . $release_mbid . '/front';

			if ( $size ) {
				$endpoint .= '-' . $size;
			}

			$response = wp_safe_remote_head(
				$this->cover_art_url . $endpoint,
				array(
					'timeout'     => 10,
					'redirection' => 0, // Don't follow redirects, just get the Location.
					'user-agent'  => $this->user_agent,
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->set_cache( $cache_key, '', HOUR_IN_SECONDS );
				return null;
			}

			$code = wp_remote_retrieve_response_code( $response );

			// 307 redirect contains the image URL.
			if ( 307 === $code || 302 === $code ) {
				$location = wp_remote_retrieve_header( $response, 'location' );

				if ( $location ) {
					$this->set_cache( $cache_key, $location );
					return $location;
				}
			}

			$this->set_cache( $cache_key, '', HOUR_IN_SECONDS );
			return null;
		} catch ( \Exception $e ) {
			$this->log_error( 'Get cover art failed', array( 'mbid' => $release_mbid, 'error' => $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Normalize recording result.
	 *
	 * @param array<string, mixed> $raw_result Raw API result.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_result( array $raw_result ): array {
		$artist = '';
		$artist_mbid = '';

		if ( isset( $raw_result['artist-credit'] ) && ! empty( $raw_result['artist-credit'] ) ) {
			$artist_parts = array();

			foreach ( $raw_result['artist-credit'] as $credit ) {
				if ( isset( $credit['artist']['name'] ) ) {
					$artist_parts[] = $credit['artist']['name'];

					if ( empty( $artist_mbid ) && isset( $credit['artist']['id'] ) ) {
						$artist_mbid = $credit['artist']['id'];
					}
				}

				if ( isset( $credit['joinphrase'] ) ) {
					$artist_parts[] = $credit['joinphrase'];
				}
			}

			$artist = trim( implode( '', $artist_parts ) );
		}

		$album = '';
		$album_mbid = '';
		$cover = null;

		if ( isset( $raw_result['releases'] ) && ! empty( $raw_result['releases'] ) ) {
			$release = $raw_result['releases'][0];
			$album = $release['title'] ?? '';
			$album_mbid = $release['id'] ?? '';

			// Try to get cover art.
			if ( $album_mbid ) {
				$cover = $this->get_cover_art( $album_mbid, '250' );
			}
		}

		return array(
			'track'        => $raw_result['title'] ?? '',
			'artist'       => $artist,
			'album'        => $album,
			'mbid'         => $raw_result['id'] ?? '',
			'artist_mbid'  => $artist_mbid,
			'album_mbid'   => $album_mbid,
			'cover'        => $cover,
			'duration'     => isset( $raw_result['length'] ) ? (int) ( $raw_result['length'] / 1000 ) : null,
			'score'        => $raw_result['score'] ?? 0,
			'source'       => 'musicbrainz',
		);
	}

	/**
	 * Normalize release result.
	 *
	 * @param array<string, mixed> $release Raw release data.
	 * @return array<string, mixed> Normalized result.
	 */
	protected function normalize_release( array $release ): array {
		$artist = '';

		if ( isset( $release['artist-credit'] ) && ! empty( $release['artist-credit'] ) ) {
			$artist_parts = array();

			foreach ( $release['artist-credit'] as $credit ) {
				if ( isset( $credit['artist']['name'] ) ) {
					$artist_parts[] = $credit['artist']['name'];
				}

				if ( isset( $credit['joinphrase'] ) ) {
					$artist_parts[] = $credit['joinphrase'];
				}
			}

			$artist = trim( implode( '', $artist_parts ) );
		}

		$release_mbid = $release['id'] ?? '';
		$cover = null;

		if ( $release_mbid ) {
			$cover = $this->get_cover_art( $release_mbid, '250' );
		}

		return array(
			'album'       => $release['title'] ?? '',
			'artist'      => $artist,
			'mbid'        => $release_mbid,
			'date'        => $release['date'] ?? '',
			'country'     => $release['country'] ?? '',
			'cover'       => $cover,
			'track_count' => $release['track-count'] ?? null,
			'score'       => $release['score'] ?? 0,
			'source'      => 'musicbrainz',
		);
	}

	/**
	 * Escape special characters for Lucene query.
	 *
	 * @param string $string String to escape.
	 * @return string Escaped string.
	 */
	private function escape_lucene( string $string ): string {
		$special_chars = array(
			'+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '\\', '/',
		);

		foreach ( $special_chars as $char ) {
			$string = str_replace( $char, '\\' . $char, $string );
		}

		return $string;
	}

	/**
	 * Get API documentation URL.
	 *
	 * @return string
	 */
	public function get_docs_url(): string {
		return 'https://musicbrainz.org/doc/MusicBrainz_API';
	}
}

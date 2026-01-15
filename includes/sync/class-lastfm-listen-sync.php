<?php
/**
 * Last.fm Listen Sync Class
 *
 * Handles POSSE (scrobbling) of listen posts to Last.fm.
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Sync;

use PostKindsForIndieWeb\APIs\LastFM;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Last.fm Listen Sync class.
 *
 * Syndicates listen posts to Last.fm as scrobbles.
 *
 * @since 1.0.0
 */
class Lastfm_Listen_Sync extends Listen_Sync_Base {

	/**
	 * Service identifier.
	 *
	 * @var string
	 */
	protected string $service_id = 'lastfm';

	/**
	 * Service display name.
	 *
	 * @var string
	 */
	protected string $service_name = 'Last.fm';

	/**
	 * Last.fm API instance.
	 *
	 * @var LastFM|null
	 */
	private ?LastFM $api = null;

	/**
	 * Session key for authenticated requests.
	 *
	 * @var string|null
	 */
	private ?string $session_key = null;

	/**
	 * Last.fm username.
	 *
	 * @var string|null
	 */
	private ?string $username = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$credentials       = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$lastfm_creds      = $credentials['lastfm'] ?? [];
		$this->session_key = $lastfm_creds['session_key'] ?? '';
		$this->username    = $lastfm_creds['username'] ?? '';
	}

	/**
	 * Get the API instance.
	 *
	 * @return LastFM
	 */
	private function get_api(): LastFM {
		if ( null === $this->api ) {
			$this->api = new LastFM();
		}
		return $this->api;
	}

	/**
	 * Check if the service is connected (has session key).
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return ! empty( $this->session_key );
	}

	/**
	 * Syndicate a listen to Last.fm (scrobble).
	 *
	 * @param int   $post_id     Post ID.
	 * @param array $listen_data Listen data.
	 * @return array|false External scrobble data or false on failure.
	 */
	protected function syndicate_listen( int $post_id, array $listen_data ) {
		if ( ! $this->is_connected() ) {
			return false;
		}

		$api = $this->get_api();

		// Build track data for scrobbling.
		$track = [
			'track'  => $listen_data['track'] ?? '',
			'artist' => $listen_data['artist'] ?? '',
		];

		if ( ! empty( $listen_data['album'] ) ) {
			$track['album'] = $listen_data['album'];
		}

		if ( ! empty( $listen_data['duration'] ) ) {
			$track['duration'] = (int) $listen_data['duration'];
		}

		if ( ! empty( $listen_data['mbid'] ) ) {
			$track['mbid'] = $listen_data['mbid'];
		}

		// Get timestamp from post or listen data.
		$timestamp = $listen_data['timestamp'] ?? get_post_time( 'U', true, $post_id );

		// Scrobble the track.
		$success = $api->scrobble( $track, (int) $timestamp );

		if ( $success ) {
			// Last.fm doesn't return a scrobble ID, so generate one from components.
			$scrobble_id = md5( $track['artist'] . '|' . $track['track'] . '|' . $timestamp );

			// Build Last.fm URL for the track.
			$artist_slug = rawurlencode( str_replace( ' ', '+', $track['artist'] ) );
			$track_slug  = rawurlencode( str_replace( ' ', '+', $track['track'] ) );
			$url         = "https://www.last.fm/music/{$artist_slug}/_/{$track_slug}";

			// If we have a username, link to user's library instead.
			if ( $this->username ) {
				$url = "https://www.last.fm/user/{$this->username}/library";
			}

			return [
				'id'  => $scrobble_id,
				'url' => $url,
			];
		}

		return false;
	}

	/**
	 * Get the Last.fm username.
	 *
	 * @return string|null
	 */
	public function get_username(): ?string {
		return $this->username;
	}
}

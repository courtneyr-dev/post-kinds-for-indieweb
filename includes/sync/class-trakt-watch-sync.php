<?php
/**
 * Trakt Watch Sync Class
 *
 * Handles POSSE of watch posts to Trakt.tv history.
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Sync;

use PostKindsForIndieWeb\APIs\Trakt;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trakt Watch Sync class.
 *
 * Syndicates watch posts to Trakt history.
 *
 * @since 1.0.0
 */
class Trakt_Watch_Sync extends Watch_Sync_Base {

	/**
	 * Service identifier.
	 *
	 * @var string
	 */
	protected string $service_id = 'trakt';

	/**
	 * Service display name.
	 *
	 * @var string
	 */
	protected string $service_name = 'Trakt';

	/**
	 * Trakt API instance.
	 *
	 * @var Trakt|null
	 */
	private ?Trakt $api = null;

	/**
	 * Get the API instance.
	 *
	 * @return Trakt
	 */
	private function get_api(): Trakt {
		if ( null === $this->api ) {
			$this->api = new Trakt();
		}
		return $this->api;
	}

	/**
	 * Check if the service is connected (has OAuth token).
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return $this->get_api()->is_authenticated();
	}

	/**
	 * Syndicate a watch to Trakt history.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $watch_data Watch data.
	 * @return array|false External watch data or false on failure.
	 */
	protected function syndicate_watch( int $post_id, array $watch_data ) {
		if ( ! $this->is_connected() ) {
			return false;
		}

		$api = $this->get_api();

		// Build IDs array for Trakt.
		$ids = [];

		// Prefer Trakt ID if available.
		if ( ! empty( $watch_data['trakt_id'] ) ) {
			$ids['trakt'] = (int) $watch_data['trakt_id'];
		}

		// Use IMDB ID if available.
		if ( ! empty( $watch_data['imdb_id'] ) ) {
			$ids['imdb'] = $watch_data['imdb_id'];
		}

		// Use TMDB ID if available.
		if ( ! empty( $watch_data['tmdb_id'] ) ) {
			$ids['tmdb'] = (int) $watch_data['tmdb_id'];
		}

		// We need at least one ID to sync.
		if ( empty( $ids ) ) {
			return false;
		}

		// Determine type (movie or episode).
		$type = $watch_data['type'] ?? 'movie';

		// Build the item payload.
		$item = [
			'type'       => $type,
			'ids'        => $ids,
			'watched_at' => $watch_data['created_at'] ?? gmdate( 'c' ),
		];

		// Sync to Trakt history.
		$success = $api->add_to_history( $item );

		if ( $success ) {
			// Generate a sync ID from the components.
			$sync_id = md5( wp_json_encode( $ids ) . '|' . ( $watch_data['timestamp'] ?? time() ) );

			// Build Trakt URL.
			$url = $this->build_trakt_url( $watch_data, $ids );

			return [
				'id'  => $sync_id,
				'url' => $url,
			];
		}

		return false;
	}

	/**
	 * Build a Trakt URL for the synced item.
	 *
	 * @param array $watch_data Watch data.
	 * @param array $ids        Trakt IDs.
	 * @return string Trakt URL.
	 */
	private function build_trakt_url( array $watch_data, array $ids ): string {
		$type = $watch_data['type'] ?? 'movie';

		// If we have an IMDB ID, use it in the URL.
		if ( ! empty( $ids['imdb'] ) ) {
			if ( 'movie' === $type ) {
				return 'https://trakt.tv/movies/' . $ids['imdb'];
			} else {
				return 'https://trakt.tv/shows/' . $ids['imdb'];
			}
		}

		// If we have a Trakt ID, we'd need the slug which we may not have.
		// Fall back to a search URL or the user's history page.
		$title = rawurlencode( $watch_data['title'] ?? '' );
		if ( $title ) {
			if ( 'movie' === $type ) {
				return 'https://trakt.tv/search/movies?query=' . $title;
			} else {
				return 'https://trakt.tv/search/shows?query=' . $title;
			}
		}

		// Ultimate fallback: user's history.
		return 'https://trakt.tv/users/me/history';
	}
}

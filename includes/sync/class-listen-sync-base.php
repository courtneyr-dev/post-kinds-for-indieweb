<?php
/**
 * Listen Sync Base Class
 *
 * Abstract base class for bidirectional listen/scrobble synchronization.
 * Handles POSSE (Publish Own Site, Syndicate Elsewhere) for listen posts.
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Sync;

use PostKindsForIndieWeb\Meta_Fields;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Listen Sync Base class.
 *
 * Provides common functionality for listen/scrobble sync services.
 * Extend this class to add support for new music services.
 *
 * @since 1.0.0
 */
abstract class Listen_Sync_Base {

	/**
	 * Service identifier (e.g., 'lastfm', 'listenbrainz').
	 *
	 * @var string
	 */
	protected string $service_id = '';

	/**
	 * Service display name.
	 *
	 * @var string
	 */
	protected string $service_name = '';

	/**
	 * Meta key for storing external scrobble ID.
	 *
	 * @var string
	 */
	protected string $external_id_meta_key = '';

	/**
	 * Meta key for storing syndication URL.
	 *
	 * @var string
	 */
	protected string $syndication_url_meta_key = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->external_id_meta_key     = '_postkind_listen_' . $this->service_id . '_id';
		$this->syndication_url_meta_key = '_postkind_syndication_' . $this->service_id;
	}

	/**
	 * Initialize hooks for this sync service.
	 *
	 * @return void
	 */
	public function init(): void {
		// POSSE: Syndicate to external service on publish.
		add_action( 'transition_post_status', [ $this, 'maybe_syndicate_listen' ], 10, 3 );

		// Add syndication target to Syndication Links (if available).
		add_filter( 'syn_syndication_targets', [ $this, 'add_syndication_target' ] );
	}

	/**
	 * Check if the service is connected (authenticated).
	 *
	 * @return bool
	 */
	abstract public function is_connected(): bool;

	/**
	 * Syndicate a listen to the external service (POSSE/scrobble).
	 *
	 * @param int   $post_id     Post ID.
	 * @param array $listen_data Listen data.
	 * @return array|false External scrobble data or false on failure.
	 */
	abstract protected function syndicate_listen( int $post_id, array $listen_data );

	/**
	 * Maybe syndicate a listen when post status changes.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function maybe_syndicate_listen( string $new_status, string $old_status, \WP_Post $post ): void {
		// Only on publish.
		if ( 'publish' !== $new_status ) {
			return;
		}

		// Only for posts.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Check if this is a listen kind.
		if ( ! $this->is_listen_post( $post->ID ) ) {
			return;
		}

		// Check if syndication is enabled for this service.
		if ( ! $this->is_syndication_enabled( $post->ID ) ) {
			return;
		}

		// Check if already syndicated (prevent duplicates).
		if ( $this->is_already_syndicated( $post->ID ) ) {
			return;
		}

		// Check if this was imported from the service (prevent loops).
		if ( $this->was_imported_from_service( $post->ID ) ) {
			return;
		}

		// Get listen data from post.
		$listen_data = $this->get_listen_data_from_post( $post->ID );

		if ( empty( $listen_data['track'] ) || empty( $listen_data['artist'] ) ) {
			return;
		}

		// Syndicate.
		$result = $this->syndicate_listen( $post->ID, $listen_data );

		if ( $result && ! empty( $result['id'] ) ) {
			// Store external ID to prevent future duplicate syndication.
			update_post_meta( $post->ID, $this->external_id_meta_key, $result['id'] );

			// Store syndication URL if available.
			if ( ! empty( $result['url'] ) ) {
				update_post_meta( $post->ID, $this->syndication_url_meta_key, $result['url'] );

				// Also add to Syndication Links if available.
				$this->add_syndication_link( $post->ID, $result['url'] );
			}

			/**
			 * Fires after a listen is syndicated to an external service.
			 *
			 * @since 1.0.0
			 *
			 * @param int    $post_id     Post ID.
			 * @param string $service_id  Service identifier.
			 * @param array  $result      Syndication result.
			 * @param array  $listen_data Listen data.
			 */
			do_action( 'post_kinds_indieweb_listen_syndicated', $post->ID, $this->service_id, $result, $listen_data );
		}
	}

	/**
	 * Check if a post is a listen kind.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_listen_post( int $post_id ): bool {
		$terms = wp_get_post_terms( $post_id, 'kind', [ 'fields' => 'slugs' ] );

		if ( is_wp_error( $terms ) ) {
			return false;
		}

		return in_array( 'listen', $terms, true );
	}

	/**
	 * Check if syndication to this service is enabled for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_syndication_enabled( int $post_id ): bool {
		// Check global setting.
		$settings    = get_option( 'post_kinds_indieweb_settings', [] );
		$setting_key = 'listen_sync_to_' . $this->service_id;

		if ( empty( $settings[ $setting_key ] ) ) {
			return false;
		}

		// Check post-level opt-out (meta field from editor sidebar).
		$meta_key       = Meta_Fields::PREFIX . 'syndicate_' . $this->service_id;
		$post_syndicate = get_post_meta( $post_id, $meta_key, true );

		// If explicitly set to false, don't syndicate.
		if ( false === $post_syndicate || '0' === $post_syndicate ) {
			return false;
		}

		// Must be connected.
		return $this->is_connected();
	}

	/**
	 * Check if post was already syndicated to this service.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_already_syndicated( int $post_id ): bool {
		$external_id = get_post_meta( $post_id, $this->external_id_meta_key, true );
		return ! empty( $external_id );
	}

	/**
	 * Check if post was imported from this service.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function was_imported_from_service( int $post_id ): bool {
		$imported_from = get_post_meta( $post_id, '_postkind_imported_from', true );
		return $this->service_id === $imported_from;
	}

	/**
	 * Get listen data from a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Listen data.
	 */
	protected function get_listen_data_from_post( int $post_id ): array {
		$prefix = Meta_Fields::PREFIX;

		$data = [
			'track'      => get_post_meta( $post_id, $prefix . 'listen_track', true ),
			'artist'     => get_post_meta( $post_id, $prefix . 'listen_artist', true ),
			'album'      => get_post_meta( $post_id, $prefix . 'listen_album', true ),
			'duration'   => get_post_meta( $post_id, $prefix . 'listen_duration', true ),
			'mbid'       => get_post_meta( $post_id, $prefix . 'listen_mbid', true ),
			'created_at' => get_the_date( 'c', $post_id ),
			'timestamp'  => get_post_time( 'U', true, $post_id ),
		];

		// Get album MBID if available.
		$album_mbid = get_post_meta( $post_id, $prefix . 'listen_album_mbid', true );
		if ( $album_mbid ) {
			$data['album_mbid'] = $album_mbid;
		}

		// Get artist MBID if available.
		$artist_mbid = get_post_meta( $post_id, $prefix . 'listen_artist_mbid', true );
		if ( $artist_mbid ) {
			$data['artist_mbid'] = $artist_mbid;
		}

		return $data;
	}

	/**
	 * Add syndication link to post (Syndication Links plugin compatibility).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Syndication URL.
	 * @return void
	 */
	protected function add_syndication_link( int $post_id, string $url ): void {
		// Store in our meta.
		update_post_meta( $post_id, $this->syndication_url_meta_key, $url );

		// Add to Syndication Links if available.
		if ( function_exists( 'get_syndication_links' ) ) {
			$existing = get_post_meta( $post_id, 'mf2_syndication', true );

			if ( ! is_array( $existing ) ) {
				$existing = [];
			}

			if ( ! in_array( $url, $existing, true ) ) {
				$existing[] = $url;
				update_post_meta( $post_id, 'mf2_syndication', $existing );
			}
		}
	}

	/**
	 * Add this service as a syndication target.
	 *
	 * @param array $targets Existing targets.
	 * @return array Modified targets.
	 */
	public function add_syndication_target( array $targets ): array {
		if ( $this->is_connected() ) {
			$targets[ $this->service_id ] = [
				'uid'  => $this->service_id,
				'name' => $this->service_name,
			];
		}

		return $targets;
	}

	/**
	 * Get the service ID.
	 *
	 * @return string
	 */
	public function get_service_id(): string {
		return $this->service_id;
	}

	/**
	 * Get the service name.
	 *
	 * @return string
	 */
	public function get_service_name(): string {
		return $this->service_name;
	}
}

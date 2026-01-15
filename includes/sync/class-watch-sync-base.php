<?php
/**
 * Watch Sync Base Class
 *
 * Abstract base class for bidirectional watch synchronization.
 * Handles POSSE (Publish Own Site, Syndicate Elsewhere) for watch posts.
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
 * Abstract Watch Sync Base class.
 *
 * Provides common functionality for watch sync services.
 * Extend this class to add support for new video tracking services.
 *
 * @since 1.0.0
 */
abstract class Watch_Sync_Base {

	/**
	 * Service identifier (e.g., 'trakt', 'simkl').
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
	 * Meta key for storing external watch ID.
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
		$this->external_id_meta_key     = '_postkind_watch_' . $this->service_id . '_id';
		$this->syndication_url_meta_key = '_postkind_syndication_' . $this->service_id;
	}

	/**
	 * Initialize hooks for this sync service.
	 *
	 * @return void
	 */
	public function init(): void {
		// POSSE: Syndicate to external service on publish.
		add_action( 'transition_post_status', [ $this, 'maybe_syndicate_watch' ], 10, 3 );

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
	 * Syndicate a watch to the external service (POSSE).
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $watch_data Watch data.
	 * @return array|false External watch data or false on failure.
	 */
	abstract protected function syndicate_watch( int $post_id, array $watch_data );

	/**
	 * Maybe syndicate a watch when post status changes.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function maybe_syndicate_watch( string $new_status, string $old_status, \WP_Post $post ): void {
		// Only on publish.
		if ( 'publish' !== $new_status ) {
			return;
		}

		// Only for posts.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Check if this is a watch kind.
		if ( ! $this->is_watch_post( $post->ID ) ) {
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

		// Get watch data from post.
		$watch_data = $this->get_watch_data_from_post( $post->ID );

		if ( empty( $watch_data['title'] ) ) {
			return;
		}

		// Syndicate.
		$result = $this->syndicate_watch( $post->ID, $watch_data );

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
			 * Fires after a watch is syndicated to an external service.
			 *
			 * @since 1.0.0
			 *
			 * @param int    $post_id    Post ID.
			 * @param string $service_id Service identifier.
			 * @param array  $result     Syndication result.
			 * @param array  $watch_data Watch data.
			 */
			do_action( 'post_kinds_indieweb_watch_syndicated', $post->ID, $this->service_id, $result, $watch_data );
		}
	}

	/**
	 * Check if a post is a watch kind.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_watch_post( int $post_id ): bool {
		$terms = wp_get_post_terms( $post_id, 'kind', [ 'fields' => 'slugs' ] );

		if ( is_wp_error( $terms ) ) {
			return false;
		}

		return in_array( 'watch', $terms, true );
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
		$setting_key = 'watch_sync_to_' . $this->service_id;

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
	 * Get watch data from a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Watch data.
	 */
	protected function get_watch_data_from_post( int $post_id ): array {
		$prefix = Meta_Fields::PREFIX;

		$data = [
			'title'      => get_post_meta( $post_id, $prefix . 'watch_title', true ),
			'year'       => get_post_meta( $post_id, $prefix . 'watch_year', true ),
			'tmdb_id'    => get_post_meta( $post_id, $prefix . 'watch_tmdb_id', true ),
			'imdb_id'    => get_post_meta( $post_id, $prefix . 'watch_imdb_id', true ),
			'trakt_id'   => get_post_meta( $post_id, $prefix . 'watch_trakt_id', true ),
			'season'     => get_post_meta( $post_id, $prefix . 'watch_season', true ),
			'episode'    => get_post_meta( $post_id, $prefix . 'watch_episode', true ),
			'rating'     => get_post_meta( $post_id, $prefix . 'rating', true ),
			'is_rewatch' => get_post_meta( $post_id, $prefix . 'watch_rewatch', true ),
			'created_at' => get_the_date( 'c', $post_id ),
			'timestamp'  => get_post_time( 'U', true, $post_id ),
		];

		// Determine if this is a movie or TV show based on season/episode.
		$data['type'] = ( ! empty( $data['season'] ) || ! empty( $data['episode'] ) ) ? 'episode' : 'movie';

		// Get show title for episodes.
		$show_title = get_post_meta( $post_id, $prefix . 'watch_show_title', true );
		if ( $show_title ) {
			$data['show_title'] = $show_title;
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

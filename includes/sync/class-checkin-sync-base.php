<?php
/**
 * Checkin Sync Base Class
 *
 * Abstract base class for bidirectional checkin synchronization.
 * Handles POSSE (Publish Own Site, Syndicate Elsewhere) and
 * PESOS (Publish Elsewhere, Syndicate Own Site) patterns.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\Sync;

use ReactionsForIndieWeb\Meta_Fields;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Checkin Sync Base class.
 *
 * Provides common functionality for bidirectional checkin sync services.
 * Extend this class to add support for new checkin services.
 *
 * @since 1.0.0
 */
abstract class Checkin_Sync_Base {

	/**
	 * Service identifier (e.g., 'foursquare', 'swarm').
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
	 * Meta key for storing external checkin ID.
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
		$this->external_id_meta_key     = '_reactions_checkin_' . $this->service_id . '_id';
		$this->syndication_url_meta_key = '_reactions_syndication_' . $this->service_id;
	}

	/**
	 * Initialize hooks for this sync service.
	 *
	 * @return void
	 */
	public function init(): void {
		// POSSE: Syndicate to external service on publish.
		add_action( 'transition_post_status', array( $this, 'maybe_syndicate_checkin' ), 10, 3 );

		// Register REST routes for OAuth and webhooks.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Add syndication target to Syndication Links (if available).
		add_filter( 'syn_syndication_targets', array( $this, 'add_syndication_target' ) );
	}

	/**
	 * Register REST API routes for this service.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Check if the service is connected (OAuth authorized).
	 *
	 * @return bool
	 */
	abstract public function is_connected(): bool;

	/**
	 * Get OAuth authorization URL.
	 *
	 * @return string
	 */
	abstract public function get_auth_url(): string;

	/**
	 * Handle OAuth callback and store tokens.
	 *
	 * @param string $code Authorization code.
	 * @return bool True on success.
	 */
	abstract public function handle_oauth_callback( string $code ): bool;

	/**
	 * Syndicate a checkin to the external service (POSSE).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $checkin_data Checkin data.
	 * @return array|false External checkin data or false on failure.
	 */
	abstract protected function syndicate_checkin( int $post_id, array $checkin_data );

	/**
	 * Import a checkin from the external service (PESOS).
	 *
	 * @param array $external_checkin External checkin data.
	 * @return int|false Post ID or false on failure.
	 */
	abstract protected function import_checkin( array $external_checkin );

	/**
	 * Fetch recent checkins from the external service.
	 *
	 * @param int $limit Max checkins to fetch.
	 * @return array Array of checkin data.
	 */
	abstract public function fetch_recent_checkins( int $limit = 50 ): array;

	/**
	 * Maybe syndicate a checkin when post status changes.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function maybe_syndicate_checkin( string $new_status, string $old_status, \WP_Post $post ): void {
		// Only on publish.
		if ( 'publish' !== $new_status ) {
			return;
		}

		// Only for posts.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Check if this is a checkin kind.
		if ( ! $this->is_checkin_post( $post->ID ) ) {
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

		// Get checkin data from post.
		$checkin_data = $this->get_checkin_data_from_post( $post->ID );

		if ( empty( $checkin_data ) ) {
			return;
		}

		// Syndicate.
		$result = $this->syndicate_checkin( $post->ID, $checkin_data );

		if ( $result && ! empty( $result['id'] ) ) {
			// Store external ID to prevent future duplicate syndication.
			update_post_meta( $post->ID, $this->external_id_meta_key, $result['id'] );

			// Store syndication URL if available.
			if ( ! empty( $result['url'] ) ) {
				update_post_meta( $post->ID, $this->syndication_url_meta_key, $result['url'] );

				// Also add to Syndication Links if available.
				$this->add_syndication_link( $post->ID, $result['url'] );
			}

			// Log success.
			$this->log( 'Syndicated checkin to ' . $this->service_name, array(
				'post_id'     => $post->ID,
				'external_id' => $result['id'],
			) );
		}
	}

	/**
	 * Import checkins from the external service (PESOS).
	 *
	 * @param int $limit Max checkins to import.
	 * @return array Results with imported count and skipped count.
	 */
	public function import_checkins( int $limit = 50 ): array {
		$checkins = $this->fetch_recent_checkins( $limit );
		$imported = 0;
		$skipped  = 0;
		$errors   = 0;

		foreach ( $checkins as $external_checkin ) {
			// Check for duplicates.
			if ( $this->checkin_exists( $external_checkin ) ) {
				++$skipped;
				continue;
			}

			// Import.
			$post_id = $this->import_checkin( $external_checkin );

			if ( $post_id ) {
				++$imported;

				// Mark as imported from this service (prevent POSSE loop).
				update_post_meta( $post_id, '_reactions_imported_from', $this->service_id );
				update_post_meta( $post_id, $this->external_id_meta_key, $external_checkin['id'] ?? '' );

				// Add syndication link.
				if ( ! empty( $external_checkin['url'] ) ) {
					$this->add_syndication_link( $post_id, $external_checkin['url'] );
				}
			} else {
				++$errors;
			}
		}

		$this->log( 'Import completed', array(
			'service'  => $this->service_id,
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		) );

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'total'    => count( $checkins ),
		);
	}

	/**
	 * Check if a post is a checkin kind.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_checkin_post( int $post_id ): bool {
		$terms = wp_get_post_terms( $post_id, 'kind', array( 'fields' => 'slugs' ) );

		if ( is_wp_error( $terms ) ) {
			return false;
		}

		return in_array( 'checkin', $terms, true );
	}

	/**
	 * Check if syndication to this service is enabled for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_syndication_enabled( int $post_id ): bool {
		// Check global setting.
		$settings = get_option( 'reactions_indieweb_settings', array() );
		$setting_key = 'checkin_sync_to_' . $this->service_id;

		if ( empty( $settings[ $setting_key ] ) ) {
			return false;
		}

		// Check post-level override (if set).
		$post_syndicate = get_post_meta( $post_id, '_reactions_syndicate_' . $this->service_id, true );

		if ( 'no' === $post_syndicate ) {
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
		$imported_from = get_post_meta( $post_id, '_reactions_imported_from', true );
		return $this->service_id === $imported_from;
	}

	/**
	 * Check if an external checkin already exists as a post.
	 *
	 * @param array $external_checkin External checkin data.
	 * @return bool
	 */
	protected function checkin_exists( array $external_checkin ): bool {
		$external_id = $external_checkin['id'] ?? '';

		if ( empty( $external_id ) ) {
			return false;
		}

		// Check by external ID.
		$existing = get_posts( array(
			'post_type'   => 'post',
			'meta_key'    => $this->external_id_meta_key,
			'meta_value'  => $external_id,
			'numberposts' => 1,
			'fields'      => 'ids',
		) );

		if ( ! empty( $existing ) ) {
			return true;
		}

		// Also check by timestamp + venue (fuzzy match).
		if ( ! empty( $external_checkin['created_at'] ) && ! empty( $external_checkin['venue']['name'] ) ) {
			$timestamp = strtotime( $external_checkin['created_at'] );
			$venue     = $external_checkin['venue']['name'];

			// Look for posts within 5 minutes with same venue.
			$start = gmdate( 'Y-m-d H:i:s', $timestamp - 300 );
			$end   = gmdate( 'Y-m-d H:i:s', $timestamp + 300 );

			$fuzzy_match = get_posts( array(
				'post_type'   => 'post',
				'date_query'  => array(
					array(
						'after'     => $start,
						'before'    => $end,
						'inclusive' => true,
					),
				),
				'meta_query'  => array(
					array(
						'key'     => Meta_Fields::PREFIX . 'checkin_name',
						'value'   => $venue,
						'compare' => '=',
					),
				),
				'numberposts' => 1,
				'fields'      => 'ids',
			) );

			if ( ! empty( $fuzzy_match ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get checkin data from a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Checkin data.
	 */
	protected function get_checkin_data_from_post( int $post_id ): array {
		$prefix = Meta_Fields::PREFIX;

		$data = array(
			'venue_name' => get_post_meta( $post_id, $prefix . 'checkin_name', true ),
			'address'    => get_post_meta( $post_id, $prefix . 'checkin_address', true ),
			'locality'   => get_post_meta( $post_id, $prefix . 'checkin_locality', true ),
			'region'     => get_post_meta( $post_id, $prefix . 'checkin_region', true ),
			'country'    => get_post_meta( $post_id, $prefix . 'checkin_country', true ),
			'latitude'   => get_post_meta( $post_id, $prefix . 'geo_latitude', true ),
			'longitude'  => get_post_meta( $post_id, $prefix . 'geo_longitude', true ),
			'note'       => get_the_content( null, false, $post_id ),
			'created_at' => get_the_date( 'c', $post_id ),
		);

		// Get Foursquare venue ID if available.
		$fsq_id = get_post_meta( $post_id, $prefix . 'checkin_foursquare_id', true );
		if ( $fsq_id ) {
			$data['foursquare_id'] = $fsq_id;
		}

		// Get OSM ID if available.
		$osm_id = get_post_meta( $post_id, $prefix . 'checkin_osm_id', true );
		if ( $osm_id ) {
			$data['osm_id'] = $osm_id;
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
				$existing = array();
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
			$targets[ $this->service_id ] = array(
				'uid'  => $this->service_id,
				'name' => $this->service_name,
			);
		}

		return $targets;
	}

	/**
	 * Log a message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return void
	 */
	protected function log( string $message, array $context = array() ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[Reactions IndieWeb] [%s Sync] %s: %s',
				$this->service_name,
				$message,
				wp_json_encode( $context )
			) );
		}
	}

	/**
	 * Get service ID.
	 *
	 * @return string
	 */
	public function get_service_id(): string {
		return $this->service_id;
	}

	/**
	 * Get service name.
	 *
	 * @return string
	 */
	public function get_service_name(): string {
		return $this->service_name;
	}
}

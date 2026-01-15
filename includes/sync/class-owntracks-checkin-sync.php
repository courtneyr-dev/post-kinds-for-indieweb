<?php
/**
 * OwnTracks Checkin Sync
 *
 * Receives location updates from OwnTracks via HTTP webhook.
 * OwnTracks is a self-hosted, privacy-respecting location tracking app.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OwnTracks checkin sync class.
 *
 * Unlike other sync services, OwnTracks only does PESOS (import).
 * The app sends location data to your site via HTTP POST.
 */
class OwnTracks_Checkin_Sync extends Checkin_Sync_Base {

	/**
	 * Service identifier.
	 *
	 * @var string
	 */
	protected string $service_id = 'owntracks';

	/**
	 * Service display name.
	 *
	 * @var string
	 */
	protected string $service_name = 'OwnTracks';

	/**
	 * Initialize the sync service.
	 *
	 * @return void
	 */
	public function init(): void {
		parent::init();
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// OwnTracks webhook endpoint.
		register_rest_route(
			'post-kinds-indieweb/v1',
			'/owntracks',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => [ $this, 'verify_webhook_auth' ],
			]
		);
	}

	/**
	 * Verify webhook authentication.
	 *
	 * OwnTracks can send HTTP Basic auth credentials.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error True if authenticated, error otherwise.
	 */
	public function verify_webhook_auth( \WP_REST_Request $request ) {
		$settings = get_option( 'post_kinds_indieweb_settings', [] );

		// Check if OwnTracks is enabled.
		if ( empty( $settings['owntracks_enabled'] ) ) {
			return new \WP_Error( 'disabled', __( 'OwnTracks integration is disabled.', 'post-kinds-for-indieweb' ), [ 'status' => 403 ] );
		}

		$expected_username = $settings['owntracks_username'] ?? '';
		$expected_password = $settings['owntracks_password'] ?? '';

		// If no credentials configured, allow all (not recommended).
		if ( empty( $expected_username ) && empty( $expected_password ) ) {
			return true;
		}

		// Check HTTP Basic auth.
		$auth_header = $request->get_header( 'Authorization' );
		if ( empty( $auth_header ) || 0 !== strpos( $auth_header, 'Basic ' ) ) {
			return new \WP_Error( 'unauthorized', __( 'Authentication required.', 'post-kinds-for-indieweb' ), [ 'status' => 401 ] );
		}

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$credentials                 = base64_decode( substr( $auth_header, 6 ) );
		list( $username, $password ) = explode( ':', $credentials, 2 );

		if ( $username !== $expected_username || $password !== $expected_password ) {
			return new \WP_Error( 'forbidden', __( 'Invalid credentials.', 'post-kinds-for-indieweb' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Handle incoming webhook from OwnTracks.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) ) {
			return new \WP_Error( 'empty_payload', __( 'No data received.', 'post-kinds-for-indieweb' ), [ 'status' => 400 ] );
		}

		$type = $payload['_type'] ?? '';

		// OwnTracks sends different message types.
		switch ( $type ) {
			case 'location':
				return $this->handle_location_update( $payload );

			case 'waypoint':
				// Waypoint definition - could store for future use.
				return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );

			case 'transition':
				// Region enter/exit - could trigger checkin.
				return $this->handle_transition( $payload );

			default:
				// Acknowledge but don't process.
				return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
		}
	}

	/**
	 * Handle a location update.
	 *
	 * @param array<string, mixed> $payload Location data.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	private function handle_location_update( array $payload ) {
		$settings = get_option( 'post_kinds_indieweb_settings', [] );

		// Check if auto-checkin is enabled.
		if ( empty( $settings['owntracks_auto_checkin'] ) ) {
			// Just store location, don't create post.
			$this->store_last_location( $payload );
			return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
		}

		// Check for POI (point of interest) trigger.
		$trigger = $payload['t'] ?? '';
		$poi     = $payload['poi'] ?? '';

		// Only create checkin for manual triggers or POI visits.
		if ( 'u' !== $trigger && empty( $poi ) ) {
			$this->store_last_location( $payload );
			return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
		}

		// Create checkin post.
		$result = $this->create_checkin_from_location( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'status'  => 'ok',
				'post_id' => $result,
			],
			200
		);
	}

	/**
	 * Handle a region transition (enter/exit).
	 *
	 * @param array<string, mixed> $payload Transition data.
	 * @return \WP_REST_Response Response.
	 */
	private function handle_transition( array $payload ) {
		$settings = get_option( 'post_kinds_indieweb_settings', [] );

		// Only create checkin on enter, not exit.
		$event = $payload['event'] ?? '';
		if ( 'enter' !== $event ) {
			return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
		}

		// Check if transition checkins are enabled.
		if ( empty( $settings['owntracks_transition_checkin'] ) ) {
			return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
		}

		// Create checkin for the region.
		$result = $this->create_checkin_from_transition( $payload );

		return new \WP_REST_Response(
			[
				'status'  => 'ok',
				'post_id' => $result,
			],
			200
		);
	}

	/**
	 * Store last known location.
	 *
	 * @param array<string, mixed> $payload Location data.
	 * @return void
	 */
	private function store_last_location( array $payload ): void {
		$location = [
			'lat'       => $payload['lat'] ?? 0,
			'lon'       => $payload['lon'] ?? 0,
			'acc'       => $payload['acc'] ?? 0,
			'alt'       => $payload['alt'] ?? 0,
			'vel'       => $payload['vel'] ?? 0,
			'batt'      => $payload['batt'] ?? 0,
			'timestamp' => $payload['tst'] ?? time(),
			'device'    => $payload['tid'] ?? '',
		];

		update_option( 'post_kinds_indieweb_owntracks_last_location', $location );
	}

	/**
	 * Create a checkin post from location data.
	 *
	 * @param array<string, mixed> $payload Location data.
	 * @return int|\WP_Error Post ID or error.
	 */
	private function create_checkin_from_location( array $payload ) {
		$lat = $payload['lat'] ?? 0;
		$lon = $payload['lon'] ?? 0;
		$tst = $payload['tst'] ?? time();
		$poi = $payload['poi'] ?? '';

		if ( empty( $lat ) || empty( $lon ) ) {
			return new \WP_Error( 'no_location', __( 'No location data.', 'post-kinds-for-indieweb' ), [ 'status' => 400 ] );
		}

		// Check for recent duplicate.
		if ( $this->has_recent_checkin_at_location( $lat, $lon, $tst ) ) {
			return new \WP_Error( 'duplicate', __( 'Recent checkin at this location exists.', 'post-kinds-for-indieweb' ), [ 'status' => 200 ] );
		}

		// Try to reverse geocode.
		$address = $this->reverse_geocode( $lat, $lon );

		// Build title.
		$title = $poi;
		if ( empty( $title ) && ! empty( $address['display_name'] ) ) {
			$title = $address['display_name'];
		}
		if ( empty( $title ) ) {
			$title = sprintf( '%.4f, %.4f', $lat, $lon );
		}

		$settings    = get_option( 'post_kinds_indieweb_settings', [] );
		$post_status = $settings['owntracks_post_status'] ?? 'publish';

		$post_data = [
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => '',
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_date'    => gmdate( 'Y-m-d H:i:s', $tst ),
		];

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return $post_id ?: new \WP_Error( 'insert_failed', __( 'Failed to create post.', 'post-kinds-for-indieweb' ) );
		}

		// Set post kind.
		wp_set_object_terms( $post_id, 'checkin', 'kind' );

		// Save meta.
		update_post_meta( $post_id, '_postkind_kind', 'checkin' );
		update_post_meta( $post_id, '_postkind_imported_from', 'owntracks' );
		update_post_meta( $post_id, '_postkind_checkin_name', $poi ?: ( $address['name'] ?? '' ) );
		update_post_meta( $post_id, '_postkind_geo_latitude', $lat );
		update_post_meta( $post_id, '_postkind_geo_longitude', $lon );

		// Privacy - OwnTracks data should respect user settings.
		$privacy = $settings['checkin_default_privacy'] ?? 'approximate';
		update_post_meta( $post_id, '_postkind_geo_privacy', $privacy );

		// Address components.
		if ( ! empty( $address ) ) {
			if ( ! empty( $address['address']['road'] ) ) {
				update_post_meta( $post_id, '_postkind_checkin_street', $address['address']['road'] );
			}
			if ( ! empty( $address['address']['city'] ?? $address['address']['town'] ?? $address['address']['village'] ) ) {
				update_post_meta( $post_id, '_postkind_checkin_locality', $address['address']['city'] ?? $address['address']['town'] ?? $address['address']['village'] );
			}
			if ( ! empty( $address['address']['state'] ) ) {
				update_post_meta( $post_id, '_postkind_checkin_region', $address['address']['state'] );
			}
			if ( ! empty( $address['address']['country'] ) ) {
				update_post_meta( $post_id, '_postkind_checkin_country', $address['address']['country'] );
			}
			if ( ! empty( $address['address']['postcode'] ) ) {
				update_post_meta( $post_id, '_postkind_checkin_postal_code', $address['address']['postcode'] );
			}
		}

		// Device info.
		if ( ! empty( $payload['tid'] ) ) {
			update_post_meta( $post_id, '_postkind_owntracks_device', $payload['tid'] );
		}

		return $post_id;
	}

	/**
	 * Create a checkin from region transition.
	 *
	 * @param array<string, mixed> $payload Transition data.
	 * @return int|false Post ID or false.
	 */
	private function create_checkin_from_transition( array $payload ) {
		$desc = $payload['desc'] ?? '';
		$lat  = $payload['lat'] ?? 0;
		$lon  = $payload['lon'] ?? 0;
		$tst  = $payload['tst'] ?? time();

		if ( empty( $desc ) ) {
			return false;
		}

		// Check for recent duplicate.
		if ( $this->has_recent_checkin_at_location( $lat, $lon, $tst ) ) {
			return false;
		}

		$settings    = get_option( 'post_kinds_indieweb_settings', [] );
		$post_status = $settings['owntracks_post_status'] ?? 'publish';

		$post_data = [
			'post_title'   => sanitize_text_field( $desc ),
			'post_content' => '',
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_date'    => gmdate( 'Y-m-d H:i:s', $tst ),
		];

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return false;
		}

		// Set post kind.
		wp_set_object_terms( $post_id, 'checkin', 'kind' );

		// Save meta.
		update_post_meta( $post_id, '_postkind_kind', 'checkin' );
		update_post_meta( $post_id, '_postkind_imported_from', 'owntracks' );
		update_post_meta( $post_id, '_postkind_checkin_name', $desc );
		update_post_meta( $post_id, '_postkind_geo_latitude', $lat );
		update_post_meta( $post_id, '_postkind_geo_longitude', $lon );

		$privacy = $settings['checkin_default_privacy'] ?? 'approximate';
		update_post_meta( $post_id, '_postkind_geo_privacy', $privacy );

		return $post_id;
	}

	/**
	 * Check for recent checkin at same location.
	 *
	 * @param float $lat       Latitude.
	 * @param float $lon       Longitude.
	 * @param int   $timestamp Timestamp.
	 * @return bool True if recent checkin exists.
	 */
	private function has_recent_checkin_at_location( float $lat, float $lon, int $timestamp ): bool {
		// Look for checkins within 5 minutes and ~100 meters.
		$args = [
			'post_type'      => 'post',
			'post_status'    => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => 1,
			'date_query'     => [
				[
					'after'  => gmdate( 'Y-m-d H:i:s', $timestamp - 300 ),
					'before' => gmdate( 'Y-m-d H:i:s', $timestamp + 300 ),
				],
			],
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => '_postkind_kind',
					'value'   => 'checkin',
					'compare' => '=',
				],
				[
					'key'     => '_postkind_geo_latitude',
					'value'   => [ $lat - 0.001, $lat + 0.001 ],
					'type'    => 'DECIMAL(10,6)',
					'compare' => 'BETWEEN',
				],
				[
					'key'     => '_postkind_geo_longitude',
					'value'   => [ $lon - 0.001, $lon + 0.001 ],
					'type'    => 'DECIMAL(10,6)',
					'compare' => 'BETWEEN',
				],
			],
		];

		$query = new \WP_Query( $args );

		return $query->have_posts();
	}

	/**
	 * Reverse geocode coordinates.
	 *
	 * @param float $lat Latitude.
	 * @param float $lon Longitude.
	 * @return array<string, mixed> Address data.
	 */
	private function reverse_geocode( float $lat, float $lon ): array {
		$settings    = get_option( 'post_kinds_indieweb_settings', [] );
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );

		$email = $credentials['nominatim']['email'] ?? $settings['admin_email'] ?? get_option( 'admin_email' );

		$response = wp_remote_get(
			add_query_arg(
				[
					'lat'            => $lat,
					'lon'            => $lon,
					'format'         => 'json',
					'addressdetails' => 1,
					'email'          => $email,
				],
				'https://nominatim.openstreetmap.org/reverse'
			),
			[
				'timeout' => 10,
				'headers' => [
					'User-Agent' => 'Post Kinds for IndieWeb WordPress Plugin',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
	}

	/**
	 * Check if service is connected.
	 *
	 * OwnTracks doesn't have OAuth - it's "connected" if enabled.
	 *
	 * @return bool True if enabled.
	 */
	public function is_connected(): bool {
		$settings = get_option( 'post_kinds_indieweb_settings', [] );
		return ! empty( $settings['owntracks_enabled'] );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * Not applicable for OwnTracks.
	 *
	 * @return string Empty string.
	 */
	public function get_auth_url(): string {
		return '';
	}

	/**
	 * Handle OAuth callback.
	 *
	 * Not applicable for OwnTracks.
	 *
	 * @param string $code Authorization code.
	 * @return bool Always false.
	 */
	public function handle_oauth_callback( string $code ): bool {
		return false;
	}

	/**
	 * Syndicate a checkin.
	 *
	 * Not applicable for OwnTracks (PESOS only).
	 *
	 * @param int                  $post_id      Post ID.
	 * @param array<string, mixed> $checkin_data Checkin data.
	 * @return false Always false.
	 */
	protected function syndicate_checkin( int $post_id, array $checkin_data ) {
		return false;
	}

	/**
	 * Import a checkin.
	 *
	 * Handled via webhook, not batch import.
	 *
	 * @param array<string, mixed> $external_checkin External checkin data.
	 * @return false Always false.
	 */
	protected function import_checkin( array $external_checkin ) {
		return false;
	}

	/**
	 * Fetch recent checkins.
	 *
	 * Not applicable - OwnTracks pushes data to us.
	 *
	 * @param int $limit Maximum number of checkins.
	 * @return array Empty array.
	 */
	public function fetch_recent_checkins( int $limit = 50 ): array {
		return [];
	}

	/**
	 * Get the webhook URL for OwnTracks configuration.
	 *
	 * @return string Webhook URL.
	 */
	public function get_webhook_url(): string {
		return rest_url( 'post-kinds-indieweb/v1/owntracks' );
	}
}

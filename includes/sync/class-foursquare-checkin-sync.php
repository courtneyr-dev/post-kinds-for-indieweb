<?php
/**
 * Foursquare Checkin Sync
 *
 * Bidirectional checkin synchronization with Foursquare/Swarm.
 * Supports both POSSE (site → Foursquare) and PESOS (Foursquare → site).
 *
 * Note: Foursquare's v3 Places API is read-only. For checkins, we use the
 * older v2 API which requires OAuth2 user authentication.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\Sync;

use ReactionsForIndieWeb\Meta_Fields;
use ReactionsForIndieWeb\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Foursquare Checkin Sync class.
 *
 * @since 1.0.0
 */
class Foursquare_Checkin_Sync extends Checkin_Sync_Base {

	/**
	 * Service identifier.
	 *
	 * @var string
	 */
	protected string $service_id = 'foursquare';

	/**
	 * Service display name.
	 *
	 * @var string
	 */
	protected string $service_name = 'Foursquare';

	/**
	 * OAuth base URL (v2 API for checkins).
	 *
	 * @var string
	 */
	private const OAUTH_URL = 'https://foursquare.com/oauth2/';

	/**
	 * API base URL (v2 for checkins).
	 *
	 * @var string
	 */
	private const API_URL = 'https://api.foursquare.com/v2/';

	/**
	 * API version date.
	 *
	 * @var string
	 */
	private const API_VERSION = '20231001';

	/**
	 * Client ID.
	 *
	 * @var string|null
	 */
	private ?string $client_id = null;

	/**
	 * Client secret.
	 *
	 * @var string|null
	 */
	private ?string $client_secret = null;

	/**
	 * Access token.
	 *
	 * @var string|null
	 */
	private ?string $access_token = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$credentials          = get_option( 'reactions_indieweb_api_credentials', array() );
		$fs_creds             = $credentials['foursquare'] ?? array();
		$this->client_id      = $fs_creds['client_id'] ?? '';
		$this->client_secret  = $fs_creds['client_secret'] ?? '';
		// Check both keys for backwards compatibility (API settings uses 'access_token', sync class used 'user_access_token').
		$this->access_token   = $fs_creds['access_token'] ?? $fs_creds['user_access_token'] ?? '';
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// OAuth callback.
		register_rest_route(
			'reactions-indieweb/v1',
			'/foursquare/oauth/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_oauth_callback_request' ),
				'permission_callback' => '__return_true',
			)
		);

		// Manual import trigger.
		register_rest_route(
			'reactions-indieweb/v1',
			'/foursquare/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_import_request' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Disconnect.
		register_rest_route(
			'reactions-indieweb/v1',
			'/foursquare/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_disconnect_request' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Check if the service is connected.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return ! empty( $this->access_token );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @return string
	 */
	public function get_auth_url(): string {
		if ( empty( $this->client_id ) ) {
			return '';
		}

		$redirect_uri = rest_url( 'reactions-indieweb/v1/foursquare/oauth/callback' );

		// Store state for CSRF protection.
		$state = wp_create_nonce( 'foursquare_oauth' );
		set_transient( 'reactions_foursquare_oauth_state', $state, HOUR_IN_SECONDS );

		return add_query_arg(
			array(
				'client_id'     => $this->client_id,
				'response_type' => 'code',
				'redirect_uri'  => $redirect_uri,
			),
			self::OAUTH_URL . 'authenticate'
		);
	}

	/**
	 * Handle OAuth callback REST request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_oauth_callback_request( \WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$error = $request->get_param( 'error' );

		if ( $error ) {
			return $this->oauth_redirect_with_error( $error );
		}

		if ( empty( $code ) ) {
			return $this->oauth_redirect_with_error( 'missing_code' );
		}

		$success = $this->handle_oauth_callback( $code );

		if ( $success ) {
			return $this->oauth_redirect_with_success();
		}

		return $this->oauth_redirect_with_error( 'token_exchange_failed' );
	}

	/**
	 * Handle OAuth callback and store tokens.
	 *
	 * @param string $code Authorization code.
	 * @return bool True on success.
	 */
	public function handle_oauth_callback( string $code ): bool {
		$redirect_uri = rest_url( 'reactions-indieweb/v1/foursquare/oauth/callback' );

		$response = wp_remote_post(
			self::OAUTH_URL . 'access_token',
			array(
				'body' => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $redirect_uri,
					'code'          => $code,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'OAuth token exchange failed', array( 'error' => $response->get_error_message() ) );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$this->log( 'OAuth response missing access_token', array( 'body' => $body ) );
			return false;
		}

		// Store the user access token.
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
		$credentials['foursquare']['user_access_token'] = $body['access_token'];
		update_option( 'reactions_indieweb_api_credentials', $credentials );

		$this->access_token = $body['access_token'];

		// Fetch and store user info.
		$this->fetch_and_store_user_info();

		$this->log( 'OAuth connection successful' );
		return true;
	}

	/**
	 * Fetch and store Foursquare user info.
	 *
	 * @return void
	 */
	private function fetch_and_store_user_info(): void {
		try {
			$user = $this->api_get( 'users/self' );

			if ( ! empty( $user['response']['user'] ) ) {
				$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
				$credentials['foursquare']['user_id']   = $user['response']['user']['id'] ?? '';
				$credentials['foursquare']['user_name'] = $user['response']['user']['firstName'] ?? '';
				update_option( 'reactions_indieweb_api_credentials', $credentials );
			}
		} catch ( \Exception $e ) {
			$this->log( 'Failed to fetch user info', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Handle import REST request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_import_request( \WP_REST_Request $request ) {
		if ( ! $this->is_connected() ) {
			return new \WP_Error(
				'not_connected',
				__( 'Foursquare is not connected. Please authorize first.', 'reactions-indieweb' ),
				array( 'status' => 400 )
			);
		}

		$limit  = $request->get_param( 'limit' ) ?: 50;
		$result = $this->import_checkins( (int) $limit );

		return rest_ensure_response( $result );
	}

	/**
	 * Handle disconnect REST request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function handle_disconnect_request( \WP_REST_Request $request ) {
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );

		unset( $credentials['foursquare']['user_access_token'] );
		unset( $credentials['foursquare']['user_id'] );
		unset( $credentials['foursquare']['user_name'] );

		update_option( 'reactions_indieweb_api_credentials', $credentials );

		$this->access_token = null;

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Foursquare disconnected.', 'reactions-indieweb' ),
		) );
	}

	/**
	 * Syndicate a checkin to Foursquare (POSSE).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $checkin_data Checkin data.
	 * @return array|false External checkin data or false on failure.
	 */
	protected function syndicate_checkin( int $post_id, array $checkin_data ) {
		if ( ! $this->is_connected() ) {
			return false;
		}

		// Build checkin parameters.
		$params = array();

		// If we have a Foursquare venue ID, use it.
		if ( ! empty( $checkin_data['foursquare_id'] ) ) {
			$params['venueId'] = $checkin_data['foursquare_id'];
		} elseif ( ! empty( $checkin_data['latitude'] ) && ! empty( $checkin_data['longitude'] ) ) {
			// Otherwise try to match venue by location.
			$venue = $this->find_venue_for_checkin( $checkin_data );

			if ( $venue ) {
				$params['venueId'] = $venue['id'];
			} else {
				// Can't checkin without a venue.
				$this->log( 'No venue found for checkin', array( 'data' => $checkin_data ) );
				return false;
			}
		} else {
			$this->log( 'Missing location data for checkin', array( 'post_id' => $post_id ) );
			return false;
		}

		// Add shout (note).
		if ( ! empty( $checkin_data['note'] ) ) {
			$params['shout'] = wp_strip_all_tags( $checkin_data['note'] );
		}

		// Add coordinates.
		if ( ! empty( $checkin_data['latitude'] ) && ! empty( $checkin_data['longitude'] ) ) {
			$params['ll'] = $checkin_data['latitude'] . ',' . $checkin_data['longitude'];
		}

		// Broadcast settings (checkin visibility on Foursquare).
		$params['broadcast'] = 'public';

		try {
			$response = $this->api_post( 'checkins/add', $params );

			if ( ! empty( $response['response']['checkin'] ) ) {
				$checkin = $response['response']['checkin'];

				return array(
					'id'  => $checkin['id'],
					'url' => 'https://foursquare.com/user/' . ( $checkin['user']['id'] ?? 'self' ) . '/checkin/' . $checkin['id'],
				);
			}
		} catch ( \Exception $e ) {
			$this->log( 'Failed to syndicate checkin', array(
				'post_id' => $post_id,
				'error'   => $e->getMessage(),
			) );
		}

		return false;
	}

	/**
	 * Find a Foursquare venue for a checkin.
	 *
	 * @param array $checkin_data Checkin data.
	 * @return array|null Venue data or null.
	 */
	private function find_venue_for_checkin( array $checkin_data ): ?array {
		$params = array(
			'll'    => $checkin_data['latitude'] . ',' . $checkin_data['longitude'],
			'limit' => 10,
		);

		if ( ! empty( $checkin_data['venue_name'] ) ) {
			$params['query'] = $checkin_data['venue_name'];
		}

		try {
			$response = $this->api_get( 'venues/search', $params );

			if ( ! empty( $response['response']['venues'] ) ) {
				$venues = $response['response']['venues'];

				// If we have a venue name, try to find exact match.
				if ( ! empty( $checkin_data['venue_name'] ) ) {
					foreach ( $venues as $venue ) {
						if ( strcasecmp( $venue['name'], $checkin_data['venue_name'] ) === 0 ) {
							return $venue;
						}
					}
				}

				// Return closest venue.
				return $venues[0];
			}
		} catch ( \Exception $e ) {
			$this->log( 'Venue search failed', array( 'error' => $e->getMessage() ) );
		}

		return null;
	}

	/**
	 * Import a checkin from Foursquare (PESOS).
	 *
	 * @param array $external_checkin External checkin data.
	 * @return int|false Post ID or false on failure.
	 */
	protected function import_checkin( array $external_checkin ) {
		$venue = $external_checkin['venue'] ?? array();

		if ( empty( $venue ) ) {
			return false;
		}

		$location = $venue['location'] ?? array();

		// Prepare post data.
		$post_data = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_date'    => gmdate( 'Y-m-d H:i:s', $external_checkin['createdAt'] ?? time() ),
			'post_content' => $external_checkin['shout'] ?? '',
			'post_title'   => sprintf(
				/* translators: %s: venue name */
				__( 'Checked in at %s', 'reactions-indieweb' ),
				$venue['name'] ?? __( 'Unknown venue', 'reactions-indieweb' )
			),
		);

		// Create post.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			$this->log( 'Failed to create checkin post', array( 'error' => $post_id->get_error_message() ) );
			return false;
		}

		// Set kind taxonomy.
		wp_set_object_terms( $post_id, 'checkin', Taxonomy::TAXONOMY );

		// Set meta fields.
		$prefix = Meta_Fields::PREFIX;

		update_post_meta( $post_id, $prefix . 'checkin_name', $venue['name'] ?? '' );
		update_post_meta( $post_id, $prefix . 'checkin_address', $location['address'] ?? '' );
		update_post_meta( $post_id, $prefix . 'checkin_locality', $location['city'] ?? '' );
		update_post_meta( $post_id, $prefix . 'checkin_region', $location['state'] ?? '' );
		update_post_meta( $post_id, $prefix . 'checkin_country', $location['country'] ?? '' );
		update_post_meta( $post_id, $prefix . 'checkin_postal_code', $location['postalCode'] ?? '' );

		if ( ! empty( $location['lat'] ) && ! empty( $location['lng'] ) ) {
			update_post_meta( $post_id, $prefix . 'geo_latitude', $location['lat'] );
			update_post_meta( $post_id, $prefix . 'geo_longitude', $location['lng'] );
		}

		// Store Foursquare venue ID for future POSSE matching.
		update_post_meta( $post_id, $prefix . 'checkin_foursquare_id', $venue['id'] ?? '' );

		// Apply default privacy setting.
		$settings = get_option( 'reactions_indieweb_settings', array() );
		$default_privacy = $settings['checkin_default_privacy'] ?? 'approximate';
		update_post_meta( $post_id, $prefix . 'geo_privacy', $default_privacy );

		return $post_id;
	}

	/**
	 * Fetch recent checkins from Foursquare.
	 *
	 * @param int $limit Max checkins to fetch.
	 * @return array Array of checkin data.
	 */
	public function fetch_recent_checkins( int $limit = 50 ): array {
		if ( ! $this->is_connected() ) {
			return array();
		}

		try {
			$response = $this->api_get( 'users/self/checkins', array(
				'limit' => min( $limit, 250 ),
				'sort'  => 'newestfirst',
			) );

			if ( ! empty( $response['response']['checkins']['items'] ) ) {
				return $response['response']['checkins']['items'];
			}
		} catch ( \Exception $e ) {
			$this->log( 'Failed to fetch checkins', array( 'error' => $e->getMessage() ) );
		}

		return array();
	}

	/**
	 * Make a GET request to the Foursquare API (v2).
	 *
	 * @param string $endpoint Endpoint.
	 * @param array  $params   Query parameters.
	 * @return array Response data.
	 * @throws \Exception On error.
	 */
	private function api_get( string $endpoint, array $params = array() ): array {
		$params['oauth_token'] = $this->access_token;
		$params['v']           = self::API_VERSION;

		$url = self::API_URL . ltrim( $endpoint, '/' ) . '?' . http_build_query( $params );

		$response = wp_remote_get( $url, array(
			'timeout' => 30,
		) );

		return $this->handle_response( $response );
	}

	/**
	 * Make a POST request to the Foursquare API (v2).
	 *
	 * @param string $endpoint Endpoint.
	 * @param array  $params   Body parameters.
	 * @return array Response data.
	 * @throws \Exception On error.
	 */
	private function api_post( string $endpoint, array $params = array() ): array {
		$params['oauth_token'] = $this->access_token;
		$params['v']           = self::API_VERSION;

		$url = self::API_URL . ltrim( $endpoint, '/' );

		$response = wp_remote_post( $url, array(
			'timeout' => 30,
			'body'    => $params,
		) );

		return $this->handle_response( $response );
	}

	/**
	 * Handle API response.
	 *
	 * @param array|\WP_Error $response Response.
	 * @return array Response data.
	 * @throws \Exception On error.
	 */
	private function handle_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$error_msg = $body['meta']['errorDetail'] ?? $body['meta']['errorType'] ?? 'API error';
			throw new \Exception( $error_msg, $code );
		}

		return $body ?? array();
	}

	/**
	 * Redirect to settings with error.
	 *
	 * @param string $error Error code.
	 * @return void
	 */
	private function oauth_redirect_with_error( string $error ) {
		$redirect_url = add_query_arg(
			array(
				'page'            => 'reactions-indieweb-apis',
				'foursquare_auth' => 'error',
				'error'           => $error,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Redirect to settings with success.
	 *
	 * @return void
	 */
	private function oauth_redirect_with_success() {
		$redirect_url = add_query_arg(
			array(
				'page'            => 'reactions-indieweb-apis',
				'foursquare_auth' => 'success',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}

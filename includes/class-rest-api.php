<?php
/**
 * REST API Controller
 *
 * Registers and handles all custom REST API endpoints for the plugin.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

use ReactionsForIndieWeb\APIs\MusicBrainz;
use ReactionsForIndieWeb\APIs\ListenBrainz;
use ReactionsForIndieWeb\APIs\LastFM;
use ReactionsForIndieWeb\APIs\TMDB;
use ReactionsForIndieWeb\APIs\Trakt;
use ReactionsForIndieWeb\APIs\Simkl;
use ReactionsForIndieWeb\APIs\TVmaze;
use ReactionsForIndieWeb\APIs\OpenLibrary;
use ReactionsForIndieWeb\APIs\Hardcover;
use ReactionsForIndieWeb\APIs\GoogleBooks;
use ReactionsForIndieWeb\APIs\PodcastIndex;
use ReactionsForIndieWeb\APIs\Foursquare;
use ReactionsForIndieWeb\APIs\Nominatim;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller class.
 *
 * Handles registration of REST API endpoints for lookups, imports,
 * and webhook receivers.
 *
 * @since 1.0.0
 */
class REST_API {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'reactions-indieweb/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$this->register_lookup_routes();
		$this->register_location_routes();
		$this->register_import_routes();
		$this->register_webhook_routes();
		$this->register_oauth_routes();
		$this->register_settings_routes();
	}

	/**
	 * Register lookup/search routes.
	 *
	 * @return void
	 */
	private function register_lookup_routes(): void {
		// Music lookup.
		register_rest_route(
			self::NAMESPACE,
			'/lookup/music',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_music' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'q'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query (track name, artist, or both)', 'reactions-indieweb' ),
					),
					'artist' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Artist name to narrow search', 'reactions-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'musicbrainz',
						'enum'              => array( 'musicbrainz', 'lastfm', 'listenbrainz' ),
						'description'       => __( 'API source to use', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Movie/TV lookup.
		register_rest_route(
			self::NAMESPACE,
			'/lookup/video',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_video' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'q'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query (title)', 'reactions-indieweb' ),
					),
					'type'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'multi',
						'enum'              => array( 'movie', 'tv', 'multi' ),
						'description'       => __( 'Content type to search', 'reactions-indieweb' ),
					),
					'year'   => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Release year to narrow search', 'reactions-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'tmdb',
						'enum'              => array( 'tmdb', 'trakt', 'tvmaze', 'simkl' ),
						'description'       => __( 'API source to use', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Book lookup.
		register_rest_route(
			self::NAMESPACE,
			'/lookup/book',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_book' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'q'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query (title, author, or ISBN)', 'reactions-indieweb' ),
					),
					'isbn'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'ISBN for direct lookup', 'reactions-indieweb' ),
					),
					'author' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Author name to narrow search', 'reactions-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'openlibrary',
						'enum'              => array( 'openlibrary', 'hardcover', 'googlebooks' ),
						'description'       => __( 'API source to use', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Podcast lookup.
		register_rest_route(
			self::NAMESPACE,
			'/lookup/podcast',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_podcast' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'q'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query (podcast name)', 'reactions-indieweb' ),
					),
					'feed' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'description'       => __( 'RSS feed URL for direct lookup', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Venue/location lookup.
		register_rest_route(
			self::NAMESPACE,
			'/lookup/venue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_venue' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'q'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query (venue name or address)', 'reactions-indieweb' ),
					),
					'lat'    => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Latitude for nearby search', 'reactions-indieweb' ),
					),
					'lng'    => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Longitude for nearby search', 'reactions-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'foursquare',
						'enum'              => array( 'foursquare', 'nominatim' ),
						'description'       => __( 'API source to use', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Geocoding.
		register_rest_route(
			self::NAMESPACE,
			'/geocode',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'geocode' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'address' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Address to geocode', 'reactions-indieweb' ),
					),
					'lat'     => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Latitude for reverse geocoding', 'reactions-indieweb' ),
					),
					'lng'     => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Longitude for reverse geocoding', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Get details by ID (for all types).
		register_rest_route(
			self::NAMESPACE,
			'/details/(?P<type>[a-z]+)/(?P<id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_details' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'type'   => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'music', 'movie', 'tv', 'book', 'podcast', 'venue' ),
					),
					'id'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'source' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Register location-specific routes with caching and throttling.
	 *
	 * These endpoints proxy Nominatim requests to comply with usage policy.
	 *
	 * @return void
	 */
	private function register_location_routes(): void {
		// Location search (geocoding).
		register_rest_route(
			self::NAMESPACE,
			'/location/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'location_search' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'query' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query for location', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Reverse geocoding.
		register_rest_route(
			self::NAMESPACE,
			'/location/reverse',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'location_reverse' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'lat' => array(
						'required'          => true,
						'type'              => 'number',
						'description'       => __( 'Latitude', 'reactions-indieweb' ),
					),
					'lon' => array(
						'required'          => true,
						'type'              => 'number',
						'description'       => __( 'Longitude', 'reactions-indieweb' ),
					),
				),
			)
		);

		// Foursquare venue search.
		register_rest_route(
			self::NAMESPACE,
			'/location/venues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'location_venues' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'query' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query', 'reactions-indieweb' ),
					),
					'lat'   => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Latitude for nearby search', 'reactions-indieweb' ),
					),
					'lon'   => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Longitude for nearby search', 'reactions-indieweb' ),
					),
				),
			)
		);
	}

	/**
	 * Register import routes.
	 *
	 * @return void
	 */
	private function register_import_routes(): void {
		// Import from external service.
		register_rest_route(
			self::NAMESPACE,
			'/import/(?P<service>[a-z]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_from_service' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'service'    => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array(
							'listenbrainz',
							'lastfm',
							'trakt',
							'simkl',
							'hardcover',
							'foursquare',
						),
					),
					'date_from'  => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Start date (ISO 8601)', 'reactions-indieweb' ),
					),
					'date_to'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'End date (ISO 8601)', 'reactions-indieweb' ),
					),
					'limit'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Maximum items to import', 'reactions-indieweb' ),
					),
					'dry_run'    => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'post_status'=> array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'draft',
						'enum'     => array( 'draft', 'publish', 'private' ),
					),
				),
			)
		);

		// Get import status/progress.
		register_rest_route(
			self::NAMESPACE,
			'/import/status/(?P<job_id>[a-zA-Z0-9]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_import_status' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'job_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Cancel import.
		register_rest_route(
			self::NAMESPACE,
			'/import/cancel/(?P<job_id>[a-zA-Z0-9]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_import' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'job_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Register webhook routes.
	 *
	 * @return void
	 */
	private function register_webhook_routes(): void {
		// ListenBrainz webhook.
		register_rest_route(
			self::NAMESPACE,
			'/webhook/listenbrainz',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook_listenbrainz' ),
				'permission_callback' => array( $this, 'verify_webhook_signature' ),
			)
		);

		// Trakt webhook (scrobble).
		register_rest_route(
			self::NAMESPACE,
			'/webhook/trakt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook_trakt' ),
				'permission_callback' => array( $this, 'verify_webhook_signature' ),
			)
		);

		// Plex webhook.
		register_rest_route(
			self::NAMESPACE,
			'/webhook/plex',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook_plex' ),
				'permission_callback' => array( $this, 'verify_webhook_signature' ),
			)
		);

		// Jellyfin webhook.
		register_rest_route(
			self::NAMESPACE,
			'/webhook/jellyfin',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook_jellyfin' ),
				'permission_callback' => array( $this, 'verify_webhook_signature' ),
			)
		);

		// Generic webhook (for custom integrations).
		register_rest_route(
			self::NAMESPACE,
			'/webhook/generic',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'webhook_generic' ),
				'permission_callback' => array( $this, 'verify_webhook_token' ),
			)
		);
	}

	/**
	 * Register OAuth routes.
	 *
	 * @return void
	 */
	private function register_oauth_routes(): void {
		// OAuth callback handler.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/callback/(?P<service>[a-z]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'oauth_callback' ),
				'permission_callback' => '__return_true', // OAuth callbacks need to be public.
				'args'                => array(
					'service' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'trakt', 'foursquare', 'lastfm' ),
					),
					'code'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'state'   => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Get OAuth authorization URL.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/authorize/(?P<service>[a-z]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_oauth_url' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'service' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'trakt', 'foursquare', 'lastfm' ),
					),
				),
			)
		);

		// Revoke OAuth token.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/revoke/(?P<service>[a-z]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke_oauth' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'service' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'trakt', 'foursquare', 'lastfm' ),
					),
				),
			)
		);

		// Check connection status.
		register_rest_route(
			self::NAMESPACE,
			'/oauth/status/(?P<service>[a-z]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_oauth_status' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'service' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'trakt', 'foursquare', 'lastfm', 'listenbrainz', 'simkl', 'hardcover' ),
					),
				),
			)
		);
	}

	/**
	 * Register settings routes.
	 *
	 * @return void
	 */
	private function register_settings_routes(): void {
		// Get API settings.
		register_rest_route(
			self::NAMESPACE,
			'/settings/apis',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_api_settings' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		// Update API settings.
		register_rest_route(
			self::NAMESPACE,
			'/settings/apis',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_api_settings' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		// Test API connection.
		register_rest_route(
			self::NAMESPACE,
			'/settings/test/(?P<service>[a-z]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_api_connection' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
				'args'                => array(
					'service' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Get webhook URLs.
		register_rest_route(
			self::NAMESPACE,
			'/settings/webhooks',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_webhook_urls' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);

		// Regenerate webhook secret.
		register_rest_route(
			self::NAMESPACE,
			'/settings/webhooks/regenerate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'regenerate_webhook_secret' ),
				'permission_callback' => array( $this, 'can_manage_options' ),
			)
		);
	}

	// =========================================================================
	// Permission Callbacks
	// =========================================================================

	/**
	 * Check if current user can edit posts.
	 *
	 * @return bool
	 */
	public function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check if current user can manage options.
	 *
	 * @return bool
	 */
	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verify webhook signature.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function verify_webhook_signature( \WP_REST_Request $request ): bool {
		$signature = $request->get_header( 'X-Webhook-Signature' );
		$secret    = get_option( 'reactions_indieweb_webhook_secret' );

		if ( empty( $signature ) || empty( $secret ) ) {
			return false;
		}

		$payload  = $request->get_body();
		$expected = hash_hmac( 'sha256', $payload, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Verify webhook token (simpler auth).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function verify_webhook_token( \WP_REST_Request $request ): bool {
		$token  = $request->get_param( 'token' ) ?? $request->get_header( 'X-Webhook-Token' );
		$secret = get_option( 'reactions_indieweb_webhook_secret' );

		if ( empty( $token ) || empty( $secret ) ) {
			return false;
		}

		return hash_equals( $secret, $token );
	}

	// =========================================================================
	// Lookup Callbacks
	// =========================================================================

	/**
	 * Lookup music.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_music( \WP_REST_Request $request ) {
		$query  = $request->get_param( 'q' );
		$artist = $request->get_param( 'artist' );
		$source = $request->get_param( 'source' );

		try {
			switch ( $source ) {
				case 'lastfm':
					$api = new LastFM();
					break;
				case 'listenbrainz':
					$api = new ListenBrainz();
					break;
				case 'musicbrainz':
				default:
					$api = new MusicBrainz();
					break;
			}

			$results = $api->search( $query, $artist );

			return rest_ensure_response( $results );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'lookup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Lookup video (movie/TV).
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_video( \WP_REST_Request $request ) {
		$query  = $request->get_param( 'q' );
		$type   = $request->get_param( 'type' );
		$year   = $request->get_param( 'year' );
		$source = $request->get_param( 'source' );

		try {
			switch ( $source ) {
				case 'trakt':
					$api = new Trakt();
					break;
				case 'tvmaze':
					$api = new TVmaze();
					break;
				case 'simkl':
					$api = new Simkl();
					break;
				case 'tmdb':
				default:
					$api = new TMDB();
					break;
			}

			$results = $api->search( $query, $type, $year );

			return rest_ensure_response( $results );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'lookup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Lookup book.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_book( \WP_REST_Request $request ) {
		$query  = $request->get_param( 'q' );
		$isbn   = $request->get_param( 'isbn' );
		$author = $request->get_param( 'author' );
		$source = $request->get_param( 'source' );

		try {
			switch ( $source ) {
				case 'hardcover':
					$api = new Hardcover();
					break;
				case 'googlebooks':
					$api = new GoogleBooks();
					break;
				case 'openlibrary':
				default:
					$api = new OpenLibrary();
					break;
			}

			if ( $isbn ) {
				$results = $api->get_by_isbn( $isbn );
			} else {
				$results = $api->search( $query, $author );
			}

			return rest_ensure_response( $results );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'lookup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Lookup podcast.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_podcast( \WP_REST_Request $request ) {
		$query = $request->get_param( 'q' );
		$feed  = $request->get_param( 'feed' );

		try {
			$api = new PodcastIndex();

			if ( $feed ) {
				$results = $api->get_by_feed( $feed );
			} else {
				$results = $api->search( $query );
			}

			return rest_ensure_response( $results );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'lookup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Lookup venue.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_venue( \WP_REST_Request $request ) {
		$query  = $request->get_param( 'q' );
		$lat    = $request->get_param( 'lat' );
		$lng    = $request->get_param( 'lng' );
		$source = $request->get_param( 'source' );

		try {
			switch ( $source ) {
				case 'nominatim':
					$api = new Nominatim();
					break;
				case 'foursquare':
				default:
					$api = new Foursquare();
					break;
			}

			$results = $api->search( $query, $lat, $lng );

			return rest_ensure_response( $results );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'lookup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Geocode address or reverse geocode coordinates.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function geocode( \WP_REST_Request $request ) {
		$address = $request->get_param( 'address' );
		$lat     = $request->get_param( 'lat' );
		$lng     = $request->get_param( 'lng' );

		try {
			$api = new Nominatim();

			if ( $address ) {
				$result = $api->geocode( $address );
			} elseif ( $lat && $lng ) {
				$result = $api->reverse_geocode( $lat, $lng );
			} else {
				return new \WP_Error(
					'missing_params',
					__( 'Provide either address or lat/lng coordinates.', 'reactions-indieweb' ),
					array( 'status' => 400 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'geocode_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Search for locations via Nominatim proxy with throttling.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function location_search( \WP_REST_Request $request ) {
		$query = $request->get_param( 'query' );

		// Check throttle.
		$throttle_key = 'reactions_location_throttle_' . get_current_user_id();
		$last_request = get_transient( $throttle_key );

		if ( $last_request && ( time() - $last_request ) < 1 ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Please wait a moment before searching again.', 'reactions-indieweb' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $throttle_key, time(), 60 );

		try {
			$api     = new Nominatim();
			$results = $api->search( $query );

			return rest_ensure_response( $results );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'search_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Reverse geocode coordinates via Nominatim proxy with throttling.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function location_reverse( \WP_REST_Request $request ) {
		$lat = (float) $request->get_param( 'lat' );
		$lon = (float) $request->get_param( 'lon' );

		// Validate coordinates.
		if ( $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			return new \WP_Error(
				'invalid_coordinates',
				__( 'Invalid coordinates provided.', 'reactions-indieweb' ),
				array( 'status' => 400 )
			);
		}

		// Check throttle.
		$throttle_key = 'reactions_location_throttle_' . get_current_user_id();
		$last_request = get_transient( $throttle_key );

		if ( $last_request && ( time() - $last_request ) < 1 ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Please wait a moment before searching again.', 'reactions-indieweb' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $throttle_key, time(), 60 );

		try {
			$api    = new Nominatim();
			$result = $api->reverse( $lat, $lon );

			if ( ! $result ) {
				return new \WP_Error(
					'not_found',
					__( 'No location found for these coordinates.', 'reactions-indieweb' ),
					array( 'status' => 404 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'reverse_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Search for venues via Foursquare API.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function location_venues( \WP_REST_Request $request ) {
		$query = $request->get_param( 'query' );
		$lat   = $request->get_param( 'lat' );
		$lon   = $request->get_param( 'lon' );

		// Check throttle.
		$throttle_key = 'reactions_location_throttle_' . get_current_user_id();
		$last_request = get_transient( $throttle_key );

		if ( $last_request && ( time() - $last_request ) < 1 ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Please wait a moment before searching again.', 'reactions-indieweb' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $throttle_key, time(), 60 );

		try {
			$api = new Foursquare();

			// Check if Foursquare is configured.
			if ( ! $api->test_connection() ) {
				return new \WP_Error(
					'foursquare_not_configured',
					__( 'Foursquare API is not configured. Please add your API key in Settings > APIs.', 'reactions-indieweb' ),
					array( 'status' => 400 )
				);
			}

			if ( $lat && $lon && ! $query ) {
				// Nearby search.
				$results = $api->search_nearby( (float) $lat, (float) $lon );
			} elseif ( $query ) {
				// Query search, optionally with location bias.
				$results = $api->search( $query, null, $lat ? (float) $lat : null, $lon ? (float) $lon : null );
			} else {
				return new \WP_Error(
					'missing_params',
					__( 'Provide either a search query or lat/lon coordinates.', 'reactions-indieweb' ),
					array( 'status' => 400 )
				);
			}

			return rest_ensure_response( $results );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'venue_search_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get details by ID.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_details( \WP_REST_Request $request ) {
		$type   = $request->get_param( 'type' );
		$id     = $request->get_param( 'id' );
		$source = $request->get_param( 'source' );

		try {
			$api = $this->get_api_for_type( $type, $source );

			if ( ! $api ) {
				return new \WP_Error(
					'invalid_type',
					__( 'Invalid content type.', 'reactions-indieweb' ),
					array( 'status' => 400 )
				);
			}

			$result = $api->get_by_id( $id );

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'details_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get API instance for content type.
	 *
	 * @param string      $type   Content type.
	 * @param string|null $source Preferred source.
	 * @return object|null API instance or null.
	 */
	private function get_api_for_type( string $type, ?string $source = null ): ?object {
		switch ( $type ) {
			case 'music':
				return $source === 'lastfm' ? new LastFM() : new MusicBrainz();
			case 'movie':
			case 'tv':
				return $source === 'trakt' ? new Trakt() : new TMDB();
			case 'book':
				return $source === 'hardcover' ? new Hardcover() : new OpenLibrary();
			case 'podcast':
				return new PodcastIndex();
			case 'venue':
				return $source === 'nominatim' ? new Nominatim() : new Foursquare();
			default:
				return null;
		}
	}

	// =========================================================================
	// Import Callbacks
	// =========================================================================

	/**
	 * Import from external service.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import_from_service( \WP_REST_Request $request ) {
		$service     = $request->get_param( 'service' );
		$date_from   = $request->get_param( 'date_from' );
		$date_to     = $request->get_param( 'date_to' );
		$limit       = $request->get_param( 'limit' );
		$dry_run     = $request->get_param( 'dry_run' );
		$post_status = $request->get_param( 'post_status' );

		try {
			$import_manager = new Import_Manager();

			$job_id = $import_manager->start_import(
				$service,
				array(
					'date_from'   => $date_from,
					'date_to'     => $date_to,
					'limit'       => $limit,
					'dry_run'     => $dry_run,
					'post_status' => $post_status,
				)
			);

			return rest_ensure_response(
				array(
					'job_id'  => $job_id,
					'message' => __( 'Import started.', 'reactions-indieweb' ),
					'status'  => 'processing',
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'import_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get import status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_import_status( \WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );

		$import_manager = new Import_Manager();
		$status         = $import_manager->get_status( $job_id );

		if ( ! $status ) {
			return new \WP_Error(
				'job_not_found',
				__( 'Import job not found.', 'reactions-indieweb' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $status );
	}

	/**
	 * Cancel import.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_import( \WP_REST_Request $request ) {
		$job_id = $request->get_param( 'job_id' );

		$import_manager = new Import_Manager();
		$result         = $import_manager->cancel( $job_id );

		if ( ! $result ) {
			return new \WP_Error(
				'cancel_failed',
				__( 'Could not cancel import.', 'reactions-indieweb' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Import cancelled.', 'reactions-indieweb' ),
			)
		);
	}

	// =========================================================================
	// Webhook Callbacks
	// =========================================================================

	/**
	 * Handle ListenBrainz webhook.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook_listenbrainz( \WP_REST_Request $request ) {
		$handler = new Webhook_Handler();
		return $handler->process_listenbrainz( $request );
	}

	/**
	 * Handle Trakt webhook.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook_trakt( \WP_REST_Request $request ) {
		$handler = new Webhook_Handler();
		return $handler->process_trakt( $request );
	}

	/**
	 * Handle Plex webhook.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook_plex( \WP_REST_Request $request ) {
		$handler = new Webhook_Handler();
		return $handler->process_plex( $request );
	}

	/**
	 * Handle Jellyfin webhook.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook_jellyfin( \WP_REST_Request $request ) {
		$handler = new Webhook_Handler();
		return $handler->process_jellyfin( $request );
	}

	/**
	 * Handle generic webhook.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook_generic( \WP_REST_Request $request ) {
		$handler = new Webhook_Handler();
		return $handler->process_generic( $request );
	}

	// =========================================================================
	// OAuth Callbacks
	// =========================================================================

	/**
	 * Handle OAuth callback.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function oauth_callback( \WP_REST_Request $request ) {
		$service = $request->get_param( 'service' );
		$code    = $request->get_param( 'code' );
		$state   = $request->get_param( 'state' );

		// Verify state.
		$saved_state = get_transient( 'reactions_indieweb_oauth_state_' . $service );

		if ( ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			return new \WP_Error(
				'invalid_state',
				__( 'Invalid OAuth state. Please try again.', 'reactions-indieweb' ),
				array( 'status' => 400 )
			);
		}

		delete_transient( 'reactions_indieweb_oauth_state_' . $service );

		try {
			$api = $this->get_oauth_api( $service );

			if ( ! $api ) {
				throw new \Exception( __( 'Unknown service.', 'reactions-indieweb' ) );
			}

			$tokens = $api->exchange_code( $code );

			// Store tokens securely.
			update_option( 'reactions_indieweb_' . $service . '_access_token', $tokens['access_token'] );

			if ( isset( $tokens['refresh_token'] ) ) {
				update_option( 'reactions_indieweb_' . $service . '_refresh_token', $tokens['refresh_token'] );
			}

			if ( isset( $tokens['expires_in'] ) ) {
				update_option(
					'reactions_indieweb_' . $service . '_token_expires',
					time() + $tokens['expires_in']
				);
			}

			// Redirect back to settings page.
			wp_safe_redirect(
				admin_url( 'options-general.php?page=reactions-indieweb&tab=apis&connected=' . $service )
			);
			exit;
		} catch ( \Exception $e ) {
			wp_safe_redirect(
				admin_url( 'options-general.php?page=reactions-indieweb&tab=apis&error=' . rawurlencode( $e->getMessage() ) )
			);
			exit;
		}
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_oauth_url( \WP_REST_Request $request ) {
		$service = $request->get_param( 'service' );

		try {
			$api = $this->get_oauth_api( $service );

			if ( ! $api ) {
				throw new \Exception( __( 'Unknown service.', 'reactions-indieweb' ) );
			}

			// Generate state token.
			$state = wp_generate_password( 32, false );
			set_transient( 'reactions_indieweb_oauth_state_' . $service, $state, HOUR_IN_SECONDS );

			$url = $api->get_authorization_url( $state );

			return rest_ensure_response( array( 'url' => $url ) );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'oauth_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Revoke OAuth token.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_oauth( \WP_REST_Request $request ) {
		$service = $request->get_param( 'service' );

		try {
			$api = $this->get_oauth_api( $service );

			if ( $api && method_exists( $api, 'revoke_token' ) ) {
				$access_token = get_option( 'reactions_indieweb_' . $service . '_access_token' );
				if ( $access_token ) {
					$api->revoke_token( $access_token );
				}
			}

			// Delete stored tokens.
			delete_option( 'reactions_indieweb_' . $service . '_access_token' );
			delete_option( 'reactions_indieweb_' . $service . '_refresh_token' );
			delete_option( 'reactions_indieweb_' . $service . '_token_expires' );

			return rest_ensure_response(
				array(
					'message' => __( 'Connection revoked.', 'reactions-indieweb' ),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'revoke_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get OAuth connection status.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_oauth_status( \WP_REST_Request $request ) {
		$service = $request->get_param( 'service' );

		$access_token = get_option( 'reactions_indieweb_' . $service . '_access_token' );
		$expires      = get_option( 'reactions_indieweb_' . $service . '_token_expires' );

		$connected = ! empty( $access_token );
		$expired   = $expires && $expires < time();

		$response = array(
			'connected' => $connected && ! $expired,
			'expired'   => $expired,
		);

		// Try to get user info if connected.
		if ( $connected && ! $expired ) {
			try {
				$api = $this->get_oauth_api( $service );
				if ( $api && method_exists( $api, 'get_user' ) ) {
					$user              = $api->get_user();
					$response['user']  = $user;
				}
			} catch ( \Exception $e ) {
				$response['error'] = $e->getMessage();
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Get OAuth API instance.
	 *
	 * @param string $service Service name.
	 * @return object|null API instance or null.
	 */
	private function get_oauth_api( string $service ): ?object {
		switch ( $service ) {
			case 'trakt':
				return new Trakt();
			case 'foursquare':
				return new Foursquare();
			case 'lastfm':
				return new LastFM();
			default:
				return null;
		}
	}

	// =========================================================================
	// Settings Callbacks
	// =========================================================================

	/**
	 * Get API settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_api_settings() {
		$settings = array(
			'tmdb_api_key'          => get_option( 'reactions_indieweb_tmdb_api_key', '' ),
			'trakt_client_id'       => get_option( 'reactions_indieweb_trakt_client_id', '' ),
			'trakt_client_secret'   => get_option( 'reactions_indieweb_trakt_client_secret', '' ) ? '••••••••' : '',
			'lastfm_api_key'        => get_option( 'reactions_indieweb_lastfm_api_key', '' ),
			'lastfm_api_secret'     => get_option( 'reactions_indieweb_lastfm_api_secret', '' ) ? '••••••••' : '',
			'listenbrainz_token'    => get_option( 'reactions_indieweb_listenbrainz_token', '' ) ? '••••••••' : '',
			'simkl_client_id'       => get_option( 'reactions_indieweb_simkl_client_id', '' ),
			'foursquare_client_id'  => get_option( 'reactions_indieweb_foursquare_client_id', '' ),
			'foursquare_client_secret' => get_option( 'reactions_indieweb_foursquare_client_secret', '' ) ? '••••••••' : '',
			'hardcover_api_key'     => get_option( 'reactions_indieweb_hardcover_api_key', '' ) ? '••••••••' : '',
			'podcastindex_api_key'  => get_option( 'reactions_indieweb_podcastindex_api_key', '' ),
			'podcastindex_api_secret' => get_option( 'reactions_indieweb_podcastindex_api_secret', '' ) ? '••••••••' : '',
			'google_books_api_key'  => get_option( 'reactions_indieweb_google_books_api_key', '' ),
		);

		return rest_ensure_response( $settings );
	}

	/**
	 * Update API settings.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function update_api_settings( \WP_REST_Request $request ) {
		$settings = $request->get_json_params();

		$allowed_keys = array(
			'tmdb_api_key',
			'trakt_client_id',
			'trakt_client_secret',
			'lastfm_api_key',
			'lastfm_api_secret',
			'listenbrainz_token',
			'simkl_client_id',
			'foursquare_client_id',
			'foursquare_client_secret',
			'hardcover_api_key',
			'podcastindex_api_key',
			'podcastindex_api_secret',
			'google_books_api_key',
		);

		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $allowed_keys, true ) ) {
				// Don't overwrite with masked values.
				if ( $value !== '••••••••' ) {
					update_option( 'reactions_indieweb_' . $key, sanitize_text_field( $value ) );
				}
			}
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Settings saved.', 'reactions-indieweb' ),
			)
		);
	}

	/**
	 * Test API connection.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_api_connection( \WP_REST_Request $request ) {
		$service = $request->get_param( 'service' );

		try {
			$api = $this->get_api_for_service( $service );

			if ( ! $api ) {
				throw new \Exception( __( 'Unknown service.', 'reactions-indieweb' ) );
			}

			$result = $api->test_connection();

			return rest_ensure_response(
				array(
					'success' => $result,
					'message' => $result
						? __( 'Connection successful!', 'reactions-indieweb' )
						: __( 'Connection failed.', 'reactions-indieweb' ),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'test_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get API instance for service.
	 *
	 * @param string $service Service name.
	 * @return object|null API instance or null.
	 */
	private function get_api_for_service( string $service ): ?object {
		$map = array(
			'musicbrainz'   => MusicBrainz::class,
			'listenbrainz'  => ListenBrainz::class,
			'lastfm'        => LastFM::class,
			'tmdb'          => TMDB::class,
			'trakt'         => Trakt::class,
			'simkl'         => Simkl::class,
			'tvmaze'        => TVmaze::class,
			'openlibrary'   => OpenLibrary::class,
			'hardcover'     => Hardcover::class,
			'googlebooks'   => GoogleBooks::class,
			'podcastindex'  => PodcastIndex::class,
			'foursquare'    => Foursquare::class,
			'nominatim'     => Nominatim::class,
		);

		if ( isset( $map[ $service ] ) ) {
			return new $map[ $service ]();
		}

		return null;
	}

	/**
	 * Get webhook URLs.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_webhook_urls() {
		$secret = get_option( 'reactions_indieweb_webhook_secret' );

		if ( ! $secret ) {
			$secret = wp_generate_password( 32, false );
			update_option( 'reactions_indieweb_webhook_secret', $secret );
		}

		$base_url = rest_url( self::NAMESPACE . '/webhook/' );

		return rest_ensure_response(
			array(
				'listenbrainz' => $base_url . 'listenbrainz',
				'trakt'        => $base_url . 'trakt',
				'plex'         => $base_url . 'plex',
				'jellyfin'     => $base_url . 'jellyfin',
				'generic'      => $base_url . 'generic?token=' . $secret,
				'secret'       => $secret,
			)
		);
	}

	/**
	 * Regenerate webhook secret.
	 *
	 * @return \WP_REST_Response
	 */
	public function regenerate_webhook_secret() {
		$secret = wp_generate_password( 32, false );
		update_option( 'reactions_indieweb_webhook_secret', $secret );

		return rest_ensure_response(
			array(
				'secret'  => $secret,
				'message' => __( 'Webhook secret regenerated.', 'reactions-indieweb' ),
			)
		);
	}
}

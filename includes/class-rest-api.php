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
use ReactionsForIndieWeb\APIs\BoardGameGeek;
use ReactionsForIndieWeb\APIs\RAWG;

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
		$this->register_checkin_routes();
	}

	/**
	 * Register lookup/search routes.
	 *
	 * @return void
	 */
	private function register_lookup_routes(): void {
		// Music URL parsing (Spotify, Apple Music, etc.).
		register_rest_route(
			self::NAMESPACE,
			'/lookup/music-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_music_url' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'description'       => __( 'Music service URL (Spotify, Apple Music, YouTube, etc.)', 'reactions-for-indieweb' ),
					),
				),
			)
		);

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
						'description'       => __( 'Search query (track name, artist, or both)', 'reactions-for-indieweb' ),
					),
					'artist' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Artist name to narrow search', 'reactions-for-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'musicbrainz',
						'enum'              => array( 'musicbrainz', 'lastfm', 'listenbrainz' ),
						'description'       => __( 'API source to use', 'reactions-for-indieweb' ),
					),
				),
			)
		);

		// Watch URL parsing (IMDB, TMDB, Trakt, Letterboxd).
		register_rest_route(
			self::NAMESPACE,
			'/lookup/watch-url',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_watch_url' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'description'       => __( 'Movie/TV service URL (IMDB, TMDB, Trakt, Letterboxd)', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Search query (title)', 'reactions-for-indieweb' ),
					),
					'type'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'multi',
						'enum'              => array( 'movie', 'tv', 'multi' ),
						'description'       => __( 'Content type to search', 'reactions-for-indieweb' ),
					),
					'year'   => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Release year to narrow search', 'reactions-for-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'tmdb',
						'enum'              => array( 'tmdb', 'trakt', 'tvmaze', 'simkl' ),
						'description'       => __( 'API source to use', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Search query (title, author, or ISBN)', 'reactions-for-indieweb' ),
					),
					'isbn'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'ISBN for direct lookup', 'reactions-for-indieweb' ),
					),
					'author' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Author name to narrow search', 'reactions-for-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'openlibrary',
						'enum'              => array( 'openlibrary', 'hardcover', 'googlebooks' ),
						'description'       => __( 'API source to use', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Search query (podcast name)', 'reactions-for-indieweb' ),
					),
					'feed' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'description'       => __( 'RSS feed URL for direct lookup', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Search query (venue name or address)', 'reactions-for-indieweb' ),
					),
					'lat'    => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Latitude for nearby search', 'reactions-for-indieweb' ),
					),
					'lng'    => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Longitude for nearby search', 'reactions-for-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'foursquare',
						'enum'              => array( 'foursquare', 'nominatim' ),
						'description'       => __( 'API source to use', 'reactions-for-indieweb' ),
					),
				),
			)
		);

		// Game lookup.
		register_rest_route(
			self::NAMESPACE,
			'/lookup/game',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_game' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'q'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Search query (game name)', 'reactions-for-indieweb' ),
					),
					'source' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'bgg',
						'enum'              => array( 'bgg', 'rawg' ),
						'description'       => __( 'API source (bgg for BoardGameGeek, rawg for RAWG.io)', 'reactions-for-indieweb' ),
					),
					'type'   => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'boardgame',
						'enum'              => array( 'boardgame', 'videogame' ),
						'description'       => __( 'Game type for BGG (boardgame or videogame)', 'reactions-for-indieweb' ),
					),
					'id'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Game ID for direct lookup', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Address to geocode', 'reactions-for-indieweb' ),
					),
					'lat'     => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Latitude for reverse geocoding', 'reactions-for-indieweb' ),
					),
					'lng'     => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Longitude for reverse geocoding', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Search query for location', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Latitude', 'reactions-for-indieweb' ),
					),
					'lon' => array(
						'required'          => true,
						'type'              => 'number',
						'description'       => __( 'Longitude', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Search query', 'reactions-for-indieweb' ),
					),
					'lat'   => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Latitude for nearby search', 'reactions-for-indieweb' ),
					),
					'lon'   => array(
						'required'          => false,
						'type'              => 'number',
						'description'       => __( 'Longitude for nearby search', 'reactions-for-indieweb' ),
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
						'description'       => __( 'Start date (ISO 8601)', 'reactions-for-indieweb' ),
					),
					'date_to'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'End date (ISO 8601)', 'reactions-for-indieweb' ),
					),
					'limit'      => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Maximum items to import', 'reactions-for-indieweb' ),
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

	/**
	 * Register check-in dashboard routes.
	 *
	 * @return void
	 */
	private function register_checkin_routes(): void {
		// Get check-ins with filters.
		register_rest_route(
			self::NAMESPACE,
			'/checkins',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_checkins' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'page'       => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'   => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
					'year'       => array(
						'type' => 'integer',
					),
					'venue_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Get check-in statistics.
		register_rest_route(
			self::NAMESPACE,
			'/checkins/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_checkin_stats' ),
				'permission_callback' => array( $this, 'can_edit_posts' ),
				'args'                => array(
					'year' => array(
						'type' => 'integer',
					),
				),
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
	 * Lookup music from URL (Spotify, Apple Music, YouTube, etc.).
	 *
	 * Uses oEmbed to extract metadata from music service URLs.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_music_url( \WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );

		if ( empty( $url ) ) {
			return new \WP_Error(
				'missing_url',
				__( 'URL is required.', 'reactions-for-indieweb' ),
				array( 'status' => 400 )
			);
		}

		try {
			$result = $this->parse_music_url( $url );

			if ( ! $result ) {
				return new \WP_Error(
					'parse_failed',
					__( 'Could not extract music info from this URL. Try a direct track or album URL.', 'reactions-for-indieweb' ),
					array( 'status' => 400 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'lookup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Parse a music service URL to extract track metadata.
	 *
	 * @param string $url Music service URL.
	 * @return array|null Track metadata or null on failure.
	 */
	private function parse_music_url( string $url ): ?array {
		// Try WordPress oEmbed first.
		$oembed = _wp_oembed_get_object();
		$data   = $oembed->get_data( $url );

		if ( $data && ! empty( $data->title ) ) {
			$result = array(
				'url'      => $url,
				'provider' => $data->provider_name ?? '',
				'embed'    => $data->html ?? '',
				'track'    => '',
				'artist'   => '',
				'album'    => '',
			);

			// Get album art from thumbnail.
			if ( ! empty( $data->thumbnail_url ) ) {
				$result['cover'] = $data->thumbnail_url;
			}

			// Check for author_name first (some providers use this).
			if ( ! empty( $data->author_name ) ) {
				$result['artist'] = $data->author_name;
				$result['track']  = $this->clean_oembed_title( $data->title, $data->author_name );
			}

			// For Spotify, try to extract artist from the embed HTML.
			// Spotify embeds contain aria-label or title with artist info.
			if ( 'Spotify' === ( $data->provider_name ?? '' ) && ! empty( $data->html ) ) {
				$spotify_data = $this->parse_spotify_embed( $data->html, $data->title );
				if ( ! empty( $spotify_data['artist'] ) ) {
					$result['artist'] = $spotify_data['artist'];
				}
				if ( ! empty( $spotify_data['track'] ) ) {
					$result['track'] = $spotify_data['track'];
				}
				if ( ! empty( $spotify_data['album'] ) ) {
					$result['album'] = $spotify_data['album'];
				}
			}

			// If we still don't have track/artist, try parsing the title.
			if ( empty( $result['track'] ) || empty( $result['artist'] ) ) {
				$parsed = $this->parse_track_title( $data->title );
				if ( empty( $result['track'] ) ) {
					$result['track'] = $parsed['track'];
				}
				if ( empty( $result['artist'] ) ) {
					$result['artist'] = $parsed['artist'];
				}
			}

			// Final fallback: title is the track name.
			if ( empty( $result['track'] ) ) {
				$result['track'] = $data->title;
			}

			// If we have a track name but no artist, try looking up via MusicBrainz.
			if ( ! empty( $result['track'] ) && empty( $result['artist'] ) ) {
				$lookup = $this->lookup_track_metadata( $result['track'] );
				if ( $lookup ) {
					if ( ! empty( $lookup['artist'] ) ) {
						$result['artist'] = $lookup['artist'];
					}
					if ( ! empty( $lookup['album'] ) && empty( $result['album'] ) ) {
						$result['album'] = $lookup['album'];
					}
					if ( ! empty( $lookup['cover'] ) && empty( $result['cover'] ) ) {
						$result['cover'] = $lookup['cover'];
					}
				}
			}

			return $result;
		}

		// Fallback: Try to parse URL directly for known services.
		return $this->parse_url_directly( $url );
	}

	/**
	 * Look up track metadata from MusicBrainz/LastFM when only track name is known.
	 *
	 * @param string $track Track name to search for.
	 * @return array|null Metadata array or null if not found.
	 */
	private function lookup_track_metadata( string $track ): ?array {
		// Try LastFM first as it's faster and has good track data.
		// The LastFM class loads credentials from options automatically.
		try {
			$lastfm = new LastFM();

			// Only search if we have an API key configured.
			if ( $lastfm->test_connection() ) {
				$result = $lastfm->search( $track );

				if ( ! empty( $result ) && is_array( $result ) ) {
					$first = $result[0] ?? null;
					if ( $first ) {
						return array(
							'track'  => $first['track'] ?? $track,
							'artist' => $first['artist'] ?? '',
							'album'  => $first['album'] ?? '',
							'cover'  => $first['cover'] ?? '',
						);
					}
				}
			}
		} catch ( \Exception $e ) {
			// Fall through to MusicBrainz.
		}

		// Try MusicBrainz as fallback (no API key required).
		try {
			$musicbrainz = new MusicBrainz();
			$result      = $musicbrainz->search( $track );

			if ( ! empty( $result ) && is_array( $result ) ) {
				$first = $result[0] ?? null;
				if ( $first ) {
					return array(
						'track'  => $first['track'] ?? $track,
						'artist' => $first['artist'] ?? '',
						'album'  => $first['album'] ?? '',
						'cover'  => $first['cover'] ?? '',
					);
				}
			}
		} catch ( \Exception $e ) {
			// Lookup failed, return null.
		}

		return null;
	}

	/**
	 * Parse Spotify embed HTML to extract track and artist.
	 *
	 * @param string $html  Embed HTML.
	 * @param string $title oEmbed title.
	 * @return array Array with track, artist, album keys.
	 */
	private function parse_spotify_embed( string $html, string $title ): array {
		$result = array(
			'track'  => '',
			'artist' => '',
			'album'  => '',
		);

		// Spotify oEmbed title format is usually just the track name.
		// The artist appears in the iframe title attribute like:
		// title="Spotify Embed: Track Name"
		// But artist info is in the page, not directly accessible via oEmbed.

		// Try to parse from the title if it contains " by " or " - ".
		if ( preg_match( '/^(.+?)\s+by\s+(.+)$/i', $title, $matches ) ) {
			$result['track']  = trim( $matches[1] );
			$result['artist'] = trim( $matches[2] );
		} elseif ( preg_match( '/^(.+?)\s*[-–]\s*(.+)$/', $title, $matches ) ) {
			// Could be "Artist - Track" or "Track - Artist", hard to know.
			// Assume "Track - Additional Info" format for Spotify.
			$result['track'] = trim( $matches[1] );
		}

		// If title is clean (no separators), it's just the track name.
		if ( empty( $result['track'] ) ) {
			$result['track'] = $title;
		}

		return $result;
	}

	/**
	 * Clean oEmbed title by removing artist suffix.
	 *
	 * @param string $title       Full title.
	 * @param string $author_name Author/artist name.
	 * @return string Clean track title.
	 */
	private function clean_oembed_title( string $title, string $author_name ): string {
		// Remove " - song and lyrics by Artist" suffix (Spotify).
		$title = preg_replace( '/\s*[-–]\s*song and lyrics by\s*.+$/i', '', $title );

		// Remove " by Artist" suffix.
		$title = preg_replace( '/\s+by\s+' . preg_quote( $author_name, '/' ) . '$/i', '', $title );

		return trim( $title );
	}

	/**
	 * Parse track title in "Artist - Track" or "Track by Artist" format.
	 *
	 * @param string $title Full title string.
	 * @return array Array with 'track' and 'artist' keys.
	 */
	private function parse_track_title( string $title ): array {
		$result = array(
			'track'  => $title,
			'artist' => '',
		);

		// Try "Artist - Track" format.
		if ( preg_match( '/^(.+?)\s*[-–]\s*(.+)$/', $title, $matches ) ) {
			$result['artist'] = trim( $matches[1] );
			$result['track']  = trim( $matches[2] );
		}
		// Try "Track by Artist" format.
		elseif ( preg_match( '/^(.+?)\s+by\s+(.+)$/i', $title, $matches ) ) {
			$result['track']  = trim( $matches[1] );
			$result['artist'] = trim( $matches[2] );
		}

		return $result;
	}

	/**
	 * Parse URL directly when oEmbed fails.
	 *
	 * @param string $url Music service URL.
	 * @return array|null Parsed data or null.
	 */
	private function parse_url_directly( string $url ): ?array {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		// Spotify: open.spotify.com/track/ID
		if ( strpos( $host, 'spotify.com' ) !== false ) {
			if ( preg_match( '/\/(track|album|artist)\/([a-zA-Z0-9]+)/', $url, $matches ) ) {
				return array(
					'url'      => $url,
					'provider' => 'Spotify',
					'type'     => $matches[1],
					'id'       => $matches[2],
					// We'd need Spotify API to get actual metadata.
					'track'    => '',
					'artist'   => '',
					'note'     => __( 'Paste a Spotify track URL to auto-fill metadata.', 'reactions-for-indieweb' ),
				);
			}
		}

		// Apple Music.
		if ( strpos( $host, 'music.apple.com' ) !== false ) {
			return array(
				'url'      => $url,
				'provider' => 'Apple Music',
			);
		}

		// YouTube / YouTube Music.
		if ( strpos( $host, 'youtube.com' ) !== false || strpos( $host, 'youtu.be' ) !== false ) {
			return array(
				'url'      => $url,
				'provider' => 'YouTube',
			);
		}

		return null;
	}

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
	 * Lookup watch URL (IMDB, TMDB, Trakt, Letterboxd).
	 *
	 * Parses a URL from movie/TV services and extracts metadata.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_watch_url( \WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );

		if ( empty( $url ) ) {
			return new \WP_Error(
				'missing_url',
				__( 'URL is required.', 'reactions-for-indieweb' ),
				array( 'status' => 400 )
			);
		}

		try {
			$result = $this->parse_watch_url( $url );

			if ( ! $result ) {
				return new \WP_Error(
					'parse_failed',
					__( 'Could not extract movie/TV info from this URL. Try IMDB, TMDB, or Trakt URLs.', 'reactions-for-indieweb' ),
					array( 'status' => 400 )
				);
			}

			return rest_ensure_response( $result );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'lookup_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Parse a watch service URL to extract movie/TV metadata.
	 *
	 * Supports:
	 * - IMDB: imdb.com/title/tt1234567
	 * - TMDB: themoviedb.org/movie/123 or /tv/456
	 * - Trakt: trakt.tv/movies/slug or /shows/slug
	 * - Letterboxd: letterboxd.com/film/slug
	 *
	 * @param string $url Watch service URL.
	 * @return array|null Movie/TV metadata or null on failure.
	 */
	private function parse_watch_url( string $url ): ?array {
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';
		$path   = $parsed['path'] ?? '';

		// Normalize host (remove www.).
		$host = preg_replace( '/^www\./', '', $host );

		// IMDB: imdb.com/title/tt1234567
		if ( 'imdb.com' === $host || 'm.imdb.com' === $host ) {
			return $this->parse_imdb_url( $path );
		}

		// TMDB: themoviedb.org/movie/123 or /tv/456
		if ( 'themoviedb.org' === $host ) {
			return $this->parse_tmdb_url( $path );
		}

		// Trakt: trakt.tv/movies/slug or /shows/slug
		if ( 'trakt.tv' === $host ) {
			return $this->parse_trakt_url( $path );
		}

		// Letterboxd: letterboxd.com/film/slug
		if ( 'letterboxd.com' === $host ) {
			return $this->parse_letterboxd_url( $path, $url );
		}

		return null;
	}

	/**
	 * Parse IMDB URL and fetch metadata via TMDB.
	 *
	 * @param string $path URL path.
	 * @return array|null Movie/TV metadata.
	 * @throws \Exception If credentials are missing or API request fails.
	 */
	private function parse_imdb_url( string $path ): ?array {
		// Extract IMDB ID (tt followed by digits).
		if ( ! preg_match( '/\/title\/(tt\d+)/', $path, $matches ) ) {
			return null;
		}

		$imdb_id = $matches[1];

		// Get TMDB credentials.
		$credentials  = get_option( 'reactions_indieweb_api_credentials', array() );
		$tmdb_creds   = $credentials['tmdb'] ?? array();
		$access_token = $tmdb_creds['access_token'] ?? '';
		$api_key      = $tmdb_creds['api_key'] ?? '';
		$is_enabled   = ! empty( $tmdb_creds['enabled'] );

		// Check if TMDB is enabled.
		if ( ! $is_enabled ) {
			throw new \Exception( __( 'IMDB lookup requires TMDB to be enabled. Enable TMDB in Settings > API Connections.', 'reactions-for-indieweb' ) );
		}

		// IMDB lookup requires TMDB credentials.
		if ( ! $access_token && ! $api_key ) {
			throw new \Exception( __( 'IMDB lookup requires TMDB API credentials. Add your TMDB API Read Access Token in Settings > API Connections.', 'reactions-for-indieweb' ) );
		}

		// Build URL - use API key as query param if no access token.
		$url = 'https://api.themoviedb.org/3/find/' . $imdb_id . '?external_source=imdb_id';
		if ( ! $access_token && $api_key ) {
			$url .= '&api_key=' . $api_key;
		}

		// Build headers.
		$headers = array( 'Accept' => 'application/json' );
		if ( $access_token ) {
			$headers['Authorization'] = 'Bearer ' . $access_token;
		}

		// TMDB find endpoint searches by external ID.
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \Exception(
				sprintf(
					/* translators: %s: Error message */
					__( 'TMDB API request failed: %s', 'reactions-for-indieweb' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		// Check for HTTP errors.
		if ( $status_code >= 400 ) {
			$error_message = $data['status_message'] ?? __( 'Unknown API error', 'reactions-for-indieweb' );
			throw new \Exception(
				sprintf(
					/* translators: 1: HTTP status code, 2: Error message */
					__( 'TMDB API error (%1$d): %2$s', 'reactions-for-indieweb' ),
					$status_code,
					$error_message
				)
			);
		}

		// Check for API errors in response body.
		if ( isset( $data['success'] ) && false === $data['success'] ) {
			$error_message = $data['status_message'] ?? __( 'API returned an error', 'reactions-for-indieweb' );
			throw new \Exception(
				sprintf(
					/* translators: %s: Error message */
					__( 'TMDB error: %s', 'reactions-for-indieweb' ),
					$error_message
				)
			);
		}

		// Check movie results first.
		if ( ! empty( $data['movie_results'][0] ) ) {
			$movie = $data['movie_results'][0];
			return $this->normalize_tmdb_result( $movie, 'movie', $imdb_id );
		}

		// Then TV results.
		if ( ! empty( $data['tv_results'][0] ) ) {
			$tv = $data['tv_results'][0];
			return $this->normalize_tmdb_result( $tv, 'tv', $imdb_id );
		}

		// No results found for this IMDB ID.
		throw new \Exception(
			sprintf(
				/* translators: %s: IMDB ID */
				__( 'No movie or TV show found for IMDB ID: %s', 'reactions-for-indieweb' ),
				$imdb_id
			)
		);
	}

	/**
	 * Parse TMDB URL and fetch metadata.
	 *
	 * @param string $path URL path.
	 * @return array|null Movie/TV metadata.
	 */
	private function parse_tmdb_url( string $path ): ?array {
		$tmdb = new TMDB();

		// Movie: /movie/123 or /movie/123-title-slug
		if ( preg_match( '/\/movie\/(\d+)/', $path, $matches ) ) {
			$result = $tmdb->get_movie( (int) $matches[1] );
			if ( $result ) {
				return $this->normalize_watch_result( $result, 'tmdb' );
			}
		}

		// TV: /tv/456 or /tv/456-title-slug
		if ( preg_match( '/\/tv\/(\d+)/', $path, $matches ) ) {
			$result = $tmdb->get_tv( (int) $matches[1] );
			if ( $result ) {
				return $this->normalize_watch_result( $result, 'tmdb' );
			}
		}

		return null;
	}

	/**
	 * Parse Trakt URL and fetch metadata.
	 *
	 * @param string $path URL path.
	 * @return array|null Movie/TV metadata.
	 */
	private function parse_trakt_url( string $path ): ?array {
		$trakt = new Trakt();

		// Movie: /movies/slug-name or /movies/slug-name-2010
		if ( preg_match( '/\/movies\/([^\/]+)/', $path, $matches ) ) {
			$result = $trakt->get_movie( $matches[1] );
			if ( $result ) {
				return $this->normalize_watch_result( $result, 'trakt' );
			}
		}

		// TV Show: /shows/slug-name
		if ( preg_match( '/\/shows\/([^\/]+)/', $path, $matches ) ) {
			$result = $trakt->get_show( $matches[1] );
			if ( $result ) {
				return $this->normalize_watch_result( $result, 'trakt' );
			}
		}

		return null;
	}

	/**
	 * Parse Letterboxd URL and fetch metadata via TMDB.
	 *
	 * Letterboxd doesn't have a public API, so we scrape the page for TMDB ID.
	 *
	 * @param string $path URL path.
	 * @param string $url  Full URL.
	 * @return array|null Movie/TV metadata.
	 */
	private function parse_letterboxd_url( string $path, string $url ): ?array {
		// Film: /film/slug or /film/slug/
		if ( preg_match( '/\/film\/([^\/]+)/', $path, $matches ) ) {
			// Fetch the Letterboxd page and look for TMDB link.
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 15,
					'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
				)
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			$body = wp_remote_retrieve_body( $response );

			// Letterboxd pages contain data-tmdb-id attribute or link to TMDB.
			if ( preg_match( '/data-tmdb-id="(\d+)"/', $body, $tmdb_match ) ) {
				$tmdb   = new TMDB();
				$result = $tmdb->get_movie( (int) $tmdb_match[1] );
				if ( $result ) {
					$normalized              = $this->normalize_watch_result( $result, 'tmdb' );
					$normalized['letterboxd_url'] = $url;
					return $normalized;
				}
			}

			// Alternative: look for themoviedb.org link.
			if ( preg_match( '/href="https?:\/\/(?:www\.)?themoviedb\.org\/movie\/(\d+)"/', $body, $tmdb_match ) ) {
				$tmdb   = new TMDB();
				$result = $tmdb->get_movie( (int) $tmdb_match[1] );
				if ( $result ) {
					$normalized              = $this->normalize_watch_result( $result, 'tmdb' );
					$normalized['letterboxd_url'] = $url;
					return $normalized;
				}
			}

			// Fallback: Try to extract title from page and search TMDB.
			if ( preg_match( '/<meta property="og:title" content="([^"]+)"/', $body, $title_match ) ) {
				$title = html_entity_decode( $title_match[1], ENT_QUOTES, 'UTF-8' );
				// Remove year if present: "Movie Title (2023)"
				$title = preg_replace( '/\s*\(\d{4}\)\s*$/', '', $title );

				$tmdb    = new TMDB();
				$results = $tmdb->search_movies( $title );
				if ( ! empty( $results[0] ) ) {
					$normalized              = $this->normalize_watch_result( $results[0], 'tmdb' );
					$normalized['letterboxd_url'] = $url;
					return $normalized;
				}
			}
		}

		return null;
	}

	/**
	 * Get TMDB access token from stored credentials.
	 *
	 * @return string Access token or empty string.
	 */
	private function get_tmdb_access_token(): string {
		$credentials = get_option( 'reactions_indieweb_api_credentials', array() );
		return $credentials['tmdb']['access_token'] ?? $credentials['tmdb']['api_key'] ?? '';
	}

	/**
	 * Normalize TMDB find result.
	 *
	 * @param array  $item    Raw TMDB item.
	 * @param string $type    Content type (movie or tv).
	 * @param string $imdb_id IMDB ID.
	 * @return array Normalized result.
	 */
	private function normalize_tmdb_result( array $item, string $type, string $imdb_id ): array {
		$image_base = 'https://image.tmdb.org/t/p/w342';

		return array(
			'title'    => $item['title'] ?? $item['name'] ?? '',
			'year'     => substr( $item['release_date'] ?? $item['first_air_date'] ?? '', 0, 4 ),
			'poster'   => ! empty( $item['poster_path'] ) ? $image_base . $item['poster_path'] : '',
			'overview' => $item['overview'] ?? '',
			'type'     => $type,
			'tmdb_id'  => $item['id'] ?? 0,
			'imdb_id'  => $imdb_id,
			'provider' => 'tmdb',
		);
	}

	/**
	 * Normalize watch result from various APIs.
	 *
	 * @param array  $result   API result.
	 * @param string $provider Provider name.
	 * @return array Normalized result.
	 */
	private function normalize_watch_result( array $result, string $provider ): array {
		return array(
			'title'    => $result['title'] ?? '',
			'year'     => $result['year'] ?? '',
			'poster'   => $result['poster'] ?? '',
			'overview' => $result['overview'] ?? '',
			'type'     => $result['type'] ?? 'movie',
			'tmdb_id'  => $result['tmdb_id'] ?? 0,
			'imdb_id'  => $result['imdb_id'] ?? '',
			'trakt_id' => $result['trakt_id'] ?? 0,
			'provider' => $provider,
		);
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
	 * Look up game by name or ID.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lookup_game( \WP_REST_Request $request ) {
		$query  = $request->get_param( 'q' );
		$source = $request->get_param( 'source' );
		$type   = $request->get_param( 'type' );
		$id     = $request->get_param( 'id' );

		try {
			// Handle direct ID lookup.
			if ( ! empty( $id ) ) {
				if ( 'rawg' === $source ) {
					$api = new RAWG();
					if ( ! $api->is_configured() ) {
						return new \WP_Error(
							'api_not_configured',
							__( 'RAWG API is not configured. Add your API key in Settings > API Connections.', 'reactions-for-indieweb' ),
							array( 'status' => 400 )
						);
					}
					$result = $api->get_by_id( $id );
				} else {
					$api    = new BoardGameGeek();
					$result = $api->get_by_id( $id );
					if ( $result ) {
						$result = $api->normalize_result( $result );
					}
				}

				if ( ! $result ) {
					return new \WP_Error(
						'game_not_found',
						__( 'Game not found.', 'reactions-for-indieweb' ),
						array( 'status' => 404 )
					);
				}

				return rest_ensure_response( $result );
			}

			// Search for games.
			if ( 'rawg' === $source ) {
				$api = new RAWG();
				if ( ! $api->is_configured() ) {
					return new \WP_Error(
						'api_not_configured',
						__( 'RAWG API is not configured. Add your API key in Settings > API Connections.', 'reactions-for-indieweb' ),
						array( 'status' => 400 )
					);
				}
				$results = $api->search( $query );
			} else {
				$api     = new BoardGameGeek();
				$results = $api->search( $query, $type );

				// Normalize search results.
				$results = array_map(
					function( $item ) use ( $api ) {
						return array(
							'id'     => $item['id'],
							'title'  => $item['name'],
							'year'   => $item['year'],
							'type'   => $item['type'],
							'source' => 'bgg',
						);
					},
					$results
				);
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
					__( 'Provide either address or lat/lng coordinates.', 'reactions-for-indieweb' ),
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
				__( 'Please wait a moment before searching again.', 'reactions-for-indieweb' ),
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
				__( 'Invalid coordinates provided.', 'reactions-for-indieweb' ),
				array( 'status' => 400 )
			);
		}

		// Check throttle.
		$throttle_key = 'reactions_location_throttle_' . get_current_user_id();
		$last_request = get_transient( $throttle_key );

		if ( $last_request && ( time() - $last_request ) < 1 ) {
			return new \WP_Error(
				'rate_limited',
				__( 'Please wait a moment before searching again.', 'reactions-for-indieweb' ),
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
					__( 'No location found for these coordinates.', 'reactions-for-indieweb' ),
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
				__( 'Please wait a moment before searching again.', 'reactions-for-indieweb' ),
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
					__( 'Foursquare API is not configured. Please add your API key in Settings > APIs.', 'reactions-for-indieweb' ),
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
					__( 'Provide either a search query or lat/lon coordinates.', 'reactions-for-indieweb' ),
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
					__( 'Invalid content type.', 'reactions-for-indieweb' ),
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
					'message' => __( 'Import started.', 'reactions-for-indieweb' ),
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
				__( 'Import job not found.', 'reactions-for-indieweb' ),
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
				__( 'Could not cancel import.', 'reactions-for-indieweb' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'message' => __( 'Import cancelled.', 'reactions-for-indieweb' ),
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
				__( 'Invalid OAuth state. Please try again.', 'reactions-for-indieweb' ),
				array( 'status' => 400 )
			);
		}

		delete_transient( 'reactions_indieweb_oauth_state_' . $service );

		try {
			$api = $this->get_oauth_api( $service );

			if ( ! $api ) {
				throw new \Exception( __( 'Unknown service.', 'reactions-for-indieweb' ) );
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
				throw new \Exception( __( 'Unknown service.', 'reactions-for-indieweb' ) );
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
					'message' => __( 'Connection revoked.', 'reactions-for-indieweb' ),
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
				'message' => __( 'Settings saved.', 'reactions-for-indieweb' ),
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
				throw new \Exception( __( 'Unknown service.', 'reactions-for-indieweb' ) );
			}

			$result = $api->test_connection();

			return rest_ensure_response(
				array(
					'success' => $result,
					'message' => $result
						? __( 'Connection successful!', 'reactions-for-indieweb' )
						: __( 'Connection failed.', 'reactions-for-indieweb' ),
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
				'message' => __( 'Webhook secret regenerated.', 'reactions-for-indieweb' ),
			)
		);
	}

	// =========================================================================
	// Check-in Dashboard Callbacks
	// =========================================================================

	/**
	 * Get check-ins with filters.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_checkins( \WP_REST_Request $request ) {
		$page       = $request->get_param( 'page' ) ?? 1;
		$per_page   = $request->get_param( 'per_page' ) ?? 50;
		$year       = $request->get_param( 'year' );
		$venue_type = $request->get_param( 'venue_type' );
		$search     = $request->get_param( 'search' );

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'tax_query'      => array(
				array(
					'taxonomy' => 'kind',
					'field'    => 'slug',
					'terms'    => 'checkin',
				),
			),
			'meta_query'     => array(),
		);

		// Filter by year.
		if ( $year ) {
			$args['date_query'] = array(
				array(
					'year' => $year,
				),
			);
		}

		// Filter by venue type.
		if ( $venue_type ) {
			$args['meta_query'][] = array(
				'key'     => '_reactions_checkin_type',
				'value'   => $venue_type,
				'compare' => '=',
			);
		}

		// Search by venue name.
		if ( $search ) {
			$args['meta_query'][] = array(
				'key'     => '_reactions_checkin_name',
				'value'   => $search,
				'compare' => 'LIKE',
			);
		}

		$query    = new \WP_Query( $args );
		$checkins = array();

		foreach ( $query->posts as $post ) {
			$checkins[] = $this->format_checkin_for_response( $post );
		}

		return rest_ensure_response(
			array(
				'checkins'    => $checkins,
				'total'       => $query->found_posts,
				'total_pages' => $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Get check-in statistics.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_checkin_stats( \WP_REST_Request $request ) {
		$year = $request->get_param( 'year' );

		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'kind',
					'field'    => 'slug',
					'terms'    => 'checkin',
				),
			),
		);

		if ( $year ) {
			$args['date_query'] = array(
				array(
					'year' => $year,
				),
			);
		}

		$query = new \WP_Query( $args );

		$venues    = array();
		$countries = array();
		$cities    = array();
		$most_visited = array();

		foreach ( $query->posts as $post ) {
			$venue_name = get_post_meta( $post->ID, '_reactions_checkin_name', true );
			$locality   = get_post_meta( $post->ID, '_reactions_checkin_locality', true );
			$country    = get_post_meta( $post->ID, '_reactions_checkin_country', true );

			if ( $venue_name ) {
				$venues[ $venue_name ] = true;
				if ( ! isset( $most_visited[ $venue_name ] ) ) {
					$most_visited[ $venue_name ] = array(
						'name'  => $venue_name,
						'count' => 0,
						'locality' => $locality,
					);
				}
				$most_visited[ $venue_name ]['count']++;
			}

			if ( $locality ) {
				$cities[ $locality ] = true;
			}

			if ( $country ) {
				$countries[ $country ] = true;
			}
		}

		// Sort most visited by count.
		usort( $most_visited, function( $a, $b ) {
			return $b['count'] - $a['count'];
		} );

		return rest_ensure_response(
			array(
				'total_checkins' => $query->found_posts,
				'unique_venues'  => count( $venues ),
				'countries'      => count( $countries ),
				'cities'         => count( $cities ),
				'most_visited'   => array_slice( array_values( $most_visited ), 0, 10 ),
				'countries_list' => array_keys( $countries ),
				'cities_list'    => array_keys( $cities ),
			)
		);
	}

	/**
	 * Format a check-in post for REST response.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Formatted check-in data.
	 */
	private function format_checkin_for_response( \WP_Post $post ): array {
		$privacy = get_post_meta( $post->ID, '_reactions_geo_privacy', true ) ?: 'approximate';

		$data = array(
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'date'       => get_the_date( 'c', $post ),
			'permalink'  => get_permalink( $post ),
			'edit_link'  => get_edit_post_link( $post->ID, 'raw' ),
			'venue_name' => get_post_meta( $post->ID, '_reactions_checkin_name', true ),
			'venue_type' => get_post_meta( $post->ID, '_reactions_checkin_type', true ),
			'address'    => get_post_meta( $post->ID, '_reactions_checkin_address', true ),
			'locality'   => get_post_meta( $post->ID, '_reactions_checkin_locality', true ),
			'region'     => get_post_meta( $post->ID, '_reactions_checkin_region', true ),
			'country'    => get_post_meta( $post->ID, '_reactions_checkin_country', true ),
			'privacy'    => $privacy,
			'thumbnail'  => get_the_post_thumbnail_url( $post, 'thumbnail' ),
		);

		// Only include coordinates based on privacy setting.
		if ( 'public' === $privacy ) {
			$data['latitude']  = (float) get_post_meta( $post->ID, '_reactions_geo_latitude', true );
			$data['longitude'] = (float) get_post_meta( $post->ID, '_reactions_geo_longitude', true );
		} elseif ( 'approximate' === $privacy ) {
			// For approximate, we could add city-level coords if needed.
			$data['latitude']  = null;
			$data['longitude'] = null;
		}

		return $data;
	}
}

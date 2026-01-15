<?php
/**
 * Webhook Handler
 *
 * Handles incoming webhooks from external services (Plex, Jellyfin, Trakt, etc).
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook Handler class.
 *
 * @since 1.0.0
 */
class Webhook_Handler {

	/**
	 * Webhook endpoints.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $endpoints = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_endpoints();
	}

	/**
	 * Register webhook endpoints.
	 *
	 * @return void
	 */
	private function register_endpoints(): void {
		$this->endpoints = [
			'plex'         => [
				'name'         => 'Plex',
				'description'  => 'Receive playback events from Plex Media Server',
				'handler'      => [ $this, 'handle_plex' ],
				'auth_type'    => 'token',
				'content_type' => 'multipart/form-data',
			],
			'jellyfin'     => [
				'name'         => 'Jellyfin',
				'description'  => 'Receive playback events from Jellyfin',
				'handler'      => [ $this, 'handle_jellyfin' ],
				'auth_type'    => 'token',
				'content_type' => 'application/json',
			],
			'trakt'        => [
				'name'         => 'Trakt',
				'description'  => 'Receive scrobble events from Trakt',
				'handler'      => [ $this, 'handle_trakt' ],
				'auth_type'    => 'none',
				'content_type' => 'application/json',
			],
			'listenbrainz' => [
				'name'         => 'ListenBrainz',
				'description'  => 'Receive listen submissions from ListenBrainz',
				'handler'      => [ $this, 'handle_listenbrainz' ],
				'auth_type'    => 'token',
				'content_type' => 'application/json',
			],
			'generic'      => [
				'name'         => 'Generic',
				'description'  => 'Generic webhook endpoint for custom integrations',
				'handler'      => [ $this, 'handle_generic' ],
				'auth_type'    => 'token',
				'content_type' => 'application/json',
			],
		];

		/**
		 * Filter available webhook endpoints.
		 *
		 * @param array<string, array<string, mixed>> $endpoints Webhook endpoints.
		 */
		$this->endpoints = apply_filters( 'post_kinds_indieweb_webhook_endpoints', $this->endpoints );
	}

	/**
	 * Get available endpoints.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_endpoints(): array {
		return $this->endpoints;
	}

	/**
	 * Handle incoming webhook request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $service Service identifier.
	 * @return \WP_REST_Response|\WP_Error Response.
	 */
	public function handle_request( \WP_REST_Request $request, string $service ) {
		if ( ! isset( $this->endpoints[ $service ] ) ) {
			return new \WP_Error(
				'unknown_service',
				'Unknown webhook service: ' . $service,
				[ 'status' => 404 ]
			);
		}

		$endpoint = $this->endpoints[ $service ];

		// Authenticate.
		$auth_result = $this->authenticate( $request, $service, $endpoint );

		if ( is_wp_error( $auth_result ) ) {
			$this->log_webhook( $service, 'auth_failed', $auth_result->get_error_message() );
			return $auth_result;
		}

		// Parse payload.
		$payload = $this->parse_payload( $request, $endpoint );

		if ( is_wp_error( $payload ) ) {
			$this->log_webhook( $service, 'parse_failed', $payload->get_error_message() );
			return $payload;
		}

		// Call handler.
		try {
			$handler = $endpoint['handler'];
			$result  = call_user_func( $handler, $payload, $request );

			$this->log_webhook( $service, 'success', $result );

			return new \WP_REST_Response(
				[
					'success' => true,
					'message' => 'Webhook processed',
					'data'    => $result,
				],
				200
			);
		} catch ( \Exception $e ) {
			$this->log_webhook( $service, 'error', $e->getMessage() );

			return new \WP_Error(
				'handler_error',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Authenticate webhook request.
	 *
	 * @param \WP_REST_Request     $request  Request.
	 * @param string               $service  Service name.
	 * @param array<string, mixed> $endpoint Endpoint config.
	 * @return true|\WP_Error
	 */
	private function authenticate( \WP_REST_Request $request, string $service, array $endpoint ) {
		$auth_type = $endpoint['auth_type'] ?? 'token';

		switch ( $auth_type ) {
			case 'none':
				return true;

			case 'token':
				return $this->validate_token( $request, $service );

			case 'hmac':
				return $this->validate_hmac( $request, $service );

			case 'basic':
				return $this->validate_basic_auth( $request, $service );

			default:
				return true;
		}
	}

	/**
	 * Validate token authentication.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $service Service name.
	 * @return true|\WP_Error
	 */
	private function validate_token( \WP_REST_Request $request, string $service ) {
		$expected_token = get_option( "post_kinds_webhook_token_{$service}" );

		if ( ! $expected_token ) {
			// No token configured, generate one.
			$expected_token = wp_generate_password( 32, false );
			update_option( "post_kinds_webhook_token_{$service}", $expected_token );
		}

		// Check various token locations.
		$token = $request->get_header( 'X-Webhook-Token' );

		if ( ! $token ) {
			$token = $request->get_header( 'Authorization' );
			if ( $token && strpos( $token, 'Bearer ' ) === 0 ) {
				$token = substr( $token, 7 );
			}
		}

		if ( ! $token ) {
			$token = $request->get_param( 'token' );
		}

		if ( ! $token ) {
			return new \WP_Error(
				'missing_token',
				'Webhook token required',
				[ 'status' => 401 ]
			);
		}

		if ( ! hash_equals( $expected_token, $token ) ) {
			return new \WP_Error(
				'invalid_token',
				'Invalid webhook token',
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Validate HMAC signature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $service Service name.
	 * @return true|\WP_Error
	 */
	private function validate_hmac( \WP_REST_Request $request, string $service ) {
		$secret = get_option( "post_kinds_webhook_secret_{$service}" );

		if ( ! $secret ) {
			return new \WP_Error(
				'no_secret',
				'HMAC secret not configured',
				[ 'status' => 500 ]
			);
		}

		$signature = $request->get_header( 'X-Hub-Signature-256' );

		if ( ! $signature ) {
			$signature = $request->get_header( 'X-Signature' );
		}

		if ( ! $signature ) {
			return new \WP_Error(
				'missing_signature',
				'HMAC signature required',
				[ 'status' => 401 ]
			);
		}

		$body     = $request->get_body();
		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error(
				'invalid_signature',
				'Invalid HMAC signature',
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Validate basic authentication.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $service Service name.
	 * @return true|\WP_Error
	 */
	private function validate_basic_auth( \WP_REST_Request $request, string $service ) {
		$auth_header = $request->get_header( 'Authorization' );

		if ( ! $auth_header || strpos( $auth_header, 'Basic ' ) !== 0 ) {
			return new \WP_Error(
				'missing_auth',
				'Basic authentication required',
				[ 'status' => 401 ]
			);
		}

		$credentials                 = base64_decode( substr( $auth_header, 6 ) );
		list( $username, $password ) = explode( ':', $credentials, 2 );

		$expected_user = get_option( "post_kinds_webhook_user_{$service}" );
		$expected_pass = get_option( "post_kinds_webhook_pass_{$service}" );

		if ( ! hash_equals( $expected_user, $username ) || ! hash_equals( $expected_pass, $password ) ) {
			return new \WP_Error(
				'invalid_credentials',
				'Invalid credentials',
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Parse webhook payload.
	 *
	 * @param \WP_REST_Request     $request  Request.
	 * @param array<string, mixed> $endpoint Endpoint config.
	 * @return array<string, mixed>|\WP_Error Parsed payload.
	 */
	private function parse_payload( \WP_REST_Request $request, array $endpoint ) {
		$content_type = $endpoint['content_type'] ?? 'application/json';

		if ( strpos( $content_type, 'multipart/form-data' ) !== false ) {
			// Handle multipart (Plex sends this).
			$payload_json = $request->get_param( 'payload' );

			if ( $payload_json ) {
				$payload = json_decode( $payload_json, true );

				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new \WP_Error(
						'invalid_json',
						'Invalid JSON in payload parameter',
						[ 'status' => 400 ]
					);
				}

				return $payload;
			}

			// Return all params.
			return $request->get_params();
		}

		// JSON payload.
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return $request->get_params();
		}

		$payload = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'invalid_json',
				'Invalid JSON payload: ' . json_last_error_msg(),
				[ 'status' => 400 ]
			);
		}

		return $payload;
	}

	/**
	 * Handle Plex webhook.
	 *
	 * @param array<string, mixed> $payload Payload data.
	 * @param \WP_REST_Request     $request Request.
	 * @return array<string, mixed> Result.
	 */
	public function handle_plex( array $payload, \WP_REST_Request $request ): array {
		$event    = $payload['event'] ?? '';
		$metadata = $payload['Metadata'] ?? [];
		$account  = $payload['Account'] ?? [];
		$player   = $payload['Player'] ?? [];

		// Only process completed plays.
		if ( 'media.scrobble' !== $event ) {
			return [
				'action'  => 'ignored',
				'event'   => $event,
				'message' => 'Event type not processed',
			];
		}

		$type = $metadata['type'] ?? '';

		// Build item data.
		$item = [
			'source'     => 'plex',
			'plex_key'   => $metadata['key'] ?? '',
			'watched_at' => time(),
			'user'       => $account['title'] ?? '',
			'player'     => $player['title'] ?? '',
		];

		if ( 'movie' === $type ) {
			$item['type']   = 'movie';
			$item['title']  = $metadata['title'] ?? '';
			$item['year']   = $metadata['year'] ?? '';
			$item['poster'] = $this->get_plex_thumb( $metadata['thumb'] ?? '' );

			// Try to get external IDs.
			foreach ( $metadata['Guid'] ?? [] as $guid ) {
				$id = $guid['id'] ?? '';
				if ( strpos( $id, 'imdb://' ) === 0 ) {
					$item['imdb_id'] = substr( $id, 7 );
				} elseif ( strpos( $id, 'tmdb://' ) === 0 ) {
					$item['tmdb_id'] = (int) substr( $id, 7 );
				}
			}
		} elseif ( 'episode' === $type ) {
			$item['type']    = 'episode';
			$item['title']   = $metadata['title'] ?? '';
			$item['show']    = $metadata['grandparentTitle'] ?? '';
			$item['season']  = $metadata['parentIndex'] ?? 0;
			$item['episode'] = $metadata['index'] ?? 0;
			$item['poster']  = $this->get_plex_thumb( $metadata['grandparentThumb'] ?? '' );

			foreach ( $metadata['Guid'] ?? [] as $guid ) {
				$id = $guid['id'] ?? '';
				if ( strpos( $id, 'tvdb://' ) === 0 ) {
					$item['tvdb_id'] = (int) substr( $id, 7 );
				} elseif ( strpos( $id, 'tmdb://' ) === 0 ) {
					$item['tmdb_id'] = (int) substr( $id, 7 );
				}
			}
		} elseif ( 'track' === $type ) {
			$item['type']   = 'track';
			$item['track']  = $metadata['title'] ?? '';
			$item['artist'] = $metadata['grandparentTitle'] ?? '';
			$item['album']  = $metadata['parentTitle'] ?? '';
			$item['cover']  = $this->get_plex_thumb( $metadata['parentThumb'] ?? '' );
		} else {
			return [
				'action'  => 'ignored',
				'type'    => $type,
				'message' => 'Media type not supported',
			];
		}

		// Process the scrobble.
		return $this->process_scrobble( $item );
	}

	/**
	 * Handle Jellyfin webhook.
	 *
	 * @param array<string, mixed> $payload Payload data.
	 * @param \WP_REST_Request     $request Request.
	 * @return array<string, mixed> Result.
	 */
	public function handle_jellyfin( array $payload, \WP_REST_Request $request ): array {
		$notification_type = $payload['NotificationType'] ?? '';

		// Only process completed plays.
		if ( 'PlaybackStop' !== $notification_type ) {
			return [
				'action'  => 'ignored',
				'event'   => $notification_type,
				'message' => 'Event type not processed',
			];
		}

		// Check if actually completed (played > 90%).
		$played_percent = $payload['PlayedToCompletion'] ?? false;
		if ( ! $played_percent ) {
			return [
				'action'  => 'ignored',
				'message' => 'Playback not completed',
			];
		}

		$item_type = $payload['ItemType'] ?? '';

		$item = [
			'source'      => 'jellyfin',
			'jellyfin_id' => $payload['ItemId'] ?? '',
			'watched_at'  => time(),
			'user'        => $payload['NotificationUsername'] ?? '',
			'device'      => $payload['DeviceName'] ?? '',
		];

		if ( 'Movie' === $item_type ) {
			$item['type']    = 'movie';
			$item['title']   = $payload['Name'] ?? '';
			$item['year']    = $payload['Year'] ?? '';
			$item['imdb_id'] = $payload['Provider_imdb'] ?? '';
			$item['tmdb_id'] = $payload['Provider_tmdb'] ?? '';
		} elseif ( 'Episode' === $item_type ) {
			$item['type']    = 'episode';
			$item['title']   = $payload['Name'] ?? '';
			$item['show']    = $payload['SeriesName'] ?? '';
			$item['season']  = $payload['SeasonNumber'] ?? 0;
			$item['episode'] = $payload['EpisodeNumber'] ?? 0;
			$item['tvdb_id'] = $payload['Provider_tvdb'] ?? '';
		} elseif ( 'Audio' === $item_type ) {
			$item['type']   = 'track';
			$item['track']  = $payload['Name'] ?? '';
			$item['artist'] = $payload['Artists'][0] ?? '';
			$item['album']  = $payload['Album'] ?? '';
		} else {
			return [
				'action'  => 'ignored',
				'type'    => $item_type,
				'message' => 'Item type not supported',
			];
		}

		return $this->process_scrobble( $item );
	}

	/**
	 * Handle Trakt webhook.
	 *
	 * @param array<string, mixed> $payload Payload data.
	 * @param \WP_REST_Request     $request Request.
	 * @return array<string, mixed> Result.
	 */
	public function handle_trakt( array $payload, \WP_REST_Request $request ): array {
		$action = $payload['action'] ?? '';

		if ( 'scrobble' !== $action && 'watch' !== $action ) {
			return [
				'action'  => 'ignored',
				'event'   => $action,
				'message' => 'Action type not processed',
			];
		}

		$item = [
			'source'     => 'trakt',
			'watched_at' => $payload['watched_at'] ?? time(),
		];

		if ( isset( $payload['movie'] ) ) {
			$movie            = $payload['movie'];
			$item['type']     = 'movie';
			$item['title']    = $movie['title'] ?? '';
			$item['year']     = $movie['year'] ?? '';
			$item['trakt_id'] = $movie['ids']['trakt'] ?? '';
			$item['imdb_id']  = $movie['ids']['imdb'] ?? '';
			$item['tmdb_id']  = $movie['ids']['tmdb'] ?? '';
		} elseif ( isset( $payload['episode'] ) ) {
			$episode = $payload['episode'];
			$show    = $payload['show'] ?? [];

			$item['type']     = 'episode';
			$item['title']    = $episode['title'] ?? '';
			$item['show']     = $show['title'] ?? '';
			$item['season']   = $episode['season'] ?? 0;
			$item['episode']  = $episode['number'] ?? 0;
			$item['trakt_id'] = $episode['ids']['trakt'] ?? '';
			$item['tvdb_id']  = $episode['ids']['tvdb'] ?? '';
		} else {
			return [
				'action'  => 'ignored',
				'message' => 'No movie or episode in payload',
			];
		}

		return $this->process_scrobble( $item );
	}

	/**
	 * Handle ListenBrainz webhook.
	 *
	 * @param array<string, mixed> $payload Payload data.
	 * @param \WP_REST_Request     $request Request.
	 * @return array<string, mixed> Result.
	 */
	public function handle_listenbrainz( array $payload, \WP_REST_Request $request ): array {
		$listen_type = $payload['listen_type'] ?? '';

		if ( ! in_array( $listen_type, [ 'single', 'playing_now' ], true ) ) {
			return [
				'action'  => 'ignored',
				'type'    => $listen_type,
				'message' => 'Listen type not processed',
			];
		}

		$listens = $payload['payload'] ?? [];

		if ( empty( $listens ) ) {
			return [
				'action'  => 'ignored',
				'message' => 'No listens in payload',
			];
		}

		$results = [];

		foreach ( $listens as $listen ) {
			$metadata   = $listen['track_metadata'] ?? [];
			$additional = $metadata['additional_info'] ?? [];

			$item = [
				'source'      => 'listenbrainz',
				'type'        => 'track',
				'track'       => $metadata['track_name'] ?? '',
				'artist'      => $metadata['artist_name'] ?? '',
				'album'       => $metadata['release_name'] ?? '',
				'listened_at' => $listen['listened_at'] ?? time(),
				'mbid'        => $additional['recording_mbid'] ?? '',
				'artist_mbid' => $additional['artist_mbids'][0] ?? '',
				'album_mbid'  => $additional['release_mbid'] ?? '',
				'duration'    => isset( $additional['duration_ms'] ) ? (int) ( $additional['duration_ms'] / 1000 ) : null,
			];

			$results[] = $this->process_scrobble( $item );
		}

		return [
			'action'  => 'processed',
			'count'   => count( $results ),
			'results' => $results,
		];
	}

	/**
	 * Handle generic webhook.
	 *
	 * @param array<string, mixed> $payload Payload data.
	 * @param \WP_REST_Request     $request Request.
	 * @return array<string, mixed> Result.
	 */
	public function handle_generic( array $payload, \WP_REST_Request $request ): array {
		/**
		 * Filter generic webhook payload.
		 *
		 * @param array<string, mixed> $payload Payload data.
		 * @param \WP_REST_Request     $request Request.
		 */
		$processed = apply_filters( 'post_kinds_indieweb_generic_webhook', $payload, $request );

		if ( is_array( $processed ) && isset( $processed['handled'] ) && $processed['handled'] ) {
			return $processed;
		}

		// Default: just store the payload.
		$this->store_raw_webhook( 'generic', $payload );

		return [
			'action'  => 'stored',
			'message' => 'Webhook payload stored for manual processing',
		];
	}

	/**
	 * Process a scrobble/watch into a post.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return array<string, mixed> Result.
	 */
	private function process_scrobble( array $item ): array {
		$auto_post = get_option( 'post_kinds_webhook_auto_post', false );

		if ( ! $auto_post ) {
			// Store for later.
			$this->store_pending_scrobble( $item );

			return [
				'action'  => 'queued',
				'type'    => $item['type'] ?? 'unknown',
				'title'   => $item['title'] ?? $item['track'] ?? '',
				'message' => 'Scrobble queued for review',
			];
		}

		// Create post immediately.
		$post_id = $this->create_scrobble_post( $item );

		if ( is_wp_error( $post_id ) ) {
			return [
				'action' => 'error',
				'error'  => $post_id->get_error_message(),
			];
		}

		return [
			'action'  => 'created',
			'post_id' => $post_id,
			'type'    => $item['type'] ?? 'unknown',
			'title'   => $item['title'] ?? $item['track'] ?? '',
		];
	}

	/**
	 * Create a post from a scrobble.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return int|\WP_Error Post ID or error.
	 */
	private function create_scrobble_post( array $item ) {
		$type = $item['type'] ?? '';

		$post_data = [
			'post_type'   => 'post',
			'post_status' => 'publish',
			'post_author' => get_option( 'post_kinds_default_author', 1 ),
		];

		$meta = [];
		$kind = '';

		switch ( $type ) {
			case 'movie':
				$kind                      = 'watch';
				$post_data['post_title']   = sprintf( 'Watched %s', $item['title'] );
				$post_data['post_content'] = sprintf( '<!-- wp:paragraph --><p>Watched "%s" (%s).</p><!-- /wp:paragraph -->', esc_html( $item['title'] ), esc_html( $item['year'] ?? '' ) );

				$meta['_postkind_watch_title']  = $item['title'];
				$meta['_postkind_watch_type']   = 'movie';
				$meta['_postkind_watch_year']   = $item['year'] ?? '';
				$meta['_postkind_watch_poster'] = $item['poster'] ?? '';
				$meta['_postkind_watch_tmdb']   = $item['tmdb_id'] ?? '';
				$meta['_postkind_watch_imdb']   = $item['imdb_id'] ?? '';
				break;

			case 'episode':
				$kind                      = 'watch';
				$episode_title             = sprintf( 'S%02dE%02d', $item['season'] ?? 0, $item['episode'] ?? 0 );
				$post_data['post_title']   = sprintf( 'Watched %s %s', $item['show'], $episode_title );
				$post_data['post_content'] = sprintf( '<!-- wp:paragraph --><p>Watched %s "%s".</p><!-- /wp:paragraph -->', esc_html( $item['show'] ), esc_html( $item['title'] ) );

				$meta['_postkind_watch_title']   = $item['title'];
				$meta['_postkind_watch_type']    = 'episode';
				$meta['_postkind_watch_show']    = $item['show'];
				$meta['_postkind_watch_season']  = $item['season'] ?? '';
				$meta['_postkind_watch_episode'] = $item['episode'] ?? '';
				$meta['_postkind_watch_poster']  = $item['poster'] ?? '';
				$meta['_postkind_watch_tvdb']    = $item['tvdb_id'] ?? '';
				break;

			case 'track':
				$kind                      = 'listen';
				$post_data['post_title']   = sprintf( 'Listened to %s', $item['track'] );
				$post_data['post_content'] = sprintf( '<!-- wp:paragraph --><p>Listened to "%s" by %s.</p><!-- /wp:paragraph -->', esc_html( $item['track'] ), esc_html( $item['artist'] ) );

				$meta['_postkind_listen_track']  = $item['track'];
				$meta['_postkind_listen_artist'] = $item['artist'];
				$meta['_postkind_listen_album']  = $item['album'] ?? '';
				$meta['_postkind_listen_cover']  = $item['cover'] ?? '';
				$meta['_postkind_listen_mbid']   = $item['mbid'] ?? '';
				break;

			default:
				return new \WP_Error( 'unknown_type', 'Unknown scrobble type: ' . $type );
		}

		// Set post date.
		$timestamp = $item['watched_at'] ?? $item['listened_at'] ?? time();
		if ( is_string( $timestamp ) ) {
			$timestamp = strtotime( $timestamp );
		}
		$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
		$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );

		// Create post.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set kind.
		if ( $kind ) {
			wp_set_object_terms( $post_id, $kind, 'kind' );
		}

		// Save meta.
		foreach ( $meta as $key => $value ) {
			if ( ! empty( $value ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		// Mark source.
		update_post_meta( $post_id, '_postkind_webhook_source', $item['source'] ?? 'unknown' );
		update_post_meta( $post_id, '_postkind_created_at', time() );

		return $post_id;
	}

	/**
	 * Store a pending scrobble for review.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return void
	 */
	private function store_pending_scrobble( array $item ): void {
		$pending             = get_option( 'post_kinds_pending_scrobbles', [] );
		$item['received_at'] = time();
		$pending[]           = $item;

		// Keep only last 100.
		$pending = array_slice( $pending, -100 );

		update_option( 'post_kinds_pending_scrobbles', $pending, false );
	}

	/**
	 * Store raw webhook data.
	 *
	 * @param string               $service Service name.
	 * @param array<string, mixed> $payload Payload data.
	 * @return void
	 */
	private function store_raw_webhook( string $service, array $payload ): void {
		$stored = get_option( 'post_kinds_raw_webhooks', [] );

		$stored[] = [
			'service'     => $service,
			'payload'     => $payload,
			'received_at' => time(),
		];

		// Keep only last 50.
		$stored = array_slice( $stored, -50 );

		update_option( 'post_kinds_raw_webhooks', $stored, false );
	}

	/**
	 * Get Plex thumbnail URL.
	 *
	 * @param string $thumb Thumbnail path.
	 * @return string|null Full URL or null.
	 */
	private function get_plex_thumb( string $thumb ): ?string {
		if ( empty( $thumb ) ) {
			return null;
		}

		$plex_url   = get_option( 'post_kinds_plex_url' );
		$plex_token = get_option( 'post_kinds_plex_token' );

		if ( ! $plex_url || ! $plex_token ) {
			return null;
		}

		return rtrim( $plex_url, '/' ) . $thumb . '?X-Plex-Token=' . $plex_token;
	}

	/**
	 * Log webhook activity.
	 *
	 * @param string $service Service name.
	 * @param string $status  Status.
	 * @param mixed  $data    Additional data.
	 * @return void
	 */
	private function log_webhook( string $service, string $status, $data = null ): void {
		if ( ! WP_DEBUG ) {
			return;
		}

		$log = get_option( 'post_kinds_webhook_log', [] );

		$log[] = [
			'service'   => $service,
			'status'    => $status,
			'data'      => is_array( $data ) ? $data : [ 'message' => $data ],
			'timestamp' => time(),
		];

		// Keep only last 100 entries.
		$log = array_slice( $log, -100 );

		update_option( 'post_kinds_webhook_log', $log, false );
	}

	/**
	 * Get webhook log.
	 *
	 * @param int $limit Max entries.
	 * @return array<int, array<string, mixed>> Log entries.
	 */
	public function get_log( int $limit = 50 ): array {
		$log = get_option( 'post_kinds_webhook_log', [] );
		return array_slice( array_reverse( $log ), 0, $limit );
	}

	/**
	 * Get pending scrobbles.
	 *
	 * @return array<int, array<string, mixed>> Pending scrobbles.
	 */
	public function get_pending_scrobbles(): array {
		return get_option( 'post_kinds_pending_scrobbles', [] );
	}

	/**
	 * Approve a pending scrobble.
	 *
	 * @param int $index Scrobble index.
	 * @return int|\WP_Error Post ID or error.
	 */
	public function approve_scrobble( int $index ) {
		$pending = $this->get_pending_scrobbles();

		if ( ! isset( $pending[ $index ] ) ) {
			return new \WP_Error( 'not_found', 'Scrobble not found' );
		}

		$item = $pending[ $index ];

		// Create post.
		$post_id = $this->create_scrobble_post( $item );

		if ( ! is_wp_error( $post_id ) ) {
			// Remove from pending.
			unset( $pending[ $index ] );
			$pending = array_values( $pending );
			update_option( 'post_kinds_pending_scrobbles', $pending, false );
		}

		return $post_id;
	}

	/**
	 * Reject a pending scrobble.
	 *
	 * @param int $index Scrobble index.
	 * @return bool Success.
	 */
	public function reject_scrobble( int $index ): bool {
		$pending = $this->get_pending_scrobbles();

		if ( ! isset( $pending[ $index ] ) ) {
			return false;
		}

		unset( $pending[ $index ] );
		$pending = array_values( $pending );
		update_option( 'post_kinds_pending_scrobbles', $pending, false );

		return true;
	}

	/**
	 * Generate a webhook token.
	 *
	 * @param string $service Service name.
	 * @return string New token.
	 */
	public function generate_token( string $service ): string {
		$token = wp_generate_password( 32, false );
		update_option( "post_kinds_webhook_token_{$service}", $token );
		return $token;
	}

	/**
	 * Get webhook URL for a service.
	 *
	 * @param string $service Service name.
	 * @return string Webhook URL.
	 */
	public function get_webhook_url( string $service ): string {
		return rest_url( "post-kinds-indieweb/v1/webhook/{$service}" );
	}
}

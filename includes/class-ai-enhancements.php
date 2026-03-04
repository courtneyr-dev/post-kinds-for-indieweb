<?php
/**
 * AI Enhancements (WordPress 7.0+ WP AI Client)
 *
 * Optional AI-powered features using the WordPress AI Client API.
 * Double-gated: requires both wp_ai_client_prompt() and pk_enable_ai setting.
 *
 * Features:
 * - Auto-Populate from URL: Extract title, summary, author from URLs.
 * - Smart Tagging: Suggest tags based on content and metadata.
 * - Content Summary: Generate review prompts from media metadata.
 *
 * @package PostKindsForIndieWeb
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Enhancements using WP AI Client.
 *
 * @since 1.3.0
 */
final class AI_Enhancements {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	private const RATE_LIMIT_WINDOW = 5;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'post-kinds/v1';

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.3.0
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Check if AI enhancements are available and enabled.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True if both wp_ai_client_prompt exists and pk_enable_ai is true.
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' )
			&& (bool) get_option( 'pk_enable_ai', false );
	}

	/**
	 * Initialize the AI enhancements.
	 *
	 * @since 1.3.0
	 */
	private function __construct() {
		if ( ! self::is_available() ) {
			return;
		}

		add_action( 'init', [ $this, 'register_setting' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the AI enable/disable setting.
	 *
	 * @since 1.3.0
	 */
	public function register_setting(): void {
		register_setting(
			'pk_settings',
			'pk_enable_ai',
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
				'description'       => __( 'Enable AI-powered enhancements for post kind editing.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.3.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/auto-populate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_auto_populate' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => [
					'url' => [
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/suggest-tags',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_suggest_tags' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/content-summary',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_content_summary' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => [
					'post_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Handle auto-populate from URL request.
	 *
	 * Extracts title, summary, and author from a given URL.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function handle_auto_populate( \WP_REST_Request $request ) {
		$rate_check = $this->check_rate_limit( 'auto_populate' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$url = $request->get_param( 'url' );

		$prompt = sprintf(
			'Extract the following from this URL: %s' . "\n\n"
			. 'Return a JSON object with these fields:' . "\n"
			. '- title: The page or article title' . "\n"
			. '- summary: A 1-2 sentence summary of the content' . "\n"
			. '- author: The author name if available, or empty string' . "\n\n"
			. 'Return only valid JSON, no other text.',
			$url
		);

		$result = $this->ai_request( $prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = json_decode( $result, true );

		if ( ! is_array( $parsed ) ) {
			return new \WP_Error(
				'pk_ai_parse_error',
				__( 'Failed to parse AI response.', 'post-kinds-for-indieweb' ),
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'title'   => sanitize_text_field( $parsed['title'] ?? '' ),
				'summary' => sanitize_textarea_field( $parsed['summary'] ?? '' ),
				'author'  => sanitize_text_field( $parsed['author'] ?? '' ),
			]
		);
	}

	/**
	 * Handle tag suggestion request.
	 *
	 * Suggests tags based on post content and metadata.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function handle_suggest_tags( \WP_REST_Request $request ) {
		$rate_check = $this->check_rate_limit( 'suggest_tags' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post_id = $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'pk_post_not_found',
				__( 'Post not found.', 'post-kinds-for-indieweb' ),
				[ 'status' => 404 ]
			);
		}

		$kind    = Taxonomy::get_post_kind( $post_id );
		$prefix  = Meta_Fields::PREFIX;
		$context = $this->build_post_context( $post_id, $kind, $prefix );

		$prompt = sprintf(
			'Based on this %s post, suggest 3-5 relevant tags.' . "\n\n"
			. 'Post content: %s' . "\n\n"
			. 'Metadata: %s' . "\n\n"
			. 'Return a JSON array of tag strings. Return only valid JSON, no other text.',
			$kind ? $kind : 'blog',
			wp_strip_all_tags( $post->post_content ),
			wp_json_encode( $context )
		);

		$result = $this->ai_request( $prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tags = json_decode( $result, true );

		if ( ! is_array( $tags ) ) {
			return new \WP_Error(
				'pk_ai_parse_error',
				__( 'Failed to parse AI response.', 'post-kinds-for-indieweb' ),
				[ 'status' => 500 ]
			);
		}

		$tags = array_map( 'sanitize_text_field', $tags );
		$tags = array_slice( $tags, 0, 10 );

		return rest_ensure_response( [ 'tags' => $tags ] );
	}

	/**
	 * Handle content summary request.
	 *
	 * Generates review prompts from media metadata for read/watch posts.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error Response or error.
	 */
	public function handle_content_summary( \WP_REST_Request $request ) {
		$rate_check = $this->check_rate_limit( 'content_summary' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post_id = $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'pk_post_not_found',
				__( 'Post not found.', 'post-kinds-for-indieweb' ),
				[ 'status' => 404 ]
			);
		}

		$kind   = Taxonomy::get_post_kind( $post_id );
		$prefix = Meta_Fields::PREFIX;

		if ( ! in_array( $kind, [ 'read', 'watch', 'listen' ], true ) ) {
			return new \WP_Error(
				'pk_invalid_kind',
				__( 'Content summaries are only available for read, watch, and listen posts.', 'post-kinds-for-indieweb' ),
				[ 'status' => 400 ]
			);
		}

		$context = $this->build_post_context( $post_id, $kind, $prefix );

		$prompt = sprintf(
			'This is a %s post about: %s' . "\n\n"
			. 'Generate 3 short review prompts to help the user write about this. '
			. 'Each prompt should be a question or sentence starter that encourages personal reflection.' . "\n\n"
			. 'Return a JSON array of prompt strings. Return only valid JSON, no other text.',
			$kind,
			wp_json_encode( $context )
		);

		$result = $this->ai_request( $prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$prompts = json_decode( $result, true );

		if ( ! is_array( $prompts ) ) {
			return new \WP_Error(
				'pk_ai_parse_error',
				__( 'Failed to parse AI response.', 'post-kinds-for-indieweb' ),
				[ 'status' => 500 ]
			);
		}

		$prompts = array_map( 'sanitize_text_field', $prompts );
		$prompts = array_slice( $prompts, 0, 5 );

		return rest_ensure_response( [ 'prompts' => $prompts ] );
	}

	/**
	 * Make an AI request via the WP AI Client API.
	 *
	 * @since 1.3.0
	 *
	 * @param string $prompt The prompt to send.
	 * @return string|\WP_Error The AI response text or error.
	 */
	private function ai_request( string $prompt ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error(
				'pk_ai_unavailable',
				__( 'WP AI Client is not available.', 'post-kinds-for-indieweb' ),
				[ 'status' => 503 ]
			);
		}

		try {
			$client   = wp_ai_client_prompt();
			$response = $client->generate_text( $prompt );

			if ( is_wp_error( $response ) ) {
				$this->log_error( 'AI request failed', $response->get_error_message() );
				return $response;
			}

			return (string) $response;
		} catch ( \Exception $e ) {
			$this->log_error( 'AI request exception', $e->getMessage() );

			return new \WP_Error(
				'pk_ai_exception',
				__( 'AI request failed.', 'post-kinds-for-indieweb' ),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Check rate limit for a specific action.
	 *
	 * @since 1.3.0
	 *
	 * @param string $action The action identifier.
	 * @return true|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	private function check_rate_limit( string $action ) {
		$user_id       = get_current_user_id();
		$transient_key = 'pk_ai_rate_' . $action . '_' . $user_id;

		if ( get_transient( $transient_key ) ) {
			return new \WP_Error(
				'pk_rate_limited',
				__( 'Please wait a few seconds before making another AI request.', 'post-kinds-for-indieweb' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $transient_key, 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Build context array from post metadata for AI prompts.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind    Post kind slug.
	 * @param string $prefix  Meta key prefix.
	 * @return array<string, string> Context key-value pairs.
	 */
	private function build_post_context( int $post_id, string $kind, string $prefix ): array {
		$context = [];

		$meta_map = [
			'listen' => [
				'track'  => 'listen_track',
				'artist' => 'listen_artist',
				'album'  => 'listen_album',
			],
			'watch'  => [
				'title'    => 'watch_title',
				'year'     => 'watch_year',
				'director' => 'watch_director',
			],
			'read'   => [
				'title'  => 'read_title',
				'author' => 'read_author',
				'status' => 'read_status',
			],
			'jam'    => [
				'track'  => 'jam_track',
				'artist' => 'jam_artist',
			],
			'play'   => [
				'title'    => 'play_title',
				'platform' => 'play_platform',
			],
		];

		if ( isset( $meta_map[ $kind ] ) ) {
			foreach ( $meta_map[ $kind ] as $label => $meta_key ) {
				$value = get_post_meta( $post_id, $prefix . $meta_key, true );
				if ( $value ) {
					$context[ $label ] = $value;
				}
			}
		}

		$tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$context['existing_tags'] = implode( ', ', $tags );
		}

		return $context;
	}

	/**
	 * Log an error for debugging.
	 *
	 * @since 1.3.0
	 *
	 * @param string $context Error context.
	 * @param string $message Error message.
	 */
	private function log_error( string $context, string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( '[Post Kinds AI] %s: %s', $context, $message )
			);
		}
	}
}

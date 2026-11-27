<?php
/**
 * AI Enhancements (WordPress 7.0+ WP AI Client)
 *
 * Optional AI-powered features using the WordPress AI Client API.
 * Double-gated: requires both wp_ai_client_prompt() and pkiw_enable_ai setting.
 *
 * Features:
 * - Auto-Populate from URL: Extract title, summary, author from URLs.
 * - Smart Tagging: Suggest tags based on content and metadata.
 * - Content Summary: Generate review prompts from media metadata.
 *
 * @package PKIW
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PKIW;

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
	 * Readiness states.
	 *
	 * @var string
	 */
	public const STATE_DISABLED = 'disabled';
	public const STATE_MISSING  = 'missing';
	public const STATE_BLOCKED  = 'blocked';
	public const STATE_READY    = 'ready';

	/**
	 * Citation fields a suggestion may name, keyed by the provider's field.
	 *
	 * The order here is the order suggestions come back in.
	 *
	 * @var array<string, string>
	 */
	private const CITATION_FIELDS = [
		'title'   => 'cite_name',
		'author'  => 'cite_author',
		'summary' => 'cite_summary',
	];

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
	 * @return bool True if both wp_ai_client_prompt exists and pkiw_enable_ai is true.
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' )
			&& (bool) get_option( 'pkiw_enable_ai', false );
	}

	/**
	 * Whether a text model can answer right now, and if not, why.
	 *
	 * Disabled is checked first, so a site that hasn't opted in never asks
	 * the AI Client anything. Blocked covers the environment switch
	 * (wp_supports_ai) and core's wp_ai_client_prevent_prompt filter.
	 * Missing means no registered provider offers a JSON-capable text model.
	 *
	 * @since 1.11.0
	 *
	 * @param \WP_AI_Client_Prompt_Builder|null $builder Builder about to generate, so core's
	 *                                                  prevent filter sees the real prompt;
	 *                                                  null checks with a probe prompt.
	 * @return string One of the STATE_* constants.
	 */
	public static function readiness( $builder = null ): string {
		if ( ! (bool) get_option( 'pkiw_enable_ai', false ) ) {
			return self::STATE_DISABLED;
		}
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return self::STATE_MISSING;
		}
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return self::STATE_BLOCKED;
		}

		$builder = $builder ?? self::builder( 'Readiness check.', self::citation_schema() );
		/** This filter is documented in wp-includes/ai-client/class-wp-ai-client-prompt-builder.php */
		if ( (bool) apply_filters( 'wp_ai_client_prevent_prompt', false, clone $builder ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			return self::STATE_BLOCKED;
		}

		return true === $builder->is_supported_for_text_generation() ? self::STATE_READY : self::STATE_MISSING;
	}

	/**
	 * Readiness for the editor: state, a sentence to announce, and the Connectors link.
	 *
	 * The link is Core's Settings > Connectors screen, shown only when no
	 * provider is connected and the user can open that screen.
	 *
	 * @since 1.11.0
	 *
	 * @param string|null $state State to describe, or null to check now.
	 * @return array{state:string,message:string,connectorsUrl:string}
	 */
	public static function readiness_report( ?string $state = null ): array {
		$state    = $state ?? self::readiness();
		$messages = [
			self::STATE_DISABLED => __( 'AI suggestions are off for this site. A site administrator can turn them on.', 'post-kinds-for-indieweb-in-block-themes' ),
			self::STATE_MISSING  => __( 'No AI provider is connected for text, so no suggestions are available.', 'post-kinds-for-indieweb-in-block-themes' ),
			self::STATE_BLOCKED  => __( 'This site blocks AI requests, so no suggestions are available.', 'post-kinds-for-indieweb-in-block-themes' ),
			self::STATE_READY    => __( 'AI suggestions are ready. Nothing changes until you apply one.', 'post-kinds-for-indieweb-in-block-themes' ),
		];

		return [
			'state'         => $state,
			'message'       => $messages[ $state ] ?? $messages[ self::STATE_MISSING ],
			'connectorsUrl' => self::STATE_MISSING === $state && current_user_can( 'manage_options' ) ? admin_url( 'options-connectors.php' ) : '',
		];
	}

	/**
	 * Initialize the AI enhancements.
	 *
	 * The setting and routes always register so the editor can read
	 * readiness and an administrator can opt in; every generating route
	 * still refuses until readiness() is ready.
	 *
	 * @since 1.3.0
	 */
	private function __construct() {
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
			'pkiw_settings',
			'pkiw_enable_ai',
			[
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
				'description'       => __( 'Enable AI-powered enhancements for post kind editing.', 'post-kinds-for-indieweb-in-block-themes' ),
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
			'/ai/status',
			[
				'methods'             => 'GET',
				'callback'            => static fn() => rest_ensure_response( self::readiness_report() ),
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/auto-populate',
			[
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
							'validate_callback' => [ self::class, 'validate_http_url' ],
							'sanitize_callback' => 'esc_url_raw',
						],
					],
				],
				'schema' => [ $this, 'get_auto_populate_schema' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai/suggest-tags',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_suggest_tags' ],
				'permission_callback' => function ( \WP_REST_Request $request ) {
					return current_user_can( 'edit_post', absint( $request->get_param( 'post_id' ) ) );
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
				'permission_callback' => function ( \WP_REST_Request $request ) {
					// Capability check against the specific post, not just
					// generic editing rights.
					return current_user_can( 'edit_post', absint( $request->get_param( 'post_id' ) ) );
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
	 * Validate the cited URL: an http or https string with a host.
	 *
	 * Runs before the permission callback, so a non-string value is a REST
	 * 400 instead of reaching esc_url_raw() and throwing a TypeError.
	 *
	 * @since 1.11.0
	 *
	 * @param mixed $value URL parameter.
	 * @return true|\WP_Error True when valid.
	 */
	public static function validate_http_url( $value ) {
		$parts = is_string( $value ) ? wp_parse_url( $value ) : false;
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'The URL must be an http or https address.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 400 ]
			);
		}

		return true;
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

		$parsed = $this->generate_json( $prompt, self::citation_schema() );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$legacy      = [];
		$suggestions = [];
		foreach ( self::CITATION_FIELDS as $key => $field ) {
			$value          = 'summary' === $key
				? sanitize_textarea_field( (string) $parsed[ $key ] )
				: sanitize_text_field( (string) $parsed[ $key ] );
			$legacy[ $key ] = trim( $value );
			if ( '' === $legacy[ $key ] ) {
				continue;
			}
			$suggestions[] = [
				'field'  => $field,
				'value'  => $value,
				'source' => 'ai',
			];
		}

		// title, summary and author are the 1.3.0 response keys; callers that
		// predate typed suggestions still read them.
		return rest_ensure_response(
			[
				'state'       => self::STATE_READY,
				'title'       => $legacy['title'],
				'summary'     => $legacy['summary'],
				'author'      => $legacy['author'],
				'suggestions' => $suggestions,
			]
		);
	}

	/**
	 * Response schema for citation suggestions.
	 *
	 * Suggestions only name citation fields. The server never writes them;
	 * the editor offers each one and the author applies it.
	 *
	 * @since 1.11.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_auto_populate_schema(): array {
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'pkiw-ai-citation-suggestions',
			'type'       => 'object',
			'properties' => [
				'state'       => [
					'type' => 'string',
					'enum' => [ self::STATE_READY ],
				],
				'title'       => [ 'type' => 'string' ],
				'summary'     => [ 'type' => 'string' ],
				'author'      => [ 'type' => 'string' ],
				'suggestions' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'field'  => [
								'type' => 'string',
								'enum' => array_values( self::CITATION_FIELDS ),
							],
							'value'  => [ 'type' => 'string' ],
							'source' => [
								'type' => 'string',
								'enum' => [ 'ai' ],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * Output schema the provider must answer citation prompts in.
	 *
	 * @return array<string, mixed>
	 */
	private static function citation_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'title'   => [
					'type'      => 'string',
					'maxLength' => 300,
				],
				'summary' => [
					'type'      => 'string',
					'maxLength' => 1000,
				],
				'author'  => [
					'type'      => 'string',
					'maxLength' => 200,
				],
			],
			'required'             => [ 'title', 'summary', 'author' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Output schema for a list of short strings under one key.
	 *
	 * @param string $key       Property name.
	 * @param int    $max_items Most items allowed.
	 * @return array<string, mixed>
	 */
	private static function list_schema( string $key, int $max_items ): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				$key => [
					'type'     => 'array',
					'maxItems' => $max_items,
					'items'    => [
						'type'      => 'string',
						'maxLength' => 200,
					],
				],
			],
			'required'             => [ $key ],
			'additionalProperties' => false,
		];
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
				'pkiw_post_not_found',
				__( 'Post not found.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 404 ]
			);
		}

		$kind    = $this->get_kind_slug( $post_id );
		$prefix  = Meta_Fields::PREFIX;
		$context = $this->build_post_context( $post_id, $kind, $prefix );

		$prompt = sprintf(
			'Based on this %s post, suggest 3-5 relevant tags.' . "\n\n"
			. 'Post content: %s' . "\n\n"
			. 'Metadata: %s' . "\n\n"
			. 'Return a JSON object whose "tags" property is an array of tag strings.',
			$kind ? $kind : 'blog',
			wp_strip_all_tags( $post->post_content ),
			wp_json_encode( $context )
		);

		$parsed = $this->generate_json( $prompt, self::list_schema( 'tags', 10 ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return rest_ensure_response(
			[
				'state' => self::STATE_READY,
				'tags'  => self::clean_list( $parsed['tags'], 10 ),
			]
		);
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
				'pkiw_post_not_found',
				__( 'Post not found.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 404 ]
			);
		}

		$kind   = $this->get_kind_slug( $post_id );
		$prefix = Meta_Fields::PREFIX;

		if ( ! in_array( $kind, [ 'read', 'watch', 'listen' ], true ) ) {
			return new \WP_Error(
				'pkiw_invalid_kind',
				__( 'Content summaries are only available for read, watch, and listen posts.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 400 ]
			);
		}

		$context = $this->build_post_context( $post_id, $kind, $prefix );

		$prompt = sprintf(
			'This is a %s post about: %s' . "\n\n"
			. 'Generate 3 short review prompts to help the user write about this. '
			. 'Each prompt should be a question or sentence starter that encourages personal reflection.' . "\n\n"
			. 'Return a JSON object whose "prompts" property is an array of prompt strings.',
			$kind,
			wp_json_encode( $context )
		);

		$parsed = $this->generate_json( $prompt, self::list_schema( 'prompts', 5 ) );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return rest_ensure_response(
			[
				'state'   => self::STATE_READY,
				'prompts' => self::clean_list( $parsed['prompts'], 5 ),
			]
		);
	}

	/**
	 * Sanitize a validated list of strings and drop empty entries.
	 *
	 * @param mixed $items Validated list.
	 * @param int   $max   Most items kept.
	 * @return string[]
	 */
	private static function clean_list( $items, int $max ): array {
		$clean = array_filter(
			array_map( 'sanitize_text_field', array_map( 'strval', (array) $items ) ),
			static fn( string $item ): bool => '' !== trim( $item )
		);

		return array_slice( array_values( $clean ), 0, $max );
	}

	/**
	 * A prompt builder that asks for JSON in the given shape.
	 *
	 * @param string               $prompt Prompt text.
	 * @param array<string, mixed> $schema Output schema.
	 * @return \WP_AI_Client_Prompt_Builder
	 */
	private static function builder( string $prompt, array $schema ) {
		return wp_ai_client_prompt( $prompt )
			->as_output_modalities( \WordPress\AiClient\Messages\Enums\ModalityEnum::text() )
			->as_json_response( $schema );
	}

	/**
	 * Whether decoded JSON has the PHP types its schema names.
	 *
	 * Takes JSON decoded without the associative flag, so a JSON object is a
	 * stdClass and a JSON array is a list. An associative decode would turn
	 * {"0":"a","1":"b"} into a list and let it pass as an array. Core's
	 * validator also accepts a comma-separated string as an array, so this
	 * checks the container types first.
	 *
	 * @param mixed                $value  Value from json_decode( $text ).
	 * @param array<string, mixed> $schema Schema.
	 * @return bool
	 */
	private static function matches_shape( $value, array $schema ): bool {
		$type = $schema['type'] ?? '';
		if ( 'object' === $type ) {
			if ( ! $value instanceof \stdClass ) {
				return false;
			}
			$value = get_object_vars( $value );
			foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $child ) {
				if ( array_key_exists( $key, $value ) && ! self::matches_shape( $value[ $key ], (array) $child ) ) {
					return false;
				}
			}
			return true;
		}
		if ( 'array' === $type ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				return false;
			}
			foreach ( $value as $item ) {
				if ( ! self::matches_shape( $item, (array) ( $schema['items'] ?? [] ) ) ) {
					return false;
				}
			}
			return true;
		}

		return 'string' !== $type || is_string( $value );
	}

	/**
	 * Generate a JSON answer and validate it against its schema.
	 *
	 * Errors carry a fixed message and a state: the readiness state when the
	 * request never reached a provider, or `malformed` when the provider
	 * answered in the wrong shape. Provider error text never reaches the
	 * response or the log, because it can quote a request header or key.
	 *
	 * @since 1.11.0
	 *
	 * @param string               $prompt The prompt to send.
	 * @param array<string, mixed> $schema Output schema the answer must match.
	 * @return array<string, mixed>|\WP_Error Validated answer, or error.
	 */
	private function generate_json( string $prompt, array $schema ) {
		$builder = function_exists( 'wp_ai_client_prompt' ) && (bool) get_option( 'pkiw_enable_ai', false )
			? self::builder( $prompt, $schema )
			: null;
		$state   = null === $builder ? self::readiness() : self::readiness( $builder );
		if ( self::STATE_READY !== $state ) {
			$report = self::readiness_report( $state );
			return new \WP_Error(
				'pkiw_ai_' . $state,
				$report['message'],
				[
					'status' => 503,
					'state'  => $state,
				]
			);
		}

		$failed = new \WP_Error(
			'pkiw_ai_request_failed',
			__( 'The AI provider did not answer, so nothing was suggested.', 'post-kinds-for-indieweb-in-block-themes' ),
			[
				'status' => 502,
				'state'  => $state,
			]
		);

		try {
			$text = $builder->generate_text();
		} catch ( \Throwable $e ) {
			$this->log_error( 'AI request exception', get_class( $e ) );
			return $failed;
		}

		if ( is_wp_error( $text ) ) {
			$this->log_error( 'AI request failed', $text->get_error_code() );
			return $failed;
		}

		$parsed = self::matches_shape( json_decode( (string) $text ), $schema ) ? json_decode( (string) $text, true ) : null;
		$valid  = is_array( $parsed ) ? rest_validate_value_from_schema( $parsed, $schema, 'response' ) : false;
		if ( true !== $valid ) {
			return new \WP_Error(
				'pkiw_ai_malformed',
				__( 'The AI provider answered in the wrong shape, so nothing was suggested.', 'post-kinds-for-indieweb-in-block-themes' ),
				[
					'status' => 502,
					'state'  => 'malformed',
				]
			);
		}

		return $parsed;
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
		$transient_key = 'pkiw_ai_rate_' . $action . '_' . $user_id;

		if ( get_transient( $transient_key ) ) {
			return new \WP_Error(
				'pkiw_rate_limited',
				__( 'Please wait a few seconds before making another AI request.', 'post-kinds-for-indieweb-in-block-themes' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $transient_key, 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Get the slug of a post's kind term.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Kind slug, or empty string when the post has no kind.
	 */
	private function get_kind_slug( int $post_id ): string {
		$taxonomy = Plugin::get_instance()->get_taxonomy();
		$term     = null !== $taxonomy ? $taxonomy->get_post_kind( $post_id ) : null;

		return null !== $term ? $term->slug : '';
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

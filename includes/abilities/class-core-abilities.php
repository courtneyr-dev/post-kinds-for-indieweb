<?php
/**
 * Core Abilities Provider for Post Kinds for IndieWeb
 *
 * Registers 7 abilities for managing post kinds via the Abilities API.
 *
 * @package PostKindsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Abilities;

use PostKindsForIndieWeb\Abilities_Manager;
use PostKindsForIndieWeb\Meta_Fields;
use PostKindsForIndieWeb\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core Abilities provider.
 *
 * Provides abilities for listing kinds, listing kind fields,
 * creating posts, setting/getting kinds, and managing post meta.
 *
 * @since 1.1.0
 */
final class Core_Abilities {

	/**
	 * Singleton instance.
	 *
	 * @var Core_Abilities|null
	 */
	private static ?Core_Abilities $instance = null;

	/**
	 * Taxonomy instance.
	 *
	 * @var Taxonomy
	 */
	private Taxonomy $taxonomy;

	/**
	 * Meta Fields instance.
	 *
	 * @var Meta_Fields
	 */
	private Meta_Fields $meta_fields;

	/**
	 * Kind-to-field-prefix mapping.
	 *
	 * @var array<string, array<string>>
	 */
	private array $kind_field_prefixes = [
		'note'        => [],
		'article'     => [],
		'photo'       => [],
		'video'       => [],
		'reply'       => [ 'cite_' ],
		'like'        => [ 'cite_' ],
		'repost'      => [ 'cite_' ],
		'bookmark'    => [ 'cite_', 'bookmark_' ],
		'rsvp'        => [ 'cite_', 'rsvp_' ],
		'checkin'     => [ 'checkin_', 'geo_' ],
		'listen'      => [ 'listen_' ],
		'watch'       => [ 'watch_' ],
		'read'        => [ 'read_' ],
		'event'       => [ 'event_' ],
		'review'      => [ 'review_', 'cite_' ],
		'favorite'    => [ 'favorite_' ],
		'jam'         => [ 'jam_' ],
		'wish'        => [ 'wish_' ],
		'mood'        => [ 'mood_' ],
		'acquisition' => [ 'acquisition_' ],
		'drink'       => [ 'drink_' ],
		'eat'         => [ 'eat_' ],
		'recipe'      => [ 'recipe_' ],
		'play'        => [ 'play_' ],
	];

	/**
	 * Get singleton instance.
	 *
	 * @since 1.1.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	private function __construct() {
		$this->taxonomy    = new Taxonomy();
		$this->meta_fields = new Meta_Fields();
	}

	/**
	 * Register all 7 core abilities.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		$this->register_list_kinds();
		$this->register_list_kind_fields();
		$this->register_create_post();
		$this->register_set_kind();
		$this->register_get_kind();
		$this->register_update_post_meta();
		$this->register_get_post_meta();
	}

	/**
	 * Register the list_kinds ability.
	 */
	private function register_list_kinds(): void {
		wp_register_ability(
			'post_kinds/list_kinds',
			[
				'label'               => __( 'List Kinds', 'post-kinds-for-indieweb' ),
				'description'         => __( 'Lists all available post kinds with slugs, labels, and descriptions.', 'post-kinds-for-indieweb' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'kinds' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'slug'        => [ 'type' => 'string' ],
									'label'       => [ 'type' => 'string' ],
									'description' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_kinds' ],
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Register the list_kind_fields ability.
	 */
	private function register_list_kind_fields(): void {
		wp_register_ability(
			'post_kinds/list_kind_fields',
			[
				'label'               => __( 'List Kind Fields', 'post-kinds-for-indieweb' ),
				'description'         => __( 'Lists meta fields available for a specific kind.', 'post-kinds-for-indieweb' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'kind' => [
							'type'        => 'string',
							'description' => __( 'Kind slug to get fields for.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'kind' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'fields' => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'key'         => [ 'type' => 'string' ],
									'type'        => [ 'type' => 'string' ],
									'description' => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'execute_callback'    => [ $this, 'execute_list_kind_fields' ],
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Register the create_post ability.
	 */
	private function register_create_post(): void {
		wp_register_ability(
			'post_kinds/create_post',
			[
				'label'               => __( 'Create Post', 'post-kinds-for-indieweb' ),
				'description'         => __( 'Creates a post with a kind and optional meta fields.', 'post-kinds-for-indieweb' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'kind'    => [
							'type'        => 'string',
							'description' => __( 'Kind slug for the post.', 'post-kinds-for-indieweb' ),
						],
						'title'   => [
							'type'        => 'string',
							'description' => __( 'Post title.', 'post-kinds-for-indieweb' ),
						],
						'content' => [
							'type'        => 'string',
							'description' => __( 'Post content.', 'post-kinds-for-indieweb' ),
						],
						'status'  => [
							'type'        => 'string',
							'description' => __( 'Post status: draft, publish, or private.', 'post-kinds-for-indieweb' ),
							'default'     => 'draft',
							'enum'        => [ 'draft', 'publish', 'private' ],
						],
					],
					'required'             => [ 'kind' ],
					'additionalProperties' => true,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id'  => [ 'type' => 'integer' ],
						'edit_url' => [ 'type' => 'string' ],
						'view_url' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_create_post' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Register the set_kind ability.
	 */
	private function register_set_kind(): void {
		wp_register_ability(
			'post_kinds/set_kind',
			[
				'label'               => __( 'Set Kind', 'post-kinds-for-indieweb' ),
				'description'         => __( 'Sets the kind on an existing post.', 'post-kinds-for-indieweb' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'post-kinds-for-indieweb' ),
						],
						'kind'    => [
							'type'        => 'string',
							'description' => __( 'Kind slug to assign.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'post_id', 'kind' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'   => [ 'type' => 'boolean' ],
						'post_id'   => [ 'type' => 'integer' ],
						'kind_slug' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_set_kind' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Register the get_kind ability.
	 */
	private function register_get_kind(): void {
		wp_register_ability(
			'post_kinds/get_kind',
			[
				'label'               => __( 'Get Kind', 'post-kinds-for-indieweb' ),
				'description'         => __( 'Gets the kind assigned to a post.', 'post-kinds-for-indieweb' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'kind_slug'        => [ 'type' => 'string' ],
						'kind_label'       => [ 'type' => 'string' ],
						'kind_description' => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_kind' ],
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Register the update_post_meta ability.
	 */
	private function register_update_post_meta(): void {
		wp_register_ability(
			'post_kinds/update_post_meta',
			[
				'label'               => __( 'Update Post Meta', 'post-kinds-for-indieweb' ),
				'description'         => __( 'Updates a single meta field on a post.', 'post-kinds-for-indieweb' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'    => [
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'post-kinds-for-indieweb' ),
						],
						'meta_key'   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'type'        => 'string',
							'description' => __( 'Meta field key without the _postkind_ prefix.', 'post-kinds-for-indieweb' ),
						],
						'meta_value' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
							'description' => __( 'Value to set.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'post_id', 'meta_key', 'meta_value' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'  => [ 'type' => 'boolean' ],
						'post_id'  => [ 'type' => 'integer' ],
						'meta_key' => [ 'type' => 'string' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					],
				],
				'execute_callback'    => [ $this, 'execute_update_post_meta' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Register the get_post_meta ability.
	 */
	private function register_get_post_meta(): void {
		wp_register_ability(
			'post_kinds/get_post_meta',
			[
				'label'               => __( 'Get Post Meta', 'post-kinds-for-indieweb' ),
				'description'         => __( 'Gets meta fields from a post.', 'post-kinds-for-indieweb' ),
				'category'            => Abilities_Manager::CATEGORY_SLUG,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [
							'type'        => 'integer',
							'description' => __( 'Post ID.', 'post-kinds-for-indieweb' ),
						],
						'meta_keys' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'Meta field keys without the _postkind_ prefix. If omitted, returns all postkind meta.', 'post-kinds-for-indieweb' ),
						],
					],
					'required'   => [ 'post_id' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
						'meta'    => [ 'type' => 'object' ],
					],
				],
				'execute_callback'    => [ $this, 'execute_get_post_meta' ],
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
				'meta'                => [ 'show_in_rest' => true ],
			]
		);
	}

	/**
	 * Execute: list all kinds.
	 *
	 * @param array $args Input arguments (unused).
	 * @return array Result with 'kinds' array.
	 */
	public function execute_list_kinds( array $args ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$default_kinds = $this->taxonomy->get_default_kinds();
		$kinds         = [];

		foreach ( $default_kinds as $slug => $kind_data ) {
			$kinds[] = [
				'slug'        => $slug,
				'label'       => $kind_data['name'],
				'description' => $kind_data['description'],
			];
		}

		return [ 'kinds' => $kinds ];
	}

	/**
	 * Execute: list fields for a kind.
	 *
	 * @param array $args Input arguments with 'kind' key.
	 * @return array|\WP_Error Result with 'fields' array or error.
	 */
	public function execute_list_kind_fields( array $args ): array|\WP_Error {
		$kind = $args['kind'] ?? '';

		if ( ! isset( $this->kind_field_prefixes[ $kind ] ) ) {
			return new \WP_Error(
				'invalid_kind',
				sprintf(
					/* translators: %s: kind slug */
					__( 'Unknown kind: %s', 'post-kinds-for-indieweb' ),
					$kind
				)
			);
		}

		$prefixes   = $this->kind_field_prefixes[ $kind ];
		$all_fields = $this->meta_fields->get_fields();
		$fields     = [];

		foreach ( $all_fields as $key => $field ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					$fields[] = [
						'key'         => $key,
						'type'        => $field['type'],
						'description' => $field['description'],
					];
					break;
				}
			}
		}

		return [ 'fields' => $fields ];
	}

	/**
	 * Execute: create a post with kind and meta.
	 *
	 * @param array $args Input arguments.
	 * @return array|\WP_Error Result with post_id, edit_url, view_url or error.
	 */
	public function execute_create_post( array $args ): array|\WP_Error {
		$kind    = $args['kind'] ?? '';
		$title   = $args['title'] ?? '';
		$content = $args['content'] ?? '';
		$status  = $args['status'] ?? 'draft';

		if ( ! in_array( $status, [ 'draft', 'publish', 'private' ], true ) ) {
			$status = 'draft';
		}

		$post_id = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => 'post',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set the kind taxonomy term.
		wp_set_post_terms( $post_id, [ $kind ], Taxonomy::TAXONOMY );

		// Set meta fields from remaining args.
		$reserved_keys = [ 'kind', 'title', 'content', 'status' ];
		foreach ( $args as $key => $value ) {
			if ( in_array( $key, $reserved_keys, true ) ) {
				continue;
			}
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}

		return [
			'post_id'  => $post_id,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ) ? get_edit_post_link( $post_id, 'raw' ) : '',
			'view_url' => get_permalink( $post_id ) ? get_permalink( $post_id ) : '',
		];
	}

	/**
	 * Execute: set kind on an existing post.
	 *
	 * @param array $args Input arguments with 'post_id' and 'kind'.
	 * @return array|\WP_Error Result or error.
	 */
	public function execute_set_kind( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$kind    = $args['kind'] ?? '';

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found.', 'post-kinds-for-indieweb' )
			);
		}

		$result = wp_set_post_terms( $post_id, [ $kind ], Taxonomy::TAXONOMY );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'success'   => true,
			'post_id'   => $post_id,
			'kind_slug' => $kind,
		];
	}

	/**
	 * Execute: get kind from a post.
	 *
	 * @param array $args Input arguments with 'post_id'.
	 * @return array|\WP_Error Result with kind info or error.
	 */
	public function execute_get_kind( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found.', 'post-kinds-for-indieweb' )
			);
		}

		$terms = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			// Default to 'note'.
			$default_kinds = $this->taxonomy->get_default_kinds();
			return [
				'kind_slug'        => 'note',
				'kind_label'       => $default_kinds['note']['name'] ?? 'Note',
				'kind_description' => $default_kinds['note']['description'] ?? '',
			];
		}

		$term = $terms[0];
		return [
			'kind_slug'        => $term->slug,
			'kind_label'       => $term->name,
			'kind_description' => $term->description,
		];
	}

	/**
	 * Execute: update a single meta field.
	 *
	 * @param array $args Input arguments with 'post_id', 'meta_key', 'meta_value'.
	 * @return array|\WP_Error Result or error.
	 */
	public function execute_update_post_meta( array $args ): array|\WP_Error {
		$post_id    = (int) ( $args['post_id'] ?? 0 );
		$meta_key   = $args['meta_key'] ?? '';
		$meta_value = $args['meta_value'] ?? '';

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found.', 'post-kinds-for-indieweb' )
			);
		}

		$full_key = Meta_Fields::PREFIX . $meta_key;
		update_post_meta( $post_id, $full_key, $meta_value );

		return [
			'success'  => true,
			'post_id'  => $post_id,
			'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		];
	}

	/**
	 * Execute: get meta fields from a post.
	 *
	 * @param array $args Input arguments with 'post_id' and optional 'meta_keys'.
	 * @return array|\WP_Error Result with meta values or error.
	 */
	public function execute_get_post_meta( array $args ): array|\WP_Error {
		$post_id   = (int) ( $args['post_id'] ?? 0 );
		$meta_keys = $args['meta_keys'] ?? [];

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'invalid_post',
				__( 'Post not found.', 'post-kinds-for-indieweb' )
			);
		}

		$meta = [];

		if ( ! empty( $meta_keys ) ) {
			// Return specific keys.
			foreach ( $meta_keys as $key ) {
				$meta[ $key ] = get_post_meta( $post_id, Meta_Fields::PREFIX . $key, true );
			}
		} else {
			// Return all _postkind_ prefixed meta.
			$all_meta = get_post_meta( $post_id );
			foreach ( $all_meta as $full_key => $values ) {
				if ( str_starts_with( $full_key, Meta_Fields::PREFIX ) ) {
					$short_key          = substr( $full_key, strlen( Meta_Fields::PREFIX ) );
					$meta[ $short_key ] = $values[0] ?? '';
				}
			}
		}

		return [
			'post_id' => $post_id,
			'meta'    => $meta,
		];
	}
}

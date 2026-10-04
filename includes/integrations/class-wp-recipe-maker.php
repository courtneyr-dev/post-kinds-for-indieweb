<?php
/**
 * WP Recipe Maker Integration
 *
 * Integrates with WP Recipe Maker plugin to auto-detect and suggest
 * the 'recipe' post kind when a post contains a WPRM recipe.
 *
 * @package PKIW
 * @since   1.1.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Recipe Maker Integration class.
 *
 * Detects WP Recipe Maker recipes in posts and auto-suggests or sets
 * the recipe post kind accordingly.
 *
 * @since 1.1.0
 */
class WP_Recipe_Maker {

	/**
	 * Whether WP Recipe Maker plugin is active.
	 *
	 * @var bool
	 */
	private bool $wprm_active = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->detect_wprm();

		if ( $this->wprm_active ) {
			$this->register_hooks();
		}
	}

	/**
	 * Detect if WP Recipe Maker is active.
	 *
	 * @return void
	 */
	private function detect_wprm(): void {
		$this->wprm_active = self::is_supported_plugin_active();
	}

	/**
	 * Whether a supported recipe plugin is running.
	 *
	 * WP Recipe Maker is the one supported plugin. When it runs it owns recipe
	 * data and the recipe card; Post Kinds reads from it and copies nothing.
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	public static function is_supported_plugin_active(): bool {
		$active = class_exists( 'WPRM_Recipe_Manager' ) || post_type_exists( 'wprm_recipe' );

		if ( ! $active ) {
			foreach ( (array) get_option( 'active_plugins', [] ) as $plugin ) {
				if ( false !== strpos( (string) $plugin, 'wp-recipe-maker' ) ) {
					$active = true;
					break;
				}
			}
		}

		/**
		 * Filters whether a supported recipe plugin is treated as running.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $active True when WP Recipe Maker is detected.
		 */
		return (bool) apply_filters( 'pkiw_recipe_plugin_active', $active );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Auto-set recipe kind when saving a post with a WPRM recipe.
		add_action( 'save_post', [ $this, 'maybe_set_recipe_kind' ], 20, 2 );

		// Add editor script to suggest recipe kind.
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );

		// REST API endpoint to check if post has recipe.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Maybe set recipe kind when post contains WPRM recipe.
	 *
	 * Only auto-sets if:
	 * - Post contains a WPRM recipe
	 * - Post doesn't already have a kind set (or has 'note' as default)
	 * - Auto-detection is enabled in settings
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function maybe_set_recipe_kind( int $post_id, \WP_Post $post ): void {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only handle regular posts (not WPRM recipe CPT itself).
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Check if auto-detection is enabled.
		$settings = get_option( 'pkiw_settings', [] );
		if ( empty( $settings['wprm_auto_kind'] ) ) {
			return;
		}

		// Check if post already has a non-default kind.
		$current_kind = $this->get_post_kind( $post_id );
		if ( $current_kind && 'note' !== $current_kind ) {
			return;
		}

		// Check if post contains a WPRM recipe.
		if ( ! $this->post_has_recipe( $post_id ) ) {
			return;
		}

		// Set the recipe kind.
		wp_set_post_terms( $post_id, [ 'recipe' ], Taxonomy::TAXONOMY );
	}

	/**
	 * Formerly copied a recipe's servings and total time into Post Kinds meta on save.
	 *
	 * WP Recipe Maker owns recipe data, so nothing is copied any more. Values
	 * this method stored earlier are left in place.
	 *
	 * @deprecated 1.9.0 Read recipe values at render time with \PKIW\recipe_facts().
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function sync_recipe_meta( int $post_id, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- kept for callers of the old signature.
		_deprecated_function( __METHOD__, '1.9.0', '\\PKIW\\recipe_facts()' );
	}

	/**
	 * Get post kind slug.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Kind slug or null.
	 */
	private function get_post_kind( int $post_id ): ?string {
		$terms = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Check if a post contains a WPRM recipe.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if post has recipe.
	 */
	public function post_has_recipe( int $post_id ): bool {
		$recipe_ids = $this->get_recipe_ids_in_post( $post_id );
		return ! empty( $recipe_ids );
	}

	/**
	 * Get WPRM recipe IDs in a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int> Recipe IDs.
	 */
	public function get_recipe_ids_in_post( int $post_id ): array {
		return self::recipe_ids_in_post( $post_id );
	}

	/**
	 * Recipe IDs embedded in a post, without needing the integration instance.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<int> Recipe IDs.
	 */
	public static function recipe_ids_in_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$recipe_ids = [];

		// Method 1: Check for WPRM shortcodes in content.
		if ( has_shortcode( $post->post_content, 'wprm-recipe' ) ) {
			// Extract recipe IDs from shortcodes.
			preg_match_all( '/\[wprm-recipe\s+id="?(\d+)"?\]/', $post->post_content, $matches );
			if ( ! empty( $matches[1] ) ) {
				$recipe_ids = array_merge( $recipe_ids, array_map( 'intval', $matches[1] ) );
			}
		}

		// Method 2: Check for WPRM blocks (Gutenberg).
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks     = parse_blocks( $post->post_content );
			$recipe_ids = array_merge( $recipe_ids, self::find_recipe_blocks( $blocks ) );
		}

		// Method 3: Check WPRM's own tracking (if available).
		// `function_exists()` doesn't detect class methods — always returns
		// false for `Class::method` strings. Use class_exists + method_exists.
		if (
			class_exists( 'WPRM_Recipe_Manager' )
			&& method_exists( 'WPRM_Recipe_Manager', 'get_recipe_ids_from_post' )
		) {
			$wprm_ids = \WPRM_Recipe_Manager::get_recipe_ids_from_post( $post_id );
			if ( is_array( $wprm_ids ) ) {
				$recipe_ids = array_merge( $recipe_ids, $wprm_ids );
			}
		}

		return array_unique( array_filter( $recipe_ids ) );
	}

	/**
	 * Recursively find recipe blocks in parsed blocks.
	 *
	 * @param array<array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int> Recipe IDs found.
	 */
	private static function find_recipe_blocks( array $blocks ): array {
		$recipe_ids = [];

		foreach ( $blocks as $block ) {
			// Check for WPRM recipe block.
			if ( 'wp-recipe-maker/recipe' === $block['blockName'] ) {
				if ( ! empty( $block['attrs']['id'] ) ) {
					$recipe_ids[] = (int) $block['attrs']['id'];
				}
			}

			// Check inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$recipe_ids = array_merge( $recipe_ids, self::find_recipe_blocks( $block['innerBlocks'] ) );
			}
		}

		return $recipe_ids;
	}

	/**
	 * Get WPRM recipe data.
	 *
	 * @param int $recipe_id Recipe post ID.
	 * @return array<string, mixed>|null Recipe data or null.
	 */
	private static function get_wprm_recipe( int $recipe_id ): ?array {
		$recipe_post = get_post( $recipe_id );

		if ( ! $recipe_post || 'wprm_recipe' !== $recipe_post->post_type ) {
			return null;
		}

		// Use WPRM's recipe object if available.
		if ( class_exists( 'WPRM_Recipe_Manager' ) && method_exists( 'WPRM_Recipe_Manager', 'get_recipe' ) ) {
			$recipe = \WPRM_Recipe_Manager::get_recipe( $recipe_id );
			if ( $recipe ) {
				return [
					'id'            => $recipe_id,
					'name'          => $recipe->name(),
					'servings'      => $recipe->servings(),
					'servings_unit' => $recipe->servings_unit(),
					'prep_time'     => $recipe->prep_time(),
					'cook_time'     => $recipe->cook_time(),
					'total_time'    => $recipe->total_time(),
					'image_id'      => $recipe->image_id(),
					'summary'       => method_exists( $recipe, 'summary' ) ? wp_strip_all_tags( (string) $recipe->summary() ) : '',
					'courses'       => self::recipe_courses( $recipe_id ),
				];
			}
		}

		// Fallback: Read meta directly.
		return [
			'id'            => $recipe_id,
			'name'          => $recipe_post->post_title,
			'servings'      => get_post_meta( $recipe_id, 'wprm_servings', true ),
			'servings_unit' => get_post_meta( $recipe_id, 'wprm_servings_unit', true ),
			'prep_time'     => get_post_meta( $recipe_id, 'wprm_prep_time', true ),
			'cook_time'     => get_post_meta( $recipe_id, 'wprm_cook_time', true ),
			'total_time'    => get_post_meta( $recipe_id, 'wprm_total_time', true ),
			'image_id'      => get_post_thumbnail_id( $recipe_id ),
			'summary'       => '',
			'courses'       => self::recipe_courses( $recipe_id ),
		];
	}

	/**
	 * A recipe's courses, by name.
	 *
	 * WP Recipe Maker keeps courses as `wprm_course` terms on the recipe
	 * post, not on the post that embeds the recipe.
	 *
	 * @param int $recipe_id Recipe post ID.
	 * @return array<int, array{name: string, slug: string}>
	 */
	private static function recipe_courses( int $recipe_id ): array {
		if ( ! taxonomy_exists( 'wprm_course' ) ) {
			return [];
		}

		$terms = get_the_terms( $recipe_id, 'wprm_course' );
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$courses = [];
		foreach ( $terms as $term ) {
			$courses[] = [
				'name' => $term->name,
				'slug' => $term->slug,
			];
		}
		usort( $courses, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );

		return $courses;
	}

	/**
	 * The recipe a post embeds, read from WP Recipe Maker when called.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Recipe values, or null when the plugin isn't running or the post holds no recipe.
	 */
	public static function get_post_recipe( int $post_id ): ?array {
		if ( ! self::is_supported_plugin_active() ) {
			return null;
		}

		foreach ( self::recipe_ids_in_post( $post_id ) as $recipe_id ) {
			$recipe = self::get_wprm_recipe( (int) $recipe_id );
			if ( null !== $recipe ) {
				return $recipe;
			}
		}

		return null;
	}

	/**
	 * Posts whose recipe is filed under a course.
	 *
	 * Goes from the course to its recipes, then to each recipe's parent
	 * post, because the course taxonomy lives on the recipe post type.
	 *
	 * @since 1.9.0
	 *
	 * @param string $course_slug `wprm_course` term slug.
	 * @return array<int> Post IDs.
	 */
	public static function post_ids_in_course( string $course_slug ): array {
		if ( '' === $course_slug || ! taxonomy_exists( 'wprm_course' ) ) {
			return [];
		}

		$recipe_ids = get_posts(
			[
				'post_type'      => 'wprm_recipe',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the course filter is a taxonomy lookup.
					[
						'taxonomy' => 'wprm_course',
						'field'    => 'slug',
						'terms'    => $course_slug,
					],
				],
			]
		);

		$post_ids = [];
		foreach ( $recipe_ids as $recipe_id ) {
			$parent = (int) get_post_meta( (int) $recipe_id, 'wprm_parent_post_id', true );
			if ( $parent > 0 ) {
				$post_ids[] = $parent;
			}
		}

		return array_values( array_unique( $post_ids ) );
	}

	/**
	 * Enqueue editor assets for recipe detection.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		// Inline script to detect WPRM recipe blocks and suggest recipe kind.
		$script = "
		( function() {
			const { subscribe, select, dispatch } = wp.data;
			const { createNotice } = wp.notices ? dispatch( 'core/notices' ) : { createNotice: () => {} };

			let noticeShown = false;
			let lastBlockCount = 0;

			subscribe( function() {
				if ( noticeShown ) return;

				const blocks = select( 'core/block-editor' ).getBlocks();
				const currentBlockCount = blocks.length;

				// Only check when blocks change
				if ( currentBlockCount === lastBlockCount ) return;
				lastBlockCount = currentBlockCount;

				// Check for WPRM recipe block
				const hasRecipeBlock = blocks.some( function checkBlock( block ) {
					if ( block.name === 'wp-recipe-maker/recipe' ) return true;
					if ( block.innerBlocks && block.innerBlocks.length ) {
						return block.innerBlocks.some( checkBlock );
					}
					return false;
				});

				if ( ! hasRecipeBlock ) return;

				// Check current kind
				const postType = select( 'core/editor' ).getCurrentPostType();
				if ( postType !== 'post' ) return;

				const kinds = select( 'core/editor' ).getEditedPostAttribute( 'kind' );
				const hasRecipeKind = kinds && kinds.some( function( term ) {
					return term === 'recipe' || ( term.slug && term.slug === 'recipe' );
				});

				if ( ! hasRecipeKind ) {
					createNotice(
						'info',
						'" . esc_js( __( 'This post contains a recipe. Consider setting the post kind to \"Recipe\".', 'post-kinds-for-indieweb-in-block-themes' ) ) . "',
						{
							id: 'post-kinds-recipe-suggestion',
							isDismissible: true,
							actions: [
								{
									label: '" . esc_js( __( 'Set Recipe Kind', 'post-kinds-for-indieweb-in-block-themes' ) ) . "',
									onClick: function() {
										// Find recipe term ID and set it
										wp.apiFetch({ path: '/wp/v2/kind?slug=recipe' }).then( function( terms ) {
											if ( terms && terms.length ) {
												dispatch( 'core/editor' ).editPost({ kind: [ terms[0].id ] });
												dispatch( 'core/notices' ).removeNotice( 'post-kinds-recipe-suggestion' );
											}
										});
									}
								}
							]
						}
					);
					noticeShown = true;
				}
			});
		})();
		";

		wp_add_inline_script( 'wp-edit-post', $script );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'post-kinds-indieweb/v1',
			'/post/(?P<id>\d+)/has-recipe',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_check_recipe' ],
				'permission_callback' => function ( \WP_REST_Request $request ) {
					return current_user_can( 'edit_post', absint( $request['id'] ) );
				},
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);
	}

	/**
	 * REST API callback to check if post has recipe.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response Response.
	 */
	public function rest_check_recipe( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id    = (int) $request->get_param( 'id' );
		$has_recipe = $this->post_has_recipe( $post_id );
		$recipe_ids = $has_recipe ? $this->get_recipe_ids_in_post( $post_id ) : [];

		return new \WP_REST_Response(
			[
				'has_recipe'  => $has_recipe,
				'recipe_ids'  => $recipe_ids,
				'recipe_data' => $has_recipe && ! empty( $recipe_ids ) ? $this->get_wprm_recipe( $recipe_ids[0] ) : null,
			],
			200
		);
	}

	/**
	 * Check if WP Recipe Maker is active.
	 *
	 * @return bool True if WPRM is active.
	 */
	public function is_active(): bool {
		return $this->wprm_active;
	}
}

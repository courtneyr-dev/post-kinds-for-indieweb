<?php
/**
 * WP Recipe Maker Integration
 *
 * Integrates with WP Recipe Maker plugin to auto-detect and suggest
 * the 'recipe' post kind when a post contains a WPRM recipe.
 *
 * @package ReactionsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb\Integrations;

use ReactionsForIndieWeb\Taxonomy;

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
		// Check if WPRM is active via its main class.
		if ( class_exists( 'WPRM_Recipe_Manager' ) ) {
			$this->wprm_active = true;
			return;
		}

		// Check if WPRM post type exists.
		if ( post_type_exists( 'wprm_recipe' ) ) {
			$this->wprm_active = true;
			return;
		}

		// Check active plugins.
		$active_plugins = get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $plugin ) {
			if ( strpos( $plugin, 'wp-recipe-maker' ) !== false ) {
				$this->wprm_active = true;
				return;
			}
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Auto-set recipe kind when saving a post with a WPRM recipe.
		add_action( 'save_post', array( $this, 'maybe_set_recipe_kind' ), 20, 2 );

		// Add recipe data to post meta when WPRM recipe is detected.
		add_action( 'save_post', array( $this, 'sync_recipe_meta' ), 25, 2 );

		// Add editor script to suggest recipe kind.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

		// REST API endpoint to check if post has recipe.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
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
		$settings = get_option( 'reactions_indieweb_settings', array() );
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
		wp_set_post_terms( $post_id, array( 'recipe' ), Taxonomy::TAXONOMY );
	}

	/**
	 * Sync recipe metadata from WPRM to our meta fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function sync_recipe_meta( int $post_id, \WP_Post $post ): void {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only handle regular posts.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Check if post has a WPRM recipe.
		$recipe_ids = $this->get_recipe_ids_in_post( $post_id );
		if ( empty( $recipe_ids ) ) {
			return;
		}

		// Get the first recipe (primary recipe).
		$recipe_id = $recipe_ids[0];
		$recipe    = $this->get_wprm_recipe( $recipe_id );

		if ( ! $recipe ) {
			return;
		}

		// Sync basic recipe data to our meta fields.
		$prefix = '_reactions_';

		// Recipe yield/servings.
		$servings = $this->get_recipe_servings( $recipe );
		if ( $servings ) {
			update_post_meta( $post_id, $prefix . 'recipe_yield', $servings );
		}

		// Recipe duration (total time).
		$duration = $this->get_recipe_duration( $recipe );
		if ( $duration ) {
			update_post_meta( $post_id, $prefix . 'recipe_duration', $duration );
		}
	}

	/**
	 * Get post kind slug.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Kind slug or null.
	 */
	private function get_post_kind( int $post_id ): ?string {
		$terms = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY, array( 'fields' => 'slugs' ) );

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
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$recipe_ids = array();

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
			$blocks = parse_blocks( $post->post_content );
			$recipe_ids = array_merge( $recipe_ids, $this->find_recipe_blocks( $blocks ) );
		}

		// Method 3: Check WPRM's own tracking (if available).
		if ( function_exists( 'WPRM_Recipe_Manager::get_recipe_ids_from_post' ) ) {
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
	private function find_recipe_blocks( array $blocks ): array {
		$recipe_ids = array();

		foreach ( $blocks as $block ) {
			// Check for WPRM recipe block.
			if ( 'wp-recipe-maker/recipe' === $block['blockName'] ) {
				if ( ! empty( $block['attrs']['id'] ) ) {
					$recipe_ids[] = (int) $block['attrs']['id'];
				}
			}

			// Check inner blocks recursively.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$recipe_ids = array_merge( $recipe_ids, $this->find_recipe_blocks( $block['innerBlocks'] ) );
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
	private function get_wprm_recipe( int $recipe_id ): ?array {
		$recipe_post = get_post( $recipe_id );

		if ( ! $recipe_post || 'wprm_recipe' !== $recipe_post->post_type ) {
			return null;
		}

		// Use WPRM's recipe object if available.
		if ( class_exists( 'WPRM_Recipe_Manager' ) && method_exists( 'WPRM_Recipe_Manager', 'get_recipe' ) ) {
			$recipe = \WPRM_Recipe_Manager::get_recipe( $recipe_id );
			if ( $recipe ) {
				return array(
					'id'          => $recipe_id,
					'name'        => $recipe->name(),
					'servings'    => $recipe->servings(),
					'servings_unit' => $recipe->servings_unit(),
					'prep_time'   => $recipe->prep_time(),
					'cook_time'   => $recipe->cook_time(),
					'total_time'  => $recipe->total_time(),
					'image_id'    => $recipe->image_id(),
				);
			}
		}

		// Fallback: Read meta directly.
		return array(
			'id'          => $recipe_id,
			'name'        => $recipe_post->post_title,
			'servings'    => get_post_meta( $recipe_id, 'wprm_servings', true ),
			'servings_unit' => get_post_meta( $recipe_id, 'wprm_servings_unit', true ),
			'prep_time'   => get_post_meta( $recipe_id, 'wprm_prep_time', true ),
			'cook_time'   => get_post_meta( $recipe_id, 'wprm_cook_time', true ),
			'total_time'  => get_post_meta( $recipe_id, 'wprm_total_time', true ),
			'image_id'    => get_post_thumbnail_id( $recipe_id ),
		);
	}

	/**
	 * Get recipe servings string.
	 *
	 * @param array<string, mixed> $recipe Recipe data.
	 * @return string Servings string (e.g., "4 servings").
	 */
	private function get_recipe_servings( array $recipe ): string {
		$servings = $recipe['servings'] ?? '';
		$unit     = $recipe['servings_unit'] ?? 'servings';

		if ( empty( $servings ) ) {
			return '';
		}

		return trim( $servings . ' ' . $unit );
	}

	/**
	 * Get recipe duration in ISO 8601 format.
	 *
	 * @param array<string, mixed> $recipe Recipe data.
	 * @return string ISO 8601 duration (e.g., "PT1H30M").
	 */
	private function get_recipe_duration( array $recipe ): string {
		$total_time = (int) ( $recipe['total_time'] ?? 0 );

		if ( $total_time <= 0 ) {
			// Calculate from prep + cook time.
			$prep_time = (int) ( $recipe['prep_time'] ?? 0 );
			$cook_time = (int) ( $recipe['cook_time'] ?? 0 );
			$total_time = $prep_time + $cook_time;
		}

		if ( $total_time <= 0 ) {
			return '';
		}

		// Convert minutes to ISO 8601 duration.
		$hours   = floor( $total_time / 60 );
		$minutes = $total_time % 60;

		$duration = 'PT';
		if ( $hours > 0 ) {
			$duration .= $hours . 'H';
		}
		if ( $minutes > 0 ) {
			$duration .= $minutes . 'M';
		}

		return $duration;
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
						'" . esc_js( __( 'This post contains a recipe. Consider setting the post kind to \"Recipe\".', 'reactions-for-indieweb' ) ) . "',
						{
							id: 'reactions-recipe-suggestion',
							isDismissible: true,
							actions: [
								{
									label: '" . esc_js( __( 'Set Recipe Kind', 'reactions-for-indieweb' ) ) . "',
									onClick: function() {
										// Find recipe term ID and set it
										wp.apiFetch({ path: '/wp/v2/kind?slug=recipe' }).then( function( terms ) {
											if ( terms && terms.length ) {
												dispatch( 'core/editor' ).editPost({ kind: [ terms[0].id ] });
												dispatch( 'core/notices' ).removeNotice( 'reactions-recipe-suggestion' );
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
			'reactions-indieweb/v1',
			'/post/(?P<id>\d+)/has-recipe',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_check_recipe' ),
				'permission_callback' => function() {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => function( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
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
		$recipe_ids = $has_recipe ? $this->get_recipe_ids_in_post( $post_id ) : array();

		return new \WP_REST_Response(
			array(
				'has_recipe'  => $has_recipe,
				'recipe_ids'  => $recipe_ids,
				'recipe_data' => $has_recipe && ! empty( $recipe_ids ) ? $this->get_wprm_recipe( $recipe_ids[0] ) : null,
			),
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

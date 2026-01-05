<?php
/**
 * Reaction Custom Post Type Registration
 *
 * Registers the 'reaction' post type for imported content when CPT mode is enabled.
 *
 * @package ReactionsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post Type registration class.
 *
 * @since 1.1.0
 */
class Post_Type {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'reaction';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_register_post_type' ), 5 );
	}

	/**
	 * Conditionally register the reaction post type.
	 *
	 * @return void
	 */
	public function maybe_register_post_type(): void {
		if ( ! self::is_cpt_mode() ) {
			return;
		}

		$this->register_post_type();
	}

	/**
	 * Register the reaction post type.
	 *
	 * @return void
	 */
	private function register_post_type(): void {
		$labels = array(
			'name'                  => _x( 'Reactions', 'post type general name', 'reactions-indieweb' ),
			'singular_name'         => _x( 'Reaction', 'post type singular name', 'reactions-indieweb' ),
			'menu_name'             => _x( 'Reactions', 'admin menu', 'reactions-indieweb' ),
			'name_admin_bar'        => _x( 'Reaction', 'add new on admin bar', 'reactions-indieweb' ),
			'add_new'               => __( 'Add New', 'reactions-indieweb' ),
			'add_new_item'          => __( 'Add New Reaction', 'reactions-indieweb' ),
			'new_item'              => __( 'New Reaction', 'reactions-indieweb' ),
			'edit_item'             => __( 'Edit Reaction', 'reactions-indieweb' ),
			'view_item'             => __( 'View Reaction', 'reactions-indieweb' ),
			'all_items'             => __( 'All Reactions', 'reactions-indieweb' ),
			'search_items'          => __( 'Search Reactions', 'reactions-indieweb' ),
			'parent_item_colon'     => __( 'Parent Reaction:', 'reactions-indieweb' ),
			'not_found'             => __( 'No reactions found.', 'reactions-indieweb' ),
			'not_found_in_trash'    => __( 'No reactions found in Trash.', 'reactions-indieweb' ),
			'archives'              => __( 'Reaction Archives', 'reactions-indieweb' ),
			'filter_items_list'     => __( 'Filter reactions list', 'reactions-indieweb' ),
			'items_list_navigation' => __( 'Reactions list navigation', 'reactions-indieweb' ),
			'items_list'            => __( 'Reactions list', 'reactions-indieweb' ),
		);

		$args = array(
			'labels'              => $labels,
			'description'         => __( 'Imported reactions from external services.', 'reactions-indieweb' ),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => true,
			'show_in_rest'        => true,
			'rest_base'           => 'reactions',
			'query_var'           => true,
			'rewrite'             => array(
				'slug'       => 'reactions',
				'with_front' => false,
			),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'has_archive'         => true,
			'hierarchical'        => false,
			'menu_position'       => 5,
			'menu_icon'           => 'dashicons-heart',
			'supports'            => array(
				'title',
				'editor',
				'author',
				'thumbnail',
				'excerpt',
				'custom-fields',
				'comments',
			),
			'taxonomies'          => array( 'kind' ),
		);

		/**
		 * Filters the reaction post type arguments.
		 *
		 * @since 1.1.0
		 *
		 * @param array $args Post type arguments.
		 */
		$args = apply_filters( 'reactions_indieweb_post_type_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Check if CPT mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_cpt_mode(): bool {
		$settings = get_option( 'reactions_indieweb_settings', array() );
		return ( $settings['import_storage_mode'] ?? 'standard' ) === 'cpt';
	}

	/**
	 * Check if hidden mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_hidden_mode(): bool {
		$settings = get_option( 'reactions_indieweb_settings', array() );
		return ( $settings['import_storage_mode'] ?? 'standard' ) === 'hidden';
	}

	/**
	 * Get the current storage mode.
	 *
	 * @return string One of 'standard', 'cpt', or 'hidden'.
	 */
	public static function get_storage_mode(): string {
		$settings = get_option( 'reactions_indieweb_settings', array() );
		$mode     = $settings['import_storage_mode'] ?? 'standard';

		// Validate mode.
		if ( ! in_array( $mode, array( 'standard', 'cpt', 'hidden' ), true ) ) {
			return 'standard';
		}

		return $mode;
	}

	/**
	 * Get the post type to use for imports.
	 *
	 * @return string Post type slug.
	 */
	public static function get_import_post_type(): string {
		return self::is_cpt_mode() ? self::POST_TYPE : 'post';
	}
}

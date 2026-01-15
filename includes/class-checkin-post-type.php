<?php
/**
 * Checkin Post Type
 *
 * Registers the optional checkin custom post type when enabled in settings.
 *
 * @package PostKindsForIndieWeb
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkin Post Type class.
 *
 * @since 1.2.0
 */
class Checkin_Post_Type {

	/**
	 * Post type name.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'checkin';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'flush_rewrite_rules_maybe' ], 99 );
	}

	/**
	 * Register the checkin post type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$labels = [
			'name'                  => _x( 'Check-ins', 'Post type general name', 'post-kinds-for-indieweb' ),
			'singular_name'         => _x( 'Check-in', 'Post type singular name', 'post-kinds-for-indieweb' ),
			'menu_name'             => _x( 'Check-ins', 'Admin Menu text', 'post-kinds-for-indieweb' ),
			'name_admin_bar'        => _x( 'Check-in', 'Add New on Toolbar', 'post-kinds-for-indieweb' ),
			'add_new'               => __( 'Add New', 'post-kinds-for-indieweb' ),
			'add_new_item'          => __( 'Add New Check-in', 'post-kinds-for-indieweb' ),
			'new_item'              => __( 'New Check-in', 'post-kinds-for-indieweb' ),
			'edit_item'             => __( 'Edit Check-in', 'post-kinds-for-indieweb' ),
			'view_item'             => __( 'View Check-in', 'post-kinds-for-indieweb' ),
			'all_items'             => __( 'All Check-ins', 'post-kinds-for-indieweb' ),
			'search_items'          => __( 'Search Check-ins', 'post-kinds-for-indieweb' ),
			'parent_item_colon'     => __( 'Parent Check-ins:', 'post-kinds-for-indieweb' ),
			'not_found'             => __( 'No check-ins found.', 'post-kinds-for-indieweb' ),
			'not_found_in_trash'    => __( 'No check-ins found in Trash.', 'post-kinds-for-indieweb' ),
			'featured_image'        => _x( 'Check-in Photo', 'Overrides the "Featured Image" phrase', 'post-kinds-for-indieweb' ),
			'set_featured_image'    => _x( 'Set check-in photo', 'Overrides the "Set featured image" phrase', 'post-kinds-for-indieweb' ),
			'remove_featured_image' => _x( 'Remove check-in photo', 'Overrides the "Remove featured image" phrase', 'post-kinds-for-indieweb' ),
			'use_featured_image'    => _x( 'Use as check-in photo', 'Overrides the "Use as featured image" phrase', 'post-kinds-for-indieweb' ),
			'archives'              => _x( 'Check-in archives', 'The post type archive label used in nav menus', 'post-kinds-for-indieweb' ),
			'insert_into_item'      => _x( 'Insert into check-in', 'Overrides the "Insert into post" phrase', 'post-kinds-for-indieweb' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this check-in', 'Overrides the "Uploaded to this post" phrase', 'post-kinds-for-indieweb' ),
			'filter_items_list'     => _x( 'Filter check-ins list', 'Screen reader text', 'post-kinds-for-indieweb' ),
			'items_list_navigation' => _x( 'Check-ins list navigation', 'Screen reader text', 'post-kinds-for-indieweb' ),
			'items_list'            => _x( 'Check-ins list', 'Screen reader text', 'post-kinds-for-indieweb' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => [
				'slug'       => 'checkins',
				'with_front' => false,
			],
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => 5,
			'menu_icon'          => 'dashicons-location-alt',
			'show_in_rest'       => true,
			'supports'           => [
				'title',
				'editor',
				'author',
				'thumbnail',
				'excerpt',
				'custom-fields',
				'comments',
			],
			'taxonomies'         => [ 'venue' ],
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Flush rewrite rules when needed.
	 *
	 * @return void
	 */
	public function flush_rewrite_rules_maybe(): void {
		if ( get_option( 'post_kinds_checkin_cpt_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'post_kinds_checkin_cpt_flush_rewrite' );
		}
	}

	/**
	 * Check if checkin CPT is enabled.
	 *
	 * @return bool True if enabled.
	 */
	public static function is_enabled(): bool {
		$options = get_option( 'post_kinds_indieweb_settings', [] );
		return ! empty( $options['enable_checkin_cpt'] );
	}

	/**
	 * Schedule rewrite rules flush.
	 *
	 * Called when settings are changed.
	 *
	 * @return void
	 */
	public static function schedule_flush(): void {
		update_option( 'post_kinds_checkin_cpt_flush_rewrite', true );
	}
}

<?php
/**
 * Kind Taxonomy Registration
 *
 * Registers the 'kind' taxonomy for categorizing posts by IndieWeb post types.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomy registration class.
 *
 * Handles registration of the 'kind' taxonomy and creation of default terms.
 *
 * @since 1.0.0
 */
class Taxonomy {

	/**
	 * Taxonomy slug.
	 *
	 * @var string
	 */
	public const TAXONOMY = 'kind';

	/**
	 * Default post types to register taxonomy for.
	 *
	 * @var array<string>
	 */
	private array $post_types = array( 'post' );

	/**
	 * Default kind terms with their properties.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $default_kinds = array(
		'note'     => array(
			'name'        => 'Note',
			'description' => 'Short, untitled post similar to a tweet or status update.',
		),
		'article'  => array(
			'name'        => 'Article',
			'description' => 'Long-form content with a title, like a blog post or essay.',
		),
		'reply'    => array(
			'name'        => 'Reply',
			'description' => 'Response to external content on another website.',
		),
		'like'     => array(
			'name'        => 'Like',
			'description' => 'Appreciation or approval of external content.',
		),
		'repost'   => array(
			'name'        => 'Repost',
			'description' => 'Reshare of external content with attribution.',
		),
		'bookmark' => array(
			'name'        => 'Bookmark',
			'description' => 'Saved link with optional annotation.',
		),
		'rsvp'     => array(
			'name'        => 'RSVP',
			'description' => 'Response to an event invitation (yes, no, maybe, interested).',
		),
		'checkin'  => array(
			'name'        => 'Check-in',
			'description' => 'Location check-in at a venue or place.',
		),
		'listen'   => array(
			'name'        => 'Listen',
			'description' => 'Music or podcast listening log (scrobble).',
		),
		'watch'    => array(
			'name'        => 'Watch',
			'description' => 'Film or TV show watching log.',
		),
		'read'     => array(
			'name'        => 'Read',
			'description' => 'Book or article reading progress and log.',
		),
		'event'    => array(
			'name'        => 'Event',
			'description' => 'Event announcement with date, time, and location.',
		),
		'photo'    => array(
			'name'        => 'Photo',
			'description' => 'Image-centric post, like a photo gallery.',
		),
		'video'    => array(
			'name'        => 'Video',
			'description' => 'Video-centric post.',
		),
		'review'   => array(
			'name'        => 'Review',
			'description' => 'Rating and evaluation of an item, place, or service.',
		),
	);

	/**
	 * Constructor.
	 *
	 * Sets up hooks for taxonomy registration.
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
		add_action( 'init', array( $this, 'register_taxonomy' ), 5 );
		add_action( 'init', array( $this, 'maybe_create_default_terms' ), 10 );
		add_filter( 'term_link', array( $this, 'filter_term_link' ), 10, 3 );
	}

	/**
	 * Register the 'kind' taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		// Check if CPT mode is enabled and add reaction post type.
		$settings     = get_option( 'reactions_indieweb_settings', array() );
		$storage_mode = $settings['import_storage_mode'] ?? 'standard';

		if ( 'cpt' === $storage_mode ) {
			$this->post_types[] = 'reaction';
		}

		/**
		 * Filters the post types that the kind taxonomy is registered for.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string> $post_types Array of post type slugs.
		 */
		$this->post_types = apply_filters( 'reactions_indieweb_kind_post_types', $this->post_types );

		$labels = array(
			'name'                       => _x( 'Kinds', 'taxonomy general name', 'reactions-indieweb' ),
			'singular_name'              => _x( 'Kind', 'taxonomy singular name', 'reactions-indieweb' ),
			'search_items'               => __( 'Search Kinds', 'reactions-indieweb' ),
			'popular_items'              => __( 'Popular Kinds', 'reactions-indieweb' ),
			'all_items'                  => __( 'All Kinds', 'reactions-indieweb' ),
			'parent_item'                => null,
			'parent_item_colon'          => null,
			'edit_item'                  => __( 'Edit Kind', 'reactions-indieweb' ),
			'update_item'                => __( 'Update Kind', 'reactions-indieweb' ),
			'add_new_item'               => __( 'Add New Kind', 'reactions-indieweb' ),
			'new_item_name'              => __( 'New Kind Name', 'reactions-indieweb' ),
			'separate_items_with_commas' => __( 'Separate kinds with commas', 'reactions-indieweb' ),
			'add_or_remove_items'        => __( 'Add or remove kinds', 'reactions-indieweb' ),
			'choose_from_most_used'      => __( 'Choose from the most used kinds', 'reactions-indieweb' ),
			'not_found'                  => __( 'No kinds found.', 'reactions-indieweb' ),
			'menu_name'                  => __( 'Kinds', 'reactions-indieweb' ),
			'back_to_items'              => __( '&larr; Back to Kinds', 'reactions-indieweb' ),
			'item_link'                  => __( 'Kind Link', 'reactions-indieweb' ),
			'item_link_description'      => __( 'A link to a kind archive.', 'reactions-indieweb' ),
		);

		$args = array(
			'labels'                => $labels,
			'description'           => __( 'IndieWeb post kinds for categorizing content types.', 'reactions-indieweb' ),
			'public'                => true,
			'publicly_queryable'    => true,
			'hierarchical'          => false,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'show_in_nav_menus'     => true,
			'show_in_rest'          => true,
			'rest_base'             => 'kind',
			'rest_controller_class' => 'WP_REST_Terms_Controller',
			'show_tagcloud'         => false,
			'show_in_quick_edit'    => true,
			'show_admin_column'     => true,
			'query_var'             => 'kind',
			'rewrite'               => array(
				'slug'         => 'kind',
				'with_front'   => false,
				'hierarchical' => false,
			),
			'capabilities'          => array(
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_posts',
			),
			'default_term'          => array(
				'name'        => 'Note',
				'slug'        => 'note',
				'description' => 'Short, untitled post similar to a tweet or status update.',
			),
		);

		/**
		 * Filters the taxonomy registration arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args Taxonomy registration arguments.
		 */
		$args = apply_filters( 'reactions_indieweb_taxonomy_args', $args );

		register_taxonomy( self::TAXONOMY, $this->post_types, $args );
	}

	/**
	 * Create default terms on first activation.
	 *
	 * @return void
	 */
	public function maybe_create_default_terms(): void {
		// Only run once after activation.
		if ( ! get_option( 'reactions_indieweb_terms_created' ) ) {
			$this->create_default_terms();
			update_option( 'reactions_indieweb_terms_created', true );
		}
	}

	/**
	 * Create all default kind terms.
	 *
	 * @return void
	 */
	public function create_default_terms(): void {
		/**
		 * Filters the default kind terms.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, string>> $default_kinds Array of kind slugs and their properties.
		 */
		$kinds = apply_filters( 'reactions_indieweb_default_kinds', $this->default_kinds );

		foreach ( $kinds as $slug => $kind_data ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term(
					$kind_data['name'],
					self::TAXONOMY,
					array(
						'slug'        => $slug,
						'description' => $kind_data['description'],
					)
				);
			}
		}

		// Flush rewrite rules after creating terms.
		flush_rewrite_rules();
	}

	/**
	 * Filter term links for kind taxonomy.
	 *
	 * Ensures proper URL structure for kind archives.
	 *
	 * @param string   $termlink Term link URL.
	 * @param \WP_Term $term     Term object.
	 * @param string   $taxonomy Taxonomy slug.
	 * @return string Modified term link.
	 */
	public function filter_term_link( string $termlink, \WP_Term $term, string $taxonomy ): string {
		if ( self::TAXONOMY !== $taxonomy ) {
			return $termlink;
		}

		/**
		 * Filters the kind term link.
		 *
		 * @since 1.0.0
		 *
		 * @param string   $termlink The term link URL.
		 * @param \WP_Term $term     The term object.
		 */
		return apply_filters( 'reactions_indieweb_kind_link', $termlink, $term );
	}

	/**
	 * Get all registered kind terms.
	 *
	 * @return array<\WP_Term> Array of term objects.
	 */
	public function get_kinds(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Get the kind for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Term|null The kind term or null if not set.
	 */
	public function get_post_kind( int $post_id ): ?\WP_Term {
		$terms = wp_get_post_terms( $post_id, self::TAXONOMY );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Set the kind for a specific post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind    Kind slug.
	 * @return bool True on success, false on failure.
	 */
	public function set_post_kind( int $post_id, string $kind ): bool {
		$result = wp_set_post_terms( $post_id, array( $kind ), self::TAXONOMY );

		return ! is_wp_error( $result );
	}

	/**
	 * Get default kinds configuration.
	 *
	 * @return array<string, array<string, string>> Default kinds array.
	 */
	public function get_default_kinds(): array {
		return $this->default_kinds;
	}

	/**
	 * Check if a kind slug is valid.
	 *
	 * @param string $kind Kind slug to check.
	 * @return bool True if valid, false otherwise.
	 */
	public function is_valid_kind( string $kind ): bool {
		return term_exists( $kind, self::TAXONOMY ) !== null;
	}

	/**
	 * Get the post types registered for this taxonomy.
	 *
	 * @return array<string> Array of post type slugs.
	 */
	public function get_post_types(): array {
		return $this->post_types;
	}
}

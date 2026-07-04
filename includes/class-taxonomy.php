<?php
/**
 * Kind Taxonomy Registration
 *
 * Registers the 'kind' taxonomy for categorizing posts by IndieWeb post types.
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
	 * Map of kind card block names to their kind term slugs.
	 *
	 * Shared contract for every path that infers a kind from block
	 * content (editor saves here, Micropub bridge output upstream).
	 *
	 * @var array<string, string>
	 */
	public const KIND_CARD_BLOCKS = [
		'post-kinds-indieweb/eat-card'         => 'eat',
		'post-kinds-indieweb/drink-card'       => 'drink',
		'post-kinds-indieweb/listen-card'      => 'listen',
		'post-kinds-indieweb/watch-card'       => 'watch',
		'post-kinds-indieweb/read-card'        => 'read',
		'post-kinds-indieweb/play-card'        => 'play',
		'post-kinds-indieweb/checkin-card'     => 'checkin',
		'post-kinds-indieweb/rsvp-card'        => 'rsvp',
		'post-kinds-indieweb/like-card'        => 'like',
		'post-kinds-indieweb/repost-card'      => 'repost',
		'post-kinds-indieweb/bookmark-card'    => 'bookmark',
		'post-kinds-indieweb/reply-card'       => 'reply',
		'post-kinds-indieweb/favorite-card'    => 'favorite',
		'post-kinds-indieweb/jam-card'         => 'jam',
		'post-kinds-indieweb/wish-card'        => 'wish',
		'post-kinds-indieweb/mood-card'        => 'mood',
		'post-kinds-indieweb/acquisition-card' => 'acquisition',
	];

	/**
	 * Meta key recording a kind slug this class assigned automatically.
	 *
	 * Lets a later save re-sync when the first card block changes, while
	 * never overriding a kind a person picked themselves.
	 *
	 * @var string
	 */
	public const AUTO_KIND_META_KEY = '_pkiw_kind_auto_assigned';

	/**
	 * Default post types to register taxonomy for.
	 *
	 * @var array<string>
	 */
	private array $post_types = [ 'post' ];

	/**
	 * Default kind terms with their properties.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $default_kinds = [
		'note'        => [
			'name'        => 'Note',
			'description' => 'Short, untitled post similar to a tweet or status update.',
		],
		'article'     => [
			'name'        => 'Article',
			'description' => 'Long-form content with a title, like a blog post or essay.',
		],
		'reply'       => [
			'name'        => 'Reply',
			'description' => 'Response to external content on another website.',
		],
		'like'        => [
			'name'        => 'Like',
			'description' => 'Appreciation or approval of external content.',
		],
		'repost'      => [
			'name'        => 'Repost',
			'description' => 'Reshare of external content with attribution.',
		],
		'bookmark'    => [
			'name'        => 'Bookmark',
			'description' => 'Saved link with optional annotation.',
		],
		'rsvp'        => [
			'name'        => 'RSVP',
			'description' => 'Response to an event invitation (yes, no, maybe, interested).',
		],
		'checkin'     => [
			'name'        => 'Check-in',
			'description' => 'Location check-in at a venue or place.',
		],
		'listen'      => [
			'name'        => 'Listen',
			'description' => 'Music or podcast listening log (scrobble).',
		],
		'watch'       => [
			'name'        => 'Watch',
			'description' => 'Film or TV show watching log.',
		],
		'read'        => [
			'name'        => 'Read',
			'description' => 'Book or article reading progress and log.',
		],
		'event'       => [
			'name'        => 'Event',
			'description' => 'Event announcement with date, time, and location.',
		],
		'photo'       => [
			'name'        => 'Photo',
			'description' => 'Image-centric post, like a photo gallery.',
		],
		'video'       => [
			'name'        => 'Video',
			'description' => 'Video-centric post.',
		],
		'review'      => [
			'name'        => 'Review',
			'description' => 'Rating and evaluation of an item, place, or service.',
		],
		'favorite'    => [
			'name'        => 'Favorite',
			'description' => 'Starred or saved item for later reference.',
		],
		'jam'         => [
			'name'        => 'Jam',
			'description' => 'Current music highlight - "this is my jam right now."',
		],
		'wish'        => [
			'name'        => 'Wish',
			'description' => 'Wishlist item you want to read, watch, buy, or experience.',
		],
		'mood'        => [
			'name'        => 'Mood',
			'description' => 'Emotional state or feeling.',
		],
		'acquisition' => [
			'name'        => 'Acquisition',
			'description' => 'Item you acquired or added to your collection.',
		],
		'drink'       => [
			'name'        => 'Drink',
			'description' => 'Beverage log - coffee, beer, wine, cocktails.',
		],
		'eat'         => [
			'name'        => 'Eat',
			'description' => 'Food or meal log.',
		],
		'recipe'      => [
			'name'        => 'Recipe',
			'description' => 'Food recipe with ingredients and instructions.',
		],
		'play'        => [
			'name'        => 'Play',
			'description' => 'Video game, board game, or other game play log.',
		],
	];

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
		add_action( 'init', [ $this, 'register_taxonomy' ], 5 );
		add_action( 'init', [ $this, 'maybe_create_default_terms' ], 10 );
		add_action( 'init', [ $this, 'ensure_all_terms_exist' ], 11 );
		add_filter( 'term_link', [ $this, 'filter_term_link' ], 10, 3 );
		// wp_after_insert_post (not save_post): the REST posts controller
		// assigns taxonomy terms after wp_insert_post(), so save_post would
		// read the pre-save terms during block editor saves.
		add_action( 'wp_after_insert_post', [ $this, 'sync_kind_from_first_block' ], 10, 2 );
	}

	/**
	 * Register the 'kind' taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		// Check if CPT mode is enabled and add reaction post type.
		$settings     = get_option( 'post_kinds_indieweb_settings', [] );
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
		$this->post_types = apply_filters( 'post_kinds_indieweb_kind_post_types', $this->post_types );

		$labels = [
			'name'                       => _x( 'Kinds', 'taxonomy general name', 'post-kinds-for-indieweb' ),
			'singular_name'              => _x( 'Kind', 'taxonomy singular name', 'post-kinds-for-indieweb' ),
			'search_items'               => __( 'Search Kinds', 'post-kinds-for-indieweb' ),
			'popular_items'              => __( 'Popular Kinds', 'post-kinds-for-indieweb' ),
			'all_items'                  => __( 'All Kinds', 'post-kinds-for-indieweb' ),
			'parent_item'                => null,
			'parent_item_colon'          => null,
			'edit_item'                  => __( 'Edit Kind', 'post-kinds-for-indieweb' ),
			'update_item'                => __( 'Update Kind', 'post-kinds-for-indieweb' ),
			'add_new_item'               => __( 'Add New Kind', 'post-kinds-for-indieweb' ),
			'new_item_name'              => __( 'New Kind Name', 'post-kinds-for-indieweb' ),
			'separate_items_with_commas' => __( 'Separate kinds with commas', 'post-kinds-for-indieweb' ),
			'add_or_remove_items'        => __( 'Add or remove kinds', 'post-kinds-for-indieweb' ),
			'choose_from_most_used'      => __( 'Choose from the most used kinds', 'post-kinds-for-indieweb' ),
			'not_found'                  => __( 'No kinds found.', 'post-kinds-for-indieweb' ),
			'menu_name'                  => __( 'Kinds', 'post-kinds-for-indieweb' ),
			'back_to_items'              => __( '&larr; Back to Kinds', 'post-kinds-for-indieweb' ),
			'item_link'                  => __( 'Kind Link', 'post-kinds-for-indieweb' ),
			'item_link_description'      => __( 'A link to a kind archive.', 'post-kinds-for-indieweb' ),
		];

		$args = [
			'labels'                => $labels,
			'description'           => __( 'IndieWeb post kinds for categorizing content types.', 'post-kinds-for-indieweb' ),
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
			'rewrite'               => [
				'slug'         => 'kind',
				'with_front'   => false,
				'hierarchical' => false,
			],
			'capabilities'          => [
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_posts',
			],
			'default_term'          => [
				'name'        => 'Note',
				'slug'        => 'note',
				'description' => 'Short, untitled post similar to a tweet or status update.',
			],
		];

		/**
		 * Filters the taxonomy registration arguments.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $args Taxonomy registration arguments.
		 */
		$args = apply_filters( 'post_kinds_indieweb_taxonomy_args', $args );

		register_taxonomy( self::TAXONOMY, $this->post_types, $args );
	}

	/**
	 * Create default terms on first activation.
	 *
	 * @return void
	 */
	public function maybe_create_default_terms(): void {
		// Only run once after activation.
		if ( ! get_option( 'post_kinds_indieweb_terms_created' ) ) {
			$this->create_default_terms();
			update_option( 'post_kinds_indieweb_terms_created', true );
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
		$kinds = apply_filters( 'post_kinds_indieweb_default_kinds', $this->default_kinds );

		foreach ( $kinds as $slug => $kind_data ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term(
					$kind_data['name'],
					self::TAXONOMY,
					[
						'slug'        => $slug,
						'description' => $kind_data['description'],
					]
				);
			}
		}

		// Flush rewrite rules after creating terms.
		flush_rewrite_rules();
	}

	/**
	 * Ensure all default terms exist.
	 *
	 * This runs on every init to catch any terms added in plugin updates.
	 *
	 * @return void
	 */
	public function ensure_all_terms_exist(): void {
		// Only run in admin to avoid frontend performance hit.
		if ( ! is_admin() ) {
			return;
		}

		// Check version to only run once per plugin version.
		$version_key     = 'post_kinds_indieweb_terms_version';
		$current_version = get_option( $version_key, '0' );

		if ( version_compare( $current_version, POST_KINDS_INDIEWEB_VERSION, '>=' ) ) {
			return;
		}

		// Create any missing terms.
		foreach ( $this->default_kinds as $slug => $kind_data ) {
			if ( ! term_exists( $slug, self::TAXONOMY ) ) {
				wp_insert_term(
					$kind_data['name'],
					self::TAXONOMY,
					[
						'slug'        => $slug,
						'description' => $kind_data['description'],
					]
				);
			}
		}

		update_option( $version_key, POST_KINDS_INDIEWEB_VERSION );
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
		return apply_filters( 'post_kinds_indieweb_kind_link', $termlink, $term );
	}

	/**
	 * Get all registered kind terms.
	 *
	 * @return array<\WP_Term> Array of term objects.
	 */
	public function get_kinds(): array {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return [];
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
		$result = wp_set_post_terms( $post_id, [ $kind ], self::TAXONOMY );

		return ! is_wp_error( $result );
	}

	/**
	 * Detect the kind implied by the first block of some post content.
	 *
	 * Skips the null-blockName freeform blocks parse_blocks() emits for
	 * whitespace between block comments, so "first block" means the first
	 * real block a person sees in the editor.
	 *
	 * @param string $content Raw post content.
	 * @return string|null Kind slug, or null when the first block is not a kind card.
	 */
	public static function get_first_block_kind( string $content ): ?string {
		if ( '' === trim( $content ) || ! has_blocks( $content ) ) {
			return null;
		}

		foreach ( parse_blocks( $content ) as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}

			return self::KIND_CARD_BLOCKS[ $block['blockName'] ] ?? null;
		}

		return null;
	}

	/**
	 * Assign the kind term matching the post's first kind card block.
	 *
	 * A post whose first block is a kind card (eat-card, listen-card, …)
	 * should carry the matching kind term without the author also having
	 * to pick it in the taxonomy panel. Manual choices always win: the
	 * `note` default_term core stamps on unselected posts is the only
	 * term treated as "not chosen", plus whatever this method itself set
	 * earlier (recorded in AUTO_KIND_META_KEY) so a changed first block
	 * re-syncs. The block editor sends explicit panel picks with the
	 * REST request, and those are applied before this hook fires.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function sync_kind_from_first_block( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! is_object_in_taxonomy( $post->post_type, self::TAXONOMY ) ) {
			return;
		}

		$kind = self::get_first_block_kind( $post->post_content );
		if ( null === $kind || ! $this->is_valid_kind( $kind ) ) {
			return;
		}

		$current = $this->get_post_kind( $post_id );
		if ( $current instanceof \WP_Term ) {
			if ( $current->slug === $kind ) {
				return;
			}

			$auto_assigned = get_post_meta( $post_id, self::AUTO_KIND_META_KEY, true );
			if ( 'note' !== $current->slug && $current->slug !== $auto_assigned ) {
				// A person picked this kind — never override it.
				return;
			}
		}

		if ( $this->set_post_kind( $post_id, $kind ) ) {
			update_post_meta( $post_id, self::AUTO_KIND_META_KEY, $kind );
		}
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

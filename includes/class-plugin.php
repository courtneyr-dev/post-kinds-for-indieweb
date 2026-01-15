<?php
/**
 * Main Plugin Orchestrator Class
 *
 * Initializes all plugin components and manages the plugin lifecycle.
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
 * Main Plugin class.
 *
 * Orchestrates the initialization of all plugin components and manages
 * integration with IndieBlocks and other IndieWeb plugins.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether IndieBlocks plugin is active.
	 *
	 * @var bool
	 */
	private bool $indieblocks_active = false;

	/**
	 * Whether Post Kinds plugin is active (conflict).
	 *
	 * @var bool
	 */
	private bool $post_kinds_conflict = false;

	/**
	 * Whether Bookmark Card plugin is active.
	 *
	 * @var bool
	 */
	private bool $bookmark_card_active = false;

	/**
	 * Taxonomy component instance.
	 *
	 * @var Taxonomy|null
	 */
	private ?Taxonomy $taxonomy = null;

	/**
	 * Meta Fields component instance.
	 *
	 * @var Meta_Fields|null
	 */
	private ?Meta_Fields $meta_fields = null;

	/**
	 * Block Bindings component instance.
	 *
	 * @var Block_Bindings|null
	 */
	private ?Block_Bindings $block_bindings = null;

	/**
	 * Microformats component instance.
	 *
	 * @var Microformats|null
	 */
	private ?Microformats $microformats = null;

	/**
	 * REST API component instance.
	 *
	 * @var REST_API|null
	 */
	private ?REST_API $rest_api = null;

	/**
	 * External APIs component instance.
	 *
	 * @var External_APIs|null
	 */
	private ?External_APIs $external_apis = null;

	/**
	 * Admin component instance.
	 *
	 * @var Admin\Admin|null
	 */
	private ?Admin\Admin $admin = null;

	/**
	 * Post Type component instance.
	 *
	 * @var Post_Type|null
	 */
	private ?Post_Type $post_type = null;

	/**
	 * Query Filter component instance.
	 *
	 * @var Query_Filter|null
	 */
	private ?Query_Filter $query_filter = null;

	/**
	 * Checkin sync services.
	 *
	 * @var array<Sync\Checkin_Sync_Base>
	 */
	private array $checkin_sync_services = [];

	/**
	 * Listen sync services.
	 *
	 * @var array<Sync\Listen_Sync_Base>
	 */
	private array $listen_sync_services = [];

	/**
	 * Watch sync services.
	 *
	 * @var array<Sync\Watch_Sync_Base>
	 */
	private array $watch_sync_services = [];

	/**
	 * Import Manager component instance.
	 *
	 * @var Import_Manager|null
	 */
	private ?Import_Manager $import_manager = null;

	/**
	 * Scheduled Sync component instance.
	 *
	 * @var Scheduled_Sync|null
	 */
	private ?Scheduled_Sync $scheduled_sync = null;

	/**
	 * WP Recipe Maker integration instance.
	 *
	 * @var Integrations\WP_Recipe_Maker|null
	 */
	private ?Integrations\WP_Recipe_Maker $wprm_integration = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin The singleton instance.
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton pattern.
	 */
	private function __construct() {
		// Singleton pattern - prevent direct instantiation.
	}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @return void
	 */
	private function __clone(): void {
		// Prevent cloning.
	}

	/**
	 * Prevent unserialization of the singleton.
	 *
	 * @throws \Exception If unserialization is attempted.
	 * @return void
	 */
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Initialize the plugin.
	 *
	 * Sets up all plugin components and hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Check for Post Kinds plugin conflict.
		if ( $this->detect_post_kinds_conflict() ) {
			add_action( 'admin_notices', [ $this, 'post_kinds_conflict_notice' ] );
			return; // Don't initialize - Post Kinds is active.
		}

		// Detect IndieBlocks presence.
		$this->detect_indieblocks();

		// Detect Bookmark Card plugin.
		$this->detect_bookmark_card();

		// Initialize components.
		$this->init_components();

		// Register hooks.
		$this->register_hooks();
	}

	/**
	 * Detect if Post Kinds plugin is active (conflict).
	 *
	 * Post Kinds and Post Kinds for IndieWeb both use the 'kind' taxonomy
	 * and provide similar functionality. Only one should be active.
	 *
	 * @return bool True if Post Kinds is active (conflict detected).
	 */
	private function detect_post_kinds_conflict(): bool {
		// Check for Post Kinds plugin file.
		$active_plugins = (array) get_option( 'active_plugins', [] );
		if ( in_array( 'indieweb-post-kinds/indieweb-post-kinds.php', $active_plugins, true ) ) {
			$this->post_kinds_conflict = true;
			return true;
		}

		// Check network-activated plugins for multisite.
		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );
			if ( isset( $network_plugins['indieweb-post-kinds/indieweb-post-kinds.php'] ) ) {
				$this->post_kinds_conflict = true;
				return true;
			}
		}

		// Check for Post Kinds main class.
		if ( class_exists( 'Kind_Taxonomy' ) ) {
			$this->post_kinds_conflict = true;
			return true;
		}

		return false;
	}

	/**
	 * Detect if IndieBlocks plugin is active.
	 *
	 * Checks for IndieBlocks presence to enable enhanced integration.
	 *
	 * @return void
	 */
	private function detect_indieblocks(): void {
		// Check using active_plugins option directly (works at plugins_loaded).
		$active_plugins = (array) get_option( 'active_plugins', [] );
		if ( in_array( 'indieblocks/indieblocks.php', $active_plugins, true ) ) {
			$this->indieblocks_active = true;
			return;
		}

		// Also check network-activated plugins for multisite.
		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );
			if ( isset( $network_plugins['indieblocks/indieblocks.php'] ) ) {
				$this->indieblocks_active = true;
				return;
			}
		}

		// Fallback: Check if IndieBlocks is active by looking for its main class.
		if ( class_exists( 'IndieBlocks\\IndieBlocks' ) ) {
			$this->indieblocks_active = true;
			return;
		}

		// Alternative check: see if IndieBlocks blocks are registered (runs later).
		add_action(
			'init',
			function (): void {
				if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'indieblocks/context' ) ) {
					$this->indieblocks_active = true;
				}
			},
			20
		);
	}

	/**
	 * Detect if Bookmark Card plugin is active.
	 *
	 * Checks for the Bookmark Card plugin (mamaduka/bookmark-card) to enable
	 * integration with the bookmark post kind.
	 *
	 * @return void
	 */
	private function detect_bookmark_card(): void {
		// Check using active_plugins option directly.
		$active_plugins = (array) get_option( 'active_plugins', [] );
		if ( in_array( 'bookmark-card/bookmark-card.php', $active_plugins, true ) ) {
			$this->bookmark_card_active = true;
			return;
		}

		// Check network-activated plugins for multisite.
		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );
			if ( isset( $network_plugins['bookmark-card/bookmark-card.php'] ) ) {
				$this->bookmark_card_active = true;
				return;
			}
		}

		// Alternative check: see if Bookmark Card block is registered (runs later).
		add_action(
			'init',
			function (): void {
				if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'mamaduka/bookmark-card' ) ) {
					$this->bookmark_card_active = true;
				}
			},
			20
		);
	}

	/**
	 * Check if Bookmark Card plugin is active.
	 *
	 * @return bool True if Bookmark Card is active.
	 */
	public function is_bookmark_card_active(): bool {
		return $this->bookmark_card_active;
	}

	/**
	 * Initialize all plugin components.
	 *
	 * Creates instances of all component classes.
	 *
	 * @return void
	 */
	private function init_components(): void {
		// Core components - always loaded.
		if ( class_exists( __NAMESPACE__ . '\\Taxonomy' ) ) {
			$this->taxonomy = new Taxonomy();
		}

		if ( class_exists( __NAMESPACE__ . '\\Meta_Fields' ) ) {
			$this->meta_fields = new Meta_Fields();
		}

		if ( class_exists( __NAMESPACE__ . '\\Block_Bindings' ) ) {
			$this->block_bindings = new Block_Bindings();
		}

		if ( class_exists( __NAMESPACE__ . '\\Microformats' ) ) {
			$this->microformats = new Microformats();
		}

		// REST API component.
		if ( class_exists( __NAMESPACE__ . '\\REST_API' ) ) {
			$this->rest_api = new REST_API();
		}

		// External APIs component.
		if ( class_exists( __NAMESPACE__ . '\\External_APIs' ) ) {
			$this->external_apis = new External_APIs();
		}

		// Admin component - only in admin context.
		if ( is_admin() && class_exists( __NAMESPACE__ . '\\Admin\\Admin' ) ) {
			$this->admin = new Admin\Admin( $this );
			$this->admin->init();
		}

		// Post Type component (for CPT mode).
		if ( class_exists( __NAMESPACE__ . '\\Post_Type' ) ) {
			$this->post_type = new Post_Type();
		}

		// Checkin Post Type component (optional dedicated CPT).
		if ( class_exists( __NAMESPACE__ . '\\Checkin_Post_Type' ) ) {
			new Checkin_Post_Type();
		}

		// Venue Taxonomy component.
		if ( class_exists( __NAMESPACE__ . '\\Venue_Taxonomy' ) ) {
			new Venue_Taxonomy();
		}

		// Query Filter component (for hidden mode).
		if ( class_exists( __NAMESPACE__ . '\\Query_Filter' ) ) {
			$this->query_filter = new Query_Filter();
		}

		// Initialize sync services.
		$this->init_checkin_sync_services();
		$this->init_listen_sync_services();
		$this->init_watch_sync_services();

		// Initialize import manager and scheduled sync.
		if ( class_exists( __NAMESPACE__ . '\\Import_Manager' ) ) {
			$this->import_manager = new Import_Manager();
		}

		if ( class_exists( __NAMESPACE__ . '\\Scheduled_Sync' ) && $this->import_manager ) {
			$this->scheduled_sync = new Scheduled_Sync( $this->import_manager );
			$this->scheduled_sync->init();
		}

		// Initialize third-party integrations.
		$this->init_integrations();
	}

	/**
	 * Initialize third-party plugin integrations.
	 *
	 * @return void
	 */
	private function init_integrations(): void {
		// WP Recipe Maker integration.
		if ( class_exists( __NAMESPACE__ . '\\Integrations\\WP_Recipe_Maker' ) ) {
			$this->wprm_integration = new Integrations\WP_Recipe_Maker();
		}

		/**
		 * Fires after third-party integrations are initialized.
		 *
		 * @since 1.1.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'post_kinds_indieweb_integrations_init', $this );
	}

	/**
	 * Initialize checkin synchronization services.
	 *
	 * Sets up bidirectional sync for supported services.
	 *
	 * @return void
	 */
	private function init_checkin_sync_services(): void {
		// Foursquare sync (venues/locations).
		if ( class_exists( __NAMESPACE__ . '\\Sync\\Foursquare_Checkin_Sync' ) ) {
			$foursquare_sync = new Sync\Foursquare_Checkin_Sync();
			$foursquare_sync->init();
			$this->checkin_sync_services['foursquare'] = $foursquare_sync;
		}

		// Untappd sync (beer checkins).
		if ( class_exists( __NAMESPACE__ . '\\Sync\\Untappd_Checkin_Sync' ) ) {
			$untappd_sync = new Sync\Untappd_Checkin_Sync();
			$untappd_sync->init();
			$this->checkin_sync_services['untappd'] = $untappd_sync;
		}

		// OwnTracks (self-hosted location tracking via webhook).
		if ( class_exists( __NAMESPACE__ . '\\Sync\\OwnTracks_Checkin_Sync' ) ) {
			$owntracks_sync = new Sync\OwnTracks_Checkin_Sync();
			$owntracks_sync->init();
			$this->checkin_sync_services['owntracks'] = $owntracks_sync;
		}

		/**
		 * Filter to add additional checkin sync services.
		 *
		 * @since 1.0.0
		 *
		 * @param array<Sync\Checkin_Sync_Base> $services Checkin sync services.
		 */
		$this->checkin_sync_services = apply_filters(
			'post_kinds_indieweb_checkin_sync_services',
			$this->checkin_sync_services
		);
	}

	/**
	 * Get a checkin sync service by ID.
	 *
	 * @param string $service_id Service ID.
	 * @return Sync\Checkin_Sync_Base|null
	 */
	public function get_checkin_sync_service( string $service_id ): ?Sync\Checkin_Sync_Base {
		return $this->checkin_sync_services[ $service_id ] ?? null;
	}

	/**
	 * Get all checkin sync services.
	 *
	 * @return array<Sync\Checkin_Sync_Base>
	 */
	public function get_checkin_sync_services(): array {
		return $this->checkin_sync_services;
	}

	/**
	 * Initialize listen synchronization services.
	 *
	 * Sets up POSSE for listen/scrobble posts.
	 *
	 * @return void
	 */
	private function init_listen_sync_services(): void {
		// Last.fm scrobble sync.
		if ( class_exists( __NAMESPACE__ . '\\Sync\\Lastfm_Listen_Sync' ) ) {
			$lastfm_sync = new Sync\Lastfm_Listen_Sync();
			$lastfm_sync->init();
			$this->listen_sync_services['lastfm'] = $lastfm_sync;
		}

		/**
		 * Filter to add additional listen sync services.
		 *
		 * @since 1.0.0
		 *
		 * @param array<Sync\Listen_Sync_Base> $services Listen sync services.
		 */
		$this->listen_sync_services = apply_filters(
			'post_kinds_indieweb_listen_sync_services',
			$this->listen_sync_services
		);
	}

	/**
	 * Get a listen sync service by ID.
	 *
	 * @param string $service_id Service ID.
	 * @return Sync\Listen_Sync_Base|null
	 */
	public function get_listen_sync_service( string $service_id ): ?Sync\Listen_Sync_Base {
		return $this->listen_sync_services[ $service_id ] ?? null;
	}

	/**
	 * Get all listen sync services.
	 *
	 * @return array<Sync\Listen_Sync_Base>
	 */
	public function get_listen_sync_services(): array {
		return $this->listen_sync_services;
	}

	/**
	 * Initialize watch synchronization services.
	 *
	 * Sets up POSSE for watch posts.
	 *
	 * @return void
	 */
	private function init_watch_sync_services(): void {
		// Trakt watch sync.
		if ( class_exists( __NAMESPACE__ . '\\Sync\\Trakt_Watch_Sync' ) ) {
			$trakt_sync = new Sync\Trakt_Watch_Sync();
			$trakt_sync->init();
			$this->watch_sync_services['trakt'] = $trakt_sync;
		}

		/**
		 * Filter to add additional watch sync services.
		 *
		 * @since 1.0.0
		 *
		 * @param array<Sync\Watch_Sync_Base> $services Watch sync services.
		 */
		$this->watch_sync_services = apply_filters(
			'post_kinds_indieweb_watch_sync_services',
			$this->watch_sync_services
		);
	}

	/**
	 * Get a watch sync service by ID.
	 *
	 * @param string $service_id Service ID.
	 * @return Sync\Watch_Sync_Base|null
	 */
	public function get_watch_sync_service( string $service_id ): ?Sync\Watch_Sync_Base {
		return $this->watch_sync_services[ $service_id ] ?? null;
	}

	/**
	 * Get all watch sync services.
	 *
	 * @return array<Sync\Watch_Sync_Base>
	 */
	public function get_watch_sync_services(): array {
		return $this->watch_sync_services;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Sets up actions and filters for the plugin.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Flush rewrite rules if needed (after storage mode change).
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 999 );

		// Register custom blocks.
		add_action( 'init', [ $this, 'register_blocks' ] );

		// Enqueue editor assets.
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );

		// Enqueue frontend block styles.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_block_styles' ] );

		// Register block patterns.
		add_action( 'init', [ $this, 'register_block_patterns' ] );

		// Register block templates for CPT and taxonomy archives.
		add_filter( 'get_block_templates', [ $this, 'add_plugin_templates' ], 10, 3 );
		add_filter( 'pre_get_block_file_template', [ $this, 'get_plugin_template' ], 10, 3 );

		// Add plugin action links.
		add_filter( 'plugin_action_links_' . \POST_KINDS_INDIEWEB_BASENAME, [ $this, 'add_action_links' ] );

		// Display admin notice if IndieBlocks is not active (check deferred to admin_notices time).
		add_action( 'admin_notices', [ $this, 'indieblocks_notice' ] );
	}

	/**
	 * Register custom Gutenberg blocks.
	 *
	 * Registers all custom blocks from the blocks directory.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		// Register block category.
		add_filter(
			'block_categories_all',
			function ( array $categories ): array {
				return array_merge(
					[
						[
							'slug'  => 'post-kinds-for-indieweb',
							'title' => __( 'Post Kinds for IndieWeb', 'post-kinds-for-indieweb' ),
							'icon'  => 'heart',
						],
					],
					$categories
				);
			}
		);

		// Define blocks to register.
		$blocks = [
			'listen-card',
			'watch-card',
			'read-card',
			'checkin-card',
			'rsvp-card',
			'play-card',
			'eat-card',
			'drink-card',
			'favorite-card',
			'jam-card',
			'wish-card',
			'mood-card',
			'acquisition-card',
			'checkin-dashboard',
			'checkins-feed',
			'venue-detail',
			'star-rating',
			'media-lookup',
		];

		// Enqueue blocks script first so it's available for registration.
		$blocks_asset_file = \POST_KINDS_INDIEWEB_PATH . 'build/blocks.asset.php';

		if ( file_exists( $blocks_asset_file ) ) {
			$blocks_asset = require $blocks_asset_file;

			wp_register_script(
				'post-kinds-indieweb-blocks',
				\POST_KINDS_INDIEWEB_URL . 'build/blocks.js',
				$blocks_asset['dependencies'],
				$blocks_asset['version'],
				true
			);

			wp_set_script_translations(
				'post-kinds-indieweb-blocks',
				'post-kinds-for-indieweb',
				\POST_KINDS_INDIEWEB_PATH . 'languages'
			);
		}

		// Register shared block styles for editor and frontend.
		$blocks_style_file = \POST_KINDS_INDIEWEB_PATH . 'build/blocks.css';

		if ( file_exists( $blocks_style_file ) ) {
			wp_register_style(
				'post-kinds-indieweb-blocks',
				\POST_KINDS_INDIEWEB_URL . 'build/blocks.css',
				[],
				filemtime( $blocks_style_file )
			);
		}

		// Register each block with the shared editor script and styles.
		foreach ( $blocks as $block ) {
			$block_dir = \POST_KINDS_INDIEWEB_PATH . 'src/blocks/' . $block;

			if ( file_exists( $block_dir . '/block.json' ) ) {
				register_block_type(
					$block_dir,
					[
						'editor_script' => 'post-kinds-indieweb-blocks',
						'editor_style'  => 'post-kinds-indieweb-blocks',
						'style'         => 'post-kinds-indieweb-blocks',
					]
				);
			}
		}
	}

	/**
	 * Enqueue frontend block styles.
	 *
	 * Loads CSS for blocks on the frontend. The style is already registered
	 * in register_blocks() and will be auto-loaded when blocks are present,
	 * but we also enqueue it globally for posts with saved block content.
	 *
	 * @return void
	 */
	public function enqueue_block_styles(): void {
		// Enqueue the already-registered block styles for the frontend.
		if ( wp_style_is( 'post-kinds-indieweb-blocks', 'registered' ) ) {
			wp_enqueue_style( 'post-kinds-indieweb-blocks' );
		}
	}

	/**
	 * Enqueue editor assets.
	 *
	 * Loads JavaScript and CSS for the block editor.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$asset_file = \POST_KINDS_INDIEWEB_PATH . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'post-kinds-indieweb-editor',
			\POST_KINDS_INDIEWEB_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'post-kinds-indieweb-editor',
			'post-kinds-for-indieweb',
			\POST_KINDS_INDIEWEB_PATH . 'languages'
		);

		// Get syndication services data.
		$syndication_services = $this->get_available_syndication_services();

		// Data to pass to JavaScript.
		$localize_data = [
			'indieBlocksActive'   => $this->indieblocks_active,
			'bookmarkCardActive'  => $this->bookmark_card_active,
			'restUrl'             => rest_url( 'post-kinds-indieweb/v1/' ),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'syndicationServices' => $syndication_services,
		];

		// Pass data to JavaScript using wp_add_inline_script for more reliable delivery.
		// Use a unique name to avoid conflicts with admin.js which also uses postKindsIndieWeb.
		wp_add_inline_script(
			'post-kinds-indieweb-editor',
			'window.postKindsIndieWebEditor = ' . wp_json_encode( $localize_data ) . ';',
			'before'
		);

		// Enqueue editor styles if they exist.
		$style_file = \POST_KINDS_INDIEWEB_PATH . 'build/index.css';

		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'post-kinds-indieweb-editor',
				\POST_KINDS_INDIEWEB_URL . 'build/index.css',
				[],
				$asset['version']
			);
		}

		// Enqueue block styles in the editor. The style is registered in register_blocks()
		// and attached to blocks via editor_style, but we also enqueue it directly to ensure
		// it's available before any block is inserted.
		if ( wp_style_is( 'post-kinds-indieweb-blocks', 'registered' ) ) {
			wp_enqueue_style( 'post-kinds-indieweb-blocks' );
		}
	}

	/**
	 * Register block patterns.
	 *
	 * Registers the pattern category and loads pattern files.
	 *
	 * @return void
	 */
	public function register_block_patterns(): void {
		// Register pattern category.
		register_block_pattern_category(
			'post-kinds-for-indieweb',
			[
				'label'       => __( 'Post Kinds for IndieWeb', 'post-kinds-for-indieweb' ),
				'description' => __( 'Patterns for IndieWeb post kinds and reactions.', 'post-kinds-for-indieweb' ),
			]
		);

		// Load pattern files from patterns directory.
		$patterns_dir = \POST_KINDS_INDIEWEB_PATH . 'patterns/';

		if ( ! is_dir( $patterns_dir ) ) {
			return;
		}

		$pattern_files = glob( $patterns_dir . '*.php' );

		if ( empty( $pattern_files ) ) {
			return;
		}

		foreach ( $pattern_files as $pattern_file ) {
			// Pattern files should register themselves when included.
			require_once $pattern_file;
		}
	}

	/**
	 * Get plugin template definitions.
	 *
	 * Returns template slugs and their metadata for registration.
	 *
	 * @return array<string, array<string, string>> Template definitions.
	 */
	private function get_plugin_template_definitions(): array {
		$templates = [];

		// Only register checkin archive template if CPT is enabled.
		if ( Checkin_Post_Type::is_enabled() ) {
			$templates['archive-checkin'] = [
				'title'       => __( 'Check-in Archive', 'post-kinds-for-indieweb' ),
				'description' => __( 'Template for displaying the check-in post type archive.', 'post-kinds-for-indieweb' ),
				'post_types'  => [ 'checkin' ],
			];
		}

		// Venue taxonomy template.
		$templates['taxonomy-venue'] = [
			'title'       => __( 'Venue Archive', 'post-kinds-for-indieweb' ),
			'description' => __( 'Template for displaying venue taxonomy archives.', 'post-kinds-for-indieweb' ),
			'post_types'  => [],
		];

		return $templates;
	}

	/**
	 * Add plugin templates to the block templates list.
	 *
	 * Injects our custom templates for CPT and taxonomy archives when
	 * the theme doesn't provide them.
	 *
	 * @param \WP_Block_Template[] $query_result Array of templates.
	 * @param array                $query        Query parameters.
	 * @param string               $template_type Type of template.
	 * @return \WP_Block_Template[] Modified array of templates.
	 */
	public function add_plugin_templates( array $query_result, array $query, string $template_type ): array {
		// Only handle wp_template type.
		if ( 'wp_template' !== $template_type ) {
			return $query_result;
		}

		$template_definitions = $this->get_plugin_template_definitions();

		foreach ( $template_definitions as $slug => $definition ) {
			// Check if template already exists in results.
			$exists = false;
			foreach ( $query_result as $template ) {
				if ( $template->slug === $slug ) {
					$exists = true;
					break;
				}
			}

			// If template doesn't exist and we have a file for it, add it.
			if ( ! $exists ) {
				$template_file = \POST_KINDS_INDIEWEB_PATH . 'templates/' . $slug . '.html';

				if ( file_exists( $template_file ) ) {
					$template = $this->build_template_object( $slug, $definition, $template_file );

					if ( $template ) {
						$query_result[] = $template;
					}
				}
			}
		}

		return $query_result;
	}

	/**
	 * Get a plugin template by ID.
	 *
	 * Handles requests for specific plugin templates.
	 *
	 * @param \WP_Block_Template|null $template The template to return.
	 * @param string                  $id       Template ID in format theme//slug.
	 * @param string                  $template_type Type of template.
	 * @return \WP_Block_Template|null The template object or null.
	 */
	public function get_plugin_template( $template, string $id, string $template_type ) {
		// Only handle wp_template type.
		if ( 'wp_template' !== $template_type ) {
			return $template;
		}

		// Extract slug from ID (format: theme//slug).
		$parts = explode( '//', $id );
		$slug  = end( $parts );

		$template_definitions = $this->get_plugin_template_definitions();

		// Check if this is one of our templates.
		if ( ! isset( $template_definitions[ $slug ] ) ) {
			return $template;
		}

		$template_file = \POST_KINDS_INDIEWEB_PATH . 'templates/' . $slug . '.html';

		if ( file_exists( $template_file ) ) {
			return $this->build_template_object( $slug, $template_definitions[ $slug ], $template_file );
		}

		return $template;
	}

	/**
	 * Build a WP_Block_Template object from a file.
	 *
	 * @param string $slug       Template slug.
	 * @param array  $definition Template definition with title and description.
	 * @param string $file_path  Path to the template file.
	 * @return \WP_Block_Template|null Template object or null on failure.
	 */
	private function build_template_object( string $slug, array $definition, string $file_path ): ?\WP_Block_Template {
		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			return null;
		}

		$template                 = new \WP_Block_Template();
		$template->id             = 'post-kinds-for-indieweb//' . $slug;
		$template->theme          = 'post-kinds-for-indieweb';
		$template->source         = 'plugin';
		$template->slug           = $slug;
		$template->type           = 'wp_template';
		$template->title          = $definition['title'];
		$template->description    = $definition['description'];
		$template->status         = 'publish';
		$template->has_theme_file = true;
		$template->is_custom      = false;
		$template->content        = $content;
		$template->post_types     = $definition['post_types'] ?? [];
		$template->origin         = 'plugin';

		return $template;
	}

	/**
	 * Add plugin action links.
	 *
	 * Adds settings and documentation links to the plugins page.
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string> Modified action links.
	 */
	public function add_action_links( array $links ): array {
		$plugin_links = [
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=post-kinds-indieweb' ) ),
				esc_html__( 'Settings', 'post-kinds-for-indieweb' )
			),
		];

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Display Post Kinds conflict error notice.
	 *
	 * Shows an error notice when Post Kinds plugin is active.
	 * These plugins are mutually exclusive.
	 *
	 * @return void
	 */
	public function post_kinds_conflict_notice(): void {
		$message = sprintf(
			/* translators: %s: Post Kinds plugin name */
			esc_html__(
				'Post Kinds for IndieWeb cannot run while %s is active. These plugins provide the same functionality - Post Kinds for IndieWeb is the block editor successor to Post Kinds. Please deactivate one of them.',
				'post-kinds-for-indieweb'
			),
			'<strong>Post Kinds</strong>'
		);

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses(
				$message,
				[
					'strong' => [],
				]
			)
		);
	}

	/**
	 * Display IndieBlocks recommendation notice.
	 *
	 * Shows a non-dismissible notice recommending IndieBlocks installation.
	 *
	 * @return void
	 */
	public function indieblocks_notice(): void {
		// Re-check IndieBlocks status at runtime (it may have loaded after our initial check).
		if ( ! $this->indieblocks_active ) {
			// Check active_plugins option directly.
			$active_plugins = (array) get_option( 'active_plugins', [] );
			if ( in_array( 'indieblocks/indieblocks.php', $active_plugins, true ) ) {
				$this->indieblocks_active = true;
			} elseif ( class_exists( 'IndieBlocks\\IndieBlocks' ) ) {
				$this->indieblocks_active = true;
			}
		}

		// Don't show notice if IndieBlocks is active.
		if ( $this->indieblocks_active ) {
			return;
		}

		// Only show on relevant admin pages.
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'dashboard', 'options-general' ], true ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: IndieBlocks plugin link */
			esc_html__(
				'Post Kinds for IndieWeb works best with %s installed. While not required, IndieBlocks provides essential blocks for bookmarks, likes, replies, and more.',
				'post-kinds-for-indieweb'
			),
			'<a href="https://wordpress.org/plugins/indieblocks/" target="_blank" rel="noopener noreferrer">IndieBlocks</a>'
		);

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses(
				$message,
				[
					'a' => [
						'href'   => [],
						'target' => [],
						'rel'    => [],
					],
				]
			)
		);
	}

	/**
	 * Check if IndieBlocks is active.
	 *
	 * @return bool True if IndieBlocks is active, false otherwise.
	 */
	public function is_indieblocks_active(): bool {
		return $this->indieblocks_active;
	}

	/**
	 * Get the Taxonomy component.
	 *
	 * @return Taxonomy|null The Taxonomy instance or null if not loaded.
	 */
	public function get_taxonomy(): ?Taxonomy {
		return $this->taxonomy;
	}

	/**
	 * Get the Meta_Fields component.
	 *
	 * @return Meta_Fields|null The Meta_Fields instance or null if not loaded.
	 */
	public function get_meta_fields(): ?Meta_Fields {
		return $this->meta_fields;
	}

	/**
	 * Get the Block_Bindings component.
	 *
	 * @return Block_Bindings|null The Block_Bindings instance or null if not loaded.
	 */
	public function get_block_bindings(): ?Block_Bindings {
		return $this->block_bindings;
	}

	/**
	 * Get the Microformats component.
	 *
	 * @return Microformats|null The Microformats instance or null if not loaded.
	 */
	public function get_microformats(): ?Microformats {
		return $this->microformats;
	}

	/**
	 * Get the REST_API component.
	 *
	 * @return REST_API|null The REST_API instance or null if not loaded.
	 */
	public function get_rest_api(): ?REST_API {
		return $this->rest_api;
	}

	/**
	 * Get the External_APIs component.
	 *
	 * @return External_APIs|null The External_APIs instance or null if not loaded.
	 */
	public function get_external_apis(): ?External_APIs {
		return $this->external_apis;
	}

	/**
	 * Get the Admin component.
	 *
	 * @return Admin|null The Admin instance or null if not loaded.
	 */
	public function get_admin(): ?Admin\Admin {
		return $this->admin;
	}

	/**
	 * Get the Post_Type component.
	 *
	 * @return Post_Type|null The Post_Type instance or null if not loaded.
	 */
	public function get_post_type(): ?Post_Type {
		return $this->post_type;
	}

	/**
	 * Get the Query_Filter component.
	 *
	 * @return Query_Filter|null The Query_Filter instance or null if not loaded.
	 */
	public function get_query_filter(): ?Query_Filter {
		return $this->query_filter;
	}

	/**
	 * Get the Import_Manager component.
	 *
	 * @return Import_Manager|null The Import_Manager instance or null if not loaded.
	 */
	public function get_import_manager(): ?Import_Manager {
		return $this->import_manager;
	}

	/**
	 * Get the Scheduled_Sync component.
	 *
	 * @return Scheduled_Sync|null The Scheduled_Sync instance or null if not loaded.
	 */
	public function get_scheduled_sync(): ?Scheduled_Sync {
		return $this->scheduled_sync;
	}

	/**
	 * Flush rewrite rules if storage mode changed.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( get_option( 'post_kinds_indieweb_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'post_kinds_indieweb_flush_rewrite' );
		}
	}

	/**
	 * Get available syndication services for the editor.
	 *
	 * Returns information about which POSSE syndication services are
	 * connected and enabled, so the editor can show opt-out toggles.
	 *
	 * @return array<string, array<string, mixed>> Array of service info.
	 */
	private function get_available_syndication_services(): array {
		$services    = [];
		$settings    = get_option( 'post_kinds_indieweb_settings', [] );
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );

		// Check Last.fm for listen posts.
		if ( ! empty( $settings['listen_sync_to_lastfm'] ) ) {
			$lastfm = $credentials['lastfm'] ?? [];

			// Include service if enabled, even if not fully connected.
			// This allows showing a helpful message in the editor.
			$services['lastfm'] = [
				'name'      => 'Last.fm',
				'kind'      => 'listen',
				'metaKey'   => '_postkind_syndicate_lastfm',
				'connected' => ! empty( $lastfm['session_key'] ),
				'needsAuth' => empty( $lastfm['session_key'] ),
			];
		}

		// Check Trakt for watch posts.
		if ( ! empty( $settings['watch_sync_to_trakt'] ) ) {
			$trakt = $credentials['trakt'] ?? [];

			$services['trakt'] = [
				'name'      => 'Trakt',
				'kind'      => 'watch',
				'metaKey'   => '_postkind_syndicate_trakt',
				'connected' => ! empty( $trakt['access_token'] ),
				'needsAuth' => empty( $trakt['access_token'] ),
			];
		}

		// Check Foursquare for checkin posts.
		if ( ! empty( $settings['checkin_sync_to_foursquare'] ) ) {
			$foursquare = $credentials['foursquare'] ?? [];

			$services['foursquare'] = [
				'name'      => 'Foursquare',
				'kind'      => 'checkin',
				'metaKey'   => '_postkind_syndicate_foursquare',
				'connected' => ! empty( $foursquare['access_token'] ),
				'needsAuth' => empty( $foursquare['access_token'] ),
			];
		}

		/**
		 * Filters the available syndication services.
		 *
		 * Allows adding additional syndication services for the editor UI.
		 *
		 * @since 1.0.0
		 *
		 * @param array $services Array of service configurations.
		 */
		return apply_filters( 'post_kinds_indieweb_syndication_services', $services );
	}
}

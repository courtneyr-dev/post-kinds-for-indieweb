<?php
/**
 * Main Plugin Orchestrator Class
 *
 * Initializes all plugin components and manages the plugin lifecycle.
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
		// Detect IndieBlocks presence.
		$this->detect_indieblocks();

		// Initialize components.
		$this->init_components();

		// Register hooks.
		$this->register_hooks();
	}

	/**
	 * Detect if IndieBlocks plugin is active.
	 *
	 * Checks for IndieBlocks presence to enable enhanced integration.
	 *
	 * @return void
	 */
	private function detect_indieblocks(): void {
		// Check using WordPress plugin functions (most reliable).
		if ( function_exists( 'is_plugin_active' ) ) {
			if ( is_plugin_active( 'indieblocks/indieblocks.php' ) ) {
				$this->indieblocks_active = true;
				return;
			}
		}

		// Fallback: Check if IndieBlocks is active by looking for its main class or function.
		if ( class_exists( 'IndieBlocks\\IndieBlocks' ) || function_exists( 'IndieBlocks\\plugin' ) ) {
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
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Sets up actions and filters for the plugin.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		// Register custom blocks.
		add_action( 'init', array( $this, 'register_blocks' ) );

		// Enqueue editor assets.
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );

		// Enqueue frontend block styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_block_styles' ) );

		// Register block patterns.
		add_action( 'init', array( $this, 'register_block_patterns' ) );

		// Add plugin action links.
		add_filter( 'plugin_action_links_' . \REACTIONS_INDIEWEB_BASENAME, array( $this, 'add_action_links' ) );

		// Display admin notice if IndieBlocks is not active (check deferred to admin_notices time).
		add_action( 'admin_notices', array( $this, 'indieblocks_notice' ) );
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
					array(
						array(
							'slug'  => 'reactions-indieweb',
							'title' => __( 'Reactions for IndieWeb', 'reactions-indieweb' ),
							'icon'  => 'heart',
						),
					),
					$categories
				);
			}
		);

		// Define blocks to register.
		$blocks = array(
			'listen-card',
			'watch-card',
			'read-card',
			'checkin-card',
			'rsvp-card',
			'star-rating',
			'media-lookup',
		);

		// Register each block.
		foreach ( $blocks as $block ) {
			$block_dir = \REACTIONS_INDIEWEB_PATH . 'src/blocks/' . $block;

			if ( file_exists( $block_dir . '/block.json' ) ) {
				register_block_type( $block_dir );
			}
		}

		// Enqueue blocks script.
		$blocks_asset_file = \REACTIONS_INDIEWEB_PATH . 'build/blocks.asset.php';

		if ( file_exists( $blocks_asset_file ) ) {
			$blocks_asset = require $blocks_asset_file;

			wp_register_script(
				'reactions-indieweb-blocks',
				\REACTIONS_INDIEWEB_URL . 'build/blocks.js',
				$blocks_asset['dependencies'],
				$blocks_asset['version'],
				true
			);

			wp_set_script_translations(
				'reactions-indieweb-blocks',
				'reactions-indieweb',
				\REACTIONS_INDIEWEB_PATH . 'languages'
			);
		}
	}

	/**
	 * Enqueue frontend block styles.
	 *
	 * Loads CSS for blocks on the frontend.
	 *
	 * @return void
	 */
	public function enqueue_block_styles(): void {
		// Block styles are automatically enqueued by WordPress when using block.json.
		// This method is for any additional frontend styles.
	}

	/**
	 * Enqueue editor assets.
	 *
	 * Loads JavaScript and CSS for the block editor.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$asset_file = \REACTIONS_INDIEWEB_PATH . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'reactions-indieweb-editor',
			\REACTIONS_INDIEWEB_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'reactions-indieweb-editor',
			'reactions-indieweb',
			\REACTIONS_INDIEWEB_PATH . 'languages'
		);

		// Pass data to JavaScript.
		wp_localize_script(
			'reactions-indieweb-editor',
			'reactionsIndieWeb',
			array(
				'indieBlocksActive' => $this->indieblocks_active,
				'restUrl'           => rest_url( 'reactions-indieweb/v1/' ),
				'nonce'             => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Enqueue editor styles if they exist.
		$style_file = \REACTIONS_INDIEWEB_PATH . 'build/index.css';

		if ( file_exists( $style_file ) ) {
			wp_enqueue_style(
				'reactions-indieweb-editor',
				\REACTIONS_INDIEWEB_URL . 'build/index.css',
				array(),
				$asset['version']
			);
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
			'reactions-indieweb',
			array(
				'label'       => __( 'Reactions for IndieWeb', 'reactions-indieweb' ),
				'description' => __( 'Patterns for IndieWeb post kinds and reactions.', 'reactions-indieweb' ),
			)
		);

		// Load pattern files from patterns directory.
		$patterns_dir = \REACTIONS_INDIEWEB_PATH . 'patterns/';

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
	 * Add plugin action links.
	 *
	 * Adds settings and documentation links to the plugins page.
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string> Modified action links.
	 */
	public function add_action_links( array $links ): array {
		$plugin_links = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=reactions-indieweb' ) ),
				esc_html__( 'Settings', 'reactions-indieweb' )
			),
		);

		return array_merge( $plugin_links, $links );
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
			// Check again now that all plugins are loaded.
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'indieblocks/indieblocks.php' ) ) {
				$this->indieblocks_active = true;
			} elseif ( class_exists( 'IndieBlocks\\IndieBlocks' ) || function_exists( 'IndieBlocks\\plugin' ) ) {
				$this->indieblocks_active = true;
			}
		}

		// Don't show notice if IndieBlocks is active.
		if ( $this->indieblocks_active ) {
			return;
		}

		// Only show on relevant admin pages.
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard', 'options-general' ), true ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: IndieBlocks plugin link */
			esc_html__(
				'Reactions for IndieWeb works best with %s installed. While not required, IndieBlocks provides essential blocks for bookmarks, likes, replies, and more.',
				'reactions-indieweb'
			),
			'<a href="https://wordpress.org/plugins/indieblocks/" target="_blank" rel="noopener noreferrer">IndieBlocks</a>'
		);

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses(
				$message,
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
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
}

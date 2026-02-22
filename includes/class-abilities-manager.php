<?php
/**
 * Abilities Manager for Post Kinds for IndieWeb
 *
 * Orchestrates registration of WordPress Abilities API abilities
 * for IndieWeb post kinds.
 *
 * @package PostKindsForIndieWeb
 * @since 1.1.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities Manager
 *
 * Handles registration and management of WordPress Abilities API abilities.
 * Uses singleton pattern for consistent state across the plugin.
 *
 * @since 1.1.0
 */
final class Abilities_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var Abilities_Manager|null
	 */
	private static ?Abilities_Manager $instance = null;

	/**
	 * Plugin ability category slug.
	 */
	const CATEGORY_SLUG = 'post-kinds';

	/**
	 * Registered ability providers.
	 *
	 * @var array<string, object>
	 */
	private array $providers = [];

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
		$this->init();
	}

	/**
	 * Reset singleton for testing.
	 *
	 * @internal Only for use in tests.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone(): void {
		// Prevent cloning.
	}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception If unserialization is attempted.
	 */
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Initialize abilities registration.
	 *
	 * Hooks into the Abilities API if available and enabled.
	 *
	 * @since 1.1.0
	 */
	private function init(): void {
		if ( ! Feature_Flags::has_abilities_api() ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );

		if ( class_exists( 'WP_Pinch\\Abilities' ) ) {
			self::register_mcp_hooks();
		}
	}

	/**
	 * Register the post-kinds ability category.
	 *
	 * @since 1.1.0
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY_SLUG,
			[
				'label'       => __( 'Post Kinds', 'post-kinds-for-indieweb' ),
				'description' => __( 'Abilities for managing IndieWeb post kinds, including notes, articles, bookmarks, likes, replies, and other interaction types.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Register all plugin abilities.
	 *
	 * Instantiates ability providers and calls their register methods.
	 *
	 * @since 1.1.0
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		// Register core abilities.
		if ( class_exists( __NAMESPACE__ . '\\Abilities\\Core_Abilities' ) ) {
			$core                    = Abilities\Core_Abilities::instance();
			$this->providers['core'] = $core;
			$core->register();
		}

		// Register lookup abilities.
		if ( class_exists( __NAMESPACE__ . '\\Abilities\\Lookup_Abilities' ) ) {
			$lookup                    = Abilities\Lookup_Abilities::instance();
			$this->providers['lookup'] = $lookup;
			$lookup->register();
		}

		/**
		 * Fires after all post kind abilities are registered.
		 *
		 * Allows other plugins to register additional abilities
		 * in the post-kinds category.
		 *
		 * @since 1.1.0
		 *
		 * @param Abilities_Manager $manager The abilities manager instance.
		 */
		do_action( 'pkiw_abilities_registered', $this );
	}

	/**
	 * Get a registered ability provider.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name Provider name (core, lookup).
	 * @return object|null The provider instance or null if not registered.
	 */
	public function get_provider( string $name ): ?object {
		return $this->providers[ $name ] ?? null;
	}

	/**
	 * Get all registered providers.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, object> All registered providers.
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Get all Post Kinds ability names.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, string> Ability name strings.
	 */
	public static function get_ability_names(): array {
		return [
			'post_kinds/list_kinds',
			'post_kinds/list_kind_fields',
			'post_kinds/create_post',
			'post_kinds/set_kind',
			'post_kinds/get_kind',
			'post_kinds/update_post_meta',
			'post_kinds/get_post_meta',
			'post_kinds/lookup_music',
			'post_kinds/lookup_video',
			'post_kinds/lookup_book',
			'post_kinds/lookup_podcast',
			'post_kinds/lookup_venue',
			'post_kinds/lookup_game',
		];
	}

	/**
	 * Register WP Pinch MCP server integration hooks.
	 *
	 * @since 1.1.0
	 */
	public static function register_mcp_hooks(): void {
		add_filter( 'wp_pinch_mcp_server_abilities', [ self::class, 'filter_mcp_server_abilities' ] );
		add_filter( 'wp_register_ability_args', [ self::class, 'filter_ability_args' ], 10, 2 );
	}

	/**
	 * Append Post Kinds abilities to the MCP server abilities list.
	 *
	 * @since 1.1.0
	 *
	 * @param array $abilities Existing ability name strings.
	 * @return array Modified ability name strings.
	 */
	public static function filter_mcp_server_abilities( array $abilities ): array {
		return array_merge( $abilities, self::get_ability_names() );
	}

	/**
	 * Add MCP public meta to Post Kinds abilities.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $args Ability registration arguments.
	 * @param string $name Ability name.
	 * @return array Modified arguments.
	 */
	public static function filter_ability_args( array $args, string $name ): array {
		if ( 0 !== strpos( $name, 'post_kinds/' ) ) {
			return $args;
		}

		if ( ! isset( $args['meta'] ) ) {
			$args['meta'] = [];
		}
		if ( ! isset( $args['meta']['mcp'] ) ) {
			$args['meta']['mcp'] = [];
		}
		$args['meta']['mcp']['public'] = true;

		return $args;
	}
}

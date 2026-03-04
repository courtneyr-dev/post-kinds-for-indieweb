<?php
/**
 * Feature Flags for Post Kinds for IndieWeb
 *
 * Manages optional feature toggles for Abilities API and MCP integrations.
 * Features can be enabled/disabled via constants, filters, or options.
 *
 * @package PostKindsForIndieWeb
 * @since 1.1.0
 */

namespace PostKindsForIndieWeb;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feature Flags Manager
 *
 * Provides centralized feature flag management for optional plugin integrations.
 * Priority order: constant > filter > option > default.
 *
 * @since 1.1.0
 */
final class Feature_Flags {

	/**
	 * Default feature flag values.
	 *
	 * @var array<string, bool>
	 */
	private static $defaults = [
		'abilities_api'   => true,
		'mcp_integration' => true,
	];

	/**
	 * Check if a feature is enabled.
	 *
	 * Checks in order: constant, filter, option, default.
	 * Unknown flags return false.
	 *
	 * @since 1.1.0
	 *
	 * @param string $flag Feature flag name.
	 * @return bool Whether the feature is enabled.
	 */
	public static function is_enabled( string $flag ): bool {
		// Unknown flags return false.
		if ( ! array_key_exists( $flag, self::$defaults ) ) {
			return false;
		}

		// 1. Check for constant override (highest priority).
		$constant_name = 'PKIW_FLAG_' . strtoupper( $flag );
		if ( defined( $constant_name ) ) {
			return (bool) constant( $constant_name );
		}

		// 2. Check filter (allows runtime override).
		$filtered = apply_filters( "pkiw_feature_flag_{$flag}", null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		if ( null !== $filtered ) {
			return (bool) $filtered;
		}

		// 3. Check option (user/admin setting).
		$options = get_option( 'pkiw_feature_flags', [] );
		if ( is_array( $options ) && array_key_exists( $flag, $options ) ) {
			return (bool) $options[ $flag ];
		}

		// 4. Return default.
		return self::$defaults[ $flag ];
	}

	/**
	 * Check if the Abilities API is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool Whether the WordPress Abilities API is available and enabled.
	 */
	public static function has_abilities_api(): bool {
		return self::is_enabled( 'abilities_api' ) && function_exists( 'wp_register_ability' );
	}

	/**
	 * Check if MCP integration should be active.
	 *
	 * Requires both the MCP flag and the Abilities API.
	 *
	 * @since 1.1.0
	 *
	 * @return bool Whether MCP integration is enabled and available.
	 */
	public static function has_mcp(): bool {
		return self::is_enabled( 'mcp_integration' ) && self::has_abilities_api();
	}
}

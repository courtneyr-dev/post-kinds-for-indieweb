<?php
/**
 * Reactions for IndieWeb
 *
 * Extends IndieBlocks with comprehensive IndieWeb Post Kinds support including
 * RSVP, check-in, listen, watch, read, event, review, and recipe post types.
 *
 * @package     ReactionsForIndieWeb
 * @author      Courtney Robertson
 * @copyright   2024 Courtney Robertson
 * @license     GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Reactions for IndieWeb
 * Plugin URI:        https://github.com/developer/reactions-for-indieweb
 * Description:       Extends IndieBlocks with comprehensive IndieWeb Post Kinds support using Block Patterns and Block Bindings API.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Courtney Robertson
 * Author URI:        https://developer.example.com
 * Text Domain:       reactions-indieweb
 * Domain Path:       /languages
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        false
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version constant.
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_VERSION', '1.0.0' );

/**
 * Plugin directory path constant.
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL constant.
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename constant.
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Plugin file constant.
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_PLUGIN_FILE', __FILE__ );

/**
 * Plugin URL constant (alias for compatibility).
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum required PHP version.
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_MIN_PHP', '8.0' );

/**
 * Minimum required WordPress version.
 *
 * @var string
 */
define( 'REACTIONS_INDIEWEB_MIN_WP', '6.5' );

/**
 * Check PHP version requirement.
 *
 * @return bool True if PHP version meets requirement, false otherwise.
 */
function check_php_version(): bool {
	return version_compare( PHP_VERSION, REACTIONS_INDIEWEB_MIN_PHP, '>=' );
}

/**
 * Check WordPress version requirement.
 *
 * @return bool True if WordPress version meets requirement, false otherwise.
 */
function check_wp_version(): bool {
	global $wp_version;
	return version_compare( $wp_version, REACTIONS_INDIEWEB_MIN_WP, '>=' );
}

/**
 * Display admin notice for PHP version requirement.
 *
 * @return void
 */
function php_version_notice(): void {
	$message = sprintf(
		/* translators: 1: Required PHP version, 2: Current PHP version */
		esc_html__(
			'Reactions for IndieWeb requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade PHP to activate this plugin.',
			'reactions-indieweb'
		),
		REACTIONS_INDIEWEB_MIN_PHP,
		PHP_VERSION
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Display admin notice for WordPress version requirement.
 *
 * @return void
 */
function wp_version_notice(): void {
	global $wp_version;

	$message = sprintf(
		/* translators: 1: Required WordPress version, 2: Current WordPress version */
		esc_html__(
			'Reactions for IndieWeb requires WordPress %1$s or higher. You are running WordPress %2$s. Please upgrade WordPress to activate this plugin.',
			'reactions-indieweb'
		),
		REACTIONS_INDIEWEB_MIN_WP,
		$wp_version
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Autoloader for plugin classes.
 *
 * @param string $class_name The fully-qualified class name.
 * @return void
 */
function autoloader( string $class_name ): void {
	$namespace = 'ReactionsForIndieWeb\\';

	// Check if the class belongs to our namespace.
	if ( strpos( $class_name, $namespace ) !== 0 ) {
		return;
	}

	// Remove the namespace prefix.
	$relative_class = substr( $class_name, strlen( $namespace ) );

	// Convert namespace separators to directory separators.
	$relative_class = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class );

	// Convert to lowercase and add 'class-' prefix.
	$file_parts = explode( DIRECTORY_SEPARATOR, $relative_class );
	$class_file = 'class-' . strtolower( str_replace( '_', '-', array_pop( $file_parts ) ) ) . '.php';

	// Build the file path.
	if ( ! empty( $file_parts ) ) {
		$file_path = REACTIONS_INDIEWEB_PATH . 'includes/' . strtolower( implode( DIRECTORY_SEPARATOR, $file_parts ) ) . DIRECTORY_SEPARATOR . $class_file;
	} else {
		$file_path = REACTIONS_INDIEWEB_PATH . 'includes/' . $class_file;
	}

	// Load the file if it exists.
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

// Register the autoloader.
spl_autoload_register( __NAMESPACE__ . '\\autoloader' );

/**
 * Plugin activation hook.
 *
 * @return void
 */
function activate(): void {
	// Check PHP version.
	if ( ! check_php_version() ) {
		deactivate_plugins( REACTIONS_INDIEWEB_BASENAME );
		wp_die(
			sprintf(
				/* translators: %s: Required PHP version */
				esc_html__( 'Reactions for IndieWeb requires PHP %s or higher.', 'reactions-indieweb' ),
				esc_html( REACTIONS_INDIEWEB_MIN_PHP )
			),
			esc_html__( 'Plugin Activation Error', 'reactions-indieweb' ),
			array( 'back_link' => true )
		);
	}

	// Check WordPress version.
	if ( ! check_wp_version() ) {
		deactivate_plugins( REACTIONS_INDIEWEB_BASENAME );
		wp_die(
			sprintf(
				/* translators: %s: Required WordPress version */
				esc_html__( 'Reactions for IndieWeb requires WordPress %s or higher.', 'reactions-indieweb' ),
				esc_html( REACTIONS_INDIEWEB_MIN_WP )
			),
			esc_html__( 'Plugin Activation Error', 'reactions-indieweb' ),
			array( 'back_link' => true )
		);
	}

	// Store activation timestamp for future reference.
	add_option( 'reactions_indieweb_activated', time() );

	// Flush rewrite rules on activation for taxonomy archives.
	flush_rewrite_rules();
}

/**
 * Plugin deactivation hook.
 *
 * @return void
 */
function deactivate(): void {
	// Flush rewrite rules on deactivation.
	flush_rewrite_rules();
}

// Register activation and deactivation hooks.
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function init(): void {
	// Verify PHP version.
	if ( ! check_php_version() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\php_version_notice' );
		return;
	}

	// Verify WordPress version.
	if ( ! check_wp_version() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\wp_version_notice' );
		return;
	}

	// Load text domain for translations.
	load_plugin_textdomain(
		'reactions-indieweb',
		false,
		dirname( REACTIONS_INDIEWEB_BASENAME ) . '/languages'
	);

	// Initialize the main plugin class.
	$plugin = Plugin::get_instance();
	$plugin->init();
}

// Hook into WordPress init.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

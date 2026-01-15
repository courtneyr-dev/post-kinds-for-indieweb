<?php
/**
 * Post Kinds for IndieWeb and Block Themes
 *
 * Modern block editor support for IndieWeb post kinds and microformats.
 * A successor to the classic IndieWeb Post Kinds plugin by David Shanske.
 *
 * @package     PostKindsForIndieWeb
 * @author      Courtney Robertson
 * @copyright   2024 Courtney Robertson
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Post Kinds for IndieWeb and Block Themes
 * Plugin URI:        https://github.com/courtneyr-dev/post-kinds-for-indieweb
 * Description:       Modern block editor support for IndieWeb post kinds and microformats. A successor to the classic IndieWeb Post Kinds plugin.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Courtney Robertson
 * Author URI:        https://courtneyr.dev
 * Text Domain:       post-kinds-for-indieweb
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version constant.
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_VERSION', '1.0.0' );

/**
 * Plugin directory path constant.
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL constant.
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename constant.
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Plugin file constant.
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_PLUGIN_FILE', __FILE__ );

/**
 * Plugin URL constant (alias for compatibility).
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum required PHP version.
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_MIN_PHP', '8.0' );

/**
 * Minimum required WordPress version.
 *
 * @var string
 */
define( 'POST_KINDS_INDIEWEB_MIN_WP', '6.5' );

/**
 * Check PHP version requirement.
 *
 * @return bool True if PHP version meets requirement, false otherwise.
 */
function check_php_version(): bool {
	return version_compare( PHP_VERSION, POST_KINDS_INDIEWEB_MIN_PHP, '>=' );
}

/**
 * Check WordPress version requirement.
 *
 * @return bool True if WordPress version meets requirement, false otherwise.
 */
function check_wp_version(): bool {
	global $wp_version;
	return version_compare( $wp_version, POST_KINDS_INDIEWEB_MIN_WP, '>=' );
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
			'Post Kinds for IndieWeb requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade PHP to activate this plugin.',
			'post-kinds-for-indieweb'
		),
		POST_KINDS_INDIEWEB_MIN_PHP,
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
			'Post Kinds for IndieWeb requires WordPress %1$s or higher. You are running WordPress %2$s. Please upgrade WordPress to activate this plugin.',
			'post-kinds-for-indieweb'
		),
		POST_KINDS_INDIEWEB_MIN_WP,
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
	$namespace = 'PostKindsForIndieWeb\\';

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
		$file_path = POST_KINDS_INDIEWEB_PATH . 'includes/' . strtolower( implode( DIRECTORY_SEPARATOR, $file_parts ) ) . DIRECTORY_SEPARATOR . $class_file;
	} else {
		$file_path = POST_KINDS_INDIEWEB_PATH . 'includes/' . $class_file;
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
		deactivate_plugins( POST_KINDS_INDIEWEB_BASENAME );
		wp_die(
			sprintf(
				/* translators: %s: Required PHP version */
				esc_html__( 'Post Kinds for IndieWeb requires PHP %s or higher.', 'post-kinds-for-indieweb' ),
				esc_html( POST_KINDS_INDIEWEB_MIN_PHP )
			),
			esc_html__( 'Plugin Activation Error', 'post-kinds-for-indieweb' ),
			array( 'back_link' => true )
		);
	}

	// Check WordPress version.
	if ( ! check_wp_version() ) {
		deactivate_plugins( POST_KINDS_INDIEWEB_BASENAME );
		wp_die(
			sprintf(
				/* translators: %s: Required WordPress version */
				esc_html__( 'Post Kinds for IndieWeb requires WordPress %s or higher.', 'post-kinds-for-indieweb' ),
				esc_html( POST_KINDS_INDIEWEB_MIN_WP )
			),
			esc_html__( 'Plugin Activation Error', 'post-kinds-for-indieweb' ),
			array( 'back_link' => true )
		);
	}

	// Store activation timestamp for future reference.
	add_option( 'post_kinds_indieweb_activated', time() );

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

	// Initialize the main plugin class.
	$plugin = Plugin::get_instance();
	$plugin->init();
}

// Load helper functions.
require_once POST_KINDS_INDIEWEB_PATH . 'includes/functions-checkin.php';

// Hook into WordPress init.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\init' );

// Load WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once POST_KINDS_INDIEWEB_PATH . 'includes/class-cli-commands.php';
}

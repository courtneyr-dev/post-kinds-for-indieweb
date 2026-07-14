<?php
/**
 * Post Kinds for IndieWeb
 *
 * Modern block editor support for IndieWeb post kinds and microformats.
 * A successor to the classic IndieWeb Post Kinds plugin by David Shanske.
 *
 * @package     PKIW
 * @author      Courtney Robertson
 * @copyright   2026 Courtney Robertson
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Post Kinds for IndieWeb
 * Plugin URI:        https://github.com/courtneyr-dev/post-kinds-for-indieweb
 * Description:       Modern block editor support for IndieWeb post kinds and microformats. A successor to the classic IndieWeb Post Kinds plugin.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Tested up to:      7.0
 * Requires PHP:      8.2
 * Author:            Courtney Robertson
 * Author URI:        https://courtneyr.dev
 * Text Domain:       post-kinds-for-indieweb
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version constant.
 *
 * @var string
 */
define( 'PKIW_VERSION', '1.0.0' );

/**
 * Plugin directory path constant.
 *
 * @var string
 */
define( 'PKIW_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL constant.
 *
 * @var string
 */
define( 'PKIW_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename constant.
 *
 * @var string
 */
define( 'PKIW_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Plugin file constant.
 *
 * @var string
 */
define( 'PKIW_PLUGIN_FILE', __FILE__ );

/**
 * Plugin URL constant (alias for compatibility).
 *
 * @var string
 */
define( 'PKIW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum required PHP version.
 *
 * @var string
 */
define( 'PKIW_MIN_PHP', '8.2' );

/**
 * Minimum required WordPress version.
 *
 * @var string
 */
define( 'PKIW_MIN_WP', '7.0' );

/**
 * Check PHP version requirement.
 *
 * @return bool True if PHP version meets requirement, false otherwise.
 */
function check_php_version(): bool {
	return version_compare( PHP_VERSION, PKIW_MIN_PHP, '>=' );
}

/**
 * Check WordPress version requirement.
 *
 * @return bool True if WordPress version meets requirement, false otherwise.
 */
function check_wp_version(): bool {
	global $wp_version;
	return version_compare( $wp_version, PKIW_MIN_WP, '>=' );
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
		PKIW_MIN_PHP,
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
		PKIW_MIN_WP,
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
	$namespace = 'PKIW\\';

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
		$file_path = PKIW_PATH . 'includes/' . strtolower( implode( DIRECTORY_SEPARATOR, $file_parts ) ) . DIRECTORY_SEPARATOR . $class_file;
	} else {
		$file_path = PKIW_PATH . 'includes/' . $class_file;
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
		deactivate_plugins( PKIW_BASENAME );
		wp_die(
			sprintf(
				/* translators: %s: Required PHP version */
				esc_html__( 'Post Kinds for IndieWeb requires PHP %s or higher.', 'post-kinds-for-indieweb' ),
				esc_html( PKIW_MIN_PHP )
			),
			esc_html__( 'Plugin Activation Error', 'post-kinds-for-indieweb' ),
			[ 'back_link' => true ]
		);
	}

	// Check WordPress version.
	if ( ! check_wp_version() ) {
		deactivate_plugins( PKIW_BASENAME );
		wp_die(
			sprintf(
				/* translators: %s: Required WordPress version */
				esc_html__( 'Post Kinds for IndieWeb requires WordPress %s or higher.', 'post-kinds-for-indieweb' ),
				esc_html( PKIW_MIN_WP )
			),
			esc_html__( 'Plugin Activation Error', 'post-kinds-for-indieweb' ),
			[ 'back_link' => true ]
		);
	}

	// Store activation timestamp for future reference.
	add_option( 'pkiw_activated', time() );

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

	maybe_migrate_option_prefixes();

	// Verify WordPress version.
	if ( ! check_wp_version() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\wp_version_notice' );
		return;
	}

	// Initialize the main plugin class.
	$plugin = Plugin::get_instance();
	$plugin->init();
}

/**
 * One-time migration: rename stored options from the pre-release
 * `post_kinds_indieweb_*` / `post_kinds_*` prefixes to `pkiw_*`.
 *
 * The prefix consolidation happened before the first WordPress.org
 * release, so only development installs carry the old keys. Values are
 * copied verbatim (autoload flag preserved), then the old rows are
 * deleted. Cached transients are left to expire on their own.
 *
 * @return void
 */
function maybe_migrate_option_prefixes(): void {
	if ( get_option( 'pkiw_prefix_migrated' ) ) {
		return;
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time schema migration.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'post_kinds_' ) . '%'
		)
	);

	foreach ( $rows as $row ) {
		$old_name = $row->option_name;
		$new_name = str_starts_with( $old_name, 'post_kinds_indieweb_' )
			? 'pkiw_' . substr( $old_name, strlen( 'post_kinds_indieweb_' ) )
			: 'pkiw_' . substr( $old_name, strlen( 'post_kinds_' ) );

		if ( false === get_option( $new_name, false ) ) {
			add_option( $new_name, get_option( $old_name ), '', in_array( $row->autoload, [ 'yes', 'on', 'auto-on', 'auto' ], true ) );
		}
		delete_option( $old_name );
	}

	// The cron hooks were renamed with the prefix; clear stale schedules.
	wp_clear_scheduled_hook( 'post_kinds_indieweb_process_import' );
	wp_clear_scheduled_hook( 'post_kinds_indieweb_scheduled_sync' );

	update_option( 'pkiw_prefix_migrated', 1, false );
}

// Load helper functions.
require_once PKIW_PATH . 'includes/functions-checkin.php';
require_once PKIW_PATH . 'includes/functions-embeds.php';
require_once PKIW_PATH . 'includes/functions-card-icons.php';
require_once PKIW_PATH . 'includes/functions-stream-card.php';

// Hook into WordPress init (priority 0 so component registrations land
// before the priority-10 callbacks they depend on).
//
// `init` rather than `plugins_loaded`: WordPress 6.7 added a
// `_load_textdomain_just_in_time` notice when any `__()` / `_e()` call
// fires before `init`. Several of this plugin's components touch
// translated labels (taxonomy registration, block category, etc.), so
// kicking the bootstrap from `plugins_loaded` triggered the notice — and
// because notices flush output, login redirects (which need to set
// cookies) broke. Running at `init` keeps translations on the right side
// of the JIT loader.
add_action( 'init', __NAMESPACE__ . '\\init', 0 );

// Load WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PKIW_PATH . 'includes/class-cli-commands.php';
}

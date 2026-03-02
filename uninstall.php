<?php
/**
 * Uninstall handler.
 *
 * Cleans up plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package PostKindsForIndieWeb
 * @since 1.3.0
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Core options.
delete_option( 'post_kinds_indieweb_settings' );
delete_option( 'post_kinds_indieweb_api_credentials' );
delete_option( 'post_kinds_indieweb_webhook_settings' );
delete_option( 'post_kinds_indieweb_flush_rewrite' );
delete_option( 'post_kinds_indieweb_terms_created' );
delete_option( 'post_kinds_indieweb_terms_version' );
delete_option( 'post_kinds_indieweb_active_imports' );
delete_option( 'post_kinds_indieweb_import_history' );
delete_option( 'post_kinds_indieweb_last_sync' );
delete_option( 'post_kinds_indieweb_webhook_secret' );
delete_option( 'post_kinds_indieweb_webhook_log' );
delete_option( 'post_kinds_indieweb_pending_scrobbles' );
delete_option( 'post_kinds_indieweb_owntracks_last_location' );

// OAuth tokens for each service.
$post_kinds_oauth_services = [ 'trakt', 'simkl', 'foursquare', 'untappd' ];
foreach ( $post_kinds_oauth_services as $post_kinds_service ) {
	delete_option( 'post_kinds_indieweb_' . $post_kinds_service . '_access_token' );
	delete_option( 'post_kinds_indieweb_' . $post_kinds_service . '_refresh_token' );
	delete_option( 'post_kinds_indieweb_' . $post_kinds_service . '_token_expires' );
}

// Legacy individual API key options.
$post_kinds_legacy_keys = [
	'tmdb_api_key',
	'trakt_client_id',
	'trakt_client_secret',
	'lastfm_api_key',
	'lastfm_api_secret',
	'listenbrainz_token',
	'simkl_client_id',
	'foursquare_client_id',
	'foursquare_client_secret',
	'hardcover_api_key',
	'podcastindex_api_key',
	'podcastindex_api_secret',
	'google_books_api_key',
];
foreach ( $post_kinds_legacy_keys as $post_kinds_key ) {
	delete_option( 'post_kinds_indieweb_' . $post_kinds_key );
}

// Clean up transients.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_post_kinds_%',
		'_transient_timeout_post_kinds_%'
	)
);

// Unschedule cron events.
wp_clear_scheduled_hook( 'post_kinds_indieweb_process_import' );
wp_clear_scheduled_hook( 'post_kinds_indieweb_scheduled_sync' );

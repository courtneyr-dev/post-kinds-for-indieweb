<?php
/**
 * Uninstall handler.
 *
 * Cleans up plugin data when the plugin is deleted via the WordPress admin.
 *
 * @package PKIW
 * @since 1.3.0
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Core options.
delete_option( 'pkiw_settings' );
delete_option( 'pkiw_api_credentials' );
delete_option( 'pkiw_webhook_settings' );
delete_option( 'pkiw_flush_rewrite' );
delete_option( 'pkiw_terms_created' );
delete_option( 'pkiw_terms_version' );
delete_option( 'pkiw_active_imports' );
delete_option( 'pkiw_import_history' );
delete_option( 'pkiw_last_sync' );
delete_option( 'pkiw_webhook_secret' );
delete_option( 'pkiw_webhook_log' );
delete_option( 'pkiw_pending_scrobbles' );
delete_option( 'pkiw_owntracks_last_location' );

// OAuth tokens for each service.
$pkiw_for_indieweb_oauth_services = [ 'trakt', 'simkl', 'foursquare', 'untappd' ];
foreach ( $pkiw_for_indieweb_oauth_services as $pkiw_for_indieweb_service ) {
	delete_option( 'pkiw_' . $pkiw_for_indieweb_service . '_access_token' );
	delete_option( 'pkiw_' . $pkiw_for_indieweb_service . '_refresh_token' );
	delete_option( 'pkiw_' . $pkiw_for_indieweb_service . '_token_expires' );
}

// Legacy individual API key options.
$pkiw_for_indieweb_legacy_keys = [
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
foreach ( $pkiw_for_indieweb_legacy_keys as $pkiw_for_indieweb_key ) {
	delete_option( 'pkiw_' . $pkiw_for_indieweb_key );
}

// Clean up transients.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_pkiw_%',
		'_transient_timeout_pkiw_%'
	)
);

// Pre-1.0.0 development builds stored options under the old
// `post_kinds_*` prefixes; remove any stragglers plus their transients.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'post_kinds_' ) . '%',
		'_transient_post_kinds_%',
		'_transient_timeout_post_kinds_%'
	)
);
delete_option( 'pkiw_prefix_migrated' );
delete_option( 'pkiw_activated' );

// Unschedule cron events.
wp_clear_scheduled_hook( 'pkiw_process_import' );
wp_clear_scheduled_hook( 'pkiw_scheduled_sync' );

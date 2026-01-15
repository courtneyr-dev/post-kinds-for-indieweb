<?php
/**
 * Main Admin Controller
 *
 * Handles admin initialization, menu registration, and asset loading.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Admin;

use PostKindsForIndieWeb\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller class.
 */
class Admin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Settings page instance.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $settings_page;

	/**
	 * API settings instance.
	 *
	 * @var API_Settings
	 */
	private API_Settings $api_settings;

	/**
	 * Import page instance.
	 *
	 * @var Import_Page
	 */
	private Import_Page $import_page;

	/**
	 * Webhooks page instance.
	 *
	 * @var Webhooks_Page
	 */
	private Webhooks_Page $webhooks_page;

	/**
	 * Meta boxes instance.
	 *
	 * @var Meta_Boxes
	 */
	private Meta_Boxes $meta_boxes;

	/**
	 * Quick post instance.
	 *
	 * @var Quick_Post
	 */
	private Quick_Post $quick_post;

	/**
	 * Syndication page instance.
	 *
	 * @var Syndication_Page
	 */
	private Syndication_Page $syndication_page;

	/**
	 * Checkin dashboard instance.
	 *
	 * @var Checkin_Dashboard
	 */
	private Checkin_Dashboard $checkin_dashboard;

	/**
	 * Checkin migrator instance.
	 *
	 * @var Checkin_Migrator
	 */
	private Checkin_Migrator $checkin_migrator;

	/**
	 * Admin page hook suffixes.
	 *
	 * @var array<string, string>
	 */
	private array $page_hooks = [];

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Initialize the admin.
	 *
	 * @return void
	 */
	public function init(): void {
		// Initialize sub-components.
		$this->settings_page     = new Settings_Page( $this );
		$this->api_settings      = new API_Settings( $this );
		$this->import_page       = new Import_Page( $this );
		$this->webhooks_page     = new Webhooks_Page( $this );
		$this->meta_boxes        = new Meta_Boxes( $this );
		$this->quick_post        = new Quick_Post( $this );
		$this->syndication_page  = new Syndication_Page( $this );
		$this->checkin_dashboard = new Checkin_Dashboard( $this );
		$this->checkin_migrator  = new Checkin_Migrator();

		// Register hooks.
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( \POST_KINDS_INDIEWEB_PLUGIN_FILE ), [ $this, 'plugin_action_links' ] );

		// Initialize sub-components.
		$this->settings_page->init();
		$this->api_settings->init();
		$this->import_page->init();
		$this->webhooks_page->init();
		$this->meta_boxes->init();
		$this->quick_post->init();
		$this->syndication_page->init();
		$this->checkin_dashboard->init();

		// AJAX handlers.
		add_action( 'wp_ajax_postkind_indieweb_test_api', [ $this, 'ajax_test_api' ] );
		add_action( 'wp_ajax_postkind_indieweb_clear_cache', [ $this, 'ajax_clear_cache' ] );
		add_action( 'wp_ajax_postkind_indieweb_lookup_media', [ $this, 'ajax_lookup_media' ] );
		add_action( 'wp_ajax_postkind_indieweb_get_import_status', [ $this, 'ajax_get_import_status' ] );
		add_action( 'wp_ajax_postkind_foursquare_import', [ $this, 'ajax_foursquare_import' ] );
		add_action( 'wp_ajax_postkind_foursquare_disconnect', [ $this, 'ajax_foursquare_disconnect' ] );
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// Main menu page.
		$this->page_hooks['main'] = add_menu_page(
			__( 'Reactions', 'post-kinds-for-indieweb' ),
			__( 'Reactions', 'post-kinds-for-indieweb' ),
			'manage_options',
			'post-kinds-for-indieweb',
			[ $this->settings_page, 'render' ],
			'dashicons-heart',
			30
		);

		// Settings submenu (same as main).
		$this->page_hooks['settings'] = add_submenu_page(
			'post-kinds-for-indieweb',
			__( 'Settings', 'post-kinds-for-indieweb' ),
			__( 'Settings', 'post-kinds-for-indieweb' ),
			'manage_options',
			'post-kinds-for-indieweb',
			[ $this->settings_page, 'render' ]
		);

		// API Connections submenu.
		$this->page_hooks['apis'] = add_submenu_page(
			'post-kinds-for-indieweb',
			__( 'API Connections', 'post-kinds-for-indieweb' ),
			__( 'API Connections', 'post-kinds-for-indieweb' ),
			'manage_options',
			'post-kinds-indieweb-apis',
			[ $this->api_settings, 'render' ]
		);

		// Import submenu.
		$this->page_hooks['import'] = add_submenu_page(
			'post-kinds-for-indieweb',
			__( 'Import', 'post-kinds-for-indieweb' ),
			__( 'Import', 'post-kinds-for-indieweb' ),
			'manage_options',
			'post-kinds-indieweb-import',
			[ $this->import_page, 'render' ]
		);

		// Webhooks submenu.
		$this->page_hooks['webhooks'] = add_submenu_page(
			'post-kinds-for-indieweb',
			__( 'Webhooks', 'post-kinds-for-indieweb' ),
			__( 'Webhooks', 'post-kinds-for-indieweb' ),
			'manage_options',
			'post-kinds-indieweb-webhooks',
			[ $this->webhooks_page, 'render' ]
		);

		// Quick Post submenu.
		$this->page_hooks['quick_post'] = add_submenu_page(
			'post-kinds-for-indieweb',
			__( 'Quick Post', 'post-kinds-for-indieweb' ),
			__( 'Quick Post', 'post-kinds-for-indieweb' ),
			'edit_posts',
			'post-kinds-indieweb-quick-post',
			[ $this->quick_post, 'render' ]
		);

		// Syndication submenu.
		$this->page_hooks['syndication'] = add_submenu_page(
			'post-kinds-for-indieweb',
			__( 'Syndication', 'post-kinds-for-indieweb' ),
			__( 'Syndication', 'post-kinds-for-indieweb' ),
			'edit_posts',
			'post-kinds-indieweb-syndication',
			[ $this->syndication_page, 'render' ]
		);

		// Check-in Dashboard submenu.
		$this->page_hooks['checkin_dashboard'] = add_submenu_page(
			'post-kinds-for-indieweb',
			__( 'Check-ins', 'post-kinds-for-indieweb' ),
			__( 'Check-ins', 'post-kinds-for-indieweb' ),
			'edit_posts',
			'post-kinds-indieweb-checkins',
			[ $this->checkin_dashboard, 'render' ]
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// General settings.
		register_setting(
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_general_settings' ],
				'default'           => $this->get_default_settings(),
			]
		);

		// API credentials (stored separately for security).
		register_setting(
			'post_kinds_indieweb_apis',
			'post_kinds_indieweb_api_credentials',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_api_credentials' ],
				'default'           => [],
			]
		);

		// Webhook settings.
		register_setting(
			'post_kinds_indieweb_webhooks',
			'post_kinds_indieweb_webhook_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_webhook_settings' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Check if we're on one of our pages.
		$is_our_page = in_array( $hook_suffix, $this->page_hooks, true );

		// Also load on post edit screens.
		$screen       = get_current_screen();
		$is_post_edit = $screen && 'post' === $screen->base;

		if ( ! $is_our_page && ! $is_post_edit ) {
			return;
		}

		// Core styles.
		wp_enqueue_style(
			'post-kinds-indieweb-admin',
			\POST_KINDS_INDIEWEB_PLUGIN_URL . 'admin/css/admin.css',
			[],
			\POST_KINDS_INDIEWEB_VERSION
		);

		// Core scripts.
		wp_enqueue_script(
			'post-kinds-indieweb-admin',
			\POST_KINDS_INDIEWEB_PLUGIN_URL . 'admin/js/admin.js',
			[ 'jquery', 'wp-util', 'wp-api-fetch' ],
			\POST_KINDS_INDIEWEB_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'post-kinds-indieweb-admin',
			'postKindsIndieWeb',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'restUrl'   => rest_url( 'post-kinds-indieweb/v1/' ),
				'nonce'     => wp_create_nonce( 'post_kinds_indieweb_admin' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'strings'   => [
					'confirmDelete'  => __( 'Are you sure you want to delete this?', 'post-kinds-for-indieweb' ),
					'confirmClear'   => __( 'Are you sure you want to clear all cached data?', 'post-kinds-for-indieweb' ),
					'testingApi'     => __( 'Testing connection...', 'post-kinds-for-indieweb' ),
					'testSuccess'    => __( 'Connection successful!', 'post-kinds-for-indieweb' ),
					'testFailed'     => __( 'Connection failed: ', 'post-kinds-for-indieweb' ),
					'importing'      => __( 'Importing...', 'post-kinds-for-indieweb' ),
					'importComplete' => __( 'Import complete!', 'post-kinds-for-indieweb' ),
					'lookingUp'      => __( 'Looking up...', 'post-kinds-for-indieweb' ),
					'noResults'      => __( 'No results found.', 'post-kinds-for-indieweb' ),
					'error'          => __( 'An error occurred.', 'post-kinds-for-indieweb' ),
					'saved'          => __( 'Settings saved.', 'post-kinds-for-indieweb' ),
					'copied'         => __( 'Copied to clipboard!', 'post-kinds-for-indieweb' ),
				],
				'postKinds' => $this->get_post_kinds(),
			]
		);

		// Media uploader on quick post.
		if ( isset( $this->page_hooks['quick_post'] ) && $hook_suffix === $this->page_hooks['quick_post'] ) {
			wp_enqueue_media();
		}

		// Select2 for enhanced dropdowns.
		if ( $is_our_page ) {
			wp_enqueue_style(
				'select2',
				POST_KINDS_INDIEWEB_URL . 'assets/vendor/select2/select2.min.css',
				[],
				'4.1.0'
			);
			wp_enqueue_script(
				'select2',
				POST_KINDS_INDIEWEB_URL . 'assets/vendor/select2/select2.min.js',
				[ 'jquery' ],
				'4.1.0',
				true
			);
		}
	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		// Check for missing dependencies using active_plugins option (reliable at all times).
		$active_plugins     = (array) get_option( 'active_plugins', [] );
		$indieblocks_active = in_array( 'indieblocks/indieblocks.php', $active_plugins, true );

		if ( ! $indieblocks_active ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: Plugin name */
				esc_html__( '%s requires IndieBlocks to be installed and activated for full functionality.', 'post-kinds-for-indieweb' ),
				'<strong>Post Kinds for IndieWeb</strong>'
			);
			echo '</p></div>';
		}

		// Show success message after settings save.
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Settings saved successfully.', 'post-kinds-for-indieweb' );
			echo '</p></div>';
		}

		// Show active import notice.
		$active_imports = get_option( 'post_kinds_indieweb_active_imports', [] );
		if ( ! empty( $active_imports ) ) {
			$count = count( $active_imports );
			echo '<div class="notice notice-info"><p>';
			printf(
				/* translators: %1$d: Number of imports, %2$s: Import page URL */
				esc_html(
					_n(
						'%1$d import is currently running. <a href="%2$s">View progress</a>',
						'%1$d imports are currently running. <a href="%2$s">View progress</a>',
						$count,
						'post-kinds-for-indieweb'
					)
				),
				(int) $count,
				esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-import' ) )
			);
			echo '</p></div>';
		}
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array<string, string> $links Existing action links.
	 * @return array<string, string> Modified action links.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=post-kinds-for-indieweb' ),
			__( 'Settings', 'post-kinds-for-indieweb' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Get default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			// General settings.
			'default_post_status'        => 'publish',
			'default_post_format'        => 'aside',
			'enable_microformats'        => true,
			'enable_syndication'         => true,

			// Content settings.
			'auto_fetch_metadata'        => true,
			'cache_duration'             => 86400, // 24 hours.
			'image_handling'             => 'sideload', // 'sideload', 'hotlink', 'none'.

			// Listen settings.
			'listen_default_rating'      => 0,
			'listen_auto_import'         => false,
			'listen_import_source'       => 'listenbrainz',
			'listen_embed_source'        => 'none',
			'listen_sync_to_lastfm'      => false,

			// Watch settings.
			'watch_default_rating'       => 0,
			'watch_auto_import'          => false,
			'watch_import_source'        => 'trakt',
			'watch_include_rewatches'    => false,
			'watch_sync_to_trakt'        => false,

			// Read settings.
			'read_default_status'        => 'to-read',
			'read_auto_import'           => false,
			'read_import_source'         => 'hardcover',

			// Checkin settings.
			'checkin_auto_import'        => false,
			'checkin_privacy'            => 'public',
			'checkin_include_coords'     => true,
			'checkin_sync_to_foursquare' => false,

			// Performance.
			'rate_limit_delay'           => 1000, // milliseconds.
			'batch_size'                 => 50,
			'enable_background_sync'     => false,

			// Import storage.
			'import_storage_mode'        => 'standard', // 'standard', 'cpt', 'hidden'.

			// Sync start dates - per-source cutoff dates for auto-import.
			// Empty = import all history. ISO 8601 date = only import items after this date.
			'sync_start_dates'           => [],

			// Post Format sync.
			'sync_formats_to_kinds'      => true,
			'format_kind_mappings'       => [
				'standard' => 'article',
				'aside'    => 'note',
				'audio'    => 'listen',
				'chat'     => '',
				'gallery'  => 'photo',
				'image'    => 'photo',
				'link'     => 'bookmark',
				'quote'    => 'repost',
				'status'   => 'note',
				'video'    => 'watch',
			],
		];
	}

	/**
	 * Sanitize general settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize_general_settings( array $input ): array {
		$defaults     = $this->get_default_settings();
		$old_settings = get_option( 'post_kinds_indieweb_settings', [] );
		$sanitized    = [];
		$active_tab   = $input['_active_tab'] ?? '';

		// Define which boolean fields belong to which tab.
		// This is crucial for correctly handling unchecked checkboxes.
		$tab_bool_fields = [
			'general'     => [ 'enable_microformats', 'enable_syndication', 'sync_formats_to_kinds' ],
			'content'     => [ 'auto_fetch_metadata' ],
			'listen'      => [ 'listen_auto_import', 'listen_sync_to_lastfm' ],
			'watch'       => [ 'watch_auto_import', 'watch_include_rewatches', 'watch_sync_to_trakt' ],
			'read'        => [ 'read_auto_import' ],
			'checkin'     => [ 'checkin_auto_import', 'checkin_include_coords', 'checkin_sync_to_foursquare' ],
			'performance' => [ 'enable_background_sync' ],
		];

		// String fields.
		$string_fields = [
			'default_post_status',
			'default_post_format',
			'image_handling',
			'listen_import_source',
			'listen_embed_source',
			'watch_import_source',
			'read_default_status',
			'read_import_source',
			'checkin_privacy',
			'import_storage_mode',
		];

		foreach ( $string_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $input[ $field ] );
			} elseif ( isset( $old_settings[ $field ] ) ) {
				$sanitized[ $field ] = $old_settings[ $field ];
			} else {
				$sanitized[ $field ] = $defaults[ $field ];
			}
		}

		// All boolean fields.
		$all_bool_fields = [
			'enable_microformats',
			'enable_syndication',
			'auto_fetch_metadata',
			'listen_auto_import',
			'listen_sync_to_lastfm',
			'watch_auto_import',
			'watch_include_rewatches',
			'watch_sync_to_trakt',
			'read_auto_import',
			'checkin_auto_import',
			'checkin_include_coords',
			'checkin_sync_to_foursquare',
			'enable_background_sync',
			'sync_formats_to_kinds',
		];

		// Get fields that belong to the active tab (these should be processed).
		$active_tab_fields = $tab_bool_fields[ $active_tab ] ?? [];

		foreach ( $all_bool_fields as $field ) {
			if ( in_array( $field, $active_tab_fields, true ) ) {
				// This field belongs to the current tab - check if it was submitted (checked).
				// Unchecked checkboxes don't send any data, so empty = unchecked = false.
				$sanitized[ $field ] = ! empty( $input[ $field ] );
			} elseif ( isset( $old_settings[ $field ] ) ) {
				// Field from a different tab - preserve existing value.
				$sanitized[ $field ] = (bool) $old_settings[ $field ];
			} else {
				// Use default.
				$sanitized[ $field ] = $defaults[ $field ];
			}
		}

		// Format to Kind mappings.
		if ( isset( $input['format_kind_mappings'] ) && is_array( $input['format_kind_mappings'] ) ) {
			$valid_kinds                       = [ '', 'note', 'article', 'reply', 'like', 'repost', 'bookmark', 'rsvp', 'checkin', 'listen', 'watch', 'read', 'event', 'photo', 'video', 'review' ];
			$sanitized['format_kind_mappings'] = [];

			foreach ( $input['format_kind_mappings'] as $format => $kind ) {
				$format = sanitize_key( $format );
				$kind   = sanitize_key( $kind );

				if ( in_array( $kind, $valid_kinds, true ) ) {
					$sanitized['format_kind_mappings'][ $format ] = $kind;
				}
			}
		} else {
			$sanitized['format_kind_mappings'] = $defaults['format_kind_mappings'];
		}

		// Integer fields.
		$int_fields = [
			'cache_duration'        => [
				'min' => 0,
				'max' => 604800,
			],
			'listen_default_rating' => [
				'min' => 0,
				'max' => 10,
			],
			'watch_default_rating'  => [
				'min' => 0,
				'max' => 10,
			],
			'rate_limit_delay'      => [
				'min' => 0,
				'max' => 10000,
			],
			'batch_size'            => [
				'min' => 1,
				'max' => 500,
			],
		];

		foreach ( $int_fields as $field => $constraints ) {
			$value               = isset( $input[ $field ] ) ? absint( $input[ $field ] ) : $defaults[ $field ];
			$sanitized[ $field ] = max( $constraints['min'], min( $constraints['max'], $value ) );
		}

		// Check if storage mode changed - need to flush rewrite rules.
		$old_settings = get_option( 'post_kinds_indieweb_settings', [] );
		$old_mode     = $old_settings['import_storage_mode'] ?? 'standard';
		$new_mode     = $sanitized['import_storage_mode'] ?? 'standard';

		if ( $old_mode !== $new_mode ) {
			// Schedule rewrite flush for next page load.
			update_option( 'post_kinds_indieweb_flush_rewrite', true );
		}

		// Sync start dates - per-source cutoff dates.
		if ( isset( $input['sync_start_dates'] ) && is_array( $input['sync_start_dates'] ) ) {
			$sanitized['sync_start_dates'] = [];
			$valid_sources                 = [
				'listenbrainz',
				'lastfm',
				'readwise_podcasts',
				'trakt_movies',
				'trakt_shows',
				'simkl',
				'hardcover',
				'readwise_books',
				'readwise_articles',
				'foursquare',
				'readwise_tweets',
				'readwise_supplementals',
			];

			foreach ( $input['sync_start_dates'] as $source => $date ) {
				$source = sanitize_key( $source );
				if ( in_array( $source, $valid_sources, true ) ) {
					// Validate and sanitize date (accept empty or valid ISO date).
					if ( empty( $date ) ) {
						// Empty = no cutoff, import all.
						$sanitized['sync_start_dates'][ $source ] = '';
					} else {
						// Validate date format (YYYY-MM-DD or ISO 8601).
						$timestamp = strtotime( $date );
						if ( false !== $timestamp ) {
							$sanitized['sync_start_dates'][ $source ] = gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
						}
					}
				}
			}
		} else {
			$sanitized['sync_start_dates'] = $old_settings['sync_start_dates'] ?? [];
		}

		/**
		 * Filter sanitized general settings.
		 *
		 * @param array $sanitized Sanitized settings.
		 * @param array $input Raw input.
		 */
		return apply_filters( 'post_kinds_indieweb_sanitize_general_settings', $sanitized, $input );
	}

	/**
	 * Sanitize API credentials.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized credentials.
	 */
	public function sanitize_api_credentials( array $input ): array {
		$sanitized = [];

		$api_configs = [
			'musicbrainz'  => [ 'app_name', 'app_version', 'contact' ],
			'listenbrainz' => [ 'token', 'username' ],
			'lastfm'       => [ 'api_key', 'api_secret', 'username', 'session_key' ],
			'tmdb'         => [ 'api_key', 'access_token' ],
			'trakt'        => [ 'client_id', 'client_secret', 'username', 'access_token', 'refresh_token', 'token_expires' ],
			'simkl'        => [ 'client_id', 'access_token' ],
			'tvmaze'       => [ 'api_key' ], // Optional premium key.
			'openlibrary'  => [], // No auth needed.
			'hardcover'    => [ 'api_token', 'username' ],
			'google_books' => [ 'api_key' ],
			'foursquare'   => [ 'api_key', 'client_id', 'client_secret', 'access_token', 'username' ],
			'nominatim'    => [ 'email' ],
			'readwise'     => [ 'access_token' ],
		];

		foreach ( $api_configs as $api => $fields ) {
			if ( ! isset( $input[ $api ] ) ) {
				continue;
			}

			$sanitized[ $api ] = [
				'enabled' => ! empty( $input[ $api ]['enabled'] ),
			];

			foreach ( $fields as $field ) {
				if ( isset( $input[ $api ][ $field ] ) ) {
					// Don't overwrite with placeholder asterisks.
					if ( preg_match( '/^\*+$/', $input[ $api ][ $field ] ) ) {
						// Keep existing value.
						$existing                    = get_option( 'post_kinds_indieweb_api_credentials', [] );
						$sanitized[ $api ][ $field ] = $existing[ $api ][ $field ] ?? '';
					} else {
						$sanitized[ $api ][ $field ] = sanitize_text_field( $input[ $api ][ $field ] );
					}
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize webhook settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize_webhook_settings( array $input ): array {
		$sanitized = [];

		$webhook_types = [ 'plex', 'jellyfin', 'trakt', 'listenbrainz', 'generic' ];

		foreach ( $webhook_types as $type ) {
			if ( ! isset( $input[ $type ] ) ) {
				continue;
			}

			$sanitized[ $type ] = [
				'enabled'     => ! empty( $input[ $type ]['enabled'] ),
				'auto_post'   => ! empty( $input[ $type ]['auto_post'] ),
				'post_status' => sanitize_text_field( $input[ $type ]['post_status'] ?? 'draft' ),
			];

			// Secret key handling.
			if ( isset( $input[ $type ]['secret'] ) ) {
				if ( preg_match( '/^\*+$/', $input[ $type ]['secret'] ) ) {
					$existing                     = get_option( 'post_kinds_indieweb_webhook_settings', [] );
					$sanitized[ $type ]['secret'] = $existing[ $type ]['secret'] ?? '';
				} else {
					$sanitized[ $type ]['secret'] = sanitize_text_field( $input[ $type ]['secret'] );
				}
			}

			// Type-specific settings.
			if ( 'plex' === $type || 'jellyfin' === $type ) {
				$sanitized[ $type ]['min_watch_percent'] = isset( $input[ $type ]['min_watch_percent'] )
					? min( 100, max( 0, absint( $input[ $type ]['min_watch_percent'] ) ) )
					: 80;
			}
		}

		return $sanitized;
	}

	/**
	 * Get available post kinds.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_post_kinds(): array {
		$kinds = [
			'listen'      => [
				'label' => __( 'Listen', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-format-audio',
			],
			'watch'       => [
				'label' => __( 'Watch', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-video-alt2',
			],
			'read'        => [
				'label' => __( 'Read', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-book',
			],
			'play'        => [
				'label' => __( 'Play', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-games',
			],
			'checkin'     => [
				'label' => __( 'Checkin', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-location',
			],
			'eat'         => [
				'label' => __( 'Eat', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-carrot',
			],
			'drink'       => [
				'label' => __( 'Drink', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-coffee',
			],
			'like'        => [
				'label' => __( 'Like', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-heart',
			],
			'favorite'    => [
				'label' => __( 'Favorite', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-star-filled',
			],
			'bookmark'    => [
				'label' => __( 'Bookmark', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-flag',
			],
			'reply'       => [
				'label' => __( 'Reply', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-format-chat',
			],
			'repost'      => [
				'label' => __( 'Repost', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-controls-repeat',
			],
			'rsvp'        => [
				'label' => __( 'RSVP', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-calendar-alt',
			],
			'jam'         => [
				'label' => __( 'Jam', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-playlist-audio',
			],
			'wish'        => [
				'label' => __( 'Wish', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-pressthis',
			],
			'mood'        => [
				'label' => __( 'Mood', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-smiley',
			],
			'acquisition' => [
				'label' => __( 'Acquisition', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-cart',
			],
			'recipe'      => [
				'label' => __( 'Recipe', 'post-kinds-for-indieweb' ),
				'icon'  => 'dashicons-clipboard',
			],
		];

		/**
		 * Filter available post kinds.
		 *
		 * @param array $kinds Post kinds configuration.
		 */
		return apply_filters( 'post_kinds_indieweb_post_kinds', $kinds );
	}

	/**
	 * AJAX handler: Test API connection.
	 *
	 * @return void
	 */
	public function ajax_test_api(): void {
		try {
			check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
			}

			$api = isset( $_POST['api'] ) ? sanitize_text_field( wp_unslash( $_POST['api'] ) ) : '';

			if ( empty( $api ) ) {
				wp_send_json_error( [ 'message' => __( 'No API specified.', 'post-kinds-for-indieweb' ) ] );
			}

			// Get API instance and test connection.
			$result = $this->test_api_connection( $api );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			wp_send_json_success(
				[
					'message' => __( 'Connection successful!', 'post-kinds-for-indieweb' ),
					'data'    => $result,
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		} catch ( \Error $e ) {
			wp_send_json_error( [ 'message' => __( 'Server error: ', 'post-kinds-for-indieweb' ) . $e->getMessage() ] );
		}
	}

	/**
	 * Test an API connection.
	 *
	 * @param string $api API identifier.
	 * @return array<string, mixed>|\WP_Error Test result or error.
	 */
	private function test_api_connection( string $api ) {
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$api_creds   = $credentials[ $api ] ?? [];

		if ( empty( $api_creds['enabled'] ) ) {
			return new \WP_Error( 'disabled', __( 'API is not enabled.', 'post-kinds-for-indieweb' ) );
		}

		$class_map = [
			'lastfm'       => 'PostKindsForIndieWeb\\APIs\\LastFM',
			'tmdb'         => 'PostKindsForIndieWeb\\APIs\\TMDB',
			'trakt'        => 'PostKindsForIndieWeb\\APIs\\Trakt',
			'simkl'        => 'PostKindsForIndieWeb\\APIs\\Simkl',
			'tvmaze'       => 'PostKindsForIndieWeb\\APIs\\TVmaze',
			'openlibrary'  => 'PostKindsForIndieWeb\\APIs\\OpenLibrary',
			'hardcover'    => 'PostKindsForIndieWeb\\APIs\\Hardcover',
			'google_books' => 'PostKindsForIndieWeb\\APIs\\GoogleBooks',
			'foursquare'   => 'PostKindsForIndieWeb\\APIs\\Foursquare',
			'nominatim'    => 'PostKindsForIndieWeb\\APIs\\Nominatim',
			'readwise'     => 'PostKindsForIndieWeb\\APIs\\Readwise',
		];

		if ( ! isset( $class_map[ $api ] ) ) {
			return new \WP_Error( 'unknown', __( 'Unknown API.', 'post-kinds-for-indieweb' ) );
		}

		$class = $class_map[ $api ];
		if ( ! class_exists( $class ) ) {
			return new \WP_Error( 'missing', __( 'API class not found.', 'post-kinds-for-indieweb' ) );
		}

		try {
			// API classes read credentials from options, no constructor params needed.
			$instance = new $class();
			$result   = $instance->test_connection();

			// test_connection() returns bool.
			if ( false === $result ) {
				return new \WP_Error(
					'connection_failed',
					__( 'Connection test failed. Please check your credentials.', 'post-kinds-for-indieweb' )
				);
			}

			return [
				'api'     => $api,
				'status'  => 'connected',
				'details' => $result,
			];
		} catch ( \Throwable $e ) {
			// Catch both Exception and Error (PHP 7+).
			return new \WP_Error(
				'exception',
				sprintf(
					/* translators: 1: Error class name, 2: Error message, 3: File, 4: Line number */
					__( '%1$s: %2$s in %3$s:%4$d', 'post-kinds-for-indieweb' ),
					get_class( $e ),
					$e->getMessage(),
					basename( $e->getFile() ),
					$e->getLine()
				)
			);
		}
	}

	/**
	 * AJAX handler: Clear cache.
	 *
	 * @return void
	 */
	public function ajax_clear_cache(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'all';

		global $wpdb;

		$deleted = 0;

		if ( 'all' === $type || 'api' === $type ) {
			// Clear API response transients.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup.
			$deleted += $wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_postkind_indieweb_api_%'"
			);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup.
			$wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_postkind_indieweb_api_%'"
			);
		}

		if ( 'all' === $type || 'metadata' === $type ) {
			// Clear metadata cache transients.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup.
			$deleted += $wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_postkind_indieweb_meta_%'"
			);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup.
			$wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_postkind_indieweb_meta_%'"
			);
		}

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %d: Number of cache entries cleared */
					__( 'Cleared %d cached entries.', 'post-kinds-for-indieweb' ),
					$deleted
				),
			]
		);
	}

	/**
	 * AJAX handler: Lookup media.
	 *
	 * @return void
	 */
	public function ajax_lookup_media(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$type  = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

		if ( empty( $type ) || empty( $query ) ) {
			wp_send_json_error( [ 'message' => __( 'Type and query are required.', 'post-kinds-for-indieweb' ) ] );
		}

		$results = $this->lookup_media( $type, $query );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( [ 'message' => $results->get_error_message() ] );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	/**
	 * Lookup media by type.
	 *
	 * @param string $type Media type (music, movie, tv, book, podcast, venue).
	 * @param string $query Search query.
	 * @return array<int, array<string, mixed>>|\WP_Error Search results or error.
	 */
	private function lookup_media( string $type, string $query ) {
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );

		switch ( $type ) {
			case 'music':
				if ( ! empty( $credentials['musicbrainz']['enabled'] ) ) {
					$api = new \PostKindsForIndieWeb\APIs\MusicBrainz( $credentials['musicbrainz'] );
					return $api->search_recordings( $query, 10 );
				}
				break;

			case 'movie':
			case 'tv':
				if ( ! empty( $credentials['tmdb']['enabled'] ) ) {
					$api = new \PostKindsForIndieWeb\APIs\TMDB( $credentials['tmdb'] );
					if ( 'movie' === $type ) {
						return $api->search_movies( $query );
					} else {
						return $api->search_tv( $query );
					}
				}
				break;

			case 'book':
				// Try Open Library first (no auth needed).
				$api = new \PostKindsForIndieWeb\APIs\OpenLibrary( [] );
				return $api->search( $query, 10 );

			case 'podcast':
				if ( ! empty( $credentials['podcastindex']['enabled'] ) ) {
					$api = new \PostKindsForIndieWeb\APIs\PodcastIndex( $credentials['podcastindex'] );
					return $api->search_podcasts( $query );
				}
				break;

			case 'venue':
				if ( ! empty( $credentials['foursquare']['enabled'] ) ) {
					$api = new \PostKindsForIndieWeb\APIs\Foursquare( $credentials['foursquare'] );
					// Would need lat/lng for this.
					return new \WP_Error( 'needs_location', __( 'Location required for venue search.', 'post-kinds-for-indieweb' ) );
				}
				break;
		}

		return new \WP_Error( 'no_api', __( 'No API available for this media type.', 'post-kinds-for-indieweb' ) );
	}

	/**
	 * AJAX handler: Get import status.
	 *
	 * @return void
	 */
	public function ajax_get_import_status(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';

		if ( empty( $import_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Import ID required.', 'post-kinds-for-indieweb' ) ] );
		}

		$import_manager = new \PostKindsForIndieWeb\Import_Manager();
		$status         = $import_manager->get_status( $import_id );

		if ( is_wp_error( $status ) ) {
			wp_send_json_error( [ 'message' => $status->get_error_message() ] );
		}

		wp_send_json_success( $status );
	}

	/**
	 * Get plugin instance.
	 *
	 * @return Plugin
	 */
	public function get_plugin(): Plugin {
		return $this->plugin;
	}

	/**
	 * Get a settings value.
	 *
	 * @param string $key Settings key.
	 * @param mixed  $default Default value.
	 * @return mixed Setting value.
	 */
	public function get_setting( string $key, $default = null ) {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->get_default_settings() );
		return $settings[ $key ] ?? $default;
	}

	/**
	 * Get page hook suffix.
	 *
	 * @param string $page Page identifier.
	 * @return string|null Hook suffix or null.
	 */
	public function get_page_hook( string $page ): ?string {
		return $this->page_hooks[ $page ] ?? null;
	}

	/**
	 * AJAX handler: Import Foursquare checkins.
	 *
	 * @return void
	 */
	public function ajax_foursquare_import(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$foursquare_sync = $this->plugin->get_checkin_sync_service( 'foursquare' );

		if ( ! $foursquare_sync ) {
			wp_send_json_error( [ 'message' => __( 'Foursquare sync service not available.', 'post-kinds-for-indieweb' ) ] );
		}

		if ( ! $foursquare_sync->is_connected() ) {
			wp_send_json_error( [ 'message' => __( 'Foursquare not connected. Please authorize first.', 'post-kinds-for-indieweb' ) ] );
		}

		try {
			$result = $foursquare_sync->import_checkins();

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			wp_send_json_success(
				[
					'message'  => sprintf(
						/* translators: %d: Number of checkins imported */
						__( 'Imported %d new checkins.', 'post-kinds-for-indieweb' ),
						$result['imported'] ?? 0
					),
					'imported' => $result['imported'] ?? 0,
					'skipped'  => $result['skipped'] ?? 0,
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * AJAX handler: Disconnect Foursquare.
	 *
	 * @return void
	 */
	public function ajax_foursquare_disconnect(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );

		if ( isset( $credentials['foursquare'] ) ) {
			// Remove OAuth tokens but keep API key and client credentials.
			unset( $credentials['foursquare']['access_token'] );
			unset( $credentials['foursquare']['username'] );
			update_option( 'post_kinds_indieweb_api_credentials', $credentials );
		}

		wp_send_json_success( [ 'message' => __( 'Disconnected from Foursquare.', 'post-kinds-for-indieweb' ) ] );
	}
}

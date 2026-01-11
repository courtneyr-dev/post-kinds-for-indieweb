<?php
/**
 * Main Admin Controller
 *
 * Handles admin initialization, menu registration, and asset loading.
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

namespace ReactionsForIndieWeb\Admin;

use ReactionsForIndieWeb\Plugin;

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
     * Admin page hook suffixes.
     *
     * @var array<string, string>
     */
    private array $page_hooks = array();

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
        $this->settings_page = new Settings_Page( $this );
        $this->api_settings  = new API_Settings( $this );
        $this->import_page   = new Import_Page( $this );
        $this->webhooks_page = new Webhooks_Page( $this );
        $this->meta_boxes    = new Meta_Boxes( $this );
        $this->quick_post    = new Quick_Post( $this );

        // Register hooks.
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( \REACTIONS_INDIEWEB_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );

        // Initialize sub-components.
        $this->settings_page->init();
        $this->api_settings->init();
        $this->import_page->init();
        $this->webhooks_page->init();
        $this->meta_boxes->init();
        $this->quick_post->init();

        // AJAX handlers.
        add_action( 'wp_ajax_reactions_indieweb_test_api', array( $this, 'ajax_test_api' ) );
        add_action( 'wp_ajax_reactions_indieweb_clear_cache', array( $this, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_reactions_indieweb_lookup_media', array( $this, 'ajax_lookup_media' ) );
        add_action( 'wp_ajax_reactions_indieweb_get_import_status', array( $this, 'ajax_get_import_status' ) );
        add_action( 'wp_ajax_reactions_foursquare_import', array( $this, 'ajax_foursquare_import' ) );
        add_action( 'wp_ajax_reactions_foursquare_disconnect', array( $this, 'ajax_foursquare_disconnect' ) );
    }

    /**
     * Register admin menu pages.
     *
     * @return void
     */
    public function register_menu(): void {
        // Main menu page.
        $this->page_hooks['main'] = add_menu_page(
            __( 'Reactions', 'reactions-indieweb' ),
            __( 'Reactions', 'reactions-indieweb' ),
            'manage_options',
            'reactions-indieweb',
            array( $this->settings_page, 'render' ),
            'dashicons-heart',
            30
        );

        // Settings submenu (same as main).
        $this->page_hooks['settings'] = add_submenu_page(
            'reactions-indieweb',
            __( 'Settings', 'reactions-indieweb' ),
            __( 'Settings', 'reactions-indieweb' ),
            'manage_options',
            'reactions-indieweb',
            array( $this->settings_page, 'render' )
        );

        // API Connections submenu.
        $this->page_hooks['apis'] = add_submenu_page(
            'reactions-indieweb',
            __( 'API Connections', 'reactions-indieweb' ),
            __( 'API Connections', 'reactions-indieweb' ),
            'manage_options',
            'reactions-indieweb-apis',
            array( $this->api_settings, 'render' )
        );

        // Import submenu.
        $this->page_hooks['import'] = add_submenu_page(
            'reactions-indieweb',
            __( 'Import', 'reactions-indieweb' ),
            __( 'Import', 'reactions-indieweb' ),
            'manage_options',
            'reactions-indieweb-import',
            array( $this->import_page, 'render' )
        );

        // Webhooks submenu.
        $this->page_hooks['webhooks'] = add_submenu_page(
            'reactions-indieweb',
            __( 'Webhooks', 'reactions-indieweb' ),
            __( 'Webhooks', 'reactions-indieweb' ),
            'manage_options',
            'reactions-indieweb-webhooks',
            array( $this->webhooks_page, 'render' )
        );

        // Quick Post submenu.
        $this->page_hooks['quick_post'] = add_submenu_page(
            'reactions-indieweb',
            __( 'Quick Post', 'reactions-indieweb' ),
            __( 'Quick Post', 'reactions-indieweb' ),
            'edit_posts',
            'reactions-indieweb-quick-post',
            array( $this->quick_post, 'render' )
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
            'reactions_indieweb_general',
            'reactions_indieweb_settings',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_general_settings' ),
                'default'           => $this->get_default_settings(),
            )
        );

        // API credentials (stored separately for security).
        register_setting(
            'reactions_indieweb_apis',
            'reactions_indieweb_api_credentials',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_api_credentials' ),
                'default'           => array(),
            )
        );

        // Webhook settings.
        register_setting(
            'reactions_indieweb_webhooks',
            'reactions_indieweb_webhook_settings',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_webhook_settings' ),
                'default'           => array(),
            )
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
        $screen = get_current_screen();
        $is_post_edit = $screen && 'post' === $screen->base;

        if ( ! $is_our_page && ! $is_post_edit ) {
            return;
        }

        // Core styles.
        wp_enqueue_style(
            'reactions-indieweb-admin',
            \REACTIONS_INDIEWEB_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            \REACTIONS_INDIEWEB_VERSION
        );

        // Core scripts.
        wp_enqueue_script(
            'reactions-indieweb-admin',
            \REACTIONS_INDIEWEB_PLUGIN_URL . 'admin/js/admin.js',
            array( 'jquery', 'wp-util', 'wp-api-fetch' ),
            \REACTIONS_INDIEWEB_VERSION,
            true
        );

        // Localize script.
        wp_localize_script(
            'reactions-indieweb-admin',
            'reactionsIndieWeb',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'restUrl'   => rest_url( 'reactions-indieweb/v1/' ),
                'nonce'     => wp_create_nonce( 'reactions_indieweb_admin' ),
                'restNonce' => wp_create_nonce( 'wp_rest' ),
                'strings'   => array(
                    'confirmDelete'   => __( 'Are you sure you want to delete this?', 'reactions-indieweb' ),
                    'confirmClear'    => __( 'Are you sure you want to clear all cached data?', 'reactions-indieweb' ),
                    'testingApi'      => __( 'Testing connection...', 'reactions-indieweb' ),
                    'testSuccess'     => __( 'Connection successful!', 'reactions-indieweb' ),
                    'testFailed'      => __( 'Connection failed: ', 'reactions-indieweb' ),
                    'importing'       => __( 'Importing...', 'reactions-indieweb' ),
                    'importComplete'  => __( 'Import complete!', 'reactions-indieweb' ),
                    'lookingUp'       => __( 'Looking up...', 'reactions-indieweb' ),
                    'noResults'       => __( 'No results found.', 'reactions-indieweb' ),
                    'error'           => __( 'An error occurred.', 'reactions-indieweb' ),
                    'saved'           => __( 'Settings saved.', 'reactions-indieweb' ),
                    'copied'          => __( 'Copied to clipboard!', 'reactions-indieweb' ),
                ),
                'postKinds' => $this->get_post_kinds(),
            )
        );

        // Media uploader on quick post.
        if ( isset( $this->page_hooks['quick_post'] ) && $hook_suffix === $this->page_hooks['quick_post'] ) {
            wp_enqueue_media();
        }

        // Select2 for enhanced dropdowns.
        if ( $is_our_page ) {
            wp_enqueue_style(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                array(),
                '4.1.0'
            );
            wp_enqueue_script(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
                array( 'jquery' ),
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
        $active_plugins   = (array) get_option( 'active_plugins', array() );
        $indieblocks_active = in_array( 'indieblocks/indieblocks.php', $active_plugins, true );

        if ( ! $indieblocks_active ) {
            echo '<div class="notice notice-warning"><p>';
            printf(
                /* translators: %s: Plugin name */
                esc_html__( '%s requires IndieBlocks to be installed and activated for full functionality.', 'reactions-indieweb' ),
                '<strong>Reactions for IndieWeb</strong>'
            );
            echo '</p></div>';
        }

        // Show success message after settings save.
        if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e( 'Settings saved successfully.', 'reactions-indieweb' );
            echo '</p></div>';
        }

        // Show active import notice.
        $active_imports = get_option( 'reactions_indieweb_active_imports', array() );
        if ( ! empty( $active_imports ) ) {
            $count = count( $active_imports );
            echo '<div class="notice notice-info"><p>';
            printf(
                /* translators: %1$d: Number of imports, %2$s: Import page URL */
                esc_html( _n(
                    '%1$d import is currently running. <a href="%2$s">View progress</a>',
                    '%1$d imports are currently running. <a href="%2$s">View progress</a>',
                    $count,
                    'reactions-indieweb'
                ) ),
                (int) $count,
                esc_url( admin_url( 'admin.php?page=reactions-indieweb-import' ) )
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
            admin_url( 'admin.php?page=reactions-indieweb' ),
            __( 'Settings', 'reactions-indieweb' )
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
        return array(
            // General settings.
            'default_post_status'     => 'publish',
            'default_post_format'     => 'aside',
            'enable_microformats'     => true,
            'enable_syndication'      => true,

            // Content settings.
            'auto_fetch_metadata'     => true,
            'cache_duration'          => 86400, // 24 hours.
            'image_handling'          => 'sideload', // 'sideload', 'hotlink', 'none'.

            // Listen settings.
            'listen_default_rating'   => 0,
            'listen_auto_import'      => false,
            'listen_import_source'    => 'listenbrainz',

            // Watch settings.
            'watch_default_rating'    => 0,
            'watch_auto_import'       => false,
            'watch_import_source'     => 'trakt',
            'watch_include_rewatches' => false,

            // Read settings.
            'read_default_status'     => 'to-read',
            'read_auto_import'        => false,
            'read_import_source'      => 'hardcover',

            // Checkin settings.
            'checkin_auto_import'     => false,
            'checkin_privacy'         => 'public',
            'checkin_include_coords'  => true,

            // Performance.
            'rate_limit_delay'        => 1000, // milliseconds.
            'batch_size'              => 50,
            'enable_background_sync'  => false,

            // Import storage.
            'import_storage_mode'     => 'standard', // 'standard', 'cpt', 'hidden'.

            // Sync start dates - per-source cutoff dates for auto-import.
            // Empty = import all history. ISO 8601 date = only import items after this date.
            'sync_start_dates'        => array(),

            // Post Format sync.
            'sync_formats_to_kinds'   => true,
            'format_kind_mappings'    => array(
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
            ),
        );
    }

    /**
     * Sanitize general settings.
     *
     * @param array<string, mixed> $input Raw input.
     * @return array<string, mixed> Sanitized settings.
     */
    public function sanitize_general_settings( array $input ): array {
        $defaults  = $this->get_default_settings();
        $sanitized = array();

        // String fields.
        $string_fields = array(
            'default_post_status',
            'default_post_format',
            'image_handling',
            'listen_import_source',
            'watch_import_source',
            'read_default_status',
            'read_import_source',
            'checkin_privacy',
            'import_storage_mode',
        );

        foreach ( $string_fields as $field ) {
            $sanitized[ $field ] = isset( $input[ $field ] )
                ? sanitize_text_field( $input[ $field ] )
                : $defaults[ $field ];
        }

        // Boolean fields.
        $bool_fields = array(
            'enable_microformats',
            'enable_syndication',
            'auto_fetch_metadata',
            'listen_auto_import',
            'watch_auto_import',
            'watch_include_rewatches',
            'read_auto_import',
            'checkin_auto_import',
            'checkin_include_coords',
            'enable_background_sync',
            'sync_formats_to_kinds',
        );

        foreach ( $bool_fields as $field ) {
            $sanitized[ $field ] = ! empty( $input[ $field ] );
        }

        // Format to Kind mappings.
        if ( isset( $input['format_kind_mappings'] ) && is_array( $input['format_kind_mappings'] ) ) {
            $valid_kinds = array( '', 'note', 'article', 'reply', 'like', 'repost', 'bookmark', 'rsvp', 'checkin', 'listen', 'watch', 'read', 'event', 'photo', 'video', 'review' );
            $sanitized['format_kind_mappings'] = array();

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
        $int_fields = array(
            'cache_duration'       => array( 'min' => 0, 'max' => 604800 ),
            'listen_default_rating' => array( 'min' => 0, 'max' => 10 ),
            'watch_default_rating'  => array( 'min' => 0, 'max' => 10 ),
            'rate_limit_delay'      => array( 'min' => 0, 'max' => 10000 ),
            'batch_size'            => array( 'min' => 1, 'max' => 500 ),
        );

        foreach ( $int_fields as $field => $constraints ) {
            $value = isset( $input[ $field ] ) ? absint( $input[ $field ] ) : $defaults[ $field ];
            $sanitized[ $field ] = max( $constraints['min'], min( $constraints['max'], $value ) );
        }

        // Check if storage mode changed - need to flush rewrite rules.
        $old_settings = get_option( 'reactions_indieweb_settings', array() );
        $old_mode     = $old_settings['import_storage_mode'] ?? 'standard';
        $new_mode     = $sanitized['import_storage_mode'] ?? 'standard';

        if ( $old_mode !== $new_mode ) {
            // Schedule rewrite flush for next page load.
            update_option( 'reactions_indieweb_flush_rewrite', true );
        }

        // Sync start dates - per-source cutoff dates.
        if ( isset( $input['sync_start_dates'] ) && is_array( $input['sync_start_dates'] ) ) {
            $sanitized['sync_start_dates'] = array();
            $valid_sources = array(
                'listenbrainz', 'lastfm', 'readwise_podcasts',
                'trakt_movies', 'trakt_shows', 'simkl',
                'hardcover', 'readwise_books', 'readwise_articles',
                'foursquare', 'readwise_tweets', 'readwise_supplementals',
            );

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
            $sanitized['sync_start_dates'] = $old_settings['sync_start_dates'] ?? array();
        }

        /**
         * Filter sanitized general settings.
         *
         * @param array $sanitized Sanitized settings.
         * @param array $input Raw input.
         */
        return apply_filters( 'reactions_indieweb_sanitize_general_settings', $sanitized, $input );
    }

    /**
     * Sanitize API credentials.
     *
     * @param array<string, mixed> $input Raw input.
     * @return array<string, mixed> Sanitized credentials.
     */
    public function sanitize_api_credentials( array $input ): array {
        $sanitized = array();

        $api_configs = array(
            'musicbrainz'    => array( 'app_name', 'app_version', 'contact' ),
            'listenbrainz'   => array( 'token', 'username' ),
            'lastfm'         => array( 'api_key', 'api_secret', 'username', 'session_key' ),
            'tmdb'           => array( 'api_key', 'access_token' ),
            'trakt'          => array( 'client_id', 'client_secret', 'username', 'access_token', 'refresh_token', 'token_expires' ),
            'simkl'          => array( 'client_id', 'access_token' ),
            'tvmaze'         => array( 'api_key' ), // Optional premium key.
            'openlibrary'    => array(), // No auth needed.
            'hardcover'      => array( 'api_token', 'username' ),
            'google_books'   => array( 'api_key' ),
            'foursquare'     => array( 'api_key', 'client_id', 'client_secret', 'access_token', 'username' ),
            'nominatim'      => array( 'email' ),
            'readwise'       => array( 'access_token' ),
        );

        foreach ( $api_configs as $api => $fields ) {
            if ( ! isset( $input[ $api ] ) ) {
                continue;
            }

            $sanitized[ $api ] = array(
                'enabled' => ! empty( $input[ $api ]['enabled'] ),
            );

            foreach ( $fields as $field ) {
                if ( isset( $input[ $api ][ $field ] ) ) {
                    // Don't overwrite with placeholder asterisks.
                    if ( preg_match( '/^\*+$/', $input[ $api ][ $field ] ) ) {
                        // Keep existing value.
                        $existing = get_option( 'reactions_indieweb_api_credentials', array() );
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
        $sanitized = array();

        $webhook_types = array( 'plex', 'jellyfin', 'trakt', 'listenbrainz', 'generic' );

        foreach ( $webhook_types as $type ) {
            if ( ! isset( $input[ $type ] ) ) {
                continue;
            }

            $sanitized[ $type ] = array(
                'enabled'     => ! empty( $input[ $type ]['enabled'] ),
                'auto_post'   => ! empty( $input[ $type ]['auto_post'] ),
                'post_status' => sanitize_text_field( $input[ $type ]['post_status'] ?? 'draft' ),
            );

            // Secret key handling.
            if ( isset( $input[ $type ]['secret'] ) ) {
                if ( preg_match( '/^\*+$/', $input[ $type ]['secret'] ) ) {
                    $existing = get_option( 'reactions_indieweb_webhook_settings', array() );
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
        $kinds = array(
            'listen'  => array(
                'label' => __( 'Listen', 'reactions-indieweb' ),
                'icon'  => 'dashicons-format-audio',
            ),
            'watch'   => array(
                'label' => __( 'Watch', 'reactions-indieweb' ),
                'icon'  => 'dashicons-video-alt2',
            ),
            'read'    => array(
                'label' => __( 'Read', 'reactions-indieweb' ),
                'icon'  => 'dashicons-book',
            ),
            'checkin' => array(
                'label' => __( 'Checkin', 'reactions-indieweb' ),
                'icon'  => 'dashicons-location',
            ),
            'like'    => array(
                'label' => __( 'Like', 'reactions-indieweb' ),
                'icon'  => 'dashicons-heart',
            ),
            'reply'   => array(
                'label' => __( 'Reply', 'reactions-indieweb' ),
                'icon'  => 'dashicons-format-chat',
            ),
            'repost'  => array(
                'label' => __( 'Repost', 'reactions-indieweb' ),
                'icon'  => 'dashicons-controls-repeat',
            ),
            'bookmark' => array(
                'label' => __( 'Bookmark', 'reactions-indieweb' ),
                'icon'  => 'dashicons-bookmark',
            ),
            'rsvp'    => array(
                'label' => __( 'RSVP', 'reactions-indieweb' ),
                'icon'  => 'dashicons-calendar-alt',
            ),
        );

        /**
         * Filter available post kinds.
         *
         * @param array $kinds Post kinds configuration.
         */
        return apply_filters( 'reactions_indieweb_post_kinds', $kinds );
    }

    /**
     * AJAX handler: Test API connection.
     *
     * @return void
     */
    public function ajax_test_api(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $api = isset( $_POST['api'] ) ? sanitize_text_field( wp_unslash( $_POST['api'] ) ) : '';

        if ( empty( $api ) ) {
            wp_send_json_error( array( 'message' => __( 'No API specified.', 'reactions-indieweb' ) ) );
        }

        // Get API instance and test connection.
        $result = $this->test_api_connection( $api );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Connection successful!', 'reactions-indieweb' ),
            'data'    => $result,
        ) );
    }

    /**
     * Test an API connection.
     *
     * @param string $api API identifier.
     * @return array<string, mixed>|\WP_Error Test result or error.
     */
    private function test_api_connection( string $api ) {
        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $api_creds   = $credentials[ $api ] ?? array();

        if ( empty( $api_creds['enabled'] ) ) {
            return new \WP_Error( 'disabled', __( 'API is not enabled.', 'reactions-indieweb' ) );
        }

        $class_map = array(
            'lastfm'        => 'ReactionsForIndieWeb\\APIs\\LastFM',
            'tmdb'          => 'ReactionsForIndieWeb\\APIs\\TMDB',
            'trakt'         => 'ReactionsForIndieWeb\\APIs\\Trakt',
            'simkl'         => 'ReactionsForIndieWeb\\APIs\\Simkl',
            'tvmaze'        => 'ReactionsForIndieWeb\\APIs\\TVmaze',
            'openlibrary'   => 'ReactionsForIndieWeb\\APIs\\OpenLibrary',
            'hardcover'     => 'ReactionsForIndieWeb\\APIs\\Hardcover',
            'google_books'  => 'ReactionsForIndieWeb\\APIs\\GoogleBooks',
            'foursquare'    => 'ReactionsForIndieWeb\\APIs\\Foursquare',
            'nominatim'     => 'ReactionsForIndieWeb\\APIs\\Nominatim',
            'readwise'      => 'ReactionsForIndieWeb\\APIs\\Readwise',
        );

        if ( ! isset( $class_map[ $api ] ) ) {
            return new \WP_Error( 'unknown', __( 'Unknown API.', 'reactions-indieweb' ) );
        }

        $class = $class_map[ $api ];
        if ( ! class_exists( $class ) ) {
            return new \WP_Error( 'missing', __( 'API class not found.', 'reactions-indieweb' ) );
        }

        try {
            $instance = new $class( $api_creds );
            $result   = $instance->test_connection();

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return array(
                'api'     => $api,
                'status'  => 'connected',
                'details' => $result,
            );
        } catch ( \Exception $e ) {
            return new \WP_Error( 'exception', $e->getMessage() );
        }
    }

    /**
     * AJAX handler: Clear cache.
     *
     * @return void
     */
    public function ajax_clear_cache(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'all';

        global $wpdb;

        $deleted = 0;

        if ( 'all' === $type || 'api' === $type ) {
            // Clear API response transients.
            $deleted += $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_reactions_indieweb_api_%'"
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_reactions_indieweb_api_%'"
            );
        }

        if ( 'all' === $type || 'metadata' === $type ) {
            // Clear metadata cache transients.
            $deleted += $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_reactions_indieweb_meta_%'"
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_reactions_indieweb_meta_%'"
            );
        }

        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: %d: Number of cache entries cleared */
                __( 'Cleared %d cached entries.', 'reactions-indieweb' ),
                $deleted
            ),
        ) );
    }

    /**
     * AJAX handler: Lookup media.
     *
     * @return void
     */
    public function ajax_lookup_media(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $type  = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

        if ( empty( $type ) || empty( $query ) ) {
            wp_send_json_error( array( 'message' => __( 'Type and query are required.', 'reactions-indieweb' ) ) );
        }

        $results = $this->lookup_media( $type, $query );

        if ( is_wp_error( $results ) ) {
            wp_send_json_error( array( 'message' => $results->get_error_message() ) );
        }

        wp_send_json_success( array( 'results' => $results ) );
    }

    /**
     * Lookup media by type.
     *
     * @param string $type Media type (music, movie, tv, book, podcast, venue).
     * @param string $query Search query.
     * @return array<int, array<string, mixed>>|\WP_Error Search results or error.
     */
    private function lookup_media( string $type, string $query ) {
        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );

        switch ( $type ) {
            case 'music':
                if ( ! empty( $credentials['musicbrainz']['enabled'] ) ) {
                    $api = new \ReactionsForIndieWeb\APIs\MusicBrainz( $credentials['musicbrainz'] );
                    return $api->search_recordings( $query, 10 );
                }
                break;

            case 'movie':
            case 'tv':
                if ( ! empty( $credentials['tmdb']['enabled'] ) ) {
                    $api = new \ReactionsForIndieWeb\APIs\TMDB( $credentials['tmdb'] );
                    if ( 'movie' === $type ) {
                        return $api->search_movies( $query );
                    } else {
                        return $api->search_tv( $query );
                    }
                }
                break;

            case 'book':
                // Try Open Library first (no auth needed).
                $api = new \ReactionsForIndieWeb\APIs\OpenLibrary( array() );
                return $api->search( $query, 10 );

            case 'podcast':
                if ( ! empty( $credentials['podcastindex']['enabled'] ) ) {
                    $api = new \ReactionsForIndieWeb\APIs\PodcastIndex( $credentials['podcastindex'] );
                    return $api->search_podcasts( $query );
                }
                break;

            case 'venue':
                if ( ! empty( $credentials['foursquare']['enabled'] ) ) {
                    $api = new \ReactionsForIndieWeb\APIs\Foursquare( $credentials['foursquare'] );
                    // Would need lat/lng for this.
                    return new \WP_Error( 'needs_location', __( 'Location required for venue search.', 'reactions-indieweb' ) );
                }
                break;
        }

        return new \WP_Error( 'no_api', __( 'No API available for this media type.', 'reactions-indieweb' ) );
    }

    /**
     * AJAX handler: Get import status.
     *
     * @return void
     */
    public function ajax_get_import_status(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';

        if ( empty( $import_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Import ID required.', 'reactions-indieweb' ) ) );
        }

        $import_manager = new \ReactionsForIndieWeb\Import_Manager();
        $status = $import_manager->get_status( $import_id );

        if ( is_wp_error( $status ) ) {
            wp_send_json_error( array( 'message' => $status->get_error_message() ) );
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
        $settings = get_option( 'reactions_indieweb_settings', $this->get_default_settings() );
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
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $foursquare_sync = $this->plugin->get_checkin_sync_service( 'foursquare' );

        if ( ! $foursquare_sync ) {
            wp_send_json_error( array( 'message' => __( 'Foursquare sync service not available.', 'reactions-indieweb' ) ) );
        }

        if ( ! $foursquare_sync->is_connected() ) {
            wp_send_json_error( array( 'message' => __( 'Foursquare not connected. Please authorize first.', 'reactions-indieweb' ) ) );
        }

        try {
            $result = $foursquare_sync->import_checkins();

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %d: Number of checkins imported */
                    __( 'Imported %d new checkins.', 'reactions-indieweb' ),
                    $result['imported'] ?? 0
                ),
                'imported' => $result['imported'] ?? 0,
                'skipped'  => $result['skipped'] ?? 0,
            ) );
        } catch ( \Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler: Disconnect Foursquare.
     *
     * @return void
     */
    public function ajax_foursquare_disconnect(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );

        if ( isset( $credentials['foursquare'] ) ) {
            // Remove OAuth tokens but keep API key and client credentials.
            unset( $credentials['foursquare']['access_token'] );
            unset( $credentials['foursquare']['username'] );
            update_option( 'reactions_indieweb_api_credentials', $credentials );
        }

        wp_send_json_success( array( 'message' => __( 'Disconnected from Foursquare.', 'reactions-indieweb' ) ) );
    }
}

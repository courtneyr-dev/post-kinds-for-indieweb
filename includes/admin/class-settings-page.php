<?php
/**
 * Settings Page
 *
 * Main settings page for plugin configuration.
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

namespace ReactionsForIndieWeb\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings page class.
 */
class Settings_Page {

    /**
     * Admin instance.
     *
     * @var Admin
     */
    private Admin $admin;

    /**
     * Active tab.
     *
     * @var string
     */
    private string $active_tab = 'general';

    /**
     * Constructor.
     *
     * @param Admin $admin Admin instance.
     */
    public function __construct( Admin $admin ) {
        $this->admin = $admin;
    }

    /**
     * Initialize settings page.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'register_sections_and_fields' ) );
    }

    /**
     * Register settings sections and fields.
     *
     * @return void
     */
    public function register_sections_and_fields(): void {
        // General section.
        add_settings_section(
            'reactions_indieweb_general_section',
            __( 'General Settings', 'reactions-indieweb' ),
            array( $this, 'render_general_section' ),
            'reactions_indieweb_general'
        );

        $this->add_general_fields();

        // Content section.
        add_settings_section(
            'reactions_indieweb_content_section',
            __( 'Content Settings', 'reactions-indieweb' ),
            array( $this, 'render_content_section' ),
            'reactions_indieweb_content'
        );

        $this->add_content_fields();

        // Listen section.
        add_settings_section(
            'reactions_indieweb_listen_section',
            __( 'Listen Posts', 'reactions-indieweb' ),
            array( $this, 'render_listen_section' ),
            'reactions_indieweb_listen'
        );

        $this->add_listen_fields();

        // Watch section.
        add_settings_section(
            'reactions_indieweb_watch_section',
            __( 'Watch Posts', 'reactions-indieweb' ),
            array( $this, 'render_watch_section' ),
            'reactions_indieweb_watch'
        );

        $this->add_watch_fields();

        // Read section.
        add_settings_section(
            'reactions_indieweb_read_section',
            __( 'Read Posts', 'reactions-indieweb' ),
            array( $this, 'render_read_section' ),
            'reactions_indieweb_read'
        );

        $this->add_read_fields();

        // Checkin section.
        add_settings_section(
            'reactions_indieweb_checkin_section',
            __( 'Checkin Posts', 'reactions-indieweb' ),
            array( $this, 'render_checkin_section' ),
            'reactions_indieweb_checkin'
        );

        $this->add_checkin_fields();

        // Performance section.
        add_settings_section(
            'reactions_indieweb_performance_section',
            __( 'Performance', 'reactions-indieweb' ),
            array( $this, 'render_performance_section' ),
            'reactions_indieweb_performance'
        );

        $this->add_performance_fields();
    }

    /**
     * Add general settings fields.
     *
     * @return void
     */
    private function add_general_fields(): void {
        add_settings_field(
            'default_post_status',
            __( 'Default Post Status', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'      => 'default_post_status',
                'options' => array(
                    'publish' => __( 'Published', 'reactions-indieweb' ),
                    'draft'   => __( 'Draft', 'reactions-indieweb' ),
                    'pending' => __( 'Pending Review', 'reactions-indieweb' ),
                    'private' => __( 'Private', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'Default status for new reaction posts.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'default_post_format',
            __( 'Default Post Format', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'      => 'default_post_format',
                'options' => array(
                    'standard' => __( 'Standard', 'reactions-indieweb' ),
                    'aside'    => __( 'Aside', 'reactions-indieweb' ),
                    'status'   => __( 'Status', 'reactions-indieweb' ),
                    'link'     => __( 'Link', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'Default post format for reaction posts.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'enable_microformats',
            __( 'Enable Microformats', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'   => 'enable_microformats',
                'desc' => __( 'Add microformats2 markup to reaction posts for IndieWeb compatibility.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'enable_syndication',
            __( 'Enable Syndication', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'   => 'enable_syndication',
                'desc' => __( 'Allow sending reactions to syndication targets (requires Syndication Links plugin).', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'import_storage_mode',
            __( 'Import Post Storage', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'      => 'import_storage_mode',
                'options' => array(
                    'standard' => __( 'Standard Posts', 'reactions-indieweb' ),
                    'cpt'      => __( 'Custom Post Type (Reactions)', 'reactions-indieweb' ),
                    'hidden'   => __( 'Standard Posts (Hidden from Blog)', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'How imported posts are stored. Standard Posts appear in your blog feed. Custom Post Type keeps imports separate. Hidden removes imports from the main blog but keeps them accessible via kind archives. Only affects new imports.', 'reactions-indieweb' ),
            )
        );

        // Post Format Sync Settings.
        add_settings_field(
            'sync_formats_to_kinds',
            __( 'Sync Post Formats to Kinds', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'   => 'sync_formats_to_kinds',
                'desc' => __( 'Automatically set Post Kind when Post Format changes (and vice versa).', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'format_kind_mappings',
            __( 'Post Format Mappings', 'reactions-indieweb' ),
            array( $this, 'render_format_mappings_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'   => 'format_kind_mappings',
                'desc' => __( 'Map WordPress Post Formats to Reaction Kinds. When a post format is selected, the corresponding kind will be set automatically.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'enabled_kinds',
            __( 'Enabled Reaction Types', 'reactions-indieweb' ),
            array( $this, 'render_enabled_kinds_field' ),
            'reactions_indieweb_general',
            'reactions_indieweb_general_section',
            array(
                'id'   => 'enabled_kinds',
                'desc' => __( 'Choose which reaction types are available in your site. Disabled types will not appear in the editor, taxonomy, or blocks.', 'reactions-indieweb' ),
            )
        );
    }

    /**
     * Add content settings fields.
     *
     * @return void
     */
    private function add_content_fields(): void {
        add_settings_field(
            'auto_fetch_metadata',
            __( 'Auto-fetch Metadata', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_content',
            'reactions_indieweb_content_section',
            array(
                'id'   => 'auto_fetch_metadata',
                'desc' => __( 'Automatically fetch metadata from external APIs when creating posts.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'cache_duration',
            __( 'Cache Duration', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_content',
            'reactions_indieweb_content_section',
            array(
                'id'      => 'cache_duration',
                'options' => array(
                    '3600'   => __( '1 hour', 'reactions-indieweb' ),
                    '21600'  => __( '6 hours', 'reactions-indieweb' ),
                    '43200'  => __( '12 hours', 'reactions-indieweb' ),
                    '86400'  => __( '24 hours', 'reactions-indieweb' ),
                    '259200' => __( '3 days', 'reactions-indieweb' ),
                    '604800' => __( '1 week', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'How long to cache API responses.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'image_handling',
            __( 'Image Handling', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_content',
            'reactions_indieweb_content_section',
            array(
                'id'      => 'image_handling',
                'options' => array(
                    'sideload' => __( 'Download to Media Library', 'reactions-indieweb' ),
                    'hotlink'  => __( 'Link to External URL', 'reactions-indieweb' ),
                    'none'     => __( 'Do Not Include Images', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'How to handle cover images and artwork from external sources.', 'reactions-indieweb' ),
            )
        );
    }

    /**
     * Add listen settings fields.
     *
     * @return void
     */
    private function add_listen_fields(): void {
        add_settings_field(
            'listen_auto_import',
            __( 'Auto-Sync Music', 'reactions-indieweb' ),
            array( $this, 'render_source_auto_sync_field' ),
            'reactions_indieweb_listen',
            'reactions_indieweb_listen_section',
            array(
                'id'          => 'listen_auto_import',
                'source_type' => 'music',
                'icon'        => 'format-audio',
                'desc'        => __( 'Automatically import new music scrobbles from your connected music service.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'listen_import_source',
            __( 'Music Import Source', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_listen',
            'reactions_indieweb_listen_section',
            array(
                'id'      => 'listen_import_source',
                'options' => array(
                    'listenbrainz' => 'ListenBrainz',
                    'lastfm'       => 'Last.fm',
                ),
                'desc'    => __( 'Primary source for importing music/scrobble history.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'listen_podcast_auto_import',
            __( 'Auto-Sync Podcasts', 'reactions-indieweb' ),
            array( $this, 'render_source_auto_sync_field' ),
            'reactions_indieweb_listen',
            'reactions_indieweb_listen_section',
            array(
                'id'          => 'listen_podcast_auto_import',
                'source_type' => 'podcasts',
                'icon'        => 'microphone',
                'desc'        => __( 'Automatically import podcast episodes with highlights from Readwise/Snipd.', 'reactions-indieweb' ),
                'source_name' => 'Readwise',
            )
        );

        add_settings_field(
            'listen_default_rating',
            __( 'Default Rating', 'reactions-indieweb' ),
            array( $this, 'render_number_field' ),
            'reactions_indieweb_listen',
            'reactions_indieweb_listen_section',
            array(
                'id'   => 'listen_default_rating',
                'min'  => 0,
                'max'  => 10,
                'step' => 1,
                'desc' => __( 'Default rating for listen posts (0 = no rating).', 'reactions-indieweb' ),
            )
        );
    }

    /**
     * Add watch settings fields.
     *
     * @return void
     */
    private function add_watch_fields(): void {
        add_settings_field(
            'watch_auto_import',
            __( 'Auto-Sync Movies & TV', 'reactions-indieweb' ),
            array( $this, 'render_source_auto_sync_field' ),
            'reactions_indieweb_watch',
            'reactions_indieweb_watch_section',
            array(
                'id'          => 'watch_auto_import',
                'source_type' => 'watch',
                'icon'        => 'video-alt3',
                'desc'        => __( 'Automatically import movies and TV shows you watch.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'watch_import_source',
            __( 'Import Source', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_watch',
            'reactions_indieweb_watch_section',
            array(
                'id'      => 'watch_import_source',
                'options' => array(
                    'trakt' => 'Trakt',
                    'simkl' => 'Simkl',
                ),
                'desc'    => __( 'Primary source for importing watch history.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'watch_default_rating',
            __( 'Default Rating', 'reactions-indieweb' ),
            array( $this, 'render_number_field' ),
            'reactions_indieweb_watch',
            'reactions_indieweb_watch_section',
            array(
                'id'   => 'watch_default_rating',
                'min'  => 0,
                'max'  => 10,
                'step' => 1,
                'desc' => __( 'Default rating for watch posts (0 = no rating).', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'watch_include_rewatches',
            __( 'Include Rewatches', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_watch',
            'reactions_indieweb_watch_section',
            array(
                'id'   => 'watch_include_rewatches',
                'desc' => __( 'Create posts for rewatched content (may create duplicates).', 'reactions-indieweb' ),
            )
        );
    }

    /**
     * Add read settings fields.
     *
     * @return void
     */
    private function add_read_fields(): void {
        add_settings_field(
            'read_auto_import',
            __( 'Auto-Sync Books', 'reactions-indieweb' ),
            array( $this, 'render_source_auto_sync_field' ),
            'reactions_indieweb_read',
            'reactions_indieweb_read_section',
            array(
                'id'          => 'read_auto_import',
                'source_type' => 'books',
                'icon'        => 'book',
                'desc'        => __( 'Automatically import books you\'re reading or have read.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'read_import_source',
            __( 'Book Import Source', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_read',
            'reactions_indieweb_read_section',
            array(
                'id'      => 'read_import_source',
                'options' => array(
                    'hardcover' => 'Hardcover',
                ),
                'desc'    => __( 'Primary source for importing book reading history.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'read_articles_auto_import',
            __( 'Auto-Sync Articles', 'reactions-indieweb' ),
            array( $this, 'render_source_auto_sync_field' ),
            'reactions_indieweb_read',
            'reactions_indieweb_read_section',
            array(
                'id'          => 'read_articles_auto_import',
                'source_type' => 'articles',
                'icon'        => 'media-text',
                'desc'        => __( 'Automatically import articles with highlights from Readwise.', 'reactions-indieweb' ),
                'source_name' => 'Readwise',
            )
        );

        add_settings_field(
            'read_default_status',
            __( 'Default Read Status', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_read',
            'reactions_indieweb_read_section',
            array(
                'id'      => 'read_default_status',
                'options' => array(
                    'to-read'    => __( 'To Read', 'reactions-indieweb' ),
                    'reading'    => __( 'Currently Reading', 'reactions-indieweb' ),
                    'finished'   => __( 'Finished', 'reactions-indieweb' ),
                    'abandoned'  => __( 'Abandoned', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'Default status for new read posts.', 'reactions-indieweb' ),
            )
        );
    }

    /**
     * Add checkin settings fields.
     *
     * @return void
     */
    private function add_checkin_fields(): void {
        add_settings_field(
            'checkin_auto_import',
            __( 'Auto-Sync Checkins', 'reactions-indieweb' ),
            array( $this, 'render_source_auto_sync_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id'          => 'checkin_auto_import',
                'source_type' => 'checkins',
                'icon'        => 'location-alt',
                'desc'        => __( 'Automatically import checkins from Foursquare/Swarm.', 'reactions-indieweb' ),
                'source_name' => 'Foursquare',
            )
        );

        add_settings_field(
            'checkin_default_privacy',
            __( 'Default Location Privacy', 'reactions-indieweb' ),
            array( $this, 'render_privacy_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id' => 'checkin_default_privacy',
            )
        );

        add_settings_field(
            'checkin_coordinate_handling',
            __( 'Coordinate Handling', 'reactions-indieweb' ),
            array( $this, 'render_coordinate_handling_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id' => 'checkin_coordinate_handling',
            )
        );

        add_settings_field(
            'checkin_venue_source',
            __( 'Venue Search Source', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id'      => 'checkin_venue_source',
                'options' => array(
                    'nominatim'  => __( 'OpenStreetMap (Nominatim)', 'reactions-indieweb' ),
                    'foursquare' => __( 'Foursquare (requires API key)', 'reactions-indieweb' ),
                    'both'       => __( 'Both (Foursquare first, OSM fallback)', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'Which service to use for venue/location search in the block editor.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'checkin_sync_to_foursquare',
            __( 'Sync to Foursquare', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id'   => 'checkin_sync_to_foursquare',
                'desc' => __( 'Post checkins to Foursquare when publishing (requires Foursquare OAuth connection). This is a POSSE approach - Publish on your Own Site, Syndicate Elsewhere.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'foursquare_connection',
            __( 'Foursquare Connection', 'reactions-indieweb' ),
            array( $this, 'render_foursquare_connection_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id' => 'foursquare_connection',
            )
        );
    }

    /**
     * Add performance settings fields.
     *
     * @return void
     */
    private function add_performance_fields(): void {
        add_settings_field(
            'enable_background_sync',
            __( 'Automatic Sync', 'reactions-indieweb' ),
            array( $this, 'render_auto_sync_field' ),
            'reactions_indieweb_performance',
            'reactions_indieweb_performance_section',
            array(
                'id' => 'enable_background_sync',
            )
        );

        add_settings_field(
            'rate_limit_delay',
            __( 'Rate Limit Delay', 'reactions-indieweb' ),
            array( $this, 'render_number_field' ),
            'reactions_indieweb_performance',
            'reactions_indieweb_performance_section',
            array(
                'id'   => 'rate_limit_delay',
                'min'  => 0,
                'max'  => 10000,
                'step' => 100,
                'desc' => __( 'Milliseconds to wait between API requests (to avoid rate limits).', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'batch_size',
            __( 'Import Batch Size', 'reactions-indieweb' ),
            array( $this, 'render_number_field' ),
            'reactions_indieweb_performance',
            'reactions_indieweb_performance_section',
            array(
                'id'   => 'batch_size',
                'min'  => 1,
                'max'  => 500,
                'step' => 10,
                'desc' => __( 'Number of items to process per batch during imports.', 'reactions-indieweb' ),
            )
        );
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $this->active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

        ?>
        <div class="wrap reactions-indieweb-settings">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php $this->render_tabs(); ?>
            </nav>

            <form method="post" action="options.php" class="reactions-indieweb-form">
                <?php
                settings_fields( 'reactions_indieweb_general' );

                switch ( $this->active_tab ) {
                    case 'general':
                        $this->render_general_tab();
                        break;
                    case 'content':
                        $this->render_content_tab();
                        break;
                    case 'listen':
                        $this->render_listen_tab();
                        break;
                    case 'watch':
                        $this->render_watch_tab();
                        break;
                    case 'read':
                        $this->render_read_tab();
                        break;
                    case 'checkin':
                        $this->render_checkin_tab();
                        break;
                    case 'performance':
                        $this->render_performance_tab();
                        break;
                    case 'tools':
                        $this->render_tools_tab();
                        break;
                }

                if ( 'tools' !== $this->active_tab ) {
                    submit_button();
                }
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render navigation tabs.
     *
     * @return void
     */
    private function render_tabs(): void {
        $tabs = array(
            'general'     => __( 'General', 'reactions-indieweb' ),
            'content'     => __( 'Content', 'reactions-indieweb' ),
            'listen'      => __( 'Listen', 'reactions-indieweb' ),
            'watch'       => __( 'Watch', 'reactions-indieweb' ),
            'read'        => __( 'Read', 'reactions-indieweb' ),
            'checkin'     => __( 'Checkin', 'reactions-indieweb' ),
            'performance' => __( 'Performance', 'reactions-indieweb' ),
            'tools'       => __( 'Tools', 'reactions-indieweb' ),
        );

        foreach ( $tabs as $slug => $label ) {
            $active = $this->active_tab === $slug ? ' nav-tab-active' : '';
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url( add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=reactions-indieweb' ) ) ),
                esc_attr( $active ),
                esc_html( $label )
            );
        }
    }

    /**
     * Render general section description.
     *
     * @return void
     */
    public function render_general_section(): void {
        echo '<p>' . esc_html__( 'Configure general plugin behavior and defaults.', 'reactions-indieweb' ) . '</p>';
    }

    /**
     * Render content section description.
     *
     * @return void
     */
    public function render_content_section(): void {
        echo '<p>' . esc_html__( 'Configure how content and metadata is handled.', 'reactions-indieweb' ) . '</p>';
    }

    /**
     * Render listen section description.
     *
     * @return void
     */
    public function render_listen_section(): void {
        echo '<p>' . esc_html__( 'Configure settings for listen/scrobble posts.', 'reactions-indieweb' ) . '</p>';
    }

    /**
     * Render watch section description.
     *
     * @return void
     */
    public function render_watch_section(): void {
        echo '<p>' . esc_html__( 'Configure settings for watch posts (movies and TV shows).', 'reactions-indieweb' ) . '</p>';

        // YouTube tip notice.
        echo '<div class="notice notice-info inline" style="margin: 16px 0; padding: 12px;">';
        echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__( 'Tip: Track YouTube Watches', 'reactions-indieweb' ) . '</strong></p>';
        echo '<p style="margin: 0;">';
        echo esc_html__( 'YouTube\'s watch history API is not publicly available. However, you can track YouTube video watches by manually logging them on Trakt.tv, then importing via the Trakt integration.', 'reactions-indieweb' );
        echo ' <a href="https://trakt.tv/" target="_blank" rel="noopener">' . esc_html__( 'Visit Trakt.tv', 'reactions-indieweb' ) . ' <span class="dashicons dashicons-external" style="font-size: 14px; vertical-align: text-bottom;"></span></a>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Render read section description.
     *
     * @return void
     */
    public function render_read_section(): void {
        echo '<p>' . esc_html__( 'Configure settings for read/book posts.', 'reactions-indieweb' ) . '</p>';
    }

    /**
     * Render checkin section description.
     *
     * @return void
     */
    public function render_checkin_section(): void {
        echo '<p>' . esc_html__( 'Configure settings for location checkin posts.', 'reactions-indieweb' ) . '</p>';
    }

    /**
     * Render performance section description.
     *
     * @return void
     */
    public function render_performance_section(): void {
        echo '<p>' . esc_html__( 'Configure performance and rate limiting settings.', 'reactions-indieweb' ) . '</p>';
    }

    /**
     * Render general tab content.
     *
     * @return void
     */
    private function render_general_tab(): void {
        do_settings_sections( 'reactions_indieweb_general' );
    }

    /**
     * Render content tab content.
     *
     * @return void
     */
    private function render_content_tab(): void {
        do_settings_sections( 'reactions_indieweb_content' );
    }

    /**
     * Render listen tab content.
     *
     * @return void
     */
    private function render_listen_tab(): void {
        do_settings_sections( 'reactions_indieweb_listen' );
    }

    /**
     * Render watch tab content.
     *
     * @return void
     */
    private function render_watch_tab(): void {
        do_settings_sections( 'reactions_indieweb_watch' );
    }

    /**
     * Render read tab content.
     *
     * @return void
     */
    private function render_read_tab(): void {
        do_settings_sections( 'reactions_indieweb_read' );
    }

    /**
     * Render checkin tab content.
     *
     * @return void
     */
    private function render_checkin_tab(): void {
        do_settings_sections( 'reactions_indieweb_checkin' );
    }

    /**
     * Render performance tab content.
     *
     * @return void
     */
    private function render_performance_tab(): void {
        do_settings_sections( 'reactions_indieweb_performance' );
    }

    /**
     * Render tools tab content.
     *
     * @return void
     */
    private function render_tools_tab(): void {
        ?>
        <div class="reactions-indieweb-tools">
            <h2><?php esc_html_e( 'Cache Management', 'reactions-indieweb' ); ?></h2>
            <p><?php esc_html_e( 'Clear cached API responses and metadata.', 'reactions-indieweb' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Clear API Cache', 'reactions-indieweb' ); ?></th>
                    <td>
                        <button type="button" class="button reactions-clear-cache" data-type="api">
                            <?php esc_html_e( 'Clear API Cache', 'reactions-indieweb' ); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e( 'Clear cached responses from external APIs.', 'reactions-indieweb' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Clear Metadata Cache', 'reactions-indieweb' ); ?></th>
                    <td>
                        <button type="button" class="button reactions-clear-cache" data-type="metadata">
                            <?php esc_html_e( 'Clear Metadata Cache', 'reactions-indieweb' ); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e( 'Clear cached media metadata.', 'reactions-indieweb' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Clear All Caches', 'reactions-indieweb' ); ?></th>
                    <td>
                        <button type="button" class="button button-secondary reactions-clear-cache" data-type="all">
                            <?php esc_html_e( 'Clear All Caches', 'reactions-indieweb' ); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e( 'Clear all cached data.', 'reactions-indieweb' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr>

            <h2><?php esc_html_e( 'Export / Import Settings', 'reactions-indieweb' ); ?></h2>
            <p><?php esc_html_e( 'Export or import plugin settings.', 'reactions-indieweb' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Export Settings', 'reactions-indieweb' ); ?></th>
                    <td>
                        <button type="button" class="button reactions-export-settings">
                            <?php esc_html_e( 'Export Settings', 'reactions-indieweb' ); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e( 'Download settings as a JSON file (API keys excluded).', 'reactions-indieweb' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Import Settings', 'reactions-indieweb' ); ?></th>
                    <td>
                        <input type="file" id="reactions-import-file" accept=".json">
                        <button type="button" class="button reactions-import-settings" disabled>
                            <?php esc_html_e( 'Import Settings', 'reactions-indieweb' ); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e( 'Import settings from a previously exported JSON file.', 'reactions-indieweb' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr>

            <h2><?php esc_html_e( 'Debug Information', 'reactions-indieweb' ); ?></h2>
            <p><?php esc_html_e( 'Technical information for troubleshooting.', 'reactions-indieweb' ); ?></p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Plugin Version', 'reactions-indieweb' ); ?></th>
                    <td><code><?php echo esc_html( \REACTIONS_INDIEWEB_VERSION ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'WordPress Version', 'reactions-indieweb' ); ?></th>
                    <td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'PHP Version', 'reactions-indieweb' ); ?></th>
                    <td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'IndieBlocks', 'reactions-indieweb' ); ?></th>
                    <td>
                        <?php if ( class_exists( 'IndieBlocks\\IndieBlocks' ) ) : ?>
                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                            <?php esc_html_e( 'Installed', 'reactions-indieweb' ); ?>
                        <?php else : ?>
                            <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
                            <?php esc_html_e( 'Not installed', 'reactions-indieweb' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Active Imports', 'reactions-indieweb' ); ?></th>
                    <td>
                        <?php
                        $active_imports = get_option( 'reactions_indieweb_active_imports', array() );
                        echo esc_html( count( $active_imports ) );
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render a select field.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_select_field( array $args ): void {
        $settings = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $value    = $settings[ $args['id'] ] ?? '';

        printf(
            '<select name="reactions_indieweb_settings[%s]" id="%s">',
            esc_attr( $args['id'] ),
            esc_attr( $args['id'] )
        );

        foreach ( $args['options'] as $option_value => $option_label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $option_value ),
                selected( $value, $option_value, false ),
                esc_html( $option_label )
            );
        }

        echo '</select>';

        if ( ! empty( $args['desc'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
        }
    }

    /**
     * Render a checkbox field.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_checkbox_field( array $args ): void {
        $settings = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $checked  = ! empty( $settings[ $args['id'] ] );

        printf(
            '<label><input type="checkbox" name="reactions_indieweb_settings[%s]" id="%s" value="1"%s> %s</label>',
            esc_attr( $args['id'] ),
            esc_attr( $args['id'] ),
            checked( $checked, true, false ),
            ! empty( $args['label'] ) ? esc_html( $args['label'] ) : ''
        );

        if ( ! empty( $args['desc'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
        }
    }

    /**
     * Render a number field.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_number_field( array $args ): void {
        $settings = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $value    = $settings[ $args['id'] ] ?? 0;

        printf(
            '<input type="number" name="reactions_indieweb_settings[%s]" id="%s" value="%s" min="%s" max="%s" step="%s" class="small-text">',
            esc_attr( $args['id'] ),
            esc_attr( $args['id'] ),
            esc_attr( $value ),
            esc_attr( $args['min'] ?? 0 ),
            esc_attr( $args['max'] ?? 100 ),
            esc_attr( $args['step'] ?? 1 )
        );

        if ( ! empty( $args['desc'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
        }
    }

    /**
     * Render a text field.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_text_field( array $args ): void {
        $settings = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $value    = $settings[ $args['id'] ] ?? '';

        printf(
            '<input type="text" name="reactions_indieweb_settings[%s]" id="%s" value="%s" class="regular-text">',
            esc_attr( $args['id'] ),
            esc_attr( $args['id'] ),
            esc_attr( $value )
        );

        if ( ! empty( $args['desc'] ) ) {
            printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
        }
    }

    /**
     * Render format to kind mappings field.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_format_mappings_field( array $args ): void {
        $settings = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $mappings = $settings['format_kind_mappings'] ?? $this->get_default_format_mappings();

        // WordPress Post Formats.
        $post_formats = array(
            'standard' => __( 'Standard', 'reactions-indieweb' ),
            'aside'    => __( 'Aside', 'reactions-indieweb' ),
            'audio'    => __( 'Audio', 'reactions-indieweb' ),
            'chat'     => __( 'Chat', 'reactions-indieweb' ),
            'gallery'  => __( 'Gallery', 'reactions-indieweb' ),
            'image'    => __( 'Image', 'reactions-indieweb' ),
            'link'     => __( 'Link', 'reactions-indieweb' ),
            'quote'    => __( 'Quote', 'reactions-indieweb' ),
            'status'   => __( 'Status', 'reactions-indieweb' ),
            'video'    => __( 'Video', 'reactions-indieweb' ),
        );

        // Reaction Kinds.
        $kinds = array(
            ''         => __( '— No mapping —', 'reactions-indieweb' ),
            'note'     => __( 'Note', 'reactions-indieweb' ),
            'article'  => __( 'Article', 'reactions-indieweb' ),
            'reply'    => __( 'Reply', 'reactions-indieweb' ),
            'like'     => __( 'Like', 'reactions-indieweb' ),
            'repost'   => __( 'Repost', 'reactions-indieweb' ),
            'bookmark' => __( 'Bookmark', 'reactions-indieweb' ),
            'rsvp'     => __( 'RSVP', 'reactions-indieweb' ),
            'checkin'  => __( 'Check-in', 'reactions-indieweb' ),
            'listen'   => __( 'Listen', 'reactions-indieweb' ),
            'watch'    => __( 'Watch', 'reactions-indieweb' ),
            'read'     => __( 'Read', 'reactions-indieweb' ),
            'event'    => __( 'Event', 'reactions-indieweb' ),
            'photo'    => __( 'Photo', 'reactions-indieweb' ),
            'video'    => __( 'Video', 'reactions-indieweb' ),
            'review'   => __( 'Review', 'reactions-indieweb' ),
        );

        if ( ! empty( $args['desc'] ) ) {
            printf( '<p class="description" style="margin-bottom: 12px;">%s</p>', esc_html( $args['desc'] ) );
        }

        echo '<div class="format-mappings-grid" style="display: grid; grid-template-columns: repeat(2, minmax(200px, 280px)); gap: 8px 24px; max-width: 600px;">';

        foreach ( $post_formats as $format_slug => $format_label ) {
            $current_value = $mappings[ $format_slug ] ?? '';

            echo '<div class="format-mapping-item" style="display: flex; align-items: center; gap: 8px;">';
            printf(
                '<label for="format_mapping_%s" style="min-width: 70px; font-weight: 500;">%s</label>',
                esc_attr( $format_slug ),
                esc_html( $format_label )
            );
            echo '<span style="color: #8c8f94;">→</span>';
            printf(
                '<select name="reactions_indieweb_settings[format_kind_mappings][%s]" id="format_mapping_%s" style="flex: 1; max-width: 150px;">',
                esc_attr( $format_slug ),
                esc_attr( $format_slug )
            );

            foreach ( $kinds as $kind_slug => $kind_label ) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( $kind_slug ),
                    selected( $current_value, $kind_slug, false ),
                    esc_html( $kind_label )
                );
            }

            echo '</select>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Get default format to kind mappings.
     *
     * @return array<string, string>
     */
    private function get_default_format_mappings(): array {
        return array(
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
        );
    }

    /**
     * Render enabled kinds field with checkboxes.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_enabled_kinds_field( array $args ): void {
        $settings      = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $enabled_kinds = $settings['enabled_kinds'] ?? $this->get_default_enabled_kinds();

        // All available kinds with descriptions.
        $all_kinds = array(
            'note'     => array(
                'label' => __( 'Note', 'reactions-indieweb' ),
                'desc'  => __( 'Short posts, similar to tweets or status updates', 'reactions-indieweb' ),
                'icon'  => 'format-status',
            ),
            'article'  => array(
                'label' => __( 'Article', 'reactions-indieweb' ),
                'desc'  => __( 'Long-form content with a title', 'reactions-indieweb' ),
                'icon'  => 'media-document',
            ),
            'reply'    => array(
                'label' => __( 'Reply', 'reactions-indieweb' ),
                'desc'  => __( 'Response to someone else\'s content', 'reactions-indieweb' ),
                'icon'  => 'format-chat',
            ),
            'like'     => array(
                'label' => __( 'Like', 'reactions-indieweb' ),
                'desc'  => __( 'Indicate appreciation for external content', 'reactions-indieweb' ),
                'icon'  => 'heart',
            ),
            'repost'   => array(
                'label' => __( 'Repost', 'reactions-indieweb' ),
                'desc'  => __( 'Share someone else\'s content on your site', 'reactions-indieweb' ),
                'icon'  => 'controls-repeat',
            ),
            'bookmark' => array(
                'label' => __( 'Bookmark', 'reactions-indieweb' ),
                'desc'  => __( 'Save and share links to interesting content', 'reactions-indieweb' ),
                'icon'  => 'bookmark',
            ),
            'rsvp'     => array(
                'label' => __( 'RSVP', 'reactions-indieweb' ),
                'desc'  => __( 'Respond to event invitations', 'reactions-indieweb' ),
                'icon'  => 'calendar-alt',
            ),
            'checkin'  => array(
                'label' => __( 'Check-in', 'reactions-indieweb' ),
                'desc'  => __( 'Share your location at a venue or place', 'reactions-indieweb' ),
                'icon'  => 'location-alt',
            ),
            'listen'   => array(
                'label' => __( 'Listen', 'reactions-indieweb' ),
                'desc'  => __( 'Music scrobbles, podcasts, audio content', 'reactions-indieweb' ),
                'icon'  => 'format-audio',
            ),
            'watch'    => array(
                'label' => __( 'Watch', 'reactions-indieweb' ),
                'desc'  => __( 'Movies, TV shows, videos you\'ve watched', 'reactions-indieweb' ),
                'icon'  => 'video-alt3',
            ),
            'read'     => array(
                'label' => __( 'Read', 'reactions-indieweb' ),
                'desc'  => __( 'Books, articles, and reading progress', 'reactions-indieweb' ),
                'icon'  => 'book',
            ),
            'event'    => array(
                'label' => __( 'Event', 'reactions-indieweb' ),
                'desc'  => __( 'Create and share events', 'reactions-indieweb' ),
                'icon'  => 'calendar',
            ),
            'photo'    => array(
                'label' => __( 'Photo', 'reactions-indieweb' ),
                'desc'  => __( 'Image-focused posts', 'reactions-indieweb' ),
                'icon'  => 'format-image',
            ),
            'video'    => array(
                'label' => __( 'Video', 'reactions-indieweb' ),
                'desc'  => __( 'Video posts you create', 'reactions-indieweb' ),
                'icon'  => 'format-video',
            ),
            'review'   => array(
                'label' => __( 'Review', 'reactions-indieweb' ),
                'desc'  => __( 'Reviews with ratings', 'reactions-indieweb' ),
                'icon'  => 'star-filled',
            ),
        );

        if ( ! empty( $args['desc'] ) ) {
            printf( '<p class="description" style="margin-bottom: 16px;">%s</p>', esc_html( $args['desc'] ) );
        }

        echo '<div class="enabled-kinds-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">';

        foreach ( $all_kinds as $kind_slug => $kind_data ) {
            $is_enabled = in_array( $kind_slug, $enabled_kinds, true );

            echo '<label class="enabled-kind-item" style="display: flex; align-items: flex-start; gap: 8px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">';
            printf(
                '<input type="checkbox" name="reactions_indieweb_settings[enabled_kinds][]" value="%s"%s style="margin-top: 2px;">',
                esc_attr( $kind_slug ),
                checked( $is_enabled, true, false )
            );
            echo '<span class="dashicons dashicons-' . esc_attr( $kind_data['icon'] ) . '" style="color: #2271b1; margin-top: 2px;"></span>';
            echo '<span>';
            printf( '<strong style="display: block;">%s</strong>', esc_html( $kind_data['label'] ) );
            printf( '<span class="description" style="font-size: 12px; color: #646970;">%s</span>', esc_html( $kind_data['desc'] ) );
            echo '</span>';
            echo '</label>';
        }

        echo '</div>';

        echo '<div style="margin-top: 16px;">';
        echo '<button type="button" class="button" id="enable-all-kinds">' . esc_html__( 'Enable All', 'reactions-indieweb' ) . '</button> ';
        echo '<button type="button" class="button" id="disable-all-kinds">' . esc_html__( 'Disable All', 'reactions-indieweb' ) . '</button>';
        echo '</div>';

        // JavaScript for enable/disable all buttons.
        ?>
        <script>
        document.getElementById('enable-all-kinds').addEventListener('click', function() {
            document.querySelectorAll('.enabled-kind-item input[type="checkbox"]').forEach(cb => cb.checked = true);
        });
        document.getElementById('disable-all-kinds').addEventListener('click', function() {
            document.querySelectorAll('.enabled-kind-item input[type="checkbox"]').forEach(cb => cb.checked = false);
        });
        </script>
        <?php
    }

    /**
     * Get default enabled kinds (all enabled by default).
     *
     * @return array<string>
     */
    private function get_default_enabled_kinds(): array {
        return array(
            'note', 'article', 'reply', 'like', 'repost', 'bookmark',
            'rsvp', 'checkin', 'listen', 'watch', 'read', 'event',
            'photo', 'video', 'review',
        );
    }

    /**
     * Render privacy field with detailed explanations.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_privacy_field( array $args ): void {
        $settings = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $value    = $settings[ $args['id'] ] ?? 'approximate';

        $options = array(
            'public'      => array(
                'label' => __( 'Public (exact location)', 'reactions-indieweb' ),
                'desc'  => __( 'Shows full address, venue name, and precise coordinates. Best for public venues like restaurants or parks where you want others to find the same place.', 'reactions-indieweb' ),
            ),
            'approximate' => array(
                'label' => __( 'Approximate (city level)', 'reactions-indieweb' ),
                'desc'  => __( 'Shows city/region but hides street address and exact coordinates. Good balance of sharing where you are without revealing precise location.', 'reactions-indieweb' ),
            ),
            'private'     => array(
                'label' => __( 'Private (hidden)', 'reactions-indieweb' ),
                'desc'  => __( 'Location is stored but never displayed publicly. Use this for home, work, or other private locations you want to log but not share.', 'reactions-indieweb' ),
            ),
        );

        echo '<fieldset>';
        foreach ( $options as $option_value => $option_data ) {
            printf(
                '<label style="display: block; margin-bottom: 12px;">
                    <input type="radio" name="reactions_indieweb_settings[%s]" value="%s"%s>
                    <strong>%s</strong>
                    <p class="description" style="margin-left: 24px; margin-top: 4px;">%s</p>
                </label>',
                esc_attr( $args['id'] ),
                esc_attr( $option_value ),
                checked( $value, $option_value, false ),
                esc_html( $option_data['label'] ),
                esc_html( $option_data['desc'] )
            );
        }
        echo '</fieldset>';
        echo '<p class="description" style="margin-top: 16px; padding: 12px; background: #f0f0f1; border-left: 4px solid #2271b1;">';
        esc_html_e( 'This setting determines the default for new checkins. You can override it per-post in the block editor.', 'reactions-indieweb' );
        echo '</p>';
    }

    /**
     * Render coordinate handling field with detailed explanations.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_coordinate_handling_field( array $args ): void {
        $settings = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $value    = $settings[ $args['id'] ] ?? 'store_hide';

        $options = array(
            'store_hide'  => array(
                'label' => __( 'Store but hide coordinates', 'reactions-indieweb' ),
                'desc'  => __( 'Exact coordinates are saved in the database (for your records, maps, or future use) but never shown publicly. This lets you keep a precise location history while protecting privacy.', 'reactions-indieweb' ),
            ),
            'round'       => array(
                'label' => __( 'Round coordinates (reduce precision)', 'reactions-indieweb' ),
                'desc'  => __( 'Coordinates are rounded to ~1km precision before storing. This provides approximate mapping while making it impossible to pinpoint exact locations. Good if you want some geographic context without precision.', 'reactions-indieweb' ),
            ),
            'discard'     => array(
                'label' => __( 'Discard coordinates entirely', 'reactions-indieweb' ),
                'desc'  => __( 'Coordinates are never saved. Only venue name and address text are stored. Use this for maximum privacy, but note that coordinates cannot be recovered later.', 'reactions-indieweb' ),
            ),
            'store_show'  => array(
                'label' => __( 'Store and show coordinates', 'reactions-indieweb' ),
                'desc'  => __( 'Exact coordinates are saved and displayed publicly (when privacy is set to Public). Enables precise mapping and IndieWeb geo microformats.', 'reactions-indieweb' ),
            ),
        );

        echo '<fieldset>';
        foreach ( $options as $option_value => $option_data ) {
            printf(
                '<label style="display: block; margin-bottom: 12px;">
                    <input type="radio" name="reactions_indieweb_settings[%s]" value="%s"%s>
                    <strong>%s</strong>
                    <p class="description" style="margin-left: 24px; margin-top: 4px;">%s</p>
                </label>',
                esc_attr( $args['id'] ),
                esc_attr( $option_value ),
                checked( $value, $option_value, false ),
                esc_html( $option_data['label'] ),
                esc_html( $option_data['desc'] )
            );
        }
        echo '</fieldset>';

        echo '<div style="margin-top: 16px; padding: 12px; background: #fff8e5; border-left: 4px solid #dba617;">';
        echo '<strong>' . esc_html__( 'Why does this matter?', 'reactions-indieweb' ) . '</strong>';
        echo '<p class="description" style="margin-top: 8px;">';
        esc_html_e( 'Precise coordinates can reveal patterns about where you live, work, or spend time. Even if you hide your home address, checking in at nearby cafes regularly can expose your neighborhood. Consider your threat model when choosing.', 'reactions-indieweb' );
        echo '</p>';
        echo '</div>';
    }

    /**
     * Render the automatic sync toggle with clear status.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_auto_sync_field( array $args ): void {
        $settings   = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $enabled    = ! empty( $settings['enable_background_sync'] );

        // Get next/last sync times from scheduled sync.
        $plugin         = \ReactionsForIndieWeb\Plugin::get_instance();
        $scheduled_sync = $plugin->get_scheduled_sync();
        $last_sync      = $scheduled_sync ? $scheduled_sync->get_last_sync_time() : null;
        $next_sync      = $scheduled_sync ? $scheduled_sync->get_next_sync_time() : null;

        // Check which auto-imports are enabled.
        $auto_imports_enabled = array();
        if ( ! empty( $settings['listen_auto_import'] ) ) {
            $auto_imports_enabled[] = __( 'Music', 'reactions-indieweb' );
        }
        if ( ! empty( $settings['listen_podcast_auto_import'] ) ) {
            $auto_imports_enabled[] = __( 'Podcasts', 'reactions-indieweb' );
        }
        if ( ! empty( $settings['watch_auto_import'] ) ) {
            $auto_imports_enabled[] = __( 'Movies & TV', 'reactions-indieweb' );
        }
        if ( ! empty( $settings['read_auto_import'] ) ) {
            $auto_imports_enabled[] = __( 'Books', 'reactions-indieweb' );
        }
        if ( ! empty( $settings['read_articles_auto_import'] ) ) {
            $auto_imports_enabled[] = __( 'Articles', 'reactions-indieweb' );
        }
        if ( ! empty( $settings['checkin_auto_import'] ) ) {
            $auto_imports_enabled[] = __( 'Checkins', 'reactions-indieweb' );
        }

        ?>
        <div class="auto-sync-settings" style="max-width: 600px;">
            <!-- Main Toggle -->
            <div style="display: flex; align-items: center; gap: 16px; padding: 16px; background: <?php echo $enabled ? '#f0f6fc' : '#f9f9f9'; ?>; border: 2px solid <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; border-radius: 8px; margin-bottom: 16px;">
                <label class="auto-sync-toggle" style="display: flex; align-items: center; gap: 12px; cursor: pointer; flex: 1;">
                    <input
                        type="checkbox"
                        name="reactions_indieweb_settings[enable_background_sync]"
                        id="enable_background_sync"
                        value="1"
                        <?php checked( $enabled ); ?>
                        style="width: 20px; height: 20px;"
                    >
                    <span style="font-size: 16px; font-weight: 600;">
                        <?php esc_html_e( 'Enable Automatic Sync', 'reactions-indieweb' ); ?>
                    </span>
                </label>
                <span style="padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 500; background: <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; color: <?php echo $enabled ? '#fff' : '#666'; ?>;">
                    <?php echo $enabled ? esc_html__( 'ON', 'reactions-indieweb' ) : esc_html__( 'OFF', 'reactions-indieweb' ); ?>
                </span>
            </div>

            <!-- Description -->
            <p class="description" style="margin-bottom: 16px;">
                <?php esc_html_e( 'When enabled, the plugin will automatically import new content from your connected services every hour using WordPress cron.', 'reactions-indieweb' ); ?>
            </p>

            <?php if ( $enabled ) : ?>
                <!-- Status Info -->
                <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 16px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #1d2327;">
                        <?php esc_html_e( 'Sync Status', 'reactions-indieweb' ); ?>
                    </h4>
                    <dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 13px;">
                        <dt style="color: #646970;"><?php esc_html_e( 'Last sync:', 'reactions-indieweb' ); ?></dt>
                        <dd style="margin: 0;">
                            <?php
                            if ( $last_sync ) {
                                echo esc_html( human_time_diff( $last_sync ) . ' ' . __( 'ago', 'reactions-indieweb' ) );
                            } else {
                                esc_html_e( 'Never', 'reactions-indieweb' );
                            }
                            ?>
                        </dd>
                        <dt style="color: #646970;"><?php esc_html_e( 'Next sync:', 'reactions-indieweb' ); ?></dt>
                        <dd style="margin: 0;">
                            <?php
                            if ( $next_sync ) {
                                if ( $next_sync <= time() ) {
                                    esc_html_e( 'Pending (waiting for cron)', 'reactions-indieweb' );
                                } else {
                                    echo esc_html( sprintf(
                                        /* translators: %s: human time diff */
                                        __( 'in %s', 'reactions-indieweb' ),
                                        human_time_diff( time(), $next_sync )
                                    ) );
                                }
                            } else {
                                esc_html_e( 'Not scheduled', 'reactions-indieweb' );
                            }
                            ?>
                        </dd>
                    </dl>
                </div>

                <!-- Active Auto-Imports -->
                <?php if ( ! empty( $auto_imports_enabled ) ) : ?>
                    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px;">
                        <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #1d2327;">
                            <?php esc_html_e( 'Active Auto-Imports', 'reactions-indieweb' ); ?>
                        </h4>
                        <p style="margin: 0; font-size: 13px;">
                            <?php echo esc_html( implode( ', ', $auto_imports_enabled ) ); ?>
                        </p>
                        <p class="description" style="margin: 8px 0 0 0; font-size: 12px;">
                            <?php esc_html_e( 'Configure individual auto-imports in their respective tabs above.', 'reactions-indieweb' ); ?>
                        </p>
                    </div>
                <?php else : ?>
                    <div style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 12px;">
                        <p style="margin: 0; font-size: 13px; color: #614b00;">
                            <span class="dashicons dashicons-warning" style="font-size: 16px; vertical-align: text-bottom;"></span>
                            <?php esc_html_e( 'No auto-imports are enabled. Enable auto-import in the Listen, Watch, Read, or Checkin tabs for automatic sync to work.', 'reactions-indieweb' ); ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <!-- Disabled Info -->
                <div style="background: #f0f0f1; border: 1px solid #ddd; border-radius: 4px; padding: 12px; color: #646970;">
                    <p style="margin: 0; font-size: 13px;">
                        <?php esc_html_e( 'Automatic sync is disabled. Imports will only run when you manually trigger them from the Import page.', 'reactions-indieweb' ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a per-source auto-sync toggle field with prominent styling.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_source_auto_sync_field( array $args ): void {
        $settings    = get_option( 'reactions_indieweb_settings', $this->admin->get_default_settings() );
        $enabled     = ! empty( $settings[ $args['id'] ] );
        $icon        = $args['icon'] ?? 'admin-generic';
        $source_name = $args['source_name'] ?? '';
        $source_type = $args['source_type'] ?? '';

        // Check if the main background sync is enabled.
        $background_sync_enabled = ! empty( $settings['enable_background_sync'] );

        // Check if relevant API is configured.
        $is_configured = true;
        $config_message = '';

        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );

        if ( 'Readwise' === $source_name ) {
            $is_configured = ! empty( $credentials['readwise']['access_token'] );
            $config_message = __( 'Requires Readwise API token', 'reactions-indieweb' );
        } elseif ( 'Foursquare' === $source_name ) {
            $is_configured = ! empty( $credentials['foursquare']['access_token'] );
            $config_message = __( 'Requires Foursquare connection', 'reactions-indieweb' );
        }

        ?>
        <div class="source-auto-sync-field" style="max-width: 500px;">
            <div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: <?php echo $enabled ? '#f0f6fc' : '#f9f9f9'; ?>; border: 2px solid <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; border-radius: 6px; <?php echo ! $is_configured ? 'opacity: 0.7;' : ''; ?>">
                <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="font-size: 24px; width: 24px; height: 24px; color: <?php echo $enabled ? '#2271b1' : '#8c8f94'; ?>;"></span>

                <label style="display: flex; align-items: center; gap: 10px; cursor: <?php echo $is_configured ? 'pointer' : 'not-allowed'; ?>; flex: 1;">
                    <input
                        type="checkbox"
                        name="reactions_indieweb_settings[<?php echo esc_attr( $args['id'] ); ?>]"
                        id="<?php echo esc_attr( $args['id'] ); ?>"
                        value="1"
                        <?php checked( $enabled ); ?>
                        <?php disabled( ! $is_configured ); ?>
                        style="width: 18px; height: 18px;"
                    >
                    <span style="font-weight: 500;">
                        <?php esc_html_e( 'Enable Auto-Sync', 'reactions-indieweb' ); ?>
                        <?php if ( $source_name ) : ?>
                            <span style="font-weight: 400; color: #646970;">(<?php echo esc_html( $source_name ); ?>)</span>
                        <?php endif; ?>
                    </span>
                </label>

                <span style="padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; color: <?php echo $enabled ? '#fff' : '#666'; ?>;">
                    <?php echo $enabled ? esc_html__( 'ON', 'reactions-indieweb' ) : esc_html__( 'OFF', 'reactions-indieweb' ); ?>
                </span>
            </div>

            <?php if ( ! empty( $args['desc'] ) ) : ?>
                <p class="description" style="margin-top: 8px; margin-left: 4px;">
                    <?php echo esc_html( $args['desc'] ); ?>
                </p>
            <?php endif; ?>

            <?php if ( ! $is_configured && $config_message ) : ?>
                <p style="margin-top: 8px; margin-left: 4px; color: #d63638; font-size: 13px;">
                    <span class="dashicons dashicons-warning" style="font-size: 14px; vertical-align: text-bottom;"></span>
                    <?php echo esc_html( $config_message ); ?>.
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=reactions-indieweb-apis' ) ); ?>"><?php esc_html_e( 'Configure API', 'reactions-indieweb' ); ?></a>
                </p>
            <?php elseif ( $enabled && ! $background_sync_enabled ) : ?>
                <p style="margin-top: 8px; margin-left: 4px; color: #dba617; font-size: 13px;">
                    <span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: text-bottom;"></span>
                    <?php esc_html_e( 'Note: Main background sync is off.', 'reactions-indieweb' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=reactions-indieweb&tab=performance' ) ); ?>"><?php esc_html_e( 'Enable in Performance tab', 'reactions-indieweb' ); ?></a>
                </p>
            <?php endif; ?>

            <?php
            // Show sync start date picker if enabled.
            if ( $is_configured ) :
                $sync_source_key = $this->get_sync_source_key( $args['id'], $source_type );
                $sync_start_dates = $settings['sync_start_dates'] ?? array();
                $current_date = $sync_start_dates[ $sync_source_key ] ?? '';
                // Convert ISO date to input date format (YYYY-MM-DD).
                $date_value = $current_date ? substr( $current_date, 0, 10 ) : '';
            ?>
                <div class="sync-start-date-field" style="margin-top: 12px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <label for="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>" style="display: block; font-weight: 500; margin-bottom: 6px;">
                        <?php esc_html_e( 'Sync Start Date', 'reactions-indieweb' ); ?>
                    </label>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <input
                            type="date"
                            id="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>"
                            name="reactions_indieweb_settings[sync_start_dates][<?php echo esc_attr( $sync_source_key ); ?>]"
                            value="<?php echo esc_attr( $date_value ); ?>"
                            class="regular-text"
                            style="width: auto;"
                        >
                        <button type="button" class="button button-small set-today-btn" data-target="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>">
                            <?php esc_html_e( 'Set to Today', 'reactions-indieweb' ); ?>
                        </button>
                        <button type="button" class="button button-small clear-date-btn" data-target="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>">
                            <?php esc_html_e( 'Clear', 'reactions-indieweb' ); ?>
                        </button>
                    </div>
                    <p class="description" style="margin-top: 6px;">
                        <?php esc_html_e( 'Only auto-sync items from this date forward. Leave empty to import all history.', 'reactions-indieweb' ); ?>
                    </p>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    document.querySelectorAll('.set-today-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var target = document.getElementById(this.dataset.target);
                            if (target) target.value = new Date().toISOString().split('T')[0];
                        });
                    });
                    document.querySelectorAll('.clear-date-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var target = document.getElementById(this.dataset.target);
                            if (target) target.value = '';
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get the sync source key for a given setting ID and source type.
     *
     * @param string $setting_id  The setting ID (e.g., 'listen_auto_import').
     * @param string $source_type The source type (e.g., 'music', 'podcasts').
     * @return string The sync source key for storing in sync_start_dates.
     */
    private function get_sync_source_key( string $setting_id, string $source_type ): string {
        // Map setting IDs and source types to their sync source keys.
        $mappings = array(
            'listen_auto_import'         => 'listenbrainz', // Will be overridden by import source.
            'listen_podcast_auto_import' => 'readwise_podcasts',
            'watch_auto_import'          => 'trakt_movies', // Will be overridden by import source.
            'read_auto_import'           => 'hardcover',    // Will be overridden by import source.
            'read_articles_auto_import'  => 'readwise_articles',
            'checkin_auto_import'        => 'foursquare',
        );

        return $mappings[ $setting_id ] ?? $source_type;
    }

    /**
     * Render Foursquare connection status and actions.
     *
     * @param array<string, mixed> $args Field arguments.
     * @return void
     */
    public function render_foursquare_connection_field( array $args ): void {
        $credentials   = get_option( 'reactions_indieweb_api_credentials', array() );
        $foursquare    = $credentials['foursquare'] ?? array();
        $is_connected  = ! empty( $foursquare['access_token'] );
        $username      = $foursquare['username'] ?? '';
        $has_client_id = ! empty( $foursquare['client_id'] );

        echo '<div class="foursquare-connection-status">';

        if ( $is_connected ) {
            echo '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">';
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px;"></span>';
            echo '<span style="font-weight: 500;">' . esc_html__( 'Connected to Foursquare', 'reactions-indieweb' ) . '</span>';
            if ( $username ) {
                echo '<span style="color: #646970;">(' . esc_html( $username ) . ')</span>';
            }
            echo '</div>';

            echo '<div style="display: flex; gap: 8px; flex-wrap: wrap;">';

            // Import button.
            echo '<button type="button" class="button" id="foursquare-import-checkins">';
            echo '<span class="dashicons dashicons-download" style="margin-top: 4px;"></span> ';
            esc_html_e( 'Import Checkins from Foursquare', 'reactions-indieweb' );
            echo '</button>';

            // Disconnect button.
            echo '<button type="button" class="button" id="foursquare-disconnect" style="color: #d63638;">';
            echo '<span class="dashicons dashicons-no" style="margin-top: 4px;"></span> ';
            esc_html_e( 'Disconnect', 'reactions-indieweb' );
            echo '</button>';

            echo '</div>';

            echo '<p class="description" style="margin-top: 12px;">';
            esc_html_e( 'With Foursquare connected, you can:', 'reactions-indieweb' );
            echo '</p>';
            echo '<ul style="margin: 8px 0 0 24px; list-style: disc;">';
            echo '<li>' . esc_html__( 'POSSE: Publish checkins on your site first, automatically sync to Foursquare', 'reactions-indieweb' ) . '</li>';
            echo '<li>' . esc_html__( 'PESOS: Import existing Foursquare checkins to your site', 'reactions-indieweb' ) . '</li>';
            echo '</ul>';

        } elseif ( $has_client_id ) {
            echo '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">';
            echo '<span class="dashicons dashicons-warning" style="color: #dba617; font-size: 20px;"></span>';
            echo '<span>' . esc_html__( 'Foursquare app configured but not connected', 'reactions-indieweb' ) . '</span>';
            echo '</div>';

            echo '<p>';
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=reactions-indieweb-apis' ) ) . '" class="button button-primary">';
            esc_html_e( 'Connect to Foursquare', 'reactions-indieweb' );
            echo '</a>';
            echo '</p>';

        } else {
            echo '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">';
            echo '<span class="dashicons dashicons-info" style="color: #72aee6; font-size: 20px;"></span>';
            echo '<span>' . esc_html__( 'Foursquare not configured', 'reactions-indieweb' ) . '</span>';
            echo '</div>';

            echo '<p class="description">';
            esc_html_e( 'To enable bidirectional checkin sync with Foursquare:', 'reactions-indieweb' );
            echo '</p>';
            echo '<ol style="margin: 8px 0 12px 24px;">';
            echo '<li>' . wp_kses(
                sprintf(
                    /* translators: %s: URL to Foursquare developers */
                    __( 'Create an app at <a href="%s" target="_blank" rel="noopener">foursquare.com/developers/apps</a>', 'reactions-indieweb' ),
                    'https://foursquare.com/developers/apps'
                ),
                array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
            ) . '</li>';
            echo '<li>' . esc_html__( 'Copy the Client ID and Client Secret', 'reactions-indieweb' ) . '</li>';
            echo '<li>' . wp_kses(
                sprintf(
                    /* translators: %s: URL to API settings page */
                    __( 'Enter them in the <a href="%s">API Settings</a> page', 'reactions-indieweb' ),
                    admin_url( 'admin.php?page=reactions-indieweb-apis' )
                ),
                array( 'a' => array( 'href' => array() ) )
            ) . '</li>';
            echo '<li>' . esc_html__( 'Click "Connect to Foursquare" to authorize', 'reactions-indieweb' ) . '</li>';
            echo '</ol>';

            echo '<p>';
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=reactions-indieweb-apis' ) ) . '" class="button">';
            esc_html_e( 'Go to API Settings', 'reactions-indieweb' );
            echo '</a>';
            echo '</p>';
        }

        echo '</div>';

        // JavaScript for import and disconnect actions.
        if ( $is_connected ) {
            ?>
            <script>
            document.getElementById('foursquare-import-checkins')?.addEventListener('click', function() {
                const btn = this;
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 4px; animation: rotation 1s linear infinite;"></span> <?php echo esc_js( __( 'Importing...', 'reactions-indieweb' ) ); ?>';

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'reactions_foursquare_import',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'reactions_indieweb_admin' ) ); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    if (data.success) {
                        alert('<?php echo esc_js( __( 'Import complete!', 'reactions-indieweb' ) ); ?> ' + data.data.message);
                    } else {
                        alert('<?php echo esc_js( __( 'Import failed:', 'reactions-indieweb' ) ); ?> ' + data.data.message);
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                    alert('<?php echo esc_js( __( 'Import failed:', 'reactions-indieweb' ) ); ?> ' + error.message);
                });
            });

            document.getElementById('foursquare-disconnect')?.addEventListener('click', function() {
                if (!confirm('<?php echo esc_js( __( 'Are you sure you want to disconnect from Foursquare? You can reconnect later.', 'reactions-indieweb' ) ); ?>')) {
                    return;
                }

                const btn = this;
                btn.disabled = true;

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'reactions_foursquare_disconnect',
                        nonce: '<?php echo esc_js( wp_create_nonce( 'reactions_indieweb_admin' ) ); ?>'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        btn.disabled = false;
                        alert('<?php echo esc_js( __( 'Disconnect failed:', 'reactions-indieweb' ) ); ?> ' + data.data.message);
                    }
                })
                .catch(error => {
                    btn.disabled = false;
                    alert('<?php echo esc_js( __( 'Disconnect failed:', 'reactions-indieweb' ) ); ?> ' + error.message);
                });
            });
            </script>
            <style>
            @keyframes rotation {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            </style>
            <?php
        }
    }
}

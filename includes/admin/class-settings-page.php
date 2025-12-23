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

        add_settings_field(
            'listen_auto_import',
            __( 'Auto Import', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_listen',
            'reactions_indieweb_listen_section',
            array(
                'id'   => 'listen_auto_import',
                'desc' => __( 'Automatically import new listens from connected services.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'listen_import_source',
            __( 'Import Source', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_listen',
            'reactions_indieweb_listen_section',
            array(
                'id'      => 'listen_import_source',
                'options' => array(
                    'listenbrainz' => 'ListenBrainz',
                    'lastfm'       => 'Last.fm',
                ),
                'desc'    => __( 'Primary source for importing listen history.', 'reactions-indieweb' ),
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
            'watch_auto_import',
            __( 'Auto Import', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_watch',
            'reactions_indieweb_watch_section',
            array(
                'id'   => 'watch_auto_import',
                'desc' => __( 'Automatically import new watches from connected services.', 'reactions-indieweb' ),
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

        add_settings_field(
            'read_auto_import',
            __( 'Auto Import', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_read',
            'reactions_indieweb_read_section',
            array(
                'id'   => 'read_auto_import',
                'desc' => __( 'Automatically import reading history from connected services.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'read_import_source',
            __( 'Import Source', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_read',
            'reactions_indieweb_read_section',
            array(
                'id'      => 'read_import_source',
                'options' => array(
                    'hardcover' => 'Hardcover',
                ),
                'desc'    => __( 'Primary source for importing reading history.', 'reactions-indieweb' ),
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
            __( 'Auto Import', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id'   => 'checkin_auto_import',
                'desc' => __( 'Automatically import checkins from connected services.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'checkin_privacy',
            __( 'Location Privacy', 'reactions-indieweb' ),
            array( $this, 'render_select_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id'      => 'checkin_privacy',
                'options' => array(
                    'public'  => __( 'Public (show venue and address)', 'reactions-indieweb' ),
                    'venue'   => __( 'Venue only (hide address)', 'reactions-indieweb' ),
                    'city'    => __( 'City only', 'reactions-indieweb' ),
                    'private' => __( 'Private (hide all location)', 'reactions-indieweb' ),
                ),
                'desc'    => __( 'How much location detail to show in checkin posts.', 'reactions-indieweb' ),
            )
        );

        add_settings_field(
            'checkin_include_coords',
            __( 'Include Coordinates', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_checkin',
            'reactions_indieweb_checkin_section',
            array(
                'id'   => 'checkin_include_coords',
                'desc' => __( 'Include latitude/longitude in checkin posts (for mapping).', 'reactions-indieweb' ),
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

        add_settings_field(
            'enable_background_sync',
            __( 'Background Sync', 'reactions-indieweb' ),
            array( $this, 'render_checkbox_field' ),
            'reactions_indieweb_performance',
            'reactions_indieweb_performance_section',
            array(
                'id'   => 'enable_background_sync',
                'desc' => __( 'Use WP-Cron for background synchronization with external services.', 'reactions-indieweb' ),
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
                    <td><code><?php echo esc_html( REACTIONS_INDIEWEB_VERSION ); ?></code></td>
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
}

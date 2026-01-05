<?php
/**
 * Import Page
 *
 * Admin page for importing data from external services.
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

namespace ReactionsForIndieWeb\Admin;

use ReactionsForIndieWeb\Import_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Import page class.
 */
class Import_Page {

    /**
     * Admin instance.
     *
     * @var Admin
     */
    private Admin $admin;

    /**
     * Import sources configuration.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $import_sources;

    /**
     * Constructor.
     *
     * @param Admin $admin Admin instance.
     */
    public function __construct( Admin $admin ) {
        $this->admin = $admin;
        $this->import_sources = $this->get_import_sources();
    }

    /**
     * Initialize import page.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_ajax_reactions_indieweb_start_import', array( $this, 'ajax_start_import' ) );
        add_action( 'wp_ajax_reactions_indieweb_cancel_import', array( $this, 'ajax_cancel_import' ) );
        add_action( 'wp_ajax_reactions_indieweb_get_import_preview', array( $this, 'ajax_get_import_preview' ) );
    }

    /**
     * Get import sources configuration.
     *
     * @return array<string, array<string, mixed>>
     */
    private function get_import_sources(): array {
        return array(
            'listenbrainz' => array(
                'name'        => 'ListenBrainz',
                'description' => __( 'Import your listening history from ListenBrainz.', 'reactions-indieweb' ),
                'post_kind'   => 'listen',
                'icon'        => 'dashicons-format-audio',
                'api_key'     => 'listenbrainz',
                'options'     => array(
                    'date_from' => array(
                        'label' => __( 'From Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'date_to' => array(
                        'label' => __( 'To Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'limit' => array(
                        'label'   => __( 'Maximum Items', 'reactions-indieweb' ),
                        'type'    => 'number',
                        'default' => 100,
                        'max'     => 1000,
                    ),
                ),
            ),
            'lastfm' => array(
                'name'        => 'Last.fm',
                'description' => __( 'Import your scrobble history from Last.fm.', 'reactions-indieweb' ),
                'post_kind'   => 'listen',
                'icon'        => 'dashicons-format-audio',
                'api_key'     => 'lastfm',
                'options'     => array(
                    'username' => array(
                        'label'    => __( 'Last.fm Username', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => true,
                    ),
                    'date_from' => array(
                        'label' => __( 'From Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'date_to' => array(
                        'label' => __( 'To Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'limit' => array(
                        'label'   => __( 'Maximum Items', 'reactions-indieweb' ),
                        'type'    => 'number',
                        'default' => 100,
                        'max'     => 1000,
                    ),
                ),
            ),
            'trakt_movies' => array(
                'name'        => 'Trakt Movies',
                'description' => __( 'Import your movie watch history from Trakt.', 'reactions-indieweb' ),
                'post_kind'   => 'watch',
                'icon'        => 'dashicons-video-alt2',
                'api_key'     => 'trakt',
                'options'     => array(
                    'date_from' => array(
                        'label' => __( 'From Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'date_to' => array(
                        'label' => __( 'To Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'include_ratings' => array(
                        'label'   => __( 'Include Ratings', 'reactions-indieweb' ),
                        'type'    => 'checkbox',
                        'default' => true,
                    ),
                ),
            ),
            'trakt_shows' => array(
                'name'        => 'Trakt TV Shows',
                'description' => __( 'Import your TV show watch history from Trakt.', 'reactions-indieweb' ),
                'post_kind'   => 'watch',
                'icon'        => 'dashicons-video-alt2',
                'api_key'     => 'trakt',
                'options'     => array(
                    'date_from' => array(
                        'label' => __( 'From Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'date_to' => array(
                        'label' => __( 'To Date', 'reactions-indieweb' ),
                        'type'  => 'date',
                    ),
                    'group_by' => array(
                        'label'   => __( 'Group Episodes', 'reactions-indieweb' ),
                        'type'    => 'select',
                        'options' => array(
                            'none'    => __( 'Individual episodes', 'reactions-indieweb' ),
                            'season'  => __( 'By season', 'reactions-indieweb' ),
                            'show'    => __( 'By show', 'reactions-indieweb' ),
                        ),
                        'default' => 'none',
                    ),
                ),
            ),
            'simkl' => array(
                'name'        => 'Simkl',
                'description' => __( 'Import your watch history from Simkl.', 'reactions-indieweb' ),
                'post_kind'   => 'watch',
                'icon'        => 'dashicons-video-alt2',
                'api_key'     => 'simkl',
                'options'     => array(
                    'type' => array(
                        'label'   => __( 'Content Type', 'reactions-indieweb' ),
                        'type'    => 'select',
                        'options' => array(
                            'movies' => __( 'Movies', 'reactions-indieweb' ),
                            'shows'  => __( 'TV Shows', 'reactions-indieweb' ),
                            'anime'  => __( 'Anime', 'reactions-indieweb' ),
                        ),
                        'default' => 'movies',
                    ),
                    'status' => array(
                        'label'   => __( 'Watch Status', 'reactions-indieweb' ),
                        'type'    => 'select',
                        'options' => array(
                            'completed'  => __( 'Completed', 'reactions-indieweb' ),
                            'watching'   => __( 'Currently Watching', 'reactions-indieweb' ),
                            'plantowatch' => __( 'Plan to Watch', 'reactions-indieweb' ),
                            'all'        => __( 'All', 'reactions-indieweb' ),
                        ),
                        'default' => 'completed',
                    ),
                ),
            ),
            'hardcover' => array(
                'name'        => 'Hardcover',
                'description' => __( 'Import your reading history from Hardcover.', 'reactions-indieweb' ),
                'post_kind'   => 'read',
                'icon'        => 'dashicons-book',
                'api_key'     => 'hardcover',
                'options'     => array(
                    'status' => array(
                        'label'   => __( 'Reading Status', 'reactions-indieweb' ),
                        'type'    => 'select',
                        'options' => array(
                            'finished' => __( 'Finished', 'reactions-indieweb' ),
                            'reading'  => __( 'Currently Reading', 'reactions-indieweb' ),
                            'want'     => __( 'Want to Read', 'reactions-indieweb' ),
                            'dnf'      => __( 'Did Not Finish', 'reactions-indieweb' ),
                            'all'      => __( 'All', 'reactions-indieweb' ),
                        ),
                        'default' => 'finished',
                    ),
                    'include_ratings' => array(
                        'label'   => __( 'Include Ratings', 'reactions-indieweb' ),
                        'type'    => 'checkbox',
                        'default' => true,
                    ),
                    'include_reviews' => array(
                        'label'   => __( 'Include Reviews', 'reactions-indieweb' ),
                        'type'    => 'checkbox',
                        'default' => true,
                    ),
                ),
            ),
        );
    }

    /**
     * Render the import page.
     *
     * @return void
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $active_imports = get_option( 'reactions_indieweb_active_imports', array() );

        ?>
        <div class="wrap reactions-indieweb-import">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( ! empty( $active_imports ) ) : ?>
                <div class="active-imports-section">
                    <h2><?php esc_html_e( 'Active Imports', 'reactions-indieweb' ); ?></h2>
                    <?php $this->render_active_imports( $active_imports ); ?>
                </div>
                <hr>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Start New Import', 'reactions-indieweb' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Import your media history from connected services. Imports run in the background and may take a while for large collections.', 'reactions-indieweb' ); ?>
            </p>

            <div class="import-sources">
                <?php foreach ( $this->import_sources as $source_id => $source ) : ?>
                    <?php
                    $api_key     = $source['api_key'];
                    $is_enabled  = ! empty( $credentials[ $api_key ]['enabled'] );
                    $is_connected = $is_enabled && $this->check_api_connected( $api_key, $credentials[ $api_key ] ?? array() );
                    ?>
                    <div class="import-source-card <?php echo $is_connected ? 'available' : 'unavailable'; ?>"
                         data-source="<?php echo esc_attr( $source_id ); ?>">

                        <div class="source-header">
                            <span class="dashicons <?php echo esc_attr( $source['icon'] ); ?>"></span>
                            <h3><?php echo esc_html( $source['name'] ); ?></h3>
                            <?php if ( ! $is_connected ) : ?>
                                <span class="status-badge not-connected">
                                    <?php esc_html_e( 'Not Connected', 'reactions-indieweb' ); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <p class="source-description"><?php echo esc_html( $source['description'] ); ?></p>

                        <?php if ( $is_connected ) : ?>
                            <div class="source-options">
                                <?php $this->render_source_options( $source_id, $source['options'] ?? array() ); ?>
                            </div>

                            <div class="source-actions">
                                <button type="button" class="button import-preview-button" data-source="<?php echo esc_attr( $source_id ); ?>">
                                    <span class="dashicons dashicons-visibility"></span>
                                    <?php esc_html_e( 'Preview', 'reactions-indieweb' ); ?>
                                </button>
                                <button type="button" class="button button-primary import-start-button" data-source="<?php echo esc_attr( $source_id ); ?>">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e( 'Start Import', 'reactions-indieweb' ); ?>
                                </button>
                            </div>
                        <?php else : ?>
                            <div class="source-actions">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=reactions-indieweb-apis' ) ); ?>" class="button">
                                    <?php esc_html_e( 'Configure API', 'reactions-indieweb' ); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Import preview modal -->
            <div id="import-preview-modal" class="reactions-modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><?php esc_html_e( 'Import Preview', 'reactions-indieweb' ); ?></h2>
                        <button type="button" class="modal-close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="preview-loading">
                            <span class="spinner is-active"></span>
                            <?php esc_html_e( 'Loading preview...', 'reactions-indieweb' ); ?>
                        </div>
                        <div class="preview-content" style="display: none;"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="button modal-cancel">
                            <?php esc_html_e( 'Cancel', 'reactions-indieweb' ); ?>
                        </button>
                        <button type="button" class="button button-primary modal-confirm-import">
                            <?php esc_html_e( 'Start Import', 'reactions-indieweb' ); ?>
                        </button>
                    </div>
                </div>
            </div>

            <hr>

            <h2><?php esc_html_e( 'Import History', 'reactions-indieweb' ); ?></h2>
            <?php $this->render_import_history(); ?>
        </div>
        <?php
    }

    /**
     * Render source options.
     *
     * @param string                            $source_id Source identifier.
     * @param array<string, array<string,mixed>> $options  Source options.
     * @return void
     */
    private function render_source_options( string $source_id, array $options ): void {
        if ( empty( $options ) ) {
            return;
        }

        echo '<div class="options-grid">';

        foreach ( $options as $option_id => $option ) {
            $field_name = "import_{$source_id}_{$option_id}";
            $default    = $option['default'] ?? '';

            echo '<div class="option-field">';
            echo '<label for="' . esc_attr( $field_name ) . '">' . esc_html( $option['label'] ) . '</label>';

            switch ( $option['type'] ) {
                case 'date':
                    printf(
                        '<input type="date" id="%s" name="%s" class="import-option" data-source="%s" data-option="%s">',
                        esc_attr( $field_name ),
                        esc_attr( $field_name ),
                        esc_attr( $source_id ),
                        esc_attr( $option_id )
                    );
                    break;

                case 'number':
                    printf(
                        '<input type="number" id="%s" name="%s" value="%s" min="1" max="%s" class="small-text import-option" data-source="%s" data-option="%s">',
                        esc_attr( $field_name ),
                        esc_attr( $field_name ),
                        esc_attr( $default ),
                        esc_attr( $option['max'] ?? 1000 ),
                        esc_attr( $source_id ),
                        esc_attr( $option_id )
                    );
                    break;

                case 'text':
                    printf(
                        '<input type="text" id="%s" name="%s" value="%s" class="regular-text import-option" data-source="%s" data-option="%s" %s>',
                        esc_attr( $field_name ),
                        esc_attr( $field_name ),
                        esc_attr( $default ),
                        esc_attr( $source_id ),
                        esc_attr( $option_id ),
                        ! empty( $option['required'] ) ? 'required' : ''
                    );
                    break;

                case 'checkbox':
                    printf(
                        '<input type="checkbox" id="%s" name="%s" value="1" %s class="import-option" data-source="%s" data-option="%s">',
                        esc_attr( $field_name ),
                        esc_attr( $field_name ),
                        checked( $default, true, false ),
                        esc_attr( $source_id ),
                        esc_attr( $option_id )
                    );
                    break;

                case 'select':
                    printf(
                        '<select id="%s" name="%s" class="import-option" data-source="%s" data-option="%s">',
                        esc_attr( $field_name ),
                        esc_attr( $field_name ),
                        esc_attr( $source_id ),
                        esc_attr( $option_id )
                    );
                    foreach ( $option['options'] as $value => $label ) {
                        printf(
                            '<option value="%s" %s>%s</option>',
                            esc_attr( $value ),
                            selected( $default, $value, false ),
                            esc_html( $label )
                        );
                    }
                    echo '</select>';
                    break;
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render active imports.
     *
     * @param array<string, array<string, mixed>> $active_imports Active imports data.
     * @return void
     */
    private function render_active_imports( array $active_imports ): void {
        ?>
        <div class="active-imports">
            <?php foreach ( $active_imports as $import_id => $import ) : ?>
                <div class="active-import" data-import-id="<?php echo esc_attr( $import_id ); ?>">
                    <div class="import-info">
                        <strong><?php echo esc_html( $this->import_sources[ $import['source'] ]['name'] ?? $import['source'] ); ?></strong>
                        <span class="import-status">
                            <?php echo esc_html( ucfirst( $import['status'] ?? 'running' ) ); ?>
                        </span>
                    </div>

                    <div class="import-progress">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo esc_attr( $import['progress'] ?? 0 ); ?>%;"></div>
                        </div>
                        <span class="progress-text">
                            <?php
                            printf(
                                /* translators: 1: Processed count, 2: Total count */
                                esc_html__( '%1$d of %2$d items', 'reactions-indieweb' ),
                                (int) ( $import['processed'] ?? 0 ),
                                (int) ( $import['total'] ?? 0 )
                            );
                            ?>
                        </span>
                    </div>

                    <div class="import-actions">
                        <button type="button" class="button import-cancel-button" data-import-id="<?php echo esc_attr( $import_id ); ?>">
                            <?php esc_html_e( 'Cancel', 'reactions-indieweb' ); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render import history.
     *
     * @return void
     */
    private function render_import_history(): void {
        $history = get_option( 'reactions_indieweb_import_history', array() );

        if ( empty( $history ) ) {
            echo '<p class="description">' . esc_html__( 'No imports have been run yet.', 'reactions-indieweb' ) . '</p>';
            return;
        }

        // Sort by date descending.
        usort( $history, function( $a, $b ) {
            return ( $b['completed_at'] ?? 0 ) - ( $a['completed_at'] ?? 0 );
        } );

        // Limit to last 20.
        $history = array_slice( $history, 0, 20 );

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Source', 'reactions-indieweb' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'reactions-indieweb' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'reactions-indieweb' ); ?></th>
                    <th><?php esc_html_e( 'Items', 'reactions-indieweb' ); ?></th>
                    <th><?php esc_html_e( 'Posts Created', 'reactions-indieweb' ); ?></th>
                    <th><?php esc_html_e( 'Duplicates Skipped', 'reactions-indieweb' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $history as $import ) : ?>
                    <tr>
                        <td>
                            <?php echo esc_html( $this->import_sources[ $import['source'] ]['name'] ?? $import['source'] ); ?>
                        </td>
                        <td>
                            <?php
                            if ( ! empty( $import['completed_at'] ) ) {
                                echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $import['completed_at'] ) );
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            $status_class = 'completed' === $import['status'] ? 'success' : ( 'failed' === $import['status'] ? 'error' : 'warning' );
                            ?>
                            <span class="status-badge <?php echo esc_attr( $status_class ); ?>">
                                <?php echo esc_html( ucfirst( $import['status'] ?? 'unknown' ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $import['total'] ?? 0 ); ?></td>
                        <td><?php echo esc_html( $import['created'] ?? 0 ); ?></td>
                        <td><?php echo esc_html( $import['duplicates'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Check if API is connected.
     *
     * @param string               $api_key     API key identifier.
     * @param array<string, mixed> $credentials API credentials.
     * @return bool True if connected.
     */
    private function check_api_connected( string $api_key, array $credentials ): bool {
        // OAuth APIs need access token.
        if ( in_array( $api_key, array( 'trakt', 'simkl' ), true ) ) {
            return ! empty( $credentials['access_token'] );
        }

        // Token-based APIs.
        if ( 'listenbrainz' === $api_key ) {
            return ! empty( $credentials['token'] );
        }

        if ( 'hardcover' === $api_key ) {
            return ! empty( $credentials['api_token'] );
        }

        // API key-based.
        if ( 'lastfm' === $api_key ) {
            return ! empty( $credentials['api_key'] );
        }

        return true;
    }

    /**
     * AJAX handler: Start import.
     *
     * @return void
     */
    public function ajax_start_import(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $options = isset( $_POST['options'] ) ? $this->sanitize_import_options( wp_unslash( $_POST['options'] ) ) : array();

        if ( empty( $source ) || ! isset( $this->import_sources[ $source ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid import source.', 'reactions-indieweb' ) ) );
        }

        try {
            $import_manager = new Import_Manager();
            $result = $import_manager->start_import( $source, $options );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            // Handle array result from Import_Manager.
            if ( is_array( $result ) ) {
                if ( empty( $result['success'] ) ) {
                    wp_send_json_error( array( 'message' => $result['error'] ?? __( 'Import failed to start.', 'reactions-indieweb' ) ) );
                }

                $job_id = $result['job_id'] ?? '';

                // Process the import immediately instead of waiting for WP-Cron.
                // This ensures imports work on local dev sites where cron may not run.
                if ( $job_id ) {
                    $import_manager->process_import_batch( $job_id, $source );

                    // Get updated job status.
                    $job_status = $import_manager->get_status( $job_id );

                    wp_send_json_success( array(
                        'import_id' => $job_id,
                        'message'   => sprintf(
                            __( 'Import completed: %d imported, %d skipped.', 'reactions-indieweb' ),
                            $job_status['imported'] ?? 0,
                            $job_status['skipped'] ?? 0
                        ),
                        'imported'  => $job_status['imported'] ?? 0,
                        'skipped'   => $job_status['skipped'] ?? 0,
                    ) );
                }

                wp_send_json_success( array(
                    'import_id' => $job_id,
                    'message'   => $result['message'] ?? __( 'Import started successfully.', 'reactions-indieweb' ),
                ) );
            }

            wp_send_json_success( array(
                'import_id' => $result,
                'message'   => __( 'Import started successfully.', 'reactions-indieweb' ),
            ) );
        } catch ( \Exception $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ) );
        } catch ( \Error $e ) {
            wp_send_json_error( array( 'message' => 'PHP Error: ' . $e->getMessage() ) );
        }
    }

    /**
     * AJAX handler: Cancel import.
     *
     * @return void
     */
    public function ajax_cancel_import(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';

        if ( empty( $import_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Import ID required.', 'reactions-indieweb' ) ) );
        }

        $import_manager = new Import_Manager();
        $result = $import_manager->cancel_import( $import_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Import cancelled.', 'reactions-indieweb' ),
        ) );
    }

    /**
     * AJAX handler: Get import preview.
     *
     * @return void
     */
    public function ajax_get_import_preview(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $options = isset( $_POST['options'] ) ? $this->sanitize_import_options( wp_unslash( $_POST['options'] ) ) : array();

        if ( empty( $source ) || ! isset( $this->import_sources[ $source ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid import source.', 'reactions-indieweb' ) ) );
        }

        $preview = $this->get_import_preview( $source, $options );

        if ( is_wp_error( $preview ) ) {
            wp_send_json_error( array( 'message' => $preview->get_error_message() ) );
        }

        wp_send_json_success( $preview );
    }

    /**
     * Get import preview data.
     *
     * @param string               $source  Import source.
     * @param array<string, mixed> $options Import options.
     * @return array<string, mixed>|\WP_Error Preview data or error.
     */
    private function get_import_preview( string $source, array $options ) {
        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $source_config = $this->import_sources[ $source ];
        $api_key = $source_config['api_key'];
        $api_creds = $credentials[ $api_key ] ?? array();

        // Fetch a small sample of items.
        $preview_limit = 5;
        $items = array();
        $total_count = 0;

        switch ( $source ) {
            case 'listenbrainz':
                $lb_creds = $credentials['listenbrainz'] ?? array();
                $username = $lb_creds['username'] ?? '';
                if ( empty( $username ) ) {
                    return new \WP_Error( 'missing_username', __( 'ListenBrainz username not configured. Please set it in API Connections.', 'reactions-indieweb' ) );
                }

                $api = new \ReactionsForIndieWeb\APIs\ListenBrainz();
                $listens = $api->get_listens( $username, $preview_limit );
                if ( is_wp_error( $listens ) ) {
                    return $listens;
                }

                $total_count = $listens['count'] ?? count( $listens['listens'] ?? array() );
                $items = array_slice( $listens['listens'] ?? array(), 0, $preview_limit );
                break;

            case 'lastfm':
                $username = $options['username'] ?? '';
                if ( empty( $username ) ) {
                    return new \WP_Error( 'missing_username', __( 'Please enter your Last.fm username.', 'reactions-indieweb' ) );
                }

                $api = new \ReactionsForIndieWeb\APIs\LastFM();

                // Check if API is configured.
                if ( ! $api->test_connection() ) {
                    return new \WP_Error( 'api_not_configured', __( 'Last.fm API is not configured. Please add your API key in API Connections.', 'reactions-indieweb' ) );
                }

                $tracks = $api->get_recent_tracks( $username, $preview_limit );
                if ( is_wp_error( $tracks ) ) {
                    return $tracks;
                }

                $total_count = $tracks['total'] ?? count( $tracks['tracks'] ?? array() );
                $items = array_slice( $tracks['tracks'] ?? array(), 0, $preview_limit );

                if ( empty( $items ) && 0 === $total_count ) {
                    return new \WP_Error( 'no_tracks', __( 'No tracks found for this username. Check that the username is correct.', 'reactions-indieweb' ) );
                }
                break;

            case 'trakt_movies':
            case 'trakt_shows':
                $api = new \ReactionsForIndieWeb\APIs\Trakt();
                if ( ! $api->is_configured() ) {
                    return new \WP_Error( 'api_not_configured', __( 'Trakt API is not configured. Please set up OAuth in API Connections.', 'reactions-indieweb' ) );
                }

                $type = 'trakt_movies' === $source ? 'movies' : 'shows';
                $history = $api->get_history( $type, 1, $preview_limit );
                if ( is_wp_error( $history ) ) {
                    return $history;
                }

                $items = $history;
                $total_count = count( $history ) * 10; // Rough estimate.
                break;

            case 'simkl':
                $api = new \ReactionsForIndieWeb\APIs\Simkl();
                if ( ! $api->is_configured() ) {
                    return new \WP_Error( 'api_not_configured', __( 'Simkl API is not configured. Please set up OAuth in API Connections.', 'reactions-indieweb' ) );
                }

                $type = $options['type'] ?? 'movies';
                $history = $api->get_history( $type );
                if ( is_wp_error( $history ) ) {
                    return $history;
                }

                $total_count = count( $history );
                $items = array_slice( $history, 0, $preview_limit );
                break;

            case 'hardcover':
                $api = new \ReactionsForIndieWeb\APIs\Hardcover();
                if ( ! $api->is_configured() ) {
                    return new \WP_Error( 'api_not_configured', __( 'Hardcover API is not configured. Please add your API token in API Connections.', 'reactions-indieweb' ) );
                }

                $status = $options['status'] ?? 'finished';
                $books = $api->get_reading_list( $status, $preview_limit );
                if ( is_wp_error( $books ) ) {
                    return $books;
                }

                $items = $books;
                $total_count = count( $books ) * 5; // Estimate.
                break;

            default:
                return new \WP_Error( 'unsupported', __( 'Import preview not supported for this source.', 'reactions-indieweb' ) );
        }

        return array(
            'source'      => $source,
            'source_name' => $source_config['name'],
            'total_count' => $total_count,
            'sample'      => $this->format_preview_items( $items, $source ),
            'post_kind'   => $source_config['post_kind'],
        );
    }

    /**
     * Format preview items for display.
     *
     * @param array<int, array<string, mixed>> $items  Raw items.
     * @param string                           $source Import source.
     * @return array<int, array<string, string>> Formatted items.
     */
    private function format_preview_items( array $items, string $source ): array {
        $formatted = array();

        foreach ( $items as $item ) {
            switch ( $source ) {
                case 'listenbrainz':
                case 'lastfm':
                    $formatted[] = array(
                        'title'  => $item['track'] ?? $item['track_name'] ?? 'Unknown Track',
                        'artist' => $item['artist'] ?? $item['artist_name'] ?? 'Unknown Artist',
                        'date'   => isset( $item['listened_at'] ) ? wp_date( 'M j, Y g:i a', $item['listened_at'] ) : '',
                    );
                    break;

                case 'trakt_movies':
                    $formatted[] = array(
                        'title' => $item['movie']['title'] ?? 'Unknown Movie',
                        'year'  => $item['movie']['year'] ?? '',
                        'date'  => isset( $item['watched_at'] ) ? wp_date( 'M j, Y g:i a', strtotime( $item['watched_at'] ) ) : '',
                    );
                    break;

                case 'trakt_shows':
                    $formatted[] = array(
                        'title'   => $item['show']['title'] ?? 'Unknown Show',
                        'episode' => sprintf( 'S%02dE%02d', $item['episode']['season'] ?? 0, $item['episode']['number'] ?? 0 ),
                        'date'    => isset( $item['watched_at'] ) ? wp_date( 'M j, Y g:i a', strtotime( $item['watched_at'] ) ) : '',
                    );
                    break;

                case 'simkl':
                    $formatted[] = array(
                        'title' => $item['movie']['title'] ?? $item['show']['title'] ?? 'Unknown',
                        'year'  => $item['movie']['year'] ?? $item['show']['year'] ?? '',
                    );
                    break;

                case 'hardcover':
                    $formatted[] = array(
                        'title'  => $item['book']['title'] ?? 'Unknown Book',
                        'author' => $item['book']['author'] ?? '',
                        'rating' => $item['rating'] ?? '',
                    );
                    break;
            }
        }

        return $formatted;
    }

    /**
     * Sanitize import options.
     *
     * @param mixed $options Raw options.
     * @return array<string, mixed> Sanitized options.
     */
    private function sanitize_import_options( $options ): array {
        if ( ! is_array( $options ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $options as $key => $value ) {
            $key = sanitize_key( $key );

            if ( is_array( $value ) ) {
                $sanitized[ $key ] = $this->sanitize_import_options( $value );
            } elseif ( is_bool( $value ) ) {
                $sanitized[ $key ] = $value;
            } else {
                $sanitized[ $key ] = sanitize_text_field( $value );
            }
        }

        return $sanitized;
    }
}

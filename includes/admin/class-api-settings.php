<?php
/**
 * API Settings Page
 *
 * Manages API credentials and connection settings.
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

namespace ReactionsForIndieWeb\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API settings class.
 */
class API_Settings {

    /**
     * Admin instance.
     *
     * @var Admin
     */
    private Admin $admin;

    /**
     * API configurations.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $api_configs;

    /**
     * Constructor.
     *
     * @param Admin $admin Admin instance.
     */
    public function __construct( Admin $admin ) {
        $this->admin = $admin;
        $this->api_configs = $this->get_api_configs();
    }

    /**
     * Initialize API settings.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_reactions_indieweb_oauth_callback', array( $this, 'handle_oauth_callback' ) );
    }

    /**
     * Get API configurations.
     *
     * @return array<string, array<string, mixed>>
     */
    private function get_api_configs(): array {
        return array(
            'musicbrainz' => array(
                'name'        => 'MusicBrainz',
                'description' => __( 'Music metadata database. Free, no API key required.', 'reactions-indieweb' ),
                'category'    => 'music',
                'docs_url'    => 'https://musicbrainz.org/doc/MusicBrainz_API',
                'auth_type'   => 'user_agent',
                'fields'      => array(
                    'app_name' => array(
                        'label'       => __( 'Application Name', 'reactions-indieweb' ),
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => 'Reactions for IndieWeb',
                    ),
                    'app_version' => array(
                        'label'       => __( 'Application Version', 'reactions-indieweb' ),
                        'type'        => 'text',
                        'required'    => true,
                        'placeholder' => '1.0.0',
                    ),
                    'contact' => array(
                        'label'       => __( 'Contact Email', 'reactions-indieweb' ),
                        'type'        => 'email',
                        'required'    => true,
                        'placeholder' => 'admin@example.com',
                    ),
                ),
            ),
            'listenbrainz' => array(
                'name'        => 'ListenBrainz',
                'description' => __( 'Open-source scrobble service. Get your token from your profile settings.', 'reactions-indieweb' ),
                'category'    => 'music',
                'docs_url'    => 'https://listenbrainz.readthedocs.io/',
                'signup_url'  => 'https://listenbrainz.org/profile/',
                'auth_type'   => 'token',
                'fields'      => array(
                    'token' => array(
                        'label'    => __( 'User Token', 'reactions-indieweb' ),
                        'type'     => 'password',
                        'required' => true,
                    ),
                    'username' => array(
                        'label'    => __( 'Username', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => false,
                    ),
                ),
            ),
            'lastfm' => array(
                'name'        => 'Last.fm',
                'description' => __( 'Scrobble service and music database. Requires API account.', 'reactions-indieweb' ),
                'category'    => 'music',
                'docs_url'    => 'https://www.last.fm/api',
                'signup_url'  => 'https://www.last.fm/api/account/create',
                'auth_type'   => 'api_key_secret',
                'fields'      => array(
                    'api_key' => array(
                        'label'    => __( 'API Key', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => true,
                    ),
                    'api_secret' => array(
                        'label'    => __( 'Shared Secret', 'reactions-indieweb' ),
                        'type'     => 'password',
                        'required' => true,
                    ),
                    'username' => array(
                        'label'    => __( 'Username', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => false,
                    ),
                ),
            ),
            'tmdb' => array(
                'name'        => 'TMDB',
                'description' => __( 'The Movie Database. Comprehensive movie and TV metadata.', 'reactions-indieweb' ),
                'category'    => 'video',
                'docs_url'    => 'https://developer.themoviedb.org/',
                'signup_url'  => 'https://www.themoviedb.org/settings/api',
                'auth_type'   => 'bearer',
                'fields'      => array(
                    'access_token' => array(
                        'label'    => __( 'API Read Access Token', 'reactions-indieweb' ),
                        'type'     => 'password',
                        'required' => true,
                        'help'     => __( 'Use the "API Read Access Token" (v4 auth), not the API Key.', 'reactions-indieweb' ),
                    ),
                ),
            ),
            'trakt' => array(
                'name'        => 'Trakt',
                'description' => __( 'Watch history tracking. Requires OAuth authentication.', 'reactions-indieweb' ),
                'category'    => 'video',
                'docs_url'    => 'https://trakt.docs.apiary.io/',
                'signup_url'  => 'https://trakt.tv/oauth/applications',
                'auth_type'   => 'oauth',
                'oauth_url'   => 'https://trakt.tv/oauth/authorize',
                'fields'      => array(
                    'client_id' => array(
                        'label'    => __( 'Client ID', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => true,
                    ),
                    'client_secret' => array(
                        'label'    => __( 'Client Secret', 'reactions-indieweb' ),
                        'type'     => 'password',
                        'required' => true,
                    ),
                    'username' => array(
                        'label'    => __( 'Trakt Username', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => false,
                    ),
                ),
            ),
            'simkl' => array(
                'name'        => 'Simkl',
                'description' => __( 'Watch tracking for movies, TV, and anime.', 'reactions-indieweb' ),
                'category'    => 'video',
                'docs_url'    => 'https://simkl.docs.apiary.io/',
                'signup_url'  => 'https://simkl.com/settings/developer/',
                'auth_type'   => 'oauth',
                'fields'      => array(
                    'client_id' => array(
                        'label'    => __( 'Client ID', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => true,
                    ),
                ),
            ),
            'tvmaze' => array(
                'name'        => 'TVmaze',
                'description' => __( 'TV show database. Free, no API key required.', 'reactions-indieweb' ),
                'category'    => 'video',
                'docs_url'    => 'https://www.tvmaze.com/api',
                'auth_type'   => 'none',
                'fields'      => array(),
            ),
            'openlibrary' => array(
                'name'        => 'Open Library',
                'description' => __( 'Book metadata from Internet Archive. Free, no API key required.', 'reactions-indieweb' ),
                'category'    => 'books',
                'docs_url'    => 'https://openlibrary.org/developers/api',
                'auth_type'   => 'none',
                'fields'      => array(),
            ),
            'hardcover' => array(
                'name'        => 'Hardcover',
                'description' => __( 'Book tracking service. Get your token from your settings.', 'reactions-indieweb' ),
                'category'    => 'books',
                'docs_url'    => 'https://hardcover.app/docs',
                'signup_url'  => 'https://hardcover.app/account/api',
                'auth_type'   => 'bearer',
                'fields'      => array(
                    'api_token' => array(
                        'label'    => __( 'API Token', 'reactions-indieweb' ),
                        'type'     => 'password',
                        'required' => true,
                    ),
                    'username' => array(
                        'label'    => __( 'Username', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => false,
                    ),
                ),
            ),
            'google_books' => array(
                'name'        => 'Google Books',
                'description' => __( 'Book metadata fallback. Optional API key for higher rate limits.', 'reactions-indieweb' ),
                'category'    => 'books',
                'docs_url'    => 'https://developers.google.com/books',
                'signup_url'  => 'https://console.cloud.google.com/apis/library/books.googleapis.com',
                'auth_type'   => 'api_key',
                'fields'      => array(
                    'api_key' => array(
                        'label'    => __( 'API Key', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => false,
                        'help'     => __( 'Optional. Works without API key but with lower rate limits.', 'reactions-indieweb' ),
                    ),
                ),
            ),
            'podcastindex' => array(
                'name'        => 'Podcast Index',
                'description' => __( 'Open podcast database. Free API access.', 'reactions-indieweb' ),
                'category'    => 'audio',
                'docs_url'    => 'https://podcastindex-org.github.io/docs-api/',
                'signup_url'  => 'https://api.podcastindex.org/',
                'auth_type'   => 'api_key_secret',
                'fields'      => array(
                    'api_key' => array(
                        'label'    => __( 'API Key', 'reactions-indieweb' ),
                        'type'     => 'text',
                        'required' => true,
                    ),
                    'api_secret' => array(
                        'label'    => __( 'API Secret', 'reactions-indieweb' ),
                        'type'     => 'password',
                        'required' => true,
                    ),
                ),
            ),
            'foursquare' => array(
                'name'        => 'Foursquare',
                'description' => __( 'Venue and place data for checkins.', 'reactions-indieweb' ),
                'category'    => 'location',
                'docs_url'    => 'https://location.foursquare.com/developer/',
                'signup_url'  => 'https://foursquare.com/developers/apps',
                'auth_type'   => 'api_key',
                'fields'      => array(
                    'api_key' => array(
                        'label'    => __( 'API Key', 'reactions-indieweb' ),
                        'type'     => 'password',
                        'required' => true,
                    ),
                ),
            ),
            'nominatim' => array(
                'name'        => 'Nominatim',
                'description' => __( 'OpenStreetMap geocoding. Free with usage policy.', 'reactions-indieweb' ),
                'category'    => 'location',
                'docs_url'    => 'https://nominatim.org/release-docs/develop/api/Overview/',
                'auth_type'   => 'email',
                'fields'      => array(
                    'email' => array(
                        'label'    => __( 'Contact Email', 'reactions-indieweb' ),
                        'type'     => 'email',
                        'required' => true,
                        'help'     => __( 'Required by Nominatim usage policy.', 'reactions-indieweb' ),
                    ),
                ),
            ),
        );
    }

    /**
     * Register settings.
     *
     * @return void
     */
    public function register_settings(): void {
        register_setting(
            'reactions_indieweb_apis',
            'reactions_indieweb_api_credentials',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this->admin, 'sanitize_api_credentials' ),
                'default'           => array(),
            )
        );
    }

    /**
     * Render the API settings page.
     *
     * @return void
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $categories  = array(
            'music'    => __( 'Music', 'reactions-indieweb' ),
            'video'    => __( 'Movies & TV', 'reactions-indieweb' ),
            'books'    => __( 'Books', 'reactions-indieweb' ),
            'audio'    => __( 'Podcasts', 'reactions-indieweb' ),
            'location' => __( 'Location', 'reactions-indieweb' ),
        );

        ?>
        <div class="wrap reactions-indieweb-api-settings">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <p class="description">
                <?php esc_html_e( 'Configure API connections for fetching media metadata and importing history.', 'reactions-indieweb' ); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields( 'reactions_indieweb_apis' ); ?>

                <?php foreach ( $categories as $category_slug => $category_name ) : ?>
                    <h2><?php echo esc_html( $category_name ); ?></h2>

                    <div class="reactions-api-cards">
                        <?php foreach ( $this->api_configs as $api_id => $config ) : ?>
                            <?php if ( $config['category'] === $category_slug ) : ?>
                                <?php $this->render_api_card( $api_id, $config, $credentials[ $api_id ] ?? array() ); ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render an API configuration card.
     *
     * @param string               $api_id      API identifier.
     * @param array<string, mixed> $config      API configuration.
     * @param array<string, mixed> $credentials Saved credentials.
     * @return void
     */
    private function render_api_card( string $api_id, array $config, array $credentials ): void {
        $is_enabled   = ! empty( $credentials['enabled'] );
        $is_connected = $this->check_connection_status( $api_id, $credentials );
        $status_class = $is_enabled ? ( $is_connected ? 'connected' : 'error' ) : 'disabled';

        ?>
        <div class="reactions-api-card <?php echo esc_attr( $status_class ); ?>" data-api="<?php echo esc_attr( $api_id ); ?>">
            <div class="api-card-header">
                <div class="api-card-title">
                    <h3><?php echo esc_html( $config['name'] ); ?></h3>
                    <span class="api-status-badge <?php echo esc_attr( $status_class ); ?>">
                        <?php
                        if ( ! $is_enabled ) {
                            esc_html_e( 'Disabled', 'reactions-indieweb' );
                        } elseif ( $is_connected ) {
                            esc_html_e( 'Connected', 'reactions-indieweb' );
                        } else {
                            esc_html_e( 'Not Connected', 'reactions-indieweb' );
                        }
                        ?>
                    </span>
                </div>
                <label class="api-toggle">
                    <input type="checkbox"
                           name="reactions_indieweb_api_credentials[<?php echo esc_attr( $api_id ); ?>][enabled]"
                           value="1"
                           <?php checked( $is_enabled ); ?>
                           class="api-enable-toggle">
                    <span class="toggle-slider"></span>
                </label>
            </div>

            <p class="api-description"><?php echo esc_html( $config['description'] ); ?></p>

            <div class="api-card-body" <?php echo $is_enabled ? '' : 'style="display: none;"'; ?>>
                <?php if ( ! empty( $config['fields'] ) ) : ?>
                    <table class="form-table api-fields">
                        <?php foreach ( $config['fields'] as $field_id => $field ) : ?>
                            <?php $this->render_field( $api_id, $field_id, $field, $credentials ); ?>
                        <?php endforeach; ?>
                    </table>
                <?php else : ?>
                    <p class="no-config-needed">
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e( 'No configuration needed.', 'reactions-indieweb' ); ?>
                    </p>
                <?php endif; ?>

                <?php if ( 'oauth' === $config['auth_type'] ) : ?>
                    <?php $this->render_oauth_section( $api_id, $config, $credentials ); ?>
                <?php endif; ?>
            </div>

            <div class="api-card-footer">
                <div class="api-actions">
                    <?php if ( $is_enabled && ! empty( $config['fields'] ) ) : ?>
                        <button type="button" class="button api-test-button" data-api="<?php echo esc_attr( $api_id ); ?>">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Test Connection', 'reactions-indieweb' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="api-links">
                    <?php if ( ! empty( $config['docs_url'] ) ) : ?>
                        <a href="<?php echo esc_url( $config['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Documentation', 'reactions-indieweb' ); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    <?php endif; ?>
                    <?php if ( ! empty( $config['signup_url'] ) ) : ?>
                        <a href="<?php echo esc_url( $config['signup_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Get API Key', 'reactions-indieweb' ); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a credential field.
     *
     * @param string               $api_id      API identifier.
     * @param string               $field_id    Field identifier.
     * @param array<string, mixed> $field       Field configuration.
     * @param array<string, mixed> $credentials Saved credentials.
     * @return void
     */
    private function render_field( string $api_id, string $field_id, array $field, array $credentials ): void {
        $value    = $credentials[ $field_id ] ?? '';
        $name     = "reactions_indieweb_api_credentials[{$api_id}][{$field_id}]";
        $id       = "api_{$api_id}_{$field_id}";
        $required = ! empty( $field['required'] ) ? 'required' : '';

        // Mask password values for display.
        $display_value = $value;
        if ( 'password' === $field['type'] && ! empty( $value ) ) {
            $display_value = str_repeat( '*', min( strlen( $value ), 20 ) );
        }

        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr( $id ); ?>">
                    <?php echo esc_html( $field['label'] ); ?>
                    <?php if ( ! empty( $field['required'] ) ) : ?>
                        <span class="required">*</span>
                    <?php endif; ?>
                </label>
            </th>
            <td>
                <?php if ( 'password' === $field['type'] ) : ?>
                    <div class="password-field-wrapper">
                        <input type="password"
                               name="<?php echo esc_attr( $name ); ?>"
                               id="<?php echo esc_attr( $id ); ?>"
                               value="<?php echo esc_attr( $display_value ); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                               autocomplete="off"
                               <?php echo esc_attr( $required ); ?>>
                        <button type="button" class="button toggle-password" aria-label="<?php esc_attr_e( 'Toggle password visibility', 'reactions-indieweb' ); ?>">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                <?php elseif ( 'email' === $field['type'] ) : ?>
                    <input type="email"
                           name="<?php echo esc_attr( $name ); ?>"
                           id="<?php echo esc_attr( $id ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                           <?php echo esc_attr( $required ); ?>>
                <?php else : ?>
                    <input type="text"
                           name="<?php echo esc_attr( $name ); ?>"
                           id="<?php echo esc_attr( $id ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           class="regular-text"
                           placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                           <?php echo esc_attr( $required ); ?>>
                <?php endif; ?>

                <?php if ( ! empty( $field['help'] ) ) : ?>
                    <p class="description"><?php echo esc_html( $field['help'] ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render OAuth section.
     *
     * @param string               $api_id      API identifier.
     * @param array<string, mixed> $config      API configuration.
     * @param array<string, mixed> $credentials Saved credentials.
     * @return void
     */
    private function render_oauth_section( string $api_id, array $config, array $credentials ): void {
        $has_tokens = ! empty( $credentials['access_token'] );
        $token_info = $this->get_token_info( $api_id, $credentials );

        ?>
        <div class="oauth-section">
            <h4><?php esc_html_e( 'OAuth Connection', 'reactions-indieweb' ); ?></h4>

            <?php if ( $has_tokens ) : ?>
                <div class="oauth-connected">
                    <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                    <?php esc_html_e( 'Connected', 'reactions-indieweb' ); ?>

                    <?php if ( ! empty( $token_info['username'] ) ) : ?>
                        <span class="oauth-username">
                            <?php
                            printf(
                                /* translators: %s: Username */
                                esc_html__( 'as %s', 'reactions-indieweb' ),
                                esc_html( $token_info['username'] )
                            );
                            ?>
                        </span>
                    <?php endif; ?>

                    <?php if ( ! empty( $token_info['expires'] ) ) : ?>
                        <span class="oauth-expires">
                            <?php
                            printf(
                                /* translators: %s: Expiration date */
                                esc_html__( '(expires %s)', 'reactions-indieweb' ),
                                esc_html( human_time_diff( time(), $token_info['expires'] ) )
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>

                <p>
                    <button type="button" class="button oauth-disconnect" data-api="<?php echo esc_attr( $api_id ); ?>">
                        <?php esc_html_e( 'Disconnect', 'reactions-indieweb' ); ?>
                    </button>
                    <button type="button" class="button oauth-refresh" data-api="<?php echo esc_attr( $api_id ); ?>">
                        <?php esc_html_e( 'Refresh Token', 'reactions-indieweb' ); ?>
                    </button>
                </p>
            <?php else : ?>
                <div class="oauth-disconnected">
                    <p><?php esc_html_e( 'Click the button below to authorize with your account.', 'reactions-indieweb' ); ?></p>
                    <button type="button" class="button button-primary oauth-connect" data-api="<?php echo esc_attr( $api_id ); ?>">
                        <?php
                        printf(
                            /* translators: %s: Service name */
                            esc_html__( 'Connect to %s', 'reactions-indieweb' ),
                            esc_html( $config['name'] )
                        );
                        ?>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Hidden fields for OAuth tokens -->
            <input type="hidden"
                   name="reactions_indieweb_api_credentials[<?php echo esc_attr( $api_id ); ?>][access_token]"
                   value="<?php echo esc_attr( $credentials['access_token'] ?? '' ); ?>"
                   class="oauth-access-token">
            <input type="hidden"
                   name="reactions_indieweb_api_credentials[<?php echo esc_attr( $api_id ); ?>][refresh_token]"
                   value="<?php echo esc_attr( $credentials['refresh_token'] ?? '' ); ?>"
                   class="oauth-refresh-token">
        </div>
        <?php
    }

    /**
     * Check if an API connection is working.
     *
     * @param string               $api_id      API identifier.
     * @param array<string, mixed> $credentials Saved credentials.
     * @return bool True if connected.
     */
    private function check_connection_status( string $api_id, array $credentials ): bool {
        if ( empty( $credentials['enabled'] ) ) {
            return false;
        }

        $config = $this->api_configs[ $api_id ] ?? null;
        if ( ! $config ) {
            return false;
        }

        // APIs with no required fields are always connected when enabled.
        if ( empty( $config['fields'] ) || 'none' === $config['auth_type'] ) {
            return true;
        }

        // Check OAuth tokens.
        if ( 'oauth' === $config['auth_type'] ) {
            return ! empty( $credentials['access_token'] );
        }

        // Check required fields.
        foreach ( $config['fields'] as $field_id => $field ) {
            if ( ! empty( $field['required'] ) && empty( $credentials[ $field_id ] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get OAuth token info.
     *
     * @param string               $api_id      API identifier.
     * @param array<string, mixed> $credentials Saved credentials.
     * @return array<string, mixed> Token info.
     */
    private function get_token_info( string $api_id, array $credentials ): array {
        $info = array();

        if ( ! empty( $credentials['username'] ) ) {
            $info['username'] = $credentials['username'];
        }

        if ( ! empty( $credentials['token_expires'] ) ) {
            $info['expires'] = (int) $credentials['token_expires'];
        }

        return $info;
    }

    /**
     * Handle OAuth callback.
     *
     * @return void
     */
    public function handle_oauth_callback(): void {
        check_ajax_referer( 'reactions_indieweb_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'reactions-indieweb' ) ) );
        }

        $api  = isset( $_POST['api'] ) ? sanitize_text_field( wp_unslash( $_POST['api'] ) ) : '';
        $code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

        if ( empty( $api ) || empty( $code ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing API or authorization code.', 'reactions-indieweb' ) ) );
        }

        $result = $this->exchange_oauth_code( $api, $code );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * Exchange OAuth authorization code for tokens.
     *
     * @param string $api  API identifier.
     * @param string $code Authorization code.
     * @return array<string, mixed>|\WP_Error Token data or error.
     */
    private function exchange_oauth_code( string $api, string $code ) {
        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $api_creds   = $credentials[ $api ] ?? array();

        switch ( $api ) {
            case 'trakt':
                return $this->exchange_trakt_code( $code, $api_creds );
            case 'simkl':
                return $this->exchange_simkl_code( $code, $api_creds );
            default:
                return new \WP_Error( 'unsupported', __( 'OAuth not supported for this API.', 'reactions-indieweb' ) );
        }
    }

    /**
     * Exchange Trakt authorization code.
     *
     * @param string               $code        Authorization code.
     * @param array<string, mixed> $credentials API credentials.
     * @return array<string, mixed>|\WP_Error Token data or error.
     */
    private function exchange_trakt_code( string $code, array $credentials ) {
        $response = wp_remote_post( 'https://api.trakt.tv/oauth/token', array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'code'          => $code,
                'client_id'     => $credentials['client_id'] ?? '',
                'client_secret' => $credentials['client_secret'] ?? '',
                'redirect_uri'  => admin_url( 'admin.php?page=reactions-indieweb-apis&oauth_callback=trakt' ),
                'grant_type'    => 'authorization_code',
            ) ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            return new \WP_Error( 'no_token', __( 'No access token received.', 'reactions-indieweb' ) );
        }

        // Save tokens.
        $all_credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $all_credentials['trakt']['access_token']  = $body['access_token'];
        $all_credentials['trakt']['refresh_token'] = $body['refresh_token'] ?? '';
        $all_credentials['trakt']['token_expires'] = time() + ( $body['expires_in'] ?? 7776000 );
        update_option( 'reactions_indieweb_api_credentials', $all_credentials );

        return array(
            'success'      => true,
            'access_token' => $body['access_token'],
            'expires_in'   => $body['expires_in'] ?? 7776000,
        );
    }

    /**
     * Exchange Simkl authorization code.
     *
     * @param string               $code        Authorization code.
     * @param array<string, mixed> $credentials API credentials.
     * @return array<string, mixed>|\WP_Error Token data or error.
     */
    private function exchange_simkl_code( string $code, array $credentials ) {
        $response = wp_remote_post( 'https://api.simkl.com/oauth/token', array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( array(
                'code'         => $code,
                'client_id'    => $credentials['client_id'] ?? '',
                'redirect_uri' => admin_url( 'admin.php?page=reactions-indieweb-apis&oauth_callback=simkl' ),
                'grant_type'   => 'authorization_code',
            ) ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            return new \WP_Error( 'no_token', __( 'No access token received.', 'reactions-indieweb' ) );
        }

        // Save token.
        $all_credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $all_credentials['simkl']['access_token'] = $body['access_token'];
        update_option( 'reactions_indieweb_api_credentials', $all_credentials );

        return array(
            'success'      => true,
            'access_token' => $body['access_token'],
        );
    }

    /**
     * Get OAuth authorization URL.
     *
     * @param string $api API identifier.
     * @return string|null Authorization URL or null.
     */
    public function get_oauth_url( string $api ): ?string {
        $credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $api_creds   = $credentials[ $api ] ?? array();

        $redirect_uri = admin_url( 'admin.php?page=reactions-indieweb-apis&oauth_callback=' . $api );

        switch ( $api ) {
            case 'trakt':
                if ( empty( $api_creds['client_id'] ) ) {
                    return null;
                }
                return add_query_arg( array(
                    'response_type' => 'code',
                    'client_id'     => $api_creds['client_id'],
                    'redirect_uri'  => $redirect_uri,
                ), 'https://trakt.tv/oauth/authorize' );

            case 'simkl':
                if ( empty( $api_creds['client_id'] ) ) {
                    return null;
                }
                return add_query_arg( array(
                    'response_type' => 'code',
                    'client_id'     => $api_creds['client_id'],
                    'redirect_uri'  => $redirect_uri,
                ), 'https://simkl.com/oauth/authorize' );

            default:
                return null;
        }
    }
}

<?php
/**
 * API Settings Page
 *
 * Manages API credentials and connection settings.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Admin;

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
		$this->admin       = $admin;
		$this->api_configs = $this->get_api_configs();
	}

	/**
	 * Initialize API settings.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_postkind_indieweb_oauth_callback', [ $this, 'handle_oauth_callback' ] );
		add_action( 'wp_ajax_postkind_indieweb_get_oauth_url', [ $this, 'ajax_get_oauth_url' ] );

		// OAuth callbacks via admin-post.php (cleaner URLs for OAuth redirect URIs).
		add_action( 'admin_post_postkind_trakt_oauth', [ $this, 'handle_trakt_oauth_callback' ] );
		add_action( 'admin_post_postkind_simkl_oauth', [ $this, 'handle_simkl_oauth_callback' ] );
		add_action( 'admin_post_postkind_foursquare_oauth', [ $this, 'handle_foursquare_oauth_callback' ] );
		add_action( 'admin_post_postkind_lastfm_oauth', [ $this, 'handle_lastfm_oauth_callback' ] );
		// Note: Untappd OAuth removed - API requires commercial agreement.
	}

	/**
	 * Get API configurations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_api_configs(): array {
		return [
			// Note: MusicBrainz and ListenBrainz removed - complicated setup and credential saving issues.
			'lastfm'       => [
				'name'        => 'Last.fm',
				'description' => __( 'Scrobble service and music database. Requires API account and user authorization for scrobbling.', 'post-kinds-for-indieweb' ),
				'category'    => 'music',
				'docs_url'    => 'https://www.last.fm/api',
				'signup_url'  => 'https://www.last.fm/api/account/create',
				'auth_type'   => 'lastfm_oauth',
				'fields'      => [
					'api_key'    => [
						'label'    => __( 'API Key', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => true,
					],
					'api_secret' => [
						'label'    => __( 'Shared Secret', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => true,
					],
					'username'   => [
						'label'    => __( 'Username', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => false,
						'help'     => __( 'For importing scrobbles. Will be set automatically after authorization.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'tmdb'         => [
				'name'        => 'TMDB',
				'description' => __( 'The Movie Database. Comprehensive movie and TV metadata.', 'post-kinds-for-indieweb' ),
				'category'    => 'video',
				'docs_url'    => 'https://developer.themoviedb.org/',
				'signup_url'  => 'https://www.themoviedb.org/settings/api',
				'auth_type'   => 'bearer',
				'fields'      => [
					'access_token' => [
						'label'    => __( 'API Read Access Token', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => true,
						'help'     => __( 'Use the "API Read Access Token" (v4 auth), not the API Key.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'trakt'        => [
				'name'        => 'Trakt',
				'description' => __( 'Watch history tracking. Requires OAuth authentication.', 'post-kinds-for-indieweb' ),
				'category'    => 'video',
				'docs_url'    => 'https://trakt.docs.apiary.io/',
				'signup_url'  => 'https://trakt.tv/oauth/applications',
				'auth_type'   => 'oauth',
				'oauth_url'   => 'https://trakt.tv/oauth/authorize',
				'fields'      => [
					'client_id'     => [
						'label'    => __( 'Client ID', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => true,
					],
					'client_secret' => [
						'label'    => __( 'Client Secret', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => true,
					],
					'username'      => [
						'label'    => __( 'Trakt Username', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => false,
					],
				],
			],
			'simkl'        => [
				'name'        => 'Simkl',
				'description' => __( 'Watch tracking for movies, TV, and anime.', 'post-kinds-for-indieweb' ),
				'category'    => 'video',
				'docs_url'    => 'https://simkl.docs.apiary.io/',
				'signup_url'  => 'https://simkl.com/settings/developer/',
				'auth_type'   => 'oauth',
				'fields'      => [
					'client_id' => [
						'label'    => __( 'Client ID', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => true,
					],
				],
			],
			'tvmaze'       => [
				'name'        => 'TVmaze',
				'description' => __( 'TV show database. Works without API key, but premium key gives higher rate limits.', 'post-kinds-for-indieweb' ),
				'category'    => 'video',
				'docs_url'    => 'https://www.tvmaze.com/api',
				'signup_url'  => 'https://www.tvmaze.com/api#premium',
				'auth_type'   => 'api_key',
				'fields'      => [
					'api_key' => [
						'label'    => __( 'API Key (Optional)', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => false,
						'help'     => __( 'Premium API key for higher rate limits. Free tier works without a key.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'openlibrary'  => [
				'name'        => 'Open Library',
				'description' => __( 'Book metadata from Internet Archive. Free, no API key required.', 'post-kinds-for-indieweb' ),
				'category'    => 'books',
				'docs_url'    => 'https://openlibrary.org/developers/api',
				'auth_type'   => 'none',
				'fields'      => [],
			],
			'hardcover'    => [
				'name'        => 'Hardcover',
				'description' => __( 'Book tracking service. Get your token from your settings.', 'post-kinds-for-indieweb' ),
				'category'    => 'books',
				'docs_url'    => 'https://hardcover.app/docs',
				'signup_url'  => 'https://hardcover.app/account/api',
				'auth_type'   => 'bearer',
				'fields'      => [
					'api_token' => [
						'label'    => __( 'API Token', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => true,
					],
					'username'  => [
						'label'    => __( 'Username', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => false,
					],
				],
			],
			'google_books' => [
				'name'        => 'Google Books',
				'description' => __( 'Book metadata fallback. Optional API key for higher rate limits.', 'post-kinds-for-indieweb' ),
				'category'    => 'books',
				'docs_url'    => 'https://developers.google.com/books',
				'signup_url'  => 'https://console.cloud.google.com/apis/library/books.googleapis.com',
				'auth_type'   => 'api_key',
				'fields'      => [
					'api_key' => [
						'label'    => __( 'API Key', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => false,
						'help'     => __( 'Optional. Works without API key but with lower rate limits.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			// Note: Podcast Index removed - API signup requires app/business email, not personal accounts.
			'foursquare'   => [
				'name'        => 'Foursquare',
				'description' => __( 'Venue search and bidirectional checkin sync. API key for venue lookup, OAuth for syncing checkins.', 'post-kinds-for-indieweb' ),
				'category'    => 'location',
				'docs_url'    => 'https://location.foursquare.com/developer/',
				'signup_url'  => 'https://foursquare.com/developers/apps',
				'auth_type'   => 'oauth',
				'oauth_url'   => 'https://foursquare.com/oauth2/authorize',
				'fields'      => [
					'api_key'       => [
						'label'    => __( 'API Key (Places API)', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => false,
						'help'     => __( 'For venue search in the block editor. Get from Foursquare Developer Console.', 'post-kinds-for-indieweb' ),
					],
					'client_id'     => [
						'label'    => __( 'Client ID (OAuth)', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => false,
						'help'     => __( 'For syncing checkins. Create an app at foursquare.com/developers/apps.', 'post-kinds-for-indieweb' ),
					],
					'client_secret' => [
						'label'    => __( 'Client Secret (OAuth)', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => false,
					],
				],
			],
			'nominatim'    => [
				'name'        => 'Nominatim',
				'description' => __( 'OpenStreetMap geocoding. Free with usage policy.', 'post-kinds-for-indieweb' ),
				'category'    => 'location',
				'docs_url'    => 'https://nominatim.org/release-docs/develop/api/Overview/',
				'auth_type'   => 'email',
				'fields'      => [
					'email' => [
						'label'    => __( 'Contact Email', 'post-kinds-for-indieweb' ),
						'type'     => 'email',
						'required' => true,
						'help'     => __( 'Required by Nominatim usage policy.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'readwise'     => [
				'name'        => 'Readwise',
				'description' => __( 'Import highlights from books, articles, podcasts (Snipd), tweets, and more.', 'post-kinds-for-indieweb' ),
				'category'    => 'aggregators',
				'docs_url'    => 'https://readwise.io/api_deets',
				'signup_url'  => 'https://readwise.io/access_token',
				'auth_type'   => 'token',
				'fields'      => [
					'access_token' => [
						'label'    => __( 'Access Token', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => true,
						'help'     => __( 'Get your token from readwise.io/access_token', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'bgg'          => [
				'name'        => 'BoardGameGeek',
				'description' => __( 'Board game and video game database. As of January 2026, BGG requires API token registration for all API access.', 'post-kinds-for-indieweb' ),
				'category'    => 'games',
				'docs_url'    => 'https://boardgamegeek.com/wiki/page/BGG_XML_API2',
				'signup_url'  => 'https://boardgamegeek.com/applications',
				'auth_type'   => 'bearer',
				'notice'      => __( 'Note: BGG application approval may take a week or more. While waiting, you can still use the Play Card block by manually pasting BGG URLs - the game ID will be extracted automatically.', 'post-kinds-for-indieweb' ),
				'fields'      => [
					'api_token' => [
						'label'    => __( 'API Token', 'post-kinds-for-indieweb' ),
						'type'     => 'password',
						'required' => true,
						'help'     => __( 'Register your app, wait for approval, then create a token from your Applications page.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'rawg'         => [
				'name'        => 'RAWG',
				'description' => __( 'Video game database with 500,000+ games. Free tier: 20,000 requests/month.', 'post-kinds-for-indieweb' ),
				'category'    => 'games',
				'docs_url'    => 'https://rawg.io/apidocs',
				'signup_url'  => 'https://rawg.io/apidocs',
				'auth_type'   => 'api_key',
				'fields'      => [
					'api_key' => [
						'label'    => __( 'API Key', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => true,
						'help'     => __( 'Get your free API key from rawg.io/apidocs', 'post-kinds-for-indieweb' ),
					],
				],
			],
			// Note: Untappd API requires a commercial agreement and is not available for personal use.
			// The sync class code remains in place in case API access becomes available in the future.
		];
	}

	/**
	 * Register settings.
	 *
	 * Settings are registered in the main Admin class to avoid duplication.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Settings are registered in Admin::register_settings().
		// This method is kept for potential future API-specific settings sections.
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

		// Check for OAuth success/error transients.
		$oauth_error   = get_transient( 'post_kinds_oauth_error' );
		$oauth_success = get_transient( 'post_kinds_oauth_success' );

		if ( $oauth_error ) {
			delete_transient( 'post_kinds_oauth_error' );
		}
		if ( $oauth_success ) {
			delete_transient( 'post_kinds_oauth_success' );
		}

		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$categories  = [
			'music'       => __( 'Music', 'post-kinds-for-indieweb' ),
			'video'       => __( 'Movies & TV', 'post-kinds-for-indieweb' ),
			'books'       => __( 'Books', 'post-kinds-for-indieweb' ),
			'games'       => __( 'Games', 'post-kinds-for-indieweb' ),
			'location'    => __( 'Location', 'post-kinds-for-indieweb' ),
			'aggregators' => __( 'Aggregators', 'post-kinds-for-indieweb' ),
		];

		?>
		<div class="wrap post-kinds-indieweb-api-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( $oauth_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %s: Error message */
							esc_html__( 'OAuth authentication failed: %s', 'post-kinds-for-indieweb' ),
							esc_html( $oauth_error )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $oauth_success ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Successfully connected! You can now import your watch history.', 'post-kinds-for-indieweb' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Configure API connections for fetching media metadata and importing history.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'post_kinds_indieweb_apis' ); ?>

				<?php foreach ( $categories as $category_slug => $category_name ) : ?>
					<h2><?php echo esc_html( $category_name ); ?></h2>

					<div class="post-kinds-api-cards">
						<?php foreach ( $this->api_configs as $api_id => $config ) : ?>
							<?php if ( $config['category'] === $category_slug ) : ?>
								<?php $this->render_api_card( $api_id, $config, $credentials[ $api_id ] ?? [] ); ?>
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
		<div class="post-kinds-api-card <?php echo esc_attr( $status_class ); ?>" data-api="<?php echo esc_attr( $api_id ); ?>">
			<div class="api-card-header">
				<div class="api-card-title">
					<h3><?php echo esc_html( $config['name'] ); ?></h3>
					<span class="api-status-badge <?php echo esc_attr( $status_class ); ?>">
						<?php
						if ( ! $is_enabled ) {
							esc_html_e( 'Disabled', 'post-kinds-for-indieweb' );
						} elseif ( $is_connected ) {
							esc_html_e( 'Connected', 'post-kinds-for-indieweb' );
						} else {
							esc_html_e( 'Not Connected', 'post-kinds-for-indieweb' );
						}
						?>
					</span>
				</div>
				<label class="api-toggle">
					<input type="checkbox"
							name="post_kinds_indieweb_api_credentials[<?php echo esc_attr( $api_id ); ?>][enabled]"
							value="1"
							<?php checked( $is_enabled ); ?>
							class="api-enable-toggle">
					<span class="toggle-slider"></span>
				</label>
			</div>

			<p class="api-description"><?php echo esc_html( $config['description'] ); ?></p>

			<?php if ( ! empty( $config['notice'] ) ) : ?>
				<div class="api-notice" style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 8px 12px; margin: 8px 0; font-size: 13px;">
					<?php echo esc_html( $config['notice'] ); ?>
				</div>
			<?php endif; ?>

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
						<?php esc_html_e( 'No configuration needed.', 'post-kinds-for-indieweb' ); ?>
					</p>
				<?php endif; ?>

				<?php if ( 'oauth' === $config['auth_type'] ) : ?>
					<?php $this->render_oauth_section( $api_id, $config, $credentials ); ?>
				<?php endif; ?>

				<?php if ( 'lastfm_oauth' === $config['auth_type'] ) : ?>
					<?php $this->render_lastfm_auth_section( $api_id, $credentials ); ?>
				<?php endif; ?>
			</div>

			<div class="api-card-footer">
				<div class="api-actions">
					<?php if ( $is_enabled && ! empty( $config['fields'] ) ) : ?>
						<button type="button" class="button api-test-button" data-api="<?php echo esc_attr( $api_id ); ?>">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Test Connection', 'post-kinds-for-indieweb' ); ?>
						</button>
					<?php endif; ?>
				</div>
				<div class="api-links">
					<?php if ( ! empty( $config['docs_url'] ) ) : ?>
						<a href="<?php echo esc_url( $config['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Documentation', 'post-kinds-for-indieweb' ); ?>
							<span class="dashicons dashicons-external"></span>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $config['signup_url'] ) ) : ?>
						<a href="<?php echo esc_url( $config['signup_url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Get API Key', 'post-kinds-for-indieweb' ); ?>
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
		$name     = "post_kinds_indieweb_api_credentials[{$api_id}][{$field_id}]";
		$id       = "api_{$api_id}_{$field_id}";
		$required = ! empty( $field['required'] ) ? 'required' : '';

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
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
								autocomplete="new-password">
						<button type="button" class="button toggle-password" aria-label="<?php esc_attr_e( 'Toggle password visibility', 'post-kinds-for-indieweb' ); ?>">
							<span class="dashicons dashicons-visibility"></span>
						</button>
					</div>
				<?php elseif ( 'email' === $field['type'] ) : ?>
					<input type="email"
							name="<?php echo esc_attr( $name ); ?>"
							id="<?php echo esc_attr( $id ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="regular-text"
							placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>">
				<?php else : ?>
					<input type="text"
							name="<?php echo esc_attr( $name ); ?>"
							id="<?php echo esc_attr( $id ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
							class="regular-text"
							placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>">
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
			<h4><?php esc_html_e( 'OAuth Connection', 'post-kinds-for-indieweb' ); ?></h4>

			<?php if ( $has_tokens ) : ?>
				<div class="oauth-connected">
					<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
					<?php esc_html_e( 'Connected', 'post-kinds-for-indieweb' ); ?>

					<?php if ( ! empty( $token_info['username'] ) ) : ?>
						<span class="oauth-username">
							<?php
							printf(
								/* translators: %s: Username */
								esc_html__( 'as %s', 'post-kinds-for-indieweb' ),
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
								esc_html__( '(expires %s)', 'post-kinds-for-indieweb' ),
								esc_html( human_time_diff( time(), $token_info['expires'] ) )
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<p>
					<button type="button" class="button oauth-disconnect" data-api="<?php echo esc_attr( $api_id ); ?>">
						<?php esc_html_e( 'Disconnect', 'post-kinds-for-indieweb' ); ?>
					</button>
					<button type="button" class="button oauth-refresh" data-api="<?php echo esc_attr( $api_id ); ?>">
						<?php esc_html_e( 'Refresh Token', 'post-kinds-for-indieweb' ); ?>
					</button>
				</p>
			<?php else : ?>
				<div class="oauth-disconnected">
					<?php
					$redirect_uri = $this->get_oauth_redirect_uri( $api_id );
					?>
					<p style="margin-bottom: 8px;">
						<strong><?php esc_html_e( 'Redirect URI:', 'post-kinds-for-indieweb' ); ?></strong><br>
						<code style="user-select: all; cursor: text; padding: 4px 8px; display: inline-block; margin-top: 4px; word-break: break-all;"><?php echo esc_url( $redirect_uri ); ?></code>
					</p>
					<p class="description" style="margin-bottom: 12px;">
						<?php
						printf(
							/* translators: %s: Service name */
							esc_html__( 'Copy this URL to your %s app settings as the Redirect URI.', 'post-kinds-for-indieweb' ),
							esc_html( $config['name'] )
						);
						?>
					</p>
					<button type="button" class="button button-primary oauth-connect" data-api="<?php echo esc_attr( $api_id ); ?>">
						<?php
						printf(
							/* translators: %s: Service name */
							esc_html__( 'Connect to %s', 'post-kinds-for-indieweb' ),
							esc_html( $config['name'] )
						);
						?>
					</button>
				</div>
			<?php endif; ?>

			<!-- Hidden fields for OAuth tokens -->
			<input type="hidden"
					name="post_kinds_indieweb_api_credentials[<?php echo esc_attr( $api_id ); ?>][access_token]"
					value="<?php echo esc_attr( $credentials['access_token'] ?? '' ); ?>"
					class="oauth-access-token">
			<input type="hidden"
					name="post_kinds_indieweb_api_credentials[<?php echo esc_attr( $api_id ); ?>][refresh_token]"
					value="<?php echo esc_attr( $credentials['refresh_token'] ?? '' ); ?>"
					class="oauth-refresh-token">
		</div>
		<?php
	}

	/**
	 * Render Last.fm authentication section.
	 *
	 * @param string               $api_id      API identifier.
	 * @param array<string, mixed> $credentials Saved credentials.
	 * @return void
	 */
	private function render_lastfm_auth_section( string $api_id, array $credentials ): void {
		$has_session  = ! empty( $credentials['session_key'] );
		$username     = $credentials['username'] ?? '';
		$has_api_key  = ! empty( $credentials['api_key'] ) && ! empty( $credentials['api_secret'] );
		$callback_url = admin_url( 'admin-post.php?action=post_kinds_lastfm_oauth' );

		?>
		<div class="oauth-section lastfm-auth-section">
			<h4><?php esc_html_e( 'Scrobbling Authorization', 'post-kinds-for-indieweb' ); ?></h4>

			<?php if ( $has_session ) : ?>
				<div class="oauth-connected">
					<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
					<?php esc_html_e( 'Authorized', 'post-kinds-for-indieweb' ); ?>

					<?php if ( $username ) : ?>
						<span class="oauth-username">
							<?php
							printf(
								/* translators: %s: Username */
								esc_html__( 'as %s', 'post-kinds-for-indieweb' ),
								esc_html( $username )
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<p class="description">
					<?php esc_html_e( 'You can scrobble listen posts to Last.fm.', 'post-kinds-for-indieweb' ); ?>
				</p>

				<p>
					<button type="button" class="button lastfm-disconnect" data-api="<?php echo esc_attr( $api_id ); ?>">
						<?php esc_html_e( 'Disconnect', 'post-kinds-for-indieweb' ); ?>
					</button>
				</p>
			<?php else : ?>
				<div class="oauth-disconnected">
					<p class="description">
						<?php esc_html_e( 'To scrobble listen posts to Last.fm, you need to authorize this application.', 'post-kinds-for-indieweb' ); ?>
					</p>

					<?php if ( $has_api_key ) : ?>
						<?php
						$api      = new \PostKindsForIndieWeb\APIs\Lastfm();
						$auth_url = $api->get_auth_url( $callback_url );
						?>
						<a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Connect to Last.fm', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<p class="notice notice-warning" style="padding: 8px;">
							<?php esc_html_e( 'Please save your API Key and Shared Secret first, then you can connect.', 'post-kinds-for-indieweb' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Hidden field for session key -->
			<input type="hidden"
					name="post_kinds_indieweb_api_credentials[<?php echo esc_attr( $api_id ); ?>][session_key]"
					value="<?php echo esc_attr( $credentials['session_key'] ?? '' ); ?>"
					class="lastfm-session-key">
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

		// Check Last.fm session key.
		if ( 'lastfm_oauth' === $config['auth_type'] ) {
			// For Last.fm, having session_key means fully authenticated for scrobbling.
			// Without it, just having API key is enough for lookups but not scrobbling.
			return ! empty( $credentials['api_key'] );
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
		$info = [];

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
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$api  = isset( $_POST['api'] ) ? sanitize_text_field( wp_unslash( $_POST['api'] ) ) : '';
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( empty( $api ) || empty( $code ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing API or authorization code.', 'post-kinds-for-indieweb' ) ] );
		}

		$result = $this->exchange_oauth_code( $api, $code );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
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
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$api_creds   = $credentials[ $api ] ?? [];

		switch ( $api ) {
			case 'trakt':
				return $this->exchange_trakt_code( $code, $api_creds );
			case 'simkl':
				return $this->exchange_simkl_code( $code, $api_creds );
			case 'foursquare':
				return $this->exchange_foursquare_code( $code, $api_creds );
			case 'untappd':
				return $this->exchange_untappd_code( $code, $api_creds );
			default:
				return new \WP_Error( 'unsupported', __( 'OAuth not supported for this API.', 'post-kinds-for-indieweb' ) );
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
		$response = wp_remote_post(
			'https://api.trakt.tv/oauth/token',
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'code'          => $code,
						'client_id'     => $credentials['client_id'] ?? '',
						'client_secret' => $credentials['client_secret'] ?? '',
						'redirect_uri'  => $this->get_oauth_redirect_uri( 'trakt' ),
						'grant_type'    => 'authorization_code',
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'no_token', __( 'No access token received.', 'post-kinds-for-indieweb' ) );
		}

		// Save tokens.
		$all_credentials                           = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$all_credentials['trakt']['access_token']  = $body['access_token'];
		$all_credentials['trakt']['refresh_token'] = $body['refresh_token'] ?? '';
		$all_credentials['trakt']['token_expires'] = time() + ( $body['expires_in'] ?? 7776000 );
		update_option( 'post_kinds_indieweb_api_credentials', $all_credentials );

		return [
			'success'      => true,
			'access_token' => $body['access_token'],
			'expires_in'   => $body['expires_in'] ?? 7776000,
		];
	}

	/**
	 * Exchange Simkl authorization code.
	 *
	 * @param string               $code        Authorization code.
	 * @param array<string, mixed> $credentials API credentials.
	 * @return array<string, mixed>|\WP_Error Token data or error.
	 */
	private function exchange_simkl_code( string $code, array $credentials ) {
		$response = wp_remote_post(
			'https://api.simkl.com/oauth/token',
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'code'         => $code,
						'client_id'    => $credentials['client_id'] ?? '',
						'redirect_uri' => $this->get_oauth_redirect_uri( 'simkl' ),
						'grant_type'   => 'authorization_code',
					]
				),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'no_token', __( 'No access token received.', 'post-kinds-for-indieweb' ) );
		}

		// Save token.
		$all_credentials                          = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$all_credentials['simkl']['access_token'] = $body['access_token'];
		update_option( 'post_kinds_indieweb_api_credentials', $all_credentials );

		return [
			'success'      => true,
			'access_token' => $body['access_token'],
		];
	}

	/**
	 * Exchange Foursquare authorization code.
	 *
	 * @param string               $code        Authorization code.
	 * @param array<string, mixed> $credentials API credentials.
	 * @return array<string, mixed>|\WP_Error Token data or error.
	 */
	private function exchange_foursquare_code( string $code, array $credentials ) {
		$response = wp_remote_post(
			'https://foursquare.com/oauth2/access_token',
			[
				'body'    => [
					'client_id'     => $credentials['client_id'] ?? '',
					'client_secret' => $credentials['client_secret'] ?? '',
					'grant_type'    => 'authorization_code',
					'redirect_uri'  => $this->get_oauth_redirect_uri( 'foursquare' ),
					'code'          => $code,
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$error_msg = $body['error'] ?? __( 'No access token received.', 'post-kinds-for-indieweb' );
			return new \WP_Error( 'no_token', $error_msg );
		}

		// Fetch user info to get username.
		$user_response = wp_remote_get(
			'https://api.foursquare.com/v2/users/self',
			[
				'timeout' => 15,
				'headers' => [
					'Accept' => 'application/json',
				],
				'body'    => [
					'oauth_token' => $body['access_token'],
					'v'           => gmdate( 'Ymd' ),
				],
			]
		);

		$username = '';
		if ( ! is_wp_error( $user_response ) ) {
			$user_body = json_decode( wp_remote_retrieve_body( $user_response ), true );
			if ( ! empty( $user_body['response']['user']['firstName'] ) ) {
				$username = $user_body['response']['user']['firstName'];
				if ( ! empty( $user_body['response']['user']['lastName'] ) ) {
					$username .= ' ' . $user_body['response']['user']['lastName'];
				}
			}
		}

		// Save token and username.
		$all_credentials                               = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$all_credentials['foursquare']['access_token'] = $body['access_token'];
		$all_credentials['foursquare']['username']     = $username;
		update_option( 'post_kinds_indieweb_api_credentials', $all_credentials );

		return [
			'success'      => true,
			'access_token' => $body['access_token'],
			'username'     => $username,
		];
	}

	/**
	 * Exchange Untappd authorization code.
	 *
	 * @param string               $code        Authorization code.
	 * @param array<string, mixed> $credentials API credentials.
	 * @return array<string, mixed>|\WP_Error Token data or error.
	 */
	private function exchange_untappd_code( string $code, array $credentials ) {
		// Untappd uses GET request for token exchange.
		$response = wp_remote_get(
			add_query_arg(
				[
					'client_id'     => $credentials['client_id'] ?? '',
					'client_secret' => $credentials['client_secret'] ?? '',
					'response_type' => 'code',
					'redirect_url'  => $this->get_oauth_redirect_uri( 'untappd' ),
					'code'          => $code,
				],
				'https://untappd.com/oauth/authorize'
			),
			[
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['response']['access_token'] ) ) {
			$error_msg = $body['meta']['error_detail'] ?? __( 'No access token received.', 'post-kinds-for-indieweb' );
			return new \WP_Error( 'no_token', $error_msg );
		}

		$access_token = $body['response']['access_token'];

		// Fetch user info.
		$user_response = wp_remote_get(
			add_query_arg(
				[
					'access_token' => $access_token,
				],
				'https://api.untappd.com/v4/user/info'
			),
			[
				'timeout' => 15,
				'headers' => [
					'User-Agent' => 'Post Kinds for IndieWeb WordPress Plugin',
				],
			]
		);

		$username = '';
		if ( ! is_wp_error( $user_response ) ) {
			$user_body = json_decode( wp_remote_retrieve_body( $user_response ), true );
			if ( ! empty( $user_body['response']['user']['user_name'] ) ) {
				$username = $user_body['response']['user']['user_name'];
			}
		}

		// Save token and username.
		$all_credentials                            = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$all_credentials['untappd']['access_token'] = $access_token;
		$all_credentials['untappd']['username']     = $username;
		update_option( 'post_kinds_indieweb_api_credentials', $all_credentials );

		return [
			'success'      => true,
			'access_token' => $access_token,
			'username'     => $username,
		];
	}

	/**
	 * Get OAuth redirect URI for an API.
	 *
	 * Uses admin-post.php for cleaner URLs without query parameter encoding issues.
	 *
	 * @param string $api API identifier.
	 * @return string Redirect URI.
	 */
	public function get_oauth_redirect_uri( string $api ): string {
		return admin_url( 'admin-post.php?action=post_kinds_' . $api . '_oauth' );
	}

	/**
	 * Get OAuth authorization URL.
	 *
	 * @param string $api API identifier.
	 * @return string|null Authorization URL or null.
	 */
	public function get_oauth_url( string $api ): ?string {
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$api_creds   = $credentials[ $api ] ?? [];

		$redirect_uri = $this->get_oauth_redirect_uri( $api );

		switch ( $api ) {
			case 'trakt':
				if ( empty( $api_creds['client_id'] ) ) {
					return null;
				}
				return add_query_arg(
					[
						'response_type' => 'code',
						'client_id'     => $api_creds['client_id'],
						'redirect_uri'  => $redirect_uri,
					],
					'https://trakt.tv/oauth/authorize'
				);

			case 'simkl':
				if ( empty( $api_creds['client_id'] ) ) {
					return null;
				}
				return add_query_arg(
					[
						'response_type' => 'code',
						'client_id'     => $api_creds['client_id'],
						'redirect_uri'  => $redirect_uri,
					],
					'https://simkl.com/oauth/authorize'
				);

			case 'foursquare':
				if ( empty( $api_creds['client_id'] ) ) {
					return null;
				}
				return add_query_arg(
					[
						'response_type' => 'code',
						'client_id'     => $api_creds['client_id'],
						'redirect_uri'  => $redirect_uri,
					],
					'https://foursquare.com/oauth2/authenticate'
				);

			case 'untappd':
				if ( empty( $api_creds['client_id'] ) ) {
					return null;
				}
				return add_query_arg(
					[
						'response_type' => 'code',
						'client_id'     => $api_creds['client_id'],
						'redirect_url'  => $redirect_uri, // Untappd uses redirect_url not redirect_uri.
					],
					'https://untappd.com/oauth/authenticate'
				);

			default:
				return null;
		}
	}

	/**
	 * Handle Trakt OAuth callback via admin-post.php.
	 *
	 * @return void
	 */
	public function handle_trakt_oauth_callback(): void {
		$this->process_oauth_callback( 'trakt' );
	}

	/**
	 * Handle Simkl OAuth callback via admin-post.php.
	 *
	 * @return void
	 */
	public function handle_simkl_oauth_callback(): void {
		$this->process_oauth_callback( 'simkl' );
	}

	/**
	 * Handle Foursquare OAuth callback via admin-post.php.
	 *
	 * @return void
	 */
	public function handle_foursquare_oauth_callback(): void {
		$this->process_oauth_callback( 'foursquare' );
	}

	/**
	 * Handle Last.fm OAuth callback via admin-post.php.
	 *
	 * Last.fm uses a token-based auth flow instead of standard OAuth.
	 *
	 * @return void
	 */
	public function handle_lastfm_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'post-kinds-for-indieweb' ) );
		}

		// Last.fm returns 'token' not 'code'.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['token'] ) ) {
			set_transient( 'post_kinds_oauth_error', __( 'No token received from Last.fm.', 'post-kinds-for-indieweb' ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ) );
			exit;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );

		// Get credentials.
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$lastfm      = $credentials['lastfm'] ?? [];

		if ( empty( $lastfm['api_key'] ) || empty( $lastfm['api_secret'] ) ) {
			set_transient( 'post_kinds_oauth_error', __( 'Last.fm API credentials not configured.', 'post-kinds-for-indieweb' ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ) );
			exit;
		}

		// Exchange token for session key.
		$api     = new \PostKindsForIndieWeb\APIs\Lastfm();
		$session = $api->get_session( $token );

		if ( ! $session || empty( $session['session_key'] ) ) {
			set_transient( 'post_kinds_oauth_error', __( 'Failed to get Last.fm session key.', 'post-kinds-for-indieweb' ), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ) );
			exit;
		}

		// Save session key and username.
		$credentials['lastfm']['session_key'] = $session['session_key'];
		if ( ! empty( $session['username'] ) ) {
			$credentials['lastfm']['username'] = $session['username'];
		}
		update_option( 'post_kinds_indieweb_api_credentials', $credentials );

		set_transient( 'post_kinds_oauth_success', 'lastfm', 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_success=1' ) );
		exit;
	}

	/**
	 * Handle Untappd OAuth callback via admin-post.php.
	 *
	 * @return void
	 */
	public function handle_untappd_oauth_callback(): void {
		$this->process_oauth_callback( 'untappd' );
	}

	/**
	 * Process OAuth callback for any API.
	 *
	 * @param string $api API identifier.
	 * @return void
	 */
	private function process_oauth_callback( string $api ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'post-kinds-for-indieweb' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['code'] ) ) {
			// Check for error from OAuth provider.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
			if ( $error ) {
				set_transient( 'post_kinds_oauth_error', $error, 60 );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ) );
			exit;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

		$result = $this->exchange_oauth_code( $api, $code );

		if ( is_wp_error( $result ) ) {
			set_transient( 'post_kinds_oauth_error', $result->get_error_message(), 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_error=1' ) );
		} else {
			set_transient( 'post_kinds_oauth_success', $api, 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=post-kinds-indieweb-apis&oauth_success=1' ) );
		}
		exit;
	}

	/**
	 * AJAX handler to get OAuth URL.
	 *
	 * Saves credentials first, then returns the authorization URL.
	 *
	 * @return void
	 */
	public function ajax_get_oauth_url(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$api           = isset( $_POST['api'] ) ? sanitize_text_field( wp_unslash( $_POST['api'] ) ) : '';
		$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';

		if ( empty( $api ) || empty( $client_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing API or Client ID.', 'post-kinds-for-indieweb' ) ] );
		}

		// Save credentials first so they're available for the callback.
		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );

		if ( ! isset( $credentials[ $api ] ) ) {
			$credentials[ $api ] = [];
		}

		$credentials[ $api ]['client_id']     = $client_id;
		$credentials[ $api ]['client_secret'] = $client_secret;
		$credentials[ $api ]['enabled']       = true;

		update_option( 'post_kinds_indieweb_api_credentials', $credentials );

		// Now get the OAuth URL.
		$url = $this->get_oauth_url( $api );

		if ( ! $url ) {
			wp_send_json_error( [ 'message' => __( 'Could not generate OAuth URL.', 'post-kinds-for-indieweb' ) ] );
		}

		wp_send_json_success( [ 'url' => $url ] );
	}
}

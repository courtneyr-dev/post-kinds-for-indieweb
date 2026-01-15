<?php
/**
 * Webhooks Page
 *
 * Admin page for managing incoming webhooks.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhooks page class.
 */
class Webhooks_Page {

	/**
	 * Admin instance.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Webhook configurations.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $webhook_configs;

	/**
	 * Constructor.
	 *
	 * @param Admin $admin Admin instance.
	 */
	public function __construct( Admin $admin ) {
		$this->admin           = $admin;
		$this->webhook_configs = $this->get_webhook_configs();
	}

	/**
	 * Initialize webhooks page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_ajax_postkind_indieweb_regenerate_webhook_secret', [ $this, 'ajax_regenerate_secret' ] );
		add_action( 'wp_ajax_postkind_indieweb_clear_pending_scrobbles', [ $this, 'ajax_clear_pending' ] );
		add_action( 'wp_ajax_postkind_indieweb_approve_scrobble', [ $this, 'ajax_approve_scrobble' ] );
		add_action( 'wp_ajax_postkind_indieweb_reject_scrobble', [ $this, 'ajax_reject_scrobble' ] );
	}

	/**
	 * Get webhook configurations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_webhook_configs(): array {
		return [
			'plex'         => [
				'name'        => 'Plex',
				'description' => __( 'Receive play notifications from Plex Media Server.', 'post-kinds-for-indieweb' ),
				'icon'        => 'dashicons-video-alt3',
				'post_kind'   => 'watch',
				'docs_url'    => 'https://support.plex.tv/articles/115002267687-webhooks/',
				'fields'      => [
					'min_watch_percent' => [
						'label'   => __( 'Minimum Watch Percentage', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'min'     => 0,
						'max'     => 100,
						'default' => 80,
						'help'    => __( 'Only create posts when watched at least this percentage.', 'post-kinds-for-indieweb' ),
					],
					'player_filter'     => [
						'label'       => __( 'Player Filter', 'post-kinds-for-indieweb' ),
						'type'        => 'text',
						'placeholder' => __( 'Leave empty to accept all players', 'post-kinds-for-indieweb' ),
						'help'        => __( 'Comma-separated list of player names to accept.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'jellyfin'     => [
				'name'        => 'Jellyfin',
				'description' => __( 'Receive play notifications from Jellyfin.', 'post-kinds-for-indieweb' ),
				'icon'        => 'dashicons-video-alt3',
				'post_kind'   => 'watch',
				'docs_url'    => 'https://jellyfin.org/docs/general/server/plugins/webhooks/',
				'fields'      => [
					'min_watch_percent' => [
						'label'   => __( 'Minimum Watch Percentage', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'min'     => 0,
						'max'     => 100,
						'default' => 80,
					],
					'user_filter'       => [
						'label'       => __( 'User Filter', 'post-kinds-for-indieweb' ),
						'type'        => 'text',
						'placeholder' => __( 'Leave empty to accept all users', 'post-kinds-for-indieweb' ),
						'help'        => __( 'Comma-separated list of usernames to accept.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'trakt'        => [
				'name'        => 'Trakt',
				'description' => __( 'Receive scrobble notifications from Trakt (requires VIP).', 'post-kinds-for-indieweb' ),
				'icon'        => 'dashicons-video-alt2',
				'post_kind'   => 'watch',
				'docs_url'    => 'https://trakt.docs.apiary.io/#reference/webhooks',
			],
			'listenbrainz' => [
				'name'        => 'ListenBrainz',
				'description' => __( 'Receive listen notifications from ListenBrainz.', 'post-kinds-for-indieweb' ),
				'icon'        => 'dashicons-format-audio',
				'post_kind'   => 'listen',
				'docs_url'    => 'https://listenbrainz.readthedocs.io/',
			],
			'generic'      => [
				'name'        => 'Generic Webhook',
				'description' => __( 'Accept custom webhook payloads in a standard format.', 'post-kinds-for-indieweb' ),
				'icon'        => 'dashicons-rest-api',
				'post_kind'   => 'any',
				'fields'      => [
					'auth_method' => [
						'label'   => __( 'Authentication', 'post-kinds-for-indieweb' ),
						'type'    => 'select',
						'options' => [
							'token' => __( 'Bearer Token', 'post-kinds-for-indieweb' ),
							'hmac'  => __( 'HMAC Signature', 'post-kinds-for-indieweb' ),
							'basic' => __( 'Basic Auth', 'post-kinds-for-indieweb' ),
							'none'  => __( 'None', 'post-kinds-for-indieweb' ),
						],
						'default' => 'token',
					],
				],
			],
		];
	}

	/**
	 * Render the webhooks page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( 'post_kinds_indieweb_webhook_settings', [] );
		$pending  = get_option( 'post_kinds_indieweb_pending_scrobbles', [] );

		?>
		<div class="wrap post-kinds-indieweb-webhooks">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Configure webhooks to automatically create posts when you watch, listen, or check in using external apps.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<?php if ( ! empty( $pending ) ) : ?>
				<div class="pending-scrobbles-section">
					<h2>
						<?php esc_html_e( 'Pending Scrobbles', 'post-kinds-for-indieweb' ); ?>
						<span class="count">(<?php echo count( $pending ); ?>)</span>
					</h2>
					<?php $this->render_pending_scrobbles( $pending ); ?>
				</div>
				<hr>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'post_kinds_indieweb_webhooks' ); ?>

				<div class="webhook-cards">
					<?php foreach ( $this->webhook_configs as $webhook_id => $config ) : ?>
						<?php $this->render_webhook_card( $webhook_id, $config, $settings[ $webhook_id ] ?? [] ); ?>
					<?php endforeach; ?>
				</div>

				<?php submit_button(); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Webhook Log', 'post-kinds-for-indieweb' ); ?></h2>
			<?php $this->render_webhook_log(); ?>
		</div>
		<?php
	}

	/**
	 * Render a webhook configuration card.
	 *
	 * @param string               $webhook_id Webhook identifier.
	 * @param array<string, mixed> $config     Webhook configuration.
	 * @param array<string, mixed> $settings   Saved settings.
	 * @return void
	 */
	private function render_webhook_card( string $webhook_id, array $config, array $settings ): void {
		$is_enabled  = ! empty( $settings['enabled'] );
		$webhook_url = $this->get_webhook_url( $webhook_id );
		$secret      = $settings['secret'] ?? '';

		?>
		<div class="webhook-card <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>" data-webhook="<?php echo esc_attr( $webhook_id ); ?>">
			<div class="webhook-header">
				<div class="webhook-title">
					<span class="dashicons <?php echo esc_attr( $config['icon'] ); ?>"></span>
					<h3><?php echo esc_html( $config['name'] ); ?></h3>
				</div>
				<label class="webhook-toggle">
					<input type="checkbox"
							name="post_kinds_indieweb_webhook_settings[<?php echo esc_attr( $webhook_id ); ?>][enabled]"
							value="1"
							<?php checked( $is_enabled ); ?>
							class="webhook-enable-toggle">
					<span class="toggle-slider"></span>
				</label>
			</div>

			<p class="webhook-description"><?php echo esc_html( $config['description'] ); ?></p>

			<div class="webhook-body" <?php echo $is_enabled ? '' : 'style="display: none;"'; ?>>
				<!-- Webhook URL -->
				<div class="webhook-url-section">
					<label><?php esc_html_e( 'Webhook URL', 'post-kinds-for-indieweb' ); ?></label>
					<div class="webhook-url-field">
						<input type="text" value="<?php echo esc_url( $webhook_url ); ?>" readonly class="webhook-url-input">
						<button type="button" class="button copy-webhook-url" data-url="<?php echo esc_url( $webhook_url ); ?>">
							<span class="dashicons dashicons-clipboard"></span>
							<?php esc_html_e( 'Copy', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
					<p class="description">
						<?php esc_html_e( 'Use this URL in your external service to send webhook notifications.', 'post-kinds-for-indieweb' ); ?>
					</p>
				</div>

				<!-- Secret Key -->
				<div class="webhook-secret-section">
					<label><?php esc_html_e( 'Secret Key', 'post-kinds-for-indieweb' ); ?></label>
					<div class="webhook-secret-field">
						<input type="password"
								name="post_kinds_indieweb_webhook_settings[<?php echo esc_attr( $webhook_id ); ?>][secret]"
								value="<?php echo esc_attr( $secret ); ?>"
								class="regular-text webhook-secret-input"
								autocomplete="off">
						<button type="button" class="button toggle-secret-visibility">
							<span class="dashicons dashicons-visibility"></span>
						</button>
						<button type="button" class="button regenerate-secret" data-webhook="<?php echo esc_attr( $webhook_id ); ?>">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Generate', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
					<p class="description">
						<?php esc_html_e( 'Secret key for authenticating webhook requests.', 'post-kinds-for-indieweb' ); ?>
					</p>
				</div>

				<!-- Common Settings -->
				<table class="form-table webhook-settings">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-create Posts', 'post-kinds-for-indieweb' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
										name="post_kinds_indieweb_webhook_settings[<?php echo esc_attr( $webhook_id ); ?>][auto_post]"
										value="1"
										<?php checked( ! empty( $settings['auto_post'] ) ); ?>>
								<?php esc_html_e( 'Automatically create posts from webhook data', 'post-kinds-for-indieweb' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'If disabled, incoming scrobbles will be queued for manual review.', 'post-kinds-for-indieweb' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post Status', 'post-kinds-for-indieweb' ); ?></th>
						<td>
							<select name="post_kinds_indieweb_webhook_settings[<?php echo esc_attr( $webhook_id ); ?>][post_status]">
								<?php
								$statuses       = [
									'publish' => __( 'Published', 'post-kinds-for-indieweb' ),
									'draft'   => __( 'Draft', 'post-kinds-for-indieweb' ),
									'pending' => __( 'Pending Review', 'post-kinds-for-indieweb' ),
									'private' => __( 'Private', 'post-kinds-for-indieweb' ),
								];
								$current_status = $settings['post_status'] ?? 'draft';
								foreach ( $statuses as $value => $label ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $value ),
										selected( $current_status, $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</td>
					</tr>

					<!-- Custom fields per webhook type -->
					<?php if ( ! empty( $config['fields'] ) ) : ?>
						<?php foreach ( $config['fields'] as $field_id => $field ) : ?>
							<tr>
								<th scope="row">
									<label for="webhook_<?php echo esc_attr( $webhook_id ); ?>_<?php echo esc_attr( $field_id ); ?>">
										<?php echo esc_html( $field['label'] ); ?>
									</label>
								</th>
								<td>
									<?php
									$field_name  = "post_kinds_indieweb_webhook_settings[{$webhook_id}][{$field_id}]";
									$field_value = $settings[ $field_id ] ?? ( $field['default'] ?? '' );

									switch ( $field['type'] ) {
										case 'number':
											printf(
												'<input type="number" id="webhook_%s_%s" name="%s" value="%s" min="%s" max="%s" class="small-text">',
												esc_attr( $webhook_id ),
												esc_attr( $field_id ),
												esc_attr( $field_name ),
												esc_attr( $field_value ),
												esc_attr( $field['min'] ?? 0 ),
												esc_attr( $field['max'] ?? 100 )
											);
											break;

										case 'text':
											printf(
												'<input type="text" id="webhook_%s_%s" name="%s" value="%s" class="regular-text" placeholder="%s">',
												esc_attr( $webhook_id ),
												esc_attr( $field_id ),
												esc_attr( $field_name ),
												esc_attr( $field_value ),
												esc_attr( $field['placeholder'] ?? '' )
											);
											break;

										case 'select':
											printf( '<select id="webhook_%s_%s" name="%s">', esc_attr( $webhook_id ), esc_attr( $field_id ), esc_attr( $field_name ) );
											foreach ( $field['options'] as $opt_value => $opt_label ) {
												printf(
													'<option value="%s" %s>%s</option>',
													esc_attr( $opt_value ),
													selected( $field_value, $opt_value, false ),
													esc_html( $opt_label )
												);
											}
											echo '</select>';
											break;
									}

									if ( ! empty( $field['help'] ) ) {
										printf( '<p class="description">%s</p>', esc_html( $field['help'] ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</table>
			</div>

			<div class="webhook-footer">
				<?php if ( ! empty( $config['docs_url'] ) ) : ?>
					<a href="<?php echo esc_url( $config['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Documentation', 'post-kinds-for-indieweb' ); ?>
						<span class="dashicons dashicons-external"></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get webhook URL.
	 *
	 * @param string $webhook_id Webhook identifier.
	 * @return string Webhook URL.
	 */
	private function get_webhook_url( string $webhook_id ): string {
		return rest_url( "post-kinds-indieweb/v1/webhooks/{$webhook_id}" );
	}

	/**
	 * Render pending scrobbles.
	 *
	 * @param array<int, array<string, mixed>> $pending Pending scrobbles.
	 * @return void
	 */
	private function render_pending_scrobbles( array $pending ): void {
		?>
		<div class="pending-scrobbles">
			<div class="pending-actions">
				<button type="button" class="button approve-all-scrobbles">
					<?php esc_html_e( 'Approve All', 'post-kinds-for-indieweb' ); ?>
				</button>
				<button type="button" class="button reject-all-scrobbles">
					<?php esc_html_e( 'Reject All', 'post-kinds-for-indieweb' ); ?>
				</button>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th class="column-cb"><input type="checkbox" class="select-all-scrobbles"></th>
						<th><?php esc_html_e( 'Source', 'post-kinds-for-indieweb' ); ?></th>
						<th><?php esc_html_e( 'Type', 'post-kinds-for-indieweb' ); ?></th>
						<th><?php esc_html_e( 'Content', 'post-kinds-for-indieweb' ); ?></th>
						<th><?php esc_html_e( 'Received', 'post-kinds-for-indieweb' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'post-kinds-for-indieweb' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pending as $index => $scrobble ) : ?>
						<tr data-scrobble-index="<?php echo esc_attr( $index ); ?>">
							<td class="column-cb">
								<input type="checkbox" class="select-scrobble" value="<?php echo esc_attr( $index ); ?>">
							</td>
							<td><?php echo esc_html( $this->webhook_configs[ $scrobble['source'] ]['name'] ?? $scrobble['source'] ); ?></td>
							<td><?php echo esc_html( ucfirst( $scrobble['type'] ?? 'unknown' ) ); ?></td>
							<td>
								<strong><?php echo esc_html( $scrobble['title'] ?? 'Unknown' ); ?></strong>
								<?php if ( ! empty( $scrobble['artist'] ) ) : ?>
									<br><span class="artist"><?php echo esc_html( $scrobble['artist'] ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								if ( ! empty( $scrobble['received_at'] ) ) {
									echo esc_html( human_time_diff( $scrobble['received_at'], time() ) . ' ' . __( 'ago', 'post-kinds-for-indieweb' ) );
								}
								?>
							</td>
							<td>
								<button type="button" class="button button-small approve-scrobble" data-index="<?php echo esc_attr( $index ); ?>">
									<span class="dashicons dashicons-yes"></span>
								</button>
								<button type="button" class="button button-small reject-scrobble" data-index="<?php echo esc_attr( $index ); ?>">
									<span class="dashicons dashicons-no"></span>
								</button>
								<button type="button" class="button button-small preview-scrobble" data-index="<?php echo esc_attr( $index ); ?>">
									<span class="dashicons dashicons-visibility"></span>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render webhook log.
	 *
	 * @return void
	 */
	private function render_webhook_log(): void {
		$log = get_option( 'post_kinds_indieweb_webhook_log', [] );

		if ( empty( $log ) ) {
			echo '<p class="description">' . esc_html__( 'No webhook requests received yet.', 'post-kinds-for-indieweb' ) . '</p>';
			return;
		}

		// Sort by date descending.
		usort(
			$log,
			function ( $a, $b ) {
				return ( $b['timestamp'] ?? 0 ) - ( $a['timestamp'] ?? 0 );
			}
		);

		// Limit to last 50.
		$log = array_slice( $log, 0, 50 );

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Source', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Status', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Message', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'IP Address', 'post-kinds-for-indieweb' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td>
							<?php
							if ( ! empty( $entry['timestamp'] ) ) {
								echo esc_html( wp_date( 'M j, Y g:i:s a', $entry['timestamp'] ) );
							}
							?>
						</td>
						<td><?php echo esc_html( $this->webhook_configs[ $entry['source'] ]['name'] ?? $entry['source'] ?? 'Unknown' ); ?></td>
						<td>
							<?php
							$status       = $entry['status'] ?? 'unknown';
							$status_class = 'success' === $status ? 'success' : ( 'error' === $status ? 'error' : 'warning' );
							?>
							<span class="status-badge <?php echo esc_attr( $status_class ); ?>">
								<?php echo esc_html( ucfirst( $status ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
						<td><?php echo esc_html( $entry['ip'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button clear-webhook-log">
				<?php esc_html_e( 'Clear Log', 'post-kinds-for-indieweb' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * AJAX handler: Regenerate secret.
	 *
	 * @return void
	 */
	public function ajax_regenerate_secret(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$webhook = isset( $_POST['webhook'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook'] ) ) : '';

		if ( empty( $webhook ) || ! isset( $this->webhook_configs[ $webhook ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid webhook.', 'post-kinds-for-indieweb' ) ] );
		}

		// Generate new secret.
		$secret = wp_generate_password( 32, true, false );

		// Save it.
		$settings = get_option( 'post_kinds_indieweb_webhook_settings', [] );
		if ( ! isset( $settings[ $webhook ] ) ) {
			$settings[ $webhook ] = [];
		}
		$settings[ $webhook ]['secret'] = $secret;
		update_option( 'post_kinds_indieweb_webhook_settings', $settings );

		wp_send_json_success( [ 'secret' => $secret ] );
	}

	/**
	 * AJAX handler: Clear pending scrobbles.
	 *
	 * @return void
	 */
	public function ajax_clear_pending(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		delete_option( 'post_kinds_indieweb_pending_scrobbles' );

		wp_send_json_success( [ 'message' => __( 'Pending scrobbles cleared.', 'post-kinds-for-indieweb' ) ] );
	}

	/**
	 * AJAX handler: Approve scrobble.
	 *
	 * @return void
	 */
	public function ajax_approve_scrobble(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1;

		$pending = get_option( 'post_kinds_indieweb_pending_scrobbles', [] );

		if ( ! isset( $pending[ $index ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Scrobble not found.', 'post-kinds-for-indieweb' ) ] );
		}

		$scrobble = $pending[ $index ];

		// Create post from scrobble.
		$post_id = $this->create_post_from_scrobble( $scrobble );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
		}

		// Remove from pending.
		unset( $pending[ $index ] );
		$pending = array_values( $pending ); // Re-index.
		update_option( 'post_kinds_indieweb_pending_scrobbles', $pending );

		wp_send_json_success(
			[
				'post_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				'message'  => __( 'Post created successfully.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * AJAX handler: Reject scrobble.
	 *
	 * @return void
	 */
	public function ajax_reject_scrobble(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1;

		$pending = get_option( 'post_kinds_indieweb_pending_scrobbles', [] );

		if ( ! isset( $pending[ $index ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Scrobble not found.', 'post-kinds-for-indieweb' ) ] );
		}

		// Remove from pending.
		unset( $pending[ $index ] );
		$pending = array_values( $pending ); // Re-index.
		update_option( 'post_kinds_indieweb_pending_scrobbles', $pending );

		wp_send_json_success( [ 'message' => __( 'Scrobble rejected.', 'post-kinds-for-indieweb' ) ] );
	}

	/**
	 * Create post from scrobble data.
	 *
	 * @param array<string, mixed> $scrobble Scrobble data.
	 * @return int|\WP_Error Post ID or error.
	 */
	private function create_post_from_scrobble( array $scrobble ) {
		$settings    = get_option( 'post_kinds_indieweb_settings', [] );
		$post_status = $settings['default_post_status'] ?? 'publish';

		// Determine post kind.
		$type      = $scrobble['type'] ?? 'unknown';
		$post_kind = 'watch';
		if ( in_array( $type, [ 'track', 'audio', 'listen' ], true ) ) {
			$post_kind = 'listen';
		}

		// Build title.
		$title = $scrobble['title'] ?? 'Untitled';
		if ( ! empty( $scrobble['artist'] ) ) {
			$title .= ' by ' . $scrobble['artist'];
		}

		// Build content.
		$content = '';
		if ( 'listen' === $post_kind ) {
			$content = sprintf(
				'<!-- wp:paragraph --><p>Listened to <strong>%s</strong>%s</p><!-- /wp:paragraph -->',
				esc_html( $scrobble['title'] ?? 'Unknown' ),
				! empty( $scrobble['artist'] ) ? ' by ' . esc_html( $scrobble['artist'] ) : ''
			);
		} else {
			$content = sprintf(
				'<!-- wp:paragraph --><p>Watched <strong>%s</strong>%s</p><!-- /wp:paragraph -->',
				esc_html( $scrobble['title'] ?? 'Unknown' ),
				! empty( $scrobble['year'] ) ? ' (' . esc_html( $scrobble['year'] ) . ')' : ''
			);
		}

		$post_data = [
			'post_type'    => 'post',
			'post_status'  => $post_status,
			'post_title'   => $title,
			'post_content' => $content,
			'post_date'    => ! empty( $scrobble['timestamp'] ) ? wp_date( 'Y-m-d H:i:s', $scrobble['timestamp'] ) : current_time( 'mysql' ),
		];

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set post kind.
		wp_set_object_terms( $post_id, $post_kind, 'kind' );

		// Save metadata.
		if ( ! empty( $scrobble['metadata'] ) ) {
			foreach ( $scrobble['metadata'] as $key => $value ) {
				update_post_meta( $post_id, '_postkind_indieweb_' . $key, $value );
			}
		}

		return $post_id;
	}
}

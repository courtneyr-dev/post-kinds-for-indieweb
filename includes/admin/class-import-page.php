<?php
/**
 * Import Page
 *
 * Admin page for importing data from external services.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Admin;

use PostKindsForIndieWeb\Import_Manager;

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
		$this->admin          = $admin;
		$this->import_sources = $this->get_import_sources();
	}

	/**
	 * Initialize import page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_ajax_postkind_indieweb_start_import', [ $this, 'ajax_start_import' ] );
		add_action( 'wp_ajax_postkind_indieweb_cancel_import', [ $this, 'ajax_cancel_import' ] );
		add_action( 'wp_ajax_postkind_indieweb_get_import_preview', [ $this, 'ajax_get_import_preview' ] );
		add_action( 'wp_ajax_postkind_indieweb_resync_metadata', [ $this, 'ajax_resync_metadata' ] );
	}

	/**
	 * Get import sources configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_import_sources(): array {
		// Get stored credentials for auto-filling usernames.
		$credentials     = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$lastfm_username = $credentials['lastfm']['username'] ?? '';

		return [
			'listenbrainz'           => [
				'name'        => 'ListenBrainz',
				'description' => __( 'Import your listening history from ListenBrainz.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'listen',
				'icon'        => 'dashicons-format-audio',
				'api_key'     => 'listenbrainz',
				'options'     => [
					'date_from' => [
						'label' => __( 'From Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'date_to'   => [
						'label' => __( 'To Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'limit'     => [
						'label'   => __( 'Maximum Items', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 100,
						'max'     => 1000,
					],
				],
			],
			'lastfm'                 => [
				'name'        => 'Last.fm',
				'description' => __( 'Import your scrobble history from Last.fm.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'listen',
				'icon'        => 'dashicons-format-audio',
				'api_key'     => 'lastfm',
				'options'     => [
					'username'  => [
						'label'    => __( 'Last.fm Username', 'post-kinds-for-indieweb' ),
						'type'     => 'text',
						'required' => empty( $lastfm_username ),
						'default'  => $lastfm_username,
					],
					'date_from' => [
						'label' => __( 'From Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'date_to'   => [
						'label' => __( 'To Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'limit'     => [
						'label'   => __( 'Maximum Items', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 100,
						'max'     => 1000,
					],
				],
			],
			'trakt_movies'           => [
				'name'        => 'Trakt Movies',
				'description' => __( 'Import your movie watch history from Trakt.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'watch',
				'icon'        => 'dashicons-video-alt2',
				'api_key'     => 'trakt',
				'options'     => [
					'date_from'       => [
						'label' => __( 'From Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'date_to'         => [
						'label' => __( 'To Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'include_ratings' => [
						'label'   => __( 'Include Ratings', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => true,
					],
				],
			],
			'trakt_shows'            => [
				'name'        => 'Trakt TV Shows',
				'description' => __( 'Import your TV show watch history from Trakt.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'watch',
				'icon'        => 'dashicons-video-alt2',
				'api_key'     => 'trakt',
				'options'     => [
					'date_from' => [
						'label' => __( 'From Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'date_to'   => [
						'label' => __( 'To Date', 'post-kinds-for-indieweb' ),
						'type'  => 'date',
					],
					'group_by'  => [
						'label'   => __( 'Group Episodes', 'post-kinds-for-indieweb' ),
						'type'    => 'select',
						'options' => [
							'none'   => __( 'Individual episodes', 'post-kinds-for-indieweb' ),
							'season' => __( 'By season', 'post-kinds-for-indieweb' ),
							'show'   => __( 'By show', 'post-kinds-for-indieweb' ),
						],
						'default' => 'none',
					],
				],
			],
			'simkl'                  => [
				'name'        => 'Simkl',
				'description' => __( 'Import your watch history from Simkl.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'watch',
				'icon'        => 'dashicons-video-alt2',
				'api_key'     => 'simkl',
				'options'     => [
					'type'   => [
						'label'   => __( 'Content Type', 'post-kinds-for-indieweb' ),
						'type'    => 'select',
						'options' => [
							'movies' => __( 'Movies', 'post-kinds-for-indieweb' ),
							'shows'  => __( 'TV Shows', 'post-kinds-for-indieweb' ),
							'anime'  => __( 'Anime', 'post-kinds-for-indieweb' ),
						],
						'default' => 'movies',
					],
					'status' => [
						'label'   => __( 'Watch Status', 'post-kinds-for-indieweb' ),
						'type'    => 'select',
						'options' => [
							'completed'   => __( 'Completed', 'post-kinds-for-indieweb' ),
							'watching'    => __( 'Currently Watching', 'post-kinds-for-indieweb' ),
							'plantowatch' => __( 'Plan to Watch', 'post-kinds-for-indieweb' ),
							'all'         => __( 'All', 'post-kinds-for-indieweb' ),
						],
						'default' => 'completed',
					],
				],
			],
			'hardcover'              => [
				'name'        => 'Hardcover',
				'description' => __( 'Import your reading history from Hardcover.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'read',
				'icon'        => 'dashicons-book',
				'api_key'     => 'hardcover',
				'options'     => [
					'status'          => [
						'label'   => __( 'Reading Status', 'post-kinds-for-indieweb' ),
						'type'    => 'select',
						'options' => [
							'finished' => __( 'Finished', 'post-kinds-for-indieweb' ),
							'reading'  => __( 'Currently Reading', 'post-kinds-for-indieweb' ),
							'want'     => __( 'Want to Read', 'post-kinds-for-indieweb' ),
							'dnf'      => __( 'Did Not Finish', 'post-kinds-for-indieweb' ),
							'all'      => __( 'All', 'post-kinds-for-indieweb' ),
						],
						'default' => 'finished',
					],
					'include_ratings' => [
						'label'   => __( 'Include Ratings', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => true,
					],
					'include_reviews' => [
						'label'   => __( 'Include Reviews', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => true,
					],
				],
			],
			'foursquare'             => [
				'name'        => 'Foursquare / Swarm',
				'description' => __( 'Import your checkin history from Foursquare/Swarm.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'checkin',
				'icon'        => 'dashicons-location-alt',
				'api_key'     => 'foursquare',
				'options'     => [
					'limit' => [
						'label'   => __( 'Maximum Checkins', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 100,
						'max'     => 500,
					],
				],
			],
			// Note: Untappd API is no longer available without a commercial agreement.
			// Keeping the sync class code in place in case API access becomes available again.

			// Readwise imports - multiple content types.
			'readwise_books'         => [
				'name'        => 'Readwise Books',
				'description' => __( 'Import book highlights from Readwise (Kindle, Apple Books, etc.). Each book requires separate API calls for highlights.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'read',
				'icon'        => 'dashicons-book',
				'api_key'     => 'readwise',
				'options'     => [
					'limit'              => [
						'label'   => __( 'Maximum Books', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 20,
						'max'     => 100,
					],
					'include_highlights' => [
						'label'   => __( 'Include Highlights', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => true,
					],
					'update_existing'    => [
						'label'   => __( 'Update existing posts', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => false,
					],
				],
			],
			'readwise_articles'      => [
				'name'        => 'Readwise Articles',
				'description' => __( 'Import article highlights from Readwise (Reader, Instapaper, Pocket, etc.).', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'bookmark',
				'icon'        => 'dashicons-admin-links',
				'api_key'     => 'readwise',
				'options'     => [
					'limit'              => [
						'label'   => __( 'Maximum Articles', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 100,
						'max'     => 500,
					],
					'include_highlights' => [
						'label'   => __( 'Include Highlights', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => true,
					],
					'update_existing'    => [
						'label'   => __( 'Update existing posts', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => false,
					],
				],
			],
			'readwise_podcasts'      => [
				'name'        => 'Readwise Podcasts',
				'description' => __( 'Import podcast episode highlights from Readwise (Snipd, Airr, etc.). Each episode requires separate API calls, so import in small batches.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'listen',
				'icon'        => 'dashicons-microphone',
				'api_key'     => 'readwise',
				'options'     => [
					'limit'              => [
						'label'   => __( 'Maximum Episodes', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 20,
						'max'     => 100,
					],
					'include_highlights' => [
						'label'   => __( 'Include Highlights/Snips', 'post-kinds-for-indieweb' ),
						'type'    => 'checkbox',
						'default' => true,
					],
					'update_existing'    => [
						'label'       => __( 'Update existing posts', 'post-kinds-for-indieweb' ),
						'type'        => 'checkbox',
						'default'     => false,
						'description' => __( 'Update metadata on previously imported posts instead of skipping them.', 'post-kinds-for-indieweb' ),
					],
				],
			],
			'readwise_tweets'        => [
				'name'        => 'Readwise Tweets',
				'description' => __( 'Import saved tweet threads from Readwise.', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'bookmark',
				'icon'        => 'dashicons-twitter',
				'api_key'     => 'readwise',
				'options'     => [
					'limit' => [
						'label'   => __( 'Maximum Threads', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 100,
						'max'     => 500,
					],
				],
			],
			'readwise_supplementals' => [
				'name'        => 'Readwise Supplementals',
				'description' => __( 'Import supplemental materials from Readwise (PDFs, notes, etc.).', 'post-kinds-for-indieweb' ),
				'post_kind'   => 'note',
				'icon'        => 'dashicons-media-document',
				'api_key'     => 'readwise',
				'options'     => [
					'limit' => [
						'label'   => __( 'Maximum Items', 'post-kinds-for-indieweb' ),
						'type'    => 'number',
						'default' => 100,
						'max'     => 500,
					],
				],
			],
		];
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

		$credentials    = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$active_imports = get_option( 'post_kinds_indieweb_active_imports', [] );

		?>
		<div class="wrap post-kinds-indieweb-import">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( ! empty( $active_imports ) ) : ?>
				<div class="active-imports-section">
					<h2><?php esc_html_e( 'Active Imports', 'post-kinds-for-indieweb' ); ?></h2>
					<?php $this->render_active_imports( $active_imports ); ?>
				</div>
				<hr>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Start New Import', 'post-kinds-for-indieweb' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import your media history from connected services. Imports run in the background and may take a while for large collections.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<div class="import-sources">
				<?php foreach ( $this->import_sources as $source_id => $source ) : ?>
					<?php
					$api_key      = $source['api_key'];
					$is_enabled   = ! empty( $credentials[ $api_key ]['enabled'] );
					$is_connected = $is_enabled && $this->check_api_connected( $api_key, $credentials[ $api_key ] ?? [] );
					?>
					<div class="import-source-card <?php echo $is_connected ? 'available' : 'unavailable'; ?>"
						data-source="<?php echo esc_attr( $source_id ); ?>">

						<div class="source-header">
							<span class="dashicons <?php echo esc_attr( $source['icon'] ); ?>"></span>
							<h3><?php echo esc_html( $source['name'] ); ?></h3>
							<?php if ( ! $is_connected ) : ?>
								<span class="status-badge not-connected">
									<?php esc_html_e( 'Not Connected', 'post-kinds-for-indieweb' ); ?>
								</span>
							<?php endif; ?>
						</div>

						<p class="source-description"><?php echo esc_html( $source['description'] ); ?></p>

						<?php if ( $is_connected ) : ?>
							<div class="source-options">
								<?php $this->render_source_options( $source_id, $source['options'] ?? [] ); ?>
							</div>

							<div class="source-actions">
								<button type="button" class="button import-preview-button" data-source="<?php echo esc_attr( $source_id ); ?>">
									<span class="dashicons dashicons-visibility"></span>
									<?php esc_html_e( 'Preview', 'post-kinds-for-indieweb' ); ?>
								</button>
								<button type="button" class="button button-primary import-start-button" data-source="<?php echo esc_attr( $source_id ); ?>">
									<span class="dashicons dashicons-download"></span>
									<?php esc_html_e( 'Start Import', 'post-kinds-for-indieweb' ); ?>
								</button>
								<button type="button" class="button import-resync-button" data-source="<?php echo esc_attr( $source_id ); ?>" title="<?php esc_attr_e( 'Update metadata for previously imported posts', 'post-kinds-for-indieweb' ); ?>">
									<span class="dashicons dashicons-update"></span>
									<?php esc_html_e( 'Re-sync', 'post-kinds-for-indieweb' ); ?>
								</button>
							</div>
						<?php else : ?>
							<div class="source-actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-apis' ) ); ?>" class="button">
									<?php esc_html_e( 'Configure API', 'post-kinds-for-indieweb' ); ?>
								</a>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Import preview modal -->
			<div id="import-preview-modal" class="post-kinds-modal" style="display: none;">
				<div class="modal-content">
					<div class="modal-header">
						<h2><?php esc_html_e( 'Import Preview', 'post-kinds-for-indieweb' ); ?></h2>
						<button type="button" class="modal-close">&times;</button>
					</div>
					<div class="modal-body">
						<div class="preview-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e( 'Loading preview...', 'post-kinds-for-indieweb' ); ?>
						</div>
						<div class="preview-content" style="display: none;"></div>
					</div>
					<div class="modal-footer">
						<button type="button" class="button modal-cancel">
							<?php esc_html_e( 'Cancel', 'post-kinds-for-indieweb' ); ?>
						</button>
						<button type="button" class="button button-primary modal-confirm-import">
							<?php esc_html_e( 'Start Import', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
				</div>
			</div>

			<hr>

			<h2><?php esc_html_e( 'Import History', 'post-kinds-for-indieweb' ); ?></h2>
			<?php $this->render_import_history(); ?>
		</div>
		<?php
	}

	/**
	 * Render common import options (import amount, publish checkbox).
	 *
	 * These options are shown for all import sources.
	 *
	 * @param string $source_id Source identifier.
	 * @return void
	 */
	private function render_common_import_options( string $source_id ): void {
		?>
		<div class="common-import-options">
			<div class="import-amount-options">
				<label class="option-label"><?php esc_html_e( 'Import:', 'post-kinds-for-indieweb' ); ?></label>
				<div class="radio-group">
					<label>
						<input type="radio" name="import_<?php echo esc_attr( $source_id ); ?>_amount"
								value="1" class="import-option"
								data-source="<?php echo esc_attr( $source_id ); ?>"
								data-option="import_amount">
						<?php esc_html_e( 'Last 1 (Test)', 'post-kinds-for-indieweb' ); ?>
					</label>
					<label>
						<input type="radio" name="import_<?php echo esc_attr( $source_id ); ?>_amount"
								value="all" class="import-option"
								data-source="<?php echo esc_attr( $source_id ); ?>"
								data-option="import_amount" checked>
						<?php esc_html_e( 'All', 'post-kinds-for-indieweb' ); ?>
					</label>
					<label class="custom-amount-label">
						<input type="radio" name="import_<?php echo esc_attr( $source_id ); ?>_amount"
								value="custom" class="import-option import-amount-custom-radio"
								data-source="<?php echo esc_attr( $source_id ); ?>"
								data-option="import_amount">
						<?php esc_html_e( 'Last', 'post-kinds-for-indieweb' ); ?>
						<input type="number"
								name="import_<?php echo esc_attr( $source_id ); ?>_custom_limit"
								class="small-text import-option import-custom-limit"
								data-source="<?php echo esc_attr( $source_id ); ?>"
								data-option="custom_limit"
								min="1" max="1000" value="50"
								style="width: 60px;">
						<?php esc_html_e( 'items', 'post-kinds-for-indieweb' ); ?>
					</label>
				</div>
			</div>

			<div class="publish-option">
				<label>
					<input type="checkbox"
							name="import_<?php echo esc_attr( $source_id ); ?>_publish"
							value="1" class="import-option import-publish-checkbox"
							data-source="<?php echo esc_attr( $source_id ); ?>"
							data-option="publish_immediately">
					<?php esc_html_e( 'Publish immediately', 'post-kinds-for-indieweb' ); ?>
				</label>
				<p class="publish-warning" style="display: none; color: #d63638; margin: 5px 0 0 24px;">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Warning: Posts will be published to your site immediately. Leave unchecked to create drafts for review.', 'post-kinds-for-indieweb' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render source options.
	 *
	 * @param string                             $source_id Source identifier.
	 * @param array<string, array<string,mixed>> $options  Source options.
	 * @return void
	 */
	private function render_source_options( string $source_id, array $options ): void {
		// Always render common import options (import amount, publish checkbox).
		$this->render_common_import_options( $source_id );

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
								esc_html__( '%1$d of %2$d items', 'post-kinds-for-indieweb' ),
								(int) ( $import['processed'] ?? 0 ),
								(int) ( $import['total'] ?? 0 )
							);
							?>
						</span>
					</div>

					<div class="import-actions">
						<button type="button" class="button import-cancel-button" data-import-id="<?php echo esc_attr( $import_id ); ?>">
							<?php esc_html_e( 'Cancel', 'post-kinds-for-indieweb' ); ?>
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
		$history = get_option( 'post_kinds_indieweb_import_history', [] );

		if ( empty( $history ) ) {
			echo '<p class="description">' . esc_html__( 'No imports have been run yet.', 'post-kinds-for-indieweb' ) . '</p>';
			return;
		}

		// Sort by date descending.
		usort(
			$history,
			function ( $a, $b ) {
				return ( $b['completed_at'] ?? 0 ) - ( $a['completed_at'] ?? 0 );
			}
		);

		// Limit to last 20.
		$history = array_slice( $history, 0, 20 );

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Date', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Status', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Items', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Posts Created', 'post-kinds-for-indieweb' ); ?></th>
					<th><?php esc_html_e( 'Duplicates Skipped', 'post-kinds-for-indieweb' ); ?></th>
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
		if ( in_array( $api_key, [ 'trakt', 'simkl', 'foursquare', 'untappd' ], true ) ) {
			return ! empty( $credentials['access_token'] );
		}

		// Token-based APIs.
		if ( 'listenbrainz' === $api_key ) {
			return ! empty( $credentials['token'] );
		}

		if ( 'hardcover' === $api_key ) {
			return ! empty( $credentials['api_token'] );
		}

		if ( 'readwise' === $api_key ) {
			return ! empty( $credentials['access_token'] );
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
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$options = isset( $_POST['options'] ) ? $this->sanitize_import_options( wp_unslash( $_POST['options'] ) ) : [];

		if ( empty( $source ) || ! isset( $this->import_sources[ $source ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid import source.', 'post-kinds-for-indieweb' ) ] );
		}

		try {
			$import_manager = new Import_Manager();
			$result         = $import_manager->start_import( $source, $options );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ] );
			}

			// Handle array result from Import_Manager.
			if ( is_array( $result ) ) {
				if ( empty( $result['success'] ) ) {
					wp_send_json_error( [ 'message' => $result['error'] ?? __( 'Import failed to start.', 'post-kinds-for-indieweb' ) ] );
				}

				$job_id = $result['job_id'] ?? '';

				// For Readwise imports (slow due to per-item API calls), don't process synchronously.
				// Let WP-Cron handle them in the background to avoid timeout/stall issues.
				$slow_sources = [ 'readwise_books', 'readwise_podcasts', 'readwise_articles' ];

				if ( $job_id && ! in_array( $source, $slow_sources, true ) ) {
					// Process non-Readwise imports immediately for dev sites where cron may not run.
					$import_manager->process_import_batch( $job_id, $source );

					// Get updated job status.
					$job_status = $import_manager->get_status( $job_id );

					// Check if job failed.
					if ( 'failed' === ( $job_status['status'] ?? '' ) ) {
						$error_msg = ! empty( $job_status['errors'] )
							? implode( '; ', $job_status['errors'] )
							: __( 'Import failed.', 'post-kinds-for-indieweb' );
						wp_send_json_error( [ 'message' => $error_msg ] );
					}

					$updated_count = $job_status['updated'] ?? 0;
					$message_parts = [];
					if ( ( $job_status['imported'] ?? 0 ) > 0 ) {
						/* translators: %d: Number of items imported */
						$message_parts[] = sprintf( __( '%d imported', 'post-kinds-for-indieweb' ), $job_status['imported'] );
					}
					if ( $updated_count > 0 ) {
						/* translators: %d: Number of items updated */
						$message_parts[] = sprintf( __( '%d updated', 'post-kinds-for-indieweb' ), $updated_count );
					}
					if ( ( $job_status['skipped'] ?? 0 ) > 0 ) {
						/* translators: %d: Number of items skipped */
						$message_parts[] = sprintf( __( '%d skipped', 'post-kinds-for-indieweb' ), $job_status['skipped'] );
					}
					if ( ( $job_status['failed'] ?? 0 ) > 0 ) {
						/* translators: %d: Number of items that failed */
						$message_parts[] = sprintf( __( '%d failed', 'post-kinds-for-indieweb' ), $job_status['failed'] );
					}

					// If nothing was processed and there are errors, show the first error.
					$errors = $job_status['errors'] ?? [];
					if ( empty( $message_parts ) && ! empty( $errors ) ) {
						wp_send_json_error( [ 'message' => $errors[0] ] );
					}

					// If nothing was processed and no errors, show a helpful message.
					if ( empty( $message_parts ) ) {
						wp_send_json_success(
							[
								'import_id' => $job_id,
								'message'   => __( 'Import completed but no new items were found.', 'post-kinds-for-indieweb' ),
								'imported'  => 0,
								'updated'   => 0,
								'skipped'   => 0,
								'failed'    => 0,
								'errors'    => $errors,
							]
						);
					}

					wp_send_json_success(
						[
							'import_id' => $job_id,
							'message'   => sprintf(
								/* translators: %s: Comma-separated list of import results */
								__( 'Import completed: %s.', 'post-kinds-for-indieweb' ),
								implode( ', ', $message_parts )
							),
							'imported'  => $job_status['imported'] ?? 0,
							'updated'   => $updated_count,
							'skipped'   => $job_status['skipped'] ?? 0,
							'failed'    => $job_status['failed'] ?? 0,
							'errors'    => $errors,
						]
					);
				}

				// For slow imports (Readwise), process one batch synchronously then queue the rest.
				// This ensures users see immediate results even if WP-Cron isn't working.
				if ( in_array( $source, $slow_sources, true ) ) {
					// Process first batch immediately to ensure at least some items import.
					$import_manager->process_import_batch( $job_id, $source );

					// Get updated job status.
					$job_status = $import_manager->get_status( $job_id );

					// Build result message.
					$message_parts = [];
					if ( ( $job_status['imported'] ?? 0 ) > 0 ) {
						/* translators: %d: Number of items imported */
						$message_parts[] = sprintf( __( '%d imported', 'post-kinds-for-indieweb' ), $job_status['imported'] );
					}
					if ( ( $job_status['updated'] ?? 0 ) > 0 ) {
						/* translators: %d: Number of items updated */
						$message_parts[] = sprintf( __( '%d updated', 'post-kinds-for-indieweb' ), $job_status['updated'] );
					}
					if ( ( $job_status['skipped'] ?? 0 ) > 0 ) {
						/* translators: %d: Number of items skipped */
						$message_parts[] = sprintf( __( '%d skipped', 'post-kinds-for-indieweb' ), $job_status['skipped'] );
					}

					// Spawn cron for any remaining items.
					spawn_cron();

					$message = ! empty( $message_parts )
						/* translators: %s: Comma-separated list of import progress */
						? sprintf( __( 'Import progress: %s. More items processing in background.', 'post-kinds-for-indieweb' ), implode( ', ', $message_parts ) )
						: __( 'Import started. Processing in background...', 'post-kinds-for-indieweb' );

					wp_send_json_success(
						[
							'import_id'  => $job_id,
							'message'    => $message,
							'imported'   => $job_status['imported'] ?? 0,
							'updated'    => $job_status['updated'] ?? 0,
							'skipped'    => $job_status['skipped'] ?? 0,
							'background' => true,
						]
					);
				}

				wp_send_json_success(
					[
						'import_id' => $job_id,
						'message'   => $result['message'] ?? __( 'Import started successfully.', 'post-kinds-for-indieweb' ),
					]
				);
			}

			wp_send_json_success(
				[
					'import_id' => $result,
					'message'   => __( 'Import started successfully.', 'post-kinds-for-indieweb' ),
				]
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		} catch ( \Error $e ) {
			wp_send_json_error( [ 'message' => 'PHP Error: ' . $e->getMessage() ] );
		}
	}

	/**
	 * AJAX handler: Cancel import.
	 *
	 * @return void
	 */
	public function ajax_cancel_import(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$import_id = isset( $_POST['import_id'] ) ? sanitize_text_field( wp_unslash( $_POST['import_id'] ) ) : '';

		if ( empty( $import_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Import ID required.', 'post-kinds-for-indieweb' ) ] );
		}

		$import_manager = new Import_Manager();
		$result         = $import_manager->cancel_import( $import_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success(
			[
				'message' => __( 'Import cancelled.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * AJAX handler: Get import preview.
	 *
	 * @return void
	 */
	public function ajax_get_import_preview(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$options = isset( $_POST['options'] ) ? $this->sanitize_import_options( wp_unslash( $_POST['options'] ) ) : [];

		if ( empty( $source ) || ! isset( $this->import_sources[ $source ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid import source.', 'post-kinds-for-indieweb' ) ] );
		}

		$preview = $this->get_import_preview( $source, $options );

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( [ 'message' => $preview->get_error_message() ] );
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
		$credentials   = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$source_config = $this->import_sources[ $source ];
		$api_key       = $source_config['api_key'];
		$api_creds     = $credentials[ $api_key ] ?? [];

		// Fetch a small sample of items.
		$preview_limit = 5;
		$items         = [];
		$total_count   = 0;

		switch ( $source ) {
			case 'listenbrainz':
				$lb_creds = $credentials['listenbrainz'] ?? [];
				$username = $lb_creds['username'] ?? '';
				if ( empty( $username ) ) {
					return new \WP_Error( 'missing_username', __( 'ListenBrainz username not configured. Please set it in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$api     = new \PostKindsForIndieWeb\APIs\ListenBrainz();
				$listens = $api->get_listens( $username, $preview_limit );
				if ( is_wp_error( $listens ) ) {
					return $listens;
				}

				$total_count = $listens['count'] ?? count( $listens['listens'] ?? [] );
				$items       = array_slice( $listens['listens'] ?? [], 0, $preview_limit );
				break;

			case 'lastfm':
				$username = $options['username'] ?? '';
				if ( empty( $username ) ) {
					return new \WP_Error( 'missing_username', __( 'Please enter your Last.fm username.', 'post-kinds-for-indieweb' ) );
				}

				$api = new \PostKindsForIndieWeb\APIs\LastFM();

				// Check if API is configured.
				if ( ! $api->test_connection() ) {
					return new \WP_Error( 'api_not_configured', __( 'Last.fm API is not configured. Please add your API key in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$tracks = $api->get_recent_tracks( $username, $preview_limit );
				if ( is_wp_error( $tracks ) ) {
					return $tracks;
				}

				$total_count = $tracks['total'] ?? count( $tracks['tracks'] ?? [] );
				$items       = array_slice( $tracks['tracks'] ?? [], 0, $preview_limit );

				if ( empty( $items ) && 0 === $total_count ) {
					return new \WP_Error( 'no_tracks', __( 'No tracks found for this username. Check that the username is correct.', 'post-kinds-for-indieweb' ) );
				}
				break;

			case 'trakt_movies':
			case 'trakt_shows':
				$api = new \PostKindsForIndieWeb\APIs\Trakt();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Trakt API is not configured. Please set up OAuth in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$type    = 'trakt_movies' === $source ? 'movies' : 'shows';
				$history = $api->get_history( $type, 1, $preview_limit );
				if ( is_wp_error( $history ) ) {
					return $history;
				}

				$items       = $history['items'] ?? [];
				$total_count = ( $history['total'] ?? count( $items ) ) * 10; // Rough estimate.
				break;

			case 'simkl':
				$api = new \PostKindsForIndieWeb\APIs\Simkl();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Simkl API is not configured. Please set up OAuth in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$type    = $options['type'] ?? 'movies';
				$history = $api->get_history( $type );
				if ( is_wp_error( $history ) ) {
					return $history;
				}

				$total_count = count( $history );
				$items       = array_slice( $history, 0, $preview_limit );
				break;

			case 'hardcover':
				$api = new \PostKindsForIndieWeb\APIs\Hardcover();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Hardcover API is not configured. Please add your API token in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$status = $options['status'] ?? 'finished';
				$books  = $api->get_reading_list( $status, $preview_limit );
				if ( is_wp_error( $books ) ) {
					return $books;
				}

				$items       = $books;
				$total_count = count( $books ) * 5; // Estimate.
				break;

			case 'foursquare':
				$foursquare_sync = \PostKindsForIndieWeb\Plugin::get_instance()->get_checkin_sync_service( 'foursquare' );
				if ( ! $foursquare_sync || ! $foursquare_sync->is_connected() ) {
					return new \WP_Error( 'api_not_configured', __( 'Foursquare is not connected. Please authorize in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$checkins = $foursquare_sync->fetch_recent_checkins( $preview_limit );
				if ( is_wp_error( $checkins ) ) {
					return $checkins;
				}

				$items       = $checkins;
				$total_count = count( $checkins ) * 10; // Estimate.
				break;

			case 'untappd':
				$untappd_sync = \PostKindsForIndieWeb\Plugin::get_instance()->get_checkin_sync_service( 'untappd' );
				if ( ! $untappd_sync || ! $untappd_sync->is_connected() ) {
					return new \WP_Error( 'api_not_configured', __( 'Untappd is not connected. Please authorize in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$checkins = $untappd_sync->fetch_recent_checkins( $preview_limit );
				if ( is_wp_error( $checkins ) ) {
					return $checkins;
				}

				$items       = $checkins;
				$total_count = count( $checkins ) * 10; // Estimate.
				break;

			case 'readwise_books':
				$api = new \PostKindsForIndieWeb\APIs\Readwise();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Readwise is not configured. Please add your access token in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				// For preview, don't fetch all highlights (slower), just book info.
				$books       = $api->get_books_with_highlights( $preview_limit, false );
				$items       = $books;
				$total_count = count( $books ) * 2; // Estimate.
				break;

			case 'readwise_articles':
				$api = new \PostKindsForIndieWeb\APIs\Readwise();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Readwise is not configured. Please add your access token in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$articles    = $api->get_articles( $preview_limit );
				$items       = $articles;
				$total_count = count( $articles ) * 2; // Estimate.
				break;

			case 'readwise_podcasts':
				$api = new \PostKindsForIndieWeb\APIs\Readwise();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Readwise is not configured. Please add your access token in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$podcasts    = $api->get_podcast_episodes( $preview_limit );
				$items       = $podcasts;
				$total_count = count( $podcasts ) * 2; // Estimate.
				break;

			case 'readwise_tweets':
				$api = new \PostKindsForIndieWeb\APIs\Readwise();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Readwise is not configured. Please add your access token in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$tweets      = $api->get_tweets( $preview_limit );
				$items       = $tweets;
				$total_count = count( $tweets ) * 2; // Estimate.
				break;

			case 'readwise_supplementals':
				$api = new \PostKindsForIndieWeb\APIs\Readwise();
				if ( ! $api->is_configured() ) {
					return new \WP_Error( 'api_not_configured', __( 'Readwise is not configured. Please add your access token in API Connections.', 'post-kinds-for-indieweb' ) );
				}

				$supplementals = $api->get_books( 'supplementals', $preview_limit );
				$items         = $supplementals;
				$total_count   = count( $supplementals ) * 2; // Estimate.
				break;

			default:
				return new \WP_Error( 'unsupported', __( 'Import preview not supported for this source.', 'post-kinds-for-indieweb' ) );
		}

		return [
			'source'      => $source,
			'source_name' => $source_config['name'],
			'total_count' => $total_count,
			'sample'      => $this->format_preview_items( $items, $source ),
			'post_kind'   => $source_config['post_kind'],
		];
	}

	/**
	 * Format preview items for display.
	 *
	 * @param array<int, array<string, mixed>> $items  Raw items.
	 * @param string                           $source Import source.
	 * @return array<int, array<string, string>> Formatted items.
	 */
	private function format_preview_items( array $items, string $source ): array {
		$formatted = [];

		foreach ( $items as $item ) {
			switch ( $source ) {
				case 'listenbrainz':
				case 'lastfm':
					$formatted[] = [
						'title'  => $item['track'] ?? $item['track_name'] ?? 'Unknown Track',
						'artist' => $item['artist'] ?? $item['artist_name'] ?? 'Unknown Artist',
						'date'   => isset( $item['listened_at'] ) ? wp_date( 'M j, Y g:i a', $item['listened_at'] ) : '',
					];
					break;

				case 'trakt_movies':
					$formatted[] = [
						'title' => $item['title'] ?? 'Unknown Movie',
						'year'  => $item['year'] ?? '',
						'date'  => isset( $item['watched_at'] ) ? wp_date( 'M j, Y g:i a', strtotime( $item['watched_at'] ) ) : '',
					];
					break;

				case 'trakt_shows':
					// Normalized data has show info in 'show' sub-array for episodes.
					$show_title  = $item['show']['title'] ?? $item['title'] ?? 'Unknown Show';
					$season      = $item['season'] ?? ( $item['show']['season'] ?? 0 );
					$number      = $item['number'] ?? ( $item['show']['number'] ?? 0 );
					$formatted[] = [
						'title'   => $show_title,
						'episode' => sprintf( 'S%02dE%02d', $season, $number ),
						'date'    => isset( $item['watched_at'] ) ? wp_date( 'M j, Y g:i a', strtotime( $item['watched_at'] ) ) : '',
					];
					break;

				case 'simkl':
					$formatted[] = [
						'title' => $item['movie']['title'] ?? $item['show']['title'] ?? 'Unknown',
						'year'  => $item['movie']['year'] ?? $item['show']['year'] ?? '',
					];
					break;

				case 'hardcover':
					$formatted[] = [
						'title'  => $item['book']['title'] ?? 'Unknown Book',
						'author' => $item['book']['author'] ?? '',
						'rating' => $item['rating'] ?? '',
					];
					break;

				case 'foursquare':
					$formatted[] = [
						'title'   => $item['venue_name'] ?? 'Unknown Venue',
						'address' => $item['address'] ?? '',
						'date'    => isset( $item['timestamp'] ) ? wp_date( 'M j, Y g:i a', $item['timestamp'] ) : '',
					];
					break;

				case 'untappd':
					$formatted[] = [
						'title'   => $item['beer_name'] ?? 'Unknown Beer',
						'brewery' => $item['brewery_name'] ?? '',
						'venue'   => $item['venue_name'] ?? '',
						'date'    => isset( $item['timestamp'] ) ? wp_date( 'M j, Y g:i a', $item['timestamp'] ) : '',
					];
					break;

				case 'readwise_books':
					$formatted[] = [
						'title'      => $item['title'] ?? 'Unknown Book',
						'author'     => $item['author'] ?? '',
						'highlights' => $item['highlight_count'] ?? 0,
						'source'     => $item['source'] ?? '',
					];
					break;

				case 'readwise_articles':
					$formatted[] = [
						'title'      => $item['title'] ?? 'Unknown Article',
						'author'     => $item['author'] ?? '',
						'highlights' => $item['highlight_count'] ?? 0,
						'source'     => $item['source'] ?? '',
					];
					break;

				case 'readwise_podcasts':
					$formatted[] = [
						'title'      => $item['episode_title'] ?? $item['title'] ?? 'Unknown Episode',
						'show'       => $item['show_name'] ?? $item['author'] ?? '',
						'highlights' => $item['highlight_count'] ?? 0,
						'source'     => $item['source'] ?? 'Snipd',
					];
					break;

				case 'readwise_tweets':
					$formatted[] = [
						'title'      => $item['title'] ?? 'Tweet Thread',
						'author'     => $item['author'] ?? '',
						'highlights' => $item['highlight_count'] ?? 0,
					];
					break;

				case 'readwise_supplementals':
					$formatted[] = [
						'title'      => $item['title'] ?? 'Untitled',
						'type'       => $item['source'] ?? 'PDF',
						'highlights' => $item['highlight_count'] ?? 0,
					];
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
			return [];
		}

		$sanitized = [];

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

		// Process common import options.
		// Convert import_amount to limit.
		if ( isset( $sanitized['import_amount'] ) ) {
			$amount = $sanitized['import_amount'];
			if ( '1' === $amount ) {
				$sanitized['limit'] = 1;
			} elseif ( 'custom' === $amount && isset( $sanitized['custom_limit'] ) ) {
				$sanitized['limit'] = absint( $sanitized['custom_limit'] );
			}
			// 'all' = no limit set (uses source default or no limit).
			unset( $sanitized['import_amount'], $sanitized['custom_limit'] );
		}

		// Convert publish_immediately to post_status.
		// Default to 'draft' for safety.
		// Note: JavaScript sends boolean false as string "false" via AJAX,
		// so we need to check for truthy values, not just !empty().
		$publish = $sanitized['publish_immediately'] ?? false;
		if ( true === $publish || '1' === $publish || 'true' === $publish ) {
			$sanitized['post_status'] = 'publish';
		} else {
			$sanitized['post_status'] = 'draft';
		}
		unset( $sanitized['publish_immediately'] );

		return $sanitized;
	}

	/**
	 * AJAX handler: Re-sync metadata for imported posts.
	 *
	 * @return void
	 */
	public function ajax_resync_metadata(): void {
		check_ajax_referer( 'post_kinds_indieweb_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';

		if ( empty( $source ) ) {
			wp_send_json_error( [ 'message' => __( 'No import source specified.', 'post-kinds-for-indieweb' ) ] );
		}

		try {
			$import_manager = new Import_Manager();
			$result         = $import_manager->resync_metadata( $source );

			if ( empty( $result['success'] ) ) {
				wp_send_json_error( [ 'message' => $result['error'] ?? __( 'Re-sync failed.', 'post-kinds-for-indieweb' ) ] );
			}

			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}
}

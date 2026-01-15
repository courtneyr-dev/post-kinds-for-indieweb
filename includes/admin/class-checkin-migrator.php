<?php
/**
 * Checkin Migrator
 *
 * Admin tool for migrating check-ins between standard posts and the checkin CPT.
 *
 * @package PostKindsForIndieWeb
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Admin;

use PostKindsForIndieWeb\Checkin_Post_Type;
use PostKindsForIndieWeb\Venue_Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkin Migrator class.
 *
 * @since 1.2.0
 */
class Checkin_Migrator {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_post_migrate_checkins', [ $this, 'handle_migration' ] );
		add_action( 'admin_notices', [ $this, 'display_migration_notice' ] );
	}

	/**
	 * Add migration submenu page under Check-ins.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		if ( ! Checkin_Post_Type::is_enabled() ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . Checkin_Post_Type::POST_TYPE,
			__( 'Migrate Check-ins', 'post-kinds-for-indieweb' ),
			__( 'Migration', 'post-kinds-for-indieweb' ),
			'manage_options',
			'checkin-migration',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the migration page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$post_count = $this->count_checkin_posts();
		$cpt_count  = $this->count_checkin_cpt();
		$results    = get_transient( 'checkin_migration_results' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Check-in Migration', 'post-kinds-for-indieweb' ); ?></h1>

			<div class="card" style="max-width: 800px; margin-bottom: 20px;">
				<h2><?php esc_html_e( 'Current Status', 'post-kinds-for-indieweb' ); ?></h2>
				<table class="widefat" style="max-width: 400px;">
					<tbody>
						<tr>
							<td><?php esc_html_e( 'Standard posts with checkin kind:', 'post-kinds-for-indieweb' ); ?></td>
							<td><strong><?php echo esc_html( number_format_i18n( $post_count ) ); ?></strong></td>
						</tr>
						<tr>
							<td><?php esc_html_e( 'Check-in CPT entries:', 'post-kinds-for-indieweb' ); ?></td>
							<td><strong><?php echo esc_html( number_format_i18n( $cpt_count ) ); ?></strong></td>
						</tr>
					</tbody>
				</table>
			</div>

			<?php if ( $results ) : ?>
				<?php delete_transient( 'checkin_migration_results' ); ?>
				<div class="card" style="max-width: 800px; margin-bottom: 20px;">
					<h2><?php esc_html_e( 'Migration Results', 'post-kinds-for-indieweb' ); ?></h2>
					<?php if ( isset( $results['error'] ) ) : ?>
						<div class="notice notice-error inline">
							<p><?php echo esc_html( $results['error'] ); ?></p>
						</div>
					<?php else : ?>
						<p>
							<?php
							if ( $results['dry_run'] ) {
								printf(
									/* translators: %d: number of posts */
									esc_html__( 'Dry run complete. %d check-in(s) would be migrated.', 'post-kinds-for-indieweb' ),
									absint( $results['count'] )
								);
							} else {
								printf(
									/* translators: %d: number of posts */
									esc_html__( 'Migration complete. %d check-in(s) migrated.', 'post-kinds-for-indieweb' ),
									absint( $results['count'] )
								);
							}
							?>
						</p>
						<?php if ( ! empty( $results['migrated'] ) ) : ?>
							<details>
								<summary><?php esc_html_e( 'View details', 'post-kinds-for-indieweb' ); ?></summary>
								<ul style="margin-top: 10px;">
									<?php foreach ( $results['migrated'] as $item ) : ?>
										<li>
											<a href="<?php echo esc_url( get_edit_post_link( $item['id'] ) ); ?>">
												<?php echo esc_html( $item['title'] ? $item['title'] : __( '(no title)', 'post-kinds-for-indieweb' ) ); ?>
											</a>
											&mdash; <?php echo esc_html( $item['action'] ); ?>
											<?php if ( ! empty( $item['venue'] ) ) : ?>
												(<?php echo esc_html( $item['venue'] ); ?>)
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</details>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width: 800px;">
				<h2><?php esc_html_e( 'Run Migration', 'post-kinds-for-indieweb' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'migrate_checkins', 'checkin_migration_nonce' ); ?>
					<input type="hidden" name="action" value="migrate_checkins" />

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="direction"><?php esc_html_e( 'Direction', 'post-kinds-for-indieweb' ); ?></label>
								</th>
								<td>
									<select name="direction" id="direction">
										<option value="posts_to_cpt">
											<?php esc_html_e( 'Standard Posts → Check-in CPT', 'post-kinds-for-indieweb' ); ?>
										</option>
										<option value="cpt_to_posts">
											<?php esc_html_e( 'Check-in CPT → Standard Posts', 'post-kinds-for-indieweb' ); ?>
										</option>
									</select>
									<p class="description">
										<?php esc_html_e( 'Choose which way to migrate check-ins.', 'post-kinds-for-indieweb' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="batch_size"><?php esc_html_e( 'Batch Size', 'post-kinds-for-indieweb' ); ?></label>
								</th>
								<td>
									<input type="number" name="batch_size" id="batch_size" value="50" min="1" max="500" class="small-text" />
									<p class="description">
										<?php esc_html_e( 'Number of posts to process per batch. Lower values are safer for large sites.', 'post-kinds-for-indieweb' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Options', 'post-kinds-for-indieweb' ); ?></th>
								<td>
									<fieldset>
										<label>
											<input type="checkbox" name="dry_run" value="1" checked />
											<?php esc_html_e( 'Dry run (preview changes without migrating)', 'post-kinds-for-indieweb' ); ?>
										</label>
										<br /><br />
										<label>
											<input type="checkbox" name="create_venues" value="1" checked />
											<?php esc_html_e( 'Create venue terms from location data', 'post-kinds-for-indieweb' ); ?>
										</label>
									</fieldset>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Run Migration', 'post-kinds-for-indieweb' ), 'primary', 'submit', true ); ?>
				</form>
			</div>

			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2><?php esc_html_e( 'WP-CLI Commands', 'post-kinds-for-indieweb' ); ?></h2>
				<p><?php esc_html_e( 'You can also run migrations via WP-CLI for better performance on large sites:', 'post-kinds-for-indieweb' ); ?></p>
				<pre style="background: #f0f0f0; padding: 10px; overflow-x: auto;">
# Preview migration (dry run)
wp postkind migrate-checkins --direction=posts-to-cpt --dry-run

# Run actual migration
wp postkind migrate-checkins --direction=posts-to-cpt --batch=100

# Migrate back to standard posts
wp postkind migrate-checkins --direction=cpt-to-posts</pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle migration form submission.
	 *
	 * @return void
	 */
	public function handle_migration(): void {
		// Verify nonce.
		if ( ! isset( $_POST['checkin_migration_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['checkin_migration_nonce'] ) ), 'migrate_checkins' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'post-kinds-for-indieweb' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'post-kinds-for-indieweb' ) );
		}

		$direction     = isset( $_POST['direction'] ) ? sanitize_text_field( wp_unslash( $_POST['direction'] ) ) : 'posts_to_cpt';
		$batch_size    = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;
		$dry_run       = isset( $_POST['dry_run'] );
		$create_venues = isset( $_POST['create_venues'] );

		if ( 'posts_to_cpt' === $direction ) {
			$results = $this->migrate_posts_to_cpt( $batch_size, $dry_run, $create_venues );
		} else {
			$results = $this->migrate_cpt_to_posts( $batch_size, $dry_run );
		}

		// Store results for display.
		set_transient( 'checkin_migration_results', $results, 300 );

		// Redirect back to migration page.
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Checkin_Post_Type::POST_TYPE . '&page=checkin-migration&migrated=1' ) );
		exit;
	}

	/**
	 * Migrate standard posts with checkin kind to CPT.
	 *
	 * @param int  $batch_size    Number of posts to process.
	 * @param bool $dry_run       Whether to preview only.
	 * @param bool $create_venues Whether to create venue terms.
	 * @return array Migration results.
	 */
	public function migrate_posts_to_cpt( int $batch_size, bool $dry_run, bool $create_venues = true ): array {
		$checkin_term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );

		if ( ! $checkin_term ) {
			return [ 'error' => __( 'Checkin kind term not found. No posts to migrate.', 'post-kinds-for-indieweb' ) ];
		}

		$posts = get_posts(
			[
				'post_type'      => 'post',
				'posts_per_page' => $batch_size,
				'post_status'    => 'any',
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => 'indieblocks_kind',
						'field'    => 'term_id',
						'terms'    => $checkin_term->term_id,
					],
				],
			]
		);

		$migrated = [];

		foreach ( $posts as $post ) {
			$venue_name = get_post_meta( $post->ID, '_postkind_checkin_venue', true );
			$venue_info = '';

			if ( $dry_run ) {
				$migrated[] = [
					'id'     => $post->ID,
					'title'  => $post->post_title,
					'action' => __( 'would migrate to CPT', 'post-kinds-for-indieweb' ),
					'venue'  => $venue_name ? $venue_name : '',
				];
			} else {
				$venue_term_id = null;

				// Create venue term if enabled and location data exists.
				if ( $create_venues && $venue_name ) {
					$location_data = [
						'name'      => $venue_name,
						'address'   => get_post_meta( $post->ID, '_postkind_checkin_address', true ),
						'city'      => get_post_meta( $post->ID, '_postkind_checkin_city', true ),
						'region'    => get_post_meta( $post->ID, '_postkind_checkin_region', true ),
						'country'   => get_post_meta( $post->ID, '_postkind_checkin_country', true ),
						'latitude'  => get_post_meta( $post->ID, '_postkind_checkin_latitude', true ),
						'longitude' => get_post_meta( $post->ID, '_postkind_checkin_longitude', true ),
					];

					$venue_result = Venue_Taxonomy::create_or_get( $location_data );

					if ( ! is_wp_error( $venue_result ) ) {
						$venue_term_id = $venue_result;
						$venue_info    = $venue_name;
					}
				}

				// Change post type.
				wp_update_post(
					[
						'ID'        => $post->ID,
						'post_type' => Checkin_Post_Type::POST_TYPE,
					]
				);

				// Assign venue term.
				if ( $venue_term_id ) {
					wp_set_object_terms( $post->ID, $venue_term_id, Venue_Taxonomy::TAXONOMY );
				}

				$migrated[] = [
					'id'     => $post->ID,
					'title'  => $post->post_title,
					'action' => __( 'migrated to CPT', 'post-kinds-for-indieweb' ),
					'venue'  => $venue_info,
				];
			}
		}

		return [
			'migrated' => $migrated,
			'count'    => count( $migrated ),
			'dry_run'  => $dry_run,
		];
	}

	/**
	 * Migrate CPT check-ins back to standard posts.
	 *
	 * @param int  $batch_size Number of posts to process.
	 * @param bool $dry_run    Whether to preview only.
	 * @return array Migration results.
	 */
	public function migrate_cpt_to_posts( int $batch_size, bool $dry_run ): array {
		$posts = get_posts(
			[
				'post_type'      => Checkin_Post_Type::POST_TYPE,
				'posts_per_page' => $batch_size,
				'post_status'    => 'any',
			]
		);

		$checkin_term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );
		$migrated     = [];

		foreach ( $posts as $post ) {
			if ( $dry_run ) {
				$migrated[] = [
					'id'     => $post->ID,
					'title'  => $post->post_title,
					'action' => __( 'would migrate to standard post', 'post-kinds-for-indieweb' ),
					'venue'  => '',
				];
			} else {
				// Change post type back to post.
				wp_update_post(
					[
						'ID'        => $post->ID,
						'post_type' => 'post',
					]
				);

				// Assign checkin kind if term exists.
				if ( $checkin_term ) {
					wp_set_object_terms( $post->ID, $checkin_term->term_id, 'indieblocks_kind' );
				}

				$migrated[] = [
					'id'     => $post->ID,
					'title'  => $post->post_title,
					'action' => __( 'migrated to standard post', 'post-kinds-for-indieweb' ),
					'venue'  => '',
				];
			}
		}

		return [
			'migrated' => $migrated,
			'count'    => count( $migrated ),
			'dry_run'  => $dry_run,
		];
	}

	/**
	 * Count standard posts with checkin kind.
	 *
	 * @return int Post count.
	 */
	public function count_checkin_posts(): int {
		$checkin_term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );

		if ( ! $checkin_term ) {
			return 0;
		}

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'posts_per_page' => 1,
				'post_status'    => 'any',
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => 'indieblocks_kind',
						'field'    => 'term_id',
						'terms'    => $checkin_term->term_id,
					],
				],
			]
		);

		return $query->found_posts;
	}

	/**
	 * Count check-in CPT entries.
	 *
	 * @return int Post count.
	 */
	public function count_checkin_cpt(): int {
		$counts = wp_count_posts( Checkin_Post_Type::POST_TYPE );

		if ( ! $counts ) {
			return 0;
		}

		return (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 ) + (int) ( $counts->private ?? 0 );
	}

	/**
	 * Display migration notice after successful migration.
	 *
	 * @return void
	 */
	public function display_migration_notice(): void {
		$screen = get_current_screen();

		if ( ! $screen || false === strpos( $screen->id, 'checkin-migration' ) ) {
			return;
		}

		if ( isset( $_GET['migrated'] ) && '1' === $_GET['migrated'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Migration process completed. See results below.', 'post-kinds-for-indieweb' ); ?></p>
			</div>
			<?php
		}
	}
}

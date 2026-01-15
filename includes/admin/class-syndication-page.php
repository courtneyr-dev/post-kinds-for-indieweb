<?php
/**
 * Syndication Management Page
 *
 * Admin page to view posts that were skipped for syndication
 * and manually trigger syndication for them.
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Admin;

use PostKindsForIndieWeb\Meta_Fields;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syndication Page class.
 *
 * Manages the syndication admin page.
 *
 * @since 1.0.0
 */
class Syndication_Page {

	/**
	 * Parent admin instance.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Available syndication services.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $services = [];

	/**
	 * Constructor.
	 *
	 * @param Admin $admin Parent admin instance.
	 */
	public function __construct( Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Initialize the page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'wp_ajax_postkind_syndicate_now', [ $this, 'ajax_syndicate_now' ] );
	}

	/**
	 * Get available syndication services.
	 *
	 * @return array<string, array<string, mixed>> Services configuration.
	 */
	private function get_services(): array {
		if ( ! empty( $this->services ) ) {
			return $this->services;
		}

		$settings = get_option( 'post_kinds_indieweb_settings', [] );

		// Check Last.fm.
		if ( ! empty( $settings['listen_sync_to_lastfm'] ) ) {
			$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
			$lastfm      = $credentials['lastfm'] ?? [];

			if ( ! empty( $lastfm['session_key'] ) ) {
				$this->services['lastfm'] = [
					'name'     => 'Last.fm',
					'kind'     => 'listen',
					'meta_key' => Meta_Fields::PREFIX . 'syndicate_lastfm',
				];
			}
		}

		// Check Trakt.
		if ( ! empty( $settings['watch_sync_to_trakt'] ) ) {
			$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );
			$trakt       = $credentials['trakt'] ?? [];

			if ( ! empty( $trakt['access_token'] ) ) {
				$this->services['trakt'] = [
					'name'     => 'Trakt',
					'kind'     => 'watch',
					'meta_key' => Meta_Fields::PREFIX . 'syndicate_trakt',
				];
			}
		}

		return $this->services;
	}

	/**
	 * Handle admin actions (syndicate now).
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! isset( $_GET['action'] ) || 'syndicate_now' !== $_GET['action'] ) {
			return;
		}

		if ( ! isset( $_GET['page'] ) || 'post-kinds-indieweb-syndication' !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$service = isset( $_GET['service'] ) ? sanitize_key( $_GET['service'] ) : '';

		if ( ! $post_id || ! $service ) {
			return;
		}

		check_admin_referer( 'syndicate_now_' . $post_id );

		$result = $this->syndicate_post( $post_id, $service );

		if ( $result ) {
			add_settings_error(
				'post_kinds_syndication',
				'syndicated',
				sprintf(
					/* translators: %s: service name */
					__( 'Post successfully syndicated to %s.', 'post-kinds-for-indieweb' ),
					esc_html( $this->get_services()[ $service ]['name'] ?? $service )
				),
				'success'
			);
		} else {
			add_settings_error(
				'post_kinds_syndication',
				'syndication_failed',
				__( 'Syndication failed. Please check the post data and try again.', 'post-kinds-for-indieweb' ),
				'error'
			);
		}
	}

	/**
	 * AJAX handler for syndicate now action.
	 *
	 * @return void
	 */
	public function ajax_syndicate_now(): void {
		check_ajax_referer( 'post_kinds_syndicate_now', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'post-kinds-for-indieweb' ) ] );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$service = isset( $_POST['service'] ) ? sanitize_key( $_POST['service'] ) : '';

		if ( ! $post_id || ! $service ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'post-kinds-for-indieweb' ) ] );
		}

		$result = $this->syndicate_post( $post_id, $service );

		if ( $result ) {
			wp_send_json_success(
				[
					'message' => sprintf(
						/* translators: %s: service name */
						__( 'Syndicated to %s', 'post-kinds-for-indieweb' ),
						$this->get_services()[ $service ]['name'] ?? $service
					),
					'url'     => $result['url'] ?? '',
				]
			);
		} else {
			wp_send_json_error( [ 'message' => __( 'Syndication failed.', 'post-kinds-for-indieweb' ) ] );
		}
	}

	/**
	 * Syndicate a post to a service.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $service Service ID.
	 * @return array|false Result array or false on failure.
	 */
	private function syndicate_post( int $post_id, string $service ) {
		$services = $this->get_services();

		if ( ! isset( $services[ $service ] ) ) {
			return false;
		}

		$kind = $services[ $service ]['kind'];

		// Re-enable syndication for this post.
		$meta_key = $services[ $service ]['meta_key'];
		update_post_meta( $post_id, $meta_key, true );

		// Trigger syndication based on service.
		if ( 'lastfm' === $service && 'listen' === $kind ) {
			return $this->syndicate_to_lastfm( $post_id );
		}

		if ( 'trakt' === $service && 'watch' === $kind ) {
			return $this->syndicate_to_trakt( $post_id );
		}

		return false;
	}

	/**
	 * Syndicate a listen post to Last.fm.
	 *
	 * @param int $post_id Post ID.
	 * @return array|false Result array or false on failure.
	 */
	private function syndicate_to_lastfm( int $post_id ) {
		if ( ! class_exists( 'PostKindsForIndieWeb\\Sync\\Lastfm_Listen_Sync' ) ) {
			return false;
		}

		$sync = new \PostKindsForIndieWeb\Sync\Lastfm_Listen_Sync();

		// Get listen data from post.
		$prefix = Meta_Fields::PREFIX;
		$data   = [
			'track'     => get_post_meta( $post_id, $prefix . 'listen_track', true ),
			'artist'    => get_post_meta( $post_id, $prefix . 'listen_artist', true ),
			'album'     => get_post_meta( $post_id, $prefix . 'listen_album', true ),
			'timestamp' => get_post_time( 'U', true, $post_id ),
		];

		if ( empty( $data['track'] ) || empty( $data['artist'] ) ) {
			return false;
		}

		// Use reflection to call the protected syndicate method.
		$reflection = new \ReflectionClass( $sync );
		$method     = $reflection->getMethod( 'syndicate_listen' );
		$method->setAccessible( true );

		$result = $method->invoke( $sync, $post_id, $data );

		if ( $result && ! empty( $result['id'] ) ) {
			update_post_meta( $post_id, '_postkind_listen_lastfm_id', $result['id'] );
			if ( ! empty( $result['url'] ) ) {
				update_post_meta( $post_id, '_postkind_syndication_lastfm', $result['url'] );
			}
		}

		return $result;
	}

	/**
	 * Syndicate a watch post to Trakt.
	 *
	 * @param int $post_id Post ID.
	 * @return array|false Result array or false on failure.
	 */
	private function syndicate_to_trakt( int $post_id ) {
		if ( ! class_exists( 'PostKindsForIndieWeb\\Sync\\Trakt_Watch_Sync' ) ) {
			return false;
		}

		$sync = new \PostKindsForIndieWeb\Sync\Trakt_Watch_Sync();

		// Get watch data from post.
		$prefix = Meta_Fields::PREFIX;
		$data   = [
			'title'      => get_post_meta( $post_id, $prefix . 'watch_title', true ),
			'year'       => get_post_meta( $post_id, $prefix . 'watch_year', true ),
			'tmdb_id'    => get_post_meta( $post_id, $prefix . 'watch_tmdb_id', true ),
			'imdb_id'    => get_post_meta( $post_id, $prefix . 'watch_imdb_id', true ),
			'trakt_id'   => get_post_meta( $post_id, $prefix . 'watch_trakt_id', true ),
			'season'     => get_post_meta( $post_id, $prefix . 'watch_season', true ),
			'episode'    => get_post_meta( $post_id, $prefix . 'watch_episode', true ),
			'created_at' => get_the_date( 'c', $post_id ),
			'timestamp'  => get_post_time( 'U', true, $post_id ),
		];

		// Determine type.
		$data['type'] = ( ! empty( $data['season'] ) || ! empty( $data['episode'] ) ) ? 'episode' : 'movie';

		if ( empty( $data['title'] ) ) {
			return false;
		}

		// Use reflection to call the protected syndicate method.
		$reflection = new \ReflectionClass( $sync );
		$method     = $reflection->getMethod( 'syndicate_watch' );
		$method->setAccessible( true );

		$result = $method->invoke( $sync, $post_id, $data );

		if ( $result && ! empty( $result['id'] ) ) {
			update_post_meta( $post_id, '_postkind_watch_trakt_id', $result['id'] );
			if ( ! empty( $result['url'] ) ) {
				update_post_meta( $post_id, '_postkind_syndication_trakt', $result['url'] );
			}
		}

		return $result;
	}

	/**
	 * Get posts that were skipped for syndication.
	 *
	 * @param string $service Service ID.
	 * @return array<\WP_Post> Array of posts.
	 */
	private function get_skipped_posts( string $service ): array {
		$services = $this->get_services();

		if ( ! isset( $services[ $service ] ) ) {
			return [];
		}

		$kind     = $services[ $service ]['kind'];
		$meta_key = $services[ $service ]['meta_key'];

		// Get posts of this kind that have syndication disabled.
		$args = [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => $meta_key,
					'value'   => '0',
					'compare' => '=',
				],
			],
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'kind',
					'field'    => 'slug',
					'terms'    => $kind,
				],
			],
		];

		$query = new \WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Get posts that have been syndicated.
	 *
	 * @param string $service Service ID.
	 * @return array<\WP_Post> Array of posts.
	 */
	private function get_syndicated_posts( string $service ): array {
		$syndication_meta_key = '_postkind_syndication_' . $service;

		$args = [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => $syndication_meta_key,
					'compare' => 'EXISTS',
				],
			],
		];

		$query = new \WP_Query( $args );

		return $query->posts;
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render(): void {
		$services = $this->get_services();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only parameters for tab navigation.
		$current_service = isset( $_GET['service'] ) ? sanitize_key( $_GET['service'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View-only parameters for tab navigation.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'skipped';

		if ( empty( $current_service ) && ! empty( $services ) ) {
			$current_service = array_key_first( $services );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Syndication', 'post-kinds-for-indieweb' ); ?></h1>

			<?php settings_errors( 'post_kinds_syndication' ); ?>

			<?php if ( empty( $services ) ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %s: link to settings page */
							esc_html__( 'No syndication services are configured. %s to enable syndication.', 'post-kinds-for-indieweb' ),
							sprintf(
								'<a href="%s">%s</a>',
								esc_url( admin_url( 'admin.php?page=post-kinds-indieweb' ) ),
								esc_html__( 'Configure settings', 'post-kinds-for-indieweb' )
							)
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<nav class="nav-tab-wrapper">
					<?php foreach ( $services as $id => $service ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-syndication&service=' . $id ) ); ?>"
							class="nav-tab <?php echo $current_service === $id ? 'nav-tab-active' : ''; ?>">
							<?php echo esc_html( $service['name'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php if ( $current_service && isset( $services[ $current_service ] ) ) : ?>
					<div class="post-kinds-syndication-content" style="margin-top: 20px;">
						<ul class="subsubsub">
							<li>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-syndication&service=' . $current_service . '&tab=skipped' ) ); ?>"
									class="<?php echo 'skipped' === $current_tab ? 'current' : ''; ?>">
									<?php esc_html_e( 'Skipped', 'post-kinds-for-indieweb' ); ?>
								</a> |
							</li>
							<li>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-syndication&service=' . $current_service . '&tab=syndicated' ) ); ?>"
									class="<?php echo 'syndicated' === $current_tab ? 'current' : ''; ?>">
									<?php esc_html_e( 'Syndicated', 'post-kinds-for-indieweb' ); ?>
								</a>
							</li>
						</ul>
						<br class="clear">

						<?php if ( 'skipped' === $current_tab ) : ?>
							<?php $this->render_skipped_posts( $current_service ); ?>
						<?php else : ?>
							<?php $this->render_syndicated_posts( $current_service ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the skipped posts table.
	 *
	 * @param string $service Service ID.
	 * @return void
	 */
	private function render_skipped_posts( string $service ): void {
		$posts = $this->get_skipped_posts( $service );
		?>
		<h2><?php esc_html_e( 'Posts Skipped for Syndication', 'post-kinds-for-indieweb' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These posts have syndication disabled. Click "Syndicate Now" to send them to the service.', 'post-kinds-for-indieweb' ); ?>
		</p>

		<?php if ( empty( $posts ) ) : ?>
			<p><?php esc_html_e( 'No skipped posts found.', 'post-kinds-for-indieweb' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Title', 'post-kinds-for-indieweb' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Date', 'post-kinds-for-indieweb' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Content', 'post-kinds-for-indieweb' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'post-kinds-for-indieweb' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $posts as $post ) : ?>
						<?php $this->render_post_row( $post, $service, false ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the syndicated posts table.
	 *
	 * @param string $service Service ID.
	 * @return void
	 */
	private function render_syndicated_posts( string $service ): void {
		$posts = $this->get_syndicated_posts( $service );
		?>
		<h2><?php esc_html_e( 'Syndicated Posts', 'post-kinds-for-indieweb' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These posts have been syndicated to the service.', 'post-kinds-for-indieweb' ); ?>
		</p>

		<?php if ( empty( $posts ) ) : ?>
			<p><?php esc_html_e( 'No syndicated posts found.', 'post-kinds-for-indieweb' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Title', 'post-kinds-for-indieweb' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Date', 'post-kinds-for-indieweb' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Content', 'post-kinds-for-indieweb' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Syndication URL', 'post-kinds-for-indieweb' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $posts as $post ) : ?>
						<?php $this->render_post_row( $post, $service, true ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a single post row.
	 *
	 * @param \WP_Post $post       Post object.
	 * @param string   $service    Service ID.
	 * @param bool     $syndicated Whether this is a syndicated post.
	 * @return void
	 */
	private function render_post_row( \WP_Post $post, string $service, bool $syndicated ): void {
		$services = $this->get_services();
		$kind     = $services[ $service ]['kind'] ?? '';
		$prefix   = Meta_Fields::PREFIX;

		// Get content info based on kind.
		$content = '';
		if ( 'listen' === $kind ) {
			$track  = get_post_meta( $post->ID, $prefix . 'listen_track', true );
			$artist = get_post_meta( $post->ID, $prefix . 'listen_artist', true );
			if ( $track && $artist ) {
				$content = sprintf( '%s - %s', esc_html( $artist ), esc_html( $track ) );
			}
		} elseif ( 'watch' === $kind ) {
			$title = get_post_meta( $post->ID, $prefix . 'watch_title', true );
			$year  = get_post_meta( $post->ID, $prefix . 'watch_year', true );
			if ( $title ) {
				$content = $year ? sprintf( '%s (%s)', esc_html( $title ), esc_html( $year ) ) : esc_html( $title );
			}
		}

		$syndication_url = get_post_meta( $post->ID, '_postkind_syndication_' . $service, true );
		?>
		<tr>
			<td>
				<strong>
					<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
						<?php echo esc_html( get_the_title( $post ) ); ?>
					</a>
				</strong>
			</td>
			<td><?php echo esc_html( get_the_date( '', $post ) ); ?></td>
			<td><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped above. ?></td>
			<td>
				<?php if ( $syndicated && $syndication_url ) : ?>
					<a href="<?php echo esc_url( $syndication_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'View', 'post-kinds-for-indieweb' ); ?>
					</a>
				<?php elseif ( ! $syndicated ) : ?>
					<?php
					$syndicate_url = wp_nonce_url(
						add_query_arg(
							[
								'page'    => 'post-kinds-indieweb-syndication',
								'action'  => 'syndicate_now',
								'post_id' => $post->ID,
								'service' => $service,
							],
							admin_url( 'admin.php' )
						),
						'syndicate_now_' . $post->ID
					);
					?>
					<a href="<?php echo esc_url( $syndicate_url ); ?>" class="button button-small">
						<?php esc_html_e( 'Syndicate Now', 'post-kinds-for-indieweb' ); ?>
					</a>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}

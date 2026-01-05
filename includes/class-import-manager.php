<?php
/**
 * Import Manager
 *
 * Handles bulk imports from external services (scrobbles, watch history, reading lists).
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import Manager class.
 *
 * @since 1.0.0
 */
class Import_Manager {

	/**
	 * Import job option prefix.
	 *
	 * @var string
	 */
	private const JOB_PREFIX = 'reactions_import_job_';

	/**
	 * Supported import sources.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sources = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_sources();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		add_action( 'reactions_indieweb_process_import', array( $this, 'process_import_batch' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'cleanup_old_jobs' ) );
	}

	/**
	 * Register import sources.
	 *
	 * @return void
	 */
	private function register_sources(): void {
		$this->sources = array(
			'listenbrainz' => array(
				'name'        => 'ListenBrainz',
				'type'        => 'music',
				'kind'        => 'listen',
				'api_class'   => APIs\ListenBrainz::class,
				'fetch_method'=> 'get_listens',
				'batch_size'  => 100,
				'requires_auth' => true,
			),
			'lastfm' => array(
				'name'        => 'Last.fm',
				'type'        => 'music',
				'kind'        => 'listen',
				'api_class'   => APIs\LastFM::class,
				'fetch_method'=> 'get_recent_tracks',
				'batch_size'  => 200,
				'requires_auth' => false,
				'requires_username' => true,
			),
			'trakt_movies' => array(
				'name'        => 'Trakt Movies',
				'type'        => 'video',
				'kind'        => 'watch',
				'api_class'   => APIs\Trakt::class,
				'fetch_method'=> 'get_history',
				'fetch_args'  => array( 'movies' ),
				'batch_size'  => 100,
				'requires_auth' => true,
			),
			'trakt_shows' => array(
				'name'        => 'Trakt TV Shows',
				'type'        => 'video',
				'kind'        => 'watch',
				'api_class'   => APIs\Trakt::class,
				'fetch_method'=> 'get_history',
				'fetch_args'  => array( 'episodes' ),
				'batch_size'  => 100,
				'requires_auth' => true,
			),
			'simkl' => array(
				'name'        => 'Simkl',
				'type'        => 'video',
				'kind'        => 'watch',
				'api_class'   => APIs\Simkl::class,
				'fetch_method'=> 'get_history',
				'batch_size'  => 100,
				'requires_auth' => true,
			),
			'hardcover' => array(
				'name'        => 'Hardcover',
				'type'        => 'book',
				'kind'        => 'read',
				'api_class'   => APIs\Hardcover::class,
				'fetch_method'=> 'get_read_books',
				'batch_size'  => 50,
				'requires_auth' => true,
			),
		);

		/**
		 * Filter available import sources.
		 *
		 * @param array<string, array<string, mixed>> $sources Import sources.
		 */
		$this->sources = apply_filters( 'reactions_indieweb_import_sources', $this->sources );
	}

	/**
	 * Get available import sources.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_sources(): array {
		return $this->sources;
	}

	/**
	 * Start an import job.
	 *
	 * @param string               $source  Source identifier.
	 * @param array<string, mixed> $options Import options.
	 * @return array<string, mixed> Job info.
	 */
	public function start_import( string $source, array $options = array() ): array {
		if ( ! isset( $this->sources[ $source ] ) ) {
			return array(
				'success' => false,
				'error'   => 'Unknown import source: ' . $source,
			);
		}

		$source_config = $this->sources[ $source ];

		// Validate requirements.
		if ( $source_config['requires_auth'] ?? false ) {
			$api = $this->get_api_instance( $source_config['api_class'] );
			if ( method_exists( $api, 'is_authenticated' ) && ! $api->is_authenticated() ) {
				return array(
					'success' => false,
					'error'   => 'Authentication required for ' . $source_config['name'],
				);
			}
		}

		if ( ( $source_config['requires_username'] ?? false ) && empty( $options['username'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Username required for ' . $source_config['name'],
			);
		}

		// Create job.
		$job_id = wp_generate_uuid4();

		$job = array(
			'id'           => $job_id,
			'source'       => $source,
			'status'       => 'pending',
			'options'      => $options,
			'progress'     => 0,
			'total'        => 0,
			'imported'     => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'errors'       => array(),
			'created_at'   => time(),
			'updated_at'   => time(),
			'started_at'   => null,
			'completed_at' => null,
			'cursor'       => null,
		);

		$this->save_job( $job_id, $job );

		// Schedule first batch.
		wp_schedule_single_event( time(), 'reactions_indieweb_process_import', array( $job_id, $source ) );

		return array(
			'success' => true,
			'job_id'  => $job_id,
			'message' => 'Import started',
		);
	}

	/**
	 * Process an import batch.
	 *
	 * @param string $job_id Job ID.
	 * @param string $source Source identifier.
	 * @return void
	 */
	public function process_import_batch( string $job_id, string $source ): void {
		$job = $this->get_job( $job_id );

		if ( ! $job || 'cancelled' === $job['status'] ) {
			return;
		}

		$source_config = $this->sources[ $source ] ?? null;

		if ( ! $source_config ) {
			$this->update_job(
				$job_id,
				array(
					'status' => 'failed',
					'errors' => array( 'Unknown source: ' . $source ),
				)
			);
			return;
		}

		// Mark as running.
		if ( 'pending' === $job['status'] ) {
			$job['status']     = 'running';
			$job['started_at'] = time();
			$this->save_job( $job_id, $job );
		}

		try {
			$api = $this->get_api_instance( $source_config['api_class'] );

			// Fetch batch.
			$batch = $this->fetch_batch( $api, $source_config, $job );

			if ( empty( $batch['items'] ) ) {
				// No more items.
				$this->update_job(
					$job_id,
					array(
						'status'       => 'completed',
						'completed_at' => time(),
						'progress'     => 100,
					)
				);
				return;
			}

			// Process items.
			$result = $this->process_items( $batch['items'], $source_config, $job );

			// Update job.
			$job['imported']  += $result['imported'];
			$job['skipped']   += $result['skipped'];
			$job['failed']    += $result['failed'];
			$job['cursor']     = $batch['cursor'] ?? null;
			$job['updated_at'] = time();

			if ( ! empty( $result['errors'] ) ) {
				$job['errors'] = array_merge( $job['errors'], array_slice( $result['errors'], 0, 10 ) );
			}

			// Calculate progress.
			if ( $job['total'] > 0 ) {
				$job['progress'] = min( 100, round( ( $job['imported'] + $job['skipped'] + $job['failed'] ) / $job['total'] * 100 ) );
			}

			$this->save_job( $job_id, $job );

			// Schedule next batch if more items.
			if ( $batch['has_more'] ?? false ) {
				wp_schedule_single_event( time() + 2, 'reactions_indieweb_process_import', array( $job_id, $source ) );
			} else {
				$this->update_job(
					$job_id,
					array(
						'status'       => 'completed',
						'completed_at' => time(),
						'progress'     => 100,
					)
				);
			}
		} catch ( \Exception $e ) {
			$job['errors'][] = $e->getMessage();
			$job['status']   = 'failed';
			$this->save_job( $job_id, $job );
		}
	}

	/**
	 * Fetch a batch of items from the API.
	 *
	 * @param object               $api           API instance.
	 * @param array<string, mixed> $source_config Source configuration.
	 * @param array<string, mixed> $job           Job data.
	 * @return array<string, mixed> Batch result.
	 */
	private function fetch_batch( object $api, array $source_config, array $job ): array {
		$method = $source_config['fetch_method'];
		$args   = $source_config['fetch_args'] ?? array();
		$options = $job['options'] ?? array();
		$cursor = $job['cursor'];

		// Build arguments based on source.
		switch ( $source_config['api_class'] ) {
			case APIs\ListenBrainz::class:
				$username = $options['username'] ?? '';
				$max_ts   = $cursor ? (int) $cursor : 0;
				$result   = $api->$method( $username, $source_config['batch_size'], $max_ts );

				$has_more = count( $result ) >= $source_config['batch_size'];
				$new_cursor = ! empty( $result ) ? end( $result )['listened_at'] : null;

				return array(
					'items'    => $result,
					'has_more' => $has_more,
					'cursor'   => $new_cursor,
				);

			case APIs\LastFM::class:
				$username = $options['username'] ?? '';
				$page     = $cursor ? (int) $cursor : 1;
				$result   = $api->$method( $username, $source_config['batch_size'], $page );

				return array(
					'items'    => $result['tracks'] ?? array(),
					'has_more' => $page < ( $result['total_pages'] ?? 0 ),
					'cursor'   => $page + 1,
				);

			case APIs\Trakt::class:
				$type  = $args[0] ?? 'movies';
				$page  = $cursor ? (int) $cursor : 1;
				$result = $api->$method( $type, $page, $source_config['batch_size'] );

				return array(
					'items'    => $result['items'] ?? array(),
					'has_more' => count( $result['items'] ?? array() ) >= $source_config['batch_size'],
					'cursor'   => $page + 1,
				);

			case APIs\Simkl::class:
				$result = $api->$method();

				return array(
					'items'    => $result,
					'has_more' => false,
					'cursor'   => null,
				);

			case APIs\Hardcover::class:
				$result = $api->$method( $source_config['batch_size'] );

				return array(
					'items'    => $result,
					'has_more' => false,
					'cursor'   => null,
				);

			default:
				return array(
					'items'    => array(),
					'has_more' => false,
					'cursor'   => null,
				);
		}
	}

	/**
	 * Process imported items.
	 *
	 * @param array<int, array<string, mixed>> $items         Items to process.
	 * @param array<string, mixed>             $source_config Source configuration.
	 * @param array<string, mixed>             $job           Job data.
	 * @return array<string, mixed> Processing result.
	 */
	private function process_items( array $items, array $source_config, array $job ): array {
		$result = array(
			'imported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'errors'   => array(),
		);

		$options = $job['options'] ?? array();
		$create_posts = $options['create_posts'] ?? true;  // Default to creating posts.
		$skip_existing = $options['skip_existing'] ?? true;

		foreach ( $items as $item ) {
			try {
				// Check for duplicates.
				if ( $skip_existing && $this->item_exists( $item, $source_config ) ) {
					++$result['skipped'];
					continue;
				}

				if ( $create_posts ) {
					// Create a WordPress post.
					$post_id = $this->create_post_from_item( $item, $source_config );

					if ( is_wp_error( $post_id ) ) {
						++$result['failed'];
						$result['errors'][] = $post_id->get_error_message();
					} else {
						++$result['imported'];
					}
				} else {
					// Just log the import.
					$this->log_item( $item, $source_config );
					++$result['imported'];
				}
			} catch ( \Exception $e ) {
				++$result['failed'];
				$result['errors'][] = $e->getMessage();
			}
		}

		return $result;
	}

	/**
	 * Check if an item already exists.
	 *
	 * @param array<string, mixed> $item          Item data.
	 * @param array<string, mixed> $source_config Source configuration.
	 * @return bool
	 */
	private function item_exists( array $item, array $source_config ): bool {
		$kind = $source_config['kind'];

		// Build unique identifier based on type.
		switch ( $kind ) {
			case 'listen':
				$meta_key   = '_reactions_listen_track';
				$meta_value = $item['track'] ?? '';
				$date       = isset( $item['listened_at'] ) ? gmdate( 'Y-m-d', $item['listened_at'] ) : '';
				break;

			case 'watch':
				$meta_key   = '_reactions_watch_title';
				$meta_value = $item['title'] ?? '';
				$date       = isset( $item['watched_at'] ) ? gmdate( 'Y-m-d', strtotime( $item['watched_at'] ) ) : '';
				break;

			case 'read':
				$meta_key   = '_reactions_read_title';
				$meta_value = $item['title'] ?? '';
				$date       = '';
				break;

			default:
				return false;
		}

		if ( empty( $meta_value ) ) {
			return false;
		}

		$args = array(
			'post_type'      => $this->get_import_post_type(),
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => $meta_key,
					'value' => $meta_value,
				),
			),
			'fields'         => 'ids',
		);

		if ( $date ) {
			$args['date_query'] = array(
				array(
					'year'  => (int) gmdate( 'Y', strtotime( $date ) ),
					'month' => (int) gmdate( 'm', strtotime( $date ) ),
					'day'   => (int) gmdate( 'd', strtotime( $date ) ),
				),
			);
		}

		$query = new \WP_Query( $args );

		return $query->have_posts();
	}

	/**
	 * Create a WordPress post from an imported item.
	 *
	 * @param array<string, mixed> $item          Item data.
	 * @param array<string, mixed> $source_config Source configuration.
	 * @return int|\WP_Error Post ID or error.
	 */
	private function create_post_from_item( array $item, array $source_config ) {
		$kind = $source_config['kind'];

		// Build post data.
		$post_data = array(
			'post_type'   => $this->get_import_post_type(),
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		);

		$meta = array();

		switch ( $kind ) {
			case 'listen':
				$track  = $item['track'] ?? '';
				$artist = $item['artist'] ?? '';
				$album  = $item['album'] ?? '';

				$post_data['post_title']   = sprintf( 'Listened to %s', $track );
				$post_data['post_content'] = sprintf( '<!-- wp:paragraph --><p>Listened to "%s" by %s.</p><!-- /wp:paragraph -->', esc_html( $track ), esc_html( $artist ) );

				if ( isset( $item['listened_at'] ) ) {
					$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $item['listened_at'] );
					$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $item['listened_at'] );
				}

				$meta['_reactions_listen_track']  = $track;
				$meta['_reactions_listen_artist'] = $artist;
				$meta['_reactions_listen_album']  = $album;
				$meta['_reactions_listen_cover']  = $item['cover'] ?? '';
				$meta['_reactions_listen_mbid']   = $item['mbid'] ?? '';
				break;

			case 'watch':
				$title = $item['title'] ?? '';
				$type  = $item['type'] ?? 'movie';

				$post_data['post_title'] = sprintf( 'Watched %s', $title );
				$post_data['post_content'] = sprintf( '<!-- wp:paragraph --><p>Watched "%s".</p><!-- /wp:paragraph -->', esc_html( $title ) );

				if ( isset( $item['watched_at'] ) ) {
					$timestamp = is_numeric( $item['watched_at'] ) ? $item['watched_at'] : strtotime( $item['watched_at'] );
					$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
					$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
				}

				$meta['_reactions_watch_title']  = $title;
				$meta['_reactions_watch_type']   = $type;
				$meta['_reactions_watch_poster'] = $item['poster'] ?? '';
				$meta['_reactions_watch_tmdb']   = $item['tmdb_id'] ?? '';
				$meta['_reactions_watch_imdb']   = $item['imdb_id'] ?? '';

				if ( 'episode' === $type ) {
					$meta['_reactions_watch_show']    = $item['show']['title'] ?? '';
					$meta['_reactions_watch_season']  = $item['season'] ?? '';
					$meta['_reactions_watch_episode'] = $item['number'] ?? '';
				}
				break;

			case 'read':
				$title  = $item['title'] ?? '';
				$author = '';

				if ( isset( $item['authors'] ) && is_array( $item['authors'] ) ) {
					if ( isset( $item['authors'][0]['name'] ) ) {
						$author = $item['authors'][0]['name'];
					} elseif ( is_string( $item['authors'][0] ) ) {
						$author = $item['authors'][0];
					}
				}

				$post_data['post_title']   = sprintf( 'Read %s', $title );
				$post_data['post_content'] = sprintf( '<!-- wp:paragraph --><p>Finished reading "%s" by %s.</p><!-- /wp:paragraph -->', esc_html( $title ), esc_html( $author ) );

				if ( isset( $item['finished_at'] ) ) {
					$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', strtotime( $item['finished_at'] ) );
					$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $item['finished_at'] ) );
				}

				$meta['_reactions_read_title']  = $title;
				$meta['_reactions_read_author'] = $author;
				$meta['_reactions_read_cover']  = $item['cover'] ?? '';
				$meta['_reactions_read_isbn']   = $item['isbn'] ?? '';
				$meta['_reactions_read_status'] = 'finished';
				break;

			default:
				return new \WP_Error( 'invalid_kind', 'Unknown import kind: ' . $kind );
		}

		// Insert post.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set taxonomy term.
		wp_set_object_terms( $post_id, $kind, 'kind' );

		// Save meta.
		foreach ( $meta as $key => $value ) {
			if ( ! empty( $value ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		// Mark as imported.
		update_post_meta( $post_id, '_reactions_imported_from', $source_config['name'] );
		update_post_meta( $post_id, '_reactions_imported_at', time() );

		return $post_id;
	}

	/**
	 * Log an imported item without creating a post.
	 *
	 * @param array<string, mixed> $item          Item data.
	 * @param array<string, mixed> $source_config Source configuration.
	 * @return void
	 */
	private function log_item( array $item, array $source_config ): void {
		/**
		 * Action fired when an item is imported.
		 *
		 * @param array<string, mixed> $item          Imported item data.
		 * @param array<string, mixed> $source_config Source configuration.
		 */
		do_action( 'reactions_indieweb_item_imported', $item, $source_config );
	}

	/**
	 * Get the post type to use for imports based on settings.
	 *
	 * @return string Post type slug.
	 */
	private function get_import_post_type(): string {
		$settings     = get_option( 'reactions_indieweb_settings', array() );
		$storage_mode = $settings['import_storage_mode'] ?? 'standard';

		return 'cpt' === $storage_mode ? 'reaction' : 'post';
	}

	/**
	 * Get API instance.
	 *
	 * @param string $class_name API class name.
	 * @return object API instance.
	 */
	private function get_api_instance( string $class_name ): object {
		return new $class_name();
	}

	/**
	 * Get job data.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|null Job data.
	 */
	public function get_job( string $job_id ): ?array {
		$job = get_option( self::JOB_PREFIX . $job_id );
		return $job ?: null;
	}

	/**
	 * Save job data.
	 *
	 * @param string               $job_id Job ID.
	 * @param array<string, mixed> $job    Job data.
	 * @return void
	 */
	private function save_job( string $job_id, array $job ): void {
		update_option( self::JOB_PREFIX . $job_id, $job, false );
	}

	/**
	 * Update job data.
	 *
	 * @param string               $job_id  Job ID.
	 * @param array<string, mixed> $updates Updates to apply.
	 * @return void
	 */
	private function update_job( string $job_id, array $updates ): void {
		$job = $this->get_job( $job_id );

		if ( $job ) {
			$job = array_merge( $job, $updates );
			$job['updated_at'] = time();
			$this->save_job( $job_id, $job );
		}
	}

	/**
	 * Cancel an import job.
	 *
	 * @param string $job_id Job ID.
	 * @return bool Success.
	 */
	public function cancel_import( string $job_id ): bool {
		$job = $this->get_job( $job_id );

		if ( ! $job ) {
			return false;
		}

		$this->update_job(
			$job_id,
			array(
				'status'       => 'cancelled',
				'completed_at' => time(),
			)
		);

		return true;
	}

	/**
	 * Get job status.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|null Status info.
	 */
	public function get_status( string $job_id ): ?array {
		$job = $this->get_job( $job_id );

		if ( ! $job ) {
			return null;
		}

		return array(
			'id'           => $job['id'],
			'source'       => $job['source'],
			'status'       => $job['status'],
			'progress'     => $job['progress'],
			'imported'     => $job['imported'],
			'skipped'      => $job['skipped'],
			'failed'       => $job['failed'],
			'errors'       => array_slice( $job['errors'], 0, 5 ),
			'started_at'   => $job['started_at'],
			'completed_at' => $job['completed_at'],
			'elapsed'      => $job['started_at'] ? time() - $job['started_at'] : 0,
		);
	}

	/**
	 * Get all active jobs.
	 *
	 * @return array<int, array<string, mixed>> Active jobs.
	 */
	public function get_active_jobs(): array {
		global $wpdb;

		$prefix = $wpdb->esc_like( self::JOB_PREFIX );
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix . '%'
			)
		);

		$jobs = array();

		foreach ( $results as $row ) {
			$job = maybe_unserialize( $row->option_value );

			if ( in_array( $job['status'] ?? '', array( 'pending', 'running' ), true ) ) {
				$jobs[] = $job;
			}
		}

		return $jobs;
	}

	/**
	 * Cleanup old completed jobs.
	 *
	 * @return void
	 */
	public function cleanup_old_jobs(): void {
		global $wpdb;

		$prefix = $wpdb->esc_like( self::JOB_PREFIX );
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix . '%'
			)
		);

		$cutoff = time() - ( 7 * DAY_IN_SECONDS );

		foreach ( $results as $row ) {
			$job = maybe_unserialize( $row->option_value );

			if ( isset( $job['completed_at'] ) && $job['completed_at'] < $cutoff ) {
				delete_option( $row->option_name );
			}
		}
	}
}

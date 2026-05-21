<?php
/**
 * Import Manager
 *
 * Handles bulk imports from external services (scrobbles, watch history, reading lists).
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

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
	private const JOB_PREFIX = 'post_kinds_import_job_';

	/**
	 * Supported import sources.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $sources = [];

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
		add_action( 'post_kinds_indieweb_process_import', [ $this, 'process_import_batch' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'cleanup_old_jobs' ] );
	}

	/**
	 * Register import sources.
	 *
	 * @return void
	 */
	private function register_sources(): void {
		$this->sources = [
			'listenbrainz'           => [
				'name'          => 'ListenBrainz',
				'type'          => 'music',
				'kind'          => 'listen',
				'api_class'     => APIs\ListenBrainz::class,
				'fetch_method'  => 'get_listens',
				'batch_size'    => 100,
				'requires_auth' => true,
			],
			'lastfm'                 => [
				'name'              => 'Last.fm',
				'type'              => 'music',
				'kind'              => 'listen',
				'api_class'         => APIs\LastFM::class,
				'fetch_method'      => 'get_recent_tracks',
				'batch_size'        => 200,
				'requires_auth'     => false,
				'requires_username' => true,
			],
			'trakt_movies'           => [
				'name'          => 'Trakt Movies',
				'type'          => 'video',
				'kind'          => 'watch',
				'api_class'     => APIs\Trakt::class,
				'fetch_method'  => 'get_history',
				'fetch_args'    => [ 'movies' ],
				'batch_size'    => 100,
				'requires_auth' => true,
			],
			'trakt_shows'            => [
				'name'          => 'Trakt TV Shows',
				'type'          => 'video',
				'kind'          => 'watch',
				'api_class'     => APIs\Trakt::class,
				'fetch_method'  => 'get_history',
				'fetch_args'    => [ 'episodes' ],
				'batch_size'    => 100,
				'requires_auth' => true,
			],
			'simkl'                  => [
				'name'          => 'Simkl',
				'type'          => 'video',
				'kind'          => 'watch',
				'api_class'     => APIs\Simkl::class,
				'fetch_method'  => 'get_history',
				'batch_size'    => 100,
				'requires_auth' => true,
			],
			'hardcover'              => [
				'name'          => 'Hardcover',
				'type'          => 'book',
				'kind'          => 'read',
				'api_class'     => APIs\Hardcover::class,
				'fetch_method'  => 'get_read_books',
				'batch_size'    => 50,
				'requires_auth' => true,
			],
			'foursquare'             => [
				'name'          => 'Foursquare / Swarm',
				'type'          => 'location',
				'kind'          => 'checkin',
				'api_class'     => Sync\Foursquare_Checkin_Sync::class,
				'fetch_method'  => 'fetch_recent_checkins',
				'batch_size'    => 100,
				'requires_auth' => true,
			],
			'readwise_books'         => [
				'name'          => 'Readwise Books',
				'type'          => 'book',
				'kind'          => 'read',
				'api_class'     => APIs\Readwise::class,
				'fetch_method'  => 'get_books_with_highlights',
				'batch_size'    => 10, // Lower batch size - each book requires API calls for highlights.
				'requires_auth' => true,
			],
			'readwise_articles'      => [
				'name'          => 'Readwise Articles',
				'type'          => 'article',
				'kind'          => 'bookmark',
				'api_class'     => APIs\Readwise::class,
				'fetch_method'  => 'get_articles',
				'batch_size'    => 100,
				'requires_auth' => true,
			],
			'readwise_podcasts'      => [
				'name'          => 'Readwise Podcasts',
				'type'          => 'podcast',
				'kind'          => 'listen',
				'api_class'     => APIs\Readwise::class,
				'fetch_method'  => 'get_podcast_episodes',
				'batch_size'    => 10, // Lower batch size - each episode requires API calls for highlights.
				'requires_auth' => true,
			],
			'readwise_tweets'        => [
				'name'          => 'Readwise Tweets',
				'type'          => 'tweet',
				'kind'          => 'bookmark',
				'api_class'     => APIs\Readwise::class,
				'fetch_method'  => 'get_tweets',
				'batch_size'    => 100,
				'requires_auth' => true,
			],
			'readwise_supplementals' => [
				'name'          => 'Readwise Supplementals',
				'type'          => 'supplemental',
				'kind'          => 'note',
				'api_class'     => APIs\Readwise::class,
				'fetch_method'  => 'get_books',
				'fetch_args'    => [ 'supplementals' ],
				'batch_size'    => 100,
				'requires_auth' => true,
			],
		];

		/**
		 * Filter available import sources.
		 *
		 * @param array<string, array<string, mixed>> $sources Import sources.
		 */
		$this->sources = apply_filters( 'post_kinds_indieweb_import_sources', $this->sources );
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
	public function start_import( string $source, array $options = [] ): array {
		if ( ! isset( $this->sources[ $source ] ) ) {
			return [
				'success' => false,
				'error'   => 'Unknown import source: ' . $source,
			];
		}

		$source_config = $this->sources[ $source ];

		// Validate requirements.
		if ( $source_config['requires_auth'] ?? false ) {
			$api = $this->get_api_instance( $source_config['api_class'] );
			if ( method_exists( $api, 'is_authenticated' ) && ! $api->is_authenticated() ) {
				return [
					'success' => false,
					'error'   => 'Authentication required for ' . $source_config['name'],
				];
			}
		}

		if ( ( $source_config['requires_username'] ?? false ) && empty( $options['username'] ) ) {
			return [
				'success' => false,
				'error'   => 'Username required for ' . $source_config['name'],
			];
		}

		// Create job.
		$job_id = wp_generate_uuid4();

		$job = [
			'id'           => $job_id,
			'source'       => $source,
			'status'       => 'pending',
			'options'      => $options,
			'progress'     => 0,
			'total'        => 0,
			'imported'     => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'errors'       => [],
			'created_at'   => time(),
			'updated_at'   => time(),
			'started_at'   => null,
			'completed_at' => null,
			'cursor'       => null,
		];

		$this->save_job( $job_id, $job );

		// Schedule first batch.
		wp_schedule_single_event( time(), 'post_kinds_indieweb_process_import', [ $job_id, $source ] );

		return [
			'success' => true,
			'job_id'  => $job_id,
			'message' => 'Import started',
		];
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
				[
					'status' => 'failed',
					'errors' => [ 'Unknown source: ' . $source ],
				]
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
					[
						'status'       => 'completed',
						'completed_at' => time(),
						'progress'     => 100,
					]
				);
				return;
			}

			// Process items.
			$result = $this->process_items( $batch['items'], $source_config, $job );

			// Update job.
			$job['imported']  += $result['imported'];
			$job['updated']   += $result['updated'] ?? 0;
			$job['skipped']   += $result['skipped'];
			$job['failed']    += $result['failed'];
			$job['cursor']     = $batch['cursor'] ?? null;
			$job['updated_at'] = time();

			if ( ! empty( $result['errors'] ) ) {
				$job['errors'] = array_merge( $job['errors'], array_slice( $result['errors'], 0, 10 ) );
			}

			// Calculate progress.
			if ( $job['total'] > 0 ) {
				$job['progress'] = min( 100, round( ( $job['imported'] + $job['updated'] + $job['skipped'] + $job['failed'] ) / $job['total'] * 100 ) );
			}

			$this->save_job( $job_id, $job );

			// Check if we've reached the requested limit.
			$limit           = $job['options']['limit'] ?? 0;
			$total_processed = $job['imported'] + $job['updated'] + $job['skipped'] + $job['failed'];

			if ( $limit > 0 && $total_processed >= $limit ) {
				// Reached the limit, mark as completed.
				$this->update_job(
					$job_id,
					[
						'status'       => 'completed',
						'completed_at' => time(),
						'progress'     => 100,
					]
				);
				return;
			}

			// Schedule next batch if more items.
			if ( $batch['has_more'] ?? false ) {
				wp_schedule_single_event( time() + 2, 'post_kinds_indieweb_process_import', [ $job_id, $source ] );
			} else {
				$this->update_job(
					$job_id,
					[
						'status'       => 'completed',
						'completed_at' => time(),
						'progress'     => 100,
					]
				);
			}
		} catch ( \Exception $e ) {
			$job['errors'][] = $e->getMessage();
			$job['status']   = 'failed';
			$this->save_job( $job_id, $job );
		}
	}

	/**
	 * Get the sync start date for a source.
	 *
	 * @param string $source Source identifier.
	 * @return string|null ISO 8601 date string or null if no cutoff.
	 */
	private function get_sync_start_date( string $source ): ?string {
		$settings         = get_option( 'post_kinds_indieweb_settings', [] );
		$sync_start_dates = $settings['sync_start_dates'] ?? [];
		$date             = $sync_start_dates[ $source ] ?? '';
		return ! empty( $date ) ? $date : null;
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
		$method  = $source_config['fetch_method'];
		$args    = $source_config['fetch_args'] ?? [];
		$options = $job['options'] ?? [];
		$cursor  = $job['cursor'];
		$source  = $job['source'] ?? '';

		// Calculate batch size respecting import limit and global settings.
		$settings          = get_option( 'post_kinds_indieweb_settings', [] );
		$global_batch_size = (int) ( $settings['batch_size'] ?? 0 );
		$source_batch_size = $source_config['batch_size'];

		// Use global setting if set, otherwise use source-specific batch size.
		$base_batch_size = $global_batch_size > 0 ? min( $global_batch_size, $source_batch_size ) : $source_batch_size;

		$limit           = $options['limit'] ?? 0;
		$total_processed = ( $job['imported'] ?? 0 ) + ( $job['updated'] ?? 0 ) + ( $job['skipped'] ?? 0 ) + ( $job['failed'] ?? 0 );

		if ( $limit > 0 ) {
			$remaining  = $limit - $total_processed;
			$batch_size = min( $base_batch_size, max( 1, $remaining ) );
		} else {
			$batch_size = $base_batch_size;
		}

		// Get sync start date from options or settings.
		$date_from = $options['date_from'] ?? null;
		if ( ! $date_from ) {
			$date_from = $this->get_sync_start_date( $source );
		}

		// Build arguments based on source.
		switch ( $source_config['api_class'] ) {
			case APIs\ListenBrainz::class:
				$username = $options['username'] ?? '';
				$max_ts   = $cursor ? (int) $cursor : 0;
				// Apply min_ts from sync start date.
				$min_ts = $date_from ? (int) strtotime( $date_from ) : 0;
				$result = $api->$method( $username, $batch_size, $max_ts, $min_ts );

				// Stop if we've gone past the min_ts cutoff.
				$has_more   = count( $result ) >= $batch_size;
				$new_cursor = ! empty( $result ) ? end( $result )['listened_at'] : null;

				// If min_ts is set and new_cursor is before it, stop pagination.
				if ( $min_ts > 0 && $new_cursor && $new_cursor <= $min_ts ) {
					$has_more = false;
				}

				return [
					'items'    => $result,
					'has_more' => $has_more,
					'cursor'   => $new_cursor,
				];

			case APIs\LastFM::class:
				$username = $options['username'] ?? '';
				$page     = $cursor ? (int) $cursor : 1;
				$result   = $api->$method( $username, $batch_size, $page );

				return [
					'items'    => $result['tracks'] ?? [],
					'has_more' => $page < ( $result['total_pages'] ?? 0 ),
					'cursor'   => $page + 1,
				];

			case APIs\Trakt::class:
				$type   = $args[0] ?? 'movies';
				$page   = $cursor ? (int) $cursor : 1;
				$result = $api->$method( $type, $page, $batch_size );

				return [
					'items'    => $result['items'] ?? [],
					'has_more' => count( $result['items'] ?? [] ) >= $batch_size,
					'cursor'   => $page + 1,
				];

			case APIs\Simkl::class:
				$result = $api->$method();

				return [
					'items'    => $result,
					'has_more' => false,
					'cursor'   => null,
				];

			case APIs\Hardcover::class:
				$result = $api->$method( $source_config['batch_size'] );

				return [
					'items'    => $result,
					'has_more' => false,
					'cursor'   => null,
				];

			case Sync\Foursquare_Checkin_Sync::class:
				$limit  = (int) ( $options['limit'] ?? $source_config['batch_size'] );
				$result = $api->$method( $limit );

				return [
					'items'    => $result,
					'has_more' => false,
					'cursor'   => null,
				];

			case APIs\Readwise::class:
				$limit      = (int) ( $options['limit'] ?? $source_config['batch_size'] );
				$fetch_args = $source_config['fetch_args'] ?? [];

				// Different Readwise methods have different signatures:
				// - get_podcast_episodes: (limit, include_highlights, updated_after).
				// - get_articles, get_tweets, get_book_highlights: (limit, updated_after).
				// - get_books_with_highlights: (limit, include_highlights, updated_after).
				if ( 'get_podcast_episodes' === $method || 'get_books_with_highlights' === $method ) {
					$result = $api->$method( $limit, true, $date_from );
				} elseif ( ! empty( $fetch_args ) ) {
					// For supplementals, pass category as first arg.
					$result = $api->$method( $fetch_args[0], $limit, $date_from );
				} else {
					// get_articles, get_tweets, get_book_highlights.
					$result = $api->$method( $limit, $date_from );
				}

				return [
					'items'    => $result,
					'has_more' => false,
					'cursor'   => null,
				];

			default:
				return [
					'items'    => [],
					'has_more' => false,
					'cursor'   => null,
				];
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
		$result = [
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'errors'   => [],
		];

		$options         = $job['options'] ?? [];
		$create_posts    = $options['create_posts'] ?? true;  // Default to creating posts.
		$skip_existing   = $options['skip_existing'] ?? true;
		$update_existing = $options['update_existing'] ?? false; // Update metadata on existing posts.

		foreach ( $items as $item ) {
			try {
				// Check for existing post.
				$existing_post_id = $this->find_existing_post( $item, $source_config );

				if ( $existing_post_id ) {
					// Post already exists.
					if ( $update_existing ) {
						// Update metadata on the existing post.
						$updated = $this->update_post_metadata( $existing_post_id, $item, $source_config );
						if ( $updated ) {
							++$result['updated'];
						} else {
							++$result['skipped'];
						}
					} elseif ( $skip_existing ) {
						++$result['skipped'];
					}
					continue;
				}

				if ( $create_posts ) {
					// Create a WordPress post.
					$post_id = $this->create_post_from_item( $item, $source_config, $options );

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
		// Use cite_name as primary key since it's populated for all kinds.
		switch ( $kind ) {
			case 'listen':
				$meta_key = '_postkind_cite_name';
				// Handle both music tracks and podcast episodes.
				// Use array_key_exists because isset() returns false for null values.
				if ( array_key_exists( 'episode_title', $item ) ) {
					$meta_value = $item['episode_title'] ?? $item['title'] ?? '';
				} elseif ( array_key_exists( 'track', $item ) ) {
					$meta_value = $item['track'] ?? '';
				} else {
					$meta_value = $item['title'] ?? '';
				}
				$date = isset( $item['listened_at'] ) ? gmdate( 'Y-m-d', $item['listened_at'] ) : '';
				break;

			case 'watch':
				$meta_key   = '_postkind_cite_name';
				$meta_value = $item['title'] ?? '';
				$date       = isset( $item['watched_at'] ) ? gmdate( 'Y-m-d', strtotime( $item['watched_at'] ) ) : '';
				break;

			case 'read':
				$meta_key   = '_postkind_cite_name';
				$meta_value = $item['title'] ?? '';
				$date       = '';
				break;

			case 'checkin':
				$meta_key   = '_postkind_checkin_name';
				$meta_value = $item['venue_name'] ?? '';
				$date       = isset( $item['timestamp'] ) ? gmdate( 'Y-m-d', $item['timestamp'] ) : '';
				break;

			case 'bookmark':
				$meta_key   = '_postkind_cite_url';
				$meta_value = $item['source_url'] ?? '';
				$date       = '';
				break;

			case 'note':
				$meta_key   = '_postkind_cite_name';
				$meta_value = $item['title'] ?? '';
				$date       = '';
				break;

			default:
				return false;
		}

		if ( empty( $meta_value ) ) {
			return false;
		}

		$args = [
			'post_type'      => $this->get_import_post_type(),
			'posts_per_page' => 1,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => $meta_key,
					'value' => $meta_value,
				],
			],
			'fields'         => 'ids',
		];

		if ( $date ) {
			$args['date_query'] = [
				[
					'year'  => (int) gmdate( 'Y', strtotime( $date ) ),
					'month' => (int) gmdate( 'm', strtotime( $date ) ),
					'day'   => (int) gmdate( 'd', strtotime( $date ) ),
				],
			];
		}

		$query = new \WP_Query( $args );

		return $query->have_posts();
	}

	/**
	 * Find existing post ID for an item.
	 *
	 * @param array<string, mixed> $item          Item data.
	 * @param array<string, mixed> $source_config Source configuration.
	 * @return int|null Post ID or null if not found.
	 */
	private function find_existing_post( array $item, array $source_config ): ?int {
		$kind = $source_config['kind'];

		// Build unique identifier based on type.
		$post_title = '';
		switch ( $kind ) {
			case 'listen':
				$meta_key = '_postkind_cite_name';
				if ( array_key_exists( 'episode_title', $item ) ) {
					$meta_value = $item['episode_title'] ?? $item['title'] ?? '';
					$post_title = sprintf( 'Listened to %s', $meta_value );
				} elseif ( array_key_exists( 'track', $item ) ) {
					$meta_value = $item['track'] ?? '';
					$post_title = sprintf( 'Listened to %s', $meta_value );
				} else {
					$meta_value = $item['title'] ?? '';
					$post_title = sprintf( 'Listened to %s', $meta_value );
				}
				// Support both music (listened_at) and podcasts (last_highlight).
				$date = '';
				if ( isset( $item['listened_at'] ) ) {
					$date = gmdate( 'Y-m-d', $item['listened_at'] );
				} elseif ( isset( $item['last_highlight'] ) && ! empty( $item['last_highlight'] ) ) {
					$date = gmdate( 'Y-m-d', strtotime( $item['last_highlight'] ) );
				}
				break;

			case 'watch':
				$meta_key   = '_postkind_cite_name';
				$meta_value = $item['title'] ?? '';
				$post_title = sprintf( 'Watched %s', $meta_value );
				$date       = isset( $item['watched_at'] ) ? gmdate( 'Y-m-d', strtotime( $item['watched_at'] ) ) : '';
				break;

			case 'read':
				$meta_key   = '_postkind_cite_name';
				$meta_value = $item['title'] ?? '';
				$post_title = sprintf( 'Read %s', $meta_value );
				$date       = '';
				break;

			default:
				return null;
		}

		if ( empty( $meta_value ) ) {
			return null;
		}

		// First try to find by meta key (most accurate).
		$args = [
			'post_type'      => $this->get_import_post_type(),
			'posts_per_page' => 1,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => $meta_key,
					'value' => $meta_value,
				],
			],
			'fields'         => 'ids',
		];

		if ( $date ) {
			$args['date_query'] = [
				[
					'year'  => (int) gmdate( 'Y', strtotime( $date ) ),
					'month' => (int) gmdate( 'm', strtotime( $date ) ),
					'day'   => (int) gmdate( 'd', strtotime( $date ) ),
				],
			];
		}

		$query = new \WP_Query( $args );

		if ( $query->have_posts() ) {
			return $query->posts[0];
		}

		// Fallback: search by post title (for posts imported before meta was added).
		if ( ! empty( $post_title ) ) {
			$title_args = [
				'post_type'      => $this->get_import_post_type(),
				'posts_per_page' => 1,
				'title'          => $post_title,
				'fields'         => 'ids',
			];

			if ( $date ) {
				$title_args['date_query'] = $args['date_query'];
			}

			$title_query = new \WP_Query( $title_args );

			if ( $title_query->have_posts() ) {
				return $title_query->posts[0];
			}
		}

		return null;
	}

	/**
	 * Update metadata for an existing post.
	 *
	 * @param int                  $post_id       Post ID.
	 * @param array<string, mixed> $item          Item data.
	 * @param array<string, mixed> $source_config Source configuration.
	 * @return bool True if updated.
	 */
	public function update_post_metadata( int $post_id, array $item, array $source_config ): bool {
		$kind = $source_config['kind'];
		$meta = [];

		switch ( $kind ) {
			case 'listen':
				if ( array_key_exists( 'episode_title', $item ) || array_key_exists( 'show_name', $item ) ) {
					// Podcast episode.
					$episode    = $item['episode_title'] ?? $item['title'] ?? '';
					$show       = $item['show_name'] ?? $item['author'] ?? '';
					$source_url = $item['source_url'] ?? '';

					$meta['_postkind_cite_name']       = $episode;
					$meta['_postkind_cite_author']     = $show;
					$meta['_postkind_cite_photo']      = $item['cover_image'] ?? '';
					$meta['_postkind_cite_url']        = $source_url;
					$meta['_postkind_listen_track']    = $episode;
					$meta['_postkind_listen_artist']   = $show;
					$meta['_postkind_listen_album']    = $show;
					$meta['_postkind_listen_cover']    = $item['cover_image'] ?? '';
					$meta['_postkind_listen_url']      = $source_url;
					$meta['_postkind_source']          = $item['source'] ?? 'Snipd';
					$meta['_postkind_highlight_count'] = $item['highlight_count'] ?? 0;
				} else {
					// Music track.
					$track  = $item['track'] ?? '';
					$artist = $item['artist'] ?? '';
					$album  = $item['album'] ?? '';

					$meta['_postkind_cite_name']     = $track;
					$meta['_postkind_cite_author']   = $artist;
					$meta['_postkind_listen_track']  = $track;
					$meta['_postkind_listen_artist'] = $artist;
					$meta['_postkind_listen_album']  = $album;
					$meta['_postkind_listen_cover']  = $item['cover'] ?? '';
					$meta['_postkind_listen_mbid']   = $item['mbid'] ?? '';
				}
				break;

			case 'watch':
				$title = $item['title'] ?? '';
				$year  = $item['year'] ?? '';

				$meta['_postkind_cite_name']     = $title;
				$meta['_postkind_cite_photo']    = $item['poster'] ?? '';
				$meta['_postkind_watch_title']   = $title;
				$meta['_postkind_watch_year']    = $year;
				$meta['_postkind_watch_poster']  = $item['poster'] ?? '';
				$meta['_postkind_watch_tmdb_id'] = $item['tmdb_id'] ?? '';
				$meta['_postkind_watch_status']  = 'watched';
				break;

			case 'read':
				$title  = $item['title'] ?? '';
				$author = $item['author'] ?? '';
				$asin   = $item['asin'] ?? '';

				$meta['_postkind_cite_name']       = $title;
				$meta['_postkind_cite_author']     = $author;
				$meta['_postkind_cite_photo']      = $item['cover_image'] ?? $item['cover'] ?? '';
				$meta['_postkind_cite_url']        = $item['source_url'] ?? '';
				$meta['_postkind_read_title']      = $title;
				$meta['_postkind_read_author']     = $author;
				$meta['_postkind_read_cover']      = $item['cover_image'] ?? $item['cover'] ?? '';
				$meta['_postkind_read_isbn']       = $item['isbn'] ?? $asin;
				$meta['_postkind_read_asin']       = $asin;
				$meta['_postkind_read_status']     = 'finished';
				$meta['_postkind_source']          = $item['source'] ?? '';
				$meta['_postkind_highlight_count'] = $item['highlight_count'] ?? 0;
				break;

			default:
				return false;
		}

		// Save metadata - track how many fields actually changed.
		$updated_count = 0;
		foreach ( $meta as $key => $value ) {
			// Skip truly empty values (null, empty string), but keep 0 and false.
			if ( '' === $value || null === $value ) {
				continue;
			}
			$old_value = get_post_meta( $post_id, $key, true );
			if ( $old_value !== $value ) {
				update_post_meta( $post_id, $key, $value );
				++$updated_count;
			}
		}

		// Mark as updated if any fields changed.
		if ( $updated_count > 0 ) {
			update_post_meta( $post_id, '_postkind_metadata_updated', time() );
		}

		return $updated_count > 0;
	}

	/**
	 * Create a WordPress post from an imported item.
	 *
	 * @param array<string, mixed> $item          Item data.
	 * @param array<string, mixed> $source_config Source configuration.
	 * @param array<string, mixed> $options       Job options (post_status, etc.).
	 * @return int|\WP_Error Post ID or error.
	 */
	private function create_post_from_item( array $item, array $source_config, array $options = [] ) {
		$kind = $source_config['kind'];

		// Build post data. Default to draft for safety - user must explicitly choose to publish.
		$post_data = [
			'post_type'   => $this->get_import_post_type(),
			'post_status' => $options['post_status'] ?? 'draft',
			'post_author' => get_current_user_id(),
		];

		// Dispatch to per-kind builder. Each builder returns a triple of
		// [post_data_extras, meta, post_format] that this method merges
		// into the base post payload before inserting.
		$built = $this->build_post_payload_for_kind( $kind, $item );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		[ $post_data_extras, $meta, $post_format ] = $built;
		$post_data                                 = array_merge( $post_data, $post_data_extras );

		// Insert post.
		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set taxonomy term.
		wp_set_object_terms( $post_id, $kind, 'kind' );

		// Set post format if specified (audio for podcasts, video for movies, etc.).
		if ( ! empty( $post_format ) ) {
			set_post_format( $post_id, $post_format );
		}

		// Save meta - skip truly empty values but keep 0 and false.
		foreach ( $meta as $key => $value ) {
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		// Mark as imported.
		update_post_meta( $post_id, '_postkind_imported_from', $source_config['name'] );
		update_post_meta( $post_id, '_postkind_imported_at', time() );

		return $post_id;
	}

	/**
	 * Dispatch to the per-kind post-payload builder.
	 *
	 * Each builder returns `[post_data_extras, meta, post_format]`:
	 *   - post_data_extras: kind-specific fields merged into the base
	 *     wp_insert_post payload (post_title, post_content, post_date,
	 *     post_date_gmt).
	 *   - meta: post meta keyed by `_postkind_*` keys, written after the
	 *     post is inserted.
	 *   - post_format: '' / 'audio' / 'video' — applied via
	 *     `set_post_format()` after insert.
	 *
	 * @param string               $kind Kind slug from source_config.
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}|\WP_Error Triple or error for unknown kind.
	 */
	private function build_post_payload_for_kind( string $kind, array $item ) {
		switch ( $kind ) {
			case 'listen':
				return $this->build_listen_payload( $item );
			case 'watch':
				return $this->build_watch_payload( $item );
			case 'read':
				return $this->build_read_payload( $item );
			case 'checkin':
				return $this->build_checkin_payload( $item );
			case 'bookmark':
				return $this->build_bookmark_payload( $item );
			case 'note':
				return $this->build_note_payload( $item );
			default:
				return new \WP_Error( 'invalid_kind', 'Unknown import kind: ' . $kind );
		}
	}

	/**
	 * Build payload for `listen` kind. Routes to podcast vs music sub-builder
	 * based on whether the item carries podcast-shaped fields.
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_listen_payload( array $item ): array {
		// Use array_key_exists because isset() returns false for null values.
		$is_podcast = array_key_exists( 'episode_title', $item ) || array_key_exists( 'show_name', $item );
		return $is_podcast
			? $this->build_podcast_listen_payload( $item )
			: $this->build_music_listen_payload( $item );
	}

	/**
	 * Build payload for a podcast episode listen (Readwise/Snipd source).
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_podcast_listen_payload( array $item ): array {
		$episode    = $item['episode_title'] ?? $item['title'] ?? '';
		$show       = $item['show_name'] ?? $item['author'] ?? '';
		$source_url = $item['source_url'] ?? '';
		$highlights = $item['highlights'] ?? [];

		$content_parts   = [];
		$content_parts[] = sprintf(
			'<!-- wp:paragraph --><p>Listened to "%s" from %s.</p><!-- /wp:paragraph -->',
			esc_html( $episode ),
			esc_html( $show )
		);

		if ( ! empty( $highlights ) ) {
			$content_parts[] = '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Highlights from this episode</h3><!-- /wp:heading -->';
			$content_parts   = array_merge( $content_parts, $this->build_highlight_blocks( $highlights ) );

			if ( ! empty( $source_url ) ) {
				$content_parts[] = sprintf(
					'<!-- wp:paragraph --><p><a href="%s">View highlights on Snipd</a></p><!-- /wp:paragraph -->',
					esc_url( $source_url )
				);
			}
		}

		$post_data = [
			'post_title'   => sprintf( 'Listened to %s', $episode ),
			'post_content' => implode( "\n\n", $content_parts ),
		];

		$post_data = array_merge(
			$post_data,
			$this->extract_post_dates( $item, [ 'last_highlight' ], false )
		);

		$meta = [
			// Citation fields for Post Kind editor.
			'_postkind_cite_name'   => $episode,
			'_postkind_cite_author' => $show,
			'_postkind_cite_photo'  => $item['cover_image'] ?? '',
			'_postkind_cite_url'    => $source_url,
			// Listen-specific fields.
			'_postkind_listen_track'  => $episode,
			'_postkind_listen_artist' => $show,
			'_postkind_listen_album'  => $show, // Show name as album for podcasts.
			'_postkind_listen_cover'  => $item['cover_image'] ?? '',
			'_postkind_listen_url'    => $source_url,
			// Import tracking.
			'_postkind_source'          => $item['source'] ?? 'Snipd',
			'_postkind_highlight_count' => $item['highlight_count'] ?? 0,
		];

		return [ $post_data, $meta, 'audio' ];
	}

	/**
	 * Build payload for a music-track listen (Last.fm/ListenBrainz source).
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_music_listen_payload( array $item ): array {
		$track  = $item['track'] ?? '';
		$artist = $item['artist'] ?? '';
		$album  = $item['album'] ?? '';

		$content_parts   = [];
		$content_parts[] = sprintf(
			'<!-- wp:paragraph --><p>Listened to "%s" by %s.</p><!-- /wp:paragraph -->',
			esc_html( $track ),
			esc_html( $artist )
		);

		// Optional embed when the user has configured a music service.
		$settings     = get_option( 'post_kinds_indieweb_settings', [] );
		$embed_source = $settings['listen_embed_source'] ?? 'none';
		$embed_url    = '';

		if ( 'none' !== $embed_source ) {
			$embed_block = $this->get_music_embed_block( $embed_source, $track, $artist, $album );
			if ( $embed_block ) {
				$content_parts[] = $embed_block;
				$embed_url       = $this->get_music_service_url( $embed_source, $track, $artist, $album );
			}
		}

		$post_data = [
			'post_title'   => sprintf( 'Listened to %s', $track ),
			'post_content' => implode( "\n\n", $content_parts ),
		];

		// `listened_at` from the upstream service is already a unix
		// timestamp (Last.fm/ListenBrainz convention) — pass through
		// without strtotime.
		if ( isset( $item['listened_at'] ) ) {
			$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $item['listened_at'] );
			$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $item['listened_at'] );
		}

		$meta = [
			// Citation fields for Post Kind editor.
			'_postkind_cite_name'   => $track,
			'_postkind_cite_author' => $artist,
			// Listen-specific fields.
			'_postkind_listen_track'  => $track,
			'_postkind_listen_artist' => $artist,
			'_postkind_listen_album'  => $album,
			'_postkind_listen_cover'  => $item['cover'] ?? '',
			'_postkind_listen_mbid'   => $item['mbid'] ?? '',
			'_postkind_listen_url'    => $embed_url,
		];

		return [ $post_data, $meta, 'audio' ];
	}

	/**
	 * Build payload for `watch` kind.
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_watch_payload( array $item ): array {
		$title = $item['title'] ?? '';
		$year  = $item['year'] ?? '';
		$type  = $item['type'] ?? 'movie';

		$post_data = [
			'post_title'   => sprintf( 'Watched %s', $title ),
			'post_content' => sprintf(
				'<!-- wp:paragraph --><p>Watched "%s".</p><!-- /wp:paragraph -->',
				esc_html( $title )
			),
		];

		// `watched_at` may be unix timestamp OR ISO string depending on source.
		if ( isset( $item['watched_at'] ) ) {
			$timestamp                  = is_numeric( $item['watched_at'] ) ? $item['watched_at'] : strtotime( $item['watched_at'] );
			$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
			$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$meta = [
			// Citation fields for Post Kind editor.
			'_postkind_cite_name'  => $title,
			'_postkind_cite_photo' => $item['poster'] ?? '',
			// Watch-specific fields.
			'_postkind_watch_title'   => $title,
			'_postkind_watch_year'    => $year,
			'_postkind_watch_poster'  => $item['poster'] ?? '',
			'_postkind_watch_tmdb_id' => $item['tmdb_id'] ?? '',
			'_postkind_watch_status'  => 'watched',
			// Legacy field names for compatibility.
			'_postkind_watch_type'  => $type,
			'_postkind_watch_tmdb'  => $item['tmdb_id'] ?? '',
			'_postkind_watch_imdb'  => $item['imdb_id'] ?? '',
			'_postkind_watch_trakt' => $item['trakt_id'] ?? '',
		];

		// Episode/TV-specific fields when the type warrants them.
		if ( 'episode' === $type || 'tv' === $type ) {
			$meta['_postkind_watch_show']    = $item['show']['title'] ?? '';
			$meta['_postkind_watch_season']  = $item['season'] ?? '';
			$meta['_postkind_watch_episode'] = $item['number'] ?? '';
		}

		return [ $post_data, $meta, 'video' ];
	}

	/**
	 * Build payload for `read` kind.
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_read_payload( array $item ): array {
		$title  = $item['title'] ?? '';
		$author = $this->normalize_author_field( $item );
		$asin   = $item['asin'] ?? '';

		$content_parts = [
			sprintf(
				'<!-- wp:paragraph --><p>Finished reading "%s" by %s.</p><!-- /wp:paragraph -->',
				esc_html( $title ),
				esc_html( $author )
			),
		];

		if ( ! empty( $asin ) ) {
			$content_parts[] = $this->build_kindle_embed_block( $asin );
		}

		$highlights = $item['highlights'] ?? [];
		if ( ! empty( $highlights ) ) {
			$content_parts[] = $this->build_highlights_details_block( $highlights );
		}

		$post_data = [
			'post_title'   => sprintf( 'Read %s', $title ),
			'post_content' => implode( "\n\n", $content_parts ),
		];

		$post_data = array_merge(
			$post_data,
			$this->extract_post_dates( $item, [ 'finished_at', 'last_highlight_at' ], true )
		);

		$meta = [
			// Citation fields for Post Kind editor.
			'_postkind_cite_name'   => $title,
			'_postkind_cite_author' => $author,
			'_postkind_cite_photo'  => $item['cover_image'] ?? $item['cover'] ?? '',
			'_postkind_cite_url'    => $item['source_url'] ?? '',
			// Read-specific fields.
			'_postkind_read_title'  => $title,
			'_postkind_read_author' => $author,
			'_postkind_read_cover'  => $item['cover_image'] ?? $item['cover'] ?? '',
			'_postkind_read_isbn'   => $item['isbn'] ?? $item['asin'] ?? '',
			'_postkind_read_asin'   => $asin,
			'_postkind_read_status' => 'finished',
			// Import tracking.
			'_postkind_source'          => $item['source'] ?? '',
			'_postkind_source_url'      => $item['source_url'] ?? '',
			'_postkind_highlight_count' => $item['highlight_count'] ?? 0,
		];

		return [ $post_data, $meta, '' ];
	}

	/**
	 * Build payload for `checkin` kind.
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_checkin_payload( array $item ): array {
		$venue   = $item['venue_name'] ?? 'Unknown Venue';
		$address = $item['address'] ?? '';

		$post_data = [
			'post_title'   => sprintf( 'Checked in at %s', $venue ),
			'post_content' => sprintf(
				'<!-- wp:paragraph --><p>Checked in at %s.</p><!-- /wp:paragraph -->',
				esc_html( $venue )
			),
		];

		// `timestamp` from Foursquare/OwnTracks is a unix timestamp.
		if ( isset( $item['timestamp'] ) ) {
			$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $item['timestamp'] );
			$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $item['timestamp'] );
		}

		$meta = [
			// Checkin-specific fields for Post Kind editor.
			'_postkind_checkin_name'    => $venue,
			'_postkind_checkin_address' => $address,
			'_postkind_geo_latitude'    => $item['latitude'] ?? '',
			'_postkind_geo_longitude'   => $item['longitude'] ?? '',
			// Legacy/internal fields.
			'_postkind_checkin_venue'     => $venue,
			'_postkind_checkin_latitude'  => $item['latitude'] ?? '',
			'_postkind_checkin_longitude' => $item['longitude'] ?? '',
			'_postkind_checkin_venue_id'  => $item['venue_id'] ?? '',
			'_postkind_checkin_shout'     => $item['shout'] ?? '',
		];

		return [ $post_data, $meta, '' ];
	}

	/**
	 * Build payload for `bookmark` kind.
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_bookmark_payload( array $item ): array {
		$title  = $item['title'] ?? 'Untitled';
		$author = $item['author'] ?? '';
		$url    = $item['source_url'] ?? '';

		$post_data = [
			'post_title'   => sprintf( 'Bookmarked: %s', $title ),
			'post_content' => sprintf(
				'<!-- wp:paragraph --><p>Bookmarked "%s".</p><!-- /wp:paragraph -->',
				esc_html( $title )
			),
		];

		$post_data = array_merge(
			$post_data,
			$this->extract_post_dates( $item, [ 'last_highlight_at' ], true )
		);

		$meta = [
			// Citation fields for Post Kind editor.
			'_postkind_cite_name'   => $title,
			'_postkind_cite_author' => $author,
			'_postkind_cite_url'    => $url,
			'_postkind_cite_photo'  => $item['cover_image'] ?? '',
			// Import tracking.
			'_postkind_source'          => $item['source'] ?? '',
			'_postkind_highlight_count' => $item['highlight_count'] ?? 0,
		];

		return [ $post_data, $meta, '' ];
	}

	/**
	 * Build payload for `note` kind.
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
	 */
	private function build_note_payload( array $item ): array {
		$title = $item['title'] ?? 'Untitled';

		$post_data = [
			'post_title'   => $title,
			'post_content' => sprintf(
				'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
				esc_html( $item['document_note'] ?? '' )
			),
		];

		$post_data = array_merge(
			$post_data,
			$this->extract_post_dates( $item, [ 'last_highlight_at' ], true )
		);

		$meta = [
			// Citation fields for Post Kind editor.
			'_postkind_cite_name' => $title,
			'_postkind_cite_url'  => $item['source_url'] ?? '',
			// Import tracking.
			'_postkind_source'          => $item['source'] ?? '',
			'_postkind_highlight_count' => $item['highlight_count'] ?? 0,
		];

		return [ $post_data, $meta, '' ];
	}

	/**
	 * Pull a post date out of the item using the first key from $candidates
	 * that's present and non-empty. Returns a partial post_data array with
	 * post_date / post_date_gmt set, or empty array when no candidate matched.
	 *
	 * @param array<string, mixed> $item       Imported item data.
	 * @param array<int, string>   $candidates Item keys to try in order.
	 * @param bool                 $use_strtotime Pass true to run strtotime() on the value (ISO strings); false treats it as a unix timestamp.
	 * @return array<string, string>
	 */
	private function extract_post_dates( array $item, array $candidates, bool $use_strtotime ): array {
		foreach ( $candidates as $key ) {
			if ( ! isset( $item[ $key ] ) || empty( $item[ $key ] ) ) {
				continue;
			}
			$timestamp = $use_strtotime ? strtotime( $item[ $key ] ) : (int) $item[ $key ];
			if ( $timestamp ) {
				return [
					'post_date'     => gmdate( 'Y-m-d H:i:s', $timestamp ),
					'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				];
			}
		}
		return [];
	}

	/**
	 * Authors arrive in `$item['author']` as a string, or in `$item['authors']`
	 * as an array of strings or `[ ['name' => …], … ]`. Normalize to a single
	 * string, preferring `author` and falling back to `authors[0]`.
	 *
	 * @param array<string, mixed> $item Imported item data.
	 * @return string
	 */
	private function normalize_author_field( array $item ): string {
		$author = $item['author'] ?? '';
		if ( ! empty( $author ) ) {
			return (string) $author;
		}
		if ( ! isset( $item['authors'] ) || ! is_array( $item['authors'] ) || empty( $item['authors'] ) ) {
			return '';
		}
		$first = $item['authors'][0];
		if ( is_array( $first ) && isset( $first['name'] ) ) {
			return (string) $first['name'];
		}
		return is_string( $first ) ? $first : '';
	}

	/**
	 * Build a Kindle embed block markup for an ASIN.
	 *
	 * @param string $asin Amazon ASIN.
	 * @return string Block markup.
	 */
	private function build_kindle_embed_block( string $asin ): string {
		return sprintf(
			'<!-- wp:embed {"url":"https://read.amazon.com/kp/card?asin=%s","type":"rich","providerNameSlug":"amazon-kindle","responsive":true} -->' .
			'<figure class="wp-block-embed is-type-rich is-provider-amazon-kindle wp-block-embed-amazon-kindle">' .
			'<div class="wp-block-embed__wrapper">https://read.amazon.com/kp/card?asin=%s</div>' .
			'</figure><!-- /wp:embed -->',
			esc_attr( $asin ),
			esc_attr( $asin )
		);
	}

	/**
	 * Build the inner highlight blocks (quote + optional note paragraph).
	 *
	 * Used by the podcast listen builder, which spreads these into its
	 * content_parts. The read builder uses {@see build_highlights_details_block()}
	 * which wraps the same blocks in a collapsible details element.
	 *
	 * @param array<int, array<string, mixed>> $highlights Highlight items.
	 * @return array<int, string> Block markup parts.
	 */
	private function build_highlight_blocks( array $highlights ): array {
		$parts = [];
		foreach ( $highlights as $highlight ) {
			$text = $highlight['text'] ?? '';
			$note = $highlight['note'] ?? '';
			if ( empty( $text ) ) {
				continue;
			}
			$parts[] = sprintf(
				'<!-- wp:quote --><blockquote class="wp-block-quote"><p>%s</p></blockquote><!-- /wp:quote -->',
				esc_html( $text )
			);
			if ( ! empty( $note ) ) {
				$parts[] = sprintf(
					'<!-- wp:paragraph {"className":"highlight-note"} --><p class="highlight-note"><em>%s</em></p><!-- /wp:paragraph -->',
					esc_html( $note )
				);
			}
		}
		return $parts;
	}

	/**
	 * Wrap highlight blocks in a collapsible details block with a count summary.
	 *
	 * @param array<int, array<string, mixed>> $highlights Highlight items.
	 * @return string Block markup.
	 */
	private function build_highlights_details_block( array $highlights ): string {
		$inner = implode( '', $this->build_highlight_blocks( $highlights ) );
		return sprintf(
			'<!-- wp:details --><details class="wp-block-details"><summary>Highlights (%d)</summary>%s</details><!-- /wp:details -->',
			count( $highlights ),
			$inner
		);
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
		do_action( 'post_kinds_indieweb_item_imported', $item, $source_config );
	}

	/**
	 * Get the post type to use for imports based on settings.
	 *
	 * @return string Post type slug.
	 */
	private function get_import_post_type(): string {
		$settings     = get_option( 'post_kinds_indieweb_settings', [] );
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
			$job               = array_merge( $job, $updates );
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
			[
				'status'       => 'cancelled',
				'completed_at' => time(),
			]
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

		return [
			'id'           => $job['id'],
			'source'       => $job['source'],
			'status'       => $job['status'],
			'progress'     => $job['progress'],
			'imported'     => $job['imported'],
			'updated'      => $job['updated'] ?? 0,
			'skipped'      => $job['skipped'],
			'failed'       => $job['failed'],
			'errors'       => array_slice( $job['errors'], 0, 5 ),
			'started_at'   => $job['started_at'],
			'completed_at' => $job['completed_at'],
			'elapsed'      => $job['started_at'] ? time() - $job['started_at'] : 0,
		];
	}

	/**
	 * Get all active jobs.
	 *
	 * @return array<int, array<string, mixed>> Active jobs.
	 */
	public function get_active_jobs(): array {
		global $wpdb;

		$prefix = $wpdb->esc_like( self::JOB_PREFIX );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic option lookup for import jobs.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix . '%'
			)
		);

		$jobs = [];

		foreach ( $results as $row ) {
			$job = maybe_unserialize( $row->option_value );

			if ( in_array( $job['status'] ?? '', [ 'pending', 'running' ], true ) ) {
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic option lookup for job cleanup.
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

	/**
	 * Re-sync metadata for imported posts.
	 *
	 * Updates existing imported posts with fresh metadata from the source API.
	 *
	 * @param string $source Import source (e.g., 'trakt_movies', 'trakt_shows').
	 * @return array<string, mixed> Results with counts.
	 */
	public function resync_metadata( string $source ): array {
		if ( ! isset( $this->sources[ $source ] ) ) {
			return [
				'success' => false,
				'error'   => __( 'Invalid import source.', 'post-kinds-for-indieweb' ),
			];
		}

		$source_config = $this->sources[ $source ];
		$kind          = $source_config['kind'];

		// Find posts imported from this source.
		$posts = get_posts(
			[
				'post_type'      => [ 'post', 'reaction' ],
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_postkind_imported_from',
						'value'   => $source_config['name'],
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
			]
		);

		if ( empty( $posts ) ) {
			return [
				'success' => true,
				'updated' => 0,
				'skipped' => 0,
				'message' => __( 'No imported posts found to re-sync.', 'post-kinds-for-indieweb' ),
			];
		}

		$updated = 0;
		$skipped = 0;

		foreach ( $posts as $post ) {
			$result = $this->resync_post_metadata( $post, $kind, $source );

			if ( $result ) {
				++$updated;
			} else {
				++$skipped;
			}
		}

		return [
			'success' => true,
			'updated' => $updated,
			'skipped' => $skipped,
			'message' => sprintf(
				/* translators: 1: Updated count, 2: Skipped count */
				__( 'Re-synced %1$d posts, skipped %2$d.', 'post-kinds-for-indieweb' ),
				$updated,
				$skipped
			),
		];
	}

	/**
	 * Re-sync metadata for a single post.
	 *
	 * @param \WP_Post $post   Post object.
	 * @param string   $kind   Post kind.
	 * @param string   $source Import source.
	 * @return bool True if updated, false if skipped.
	 */
	private function resync_post_metadata( \WP_Post $post, string $kind, string $source ): bool {
		switch ( $kind ) {
			case 'watch':
				return $this->resync_watch_metadata( $post, $source );

			case 'listen':
				return $this->resync_listen_metadata( $post, $source );

			case 'read':
				return $this->resync_read_metadata( $post, $source );

			default:
				return false;
		}
	}

	/**
	 * Re-sync watch metadata for a post.
	 *
	 * @param \WP_Post $post   Post object.
	 * @param string   $source Import source.
	 * @return bool True if updated.
	 */
	private function resync_watch_metadata( \WP_Post $post, string $source ): bool {
		$title    = get_post_meta( $post->ID, '_postkind_watch_title', true );
		$trakt_id = get_post_meta( $post->ID, '_postkind_watch_trakt', true );
		$tmdb_id  = get_post_meta( $post->ID, '_postkind_watch_tmdb', true );
		$imdb_id  = get_post_meta( $post->ID, '_postkind_watch_imdb', true );

		// If we don't have identifiers, try to look up by title.
		if ( empty( $trakt_id ) && empty( $tmdb_id ) && empty( $imdb_id ) && empty( $title ) ) {
			return false;
		}

		// Try to fetch fresh data from Trakt.
		if ( str_starts_with( $source, 'trakt' ) ) {
			$api = new APIs\Trakt();

			if ( ! $api->is_configured() ) {
				return false;
			}

			$type = 'trakt_movies' === $source ? 'movie' : 'show';

			// Search by title if we don't have a Trakt ID.
			if ( empty( $trakt_id ) && ! empty( $title ) ) {
				$results = $api->search( $title, $type );

				if ( ! empty( $results ) ) {
					$item     = $results[0];
					$trakt_id = $item['trakt_id'] ?? '';
				}
			}

			// If we have a Trakt ID, fetch full details.
			if ( ! empty( $trakt_id ) ) {
				$details = 'movie' === $type
					? $api->get_movie( (string) $trakt_id )
					: $api->get_show( (string) $trakt_id );

				if ( ! is_wp_error( $details ) && ! empty( $details ) ) {
					// Update metadata.
					update_post_meta( $post->ID, '_postkind_watch_title', $details['title'] ?? $title );
					update_post_meta( $post->ID, '_postkind_watch_year', $details['year'] ?? '' );
					update_post_meta( $post->ID, '_postkind_watch_type', $details['type'] ?? $type );
					update_post_meta( $post->ID, '_postkind_watch_trakt', $details['trakt_id'] ?? $trakt_id );
					update_post_meta( $post->ID, '_postkind_watch_tmdb', $details['tmdb_id'] ?? '' );
					update_post_meta( $post->ID, '_postkind_watch_imdb', $details['imdb_id'] ?? '' );
					update_post_meta( $post->ID, '_postkind_watch_status', 'watched' );
					update_post_meta( $post->ID, '_postkind_resynced_at', time() );

					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Re-sync listen metadata for a post.
	 *
	 * @param \WP_Post $post   Post object.
	 * @param string   $source Import source.
	 * @return bool True if updated.
	 */
	private function resync_listen_metadata( \WP_Post $post, string $source ): bool {
		// Listen posts typically have all metadata from the initial import.
		// Mark as re-synced but no API lookup needed.
		update_post_meta( $post->ID, '_postkind_resynced_at', time() );
		return true;
	}

	/**
	 * Re-sync read metadata for a post.
	 *
	 * @param \WP_Post $post   Post object.
	 * @param string   $source Import source.
	 * @return bool True if updated.
	 */
	private function resync_read_metadata( \WP_Post $post, string $source ): bool {
		// Read posts typically have all metadata from the initial import.
		// Mark as re-synced but no API lookup needed.
		update_post_meta( $post->ID, '_postkind_resynced_at', time() );
		return true;
	}

	/**
	 * Get a music service URL for embedding.
	 *
	 * Generates a search URL for the specified music service.
	 * Note: Search URLs generally don't embed - use get_music_embed_block() for embeds.
	 *
	 * @param string $service Service identifier (spotify, apple_music, youtube, etc.).
	 * @param string $track   Track name.
	 * @param string $artist  Artist name.
	 * @param string $album   Album name (optional).
	 * @return string URL for the music service, or empty string if not supported.
	 */
	private function get_music_service_url( string $service, string $track, string $artist, string $album = '' ): string {
		if ( empty( $track ) || empty( $artist ) ) {
			return '';
		}

		// Build a search query.
		$search_query = $track . ' ' . $artist;

		switch ( $service ) {
			case 'spotify':
				// Spotify search URL.
				return 'https://open.spotify.com/search/' . rawurlencode( $search_query );

			case 'apple_music':
				// Apple Music search URL.
				return 'https://music.apple.com/us/search?term=' . rawurlencode( $search_query );

			case 'youtube':
				// YouTube search URL.
				return 'https://www.youtube.com/results?search_query=' . rawurlencode( $search_query );

			case 'bandcamp':
				// Bandcamp search URL.
				return 'https://bandcamp.com/search?q=' . rawurlencode( $search_query );

			case 'soundcloud':
				// SoundCloud search URL.
				return 'https://soundcloud.com/search?q=' . rawurlencode( $search_query );

			default:
				return '';
		}
	}

	/**
	 * Get a music embed block for the specified service.
	 *
	 * Generates proper WordPress embed blocks using service-specific block types.
	 * Since we don't have direct track IDs, we generate search links that users
	 * can later replace with direct track URLs for proper embedding.
	 *
	 * @param string $service Service identifier (spotify, apple_music, youtube, etc.).
	 * @param string $track   Track name.
	 * @param string $artist  Artist name.
	 * @param string $album   Album name (optional).
	 * @return string WordPress block markup, or empty string if not supported.
	 */
	private function get_music_embed_block( string $service, string $track, string $artist, string $album = '' ): string {
		if ( empty( $track ) || empty( $artist ) ) {
			return '';
		}

		$search_query = $track . ' ' . $artist;

		switch ( $service ) {
			case 'spotify':
				// Use the Spotify embed block with a search link.
				// WordPress will recognize this as a Spotify embed when the URL is a track/album URL.
				// For search URLs, it creates a clickable link that users can replace.
				$url = 'https://open.spotify.com/search/' . rawurlencode( $search_query );
				return sprintf(
					'<!-- wp:embed {"url":"%s","type":"rich","providerNameSlug":"spotify","responsive":true,"className":"wp-embed-aspect-21-9 wp-has-aspect-ratio"} -->' .
					'<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify wp-embed-aspect-21-9 wp-has-aspect-ratio">' .
					'<div class="wp-block-embed__wrapper">' .
					'<a href="%s">🎵 Find "%s" by %s on Spotify</a>' .
					'</div>' .
					'<figcaption class="wp-element-caption">Replace this link with a Spotify track URL for an embedded player</figcaption>' .
					'</figure>' .
					'<!-- /wp:embed -->',
					esc_url( $url ),
					esc_url( $url ),
					esc_html( $track ),
					esc_html( $artist )
				);

			case 'apple_music':
				// Apple Music embed - requires direct track URL for embedding.
				$url = 'https://music.apple.com/us/search?term=' . rawurlencode( $search_query );
				return sprintf(
					'<!-- wp:embed {"url":"%s","type":"rich","providerNameSlug":"apple-music","responsive":true} -->' .
					'<figure class="wp-block-embed is-type-rich is-provider-apple-music wp-block-embed-apple-music">' .
					'<div class="wp-block-embed__wrapper">' .
					'<a href="%s">🎵 Find "%s" by %s on Apple Music</a>' .
					'</div>' .
					'<figcaption class="wp-element-caption">Replace this link with an Apple Music track URL for an embedded player</figcaption>' .
					'</figure>' .
					'<!-- /wp:embed -->',
					esc_url( $url ),
					esc_url( $url ),
					esc_html( $track ),
					esc_html( $artist )
				);

			case 'youtube':
				// YouTube embed - search URL won't embed, but direct video URLs will.
				$url = 'https://www.youtube.com/results?search_query=' . rawurlencode( $search_query . ' official audio' );
				return sprintf(
					'<!-- wp:embed {"url":"%s","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->' .
					'<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio">' .
					'<div class="wp-block-embed__wrapper">' .
					'<a href="%s">🎵 Find "%s" by %s on YouTube</a>' .
					'</div>' .
					'<figcaption class="wp-element-caption">Replace this link with a YouTube video URL for an embedded player</figcaption>' .
					'</figure>' .
					'<!-- /wp:embed -->',
					esc_url( $url ),
					esc_url( $url ),
					esc_html( $track ),
					esc_html( $artist )
				);

			case 'bandcamp':
				// Bandcamp - search URL provided, user can replace with track URL.
				$url = 'https://bandcamp.com/search?q=' . rawurlencode( $search_query );
				return sprintf(
					'<!-- wp:embed {"url":"%s","type":"rich","providerNameSlug":"bandcamp","responsive":true} -->' .
					'<figure class="wp-block-embed is-type-rich is-provider-bandcamp wp-block-embed-bandcamp">' .
					'<div class="wp-block-embed__wrapper">' .
					'<a href="%s">🎵 Find "%s" by %s on Bandcamp</a>' .
					'</div>' .
					'<figcaption class="wp-element-caption">Replace this link with a Bandcamp track URL for an embedded player</figcaption>' .
					'</figure>' .
					'<!-- /wp:embed -->',
					esc_url( $url ),
					esc_url( $url ),
					esc_html( $track ),
					esc_html( $artist )
				);

			case 'soundcloud':
				// SoundCloud embed.
				$url = 'https://soundcloud.com/search?q=' . rawurlencode( $search_query );
				return sprintf(
					'<!-- wp:embed {"url":"%s","type":"rich","providerNameSlug":"soundcloud","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->' .
					'<figure class="wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud wp-embed-aspect-16-9 wp-has-aspect-ratio">' .
					'<div class="wp-block-embed__wrapper">' .
					'<a href="%s">🎵 Find "%s" by %s on SoundCloud</a>' .
					'</div>' .
					'<figcaption class="wp-element-caption">Replace this link with a SoundCloud track URL for an embedded player</figcaption>' .
					'</figure>' .
					'<!-- /wp:embed -->',
					esc_url( $url ),
					esc_url( $url ),
					esc_html( $track ),
					esc_html( $artist )
				);

			default:
				return '';
		}
	}
}

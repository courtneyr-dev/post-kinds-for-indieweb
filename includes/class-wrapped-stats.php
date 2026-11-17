<?php
/**
 * Yearly Wrapped aggregation and invalidation.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Compute and cache public per-kind yearly statistics. */
final class Wrapped_Stats {
	// Public callback signatures document their input types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Maximum cached top rows. */
	public const TOP_MAX = 12;

	/** Minimum contributing rows for sensitive derived metrics. */
	public const MIN_SAMPLE = 3;

	private const SCHEMA = 1;
	private const GEN    = 'pkiw_wrapped_gen';

	/**
	 * Metric definitions and every stored source suffix they read.
	 *
	 * @var array<string, array{main: string, top: bool, metrics: array<int, array{key: string, date_key: ?string, source: array<int, string>, status: array<string, string>}>}>
	 */
	private const REGISTRY = [
		'read'     => [
			'main'    => 'finished',
			'top'     => true,
			'metrics' => [
				[
					'key'      => 'finished',
					'date_key' => 'read_finished_at',
					'source'   => [],
					'status'   => [ 'read_status' => 'finished' ],
				],
				[
					'key'      => 'pages',
					'date_key' => 'read_finished_at',
					'source'   => [ 'read_pages' ],
					'status'   => [ 'read_status' => 'finished' ],
				],
				[
					'key'      => 'streak',
					'date_key' => null,
					'source'   => [],
					'status'   => [],
				],
				[
					'key'      => 'average_rating',
					'date_key' => 'read_finished_at',
					'source'   => [ 'read_rating' ],
					'status'   => [ 'read_status' => 'finished' ],
				],
			],
		],
		'listen'   => [
			'main'    => 'listens',
			'top'     => false,
			'metrics' => [
				[
					'key'      => 'listens',
					'date_key' => 'listen_listened_at',
					'source'   => [],
					'status'   => [],
				],
				[
					'key'      => 'artists',
					'date_key' => 'listen_listened_at',
					'source'   => [ 'listen_artist' ],
					'status'   => [],
				],
			],
		],
		'watch'    => [
			'main'    => 'watched',
			'top'     => false,
			'metrics' => [
				[
					'key'      => 'watched',
					'date_key' => null,
					'source'   => [],
					'status'   => [ 'watch_status' => 'watched' ],
				],
				[
					'key'      => 'rewatched',
					'date_key' => null,
					'source'   => [ 'watch_is_rewatch' ],
					'status'   => [ 'watch_status' => 'watched' ],
				],
			],
		],
		'comics'   => [
			'main'    => 'finished',
			'top'     => false,
			'metrics' => [
				[
					'key'      => 'finished',
					'date_key' => 'comic_finished_at',
					'source'   => [],
					'status'   => [ 'comic_status' => 'finished' ],
				],
				[
					'key'      => 'drawn',
					'date_key' => null,
					'source'   => [ 'comic_status' ],
					'status'   => [],
				],
			],
		],
		'play'     => [
			'main'    => 'completed',
			'top'     => false,
			'metrics' => [
				[
					'key'      => 'completed',
					'date_key' => null,
					'source'   => [],
					'status'   => [ 'play_status' => 'completed' ],
				],
				[
					'key'      => 'hours',
					'date_key' => null,
					'source'   => [ 'play_hours' ],
					'status'   => [],
				],
			],
		],
		'exercise' => [
			'main'    => 'posts',
			'top'     => false,
			'metrics' => [
				[
					'key'      => 'posts',
					'date_key' => null,
					'source'   => [],
					'status'   => [],
				],
				[
					'key'      => 'duration',
					'date_key' => null,
					'source'   => [ 'exercise_duration' ],
					'status'   => [],
				],
			],
		],
		'sleep'    => [
			'main'    => 'posts',
			'top'     => false,
			'metrics' => [
				[
					'key'      => 'posts',
					'date_key' => null,
					'source'   => [],
					'status'   => [],
				],
				[
					'key'      => 'duration',
					'date_key' => null,
					'source'   => [ 'sleep_duration' ],
					'status'   => [],
				],
			],
		],
	];

	/**
	 * Kinds and years leaving eligibility.
	 *
	 * @var array<string, array<int, bool>>
	 */
	private static array $leaving = [];

	/** Wire all tracked write events. */
	public function register(): void {
		add_action( 'transition_post_status', [ self::class, 'on_status' ], 99, 3 );
		add_action( 'save_post', [ self::class, 'on_save' ], 99, 3 );
		add_action( 'wp_after_insert_post', [ self::class, 'on_after_insert' ], 99, 4 );
		add_action( 'post_updated', [ self::class, 'on_updated' ], 99, 3 );
		add_action( 'set_object_terms', [ self::class, 'on_terms' ], 99, 6 );
		add_action( 'deleted_term_relationships', [ self::class, 'on_deleted_terms' ], 99, 3 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_meta' ], 99, 4 );
		}
		foreach ( [ 'trashed_post', 'untrashed_post', 'before_delete_post', 'deleted_post', 'after_delete_post' ] as $hook ) {
			add_action( $hook, [ self::class, 'on_post_event' ], 99, 2 );
		}
		add_action( 'update_option_timezone_string', [ self::class, 'bump' ], 99 );
		add_action( 'update_option_gmt_offset', [ self::class, 'bump' ], 99 );
		add_action( 'shutdown', [ self::class, 'flush_side_effects' ], 99 );
	}

	/** Return a read-only copy of the fixed metric registry. */
	public static function registry(): array {
		return self::REGISTRY;
	}

	/** Return every post meta key whose write changes Wrapped numbers. */
	public static function meta_keys(): array {
		$suffixes = [];
		foreach ( self::REGISTRY as $entry ) {
			foreach ( $entry['metrics'] as $metric ) {
				$suffixes = array_merge( $suffixes, $metric['source'], array_keys( $metric['status'] ) );
				if ( null !== $metric['date_key'] ) {
					$suffixes[] = $metric['date_key'];
				}
			}
		}
		$keys = array_map(
			static fn( string $suffix ): string => '_pkiw_' . $suffix,
			array_values( array_unique( $suffixes ) )
		);

		return array_values(
			array_unique(
				array_merge(
					$keys,
					[ '_pkiw_exercise_details_public', '_pkiw_wrapped_kind', '_pkiw_wrapped_year' ],
					Public_Listing::meta_keys()
				)
			)
		);
	}

	/** Return metrics in configured order, or registry order when no keys are configured. */
	public static function ordered_metrics( array $metrics, array $configured ): array {
		if ( [] === $configured ) {
			return array_values( $metrics );
		}
		$by_key = [];
		foreach ( $metrics as $metric ) {
			$by_key[ (string) $metric['key'] ] = $metric;
		}
		$ordered = [];
		$seen    = [];
		foreach ( $configured as $key ) {
			if ( ! is_string( $key ) || isset( $seen[ $key ] ) || ! isset( $by_key[ $key ] ) ) {
				continue;
			}
			$ordered[]    = $by_key[ $key ];
			$seen[ $key ] = true;
		}

		return $ordered;
	}

	/** Compute uncached statistics without writing state. */
	public static function compute( string $kind, int $year ): array {
		$kind = sanitize_key( $kind );
		if ( $year < 2000 || $year > (int) wp_date( 'Y' ) || ! term_exists( $kind, 'kind' ) ) {
			return [];
		}
		$config = self::config( $kind );
		$ids    = self::candidate_ids( $kind, $year, self::date_keys( $config ) );
		$posts  = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// Prime first so the per-post guard reads from cache (C3 step 2).
			_prime_post_caches( $chunk );
			update_meta_cache( 'post', $chunk );
			foreach ( Public_Listing::filter_ids( $chunk ) as $id ) {
				$post = get_post( $id );
				if ( $post instanceof \WP_Post ) {
					$posts[] = $post;
				}
			}
		}
		$raw = self::aggregate( $kind, $year, $posts );

		return self::decorate(
			[
				'schema'  => self::SCHEMA,
				'kind'    => $kind,
				'year'    => $year,
				'main'    => $config['main'],
				'metrics' => $raw['metrics'],
				'top'     => $raw['top'],
			]
		);
	}

	/** Return current-generation statistics with request-locale labels. */
	public static function for_kind_year( string $kind, int $year ): array {
		$gen = (string) get_option( self::GEN, '' );
		if ( '' === $gen ) {
			return self::compute( $kind, $year );
		}
		$key     = self::transient_key( $kind, $year, $gen );
		$payload = get_transient( $key );
		$hit     = is_array( $payload )
			&& self::SCHEMA === ( $payload['schema'] ?? 0 )
			&& ( $payload['gen'] ?? '' ) === $gen;
		if ( $hit && ! self::top_is_listable( $payload ) ) {
			self::bump();
			$gen = (string) get_option( self::GEN, '' );
			return self::compute_and_store( $kind, $year, $gen );
		}
		if ( ! $hit ) {
			return self::compute_and_store( $kind, $year, $gen );
		}

		return self::decorate( $payload );
	}

	/** Produce the stable transient key. */
	public static function transient_key( string $kind, int $year, string $gen ): string {
		return 'pkiw_wrapped_' . substr( md5( $kind ), 0, 12 ) . '_' . $year . '_' . $gen;
	}

	/** Write a fresh generation stamp for one tracked event. */
	public static function bump(): void {
		update_option( self::GEN, bin2hex( random_bytes( 8 ) ), false );
	}

	/** Handle a post status transition. */
	public static function on_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || $new_status === $old_status ) {
			return;
		}
		self::track( $post, 'publish' === $old_status && 'publish' !== $new_status );
	}

	/** Handle a completed post save. */
	public static function on_save( int $id, \WP_Post $post, bool $update ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
			return;
		}
		self::track( $post, false );
	}

	/** Handle the final insert hook after REST terms and meta. */
	public static function on_after_insert( int $id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void {
		self::on_save( $id, $post, $update );
	}

	/** Handle password, status, and post-type changes. */
	public static function on_updated( int $id, \WP_Post $after, \WP_Post $before ): void {
		if ( 'post' !== $after->post_type && 'post' !== $before->post_type ) {
			return;
		}
		$leaves = ( '' === $before->post_password && '' !== $after->post_password )
			|| ( 'post' === $before->post_type && 'post' !== $after->post_type )
			|| ( 'publish' === $before->post_status && 'publish' !== $after->post_status );
		self::track( $before, $leaves );
	}

	/** Handle kind relationship writes. */
	public static function on_terms( int $id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( 'kind' !== $taxonomy || 'post' !== get_post_type( $id ) ) {
			return;
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$removed = array_diff( $old_tt_ids, $tt_ids );
		$slugs   = self::term_taxonomy_slugs( array_merge( $old_tt_ids, $tt_ids ) );
		// A Wrapped post is never counted, so stripping the default term core re-adds to it takes nothing
		// out of a number; removing any other kind (a counted post becoming a Wrapped host) does.
		$default = get_term( (int) get_option( 'default_term_kind' ), 'kind' );
		$real    = array_diff( self::term_taxonomy_slugs( $removed ), $default instanceof \WP_Term ? [ $default->slug ] : [] );
		$counted = '' === (string) get_post_meta( $id, Wrapped_Post::KIND_META, true );
		self::track( $post, ( $counted && [] !== $removed ) || [] !== $real, $slugs );
	}

	/** Handle removed kind relationships. */
	public static function on_deleted_terms( int $id, array $tt_ids, string $taxonomy ): void {
		if ( 'kind' !== $taxonomy || 'post' !== get_post_type( $id ) ) {
			return;
		}
		$post = get_post( $id );
		if ( $post instanceof \WP_Post ) {
			self::track( $post, true, self::term_taxonomy_slugs( $tt_ids ) );
		}
	}

	/** Handle tracked post-meta writes. */
	public static function on_meta( $meta_id, $id, string $key, $value = null ): void {
		if ( ! in_array( $key, self::meta_keys(), true ) ) {
			return;
		}
		if ( empty( $id ) ) {
			self::bump();
			return;
		}
		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		// A visibility write leaves eligibility only when the post is no longer listable after it.
		$leaves = ( in_array( $key, Public_Listing::meta_keys(), true ) && ! Public_Listing::is_listable( $post ) )
			|| ( '_pkiw_exercise_details_public' === $key && '1' !== (string) $value );
		self::track( $post, $leaves );
	}

	/** Handle trash and deletion lifecycle events. */
	public static function on_post_event( int $id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( $id );
		if ( $post instanceof \WP_Post && 'post' === $post->post_type ) {
			// Untrashing only adds eligibility (and WordPress restores to draft), so it bumps without purging.
			$leaves = 'untrashed_post' !== current_filter();
			self::track( $post, $leaves );
			return;
		}
		self::bump();
	}

	/** Run deduped edge purges and public notifications. */
	public static function flush_side_effects(): void {
		if ( [] === self::$leaving ) {
			return;
		}
		$leaving       = self::$leaving;
		self::$leaving = [];
		$urls          = [ home_url( '/' ), home_url( '/stream/' ), home_url( '/feed/' ) ];
		ksort( $leaving );
		foreach ( $leaving as $kind => $years ) {
			$urls[] = home_url( '/kind/' . rawurlencode( $kind ) . '/' );
			$query  = new \WP_Query(
				[
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => Wrapped_Post::KIND_META,
					'meta_value'     => $kind,
				]
			);
			foreach ( $query->posts as $wrapped_id ) {
				$urls[] = get_permalink( (int) $wrapped_id );
			}
			$year_list = array_map( 'intval', array_keys( $years ) );
			sort( $year_list, SORT_NUMERIC );
			do_action( 'pkiw_wrapped_numbers_changed', $kind, $year_list );
		}
		if ( function_exists( 'Pantheon_Cache' ) ) {
			\Pantheon_Cache()->enqueue_urls( array_values( array_unique( $urls ) ) );
			\Pantheon_Cache()->enqueue_regex( '^/stream/page/' );
		}
	}

	/** Return one registry entry or the count-only fallback. */
	private static function config( string $kind ): array {
		return self::REGISTRY[ $kind ] ?? [
			'main'    => 'posts',
			'top'     => false,
			'metrics' => [
				[
					'key'      => 'posts',
					'date_key' => null,
					'source'   => [],
					'status'   => [],
				],
			],
		];
	}

	/** Return unique non-post-date keys for a registry entry. */
	private static function date_keys( array $config ): array {
		$keys = [];
		foreach ( $config['metrics'] as $metric ) {
			if ( null !== $metric['date_key'] ) {
				$keys[] = $metric['date_key'];
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/** Query post-date and date-key candidate sets to exhaustion. */
	private static function candidate_ids( string $kind, int $year, array $date_keys ): array {
		$base = [
			'post_type'      => 'post',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'posts_per_page' => 1000,
			'tax_query'      => [
				[
					'taxonomy' => 'kind',
					'field'    => 'slug',
					'terms'    => $kind,
				],
			],
			'meta_query'     => [
				[
					'key'     => '_pkiw_wrapped_kind',
					'compare' => 'NOT EXISTS',
				],
			],
		];
		$sets = [
			array_merge(
				$base,
				[
					'date_query' => [
						[
							'after'     => "$year-01-01 00:00:00",
							'before'    => "$year-12-31 23:59:59",
							'inclusive' => true,
							'column'    => 'post_date',
						],
					],
				]
			),
		];
		foreach ( $date_keys as $date_key ) {
			$sets[] = array_merge(
				$base,
				[
					'meta_query' => [
						'relation' => 'AND',
						$base['meta_query'][0],
						[
							'key'     => '_pkiw_' . $date_key,
							'value'   => $year . '-',
							'compare' => 'LIKE',
						],
					],
				]
			);
		}
		$ids = [];
		foreach ( $sets as $args ) {
			for ( $page = 1; ; ++$page ) {
				$query = new \WP_Query( Public_Listing::query_args( array_merge( $args, [ 'paged' => $page ] ) ) );
				$chunk = array_map( 'intval', $query->posts );
				$ids   = array_merge( $ids, $chunk );
				if ( count( $chunk ) < 1000 ) {
					break;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/** Aggregate one kind through its explicit per-kind calculator. */
	private static function aggregate( string $kind, int $year, array $posts ): array {
		return match ( $kind ) {
			'read' => self::aggregate_read( $year, $posts ),
			'listen' => self::aggregate_listen( $year, $posts ),
			'watch' => self::aggregate_watch( $year, $posts ),
			'comics' => self::aggregate_comics( $year, $posts ),
			'play' => self::aggregate_play( $year, $posts ),
			'exercise' => self::aggregate_exercise( $year, $posts ),
			'sleep' => self::aggregate_sleep( $year, $posts ),
			default => self::aggregate_count( $year, $posts ),
		};
	}

	/** Aggregate Read metrics and top rows. */
	private static function aggregate_read( int $year, array $posts ): array {
		$finished   = 0;
		$pages      = 0;
		$page_basis = 0;
		$ratings    = [];
		$days       = [];
		$top        = [];
		foreach ( $posts as $post ) {
			$post_year = self::post_year( $post );
			if ( $year === $post_year ) {
				$days[] = substr( $post->post_date, 0, 10 );
			}
			$is_finished = 'finished' === get_post_meta( $post->ID, '_pkiw_read_status', true )
				&& self::metric_year( $post, 'read_finished_at' ) === $year;
			if ( ! $is_finished ) {
				continue;
			}
			++$finished;
			$page_count = absint( get_post_meta( $post->ID, '_pkiw_read_pages', true ) );
			if ( $page_count > 0 ) {
				$pages += $page_count;
				++$page_basis;
			}
			$rating = (float) get_post_meta( $post->ID, '_pkiw_read_rating', true );
			if ( $rating >= 1 && $rating <= 5 ) {
				$ratings[] = $rating;
			}
			$top[] = [
				'post_id'  => $post->ID,
				'rating'   => $rating,
				'finished' => card_calendar_date( (string) get_post_meta( $post->ID, '_pkiw_read_finished_at', true ) )[0],
			];
		}
		$metrics = [ self::metric( 'finished', $finished ) ];
		if ( $page_basis > 0 ) {
			$metrics[] = self::metric( 'pages', $pages, $page_basis );
		}
		$metrics[] = self::metric( 'streak', self::streak( $days ) );
		if ( count( $ratings ) >= self::MIN_SAMPLE ) {
			$metrics[] = self::metric( 'average_rating', array_sum( $ratings ) / count( $ratings ), count( $ratings ) );
		}
		usort(
			$top,
			static function ( array $left, array $right ): int {
				$rating_order = $right['rating'] <=> $left['rating'];
				if ( 0 !== $rating_order ) {
					return $rating_order;
				}
				$date_order = strcmp( (string) $right['finished'], (string) $left['finished'] );
				if ( 0 !== $date_order ) {
					return $date_order;
				}

				return $left['post_id'] <=> $right['post_id'];
			}
		);

		return [
			'metrics' => $metrics,
			'top'     => array_slice( $top, 0, self::TOP_MAX ),
		];
	}

	/** Aggregate Listen metrics. */
	private static function aggregate_listen( int $year, array $posts ): array {
		$count   = 0;
		$artists = [];
		foreach ( $posts as $post ) {
			if ( self::metric_year( $post, 'listen_listened_at' ) !== $year ) {
				continue;
			}
			++$count;
			$artist = mb_strtolower( trim( (string) get_post_meta( $post->ID, '_pkiw_listen_artist', true ) ) );
			if ( '' !== $artist ) {
				$artists[ $artist ] = true;
			}
		}

		return self::without_top( [ self::metric( 'listens', $count ), self::metric( 'artists', count( $artists ) ) ] );
	}

	/** Aggregate Watch metrics. */
	private static function aggregate_watch( int $year, array $posts ): array {
		$watched   = 0;
		$rewatched = 0;
		foreach ( $posts as $post ) {
			if ( self::post_year( $post ) !== $year || 'watched' !== get_post_meta( $post->ID, '_pkiw_watch_status', true ) ) {
				continue;
			}
			++$watched;
			if ( '1' === get_post_meta( $post->ID, '_pkiw_watch_is_rewatch', true ) ) {
				++$rewatched;
			}
		}

		return self::without_top( [ self::metric( 'watched', $watched ), self::metric( 'rewatched', $rewatched ) ] );
	}

	/** Aggregate Comics metrics. */
	private static function aggregate_comics( int $year, array $posts ): array {
		$finished = 0;
		$drawn    = 0;
		foreach ( $posts as $post ) {
			if ( 'finished' === get_post_meta( $post->ID, '_pkiw_comic_status', true ) && self::metric_year( $post, 'comic_finished_at' ) === $year ) {
				++$finished;
			}
			if ( self::post_year( $post ) === $year && Comic_Strip::is_drawn( $post ) ) {
				++$drawn;
			}
		}

		return self::without_top( [ self::metric( 'finished', $finished ), self::metric( 'drawn', $drawn ) ] );
	}

	/** Aggregate Play metrics. */
	private static function aggregate_play( int $year, array $posts ): array {
		$completed = 0;
		$hours     = 0.0;
		$basis     = 0;
		foreach ( $posts as $post ) {
			if ( self::post_year( $post ) !== $year ) {
				continue;
			}
			if ( 'completed' === get_post_meta( $post->ID, '_pkiw_play_status', true ) ) {
				++$completed;
			}
			$value = (float) get_post_meta( $post->ID, '_pkiw_play_hours', true );
			if ( $value > 0 ) {
				$hours += $value;
				++$basis;
			}
		}
		$metrics = [ self::metric( 'completed', $completed ) ];
		if ( $basis > 0 ) {
			$metrics[] = self::metric( 'hours', $hours, $basis );
		}

		return self::without_top( $metrics );
	}

	/** Aggregate Exercise metrics. */
	private static function aggregate_exercise( int $year, array $posts ): array {
		$count   = 0;
		$seconds = 0;
		$basis   = 0;
		foreach ( $posts as $post ) {
			if ( self::post_year( $post ) !== $year ) {
				continue;
			}
			++$count;
			if ( ! Meta_Fields::health_details_visible( $post->ID ) ) {
				continue;
			}
			$value = card_duration_seconds( (string) get_post_meta( $post->ID, '_pkiw_exercise_duration', true ) );
			if ( $value > 0 ) {
				$seconds += $value;
				++$basis;
			}
		}

		return self::duration_result( $count, $seconds, $basis );
	}

	/** Aggregate Sleep metrics. */
	private static function aggregate_sleep( int $year, array $posts ): array {
		$count   = 0;
		$seconds = 0;
		$basis   = 0;
		foreach ( $posts as $post ) {
			if ( self::post_year( $post ) !== $year ) {
				continue;
			}
			++$count;
			$value = absint( get_post_meta( $post->ID, '_pkiw_sleep_duration', true ) );
			if ( $value > 0 ) {
				$seconds += $value * MINUTE_IN_SECONDS;
				++$basis;
			}
		}

		return self::duration_result( $count, $seconds, $basis );
	}

	/** Aggregate a count-only kind. */
	private static function aggregate_count( int $year, array $posts ): array {
		$count = 0;
		foreach ( $posts as $post ) {
			if ( self::post_year( $post ) === $year ) {
				++$count;
			}
		}

		return self::without_top( [ self::metric( 'posts', $count ) ] );
	}

	/** Build an exercise or sleep duration result. */
	private static function duration_result( int $count, int $seconds, int $basis ): array {
		$metrics = [ self::metric( 'posts', $count ) ];
		if ( $basis >= self::MIN_SAMPLE ) {
			$metrics[] = self::metric( 'duration', $seconds, $basis );
		}

		return self::without_top( $metrics );
	}

	/** Build a locale-free metric row. */
	private static function metric( string $key, int|float $value, ?int $basis = null ): array {
		return [
			'key'   => $key,
			'value' => $value,
			'basis' => $basis,
		];
	}

	/** Pair metrics with an empty top list. */
	private static function without_top( array $metrics ): array {
		return [
			'metrics' => $metrics,
			'top'     => [],
		];
	}

	/** Return the stored site-local post-date year. */
	private static function post_year( \WP_Post $post ): int {
		return (int) substr( $post->post_date, 0, 4 );
	}

	/** Return a stored calendar-date year, with post-date fallback. */
	private static function metric_year( \WP_Post $post, string $date_key ): int {
		$date = card_calendar_date( (string) get_post_meta( $post->ID, '_pkiw_' . $date_key, true ) );
		return '' !== $date[0] ? (int) substr( $date[0], 0, 4 ) : self::post_year( $post );
	}

	/** Find the longest run of ISO week indices. */
	private static function streak( array $days ): int {
		$weeks = [];
		foreach ( array_unique( $days ) as $day ) {
			$timestamp = gmmktime( 0, 0, 0, (int) substr( $day, 5, 2 ), (int) substr( $day, 8, 2 ), (int) substr( $day, 0, 4 ) );
			$weeks[]   = intdiv( $timestamp - ( ( (int) gmdate( 'N', $timestamp ) - 1 ) * DAY_IN_SECONDS ), WEEK_IN_SECONDS );
		}
		sort( $weeks );
		$best     = 0;
		$run      = 0;
		$previous = null;
		foreach ( array_unique( $weeks ) as $week ) {
			$run      = null !== $previous && $week === $previous + 1 ? $run + 1 : 1;
			$best     = max( $best, $run );
			$previous = $week;
		}

		return $best;
	}

	/** Add translated labels and formatted values. */
	private static function decorate( array $payload ): array {
		$kind               = (string) ( $payload['kind'] ?? '' );
		$payload['metrics'] = array_map(
			static function ( array $metric ) use ( $kind ): array {
				$metric['label']   = self::label( $kind, (string) $metric['key'], $metric['basis'] ?? null );
				$metric['display'] = self::display( (string) $metric['key'], $metric['value'] );
				return $metric;
			},
			$payload['metrics'] ?? []
		);

		return $payload;
	}

	/** Remove request-locale values before caching. */
	private static function strip_locale( array $payload ): array {
		$payload['metrics'] = array_map(
			static function ( array $metric ): array {
				unset( $metric['label'], $metric['display'] );
				return $metric;
			},
			$payload['metrics'] ?? []
		);

		return $payload;
	}

	/** Return one metric label. */
	private static function label( string $kind, string $key, $basis ): string {
		if ( 'finished' === $key ) {
			return 'read' === $kind ? read_status_labels()['finished'] : __( 'Finished', 'post-kinds-for-indieweb-in-block-themes' );
		}
		if ( 'completed' === $key ) {
			return play_status_labels()['completed'];
		}
		$plain = [
			'posts'     => __( 'Posts', 'post-kinds-for-indieweb-in-block-themes' ),
			'streak'    => __( 'Longest week streak', 'post-kinds-for-indieweb-in-block-themes' ),
			'listens'   => __( 'Listens', 'post-kinds-for-indieweb-in-block-themes' ),
			'artists'   => __( 'Artists', 'post-kinds-for-indieweb-in-block-themes' ),
			'watched'   => __( 'Watched', 'post-kinds-for-indieweb-in-block-themes' ),
			'rewatched' => __( 'Rewatched', 'post-kinds-for-indieweb-in-block-themes' ),
			'drawn'     => __( 'Drawn', 'post-kinds-for-indieweb-in-block-themes' ),
		];
		if ( isset( $plain[ $key ] ) ) {
			return $plain[ $key ];
		}
		$count = (int) $basis;
		if ( 'pages' === $key ) {
			/* translators: %d: number of finished posts with a page count. */
			$format = _n( 'Pages from %d finished', 'Pages from %d finished', $count, 'post-kinds-for-indieweb-in-block-themes' );
		} elseif ( 'average_rating' === $key ) {
			/* translators: %d: number of rated posts. */
			$format = _n( 'Average from %d rated', 'Average from %d rated', $count, 'post-kinds-for-indieweb-in-block-themes' );
		} elseif ( 'hours' === $key ) {
			/* translators: %d: number of posts with play hours. */
			$format = _n( 'Hours played from %d post', 'Hours played from %d posts', $count, 'post-kinds-for-indieweb-in-block-themes' );
		} elseif ( 'exercise' === $kind ) {
			/* translators: %d: number of exercise posts with public duration. */
			$format = _n( 'Exercise time from %d post', 'Exercise time from %d posts', $count, 'post-kinds-for-indieweb-in-block-themes' );
		} else {
			/* translators: %d: number of sleep posts with duration. */
			$format = _n( 'Sleep time from %d post', 'Sleep time from %d posts', $count, 'post-kinds-for-indieweb-in-block-themes' );
		}

		return sprintf( $format, $count );
	}

	/** Format one metric value. */
	private static function display( string $key, $value ): string {
		if ( 'duration' === $key ) {
			return card_minutes_label( intdiv( (int) $value, MINUTE_IN_SECONDS ) );
		}
		$decimals = in_array( $key, [ 'average_rating', 'hours' ], true ) ? 1 : 0;

		return number_format_i18n( (float) $value, $decimals );
	}

	/** Compute and cache one payload under a non-empty generation. */
	private static function compute_and_store( string $kind, int $year, string $gen ): array {
		$result = self::compute( $kind, $year );
		if ( [] === $result || '' === $gen ) {
			return $result;
		}
		$payload        = self::strip_locale( $result );
		$payload['gen'] = $gen;
		set_transient( self::transient_key( $kind, $year, $gen ), $payload, DAY_IN_SECONDS );

		return self::decorate( $payload );
	}

	/** Return whether every cached top row remains listable. */
	private static function top_is_listable( array $payload ): bool {
		foreach ( $payload['top'] ?? [] as $row ) {
			if ( ! Public_Listing::is_listable( (int) $row['post_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/** Resolve term-taxonomy IDs to kind slugs. */
	private static function term_taxonomy_slugs( array $tt_ids ): array {
		$slugs = [];
		foreach ( array_unique( array_map( 'intval', $tt_ids ) ) as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', $tt_id, 'kind' );
			if ( $term instanceof \WP_Term ) {
				$slugs[] = $term->slug;
			}
		}

		return $slugs;
	}

	/** Return the post-date year and every populated registry date-key year. */
	private static function affected_years( \WP_Post $post ): array {
		$years = [ self::post_year( $post ) ];
		foreach ( self::REGISTRY as $entry ) {
			foreach ( self::date_keys( $entry ) as $date_key ) {
				$date = card_calendar_date( (string) get_post_meta( $post->ID, '_pkiw_' . $date_key, true ) );
				if ( '' !== $date[0] ) {
					$years[] = (int) substr( $date[0], 0, 4 );
				}
			}
		}
		$years = array_values( array_unique( array_filter( $years ) ) );
		sort( $years, SORT_NUMERIC );

		return $years;
	}

	/** Record a write and optionally a leaving-eligibility side effect. */
	private static function track( \WP_Post $post, bool $leaves, array $extra_kinds = [] ): void {
		self::bump();
		if ( ! $leaves ) {
			return;
		}
		$current = wp_get_post_terms( $post->ID, 'kind', [ 'fields' => 'slugs' ] );
		$kinds   = array_merge( is_wp_error( $current ) ? [] : $current, $extra_kinds );
		$years   = self::affected_years( $post );
		foreach ( array_unique( $kinds ) as $kind ) {
			$kind = sanitize_key( (string) $kind );
			if ( '' === $kind ) {
				continue;
			}
			foreach ( $years as $year ) {
				self::$leaving[ $kind ][ $year ] = true;
			}
		}
	}
}

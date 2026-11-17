<?php
/**
 * Printable zine selection and manifest.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Select listable posts before assigning booklet pages. */
final class Zine_Manifest {
	// Public signatures below carry the callback and value types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	/** Zine-only ActivityPub visibility exclusions. */
	public const NARROWED_VISIBILITY = [];

	/**
	 * Test-only visibility override.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $narrowed_override = null;

	/** Register the optional zine-only SQL predicate. */
	public static function register(): void {
		add_filter( 'posts_where', [ self::class, 'filter_where' ], 21, 2 );
	}

	/** Return zine-only ActivityPub visibility exclusions. */
	public static function narrowed_visibility(): array {
		return null === self::$narrowed_override ? self::NARROWED_VISIBILITY : self::$narrowed_override;
	}

	/**
	 * Override zine-only visibility values in tests.
	 *
	 * @param array<int, string>|null $values Values, or null to restore the constant.
	 */
	public static function override_narrowed_visibility_for_tests( ?array $values ): void {
		self::$narrowed_override = $values;
	}

	/** Return kind slugs excluded from all zines. */
	public static function excluded_kinds(): array {
		$values = apply_filters( 'pkiw_zine_excluded_kinds', [ 'follow', 'tag' ] );
		if ( ! is_array( $values ) ) {
			return [];
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_title', $values ) ) ) );
	}

	/**
	 * Append the optional zine-only visibility predicate.
	 *
	 * @param string    $where Existing WHERE clause.
	 * @param \WP_Query $query Query being built.
	 */
	public static function filter_where( string $where, \WP_Query $query ): string {
		$hidden = self::narrowed_visibility();
		if ( true !== $query->get( 'pkiw_zine_narrow_visibility' ) || [] === $hidden ) {
			return $where;
		}

		global $wpdb;
		$keys               = Public_Listing::meta_keys();
		$key_placeholders   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$value_placeholders = implode( ', ', array_fill( 0, count( $hidden ), '%s' ) );
		$prepared           = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder lists match the two runtime arrays.
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pkiw_zine_apv WHERE pkiw_zine_apv.post_id = {$wpdb->posts}.ID AND pkiw_zine_apv.meta_key IN ( {$key_placeholders} ) AND pkiw_zine_apv.meta_value IN ( {$value_placeholders} ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Core table names and generated placeholders only.
			array_merge( $keys, $hidden )
		);

		return $where . $prepared;
	}

	/**
	 * Return the first filtered, non-inheriting Query Loop's variables.
	 *
	 * @param \WP_Post $page Source page.
	 * @return array<string, mixed>|null
	 */
	public static function stream_query_vars( \WP_Post $page ): ?array {
		foreach ( parse_blocks( $page->post_content ) as $parsed ) {
			if ( 'core/query' !== (string) ( $parsed['blockName'] ?? '' ) || ! empty( $parsed['attrs']['query']['inherit'] ) ) {
				continue;
			}
			$template = self::find_block( (array) ( $parsed['innerBlocks'] ?? [] ), 'core/post-template' );
			if ( null === $template ) {
				return null;
			}
			$context = [
				'query'   => (array) ( $parsed['attrs']['query'] ?? [] ),
				'queryId' => (int) ( $parsed['attrs']['queryId'] ?? 0 ),
			];
			$block   = new \WP_Block( $template, $context );
			$prior   = $GLOBALS['wp_query'] ?? null;
			$standin = new \WP_Query();
			$standin->parse_query( [ 'page_id' => $page->ID ] );
			$standin->queried_object    = $page;
			$standin->queried_object_id = $page->ID;
			try {
				$GLOBALS['wp_query'] = $standin; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The contract requires a temporary page context.

				return build_query_vars_from_query_block( $block, 1 );
			} finally {
				if ( $prior instanceof \WP_Query ) {
					$GLOBALS['wp_query'] = $prior; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the saved request context.
				} else {
					unset( $GLOBALS['wp_query'] );
				}
			}
		}

		return null;
	}

	/**
	 * Determine whether a page contains the stream-source block at top level.
	 *
	 * @param \WP_Post $page Page to inspect.
	 */
	public static function has_stream_block( \WP_Post $page ): bool {
		foreach ( parse_blocks( $page->post_content ) as $block ) {
			if ( Zine_Export::BLOCK === (string) ( $block['blockName'] ?? '' ) && 'stream' === (string) ( $block['attrs']['source'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check the zine-only narrowing for one post.
	 *
	 * @param \WP_Post|int|null $post Post to inspect.
	 */
	public static function passes_narrowing( \WP_Post|int|null $post ): bool {
		$post   = get_post( $post );
		$hidden = self::narrowed_visibility();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		foreach ( Public_Listing::meta_keys() as $key ) {
			foreach ( get_post_meta( $post->ID, $key, false ) as $value ) {
				if ( in_array( (string) $value, $hidden, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Build a manifest from an archive query or stream page.
	 *
	 * @param array<string, mixed> $args Build arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function build( array $args ): array|\WP_Error { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- One ordered selection pipeline keeps SQL, PHP recheck, count and layout together.
		$source = (string) ( $args['source'] ?? '' );
		$pages  = (int) ( $args['pages'] ?? 8 );
		if ( ! in_array( $source, [ 'archive', 'stream' ], true ) || ! in_array( $pages, [ 8, 16 ], true ) ) {
			return new \WP_Error( 'invalid_zine_args', __( 'Invalid zine arguments.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 400 ] );
		}
		$capacity = 8 === $pages ? 6 : 14;
		$query    = $args['query'] ?? null;
		$kind     = 'archive' === $source ? sanitize_title( (string) ( $args['kind'] ?? '' ) ) : null;
		$page_id  = 'stream' === $source ? (int) ( $args['page_id'] ?? 0 ) : null;

		if ( 'stream' === $source ) {
			$page = get_post( $page_id );
			if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type || ! Public_Listing::is_listable( $page ) || ! self::passes_narrowing( $page ) || ! self::has_stream_block( $page ) ) {
				return self::not_found();
			}
			$vars = self::stream_query_vars( $page );
			if ( null === $vars ) {
				return self::not_found();
			}
			$query = self::run_query( $vars, $args, $capacity );
		} elseif ( ! $query instanceof \WP_Query ) {
			if ( '' === (string) $kind || ! get_term_by( 'slug', $kind, Taxonomy::TAXONOMY ) || in_array( $kind, self::excluded_kinds(), true ) ) {
				return self::not_found();
			}
			$vars  = [
				'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The selected kind defines the archive source.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => [ $kind ],
					],
				],
				'orderby'   => in_array( (string) ( $args['orderby'] ?? 'date' ), [ 'date', 'title' ], true ) ? (string) ( $args['orderby'] ?? 'date' ) : 'date',
				'order'     => 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC',
			];
			$query = self::run_query( $vars, $args, $capacity );
		}

		$raw_ids = array_map( 'intval', wp_list_pluck( (array) $query->posts, 'ID' ) );
		if ( [] === $raw_ids && isset( $query->query_vars['fields'] ) && 'ids' === $query->query_vars['fields'] ) {
			$raw_ids = array_map( 'intval', (array) $query->posts );
		}
		$ids      = Public_Listing::filter_ids( $raw_ids );
		$ids      = array_values( array_filter( $ids, [ self::class, 'passes_narrowing' ] ) );
		$excluded = self::excluded_kinds();
		$ids      = array_values(
			array_filter(
				$ids,
				static fn( int $id ): bool => [] === array_intersect( $excluded, wp_get_object_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ) )
			)
		);
		$dropped  = count( $raw_ids ) - count( $ids );
		$total    = max( 0, (int) $query->found_posts - $dropped );
		$ids      = array_slice( $ids, 0, $capacity );
		$entries  = [];
		foreach ( $ids as $index => $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$terms     = wp_get_object_terms( $id, Taxonomy::TAXONOMY );
			$term      = is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ? $terms[0] : null;
			[ $date ]  = card_calendar_date( $post->post_date );
			$entries[] = [
				'id'        => $id,
				'title'     => pkiw_get_safe_title( $id ),
				'kind'      => $term ? $term->slug : null,
				'kindLabel' => $term ? get_kind_label( $term->name, $term->slug, 'zine-entry' ) : null,
				'date'      => $date,
				'permalink' => get_permalink( $id ),
				'page'      => $index + 3,
			];
		}
		$dates = array_values( array_filter( wp_list_pluck( $entries, 'date' ) ) );

		return [
			'source'     => $source,
			'kind'       => $kind,
			'page_id'    => $page_id,
			'pages'      => $pages,
			'capacity'   => $capacity,
			'total'      => $total,
			'included'   => array_values( wp_list_pluck( $entries, 'id' ) ),
			'from'       => (string) ( $args['from'] ?? '' ),
			'to'         => (string) ( $args['to'] ?? '' ),
			'entries'    => $entries,
			'contents'   => array_map(
				static fn( array $entry ): array => [
					'title' => $entry['title'],
					'page'  => $entry['page'],
				],
				$entries
			),
			'span'       => [] === $dates ? [] : [
				'from' => min( $dates ),
				'to'   => max( $dates ),
			],
			'imposition' => Zine_Imposition::layout( $pages ),
		];
	}

	/**
	 * Run a capacity-limited secondary query.
	 *
	 * @param array<string, mixed> $vars     Source query variables.
	 * @param array<string, mixed> $args     Build arguments.
	 * @param int                  $capacity Entry capacity.
	 */
	private static function run_query( array $vars, array $args, int $capacity ): \WP_Query {
		$from  = (string) ( $args['from'] ?? '' );
		$to    = (string) ( $args['to'] ?? '' );
		$dates = [];
		if ( '' !== $from || '' !== $to ) {
			$clause = [
				'inclusive' => true,
				'column'    => 'post_date',
			];
			if ( '' !== $from ) {
				$clause['after'] = $from . ' 00:00:00';
			}
			if ( '' !== $to ) {
				$clause['before'] = $to . ' 23:59:59';
			}
			$dates = [ $clause ];
		}
		$tax_query = (array) ( $vars['tax_query'] ?? [] );
		$excluded  = self::excluded_kinds();
		if ( [] !== $excluded ) {
			$tax_query[] = [
				'taxonomy' => Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $excluded,
				'operator' => 'NOT IN',
			];
		}
		$overrides = Public_Listing::query_args(
			[
				'paged'                       => 1,
				'posts_per_page'              => $capacity,
				'no_found_rows'               => false,
				'ignore_sticky_posts'         => 1,
				'fields'                      => 'ids',
				'date_query'                  => $dates, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- User-selected inclusive archive bounds.
				'tax_query'                   => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Source and excluded kinds define the public selection.
				'pkiw_zine_narrow_visibility' => true,
			]
		);

		return new \WP_Query( array_merge( $vars, $overrides ) );
	}

	/** Return the indistinguishable empty-source error. */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'not_found', __( 'Zine source not found.', 'post-kinds-for-indieweb-in-block-themes' ), [ 'status' => 404 ] );
	}

	/**
	 * Find the first named block at any depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $name   Block name.
	 * @return array<string, mixed>|null
	 */
	private static function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( (string) ( $block['blockName'] ?? '' ) === $name ) {
				return $block;
			}
			$found = self::find_block( (array) ( $block['innerBlocks'] ?? [] ), $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}
}

<?php
/**
 * On This Day archive, cards, cache, headers, and invalidation.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\On_This_Day_Year_Source;
use PKIW\Integrations\Atmosphere_Titles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own every plugin surface for the On This Day feature. */
final class On_This_Day {
	// Public method signatures carry the callback contract types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

	public const ROUTE_BASE  = 'on-this-day';
	public const QUERY_VAR   = 'pkiw_on_this_day';
	public const BLOCK       = 'post-kinds-indieweb/on-this-day';
	public const ENTRY_BLOCK = 'post-kinds-indieweb/on-this-day-entry';
	public const SOURCE      = 'on-this-day-year';
	public const CRON_HOOK   = 'pkiw_otd_midnight';
	public const STYLE       = 'pkiw-on-this-day';

	/**
	 * Test clock.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private static ?\DateTimeImmutable $now = null;

	/**
	 * Stream relocation state.
	 *
	 * @var array{level:int, depth:int}|null
	 */
	private static ?array $pending = null;

	/**
	 * Current nested the_content depth.
	 *
	 * @var int
	 */
	private static int $content_depth = 0;

	/**
	 * Whether this request sent branch B headers.
	 *
	 * @var bool
	 */
	private static bool $uncached = false;

	/**
	 * Test header receiver.
	 *
	 * @var callable|null
	 */
	private static $header_sink = null;

	/**
	 * Test answer for headers_sent().
	 *
	 * @var bool
	 */
	private static bool $headers_already_sent = false;

	/**
	 * Whether the current request has queued a transient deletion.
	 *
	 * @var bool
	 */
	private static bool $delete_transient = false;

	/**
	 * Edge paths queued for the request.
	 *
	 * @var string[]
	 */
	private static array $purge_paths = [];

	/**
	 * Edge keys queued for the request.
	 *
	 * @var string[]
	 */
	private static array $purge_keys = [];

	/**
	 * Number of visible archive images rendered in this request.
	 *
	 * @var int
	 */
	private static int $entry_images = 0;

	/**
	 * Heading level active while the grouped engine calls item_html().
	 *
	 * @var int
	 */
	private static int $entry_heading_level = 3;

	/** Wire the route, blocks, readers, and invalidation events. */
	public function register(): void {
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ) {
			$GLOBALS['wp']->add_query_var( self::QUERY_VAR );
		}

		add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
		add_filter( 'request', [ self::class, 'filter_request' ] );
		add_action( 'pre_get_posts', [ self::class, 'filter_main_query' ] );
		add_filter( 'archive_template_hierarchy', [ self::class, 'template_hierarchy' ] );
		add_filter( 'pre_handle_404', [ self::class, 'pre_handle_404' ], 10, 2 );
		add_filter( 'document_title_parts', [ self::class, 'document_title_parts' ] );
		add_filter( 'wpseo_title', [ self::class, 'yoast_title' ] );
		add_filter( 'wpseo_opengraph_title', [ self::class, 'yoast_title' ] );
		add_filter( 'wpseo_canonical', [ self::class, 'yoast_url' ] );
		add_filter( 'wpseo_opengraph_url', [ self::class, 'yoast_url' ] );
		add_filter( 'render_block_core/post-template', [ self::class, 'relocate_stream_card' ], 20, 3 );
		add_filter( 'the_content', [ self::class, 'enter_content' ], PHP_INT_MIN );
		add_filter( 'the_content', [ self::class, 'leave_content' ], PHP_INT_MAX );
		add_action( 'send_headers', [ self::class, 'send_day_headers' ], PHP_INT_MAX );
		add_filter( 'pantheon_wp_main_query_surrogate_keys', [ self::class, 'surrogate_keys' ] );
		add_filter( 'pantheon_cache_default_max_age', [ self::class, 'max_age' ], PHP_INT_MAX );
		add_filter( 'perfmatters_rucss_excluded_stylesheets', [ self::class, 'exclude_rucss' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_style' ] );
		add_action( 'shutdown', [ self::class, 'flush_invalidation' ], PHP_INT_MAX );

		if ( did_action( 'init' ) ) {
			self::register_route();
			self::register_blocks();
			self::register_init_filters();
			self::schedule_midnight();
		} else {
			add_action( 'init', [ self::class, 'register_route' ] );
			add_action( 'init', [ self::class, 'register_blocks' ] );
			add_action( 'init', [ self::class, 'register_init_filters' ] );
			add_action( 'init', [ self::class, 'schedule_midnight' ] );
		}

		add_action( self::CRON_HOOK, [ self::class, 'midnight' ] );
		add_action( 'transition_post_status', [ self::class, 'status_changed' ], 20, 3 );
		add_action( 'post_updated', [ self::class, 'post_updated' ], 20, 3 );
		add_action( 'trashed_post', [ self::class, 'post_event' ], 20, 2 );
		add_action( 'untrashed_post', [ self::class, 'post_event' ], 20, 2 );
		add_action( 'deleted_post', [ self::class, 'post_event' ], 20, 2 );
		add_action( 'set_object_terms', [ self::class, 'terms_changed' ], 20, 6 );
		add_action( 'deleted_term_relationships', [ self::class, 'term_relationships_deleted' ], 20, 3 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'meta_changed' ], 20, 4 );
		}
		add_action( 'update_option_timezone_string', [ self::class, 'site_rule_changed' ] );
		add_action( 'update_option_gmt_offset', [ self::class, 'site_rule_changed' ] );
		add_action( 'edited_kind', [ self::class, 'site_rule_changed' ] );
		add_action( 'delete_kind', [ self::class, 'site_rule_changed' ] );
	}

	/** Register filters Pantheon reads during send_headers. */
	public static function register_init_filters(): void {
		add_filter( 'pantheon_skip_cache_control', [ self::class, 'skip_pantheon_cache_control' ] );
	}

	/** Register the archive rewrite rules. */
	public static function register_route(): void {
		add_rewrite_rule( '^on-this-day/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^on-this-day/page/([0-9]{1,})/?$', 'index.php?' . self::QUERY_VAR . '=1&paged=$matches[1]', 'top' );
	}

	/** Register both PHP-rendered blocks and their grouped-archive contract. */
	public static function register_blocks(): void {
		Grouped_Archive::register_source( new On_This_Day_Year_Source() );
		Grouped_Archive::register_entry_block(
			self::ENTRY_BLOCK,
			[
				'shape'       => Grouped_Archive::ENTRY_LINE,
				'list'        => 'ul',
				'heading_ids' => true,
				'classes'     => [
					'wrapper'      => 'pkiw-otd-years',
					'section'      => 'pkiw-otd-year',
					'heading'      => 'pkiw-otd-year__heading',
					'items'        => 'pkiw-otd-year__items',
					'placeholder'  => 'pkiw-otd-year__placeholder',
					'lead'         => 'pkiw-otd-lead',
					'lead_heading' => 'pkiw-otd-lead__heading',
					'lead_items'   => 'pkiw-otd-lead__items',
				],
			]
		);

		wp_register_script(
			'pkiw-on-this-day-editor',
			PKIW_URL . 'assets/js/on-this-day-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ],
			PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-on-this-day-editor', 'post-kinds-for-indieweb-in-block-themes' );
		wp_register_style( self::STYLE, PKIW_URL . 'styles/on-this-day.css', [], PKIW_VERSION );

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			/**
			 * Card block arguments.
			 *
			 * @var array<string, mixed> $block_args
			 */
			$block_args = [
				'api_version'     => 3,
				'title'           => __( 'On This Day', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => [ self::class, 'render_block' ],
				'uses_context'    => [ 'queryId', 'postId', 'postType' ],
				'attributes'      => [
					'context'      => [
						'type'    => 'string',
						'enum'    => [ 'single', 'stream' ],
						'default' => 'stream',
					],
					'headingLevel' => [
						'type'    => 'integer',
						'default' => 3,
					],
				],
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-on-this-day-editor',
				'style'           => self::STYLE,
				'example'         => [
					'attributes' => [
						'context'      => 'stream',
						'headingLevel' => 3,
					],
				],
			];
			register_block_type( self::BLOCK, $block_args );
		}

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			/**
			 * Entry block arguments.
			 *
			 * @var array<string, mixed> $entry_args
			 */
			$entry_args = [
				'api_version'     => 3,
				'title'           => __( 'On This Day entry', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => [ self::class, 'render_entry_block' ],
				'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
				'attributes'      => [
					'headingLevel' => [
						'type'    => 'integer',
						'default' => 3,
					],
				],
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-on-this-day-editor',
				'style'           => self::STYLE,
				'example'         => [ 'attributes' => [ 'headingLevel' => 3 ] ],
			];
			register_block_type( self::ENTRY_BLOCK, $entry_args );
		}
	}

	/** Return the site-local current day. */
	public static function today(): \DateTimeImmutable {
		$now = self::$now ?? current_datetime();
		return $now->setTimezone( wp_timezone() );
	}

	/** Replace the clock during a test. */
	public static function override_now( ?\DateTimeImmutable $now ): void {
		self::$now = $now;
	}

	/** Return wall-clock seconds until the next site-local midnight. */
	public static function seconds_until_midnight( ?\DateTimeImmutable $now = null ): int {
		$now      = ( $now ?? self::today() )->setTimezone( wp_timezone() );
		$midnight = $now->setTime( 0, 0 )->modify( '+1 day' );
		return max( 1, $midnight->getTimestamp() - $now->getTimestamp() );
	}

	/** Return the filtered, installed opt-in kind slugs. */
	public static function kinds(): array {
		$defaults = [ 'listen', 'watch', 'read', 'comics', 'eat', 'note', 'article', '__none__' ];
		$filtered = apply_filters( 'pkiw_on_this_day_kinds', $defaults );
		$filtered = is_array( $filtered ) ? $filtered : [];
		$kinds    = [];

		foreach ( $filtered as $kind ) {
			if ( ! is_string( $kind ) ) {
				continue;
			}
			$kind = sanitize_key( $kind );
			if ( '__none__' !== $kind && ! term_exists( $kind, Taxonomy::TAXONOMY ) ) {
				continue;
			}
			if ( ! in_array( $kind, $kinds, true ) ) {
				$kinds[] = $kind;
			}
		}

		return $kinds;
	}

	/** Build the shared query arguments for one calendar month-day. */
	public static function query_args( \DateTimeImmutable $day, array $extra = [] ): array {
		$day        = $day->setTimezone( wp_timezone() );
		$bound_year = isset( $extra['bound_year'] ) ? (int) $extra['bound_year'] : (int) $day->format( 'Y' );
		unset( $extra['bound_year'] );

		$kinds      = self::kinds();
		$kind_slugs = array_values( array_diff( $kinds, [ '__none__' ] ) );
		$tax_query  = [ 'relation' => 'OR' ];
		if ( [] !== $kind_slugs ) {
			$tax_query[] = [
				'taxonomy' => Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $kind_slugs,
			];
		}
		if ( in_array( '__none__', $kinds, true ) ) {
			$tax_query[] = [
				'taxonomy' => Taxonomy::TAXONOMY,
				'operator' => 'NOT EXISTS',
			];
		}

		$args = [
			'post_type'           => 'post',
			'ignore_sticky_posts' => true,
			'orderby'             => [
				'date' => 'DESC',
				'ID'   => 'DESC',
			],
			'date_query'          => [
				'relation' => 'AND',
				[
					'month'  => (int) $day->format( 'n' ),
					'day'    => (int) $day->format( 'j' ),
					'column' => 'post_date',
				],
				[
					'year'    => $bound_year,
					'compare' => '<',
					'column'  => 'post_date',
				],
			],
		];
		if ( count( $tax_query ) > 1 ) {
			$args['tax_query'] = $tax_query;
		} else {
			$args['post__in'] = [ 0 ];
		}

		return Public_Listing::query_args( array_merge( $args, $extra ) );
	}

	/** Build uncached ID rows for one calendar month-day. */
	public static function build_rows( \DateTimeImmutable $day ): array {
		$query = new \WP_Query(
			self::query_args(
				$day,
				[
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
				]
			)
		);
		$ids   = Public_Listing::filter_ids( array_map( 'intval', $query->posts ) );
		$rows  = [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || ! self::row_visible( $post, self::kinds() ) ) {
				continue;
			}
			$date = get_post_datetime( $post, 'date' );
			if ( ! $date instanceof \DateTimeImmutable ) {
				continue;
			}
			$rows[] = [
				'id'   => $post->ID,
				'year' => (int) $date->format( 'Y' ),
				'ts'   => $date->getTimestamp(),
			];
		}

		return $rows;
	}

	/** Return today's cached rows after rechecking every post. */
	public static function served_rows( \DateTimeImmutable $day ): array {
		$day = $day->setTimezone( wp_timezone() );
		if ( $day->format( 'Y-m-d' ) !== self::today()->format( 'Y-m-d' ) ) {
			return self::build_rows( $day );
		}

		$key        = self::transient_key( $day );
		$kinds      = self::kinds();
		$kinds_hash = hash( 'sha256', (string) wp_json_encode( $kinds ) );
		$cached     = get_transient( $key );
		$valid      = is_array( $cached )
			&& [ 'kinds', 'rows' ] === array_keys( $cached )
			&& hash_equals( $kinds_hash, is_string( $cached['kinds'] ) ? $cached['kinds'] : '' )
			&& self::valid_rows( $cached['rows'] );
		if ( ! $valid ) {
			$cached = [
				'kinds' => $kinds_hash,
				'rows'  => self::build_rows( $day ),
			];
			set_transient( $key, $cached, self::seconds_until_midnight() );
		}

		$rows = $cached['rows'];
		$ids  = array_map( static fn( array $row ): int => $row['id'], $rows );
		if ( [] !== $ids ) {
			_prime_post_caches( $ids, true, true );
		}
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $kinds ): bool {
					$post = get_post( $row['id'] );
					return $post instanceof \WP_Post && self::row_visible( $post, $kinds );
				}
			)
		);
	}

	/** Confirm the transient contains only the frozen row shape. */
	private static function valid_rows( mixed $rows ): bool {
		if ( ! is_array( $rows ) ) {
			return false;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || [ 'id', 'year', 'ts' ] !== array_keys( $row ) || ! is_int( $row['id'] ) || ! is_int( $row['year'] ) || ! is_int( $row['ts'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** Return the transient key for a site-local day. */
	public static function transient_key( \DateTimeImmutable $day ): string {
		return 'pkiw_otd_' . $day->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/** Recheck one row immediately before use. */
	public static function row_visible( \WP_Post $post, array $kinds ): bool {
		if ( 'post' !== $post->post_type || ! Public_Listing::is_listable( $post ) || Public_Eligibility::hides( $post ) ) {
			return false;
		}
		$terms = wp_get_post_terms( $post->ID, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		if ( [] === $terms ) {
			return in_array( '__none__', $kinds, true );
		}
		return [] !== array_intersect( array_map( 'strval', $terms ), $kinds );
	}

	/** Return the safe display data shared by every card. */
	public static function display( \WP_Post $post ): array {
		$raw_title = trim( (string) $post->post_title );
		$stored    = '' !== $raw_title && ! stream_card_title_is_cited_url( $post, $raw_title );
		$title     = $stored ? trim( wp_strip_all_tags( visitor_post_title( $post ) ) ) : trim( wp_strip_all_tags( Atmosphere_Titles::derive( $post ) ) );
		if ( '' === $title ) {
			$title = trim( wp_strip_all_tags( untitled_name( $post, false ) ) );
		}
		$kind       = get_post_kind_slug( $post );
		$kind_label = '' !== $kind ? get_kind_label( stream_card_kind_label( $post ), $kind, 'on-this-day' ) : '';
		$image_id   = (int) get_post_thumbnail_id( $post );
		$image_id   = $image_id > 0 ? Public_Eligibility::public_image_id( $image_id ) : 0;

		return [
			'post_id'      => $post->ID,
			'title'        => $title,
			'stored'       => $stored,
			'kind'         => $kind,
			'kind_label'   => $kind_label,
			'date'         => get_the_date( 'Y-m-d', $post ),
			'date_display' => get_the_date( '', $post ),
			'year'         => (int) get_the_date( 'Y', $post ),
			'url'          => (string) get_permalink( $post ),
			'image_id'     => $image_id,
		];
	}

	/** Return the nearest visible post before the viewed post's year. */
	public static function single_pick( \WP_Post $viewed ): ?\WP_Post {
		$day = get_post_datetime( $viewed, 'date' );
		if ( ! $day instanceof \DateTimeImmutable ) {
			return null;
		}
		$query = new \WP_Query(
			self::query_args(
				$day,
				[
					'bound_year'     => (int) $day->format( 'Y' ),
					'posts_per_page' => 1,
					'no_found_rows'  => true,
					'post__not_in'   => [ $viewed->ID ],
				]
			)
		);
		$post  = $query->posts[0] ?? null;
		return $post instanceof \WP_Post && self::row_visible( $post, self::kinds() ) ? $post : null;
	}

	/** Return today's first visible row as a post. */
	public static function stream_pick( \DateTimeImmutable $day ): ?\WP_Post {
		$row  = self::served_rows( $day )[0] ?? null;
		$post = is_array( $row ) ? get_post( (int) ( $row['id'] ?? 0 ) ) : null;
		return $post instanceof \WP_Post && self::row_visible( $post, self::kinds() ) ? $post : null;
	}

	/**
	 * Add the route's public query var.
	 *
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return array_values( array_unique( $vars ) );
	}

	/** Keep only route and page number variables on the custom route. */
	public static function filter_request( array $vars ): array {
		if ( empty( $vars[ self::QUERY_VAR ] ) ) {
			return $vars;
		}
		$clean = [ self::QUERY_VAR => $vars[ self::QUERY_VAR ] ];
		if ( isset( $vars['paged'] ) ) {
			$clean['paged'] = $vars['paged'];
		}
		return $clean;
	}

	/** Turn the route's main query into the On This Day archive query. */
	public static function filter_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->get( self::QUERY_VAR ) || $query->is_feed() ) {
			return;
		}
		$query->query_vars = array_merge(
			$query->query_vars,
			self::query_args( self::today() ) + [
				'pkiw_group_source' => self::SOURCE,
				'pkiw_group_order'  => 'DESC',
			]
		);
		$query->is_home    = false;
		$query->is_archive = true;
	}

	/** Put the On This Day block template first for the route. */
	public static function template_hierarchy( array $templates ): array {
		if ( ! self::is_route() ) {
			return $templates;
		}
		return array_values( array_unique( array_merge( [ 'on-this-day.php' ], $templates ) ) );
	}

	/** Keep an empty first page from becoming a 404. */
	public static function pre_handle_404( $preempt, \WP_Query $query ) {
		if ( $query->get( self::QUERY_VAR ) && ! $query->is_paged() && 0 === (int) $query->post_count ) {
			return true;
		}
		return $preempt;
	}

	/** Set the route's core document-title part. */
	public static function document_title_parts( array $parts ): array {
		if ( self::is_route() ) {
			$parts['title'] = __( 'On This Day', 'post-kinds-for-indieweb-in-block-themes' );
		}
		return $parts;
	}

	/** Return the Yoast title on the route. */
	public static function yoast_title( string $title ): string {
		if ( ! self::is_route() || ! defined( 'WPSEO_VERSION' ) ) {
			return $title;
		}
		$separator = '-';
		if ( function_exists( 'YoastSEO' ) ) {
			$yoast = ( new \ReflectionFunction( 'YoastSEO' ) )->invoke();
			if ( is_object( $yoast ) && isset( $yoast->helpers->options ) && is_callable( [ $yoast->helpers->options, 'get_title_separator' ] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$separator = (string) $yoast->helpers->options->get_title_separator(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}
		return trim( __( 'On This Day', 'post-kinds-for-indieweb-in-block-themes' ) . ' ' . $separator . ' ' . get_bloginfo( 'name' ) );
	}

	/** Return the Yoast canonical or Open Graph URL on the route. */
	public static function yoast_url( string $url ): string {
		return self::is_route() && defined( 'WPSEO_VERSION' ) ? self::route_url() : $url;
	}

	/** Return the canonical route URL for the current page number. */
	public static function route_url(): string {
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		return home_url( 1 === $paged ? '/on-this-day/' : '/on-this-day/page/' . $paged . '/' );
	}

	/** Whether the current main query is the custom route. */
	private static function is_route(): bool {
		return (bool) get_query_var( self::QUERY_VAR );
	}

	/** Render the entry block through the grouped-archive engine. */
	public static function render_entry_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$previous                  = self::$entry_heading_level;
		self::$entry_heading_level = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 3 ) ) );
		try {
			return Grouped_Archive::render_entry( self::ENTRY_BLOCK, $attributes, $block, [ self::class, 'item_html' ] );
		} finally {
			self::$entry_heading_level = $previous;
		}
	}

	/** Return one archive item's inner markup, without its li wrapper. */
	public static function item_html( \WP_Post $post, bool $in_section, string $kind ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! self::row_visible( $post, self::kinds() ) ) {
			return '';
		}
		$data       = self::display( $post );
		$title_id   = 'pkiw-otd-title-' . $post->ID;
		$year_id    = 'pkiw-group-' . $data['year'];
		$title_attr = $in_section ? ' aria-labelledby="' . esc_attr( $year_id . ' ' . $title_id ) . '"' : '';
		$html       = '';
		if ( $data['image_id'] > 0 ) {
			$high  = 0 === self::$entry_images++;
			$html .= '<figure class="pkiw-otd__image" aria-hidden="true">' . wp_get_attachment_image(
				$data['image_id'],
				'medium',
				false,
				[
					'alt'           => '',
					'loading'       => $high ? 'eager' : 'lazy',
					'fetchpriority' => $high ? 'high' : 'auto',
				]
			) . '</figure>';
		}
		if ( '' !== $data['kind_label'] ) {
			$html .= '<p class="pkiw-otd__kind">' . esc_html( $data['kind_label'] ) . '</p>';
		}
		$name_class = $data['stored'] ? ' class="u-url p-name"' : ' class="u-url"';
		$level      = self::$entry_heading_level;
		$html      .= '<h' . $level . ' id="' . esc_attr( $title_id ) . '" class="pkiw-otd__title"' . $title_attr . '><a' . $name_class . ' href="' . esc_url( $data['url'] ) . '">' . esc_html( $data['title'] ) . '</a></h' . $level . '>';
		$html      .= '<data class="dt-published" value="' . esc_attr( $data['date'] ) . '" hidden></data>';
		$html      .= '<span class="p-author h-card" hidden>' . esc_html( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ) . '</span>';
		return $html;
	}

	/** Render the single card or record a Stream relocation. */
	public static function render_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$context = (string) ( $attributes['context'] ?? 'stream' );
		$level   = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 3 ) ) );
		if ( 'single' === $context ) {
			if ( ! self::edge_can_purge() || wp_is_serving_rest_request() || is_feed() || is_admin() || ! is_singular( 'post' ) || isset( $block?->context['queryId'] ) ) {
				return '';
			}
			$viewed = get_queried_object();
			$pick   = $viewed instanceof \WP_Post ? self::single_pick( $viewed ) : null;
			return $pick instanceof \WP_Post ? self::single_card( $pick, $level ) : '';
		}

		if ( 'stream' === $context && self::is_uncached() && ! wp_is_serving_rest_request() && ! is_feed() && ! is_admin() && is_page() && get_queried_object_id() === get_the_ID() ) {
			self::$pending = [
				'level' => $level,
				'depth' => self::$content_depth,
			];
		}
		return '';
	}

	/** Build the single-context card. */
	private static function single_card( \WP_Post $post, int $level ): string {
		$data = self::display( $post );
		$id   = 'pkiw-otd-h-' . $post->ID;
		$html = '<aside class="pkiw-otd pkiw-otd--single h-cite" aria-labelledby="' . esc_attr( $id ) . '">';
		/* translators: %d: Four-digit publication year. */
		$html .= '<h2 id="' . esc_attr( $id ) . '" class="pkiw-otd__label">' . esc_html( sprintf( __( 'On this day in %d', 'post-kinds-for-indieweb-in-block-themes' ), $data['year'] ) ) . '</h2>';
		if ( '' !== $data['kind_label'] ) {
			$html .= '<p class="pkiw-otd__kind">' . esc_html( $data['kind_label'] ) . '</p>';
		}
		$name_class = $data['stored'] ? 'u-url p-name' : 'u-url';
		$html      .= '<h' . $level . ' class="pkiw-otd__title"><a class="' . esc_attr( $name_class ) . '" href="' . esc_url( $data['url'] ) . '">' . esc_html( $data['title'] ) . '</a></h' . $level . '>';
		$html      .= '<time class="dt-published" datetime="' . esc_attr( $data['date'] ) . '">' . esc_html( $data['date_display'] ) . '</time>';
		if ( $data['stored'] && has_excerpt( $post ) ) {
			$excerpt = wp_trim_words( trim( wp_strip_all_tags( (string) $post->post_excerpt ) ), 25 );
			if ( '' !== $excerpt ) {
				$html .= '<p class="pkiw-otd__excerpt">' . esc_html( $excerpt ) . '</p>';
			}
		}
		if ( $data['image_id'] > 0 ) {
			$html .= '<figure class="pkiw-otd__image" aria-hidden="true">' . wp_get_attachment_image( $data['image_id'], 'medium', false, [ 'alt' => '' ] ) . '</figure>';
		}
		return $html . '</aside>';
	}

	/** Insert the pending Stream card before the first Post Template item. */
	public static function relocate_stream_card( mixed $html, mixed $parsed_block, mixed $block = null ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$pending       = self::$pending;
		self::$pending = null;
		if ( null === $pending ) {
			return $html;
		}
		$block = $block instanceof \WP_Block ? $block : null;
		$key   = isset( $block?->context['queryId'] ) ? 'query-' . $block->context['queryId'] . '-page' : 'query-page';
		$page  = empty( $_GET[ $key ] ) ? 1 : (int) $_GET[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own Query Loop page parameter, read as core reads it.
		if ( 1 !== $page || ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		$offset = strpos( $html, '<li' );
		if ( false === $offset ) {
			return $html;
		}
		$listed    = [];
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( [ 'tag_name' => 'LI' ] ) ) {
			$classes = str_replace( [ "\r", "\n", "\t" ], ' ', (string) $processor->get_attribute( 'class' ) );
			foreach ( array_filter( explode( ' ', $classes ) ) as $class ) {
				if ( str_starts_with( $class, 'post-' ) && ctype_digit( substr( $class, 5 ) ) ) {
					$listed[] = (int) substr( $class, 5 );
				}
			}
		}
		$pick = self::stream_pick( self::today() );
		if ( ! $pick instanceof \WP_Post || in_array( $pick->ID, $listed, true ) ) {
			return $html;
		}

		$card = self::stream_card( $pick, $pending['level'] );
		return substr( $html, 0, $offset ) . $card . substr( $html, $offset );
	}

	/** Build the hand-written Stream list item. */
	private static function stream_card( \WP_Post $post, int $level ): string {
		$data  = self::display( $post );
		$years = max( 1, (int) self::today()->format( 'Y' ) - $data['year'] );
		$label = sprintf(
			/* translators: %d: Number of years ago. */
			_n( 'A year ago today', '%d years ago today', $years, 'post-kinds-for-indieweb-in-block-themes' ), // phpcs:ignore WordPress.WP.I18n.MissingSingularPlaceholder -- The singular is natural language rather than "1 year".
			$years
		);
		$html  = '<li class="wp-block-post pkiw-otd-stream-item"><article class="pk-card pk-card--stream pkiw-otd pkiw-otd--stream h-cite">';
		$html .= '<p class="pkiw-otd__label">' . esc_html( $label ) . '</p>';
		if ( $data['image_id'] > 0 ) {
			$html .= '<figure class="pkiw-otd__image" aria-hidden="true">' . wp_get_attachment_image(
				$data['image_id'],
				'medium',
				false,
				[
					'alt'     => '',
					'loading' => 'eager',
				]
			) . '</figure>';
		}
		$name_class = $data['stored'] ? 'u-url p-name' : 'u-url';
		$html      .= '<h' . $level . ' class="pkiw-otd__title"><a class="' . esc_attr( $name_class ) . '" href="' . esc_url( $data['url'] ) . '">' . esc_html( $data['title'] ) . '</a></h' . $level . '>';
		$meta       = implode( ' · ', array_filter( [ $data['kind_label'], $data['date_display'] ] ) );
		$html      .= '<p class="pkiw-otd__meta">' . esc_html( $meta ) . '</p>';
		return $html . '</article></li>';
	}

	/** Enter one nested the_content pass. */
	public static function enter_content( mixed $content ): mixed {
		++self::$content_depth;
		return $content;
	}

	/** Leave one nested the_content pass and discard stale relocation state. */
	public static function leave_content( mixed $content ): mixed {
		self::$content_depth = max( 0, self::$content_depth - 1 );
		if ( null !== self::$pending && self::$content_depth < self::$pending['depth'] ) {
			self::$pending = null;
		}
		return $content;
	}

	/** Return whether branch A has been explicitly enabled and fully supported. */
	public static function edge_can_purge(): bool {
		return (bool) apply_filters( 'pkiw_otd_edge_purge', false )
			&& function_exists( 'pantheon_wp_clear_edge_paths' )
			&& function_exists( 'pantheon_clear_edge_paths' )
			&& function_exists( 'pantheon_wp_clear_edge_keys' )
			&& function_exists( 'pantheon_clear_edge_keys' );
	}

	/** Return whether this request can carry today's card. */
	public static function is_day_dependent(): bool {
		if ( self::is_route() ) {
			return true;
		}
		$post = get_queried_object();
		return is_page() && $post instanceof \WP_Post && has_block( self::BLOCK, (string) $post->post_content );
	}

	/** Tell Pantheon to skip its default cache-control value on day-dependent pages. */
	public static function skip_pantheon_cache_control( bool $skip ): bool {
		return $skip || self::is_day_dependent();
	}

	/** Send the final cache policy for a day-dependent response. */
	public static function send_day_headers(): void {
		self::$uncached = false;
		if ( ! self::is_day_dependent() || wp_is_serving_rest_request() || self::headers_sent() ) {
			return;
		}
		$seconds = self::seconds_until_midnight();
		if ( ! self::edge_can_purge() || $seconds < 60 ) {
			self::emit_nocache_headers();
			self::$uncached = true;
			return;
		}

		$ttl = (int) apply_filters( 'pantheon_cache_default_max_age', 600 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Pantheon Advanced Page Cache filter.
		self::emit_header( 'Cache-Control: public, max-age=' . min( 600, $ttl, $seconds ) . ', must-revalidate', true );
	}

	/** Return whether branch B headers were emitted in this request. */
	public static function is_uncached(): bool {
		return self::$uncached;
	}

	/** Replace header I/O during a test. */
	public static function override_headers_for_tests( ?callable $sink, bool $already_sent = false ): void {
		self::$header_sink          = $sink;
		self::$headers_already_sent = $already_sent;
	}

	/** Return the production or test headers-sent state. */
	private static function headers_sent(): bool {
		return null !== self::$header_sink ? self::$headers_already_sent : headers_sent();
	}

	/** Emit core's no-cache headers through the current header adapter. */
	private static function emit_nocache_headers(): void {
		if ( null === self::$header_sink ) {
			header_remove( 'Last-Modified' );
		}
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			self::emit_header( $name . ': ' . $value, true );
		}
	}

	/** Emit one header through production or test I/O. */
	private static function emit_header( string $line, bool $replace ): void {
		if ( is_callable( self::$header_sink ) ) {
			call_user_func( self::$header_sink, $line, $replace );
			return;
		}
		header( $line, $replace );
	}

	/** Add the relevant On This Day key to a cacheable response. */
	public static function surrogate_keys( array $keys ): array {
		if ( ! self::edge_can_purge() ) {
			return $keys;
		}
		if ( self::is_route() || self::is_day_dependent() ) {
			$keys[] = self::surrogate_key( self::today() );
		} elseif ( is_singular( 'post' ) ) {
			$viewed = get_queried_object();
			if ( $viewed instanceof \WP_Post && self::single_pick( $viewed ) instanceof \WP_Post ) {
				$date = get_post_datetime( $viewed, 'date' );
				if ( $date instanceof \DateTimeImmutable ) {
					$keys[] = self::surrogate_key( $date );
				}
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/** Cap branch A at the next site-local midnight. */
	public static function max_age( int $ttl ): int {
		return self::edge_can_purge() && self::is_day_dependent() ? min( $ttl, self::seconds_until_midnight() ) : $ttl;
	}

	/** Return the day-specific edge key. */
	private static function surrogate_key( \DateTimeImmutable $day ): string {
		return 'pkiw-otd-' . $day->setTimezone( wp_timezone() )->format( 'md' );
	}

	/** Queue cache invalidation for one post. */
	public static function invalidate( \WP_Post|int $post ): void {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		self::$delete_transient = true;
		self::purge( $post );
	}

	/** Queue paths and this post's month-day key. */
	private static function purge( \WP_Post $post ): void {
		self::$purge_paths = array_merge( self::$purge_paths, self::day_paths() );
		$date              = get_post_datetime( $post, 'date' );
		if ( $date instanceof \DateTimeImmutable ) {
			self::$purge_keys[] = self::surrogate_key( $date );
		}
	}

	/** Queue invalidation after a post-status transition. */
	public static function status_changed( string $new_status, string $old_status, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		self::invalidate( $post );
	}

	/** Queue invalidation for the before and after month-days. */
	public static function post_updated( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		self::invalidate( $post_before );
		self::invalidate( $post_after );
	}

	/** Queue invalidation for trash, untrash, or delete hooks. */
	public static function post_event( int $post_id, mixed $post = null ): void {
		$candidate = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( $candidate instanceof \WP_Post ) {
			self::invalidate( $candidate );
		}
	}

	/** Queue invalidation after kind terms change. */
	public static function terms_changed( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::invalidate( $object_id );
		}
	}

	/** Queue invalidation after kind relationships are deleted. */
	public static function term_relationships_deleted( int $object_id, array $tt_ids, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::invalidate( $object_id );
		}
	}

	/** Queue invalidation after public display metadata changes. */
	public static function meta_changed( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$keys = array_merge( Public_Listing::meta_keys(), [ '_thumbnail_id' ], Title_Privacy::deciding_meta() );
		if ( is_string( $meta_key ) && in_array( $meta_key, $keys, true ) && (int) $object_id > 0 ) {
			self::invalidate( (int) $object_id );
		}
	}

	/** Queue a full day-rule invalidation. */
	public static function site_rule_changed(): void {
		self::$delete_transient = true;
		self::$purge_paths      = array_merge( self::$purge_paths, self::day_paths() );
		self::$purge_keys[]     = self::surrogate_key( self::today() );
	}

	/** Delete one transient and send one deduplicated edge purge set. */
	public static function flush_invalidation(): void {
		if ( self::$delete_transient ) {
			delete_transient( self::transient_key( self::today() ) );
		}
		$paths = array_values( array_unique( self::$purge_paths ) );
		$keys  = array_values( array_unique( self::$purge_keys ) );
		if ( self::edge_can_purge() ) {
			if ( [] !== $paths ) {
				( new \ReflectionFunction( 'pantheon_wp_clear_edge_paths' ) )->invoke( $paths );
			}
			if ( [] !== $keys ) {
				( new \ReflectionFunction( 'pantheon_wp_clear_edge_keys' ) )->invoke( $keys );
			}
		}
		self::$delete_transient = false;
		self::$purge_paths      = [];
		self::$purge_keys       = [];
	}

	/** Return both slash forms for the two day-dependent paths. */
	private static function day_paths(): array {
		$paths = [];
		foreach ( [ home_url( '/on-this-day/' ), home_url( '/stream/' ) ] as $url ) {
			$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
			$paths[] = trailingslashit( $path );
			$paths[] = untrailingslashit( $path );
		}
		return array_values( array_unique( $paths ) );
	}

	/** Schedule the next site-local midnight callback once. */
	public static function schedule_midnight(): void {
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$next = self::today()->setTime( 0, 0, 5 )->modify( '+1 day' );
		wp_schedule_single_event( $next->getTimestamp(), self::CRON_HOOK );
	}

	/** Expire yesterday, purge day-dependent paths, and schedule tomorrow. */
	public static function midnight(): void {
		delete_transient( self::transient_key( self::today()->modify( '-1 day' ) ) );
		self::$purge_paths  = array_merge( self::$purge_paths, self::day_paths() );
		self::$purge_keys[] = self::surrogate_key( self::today() );
		self::flush_invalidation();
		self::schedule_midnight();
	}

	/** Keep both On This Day stylesheets out of Remove Unused CSS. */
	public static function exclude_rucss( array $styles ): array {
		$styles[] = 'styles/on-this-day.css';
		return array_values( array_unique( $styles ) );
	}

	/** Enqueue the neutral card stylesheet where a plugin card can render. */
	public static function enqueue_style(): void {
		$enqueue = self::is_route();
		if ( ! $enqueue && is_page() ) {
			$post    = get_queried_object();
			$enqueue = $post instanceof \WP_Post && has_block( self::BLOCK, (string) $post->post_content ) && self::stream_pick( self::today() ) instanceof \WP_Post;
		}
		if ( ! $enqueue && self::edge_can_purge() && is_singular( 'post' ) ) {
			$post    = get_queried_object();
			$enqueue = $post instanceof \WP_Post && self::single_pick( $post ) instanceof \WP_Post;
		}
		if ( $enqueue ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/** Reset per-request and test state. */
	public static function reset(): void {
		self::$pending              = null;
		self::$content_depth        = 0;
		self::$uncached             = false;
		self::$header_sink          = null;
		self::$headers_already_sent = false;
		self::$delete_transient     = false;
		self::$purge_paths          = [];
		self::$purge_keys           = [];
		self::$entry_images         = 0;
		self::$entry_heading_level  = 3;
	}
}

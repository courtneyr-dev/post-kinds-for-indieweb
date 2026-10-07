<?php
/**
 * Grouped archive engine (P3): group sources, entry blocks, sections and the editor preview.
 *
 * A kind archive is grouped when its template holds a registered entry block
 * inside the Post Template of the Query Loop that inherits the main query.
 * The entry block carries the settings (section order, where the empty group
 * goes, lines per page, heading level, labels). The kind's group source says
 * what posts group by: a meta value, a computed case, a term or a date
 * bucket. Ordering is one ORDER BY on the posts query, so core pagination and
 * counts are untouched, and sections are printed from the posts the query
 * already holds.
 *
 * The #230 eat and drink menus run through here on their legacy path: a meta
 * key from `pkiw_archive_group_fields`, the shipped ORDER BY clause and the
 * shipped labels and markup.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Date_Source;
use PKIW\Grouping\Group_Source;
use PKIW\Grouping\Meta_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The grouped archive engine.
 */
final class Grouped_Archive {

	/**
	 * Entry shape: the block prints the whole item (the menu entry).
	 */
	public const ENTRY_LINE = 'line';

	/**
	 * Entry shape: core blocks print the item; the entry block carries settings.
	 */
	public const ENTRY_MARKER = 'marker';

	/**
	 * Section markup classes for an entry block that names none.
	 *
	 * @var array<string, string>
	 */
	private const DEFAULT_CLASSES = [
		'wrapper'     => 'pkiw-grouped',
		'section'     => 'pkiw-group',
		'heading'     => 'pkiw-group__heading',
		'items'       => 'pkiw-group__items',
		'placeholder' => 'pkiw-group-placeholder',
	];

	/**
	 * Registered sources by id.
	 *
	 * @var array<string, Group_Source>
	 */
	private static array $sources = [];

	/**
	 * Default source id by kind slug.
	 *
	 * @var array<string, string>
	 */
	private static array $kind_sources = [];

	/**
	 * Registered entry blocks by block name.
	 *
	 * @var array<string, array{shape:string, source:?string, expose_key:bool, classes:array<string, string>}>
	 */
	private static array $entries = [];

	/**
	 * Core's own render callback for the Post Template block.
	 *
	 * @var callable|null
	 */
	private static $core_post_template = null;

	/**
	 * Whether a block-renderer request is in progress: the editor previewing a block.
	 *
	 * @var bool
	 */
	private static bool $block_preview = false;

	/**
	 * Kind the editor previews against (`pkiw_kind` on the block-renderer request).
	 *
	 * @var string
	 */
	private static string $preview_kind = '';

	/**
	 * How many sections the page of the last previewed entry holds.
	 *
	 * @var int
	 */
	private static int $preview_sections = 0;

	/**
	 * Post IDs of a kind in grouped order, remembered for one editor render.
	 *
	 * @var array<string, int[]>
	 */
	private static array $preview_order = [];

	/**
	 * Render state, one frame per Post Template render and per item.
	 *
	 * @var array<int, array{sectioning:bool, group:?Archive_Group}>
	 */
	private static array $frames = [];

	/**
	 * Register a group source, and optionally make it a kind's default.
	 *
	 * @param Group_Source $source Source.
	 * @param string|null  $kind   Kind slug it groups by default.
	 * @return void
	 */
	public static function register_source( Group_Source $source, ?string $kind = null ): void {
		self::$sources[ $source->id() ] = $source;
		if ( null !== $kind && '' !== sanitize_key( $kind ) ) {
			self::$kind_sources[ sanitize_key( $kind ) ] = $source->id();
		}
	}

	/**
	 * Remove a group source and any kind default that names it.
	 *
	 * @param string $id Source id.
	 * @return void
	 */
	public static function unregister_source( string $id ): void {
		unset( self::$sources[ $id ] );
		self::$kind_sources = array_filter( self::$kind_sources, static fn( string $source ): bool => $source !== $id );
	}

	/**
	 * A registered source.
	 *
	 * @param string $id Source id.
	 * @return Group_Source|null
	 */
	public static function source( string $id ): ?Group_Source {
		return self::$sources[ $id ] ?? null;
	}

	/**
	 * Register a block whose presence in a Post Template groups the loop.
	 *
	 * Args:
	 * - shape: ENTRY_LINE (the block prints each item) or ENTRY_MARKER (core
	 *   blocks print the item and the block prints nothing on the front end).
	 *   Default ENTRY_MARKER.
	 * - source: a registered date source id the block always groups by.
	 *   Sources that order by a value attach to kinds instead.
	 * - expose_key: print `data-pkiw-group` on each section. Default false.
	 * - classes: wrapper, section, heading, items and placeholder classes.
	 *
	 * @param string               $block_name Block name, `namespace/name`.
	 * @param array<string, mixed> $args       Args.
	 * @return void
	 * @throws \InvalidArgumentException On a malformed name, an unknown shape or a fixed source that isn't a registered date source.
	 */
	public static function register_entry_block( string $block_name, array $args = [] ): void {
		if ( 1 !== preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $block_name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Entry block name "%s" must be namespace/name.', esc_html( $block_name ) ) );
		}
		$shape = (string) ( $args['shape'] ?? self::ENTRY_MARKER );
		if ( ! in_array( $shape, [ self::ENTRY_LINE, self::ENTRY_MARKER ], true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Entry shape "%s" must be line or marker.', esc_html( $shape ) ) );
		}
		$source = isset( $args['source'] ) ? (string) $args['source'] : null;
		if ( null !== $source && ! self::source( $source ) instanceof Date_Source ) {
			throw new \InvalidArgumentException( sprintf( 'Entry block "%s" can fix only a registered date source.', esc_html( $block_name ) ) );
		}
		$classes = self::DEFAULT_CLASSES;
		foreach ( (array) ( $args['classes'] ?? [] ) as $part => $class ) {
			if ( isset( $classes[ $part ] ) && is_string( $class ) ) {
				$classes[ $part ] = $class;
			}
		}

		self::$entries[ $block_name ] = [
			'shape'      => $shape,
			'source'     => $source,
			'expose_key' => ! empty( $args['expose_key'] ),
			'classes'    => $classes,
		];
	}

	/**
	 * Remove an entry block.
	 *
	 * @param string $block_name Block name.
	 * @return void
	 */
	public static function unregister_entry_block( string $block_name ): void {
		unset( self::$entries[ $block_name ] );
	}

	/**
	 * Whether a block is a registered entry block.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	public static function is_entry_block( string $block_name ): bool {
		return isset( self::$entries[ $block_name ] );
	}

	/**
	 * The attribute schema entry blocks share, for register_block_type().
	 *
	 * `$extra` adds attributes (`groupLabels`: object of group key => label;
	 * `dateFormat`: string) and overrides defaults, e.g.
	 * `[ 'emptyLabel' => [ 'default' => 'Play' ] ]`.
	 *
	 * @param array<string, array<string, mixed>> $extra Attributes to add or override.
	 * @return array<string, array<string, mixed>>
	 */
	public static function entry_attributes( array $extra = [] ): array {
		return array_replace_recursive(
			[
				'showSections' => [
					'type'    => 'boolean',
					'default' => true,
				],
				'headingLevel' => [
					'type'    => 'integer',
					'default' => 2,
				],
				'linesPerPage' => [
					'type'    => 'integer',
					'default' => 0,
				],
				'sectionOrder' => [
					'type'    => 'string',
					'enum'    => [ 'asc', 'desc' ],
					'default' => 'asc',
				],
				'emptyGroup'   => [
					'type'    => 'string',
					'enum'    => [ 'last', 'first' ],
					'default' => 'last',
				],
				'emptyLabel'   => [
					'type'    => 'string',
					'default' => '',
				],
			],
			$extra
		);
	}

	/**
	 * Whether items are being rendered into sections right now.
	 *
	 * @return bool
	 */
	public static function is_sectioning(): bool {
		$frame = end( self::$frames );

		return false !== $frame && $frame['sectioning'];
	}

	/**
	 * The group of the item being rendered into a section, or null.
	 *
	 * @return Archive_Group|null
	 */
	public static function current_group(): ?Archive_Group {
		$frame = end( self::$frames );

		return false !== $frame ? $frame['group'] : null;
	}

	/**
	 * A post's group under a kind's source, by the same rule the archive uses.
	 *
	 * @param string $kind    Kind slug.
	 * @param int    $post_id Post ID.
	 * @return Archive_Group|null Null when the kind isn't grouped or the post doesn't exist.
	 */
	public static function group_of_post( string $kind, int $post_id ): ?Archive_Group {
		$post   = get_post( $post_id );
		$source = $post instanceof \WP_Post ? self::kind_source( $kind, null ) : null;

		return null !== $source && $post instanceof \WP_Post ? $source->group_of( $post ) : null;
	}

	/**
	 * Kinds the editor previews in grouped order.
	 *
	 * @return string[]
	 */
	public static function grouped_kinds(): array {
		return array_values( array_unique( array_merge( array_keys( Kind_Archive_Layouts::group_fields() ), array_keys( self::$kind_sources ) ) ) );
	}

	/**
	 * Entry blocks as the editor preview needs them: whether each fixes a date source.
	 *
	 * @return array<string, array{fixed:bool}>
	 */
	public static function editor_entries(): array {
		return array_map( static fn( array $entry ): array => [ 'fixed' => null !== $entry['source'] ], self::$entries );
	}

	/**
	 * Whether a template mentions any registered entry block.
	 *
	 * @param string $content Template content.
	 * @return bool
	 */
	public static function mentions_entry_block( string $content ): bool {
		foreach ( array_keys( self::$entries ) as $name ) {
			if ( str_contains( $content, 'wp:' . $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What a kind archive's main query needs, read from its template.
	 *
	 * Looks for the first registered entry block inside the Post Template of
	 * a Query Loop that inherits the main query. An entry anywhere else (a
	 * sidebar, a loop with its own query) doesn't configure the archive.
	 *
	 * @param \WP_Query $query            Main query.
	 * @param string    $kind             Kind slug.
	 * @param string    $template_content Resolved template, patterns included.
	 * @return array{per_page:int, vars:array<string, string>}|null Null when no entry block configures the archive.
	 */
	public static function main_query_plan( \WP_Query $query, string $kind, string $template_content ): ?array {
		$entry = self::main_entry( parse_blocks( $template_content ) );
		if ( null === $entry ) {
			return null;
		}

		$settings = self::settings( (string) $entry['blockName'], (array) ( $entry['attrs'] ?? [] ) );
		$source   = self::entry_source( (string) $entry['blockName'] ) ?? self::kind_source( $kind, $query );

		return [
			'per_page' => $settings['per_page'],
			'vars'     => null === $source ? [] : self::query_vars( $source, $settings ),
		];
	}

	/**
	 * Render an entry block.
	 *
	 * On the front end a line entry prints its item and a marker prints
	 * nothing. In the editor each item is its own block-renderer request, so
	 * the entry on the post that opens a section prints that section (line)
	 * or its heading (marker) and the others print a hidden placeholder.
	 *
	 * @param string                                  $block_name Block name.
	 * @param array<string, mixed>                    $attributes Block attributes.
	 * @param \WP_Block|null                          $block      Block instance.
	 * @param callable(\WP_Post, bool, string):string $item       Prints one item: the post, whether it sits in a section, and the kind ('' = the post's own).
	 * @return string
	 */
	public static function render_entry( string $block_name, array $attributes, ?\WP_Block $block, callable $item ): string {
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$entry    = self::$entries[ $block_name ] ?? null;
		$settings = self::settings( $block_name, $attributes );
		$line     = null === $entry || self::ENTRY_LINE === $entry['shape'];
		if ( null === $entry || ! self::$block_preview || ! $settings['show'] ) {
			return $line ? (string) $item( $post, self::is_sectioning(), '' ) : '';
		}

		$kind   = self::preview_kind( $post_id );
		$source = self::entry_source( $block_name ) ?? self::kind_source( $kind, null );
		if ( null === $source ) {
			return $line ? (string) $item( $post, false, $kind ) : '';
		}

		$run = self::preview_run( $post_id, $kind, $source, $settings );
		if ( [] === $run ) {
			return sprintf( '<div class="%s" hidden></div>', esc_attr( $entry['classes']['placeholder'] ) );
		}

		$group = $source->group_of( $post );
		if ( ! $line ) {
			return self::heading( $entry['classes']['heading'], $settings['level'], self::label( $source, $group, $settings ) );
		}

		$items = [];
		foreach ( $run as $id ) {
			$member = get_post( $id );
			if ( $member instanceof \WP_Post ) {
				$items[] = '<li>' . $item( $member, true, $kind ) . '</li>';
			}
		}

		return self::section( $block_name, $source, $group, $items, $settings, self::$preview_sections );
	}

	/**
	 * Order a grouped query: group, then date, then ID.
	 *
	 * ORDER BY: posts in the empty group last (or first), then the source's
	 * rank, the group (case-insensitive) and its tiebreak in the section
	 * order, then post_date DESC, ID DESC. Every source expression is a
	 * scalar subquery used only here, so the query keeps one row per post
	 * and pagination counts don't change. A date source orders by date and
	 * ID only, whatever order the loop asked for.
	 *
	 * @internal Hooked to `posts_orderby`.
	 *
	 * @param string    $orderby ORDER BY clause.
	 * @param \WP_Query $query   Query.
	 * @return string
	 */
	public static function group_orderby( $orderby, $query ) {
		if ( ! $query instanceof \WP_Query ) {
			return $orderby;
		}
		$source = self::query_source( $query );
		if ( null === $source ) {
			return $orderby;
		}

		global $wpdb;
		$tail = "{$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
		$sql  = $source->sql( $query );
		if ( null === $sql ) {
			return $tail;
		}

		$value  = $sql['value'];
		$groups = 'DESC' === strtoupper( (string) $query->get( 'pkiw_group_order' ) ) ? 'DESC' : 'ASC';
		$empty  = 'first' === $query->get( 'pkiw_group_empty' ) ? 'DESC' : 'ASC';
		$parts  = [ "({$value} = '') {$empty}" ];
		if ( isset( $sql['last'] ) ) {
			$parts[] = "{$sql['last']} ASC";
		}
		if ( isset( $sql['rank'] ) ) {
			$parts[] = "{$sql['rank']} {$groups}";
		}
		$parts[] = "LOWER({$value}) {$groups}";
		if ( isset( $sql['tiebreak'] ) ) {
			$parts[] = "{$sql['tiebreak']} {$groups}";
		}
		$parts[] = $tail;

		return implode( ', ', $parts );
	}

	/**
	 * Group a Query Loop that sets its own query.
	 *
	 * The loop opts in with `query.pkiwGroupBy` set to a kind slug, or holds
	 * an entry block that fixes a date source.
	 *
	 * @internal Hooked to `query_loop_block_query_vars`.
	 *
	 * @param array<string, mixed> $query_vars WP_Query args.
	 * @param \WP_Block            $block      Post Template block.
	 * @return array<string, mixed>
	 */
	public static function query_block_group_by( $query_vars, $block ) {
		if ( ! $block instanceof \WP_Block ) {
			return $query_vars;
		}
		$entry  = self::find_entry( (array) ( $block->parsed_block['innerBlocks'] ?? [] ) );
		$name   = null !== $entry ? (string) $entry['blockName'] : '';
		$kind   = (string) ( $block->context['query']['pkiwGroupBy'] ?? '' );
		$source = self::entry_source( $name );
		if ( null === $source && '' !== $kind ) {
			$source = self::kind_source( $kind, null );
		}
		if ( null === $source ) {
			return $query_vars;
		}

		return array_merge( (array) $query_vars, self::query_vars( $source, self::settings( $name, (array) ( $entry['attrs'] ?? [] ) ) ) );
	}

	/**
	 * Swap core's Post Template render callback for one that prints sections.
	 *
	 * @internal Hooked to `init`.
	 *
	 * @return void
	 */
	public static function section_post_template(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/post-template' );
		if ( ! $type || ! is_callable( $type->render_callback ) || [ self::class, 'render_post_template' ] === $type->render_callback ) {
			return;
		}

		self::$core_post_template = $type->render_callback;
		$type->render_callback    = [ self::class, 'render_post_template' ];
	}

	/**
	 * Render a Post Template. A grouped loop prints one section per group on
	 * this page: a heading and the list of that group's items. A group that
	 * continues from the page before opens this page again under its plain
	 * label. Anything else renders as core renders it.
	 *
	 * The posts are the ones the query already holds, so the inherited main
	 * query and core's pagination are untouched and no second query runs.
	 *
	 * @internal Render callback for core/post-template.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @param \WP_Block            $block      Block instance.
	 * @return string
	 */
	public static function render_post_template( $attributes, $content, $block ) {
		$plan = $block instanceof \WP_Block ? self::section_plan( $block ) : null;
		if ( null === $plan ) {
			self::$frames[] = [
				'sectioning' => false,
				'group'      => null,
			];
			try {
				return is_callable( self::$core_post_template ) ? (string) call_user_func( self::$core_post_template, $attributes, $content, $block ) : '';
			} finally {
				array_pop( self::$frames );
			}
		}

		$query    = $plan['query'];
		$source   = $plan['source'];
		$enhanced = ! empty( $block->context['enhancedPagination'] );
		$sections = [];
		$last     = null;

		try {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id   = (int) get_the_ID();
				$post_type = (string) get_post_type();
				$post      = get_post( $post_id );
				$group     = $post instanceof \WP_Post ? $source->group_of( $post, $query ) : new Archive_Group( '', '' );

				// As core renders a Post Template item: the inner blocks, with this post as their context.
				$instance              = $block->parsed_block;
				$instance['blockName'] = 'core/null';
				$context               = static function ( $context ) use ( $post_id, $post_type ) {
					$context['postType'] = $post_type;
					$context['postId']   = $post_id;
					return $context;
				};
				self::$frames[]        = [
					'sectioning' => true,
					'group'      => $group,
				];
				add_filter( 'render_block_context', $context, 1 );
				try {
					$inner = ( new \WP_Block( $instance ) )->render( [ 'dynamic' => false ] );
				} finally {
					remove_filter( 'render_block_context', $context, 1 );
					array_pop( self::$frames );
				}

				if ( $group->key() !== $last ) {
					$sections[] = [
						'group' => $group,
						'items' => [],
					];
					$last       = $group->key();
				}
				$sections[ array_key_last( $sections ) ]['items'][] = sprintf(
					'<li%1$s class="%2$s">%3$s</li>',
					$enhanced ? ' data-wp-key="post-template-item-' . $post_id . '"' : '',
					esc_attr( implode( ' ', get_post_class( 'wp-block-post' ) ) ),
					$inner
				);
			}
		} finally {
			wp_reset_postdata();
		}

		$html = '';
		foreach ( $sections as $section ) {
			$html .= self::section( $plan['entry'], $source, $section['group'], $section['items'], $plan['settings'], count( $sections ) );
		}

		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes( [ 'class' => self::$entries[ $plan['entry'] ]['classes']['wrapper'] ] ), $html );
	}

	/**
	 * Note when the editor asks the server to render a block, and for which kind.
	 *
	 * @internal Hooked to `rest_request_before_callbacks` and `rest_request_after_callbacks`.
	 *
	 * @param mixed            $response Response so far, passed through.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function track_block_preview( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wp/v2/block-renderer/' ) ) {
			self::$block_preview = 'rest_request_before_callbacks' === current_filter();
			$kind                = $request->get_param( 'pkiw_kind' );
			self::$preview_kind  = self::$block_preview && is_string( $kind ) ? sanitize_key( $kind ) : '';
			self::$preview_order = [];
		}

		return $response;
	}

	/**
	 * Add the grouped orders to a REST posts route's `orderby` enum.
	 *
	 * @internal Hooked to `rest_{$post_type}_collection_params`.
	 *
	 * @param array<string, mixed> $params Collection parameters.
	 * @return array<string, mixed>
	 */
	public static function rest_collection_params( $params ) {
		if ( isset( $params['orderby']['enum'] ) && is_array( $params['orderby']['enum'] ) ) {
			array_push( $params['orderby']['enum'], ...array_keys( Kind_Archive_Layouts::REST_MENU_ORDERS ) );
		}

		return $params;
	}

	/**
	 * Turn `orderby=pkiw_group` into the grouped order for the request's kind.
	 *
	 * With no kind, several kinds, or a kind that isn't grouped, the posts
	 * come back newest first.
	 *
	 * @internal Hooked to `rest_{$post_type}_query`.
	 *
	 * @param array<string, mixed> $args    Query arguments.
	 * @param \WP_REST_Request     $request Request.
	 * @return array<string, mixed>
	 */
	public static function rest_order( $args, $request ) {
		$order = Kind_Archive_Layouts::REST_MENU_ORDERS[ (string) $request['orderby'] ] ?? null;
		if ( null === $order ) {
			return $args;
		}

		$args['orderby'] = 'date';
		$args['order']   = 'DESC';

		$term_ids = array_values( array_filter( array_map( 'intval', (array) $request[ Taxonomy::TAXONOMY ] ) ) );
		$term     = 1 === count( $term_ids ) ? get_term( $term_ids[0], Taxonomy::TAXONOMY ) : null;
		$source   = $term instanceof \WP_Term ? self::kind_source( $term->slug, null ) : null;
		if ( null !== $source ) {
			$args = array_merge(
				$args,
				self::query_vars(
					$source,
					[
						'order' => $order[0],
						'empty' => $order[1],
					]
				)
			);
		}

		return $args;
	}

	/**
	 * The source a kind groups by for this request.
	 *
	 * The kind's registered source, unless `pkiw_archive_group_source` names
	 * another registered source or returns null to turn grouping off. A kind
	 * with no registered source and a field in `pkiw_archive_group_fields`
	 * takes the legacy meta path.
	 *
	 * @param string         $kind  Kind slug.
	 * @param \WP_Query|null $query Query being grouped, when there is one.
	 * @return Group_Source|null
	 */
	private static function kind_source( string $kind, ?\WP_Query $query ): ?Group_Source {
		$default = self::$kind_sources[ $kind ] ?? null;

		/**
		 * Filters the group source a kind archive uses for this request.
		 *
		 * Return a registered source id to group by it, or null to turn
		 * grouping off for a kind that has a registered source (an A to Z
		 * view, say). A kind grouped through `pkiw_archive_group_fields` is
		 * turned off by removing it there.
		 *
		 * @since 1.9.0
		 *
		 * @param string|null    $id    Source id. Default the kind's registered source, or null.
		 * @param string         $kind  Kind slug.
		 * @param \WP_Query|null $query Query being grouped; null for the editor preview and REST.
		 */
		$id = apply_filters( 'pkiw_archive_group_source', $default, $kind, $query );
		if ( is_string( $id ) && isset( self::$sources[ $id ] ) ) {
			return self::$sources[ $id ];
		}
		if ( null !== $default ) {
			return null;
		}

		$field = Kind_Archive_Layouts::group_fields()[ $kind ] ?? '';

		return '' !== $field ? Meta_Source::legacy( $field, $kind ) : null;
	}

	/**
	 * The source a query's grouping vars name, or null.
	 *
	 * `pkiw_group_source` must name a registered source; `pkiw_group_by` must
	 * be a meta key in `pkiw_archive_group_fields`.
	 *
	 * @param \WP_Query $query Query.
	 * @return Group_Source|null
	 */
	private static function query_source( \WP_Query $query ): ?Group_Source {
		$id = (string) $query->get( 'pkiw_group_source' );
		if ( '' !== $id && isset( self::$sources[ $id ] ) ) {
			return self::$sources[ $id ];
		}

		$field = (string) $query->get( 'pkiw_group_by' );
		$kind  = '' !== $field ? array_search( $field, Kind_Archive_Layouts::group_fields(), true ) : false;

		return false !== $kind ? Meta_Source::legacy( $field, (string) $kind ) : null;
	}

	/**
	 * Query vars that group by a source.
	 *
	 * @param Group_Source         $source   Source.
	 * @param array<string, mixed> $settings Entry settings (order, empty).
	 * @return array<string, string>
	 */
	private static function query_vars( Group_Source $source, array $settings ): array {
		$legacy = $source instanceof Meta_Source ? $source->legacy_key() : '';

		return [
			( '' !== $legacy ? 'pkiw_group_by' : 'pkiw_group_source' ) => '' !== $legacy ? $legacy : $source->id(),
			'pkiw_group_order' => 'DESC' === ( $settings['order'] ?? 'ASC' ) ? 'DESC' : 'ASC',
			'pkiw_group_empty' => 'first' === ( $settings['empty'] ?? 'last' ) ? 'first' : 'last',
		];
	}

	/**
	 * The date source an entry block fixes, or null.
	 *
	 * @param string $block_name Block name.
	 * @return Group_Source|null
	 */
	private static function entry_source( string $block_name ): ?Group_Source {
		$id = self::$entries[ $block_name ]['source'] ?? null;

		return null !== $id ? self::source( $id ) : null;
	}

	/**
	 * An entry block's settings, its registered defaults under the given attributes.
	 *
	 * @param string               $block_name Block name.
	 * @param array<string, mixed> $attrs      Attributes.
	 * @return array{show:bool, level:int, per_page:int, order:string, empty:string, label:string, group_labels:array<string, string>, date_format:string}
	 */
	private static function settings( string $block_name, array $attrs ): array {
		$type = '' !== $block_name ? \WP_Block_Type_Registry::get_instance()->get_registered( $block_name ) : null;
		foreach ( $type ? (array) $type->attributes : [] as $name => $schema ) {
			if ( is_array( $schema ) && array_key_exists( 'default', $schema ) && ! array_key_exists( $name, $attrs ) ) {
				$attrs[ $name ] = $schema['default'];
			}
		}

		return [
			'show'         => (bool) ( $attrs['showSections'] ?? true ),
			'level'        => (int) ( $attrs['headingLevel'] ?? 2 ),
			'per_page'     => max( 0, min( 100, (int) ( $attrs['linesPerPage'] ?? 0 ) ) ),
			'order'        => 'desc' === ( $attrs['sectionOrder'] ?? 'asc' ) ? 'DESC' : 'ASC',
			'empty'        => 'first' === ( $attrs['emptyGroup'] ?? 'last' ) ? 'first' : 'last',
			'label'        => self::text( $attrs['emptyLabel'] ?? '' ),
			'group_labels' => self::group_labels( $attrs['groupLabels'] ?? [] ),
			'date_format'  => self::text( $attrs['dateFormat'] ?? '' ),
		];
	}

	/**
	 * A text attribute, sanitized; '' when it isn't scalar.
	 *
	 * @param mixed $value Attribute value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * The `groupLabels` attribute as lowercase group key => sanitized label.
	 *
	 * @param mixed $value Attribute value.
	 * @return array<string, string>
	 */
	private static function group_labels( $value ): array {
		$labels = [];
		foreach ( is_array( $value ) ? $value : [] as $key => $label ) {
			$label = self::text( $label );
			if ( '' !== $label ) {
				$labels[ mb_strtolower( trim( (string) $key ) ) ] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Heading text for a group.
	 *
	 * The empty group: the entry's emptyLabel, else the source's, else
	 * "Other". A group with a value: the entry's groupLabels entry for its
	 * key, else the source's label.
	 *
	 * @param Group_Source         $source   Source.
	 * @param Archive_Group        $group    Group.
	 * @param array<string, mixed> $settings Entry settings.
	 * @return string
	 */
	private static function label( Group_Source $source, Archive_Group $group, array $settings ): string {
		if ( $group->is_empty() ) {
			$label = trim( (string) ( $settings['label'] ?? '' ) );
			if ( '' === $label ) {
				$label = $source->empty_label();
			}

			return '' !== $label ? $label : __( 'Other', 'post-kinds-for-indieweb-in-block-themes' );
		}

		$named = (string) ( $settings['group_labels'][ $group->key() ] ?? '' );

		return '' !== $named ? $named : $source->label( $group, [ 'date_format' => (string) ( $settings['date_format'] ?? '' ) ] );
	}

	/**
	 * One section: its heading and the list of its items.
	 *
	 * The section has no accessible name of its own, so it adds no landmark;
	 * its heading carries the structure.
	 *
	 * @param string               $block_name Entry block name.
	 * @param Group_Source         $source     Source.
	 * @param Archive_Group        $group      Group.
	 * @param string[]             $items      The section's items, each an `li`.
	 * @param array<string, mixed> $settings   Entry settings.
	 * @param int                  $count      How many sections this page holds, so a
	 *                                         theme can lay out as many columns.
	 * @return string
	 */
	private static function section( string $block_name, Group_Source $source, Archive_Group $group, array $items, array $settings, int $count ): string {
		$entry   = self::$entries[ $block_name ] ?? null;
		$classes = null !== $entry ? $entry['classes'] : self::DEFAULT_CLASSES;

		return sprintf(
			'<section class="%1$s" data-pkiw-sections="%2$d"%3$s>%4$s<ul class="%5$s">%6$s</ul></section>',
			esc_attr( $classes['section'] ),
			max( 1, $count ),
			null !== $entry && $entry['expose_key'] ? ' data-pkiw-group="' . esc_attr( $group->slug() ) . '"' : '',
			self::heading( $classes['heading'], (int) ( $settings['level'] ?? 2 ), self::label( $source, $group, $settings ) ),
			esc_attr( $classes['items'] ),
			implode( '', $items )
		);
	}

	/**
	 * A section heading, level clamped to 2-4.
	 *
	 * @param string $classes Heading classes.
	 * @param int    $level   Heading level.
	 * @param string $label   Heading text.
	 * @return string
	 */
	private static function heading( string $classes, int $level, string $label ): string {
		$level = max( 2, min( 4, $level ) );

		return sprintf( '<h%1$d class="%2$s">%3$s</h%1$d>', $level, esc_attr( $classes ), esc_html( $label ) );
	}

	/**
	 * What a Post Template needs to print sections, or null when it isn't grouped.
	 *
	 * @param \WP_Block $block Post Template block.
	 * @return array{query:\WP_Query, source:Group_Source, settings:array<string, mixed>, entry:string}|null
	 */
	private static function section_plan( \WP_Block $block ): ?array {
		$entry = self::find_entry( (array) ( $block->parsed_block['innerBlocks'] ?? [] ) );
		if ( null === $entry ) {
			return null;
		}
		$name     = (string) $entry['blockName'];
		$settings = self::settings( $name, (array) ( $entry['attrs'] ?? [] ) );
		if ( ! $settings['show'] ) {
			return null;
		}

		if ( ! empty( $block->context['query']['inherit'] ) ) {
			global $wp_query;
			if ( ! $wp_query instanceof \WP_Query ) {
				return null;
			}
			// As core does: inside the main loop, work on a copy from its start.
			$query = $wp_query;
			if ( in_the_loop() ) {
				$query = clone $wp_query;
				$query->rewind_posts();
			}
		} else {
			$kind = (string) ( $block->context['query']['pkiwGroupBy'] ?? '' );
			if ( null === self::entry_source( $name ) && ( '' === $kind || null === self::kind_source( $kind, null ) ) ) {
				return null;
			}
			$page_key = isset( $block->context['queryId'] ) ? 'query-' . $block->context['queryId'] . '-page' : 'query-page';
			$page     = empty( $_GET[ $page_key ] ) ? 1 : (int) $_GET[ $page_key ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own page parameter for a Query Loop, read as core reads it.
			$query    = new \WP_Query( build_query_vars_from_query_block( $block, $page ) );
		}

		$source = self::query_source( $query );
		if ( null === $source || ! $query->have_posts() ) {
			return null;
		}

		return [
			'query'    => $query,
			'source'   => $source,
			'settings' => $settings,
			'entry'    => $name,
		];
	}

	/**
	 * The first entry block inside the Post Template of an inheriting Query Loop.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private static function main_entry( array $blocks ): ?array {
		foreach ( $blocks as $parsed ) {
			if ( 'core/query' === ( $parsed['blockName'] ?? '' ) && ! empty( $parsed['attrs']['query']['inherit'] ) ) {
				$template = self::find_named( (array) ( $parsed['innerBlocks'] ?? [] ), 'core/post-template' );
				$entry    = null !== $template ? self::find_entry( (array) ( $template['innerBlocks'] ?? [] ) ) : null;
				if ( null !== $entry ) {
					return $entry;
				}
			}
			$found = self::main_entry( (array) ( $parsed['innerBlocks'] ?? [] ) );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * The first registered entry block in a parsed block list, at any depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private static function find_entry( array $blocks ): ?array {
		foreach ( $blocks as $parsed ) {
			if ( isset( self::$entries[ (string) ( $parsed['blockName'] ?? '' ) ] ) ) {
				return $parsed;
			}
			$found = self::find_entry( (array) ( $parsed['innerBlocks'] ?? [] ) );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * The first block of a given name in a parsed block list, at any depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $name   Block name.
	 * @return array<string, mixed>|null
	 */
	private static function find_named( array $blocks, string $name ): ?array {
		foreach ( $blocks as $parsed ) {
			if ( ( $parsed['blockName'] ?? '' ) === $name ) {
				return $parsed;
			}
			$found = self::find_named( (array) ( $parsed['innerBlocks'] ?? [] ), $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * The kind an editor preview renders against: the one the request names
	 * when the post has it, else the post's first kind.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function preview_kind( int $post_id ): string {
		$kinds = wp_get_object_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
		$kinds = is_wp_error( $kinds ) ? [] : array_map( 'strval', (array) $kinds );
		if ( '' !== self::$preview_kind && in_array( self::$preview_kind, $kinds, true ) ) {
			return self::$preview_kind;
		}

		return $kinds[0] ?? '';
	}

	/**
	 * The items of the section a post opens, for an entry the editor renders
	 * on its own; empty when the post continues a section.
	 *
	 * Reads the kind's posts in grouped order. A post opens a section when it
	 * opens a page or its group differs from the post before, and the section
	 * runs to the next group or the end of the page.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param string               $kind     Kind slug.
	 * @param Group_Source         $source   Source.
	 * @param array<string, mixed> $settings Entry settings.
	 * @return int[]
	 */
	private static function preview_run( int $post_id, string $kind, Group_Source $source, array $settings ): array {
		$vars  = self::query_vars( $source, $settings );
		$cache = $kind . '|' . implode( '|', $vars );
		if ( ! isset( self::$preview_order[ $cache ] ) ) {
			$taxonomy                      = get_taxonomy( Taxonomy::TAXONOMY );
			self::$preview_order[ $cache ] = array_map(
				'intval',
				get_posts(
					[
						'post_type'         => $taxonomy ? $taxonomy->object_type : 'post',
						'post_status'       => 'publish',
						'posts_per_page'    => -1,
						'fields'            => 'ids',
						'no_found_rows'     => true,
						'suppress_filters'  => false,
						'orderby'           => [
							'date' => 'DESC',
							'ID'   => 'DESC',
						],
						'pkiw_group_by'     => $vars['pkiw_group_by'] ?? '',
						'pkiw_group_source' => $vars['pkiw_group_source'] ?? '',
						'pkiw_group_order'  => $vars['pkiw_group_order'],
						'pkiw_group_empty'  => $vars['pkiw_group_empty'],
						'tax_query'         => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the archive is a kind's posts.
							[
								'taxonomy' => Taxonomy::TAXONOMY,
								'field'    => 'slug',
								'terms'    => $kind,
							],
						],
					]
				)
			);
		}

		$order = self::$preview_order[ $cache ];
		$index = array_search( $post_id, $order, true );
		if ( false === $index ) {
			self::$preview_sections = 1;
			return [ $post_id ];
		}

		$group    = static function ( int $id ) use ( $source ): string {
			$post = get_post( $id );

			return $post instanceof \WP_Post ? $source->group_of( $post )->key() : '';
		};
		$per_page = (int) $settings['per_page'] > 0 ? (int) $settings['per_page'] : ( Kind_Archive_Layouts::preview_page_sizes()[ $kind ] ?? (int) get_option( 'posts_per_page' ) );
		$first    = $per_page > 0 ? $index - ( $index % $per_page ) : 0;
		$end      = $per_page > 0 ? min( count( $order ), $first + $per_page ) : count( $order );
		$key      = $group( $post_id );
		if ( $index > $first && $key === $group( $order[ $index - 1 ] ) ) {
			return [];
		}

		// The page's sections: one for each change of group across its items.
		$page                   = array_map( $group, array_slice( $order, $first, $end - $first ) );
		self::$preview_sections = 0;
		foreach ( $page as $at => $name ) {
			if ( 0 === $at || $name !== $page[ $at - 1 ] ) {
				++self::$preview_sections;
			}
		}

		$run = [ $post_id ];
		foreach ( array_slice( $order, $index + 1, $end - $index - 1 ) as $next ) {
			if ( $key !== $group( $next ) ) {
				break;
			}
			$run[] = $next;
		}

		return $run;
	}
}

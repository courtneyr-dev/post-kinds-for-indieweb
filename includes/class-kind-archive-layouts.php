<?php
/**
 * Kind archive layout primitives (issue 233).
 *
 * Structure only: shelf and menu layouts as core block styles on
 * core/post-template, Query Loop variations the editor can insert, one
 * neutral token-driven stylesheet, a grouping query var for menu archives,
 * and the menu entry block. Themes own paint and decoration.
 *
 * Why one custom block: a menu line needs a section heading derived from
 * the previous loop item's group, a venue name gated by
 * Meta_Fields::get_visible_location_fields(), and the same p-ate/p-drank
 * h-food the card emits. No core block or style can derive a heading from
 * loop order or apply the privacy rule, so the menu entry is a dynamic
 * block used inside core/post-template; everything else is core blocks.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers kind archive layouts and grouped ordering.
 */
final class Kind_Archive_Layouts {

	/**
	 * Default group field (post meta key) per kind for menu sections.
	 *
	 * Listen and watch stay chronological (approved designs in issues 226 and 227);
	 * recipe courses come from WP Recipe Maker at render time, never from a
	 * PKIW copy (issue 229), so neither is listed here.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_GROUP_FIELDS = [
		'eat'   => '_pkiw_eat_cuisine',
		'drink' => '_pkiw_drink_type',
	];

	/**
	 * Menu entry block name.
	 */
	public const MENU_ENTRY = 'post-kinds-indieweb/menu-entry';

	/**
	 * Recent Specials block name.
	 */
	public const MENU_SPECIALS = 'post-kinds-indieweb/menu-specials';

	/**
	 * REST `orderby` values for a kind in menu order: section direction,
	 * then where posts with no group go.
	 *
	 * @since 1.9.0
	 * @var array<string, array{0:string,1:string}>
	 */
	public const REST_MENU_ORDERS = [
		'pkiw_group'                  => [ 'ASC', 'last' ],
		'pkiw_group_desc'             => [ 'DESC', 'last' ],
		'pkiw_group_empty_first'      => [ 'ASC', 'first' ],
		'pkiw_group_desc_empty_first' => [ 'DESC', 'first' ],
	];

	/**
	 * Core's own render callback for the Post Template block.
	 *
	 * @var callable|null
	 */
	private static $core_post_template = null;

	/**
	 * Resolved-template content per kind term ID for this request.
	 *
	 * @var array<int, string|null>
	 */
	private array $template_cache = [];

	/**
	 * Whether a block-renderer request is in progress: the editor previewing a block.
	 *
	 * @var bool
	 */
	private static bool $block_preview = false;

	/**
	 * How many sections the page of the last previewed menu line holds.
	 *
	 * @var int
	 */
	private static int $preview_sections = 0;

	/**
	 * Whether the Post Template is rendering menu lines into sections.
	 *
	 * @var bool
	 */
	private static bool $sectioning = false;

	/**
	 * Post IDs of a menu kind in menu order, remembered for one editor render.
	 *
	 * @var array<string, int[]>
	 */
	private static array $preview_order = [];

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( did_action( 'init' ) ) {
			$this->register_assets_and_blocks();
		} else {
			add_action( 'init', [ $this, 'register_assets_and_blocks' ] );
		}
		add_filter( 'get_block_type_variations', [ $this, 'add_query_variations' ], 10, 2 );
		add_action( 'pre_get_posts', [ $this, 'maybe_group_main_query' ] );
		add_filter( 'posts_orderby', [ $this, 'group_orderby' ], 10, 2 );
		add_filter( 'query_loop_block_query_vars', [ $this, 'query_block_group_by' ], 10, 2 );
		if ( did_action( 'init' ) && ! doing_action( 'init' ) ) {
			$this->section_post_template();
		} else {
			add_action( 'init', [ $this, 'section_post_template' ], 20 );
		}
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_template_preview' ] );
		add_action( 'parse_request', [ $this, 'forget_templates' ] );
		add_filter( 'rest_request_before_callbacks', [ $this, 'track_block_preview' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'track_block_preview' ], 10, 3 );
		if ( taxonomy_exists( Taxonomy::TAXONOMY ) ) {
			$this->register_rest_menu_order();
		} else {
			add_action( 'init', [ $this, 'register_rest_menu_order' ], 11 );
		}
	}

	/**
	 * Hook the menu order into the REST route of each post type that takes a kind.
	 *
	 * Runs once the kind taxonomy exists (it registers on `init` at 5),
	 * which it may not when register() runs, and before core builds its
	 * REST routes.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register_rest_menu_order(): void {
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		foreach ( $taxonomy ? $taxonomy->object_type : [] as $post_type ) {
			add_filter( "rest_{$post_type}_collection_params", [ $this, 'rest_collection_params' ] );
			add_filter( "rest_{$post_type}_query", [ $this, 'rest_menu_order' ], 10, 2 );
		}
	}

	/**
	 * Note when the editor asks the server to render a block.
	 *
	 * The editor renders each menu line in a request of its own, so the
	 * line can't tell from the one before it whether it starts a section.
	 * render_menu_entry() reads this and works it out from the menu's order.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed            $response Response so far, passed through.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function track_block_preview( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wp/v2/block-renderer/' ) ) {
			self::$block_preview = 'rest_request_before_callbacks' === current_filter();
			self::$preview_order = [];
		}

		return $response;
	}

	/**
	 * Whether the block renderer route is running: the editor previewing a block.
	 *
	 * Reads the route being dispatched, so a rest_do_request() from PHP
	 * counts as well as the editor's HTTP request.
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	public static function is_block_preview(): bool {
		return self::$block_preview;
	}

	/**
	 * Let the REST posts routes order a menu kind the way its archive does.
	 *
	 * `orderby=pkiw_group` with one menu kind in the request returns that
	 * kind's posts by group, then date, then ID. The Site Editor's preview
	 * of a menu template asks for it (assets/js/kind-template-preview.js).
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $params Collection parameters.
	 * @return array<string, mixed>
	 */
	public function rest_collection_params( $params ) {
		if ( isset( $params['orderby']['enum'] ) && is_array( $params['orderby']['enum'] ) ) {
			array_push( $params['orderby']['enum'], ...array_keys( self::REST_MENU_ORDERS ) );
		}

		return $params;
	}

	/**
	 * Turn `orderby=pkiw_group` into the grouped order for the request's kind.
	 *
	 * With no kind, several kinds, or a kind that has no group field, the
	 * posts come back newest first.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $args    Query arguments.
	 * @param \WP_REST_Request     $request Request.
	 * @return array<string, mixed>
	 */
	public function rest_menu_order( $args, $request ) {
		$menu_order = self::REST_MENU_ORDERS[ (string) $request['orderby'] ] ?? null;
		if ( null === $menu_order ) {
			return $args;
		}

		$args['orderby'] = 'date';
		$args['order']   = 'DESC';

		$term_ids = array_values( array_filter( array_map( 'intval', (array) $request[ Taxonomy::TAXONOMY ] ) ) );
		$term     = 1 === count( $term_ids ) ? get_term( $term_ids[0], Taxonomy::TAXONOMY ) : null;
		$fields   = self::group_fields();
		if ( $term instanceof \WP_Term && isset( $fields[ $term->slug ] ) ) {
			$args['pkiw_group_by']    = $fields[ $term->slug ];
			$args['pkiw_group_order'] = $menu_order[0];
			$args['pkiw_group_empty'] = $menu_order[1];
		}

		return $args;
	}

	/**
	 * Forget which template each kind archive resolved to.
	 *
	 * The lookup is remembered for one request. A long-running process
	 * that serves several (a test run, a worker) starts each one fresh, so
	 * a theme switch or an edited template is read on the next request.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function forget_templates(): void {
		$this->template_cache = [];
	}

	/**
	 * Load the script that previews a kind's archive template with that kind's posts.
	 *
	 * Core previews an inherited Query Loop with the site's latest posts
	 * unless the template is a category, tag, post type or post format
	 * archive. `taxonomy-kind-<slug>` gets the same treatment here.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function enqueue_template_preview(): void {
		wp_enqueue_script(
			'pkiw-kind-template-preview',
			\PKIW_URL . 'assets/js/kind-template-preview.js',
			[ 'wp-hooks', 'wp-compose', 'wp-data', 'wp-core-data', 'wp-element' ],
			\PKIW_VERSION,
			true
		);
		wp_add_inline_script(
			'pkiw-kind-template-preview',
			'window.pkiwKindTemplatePreview = ' . wp_json_encode(
				[
					'perPage' => (object) self::preview_page_sizes(),
					'grouped' => array_keys( self::group_fields() ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Page sizes the editor preview uses, by kind slug.
	 *
	 * Core sets an inheriting Query Loop's page size to the site's
	 * setting in the editor. A site whose kind archive shows a different
	 * number per page says so through the filter, and the preview matches.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, int> Sizes above zero, keyed by kind slug.
	 */
	public static function preview_page_sizes(): array {
		$terms = get_terms(
			[
				'taxonomy'   => Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'slugs',
			]
		);
		$sizes = [];
		foreach ( is_array( $terms ) ? $terms : [] as $slug ) {
			/**
			 * Filters how many posts the editor previews on a kind's archive template.
			 *
			 * Return the number the kind's archive shows per page on the
			 * front end. Zero keeps the editor's own page size.
			 *
			 * @since 1.9.0
			 *
			 * @param int    $per_page Posts per page. Default 0.
			 * @param string $slug     Kind slug.
			 */
			$size = (int) apply_filters( 'pkiw_kind_archive_preview_per_page', 0, (string) $slug );
			if ( $size > 0 ) {
				$sizes[ (string) $slug ] = $size;
			}
		}

		return $sizes;
	}

	/**
	 * Kind => group meta key map.
	 *
	 * @return array<string, string>
	 */
	public static function group_fields(): array {
		/**
		 * Filters the meta key each kind's menu archive groups by.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string, string> $fields Kind slug => post meta key.
		 */
		return array_filter( (array) apply_filters( 'pkiw_archive_group_fields', self::DEFAULT_GROUP_FIELDS ), 'is_string' );
	}

	/**
	 * Register block styles, the stylesheet and the menu entry block.
	 *
	 * @return void
	 */
	public function register_assets_and_blocks(): void {
		$css = \PKIW_PATH . 'styles/kind-layouts.css';
		if ( file_exists( $css ) ) {
			$deps = wp_style_is( 'pkiw-kind-tokens', 'registered' ) ? [ 'pkiw-kind-tokens' ] : [];
			wp_register_style( 'pkiw-kind-layouts', \PKIW_URL . 'styles/kind-layouts.css', $deps, (string) filemtime( $css ) );
			wp_style_add_data( 'pkiw-kind-layouts', 'path', $css );
			wp_enqueue_block_style( 'core/post-template', [ 'handle' => 'pkiw-kind-layouts' ] );
		}

		$styles = [
			'pkiw-shelf'       => __( 'Shelf, face-out', 'post-kinds-for-indieweb-in-block-themes' ),
			'pkiw-shelf-spine' => __( 'Shelf, spine-out', 'post-kinds-for-indieweb-in-block-themes' ),
			'pkiw-menu'        => __( 'Menu', 'post-kinds-for-indieweb-in-block-themes' ),
		];
		foreach ( $styles as $name => $label ) {
			register_block_style(
				'core/post-template',
				[
					'name'  => $name,
					'label' => $label,
				]
			);
		}

		wp_register_script(
			'pkiw-menu-entry-editor',
			\PKIW_URL . 'assets/js/menu-entry-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-menu-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::MENU_ENTRY ) ) {
			// No block.json, so declare apiVersion 3 here (register_block_type()
			// otherwise defaults it to 1). The WP stubs type api_version as a
			// string; core compares it numerically.
			/**
			 * Block type arguments.
			 *
			 * @var array<string, mixed> $args
			 */
			$args = [
				'api_version'     => 3,
				'title'           => __( 'Kind menu entry', 'post-kinds-for-indieweb-in-block-themes' ),
				'render_callback' => [ self::class, 'render_menu_entry' ],
				'uses_context'    => [ 'postId', 'postType', 'queryId' ],
				'attributes'      => [
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
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'ancestor'        => [ 'core/post-template' ],
				'editor_script'   => 'pkiw-menu-entry-editor',
				'style'           => 'pkiw-kind-layouts',
			];
			register_block_type( self::MENU_ENTRY, $args );
		}

		wp_register_script(
			'pkiw-menu-specials-editor',
			\PKIW_URL . 'assets/js/menu-specials-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-menu-specials-editor', 'post-kinds-for-indieweb-in-block-themes' );
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::MENU_SPECIALS ) ) {
			/**
			 * Block type arguments. No block.json, so apiVersion 3 is declared here.
			 *
			 * @var array<string, mixed> $args
			 */
			$args = [
				'api_version'     => 3,
				'title'           => __( 'Recent Specials', 'post-kinds-for-indieweb-in-block-themes' ),
				'render_callback' => [ self::class, 'render_menu_specials' ],
				'attributes'      => [
					'kind'         => [
						'type'    => 'string',
						'default' => '',
					],
					'count'        => [
						'type'    => 'integer',
						'default' => 2,
					],
					'showPhotos'   => [
						'type'    => 'boolean',
						'default' => true,
					],
					'headingLevel' => [
						'type'    => 'integer',
						'default' => 2,
					],
				],
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-menu-specials-editor',
				'style'           => 'pkiw-kind-layouts',
			];
			register_block_type( self::MENU_SPECIALS, $args );
		}
	}

	/**
	 * Render Recent Specials: the newest posts of a menu kind, above the menu.
	 *
	 * The kind comes from the `kind` attribute, or from the kind archive
	 * being shown. Each special links to its post and prints the note, the
	 * venue under the location privacy rule, the date and a text rating.
	 * It carries no microformats root: the menu line below is the post's
	 * entry on the page. Past the first page the block prints nothing.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_menu_specials( array $attributes = [] ): string {
		$kind = sanitize_key( (string) ( $attributes['kind'] ?? '' ) );
		if ( '' === $kind && is_tax( Taxonomy::TAXONOMY ) ) {
			$term = get_queried_object();
			$kind = $term instanceof \WP_Term ? $term->slug : '';
		}
		if ( ! isset( self::group_fields()[ $kind ] ) || is_paged() ) {
			return '';
		}

		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		$posts    = get_posts(
			[
				'post_type'      => $taxonomy ? $taxonomy->object_type : 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 6, (int) ( $attributes['count'] ?? 2 ) ) ),
				'orderby'        => [
					'date' => 'DESC',
					'ID'   => 'DESC',
				],
				'no_found_rows'  => true,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the specials are the kind's newest posts.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $kind,
					],
				],
			]
		);
		if ( ! $posts ) {
			return '';
		}

		$level       = max( 2, min( 5, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$show_photos = (bool) ( $attributes['showPhotos'] ?? true );
		$items       = '';
		foreach ( $posts as $post ) {
			$items .= self::menu_special( $post, $kind, $level + 1, $show_photos );
		}

		$heading_id = wp_unique_id( 'pkiw-menu-specials-' );

		return sprintf(
			'<section %1$s><h%2$d id="%3$s" class="pkiw-menu-specials__heading">%4$s</h%2$d><ul class="pkiw-menu-specials__list">%5$s</ul></section>',
			get_block_wrapper_attributes(
				[
					'class'           => 'pkiw-menu-specials',
					'aria-labelledby' => $heading_id,
				]
			),
			$level,
			esc_attr( $heading_id ),
			esc_html__( 'Recent Specials', 'post-kinds-for-indieweb-in-block-themes' ),
			$items
		);
	}

	/**
	 * One Recent Specials item.
	 *
	 * @param \WP_Post $post        Post.
	 * @param string   $kind        Kind slug.
	 * @param int      $level       Heading level for the item's name.
	 * @param bool     $show_photos Whether to print the photo.
	 * @return string
	 */
	private static function menu_special( \WP_Post $post, string $kind, int $level, bool $show_photos ): string {
		$visible = Meta_Fields::get_visible_location_fields( $post->ID );
		$f       = self::menu_fields( $post->ID, $kind, $visible );
		$name    = '' !== $f['name'] ? $f['name'] : get_the_title( $post );
		$rating  = max( 0, min( 5, $f['rating'] ) );

		$ts = $f['when'] ? strtotime( $f['when'] ) : false;
		if ( $ts ) {
			$iso     = gmdate( 'c', $ts );
			$display = wp_date( (string) get_option( 'date_format' ), $ts );
		} else {
			$iso     = (string) get_the_date( 'c', $post );
			$display = (string) get_the_date( '', $post );
		}

		// Venue identity is the "name" tier, as on the menu line. A meal
		// names its restaurant before the town; a drink names its brand
		// first, and a venue with the brand's name isn't said twice.
		$subs  = $f['subs'];
		$venue = ( '' !== $f['venue'] && ! empty( $visible['name'] ) ) ? $f['venue'] : '';
		if ( '' !== $venue && ! in_array( mb_strtolower( $venue ), array_map( 'mb_strtolower', $subs ), true ) ) {
			if ( 'eat' === $kind ) {
				array_unshift( $subs, $venue );
			} else {
				$subs[] = $venue;
			}
		}
		$meta = '';
		foreach ( $subs as $sub ) {
			$meta .= '<span class="pkiw-menu-specials__sub">' . esc_html( $sub ) . '</span> ';
		}
		$meta .= sprintf( '<time class="pkiw-menu-specials__date" datetime="%s">%s</time>', esc_attr( $iso ), esc_html( $display ) );

		// With photos on, a special that has none says so, and a theme can draw its placeholder there.
		$photo = $show_photos ? self::menu_special_photo( $post, $kind ) : '';
		$out   = $show_photos && '' === $photo
			? '<li class="pkiw-menu-specials__item pkiw-menu-specials__item--no-photo">'
			: '<li class="pkiw-menu-specials__item">';
		$out  .= $photo;
		$out  .= sprintf(
			'<div class="pkiw-menu-specials__body"><h%1$d class="pkiw-menu-specials__name"><a class="pkiw-menu-specials__link" href="%2$s">%3$s</a></h%1$d>',
			$level,
			esc_url( (string) get_permalink( $post ) ),
			esc_html( $name )
		);
		if ( '' !== $f['notes'] ) {
			$out .= '<p class="pkiw-menu-specials__notes">' . esc_html( $f['notes'] ) . '</p>';
		}
		$out .= '<p class="pkiw-menu-specials__meta">' . $meta . '</p>';
		if ( $rating > 0 ) {
			/* translators: %d: rating out of five. */
			$out .= '<p class="pkiw-menu-specials__rating">' . esc_html( sprintf( __( 'Rated %d of 5', 'post-kinds-for-indieweb-in-block-themes' ), $rating ) ) . '</p>';
		}

		return $out . '</div></li>';
	}

	/**
	 * A special's photo: the featured image, or the picture stored on its card.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $kind Kind slug.
	 * @return string Image markup, or an empty string.
	 */
	private static function menu_special_photo( \WP_Post $post, string $kind ): string {
		if ( has_post_thumbnail( $post ) ) {
			return get_the_post_thumbnail( $post, 'medium_large', [ 'class' => 'pkiw-menu-specials__photo' ] );
		}

		// The card keeps its picture and alt text in its own attributes;
		// a post made without the card may hold a URL in meta.
		$url = '';
		$alt = '';
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( 'post-kinds-indieweb/' . $kind . '-card' === $block['blockName'] ) {
				$url = trim( (string) ( $block['attrs']['photo'] ?? '' ) );
				$alt = (string) ( $block['attrs']['photoAlt'] ?? '' );
				break;
			}
		}
		if ( '' === $url ) {
			$url = trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . $kind . '_photo', true ) );
		}
		if ( '' === $url ) {
			return '';
		}

		return sprintf(
			'<img class="pkiw-menu-specials__photo" src="%s" alt="%s" loading="lazy" decoding="async" />',
			esc_url( $url ),
			esc_attr( $alt )
		);
	}

	/**
	 * Add the shelf and menu Query Loop variations.
	 *
	 * @param array<int, array<string, mixed>> $variations Variations.
	 * @param \WP_Block_Type                   $block_type Block type.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_query_variations( array $variations, \WP_Block_Type $block_type ): array {
		if ( 'core/query' !== $block_type->name ) {
			return $variations;
		}

		$query = [
			'perPage'  => null,
			'pages'    => 0,
			'offset'   => 0,
			'postType' => 'post',
			'order'    => 'desc',
			'orderBy'  => 'date',
			'author'   => '',
			'search'   => '',
			'exclude'  => [],
			'sticky'   => '',
			'inherit'  => true,
		];

		$tail = [
			[
				'core/query-pagination',
				[
					'paginationArrow' => 'arrow',
					'layout'          => [
						'type'           => 'flex',
						'justifyContent' => 'center',
					],
				],
				[
					[ 'core/query-pagination-previous' ],
					[ 'core/query-pagination-numbers' ],
					[ 'core/query-pagination-next' ],
				],
			],
			[ 'core/query-no-results', [], [ [ 'core/paragraph', [ 'placeholder' => __( 'Text shown when there are no posts.', 'post-kinds-for-indieweb-in-block-themes' ) ] ] ] ],
		];

		$variations[] = [
			'name'        => 'pkiw-kind-shelf',
			'title'       => __( 'Kind shelf', 'post-kinds-for-indieweb-in-block-themes' ),
			'description' => __( 'A grid of posts, one linked item each, on shelf rows the theme can paint.', 'post-kinds-for-indieweb-in-block-themes' ),
			'icon'        => 'grid-view',
			'scope'       => [ 'inserter', 'block' ],
			'isActive'    => [ 'namespace' ],
			'attributes'  => [
				'namespace' => 'pkiw-kind-shelf',
				'className' => 'pkiw-kind-archive',
				'query'     => $query,
			],
			'innerBlocks' => array_merge(
				[
					[
						'core/post-template',
						[ 'className' => 'is-style-pkiw-shelf' ],
						[
							[
								'core/post-featured-image',
								[
									'aspectRatio' => '2/3',
									'sizeSlug'    => 'medium',
								],
							],
							[
								'core/post-title',
								[
									'level'  => 2,
									'isLink' => true,
								],
							],
							[ 'core/post-date' ],
						],
					],
				],
				$tail
			),
		];

		$variations[] = [
			'name'        => 'pkiw-kind-menu',
			'title'       => __( 'Kind menu', 'post-kinds-for-indieweb-in-block-themes' ),
			'description' => __( 'Menu lines with section headings and leaders, grouped by cuisine or drink type.', 'post-kinds-for-indieweb-in-block-themes' ),
			'icon'        => 'list-view',
			'scope'       => [ 'inserter', 'block' ],
			'isActive'    => [ 'namespace' ],
			'attributes'  => [
				'namespace' => 'pkiw-kind-menu',
				'className' => 'pkiw-kind-archive',
				'query'     => $query,
			],
			'innerBlocks' => array_merge(
				[
					[
						'core/post-template',
						[ 'className' => 'is-style-pkiw-menu' ],
						[ [ self::MENU_ENTRY ] ],
					],
				],
				$tail
			),
		];

		return $variations;
	}

	/**
	 * Order a kind archive's main query for the plugin layouts.
	 *
	 * Only when the block template that will render this archive uses a
	 * PKIW layout: then order date DESC, ID DESC (stable pages), and when it
	 * uses the menu entry and the kind has a group field, group first. A
	 * theme's own kind template or an explicit ?orderby= is left alone.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function maybe_group_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( Taxonomy::TAXONOMY ) ) {
			return;
		}
		if ( '' !== (string) $query->get( 'orderby' ) || ! wp_is_block_theme() ) {
			return;
		}

		$term = $query->get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$content = $this->resolved_template_content( $term );

		// A Check-ins Feed that inherits the archive sets the page size.
		$checkins_per_page = null === $content ? 0 : Checkin_Map::template_per_page( $content );
		if ( $checkins_per_page > 0 ) {
			$query->set( 'posts_per_page', $checkins_per_page );
			$query->set(
				'orderby',
				[
					'date' => 'DESC',
					'ID'   => 'DESC',
				]
			);
			return;
		}

		if ( null === $content || ! preg_match( '/is-style-pkiw-(?:shelf|shelf-spine|menu)|wp:post-kinds-indieweb\/menu-entry/', $content ) ) {
			return;
		}

		$query->set(
			'orderby',
			[
				'date' => 'DESC',
				'ID'   => 'DESC',
			]
		);

		if ( ! str_contains( $content, 'wp:' . self::MENU_ENTRY ) ) {
			return;
		}

		// The menu entry in the template carries the menu's settings.
		$settings = self::menu_settings( self::menu_entry_attrs( $content ) );
		if ( $settings['per_page'] > 0 ) {
			$query->set( 'posts_per_page', $settings['per_page'] );
		}

		$fields = self::group_fields();
		if ( isset( $fields[ $term->slug ] ) ) {
			$query->set( 'pkiw_group_by', $fields[ $term->slug ] );
			$query->set( 'pkiw_group_order', $settings['order'] );
			$query->set( 'pkiw_group_empty', $settings['empty'] );
		}
	}

	/**
	 * Attributes of the first menu entry block in a template's content.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Template content, patterns included.
	 * @return array<string, mixed>
	 */
	private static function menu_entry_attrs( string $content ): array {
		if ( 1 !== preg_match( '/<!--\s*wp:post-kinds-indieweb\/menu-entry\s+(\{.*?\})\s*\/?-->/s', $content, $found ) ) {
			return [];
		}
		$attrs = json_decode( $found[1], true );

		return is_array( $attrs ) ? $attrs : [];
	}

	/**
	 * A menu's settings, read from its menu entry block's attributes.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attrs Menu entry attributes.
	 * @return array{per_page:int,order:string,empty:string,label:string}
	 */
	private static function menu_settings( array $attrs ): array {
		return [
			'per_page' => max( 0, min( 100, (int) ( $attrs['linesPerPage'] ?? 0 ) ) ),
			'order'    => 'desc' === ( $attrs['sectionOrder'] ?? 'asc' ) ? 'DESC' : 'ASC',
			'empty'    => 'first' === ( $attrs['emptyGroup'] ?? 'last' ) ? 'first' : 'last',
			'label'    => sanitize_text_field( (string) ( $attrs['emptyLabel'] ?? '' ) ),
		];
	}

	/**
	 * Content of the block template core will resolve for a kind term.
	 *
	 * Mirrors get_taxonomy_template()'s hierarchy, then archive and index,
	 * through the public get_block_templates() (theme, user and plugin
	 * templates, with this plugin's precedence guard applied).
	 *
	 * @param \WP_Term $term Kind term.
	 * @return string|null
	 */
	private function resolved_template_content( \WP_Term $term ): ?string {
		if ( array_key_exists( $term->term_id, $this->template_cache ) ) {
			return $this->template_cache[ $term->term_id ];
		}

		$tax   = $term->taxonomy;
		$slugs = [];
		$dec   = urldecode( $term->slug );
		if ( $dec !== $term->slug ) {
			$slugs[] = "taxonomy-{$tax}-{$dec}";
		}
		$slugs[] = "taxonomy-{$tax}-{$term->slug}";
		$slugs[] = "taxonomy-{$tax}-{$term->term_id}";
		$slugs[] = "taxonomy-{$tax}";
		$slugs[] = 'taxonomy';
		$slugs[] = 'archive';
		$slugs[] = 'index';

		$priority  = array_flip( $slugs );
		$templates = get_block_templates( [ 'slug__in' => $slugs ] );
		usort(
			$templates,
			static fn( $a, $b ) => ( $priority[ $a->slug ] ?? 99 ) <=> ( $priority[ $b->slug ] ?? 99 )
		);

		$content                                = isset( $templates[0] ) ? self::with_patterns( (string) $templates[0]->content ) : null;
		$this->template_cache[ $term->term_id ] = $content;

		return $content;
	}

	/**
	 * Append the content of each pattern a template references.
	 *
	 * A theme often places its Query Loop through a pattern, so its
	 * template holds a `wp:pattern` reference and none of the loop's
	 * blocks. The layout checks above read the result.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Template content.
	 * @return string
	 */
	private static function with_patterns( string $content ): string {
		return (string) preg_replace_callback(
			'/<!--\s*wp:pattern\s+(\{.*?\})\s*\/-->/',
			static function ( array $reference ): string {
				$attrs   = json_decode( $reference[1], true );
				$slug    = is_array( $attrs ) ? (string) ( $attrs['slug'] ?? '' ) : '';
				$pattern = '' !== $slug ? \WP_Block_Patterns_Registry::get_instance()->get_registered( $slug ) : null;

				return $reference[0] . ( is_array( $pattern ) ? (string) ( $pattern['content'] ?? '' ) : '' );
			},
			$content
		);
	}

	/**
	 * Apply group ordering when `pkiw_group_by` names a known group field.
	 *
	 * ORDER BY: posts with an empty group last, group (case-insensitive)
	 * ascending, then post_date DESC, ID DESC. `pkiw_group_order` set to
	 * DESC reverses the groups and `pkiw_group_empty` set to "first" puts
	 * the posts with no group ahead of them. A correlated subquery keeps
	 * one row per post, so pagination counts are unaffected.
	 *
	 * @param string    $orderby ORDER BY clause.
	 * @param \WP_Query $query   Query.
	 * @return string
	 */
	public function group_orderby( $orderby, $query ) {
		if ( ! $query instanceof \WP_Query ) {
			return $orderby;
		}
		$key = (string) $query->get( 'pkiw_group_by' );
		if ( '' === $key || ! in_array( $key, self::group_fields(), true ) ) {
			return $orderby;
		}

		global $wpdb;
		$value = $wpdb->prepare(
			"COALESCE((SELECT pkiw_g.meta_value FROM {$wpdb->postmeta} pkiw_g WHERE pkiw_g.post_id = {$wpdb->posts}.ID AND pkiw_g.meta_key = %s ORDER BY pkiw_g.meta_id ASC LIMIT 1), '')",
			$key
		);

		$groups = 'DESC' === strtoupper( (string) $query->get( 'pkiw_group_order' ) ) ? 'DESC' : 'ASC';
		$empty  = 'first' === $query->get( 'pkiw_group_empty' ) ? 'DESC' : 'ASC';

		return "({$value} = '') {$empty}, LOWER({$value}) {$groups}, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
	}

	/**
	 * Non-inherited Query Loops opt into grouping with `query.pkiwGroupBy`
	 * set to a kind slug.
	 *
	 * @param array<string, mixed> $query_vars WP_Query args.
	 * @param \WP_Block            $block      Post Template block.
	 * @return array<string, mixed>
	 */
	public function query_block_group_by( $query_vars, $block ) {
		$kind   = (string) ( $block->context['query']['pkiwGroupBy'] ?? '' );
		$fields = self::group_fields();
		if ( '' !== $kind && isset( $fields[ $kind ] ) ) {
			$entry    = $block instanceof \WP_Block ? self::find_block( (array) ( $block->parsed_block['innerBlocks'] ?? [] ), self::MENU_ENTRY ) : null;
			$settings = self::menu_settings( (array) ( $entry['attrs'] ?? [] ) );

			$query_vars['pkiw_group_by']    = $fields[ $kind ];
			$query_vars['pkiw_group_order'] = $settings['order'];
			$query_vars['pkiw_group_empty'] = $settings['empty'];
		}
		return $query_vars;
	}

	/**
	 * Section heading label for a stored group value.
	 *
	 * @param string $kind        Kind slug.
	 * @param string $value       Stored group value.
	 * @param string $empty_label Label for posts with no group; when empty, "Drink" for drinks and "Other" for every other kind.
	 * @return string
	 */
	public static function group_label( string $kind, string $value, string $empty_label = '' ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			if ( '' !== trim( $empty_label ) ) {
				return trim( $empty_label );
			}

			return 'drink' === $kind ? __( 'Drink', 'post-kinds-for-indieweb-in-block-themes' ) : __( 'Other', 'post-kinds-for-indieweb-in-block-themes' );
		}

		if ( 'drink' === $kind ) {
			$labels = [
				'coffee'   => __( 'Coffee', 'post-kinds-for-indieweb-in-block-themes' ),
				'tea'      => __( 'Tea', 'post-kinds-for-indieweb-in-block-themes' ),
				'beer'     => __( 'Beer', 'post-kinds-for-indieweb-in-block-themes' ),
				'wine'     => __( 'Wine', 'post-kinds-for-indieweb-in-block-themes' ),
				'cocktail' => __( 'Cocktail', 'post-kinds-for-indieweb-in-block-themes' ),
				'juice'    => __( 'Juice', 'post-kinds-for-indieweb-in-block-themes' ),
				'soda'     => __( 'Soda', 'post-kinds-for-indieweb-in-block-themes' ),
				'smoothie' => __( 'Smoothie', 'post-kinds-for-indieweb-in-block-themes' ),
				'water'    => __( 'Water', 'post-kinds-for-indieweb-in-block-themes' ),
				'whiskey'  => __( 'Whiskey', 'post-kinds-for-indieweb-in-block-themes' ),
				'other'    => __( 'Other', 'post-kinds-for-indieweb-in-block-themes' ),
			];
			if ( isset( $labels[ strtolower( $value ) ] ) ) {
				return $labels[ strtolower( $value ) ];
			}
		}

		return mb_strtoupper( mb_substr( $value, 0, 1 ) ) . mb_substr( $value, 1 );
	}

	/**
	 * Render one menu line for the loop post.
	 *
	 * The editor previews each line in a request of its own. There a line
	 * that opens a section renders the whole section, and the lines after
	 * it in that section render a hidden marker, so the canvas holds the
	 * same section containers the front end prints.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string
	 */
	public static function render_menu_entry( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$post_id = (int) ( $block->context['postId'] ?? get_the_ID() );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$kind   = self::post_kind( $post_id );
		$fields = self::group_fields();
		if ( ! self::$block_preview || ! isset( $fields[ $kind ] ) || ! ( $attributes['showSections'] ?? true ) ) {
			return self::menu_entry_html( $post, $kind, $attributes, self::$sectioning );
		}

		$settings = self::menu_settings( $attributes );
		$run      = self::preview_run( $post_id, $kind, $fields[ $kind ], $settings );
		if ( [] === $run ) {
			return '<div class="pkiw-menu-entry pkiw-menu-entry--continued" hidden></div>';
		}

		$items = [];
		foreach ( $run as $id ) {
			$line = get_post( $id );
			if ( $line instanceof \WP_Post ) {
				$items[] = '<li>' . self::menu_entry_html( $line, $kind, $attributes, true ) . '</li>';
			}
		}

		return self::render_section( $kind, (string) get_post_meta( $post_id, $fields[ $kind ], true ), $items, (int) ( $attributes['headingLevel'] ?? 2 ), $settings['label'], self::$preview_sections );
	}

	/**
	 * A post's kind slug.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function post_kind( int $post_id ): string {
		$kinds = wp_get_object_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );

		return ( ! is_wp_error( $kinds ) && ! empty( $kinds ) ) ? (string) $kinds[0] : '';
	}

	/**
	 * One menu line: the dish or drink as a heading link, a leader, the
	 * rating, then venue, date and note.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post             $post       Post.
	 * @param string               $kind       Kind slug.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a section heading stands above the line.
	 * @return string
	 */
	private static function menu_entry_html( \WP_Post $post, string $kind, array $attributes, bool $in_section ): string {
		$post_id = (int) $post->ID;
		// A line's name is a heading one level under its section's.
		$level   = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$visible = Meta_Fields::get_visible_location_fields( $post_id );
		$f       = self::menu_fields( $post_id, $kind, $visible );

		$property = $f['property'];
		$name     = $f['name'];
		$subs     = $f['subs'];
		$venue    = $f['venue'];
		$rating   = $f['rating'];
		$when     = $f['when'];
		$notes    = $f['notes'];

		if ( '' === $name ) {
			$name = get_the_title( $post );
		}
		$rating = max( 0, min( 5, $rating ) );

		// Venue identity is the "name" tier: private posts show none to
		// visitors. Street, coordinates and venue URL never print here.
		// A venue with the brand's name is said once.
		$show_venue = '' !== $venue && ! empty( $visible['name'] ) && ! in_array( mb_strtolower( $venue ), array_map( 'mb_strtolower', $subs ), true );

		$ts = $when ? strtotime( $when ) : false;
		if ( $ts ) {
			$iso     = gmdate( 'c', $ts );
			$display = wp_date( (string) get_option( 'date_format' ), $ts );
		} else {
			$iso     = (string) get_the_date( 'c', $post );
			$display = (string) get_the_date( '', $post );
		}

		$root_class = $property ? 'pkiw-menu-entry__item p-' . $property . ' h-food' : 'pkiw-menu-entry__item';

		ob_start();
		?>
<div class="pkiw-menu-entry">
	<div class="<?php echo esc_attr( $root_class ); ?>">
		<div class="pkiw-menu-entry__line">
			<h<?php echo (int) $level; ?> class="pkiw-menu-entry__title"><a class="pkiw-menu-entry__name p-name" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( $name ); ?></a></h<?php echo (int) $level; ?>>
			<span class="pkiw-menu-entry__leader" aria-hidden="true"></span>
			<?php if ( $rating > 0 ) : ?>
				<span class="pkiw-menu-entry__rating">
				<?php
				/* translators: %d: rating out of five. */
				echo esc_html( sprintf( __( 'Rated %d of 5', 'post-kinds-for-indieweb-in-block-themes' ), $rating ) );
				?>
				</span>
				<data class="p-rating" value="<?php echo esc_attr( (string) $rating ); ?>" hidden></data>
			<?php endif; ?>
		</div>
		<p class="pkiw-menu-entry__meta">
			<?php // A meal names its restaurant before the town; a drink names its brand first. ?>
			<?php if ( $show_venue && 'eat' === $kind ) : ?>
				<span class="pkiw-menu-entry__sub p-location h-card"><span class="p-name"><?php echo esc_html( $venue ); ?></span></span>
			<?php endif; ?>
			<?php foreach ( $subs as $sub ) : ?>
				<span class="pkiw-menu-entry__sub"><?php echo esc_html( $sub ); ?></span>
			<?php endforeach; ?>
			<?php if ( $show_venue && 'eat' !== $kind ) : ?>
				<span class="pkiw-menu-entry__sub p-location h-card"><span class="p-name"><?php echo esc_html( $venue ); ?></span></span>
			<?php endif; ?>
			<time class="pkiw-menu-entry__date dt-published" datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $display ); ?></time>
		</p>
		<?php if ( '' !== $notes ) : ?>
			<p class="pkiw-menu-entry__notes p-content"><?php echo esc_html( $notes ); ?></p>
		<?php endif; ?>
	</div>
	<data class="u-url" value="<?php echo esc_url( get_permalink( $post ) ); ?>" hidden></data>
		<?php // The entry's own date and author. The visible date sits inside the food item and belongs to it. ?>
	<data class="dt-published" value="<?php echo esc_attr( (string) get_the_date( 'c', $post ) ); ?>" hidden></data>
		<?php
		$author_html = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';
		if ( '' !== $author_html ) :
			?>
	<span hidden><?php echo $author_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in entry_author_html(). ?></span>
		<?php endif; ?>
		<?php if ( $property ) : ?>
		<data class="u-<?php echo esc_attr( $property ); ?>" value="<?php echo esc_attr( $name ); ?>" hidden></data>
		<?php endif; ?>
</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Swap core's Post Template render callback for one that can print a
	 * grouped menu as section containers.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function section_post_template(): void {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'core/post-template' );
		if ( ! $type || ! is_callable( $type->render_callback ) || [ self::class, 'render_post_template' ] === $type->render_callback ) {
			return;
		}

		self::$core_post_template = $type->render_callback;
		$type->render_callback    = [ self::class, 'render_post_template' ];
	}

	/**
	 * Render a Post Template. A grouped menu prints one section per group:
	 * a heading and the list of that group's lines on this page. Anything
	 * else renders as core renders it.
	 *
	 * The posts are the ones the query already holds, so the inherited main
	 * query and core's pagination are untouched and no second query runs.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Block content.
	 * @param \WP_Block            $block      Block instance.
	 * @return string
	 */
	public static function render_post_template( $attributes, $content, $block ) {
		$plan = $block instanceof \WP_Block ? self::section_plan( $block ) : null;
		if ( null === $plan ) {
			return is_callable( self::$core_post_template ) ? (string) call_user_func( self::$core_post_template, $attributes, $content, $block ) : '';
		}

		$query    = $plan['query'];
		$enhanced = ! empty( $block->context['enhancedPagination'] );
		$sections = [];
		$last     = null;

		self::$sectioning = true;
		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id   = (int) get_the_ID();
			$post_type = (string) get_post_type();
			$raw       = trim( (string) get_post_meta( $post_id, $plan['field'], true ) );
			$key       = mb_strtolower( $raw );

			// As core renders a Post Template item: the inner blocks, with this post as their context.
			$instance              = $block->parsed_block;
			$instance['blockName'] = 'core/null';
			$context               = static function ( $context ) use ( $post_id, $post_type ) {
				$context['postType'] = $post_type;
				$context['postId']   = $post_id;
				return $context;
			};
			add_filter( 'render_block_context', $context, 1 );
			$inner = ( new \WP_Block( $instance ) )->render( [ 'dynamic' => false ] );
			remove_filter( 'render_block_context', $context, 1 );

			if ( $key !== $last ) {
				$sections[] = [
					'raw'   => $raw,
					'items' => [],
				];
				$last       = $key;
			}
			$sections[ array_key_last( $sections ) ]['items'][] = sprintf(
				'<li%1$s class="%2$s">%3$s</li>',
				$enhanced ? ' data-wp-key="post-template-item-' . $post_id . '"' : '',
				esc_attr( implode( ' ', get_post_class( 'wp-block-post' ) ) ),
				$inner
			);
		}
		self::$sectioning = false;
		wp_reset_postdata();

		$html = '';
		foreach ( $sections as $section ) {
			$html .= self::render_section( $plan['kind'], $section['raw'], $section['items'], $plan['level'], $plan['label'], count( $sections ) );
		}

		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes( [ 'class' => 'pkiw-menu--sectioned' ] ), $html );
	}

	/**
	 * What a Post Template needs to print sections, or null when it isn't
	 * a grouped menu: its query, the group field, the kind, the heading
	 * level and the label for posts with no group.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Block $block Post Template block.
	 * @return array{query:\WP_Query,field:string,kind:string,level:int,label:string}|null
	 */
	private static function section_plan( \WP_Block $block ): ?array {
		$entry = self::find_block( (array) ( $block->parsed_block['innerBlocks'] ?? [] ), self::MENU_ENTRY );
		if ( null === $entry || false === ( $entry['attrs']['showSections'] ?? true ) ) {
			return null;
		}

		$fields = self::group_fields();
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
			if ( ! isset( $fields[ (string) ( $block->context['query']['pkiwGroupBy'] ?? '' ) ] ) ) {
				return null;
			}
			$page_key = isset( $block->context['queryId'] ) ? 'query-' . $block->context['queryId'] . '-page' : 'query-page';
			$page     = empty( $_GET[ $page_key ] ) ? 1 : (int) $_GET[ $page_key ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's own page parameter for a Query Loop, read as core reads it.
			$query    = new \WP_Query( build_query_vars_from_query_block( $block, $page ) );
		}

		$field = (string) $query->get( 'pkiw_group_by' );
		$kind  = array_search( $field, $fields, true );
		if ( '' === $field || false === $kind || ! $query->have_posts() ) {
			return null;
		}

		$attrs = (array) ( $entry['attrs'] ?? [] );

		return [
			'query' => $query,
			'field' => $field,
			'kind'  => (string) $kind,
			'level' => (int) ( $attrs['headingLevel'] ?? 2 ),
			'label' => self::menu_settings( $attrs )['label'],
		];
	}

	/**
	 * The first block of a given name in a parsed block list, at any depth.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $name   Block name.
	 * @return array<string, mixed>|null
	 */
	private static function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $parsed ) {
			if ( ( $parsed['blockName'] ?? '' ) === $name ) {
				return $parsed;
			}
			$found = self::find_block( (array) ( $parsed['innerBlocks'] ?? [] ), $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * One menu section: its heading and the list of its lines.
	 *
	 * The section has no accessible name of its own, so it adds no landmark;
	 * its heading carries the structure.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $kind        Kind slug.
	 * @param string   $raw         Stored group value.
	 * @param string[] $items       The section's lines, each an `li`.
	 * @param int      $level       Heading level (clamped 2-4).
	 * @param string   $empty_label Label for posts with no group.
	 * @param int      $count       How many sections this page of the menu holds, so a
	 *                              theme can lay out as many columns as there are sections.
	 * @return string
	 */
	private static function render_section( string $kind, string $raw, array $items, int $level, string $empty_label, int $count ): string {
		$level = max( 2, min( 4, $level ) );

		return sprintf(
			'<section class="pkiw-menu-section" data-pkiw-sections="%4$d"><h%1$d class="pkiw-menu-section__heading pkiw-menu-entry__section">%2$s</h%1$d><ul class="pkiw-menu-section__items">%3$s</ul></section>',
			$level,
			esc_html( self::group_label( $kind, $raw, $empty_label ) ),
			implode( '', $items ),
			max( 1, $count )
		);
	}

	/**
	 * Kind-specific fields for a menu line, read from synced meta.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $kind    Kind slug.
	 * @param array<string, bool> $visible Visible location tiers.
	 * @return array{property:string,name:string,subs:string[],venue:string,rating:int,when:string,notes:string}
	 */
	private static function menu_fields( int $post_id, string $kind, array $visible ): array {
		$meta = static fn( string $key ): string => trim( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $key, true ) );
		$out  = [
			'property' => '',
			'name'     => '',
			'subs'     => [],
			'venue'    => '',
			'rating'   => 0,
			'when'     => '',
			'notes'    => '',
		];

		if ( 'eat' === $kind ) {
			$out['property'] = 'ate';
			$out['venue']    = $meta( 'eat_restaurant' ) ? $meta( 'eat_restaurant' ) : $meta( 'eat_location_name' );
			if ( ! empty( $visible['locality'] ) && $meta( 'eat_location_locality' ) ) {
				$out['subs'][] = $meta( 'eat_location_locality' );
			}
		} elseif ( 'drink' === $kind ) {
			$out['property'] = 'drank';
			$out['venue']    = $meta( 'drink_location_name' );
			if ( $meta( 'drink_brewery' ) ) {
				$out['subs'][] = $meta( 'drink_brewery' );
			}
		} else {
			return $out;
		}

		$out['name']   = $meta( $kind . '_name' );
		$out['rating'] = (int) round( (float) $meta( $kind . '_rating' ) );
		$out['when']   = $meta( 'eat' === $kind ? 'eat_ate_at' : 'drink_drank_at' );
		$out['notes']  = $meta( $kind . '_notes' );

		return $out;
	}

	/**
	 * The lines of the section a menu line opens, for a line the editor
	 * renders on its own; empty when the line continues a section.
	 *
	 * Reads the kind's posts in menu order. A line opens a section when it
	 * opens a page or its group differs from the line before, and the
	 * section runs to the next group or the end of the page.
	 *
	 * @since 1.9.0
	 *
	 * @param int                                                        $post_id  Post ID.
	 * @param string                                                     $kind     Kind slug.
	 * @param string                                                     $field    Group meta key.
	 * @param array{per_page:int,order:string,empty:string,label:string} $settings Menu settings.
	 * @return int[]
	 */
	private static function preview_run( int $post_id, string $kind, string $field, array $settings ): array {
		$cache = $kind . '|' . $settings['order'] . '|' . $settings['empty'];
		if ( ! isset( self::$preview_order[ $cache ] ) ) {
			$taxonomy                      = get_taxonomy( Taxonomy::TAXONOMY );
			self::$preview_order[ $cache ] = array_map(
				'intval',
				get_posts(
					[
						'post_type'        => $taxonomy ? $taxonomy->object_type : 'post',
						'post_status'      => 'publish',
						'posts_per_page'   => -1,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => false,
						'pkiw_group_by'    => $field,
						'pkiw_group_order' => $settings['order'],
						'pkiw_group_empty' => $settings['empty'],
						'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the menu is a kind's posts.
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

		$group    = static fn( int $id ): string => mb_strtolower( trim( (string) get_post_meta( $id, $field, true ) ) );
		$per_page = $settings['per_page'] > 0 ? $settings['per_page'] : ( self::preview_page_sizes()[ $kind ] ?? (int) get_option( 'posts_per_page' ) );
		$first    = $per_page > 0 ? $index - ( $index % $per_page ) : 0;
		$end      = $per_page > 0 ? min( count( $order ), $first + $per_page ) : count( $order );
		$key      = $group( $post_id );
		if ( $index > $first && $key === $group( $order[ $index - 1 ] ) ) {
			return [];
		}

		// The page's sections: one for each change of group across its lines.
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

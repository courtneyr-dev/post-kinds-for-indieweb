<?php
/**
 * Printable zine block, request hooks and print rendering.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own the printable zine request lifecycle. */
final class Zine_Export {
	// Public signatures below carry the callback and value types.
	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, WordPress.WP.I18n.MissingTranslatorsComment

	/** Dynamic export block name. */
	public const BLOCK = 'post-kinds-indieweb/zine-export';

	/**
	 * Active manifest.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $manifest = null;

	/**
	 * Active request.
	 *
	 * @var Zine_Request|null
	 */
	private static ?Zine_Request $request = null;

	/**
	 * Whether the block has rendered during this request.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Test-only response-header sink.
	 *
	 * @var callable|null
	 */
	private static $header_sink = null;

	/** Register all zine hooks. */
	public function register(): void {
		Zine_Manifest::register();
		add_filter( 'query_vars', [ self::class, 'query_vars' ] );
		add_action( 'pre_get_posts', [ self::class, 'filter_main_query' ], 999 );
		add_action( 'wp', [ self::class, 'resolve_request' ], 1 );
		add_action( 'wp', [ self::class, 'hide_admin_bar' ], 2 );
		add_action( 'template_redirect', [ self::class, 'send_privacy_headers' ], 0 );
		add_filter( 'template_include', [ self::class, 'template' ], 99 );
		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		add_action( 'init', [ self::class, 'register_block' ] );
		add_filter( 'perfmatters_rucss_excluded_stylesheets', [ self::class, 'exclude_rucss' ] );
	}

	/** Add the three public query variables. */
	public static function query_vars( array $vars ): array {
		return array_values( array_unique( array_merge( $vars, [ Zine_Request::VAR_PAGES, Zine_Request::VAR_FROM, Zine_Request::VAR_TO ] ) ) );
	}

	/** Register the editor script, structural style and dynamic block. */
	public static function register_block(): void {
		wp_register_script(
			'pkiw-zine-export-editor',
			\PKIW_URL . 'assets/js/zine-export-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-zine-export-editor', 'post-kinds-for-indieweb-in-block-themes' );
		$style_path = \PKIW_PATH . 'styles/zine.css';
		wp_register_style( 'pkiw-zine', \PKIW_URL . 'styles/zine.css', [], file_exists( $style_path ) ? (string) filemtime( $style_path ) : \PKIW_VERSION );
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}
		register_block_type(
			self::BLOCK,
			[
				'api_version'     => '3',
				'title'           => __( 'Export as Zine', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'attributes'      => [
					'source'       => [
						'type'    => 'string',
						'enum'    => [ 'archive', 'stream' ],
						'default' => 'archive',
					],
					'defaultPages' => [
						'type'    => 'integer',
						'enum'    => [ 8, 16 ],
						'default' => 8,
					],
				],
				'render_callback' => [ self::class, 'render_block' ],
				'editor_script'   => 'pkiw-zine-export-editor',
				'style'           => 'pkiw-zine',
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
			]
		);
	}

	/** Apply capacity, date and privacy rules to an archive main query. */
	public static function filter_main_query( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $query->is_tax( Taxonomy::TAXONOMY ) || $query->is_feed() || $query->is_embed() || wp_is_serving_rest_request() ) {
			return;
		}
		$request = Zine_Request::from_query( $query );
		if ( ! $request->is_valid() ) {
			return;
		}
		$term = $query->get_queried_object();
		if ( $term instanceof \WP_Term && in_array( $term->slug, Zine_Manifest::excluded_kinds(), true ) ) {
			return;
		}
		$args      = Public_Listing::query_args(
			[
				'paged'                       => 1,
				'posts_per_page'              => $request->capacity(),
				'no_found_rows'               => false,
				'ignore_sticky_posts'         => 1,
				// Replace, don't merge: calendar paging (Kind_Archive_Layouts, pre_get_posts 10) sets a per-page window here, and the zine ignores paged (C2).
				'date_query'                  => $request->date_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_date_query -- User-selected inclusive archive bounds.
				'pkiw_zine_narrow_visibility' => true,
			]
		);
		$tax_query = (array) $query->get( 'tax_query' );
		$excluded  = Zine_Manifest::excluded_kinds();
		if ( [] !== $excluded ) {
			$tax_query[]       = [
				'taxonomy' => Taxonomy::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $excluded,
				'operator' => 'NOT IN',
			];
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Excluded kinds are part of the selection contract.
		}
		foreach ( $args as $key => $value ) {
			$query->set( $key, $value );
		}
	}

	/** Resolve the source and build the active manifest after the main query. */
	public static function resolve_request(): void {
		self::$manifest = null;
		self::$rendered = false;
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			self::$request = null;
			return;
		}
		self::$request = Zine_Request::from_query( $wp_query );
		if ( ! self::$request->is_valid() || is_404() || is_feed() || is_embed() || wp_is_serving_rest_request() ) {
			return;
		}

		if ( is_tax( Taxonomy::TAXONOMY ) ) {
			$term = get_queried_object();
			if ( ! $term instanceof \WP_Term || in_array( $term->slug, Zine_Manifest::excluded_kinds(), true ) ) {
				return;
			}
			// Only a main query that filter_main_query() narrowed may feed a print view: its found_posts is the count.
			if ( true !== $wp_query->get( 'pkiw_public_listing' ) ) {
				return;
			}
			$result = Zine_Manifest::build(
				[
					'source' => 'archive',
					'kind'   => $term->slug,
					'pages'  => self::$request->pages(),
					'from'   => self::$request->from(),
					'to'     => self::$request->to(),
					'query'  => $wp_query,
				]
			);
		} elseif ( is_page() ) {
			$page = get_queried_object();
			if ( ! $page instanceof \WP_Post ) {
				return;
			}
			$result = Zine_Manifest::build(
				[
					'source'  => 'stream',
					'page_id' => $page->ID,
					'pages'   => self::$request->pages(),
					'from'    => self::$request->from(),
					'to'      => self::$request->to(),
				]
			);
		} else {
			return;
		}
		if ( is_array( $result ) ) {
			self::$manifest = $result;
		}
	}

	/** Disable the administrator toolbar before core initializes it. */
	public static function hide_admin_bar(): void {
		if ( self::is_print_mode() ) {
			show_admin_bar( false );
		}
	}

	/** Return the plugin or theme print template. */
	public static function template( string $template ): string {
		if ( ! self::is_print_mode() ) {
			return $template;
		}
		$override = locate_template( 'pkiw/zine-print.php' );
		$path     = '' !== $override ? $override : \PKIW_PATH . 'templates/zine-print.php';

		return (string) apply_filters( 'pkiw_zine_print_template', $path );
	}

	/** Add noindex to every request carrying a zine query variable. */
	public static function robots( array $robots ): array {
		if ( self::has_zine_vars() ) {
			$robots['noindex'] = true;
		}

		return $robots;
	}

	/** Send noindex and no-cache headers for valid, invalid and zero zine requests. */
	public static function send_privacy_headers(): void {
		if ( ! self::has_zine_vars() ) {
			return;
		}
		self::send_header( 'X-Robots-Tag: noindex' );
		if ( null !== self::$header_sink ) {
			foreach ( wp_get_nocache_headers() as $name => $value ) {
				if ( false !== $value ) {
					self::send_header( $name . ': ' . $value );
				}
			}
			return;
		}
		nocache_headers();
	}

	/**
	 * Replace the header emitter in tests.
	 *
	 * @param callable|null $sink Header receiver, or null to restore PHP headers.
	 */
	public static function override_header_sink_for_tests( ?callable $sink ): void {
		self::$header_sink = $sink;
	}

	/** Keep both conditional zine sheets out of used-CSS removal. */
	public static function exclude_rucss( array $styles ): array {
		foreach ( [ 'styles/zine.css', 'assets/css/cr-zine.css' ] as $style ) {
			if ( ! in_array( $style, $styles, true ) ) {
				$styles[] = $style;
			}
		}

		return $styles;
	}

	/**
	 * Return active archive filter variables for hidden form inputs.
	 *
	 * @param \WP_Query|null $query Query to inspect; defaults to the main query.
	 * @return array<string, string>
	 */
	public static function filter_vars( ?\WP_Query $query = null ): array {
		$query = $query ?? ( $GLOBALS['wp_query'] ?? null );
		if ( ! $query instanceof \WP_Query ) {
			return [];
		}
		$allowed = [
			Recipe_Archive::QUERY_VAR,
			Listen_Archive::QUERY_VAR,
			Read_Archive::QUERY_VAR,
			Comic_Strip::SERIES_VAR,
			Follow_Archive::VAR_INITIAL,
			Quote_Archive::VAR_AUTHOR,
			Quote_Archive::VAR_WORK,
			Quote_Archive::VAR_INITIAL,
		];
		$extra   = apply_filters( 'pkiw_zine_archive_query_vars', [] );
		if ( is_array( $extra ) ) {
			$allowed = array_merge( $allowed, array_map( 'sanitize_key', $extra ) );
		}
		// Archive filters rewrite the main query's orderby (an array with an ID tie-break), so read the request's own vars.
		$request = $query->is_main_query() && isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP ? (array) $GLOBALS['wp']->query_vars : [];
		$read    = static fn( string $name ): mixed => array_key_exists( $name, $request ) ? $request[ $name ] : $query->get( $name, null );
		$result  = [];
		foreach ( array_unique( $allowed ) as $name ) {
			$value = $read( $name );
			if ( null !== $value && is_scalar( $value ) && '' !== (string) $value ) {
				$result[ $name ] = sanitize_text_field( (string) $value );
			}
		}
		$orderby = $read( 'orderby' );
		$order   = $read( 'order' );
		$orderby = is_scalar( $orderby ) ? strtolower( (string) $orderby ) : '';
		$order   = is_scalar( $order ) ? strtolower( (string) $order ) : '';
		if ( in_array( $orderby, [ 'date', 'title' ], true ) ) {
			$result['orderby'] = $orderby;
		}
		if ( in_array( $order, [ 'asc', 'desc' ], true ) ) {
			$result['order'] = $order;
		}

		return $result;
	}

	/** Render the closed export panel or an open error or zero state. */
	public static function render_block( array $attributes ): string {
		$source = isset( $attributes['source'] ) && 'stream' === $attributes['source'] ? 'stream' : 'archive';
		// The editor's ServerSideRender request has no archive or page context: preview the closed panel (C5).
		$preview = Grouped_Archive::is_block_preview();
		if ( ! $preview && ( self::$rendered || ! self::source_matches( $source ) ) ) {
			return '';
		}
		if ( ! $preview ) {
			self::$rendered = true;
		}
		$request = $preview ? null : self::$request;
		if ( null === $request && ! $preview ) {
			global $wp_query;
			$request = $wp_query instanceof \WP_Query ? Zine_Request::from_query( $wp_query ) : null;
		}
		$errors  = $request instanceof Zine_Request ? $request->errors() : [];
		$zero    = $request instanceof Zine_Request && $request->is_valid() && is_array( self::$manifest ) && 0 === (int) self::$manifest['total'];
		$open    = [] !== $errors || $zero;
		$default = isset( $attributes['defaultPages'] ) && 16 === (int) $attributes['defaultPages'] ? 16 : 8;
		$pages   = $request instanceof Zine_Request && $request->is_present() ? $request->pages() : $default;
		$from    = $request instanceof Zine_Request ? $request->from() : '';
		$to      = $request instanceof Zine_Request ? $request->to() : '';
		$first   = $request instanceof Zine_Request ? $request->first_invalid() : null;
		$action  = $preview ? home_url( '/' ) : self::source_url( $source );
		$hidden  = '';
		foreach ( $preview ? [] : self::filter_vars() as $name => $value ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		$pages_error = self::error_markup( 'pages', $errors );
		$from_error  = self::error_markup( 'from', $errors );
		$to_error    = self::error_markup( 'to', $errors );
		$count       = $zero ? '<p class="pkiw-zine-export__count">' . esc_html__( '0 posts', 'post-kinds-for-indieweb-in-block-themes' ) . '</p>' : '';

		return '<details class="pkiw-zine-export"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Export as Zine', 'post-kinds-for-indieweb-in-block-themes' ) . '</summary>'
			. '<form method="get" action="' . esc_url( $action ) . '">' . $hidden
			. '<fieldset' . ( isset( $errors['pages'] ) ? ' aria-invalid="true" aria-describedby="pkiw-zine-error-pages"' : '' ) . '><legend>' . esc_html__( 'Booklet pages', 'post-kinds-for-indieweb-in-block-themes' ) . '</legend>'
			. self::radio( 8, $pages, '8 pages', 'pages' === $first ) . self::radio( 16, $pages, '16 pages', false ) . $pages_error . '</fieldset>'
			. self::date_field( 'from', __( 'From', 'post-kinds-for-indieweb-in-block-themes' ), Zine_Request::VAR_FROM, $from, $errors, 'from' === $first ) . $from_error
			. self::date_field( 'to', __( 'To', 'post-kinds-for-indieweb-in-block-themes' ), Zine_Request::VAR_TO, $to, $errors, 'to' === $first ) . $to_error
			. '<button type="submit" class="wp-element-button is-style-cr-cta">' . esc_html__( 'Preview zine', 'post-kinds-for-indieweb-in-block-themes' ) . '</button>' . $count
			. '</form></details>';
	}

	/** Return whether a print template should replace the source template. */
	public static function is_print_mode(): bool {
		return ! is_404() && is_array( self::$manifest ) && [] !== (array) ( self::$manifest['included'] ?? [] );
	}

	/** Return the active manifest for the print template. */
	public static function manifest(): array {
		return self::$manifest ?? [];
	}

	/** Render the toolbar and booklet markup. */
	public static function render_booklet(): string {
		$manifest = self::manifest();
		if ( [] === $manifest ) {
			return '';
		}
		$title   = self::source_title( $manifest );
		$back    = self::source_url( (string) $manifest['source'] );
		$count   = count( $manifest['included'] );
		$total   = (int) $manifest['total'];
		$line    = $total > $count
			? sprintf( __( '%1$d of %2$d posts', 'post-kinds-for-indieweb-in-block-themes' ), $count, $total )
			: sprintf( _n( '%d post', '%d posts', $count, 'post-kinds-for-indieweb-in-block-themes' ), $count );
		$toolbar = '<div class="pkiw-zine-toolbar"><a href="' . esc_url( $back ) . '">' . esc_html( sprintf( __( 'Back to %s', 'post-kinds-for-indieweb-in-block-themes' ), $title ) ) . '</a>'
			. '<p class="pkiw-zine-toolbar__count">' . esc_html( $line ) . '</p>'
			. '<p>' . esc_html( 8 === (int) $manifest['pages'] ? __( 'Sides: one', 'post-kinds-for-indieweb-in-block-themes' ) : __( 'Sides: both, flip on short edge', 'post-kinds-for-indieweb-in-block-themes' ) ) . '</p>'
			. '<p>' . esc_html__( 'Scale: 100%', 'post-kinds-for-indieweb-in-block-themes' ) . '</p><div data-pkiw-zine-print-button data-label="' . esc_attr__( 'Print', 'post-kinds-for-indieweb-in-block-themes' ) . '"></div>'
			. self::spread_preview( $manifest['imposition'] ) . '</div>';
		$slots   = [];
		foreach ( $manifest['imposition']['sides'] as $side ) {
			foreach ( $side['cells'] as $cell ) {
				$slots[ $cell['page'] ] = $cell + [ 'side' => $side['side'] ];
			}
		}
		$pages = '';
		for ( $page = 1; $page <= (int) $manifest['pages']; $page++ ) {
			$body   = 1 === $page ? self::cover( $manifest, $title ) : ( 2 === $page ? self::contents( $manifest ) : self::entry_page( $manifest, $page ) );
			$slot   = $slots[ $page ];
			$pages .= '<section class="pkiw-zine-page" data-pkiw-zine-page="' . esc_attr( (string) $page ) . '" data-side="' . esc_attr( (string) $slot['side'] ) . '" data-row="' . esc_attr( $slot['row'] ) . '" data-col="' . esc_attr( (string) $slot['col'] ) . '" data-inverted="' . ( $slot['inverted'] ? '1' : '0' ) . '">' . $body . '</section>';
		}

		return $toolbar . '<main><div class="pkiw-zine-pages">' . $pages . '</div></main>';
	}

	/** Register and retain only the print-view module after entries render. */
	public static function prepare_print_assets(): void {
		wp_enqueue_style( 'pkiw-zine' );
		wp_scripts()->queue = [];
		if ( function_exists( 'wp_script_modules' ) ) {
			foreach ( wp_script_modules()->get_queue() as $module ) {
				wp_dequeue_script_module( $module );
			}
			wp_register_script_module( 'pkiw-zine-print', \PKIW_URL . 'assets/js/zine-print.js', [], \PKIW_VERSION );
			wp_enqueue_script_module( 'pkiw-zine-print' );
		}
	}

	/** Render one entry page or an empty trailing page. */
	private static function entry_page( array $manifest, int $page_number ): string {
		$entry = null;
		foreach ( $manifest['entries'] as $candidate ) {
			if ( $page_number === (int) $candidate['page'] ) {
				$entry = $candidate;
				break;
			}
		}
		if ( ! is_array( $entry ) ) {
			return '<div class="pkiw-zine-page__content"></div>';
		}

		return self::render_entry( $entry, $page_number );
	}

	/**
	 * Render one manifest entry as it prints: heading, meta, place line, image, content and footer.
	 *
	 * Public so the privacy eval can compare renderings across users.
	 *
	 * @param array<string, mixed> $entry       One `entries` row of a manifest.
	 * @param int                  $page_number Folio.
	 */
	public static function render_entry( array $entry, int $page_number ): string {
		$post = get_post( (int) $entry['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return '<div class="pkiw-zine-page__content"></div>';
		}
		$meta = '<p class="pkiw-zine-entry__meta">';
		if ( is_string( $entry['kindLabel'] ) ) {
			$meta .= '<span class="pkiw-zine-entry__kind">' . esc_html( $entry['kindLabel'] ) . '</span> · ';
		}
		$meta .= esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) ) . ' · ' . esc_html( (string) $entry['date'] ) . '</p>';
		$image = has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, 'large', [ 'loading' => 'eager' ] ) : '';
		$place = self::place_line( $post->ID );
		$body  = self::post_type_has_kind( $post->post_type ) ? self::render_entry_content( $post ) : '';

		return '<header><h2>' . esc_html( $entry['title'] ) . '</h2>' . $meta . $place . '</header><div class="pkiw-zine-page__content">' . $image . $body . '</div><footer><span class="pkiw-zine-entry__permalink">' . esc_html( $entry['permalink'] ) . '</span><span class="pkiw-zine-entry__folio">' . esc_html( (string) $page_number ) . '</span></footer>';
	}

	/** Render one post's permitted blocks in its own post context. */
	private static function render_entry_content( \WP_Post $post ): string {
		$prior            = $GLOBALS['post'] ?? null;
		$prior_query_post = $GLOBALS['wp_query']->post ?? null;
		$skip             = static function ( $pre_render, array $parsed ) {
			return in_array( (string) ( $parsed['blockName'] ?? '' ), [ 'core/query', 'core/latest-posts', 'core/block', 'core/post-content' ], true ) ? '' : $pre_render;
		};
		$html             = '';
		try {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Entry blocks must render in their own post context.
			if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
				$GLOBALS['wp_query']->post = $post;
			}
			setup_postdata( $post );
			add_filter( 'pre_render_block', $skip, 10, 2 );
			$thumbnail = get_post_thumbnail_id( $post );
			foreach ( parse_blocks( $post->post_content ) as $parsed ) {
				$name = (string) ( $parsed['blockName'] ?? '' );
				if ( in_array( $name, [ 'core/query', 'core/latest-posts', 'core/block', 'core/post-content' ], true ) ) {
					continue;
				}
				if ( 'core/image' === $name && 0 < $thumbnail && (int) ( $parsed['attrs']['id'] ?? 0 ) === $thumbnail ) {
					continue;
				}
				$html .= ( new \WP_Block(
					$parsed,
					[
						'postId'   => $post->ID,
						'postType' => $post->post_type,
					]
				) )->render();
			}
		} finally {
			remove_filter( 'pre_render_block', $skip, 10 );
			if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof \WP_Query ) {
				$GLOBALS['wp_query']->post = $prior_query_post;
			}
			if ( $prior instanceof \WP_Post ) {
				$GLOBALS['post'] = $prior; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the saved post context.
				setup_postdata( $prior );
			} else {
				unset( $GLOBALS['post'] );
			}
			wp_reset_postdata();
			if ( ! $prior instanceof \WP_Post && ! $prior_query_post instanceof \WP_Post ) {
				foreach ( [ 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' ] as $global ) {
					unset( $GLOBALS[ $global ] );
				}
			}
		}
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( [ 'iframe', 'video', 'audio', 'source', 'track', 'object', 'embed', 'script', 'style', 'canvas', 'map', 'area', 'form', 'input', 'button', 'select', 'textarea' ] as $tag ) {
			unset( $allowed[ $tag ] );
		}
		$allowed = apply_filters( 'pkiw_zine_allowed_html', $allowed );
		// kses drops a disallowed element but keeps its text, so empty raw-text elements first.
		$raw = new \WP_HTML_Tag_Processor( $html );
		while ( $raw->next_tag() ) {
			if ( in_array( $raw->get_tag(), [ 'SCRIPT', 'STYLE', 'TEXTAREA' ], true ) && ! $raw->is_tag_closer() ) {
				$raw->set_modifiable_text( '' );
			}
		}
		$html = wp_kses( $raw->get_updated_html(), is_array( $allowed ) ? $allowed : [] );
		$tags    = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( 'IMG' === $tags->get_tag() ) {
				$tags->set_attribute( 'loading', 'eager' );
			}
			if ( $tags->has_class( 'leaflet-container' ) ) {
				$tags->remove_class( 'leaflet-container' );
			}
		}

		return $tags->get_updated_html();
	}

	/** Render the booklet cover. */
	private static function cover( array $manifest, string $title ): string {
		$heading = '<h1 class="pkiw-zine__title">' . esc_html( $title ) . '</h1>';
		$heading = (string) apply_filters( 'pkiw_zine_cover_title_html', $heading, $title );
		$span    = '';
		if ( isset( $manifest['span']['from'], $manifest['span']['to'] ) ) {
			$from = self::display_day( (string) $manifest['span']['from'] );
			$to   = self::display_day( (string) $manifest['span']['to'] );
			$span = $from === $to ? $from : sprintf( __( '%1$s to %2$s', 'post-kinds-for-indieweb-in-block-themes' ), $from, $to );
		}

		return '<header>' . $heading . '<p>' . esc_html( get_bloginfo( 'name' ) ) . '</p><p>' . esc_html( $span ) . '</p></header><div class="pkiw-zine-page__content"></div>';
	}

	/** Render the contents page. */
	private static function contents( array $manifest ): string {
		$items = '';
		foreach ( $manifest['contents'] as $item ) {
			$items .= '<li><span>' . esc_html( $item['title'] ) . '</span> <span>' . esc_html( (string) $item['page'] ) . '</span></li>';
		}

		return '<header><h2>' . esc_html__( 'Contents', 'post-kinds-for-indieweb-in-block-themes' ) . '</h2></header><div class="pkiw-zine-page__content"><ol>' . $items . '</ol></div>';
	}

	/** Render the screen-only imposition table. */
	private static function spread_preview( array $layout ): string {
		$tables = '';
		foreach ( $layout['sides'] as $side ) {
			$top    = [];
			$bottom = [];
			foreach ( $side['cells'] as $cell ) {
				if ( 'top' === $cell['row'] ) {
					$top[] = $cell['page'];
				} else {
					$bottom[] = $cell['page'];
				}
			}
			$tables .= '<table><caption>' . esc_html( sprintf( __( 'Sheet side %d', 'post-kinds-for-indieweb-in-block-themes' ), $side['side'] ) ) . '</caption><tbody><tr><th scope="row">' . esc_html__( 'Top row, printed upside down', 'post-kinds-for-indieweb-in-block-themes' ) . '</th>' . self::number_cells( $top ) . '</tr><tr><th scope="row">' . esc_html__( 'Bottom row', 'post-kinds-for-indieweb-in-block-themes' ) . '</th>' . self::number_cells( $bottom ) . '</tr></tbody></table>';
		}

		return '<figure class="pkiw-zine-spread"><figcaption>' . esc_html__( 'Printer spread preview', 'post-kinds-for-indieweb-in-block-themes' ) . '</figcaption>' . $tables . '</figure>';
	}

	/** Render page-number cells. */
	private static function number_cells( array $pages ): string {
		return implode( '', array_map( static fn( int $page ): string => '<td>' . esc_html( (string) $page ) . '</td>', $pages ) );
	}

	/** Render a public place line with only the four permitted tiers. */
	private static function place_line( int $post_id ): string {
		$view  = Meta_Fields::get_public_location_view( $post_id );
		$parts = [];
		$keys  = [
			'name'     => [ 'checkin_name', 'geo_name' ],
			'locality' => [ 'checkin_locality', 'geo_locality' ],
			'region'   => [ 'checkin_region', 'geo_region' ],
			'country'  => [ 'checkin_country', 'geo_country_name' ],
		];
		foreach ( $keys as $tier => $candidates ) {
			if ( empty( $view['visible'][ $tier ] ) ) {
				continue;
			}
			foreach ( $candidates as $key ) {
				if ( ! empty( $view['meta'][ $key ] ) ) {
					$parts[] = $view['meta'][ $key ];
					break;
				}
			}
		}

		return [] === $parts ? '' : '<p class="pkiw-zine-entry__place">' . esc_html( implode( ', ', $parts ) ) . '</p>';
	}

	/** Return whether a post type participates in the kind taxonomy. */
	private static function post_type_has_kind( string $post_type ): bool {
		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );

		return $taxonomy instanceof \WP_Taxonomy && in_array( $post_type, $taxonomy->object_type, true );
	}

	/** Return the source title. */
	private static function source_title( array $manifest ): string {
		if ( 'stream' === $manifest['source'] ) {
			return pkiw_get_safe_title( (int) $manifest['page_id'] );
		}
		$term = get_term_by( 'slug', (string) $manifest['kind'], Taxonomy::TAXONOMY );

		return $term instanceof \WP_Term ? $term->name : '';
	}

	/** Return the clean source URL. */
	private static function source_url( string $source ): string {
		if ( 'stream' === $source ) {
			$page = get_queried_object();
			return $page instanceof \WP_Post ? (string) get_permalink( $page ) : home_url( '/' );
		}
		$term = get_queried_object();
		$url  = $term instanceof \WP_Term ? get_term_link( $term ) : home_url( '/' );

		return is_wp_error( $url ) ? home_url( '/' ) : (string) $url;
	}

	/** Return whether the block source matches the current request. */
	private static function source_matches( string $source ): bool {
		if ( 'archive' === $source ) {
			$term = get_queried_object();
			return is_tax( Taxonomy::TAXONOMY ) && $term instanceof \WP_Term && ! in_array( $term->slug, Zine_Manifest::excluded_kinds(), true );
		}
		$page = get_queried_object();

		return is_page() && $page instanceof \WP_Post && Public_Listing::is_listable( $page ) && Zine_Manifest::passes_narrowing( $page ) && null !== Zine_Manifest::stream_query_vars( $page );
	}

	/** Render a page-count radio. */
	private static function radio( int $value, int $checked, string $label, bool $autofocus ): string {
		$id = 'pkiw-zine-pages-' . $value;

		return '<label for="' . esc_attr( $id ) . '"><input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( Zine_Request::VAR_PAGES ) . '" value="' . esc_attr( (string) $value ) . '"' . checked( $checked, $value, false ) . ( $autofocus ? ' autofocus' : '' ) . '> ' . esc_html( $label ) . '</label>';
	}

	/** Render a date input. */
	private static function date_field( string $field, string $label, string $name, string $value, array $errors, bool $autofocus ): string {
		$id = 'pkiw-zine-' . $field;

		return '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( isset( $errors[ $field ] ) ? ' aria-invalid="true" aria-describedby="pkiw-zine-error-' . esc_attr( $field ) . '"' : '' ) . ( $autofocus ? ' autofocus' : '' ) . '>';
	}

	/** Render one field error. */
	private static function error_markup( string $field, array $errors ): string {
		return isset( $errors[ $field ] ) ? '<p id="pkiw-zine-error-' . esc_attr( $field ) . '" class="pkiw-zine-export__error">' . esc_html( $errors[ $field ] ) . '</p>' : '';
	}

	/** Format a stored calendar day without timezone drift. */
	private static function display_day( string $day ): string {
		$timestamp = strtotime( $day . ' 12:00:00 UTC' );

		return false === $timestamp ? '' : (string) wp_date( 'F j, Y', $timestamp, new \DateTimeZone( 'UTC' ) );
	}

	/** Return whether any registered zine variable is present. */
	private static function has_zine_vars(): bool {
		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query ) {
			return false;
		}

		return null !== $wp_query->get( Zine_Request::VAR_PAGES, null )
			|| null !== $wp_query->get( Zine_Request::VAR_FROM, null )
			|| null !== $wp_query->get( Zine_Request::VAR_TO, null );
	}

	/** Emit one replacement response header. */
	private static function send_header( string $line ): void {
		if ( is_callable( self::$header_sink ) ) {
			call_user_func( self::$header_sink, $line );
		} elseif ( ! headers_sent() ) {
			header( $line, true );
		}
	}
}

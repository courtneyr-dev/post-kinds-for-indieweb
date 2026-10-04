<?php
/**
 * The recipe kind archive: course filter, stable title order and its heading.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Integrations\WP_Recipe_Maker;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the recipe archive a native WordPress query.
 *
 * A course filter is a public query var on the archive URL, and A-Z is
 * WordPress's own `orderby=title`. Both stay in the URL, so the pager
 * carries them from page to page with no extra state.
 */
final class Recipe_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'recipe';

	/**
	 * Public query var holding a course slug, as in `/kind/recipe/?pkiw_recipe_course=soup`.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'pkiw_recipe_course';

	/**
	 * The kind term's name as this plugin seeds it.
	 *
	 * @var string
	 */
	private const DEFAULT_TERM_NAME = 'Recipe';

	/**
	 * Block that links the archive's courses.
	 *
	 * @var string
	 */
	public const COURSES_BLOCK = 'post-kinds-indieweb/recipe-courses';

	/**
	 * Whether a block-renderer request is in progress: the editor previewing a block.
	 *
	 * @var bool
	 */
	private static bool $block_preview = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', [ $this, 'add_query_var' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_main_query' ] );
		add_filter( 'get_the_archive_title', [ $this, 'archive_title' ], 10, 2 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'track_block_preview' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'track_block_preview' ], 10, 3 );
		if ( did_action( 'init' ) ) {
			$this->register_courses_block();
		} else {
			add_action( 'init', [ $this, 'register_courses_block' ] );
		}
	}

	/**
	 * Register the Recipe courses block.
	 *
	 * Server-rendered, so the Site Editor prints the links the archive
	 * prints. A theme places it in its recipe archive template and styles
	 * it; the block ships a plain row of links.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register_courses_block(): void {
		wp_register_script(
			'pkiw-recipe-courses-editor',
			\PKIW_URL . 'assets/js/recipe-courses-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-recipe-courses-editor', 'post-kinds-for-indieweb-in-block-themes' );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::COURSES_BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Recipe courses', 'post-kinds-for-indieweb-in-block-themes' ),
			'render_callback' => [ self::class, 'render_courses_block' ],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-recipe-courses-editor',
		];
		register_block_type( self::COURSES_BLOCK, $args );

		if ( wp_style_is( 'pkiw-kind-layouts', 'registered' ) ) {
			wp_enqueue_block_style( self::COURSES_BLOCK, [ 'handle' => 'pkiw-kind-layouts' ] );
		}
	}

	/**
	 * Note when the editor asks the server to render a block.
	 *
	 * Hooked before and after a REST route's callback. The Recipe courses
	 * block reads this to show the archive as it opens, with "All" current.
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
		}

		return $response;
	}

	/**
	 * Render the Recipe courses block: All, each course in use, and the A-Z order.
	 *
	 * Every link is the recipe archive's own URL with a native query, so
	 * the pager keeps the choice. The link for the page being shown gets
	 * `aria-current="page"`; away from the archive none does.
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	public static function render_courses_block(): string {
		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$base = get_term_link( $term );
		if ( is_wp_error( $base ) ) {
			return '';
		}

		$on_archive = is_tax( Taxonomy::TAXONOMY, self::KIND );
		$course     = $on_archive ? sanitize_title( (string) get_query_var( self::QUERY_VAR ) ) : '';
		$orderby    = $on_archive ? get_query_var( 'orderby' ) : '';
		$by_title   = '' === $course && ( 'title' === $orderby || ( is_array( $orderby ) && isset( $orderby['title'] ) ) );

		$items = [
			[
				'label'   => __( 'All', 'post-kinds-for-indieweb-in-block-themes' ),
				'url'     => $base,
				'current' => ( $on_archive || self::$block_preview ) && '' === $course && ! $by_title,
			],
		];
		foreach ( recipe_archive_courses() as $entry ) {
			$items[] = [
				'label'   => $entry['name'],
				'url'     => $entry['url'],
				'current' => $on_archive && $entry['current'],
			];
		}
		$items[] = [
			'label'   => __( 'A–Z index', 'post-kinds-for-indieweb-in-block-themes' ),
			'url'     => add_query_arg(
				[
					'orderby' => 'title',
					'order'   => 'asc',
				],
				$base
			),
			'current' => $by_title,
		];

		$links = '';
		foreach ( $items as $item ) {
			$links .= sprintf(
				'<li class="pk-recipe-courses__item"><a class="pk-recipe-courses__link" href="%1$s"%2$s>%3$s</a></li>',
				esc_url( $item['url'] ),
				$item['current'] ? ' aria-current="page"' : '',
				esc_html( $item['label'] )
			);
		}

		return sprintf(
			'<nav %1$s><ul class="pk-recipe-courses__list">%2$s</ul></nav>',
			get_block_wrapper_attributes( [ 'aria-label' => __( 'Recipe courses', 'post-kinds-for-indieweb-in-block-themes' ) ] ),
			$links
		);
	}

	/**
	 * Make the course query var public.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Apply the course filter and a stable title order to the recipe archive.
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public function filter_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return;
		}

		$course = sanitize_title( (string) $query->get( self::QUERY_VAR ) );
		if ( '' !== $course ) {
			if ( WP_Recipe_Maker::is_supported_plugin_active() ) {
				// Courses live on the recipe post type, so the filter is a list of the posts that embed those recipes.
				$post_ids = WP_Recipe_Maker::post_ids_in_course( $course );
				$query->set( 'post__in', $post_ids ? $post_ids : [ 0 ] );
			} else {
				$tax_query   = (array) $query->get( 'tax_query' );
				$tax_query[] = [
					'taxonomy' => recipe_fallback_course_taxonomy(),
					'field'    => 'slug',
					'terms'    => $course,
				];
				$query->set( 'tax_query', $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the course filter is a taxonomy lookup.
			}
		}

		// Two recipes can share a title; without a tie-break they could swap pages.
		if ( 'title' === $query->get( 'orderby' ) ) {
			$order = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
			$query->set(
				'orderby',
				[
					'title' => $order,
					'ID'    => $order,
				]
			);
		}
	}

	/**
	 * Head the recipe archive "Recipes".
	 *
	 * The term stays "Recipe": one post is a recipe, and the kind picker
	 * and the term's slug read that way. The archive lists many. A site
	 * that renamed the term keeps its own name.
	 *
	 * @param string $title          Archive title, with any prefix.
	 * @param string $original_title Archive title without the prefix.
	 * @return string
	 */
	public function archive_title( $title, $original_title = '' ) {
		if ( ! is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return $title;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || self::DEFAULT_TERM_NAME !== $term->name ) {
			return $title;
		}

		$plural   = __( 'Recipes', 'post-kinds-for-indieweb-in-block-themes' );
		$singular = '' !== (string) $original_title ? (string) $original_title : $term->name;
		$position = strrpos( (string) $title, $singular );

		return false === $position ? $title : substr_replace( (string) $title, $plural, $position, strlen( $singular ) );
	}
}

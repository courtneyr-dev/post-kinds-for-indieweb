<?php
/**
 * Year-grouped trip and itinerary archive entries.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Date_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register travel archive sources, entry blocks and their rendering. */
final class Travel_Archive {

	public const SOURCE          = 'travel_year';
	public const TRIP_ENTRY      = 'post-kinds-indieweb/trip-entry';
	public const ITINERARY_ENTRY = 'post-kinds-indieweb/itinerary-entry';
	public const PER_PAGE        = 12;
	public const EDITOR_SCRIPT   = 'pkiw-travel-entry-editor';
	public const STYLE           = 'pkiw-travel-archive';

	/**
	 * Travel kind named by the active block-renderer request.
	 *
	 * @var string
	 */
	private static string $preview_kind = '';

	/** Wire the shared year source, page size and dynamic entry blocks. */
	public function register(): void {
		$source = new Date_Source( self::SOURCE, 'year' );
		Grouped_Archive::register_source( $source, Travel::TRIP );
		Grouped_Archive::register_source( $source, Travel::ITINERARY );
		add_filter( 'pkiw_kind_archive_preview_per_page', [ self::class, 'preview_per_page' ], 10, 2 );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'track_preview_kind' ], 11, 3 );
		add_filter( 'rest_request_after_callbacks', [ self::class, 'track_preview_kind' ], 11, 3 );
		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', [ self::class, 'register_blocks' ] );
		}
	}

	/**
	 * Both travel archives preview twelve entries per page.
	 *
	 * @param int    $per_page Current preview page size.
	 * @param string $kind     Kind slug.
	 */
	public static function preview_per_page( int $per_page, string $kind ): int {
		return in_array( $kind, [ Travel::TRIP, Travel::ITINERARY ], true ) ? self::PER_PAGE : $per_page;
	}

	/** Register the shared editor script and stylesheet once. */
	public static function register_editor_assets(): void {
		wp_register_script(
			self::EDITOR_SCRIPT,
			PKIW_URL . 'assets/js/travel-entry-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			PKIW_VERSION,
			true
		);
		wp_set_script_translations( self::EDITOR_SCRIPT, 'post-kinds-for-indieweb-in-block-themes' );
		wp_register_style( self::STYLE, PKIW_URL . 'styles/travel-archive.css', [], PKIW_VERSION );
	}

	/** Register both entry blocks with one grouped-archive contract. */
	public static function register_blocks(): void {
		self::register_editor_assets();
		foreach ( [
			self::TRIP_ENTRY      => Travel::TRIP,
			self::ITINERARY_ENTRY => Travel::ITINERARY,
		] as $name => $kind ) {
			Grouped_Archive::register_entry_block(
				$name,
				[
					'shape'       => Grouped_Archive::ENTRY_LINE,
					'source'      => self::SOURCE,
					'heading_ids' => true,
					'expose_key'  => true,
					'classes'     => [
						'wrapper'     => 'pkiw-travel--sectioned',
						'section'     => 'pkiw-travel-year',
						'heading'     => 'pkiw-travel-year__heading',
						'items'       => 'pkiw-travel-year__items',
						'placeholder' => 'pkiw-travel-entry pkiw-travel-entry--continued',
					],
				]
			);
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			/**
			 * Block type arguments.
			 *
			 * @var array<string, mixed> $args
			 */
			$args = [
				'api_version'     => 3,
				'title'           => Travel::TRIP === $kind ? __( 'Trip entry', 'post-kinds-for-indieweb-in-block-themes' ) : __( 'Itinerary entry', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => Travel::TRIP === $kind ? [ self::class, 'render_trip' ] : [ self::class, 'render_itinerary' ],
				'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
				'ancestor'        => [ 'core/post-template' ],
				'attributes'      => Grouped_Archive::entry_attributes(
					[
						'linesPerPage' => [ 'default' => self::PER_PAGE ],
						'headingLevel' => [ 'default' => 2 ],
					]
				),
				'supports'        => [
					'html'      => false,
					'reusable'  => false,
					'className' => true,
				],
				'editor_script'   => self::EDITOR_SCRIPT,
				'style'           => self::STYLE,
				'example'         => [ 'attributes' => [ 'linesPerPage' => 3 ] ],
			];
			register_block_type( $name, $args );
		}
	}

	/**
	 * Render one trip entry through the grouped archive engine.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused saved content.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render_trip( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return self::render_entry( self::TRIP_ENTRY, Travel::TRIP, $attributes, $block );
	}

	/**
	 * Render one itinerary entry through the grouped archive engine.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused saved content.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render_itinerary( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return self::render_entry( self::ITINERARY_ENTRY, Travel::ITINERARY, $attributes, $block );
	}

	/**
	 * Render one archive item.
	 *
	 * @param \WP_Post             $post       Travel post.
	 * @param string               $kind       Travel kind.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a year heading precedes the item.
	 */
	public static function entry_html( \WP_Post $post, string $kind, array $attributes, bool $in_section ): string {
		$level     = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$protected = post_password_required( $post );
		$title     = trim( visitor_post_title( $post ) );
		$synthetic = '' === $title || post_title_is_hidden( $post );
		if ( '' === $title ) {
			$title = untitled_name( $post );
		}
		$classes = 'pkiw-travel-entry ' . ( Travel::TRIP === $kind ? 'pkiw-trip-entry' : 'pkiw-itinerary-entry' ) . ' h-entry';
		$link    = '<a class="u-url' . ( $synthetic ? '' : ' p-name' ) . ( Travel::TRIP === $kind ? ' p-trip' : ' p-itinerary' ) . '" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $title ) . '</a>';
		$html    = '<article class="' . esc_attr( $classes ) . '" id="pkiw-travel-entry-' . esc_attr( (string) $post->ID ) . '">';

		if ( ! $protected && Travel::TRIP === $kind && has_post_thumbnail( $post ) ) {
			$attrs = [
				'alt'   => '',
				'class' => 'pkiw-trip-entry__image',
			];
			if ( self::priority_image( $post->ID ) ) {
				$attrs['loading']       = 'eager';
				$attrs['fetchpriority'] = 'high';
			}
			$html .= get_the_post_thumbnail( $post, 'medium_large', $attrs );
		}
		if ( ! $protected && Travel::TRIP === $kind ) {
			$html .= (string) apply_filters( 'pkiw_trip_entry_badge_html', '', $post );
		}
		$html .= '<h' . $level . ' class="pkiw-travel-entry__title">' . $link . '</h' . $level . '>';

		if ( $protected ) {
			return $html . '</article>';
		}
		$number = Travel_Map::number_for( $post->ID );
		$map_id = Travel_Map::current_map_id();
		if ( $number > 0 && '' !== $map_id ) {
			$html .= '<span class="pkiw-travel-map__num" data-map="' . esc_attr( $map_id ) . '" data-entry="' . esc_attr( (string) $post->ID ) . '" data-label="' . esc_attr( sprintf( /* translators: %s: post title */ __( 'Show %s on map', 'post-kinds-for-indieweb-in-block-themes' ), $title ) ) . '">' . esc_html( (string) $number ) . '</span>';
		}
		$html  .= '<time class="dt-published" datetime="' . esc_attr( (string) get_the_date( 'c', $post ) ) . '" hidden></time>';
		$author = entry_author_html( $post );
		if ( '' !== $author ) {
			$html .= '<span hidden>' . $author . '</span>';
		}

		return $html . '</article>';
	}

	/**
	 * Posts in the inherited page, or the editor preview's first page.
	 *
	 * @param \WP_Block|null $block Block instance.
	 * @return \WP_Post[]
	 */
	public static function page_posts( ?\WP_Block $block = null ): array {
		global $wp_query;
		if ( ! Grouped_Archive::is_block_preview() ) {
			return $wp_query instanceof \WP_Query && is_array( $wp_query->posts ) ? $wp_query->posts : [];
		}

		$kind = self::kind_for_posts( [], $block );
		return get_posts(
			[
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => self::PER_PAGE,
				'orderby'        => [
					'date' => 'DESC',
					'ID'   => 'DESC',
				],
				'tax_query'      => [
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $kind,
					],
				],
			]
		);
	}

	/**
	 * Infer the one travel kind represented by posts or a template context.
	 *
	 * @param \WP_Post[]     $posts Posts in the page.
	 * @param \WP_Block|null $block Block instance.
	 */
	public static function kind_for_posts( array $posts, ?\WP_Block $block = null ): string {
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$kind = Kind_Facts::kind_of( $post );
				if ( in_array( $kind, [ Travel::TRIP, Travel::ITINERARY ], true ) ) {
					return $kind;
				}
			}
		}
		if ( in_array( self::$preview_kind, [ Travel::TRIP, Travel::ITINERARY ], true ) ) {
			return self::$preview_kind;
		}
		$template = (string) ( $block->context['templateSlug'] ?? '' );
		return str_contains( $template, 'itinerary' ) ? Travel::ITINERARY : Travel::TRIP;
	}

	/**
	 * Track the travel kind named by a dynamic block preview request.
	 *
	 * @param mixed            $response Response so far.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public static function track_preview_kind( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wp/v2/block-renderer/' ) ) {
			$kind               = $request->get_param( 'pkiw_kind' );
			self::$preview_kind = 'rest_request_before_callbacks' === current_filter() && is_string( $kind ) ? sanitize_key( $kind ) : '';
		}

		return $response;
	}

	/**
	 * Render one registered entry block.
	 *
	 * @param string               $block_name Entry block name.
	 * @param string               $kind       Travel kind.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param \WP_Block|null       $block      Block instance.
	 */
	private static function render_entry( string $block_name, string $kind, array $attributes, ?\WP_Block $block ): string {
		return Grouped_Archive::render_entry(
			$block_name,
			$attributes,
			$block,
			static fn( \WP_Post $post, bool $in_section, string $preview_kind = '' ): string => self::entry_html( $post, '' !== $preview_kind ? $preview_kind : $kind, $attributes, $in_section )
		);
	}

	/**
	 * Whether this trip thumbnail is among the first three on archive page one.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function priority_image( int $post_id ): bool {
		if ( max( 1, (int) get_query_var( 'paged', 1 ) ) > 1 ) {
			return false;
		}
		$ids = Grouped_Archive::current_page_ids();
		if ( [] === $ids ) {
			$ids = array_map( static fn( $post ): int => $post instanceof \WP_Post ? $post->ID : 0, self::page_posts() );
		}
		$image_ids = array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					$post = get_post( $id );
					return $post instanceof \WP_Post && ! post_password_required( $post ) && has_post_thumbnail( $post );
				}
			)
		);
		$position  = array_search( $post_id, $image_ids, true );
		return false !== $position && $position < 3;
	}
}

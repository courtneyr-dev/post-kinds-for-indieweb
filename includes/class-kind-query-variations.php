<?php
/**
 * Query Loop variations for kind archive templates.
 *
 * @package PKIW
 * @since 1.10.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep archive Query variations aligned with the plugin templates. */
final class Kind_Query_Variations {

	/**
	 * Parsed Query children keyed by template slug for this request.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $template_cache = [];

	/** Register the Query variation filter. */
	public function register(): void {
		add_filter( 'get_block_type_variations', [ self::class, 'add' ], 10, 2 );
	}

	/**
	 * Add the plugin's kind archive Query variations.
	 *
	 * @param array<int, array<string, mixed>> $variations Existing variations.
	 * @param \WP_Block_Type                   $block_type Block type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function add( array $variations, \WP_Block_Type $block_type ): array {
		if ( 'core/query' !== $block_type->name ) {
			return $variations;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( self::definitions() as $namespace => $definition ) {
			$required = array_merge( [ $definition['entry'] ], $definition['required'] ?? [] );
			foreach ( $required as $block_name ) {
				if ( ! $registry->is_registered( $block_name ) ) {
					continue 2;
				}
			}

			$inner_blocks = self::template_inner_blocks( $definition['template'], 'pkiw-kind-quote' === $namespace );
			if ( [] === $inner_blocks ) {
				continue;
			}

			$variations[] = [
				'name'        => $namespace,
				'title'       => $definition['title'],
				'description' => $definition['description'],
				'icon'        => $definition['icon'],
				'scope'       => [ 'inserter', 'block' ],
				'isActive'    => [ 'namespace' ],
				'attributes'  => [
					'namespace' => $namespace,
					'className' => 'pkiw-kind-archive',
					'query'     => self::inherited_query(),
				],
				'innerBlocks' => $inner_blocks,
			];
		}

		return $variations;
	}

	/**
	 * Variation labels and the block that makes each variation usable.
	 *
	 * @return array<string, array{title:string,description:string,icon:string,template:string,entry:string,required?:string[]}>
	 */
	private static function definitions(): array {
		return [
			'pkiw-kind-quote'        => [
				'title'       => __( 'Quote commonplace book', 'post-kinds-for-indieweb-in-block-themes' ),
				'description' => __( 'Shows quotes grouped by author and work in a commonplace-book loop.', 'post-kinds-for-indieweb-in-block-themes' ),
				'icon'        => 'format-quote',
				'template'    => 'taxonomy-kind-quote',
				'entry'       => 'post-kinds-indieweb/quote-entry',
			],
			'pkiw-kind-issue'        => [
				'title'       => __( 'Issue sections', 'post-kinds-for-indieweb-in-block-themes' ),
				'description' => __( 'Shows issue posts in archive sections with Stream cards.', 'post-kinds-for-indieweb-in-block-themes' ),
				'icon'        => 'list-view',
				'template'    => 'taxonomy-kind-issue',
				'entry'       => 'post-kinds-indieweb/archive-sections',
			],
			'pkiw-kind-comics-drawn' => [
				'title'       => __( 'Drawn comic series', 'post-kinds-for-indieweb-in-block-themes' ),
				'description' => __( 'Shows drawn comics in series sections with linked titles.', 'post-kinds-for-indieweb-in-block-themes' ),
				'icon'        => 'art',
				'template'    => 'taxonomy-kind-comics-drawn',
				'entry'       => 'post-kinds-indieweb/archive-sections',
			],
			'pkiw-kind-rsvp'         => [
				'title'       => __( 'RSVP list', 'post-kinds-for-indieweb-in-block-themes' ),
				'description' => __( 'Shows RSVP posts grouped into upcoming and past events.', 'post-kinds-for-indieweb-in-block-themes' ),
				'icon'        => 'calendar-alt',
				'template'    => 'taxonomy-kind-rsvp',
				'entry'       => 'post-kinds-indieweb/rsvp-entry',
			],
			'pkiw-kind-presentation' => [
				'title'       => __( 'Speaking badges', 'post-kinds-for-indieweb-in-block-themes' ),
				'description' => __( 'Shows presentation posts as year-grouped speaking badges.', 'post-kinds-for-indieweb-in-block-themes' ),
				'icon'        => 'megaphone',
				'template'    => 'taxonomy-kind-presentation',
				'entry'       => 'post-kinds-indieweb/presentation-entry',
			],
			'pkiw-kind-trip'         => [
				'title'       => __( 'Trip map', 'post-kinds-for-indieweb-in-block-themes' ),
				'description' => __( 'Shows trip posts beside their route map.', 'post-kinds-for-indieweb-in-block-themes' ),
				'icon'        => 'location-alt',
				'template'    => 'taxonomy-kind-trip',
				'entry'       => 'post-kinds-indieweb/trip-entry',
				'required'    => [ 'post-kinds-indieweb/travel-map' ],
			],
			'pkiw-kind-itinerary'    => [
				'title'       => __( 'Itinerary map', 'post-kinds-for-indieweb-in-block-themes' ),
				'description' => __( 'Shows itinerary posts beside their route map.', 'post-kinds-for-indieweb-in-block-themes' ),
				'icon'        => 'location-alt',
				'template'    => 'taxonomy-kind-itinerary',
				'entry'       => 'post-kinds-indieweb/itinerary-entry',
				'required'    => [ 'post-kinds-indieweb/travel-map' ],
			],
		];
	}

	/**
	 * Match the inherited Query configuration used by the lineup variation.
	 *
	 * @return array<string, mixed>
	 */
	private static function inherited_query(): array {
		return [
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
	}

	/**
	 * Read one template's Query children in variation tuple format.
	 *
	 * @param string $template         Template slug.
	 * @param bool   $drop_quote_style Whether to remove the theme-only quote style.
	 * @return array<int, array{0:string,1:array<string, mixed>,2:array<mixed>}>
	 */
	private static function template_inner_blocks( string $template, bool $drop_quote_style ): array {
		if ( ! array_key_exists( $template, self::$template_cache ) ) {
			$content = file_get_contents( \PKIW_PATH . 'templates/' . $template . '.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin template.
			if ( false === $content ) {
				self::$template_cache[ $template ] = [];
			} else {
				$query                             = self::find_block( parse_blocks( $content ), 'core/query' );
				self::$template_cache[ $template ] = null === $query ? [] : (array) ( $query['innerBlocks'] ?? [] );
			}
		}

		return self::variation_blocks( self::$template_cache[ $template ], $drop_quote_style );
	}

	/**
	 * Find the first named block in a parsed block tree.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $name   Block name.
	 * @return array<string, mixed>|null
	 */
	private static function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? null ) === $name ) {
				return $block;
			}
			$found = self::find_block( (array) ( $block['innerBlocks'] ?? [] ), $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Convert parsed blocks to the tuples accepted by block variations.
	 *
	 * @param array<int, array<string, mixed>> $blocks           Parsed blocks.
	 * @param bool                             $drop_quote_style Whether to remove the theme-only quote style.
	 * @param string                           $parent_name      Parent block name.
	 * @return array<int, array{0:string,1:array<string, mixed>,2:array<mixed>}>
	 */
	private static function variation_blocks( array $blocks, bool $drop_quote_style, string $parent_name = '' ): array {
		$converted = [];
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( ! is_string( $name ) ) {
				continue;
			}
			$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			if ( $drop_quote_style && 'post-kinds-indieweb/quote-entry' === $name ) {
				$classes = array_values( array_filter( explode( ' ', (string) ( $attributes['className'] ?? '' ) ) ) );
				$classes = array_values( array_diff( $classes, [ 'is-style-cr-commonplace' ] ) );
				if ( [] === $classes ) {
					unset( $attributes['className'] );
				} else {
					$attributes['className'] = implode( ' ', $classes );
				}
			}
			if ( 'core/query-no-results' === $parent_name && 'core/paragraph' === $name ) {
				$attributes['content'] = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
			}
			$converted[] = [
				$name,
				$attributes,
				self::variation_blocks( (array) ( $block['innerBlocks'] ?? [] ), $drop_quote_style, $name ),
			];
		}

		return $converted;
	}
}

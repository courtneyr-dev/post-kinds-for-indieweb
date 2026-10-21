<?php
/**
 * Drawn comic facts, archive filtering, grouping, and series navigation.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the drawn view of the comics archive.
 *
 * @since 1.9.0
 */
final class Comic_Strip {

	/**
	 * Comics kind slug.
	 */
	public const KIND = 'comics';

	/**
	 * Public query variable for comics archive views.
	 */
	public const VIEW_VAR = 'pkiw_comic_view';

	/**
	 * Drawn comics view value.
	 */
	public const VIEW_DRAWN = 'drawn';

	/**
	 * Public query variable for series filtering.
	 */
	public const SERIES_VAR = 'pkiw_comic_series';

	/**
	 * Comic series grouping source ID.
	 */
	public const SOURCE = 'comic_series';

	/**
	 * Stored comic series meta key.
	 */
	public const SERIES_META = '_pkiw_comic_series';

	/**
	 * Stored comic issue meta key.
	 */
	public const ISSUE_META = '_pkiw_comic_issue';

	/**
	 * Stored comic reading status meta key.
	 */
	public const STATUS_META = '_pkiw_comic_status';

	/**
	 * Comic read card block name.
	 */
	public const CARD_BLOCK = 'post-kinds-indieweb/comic-card';

	/**
	 * Comic series navigation block name.
	 */
	public const SERIES_BLOCK = 'post-kinds-indieweb/comic-series';

	/**
	 * Request-local public series index.
	 *
	 * @var string[]|null
	 */
	private static ?array $series_cache = null;

	/**
	 * Register drawn comic contracts.
	 *
	 * Every hooked callback is static, so a second call adds nothing.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Archive_Layouts::register_filtered_view( self::KIND, self::VIEW_VAR, [ self::VIEW_DRAWN ] );
		Grouped_Archive::register_source( self::source() );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );

		add_filter( 'query_vars', [ self::class, 'add_query_vars' ] );
		add_action( 'pre_get_posts', [ self::class, 'filter_main_query' ] );
		add_filter( 'pkiw_filtered_view_meta_query', [ self::class, 'filtered_view_meta_query' ], 10, 3 );
		add_filter( 'pkiw_archive_group_source', [ self::class, 'group_source' ], 10, 3 );
		add_action( 'save_post', [ self::class, 'reset_series_cache' ] );
		add_action( 'deleted_post', [ self::class, 'reset_series_cache' ] );
		add_action( 'set_object_terms', [ self::class, 'reset_series_cache' ] );
		add_action( 'added_post_meta', [ self::class, 'reset_series_cache' ] );
		add_action( 'updated_post_meta', [ self::class, 'reset_series_cache' ] );
		add_action( 'deleted_post_meta', [ self::class, 'reset_series_cache' ] );

		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', [ self::class, 'register_blocks' ] );
		}
	}

	/**
	 * Whether a post is an authored comic rather than a comic read.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post to classify.
	 * @return bool
	 */
	public static function is_drawn( \WP_Post $post ): bool {
		return has_term( self::KIND, Taxonomy::TAXONOMY, $post )
			&& ! has_block( self::CARD_BLOCK, $post )
			&& '' === (string) get_post_meta( $post->ID, self::STATUS_META, true );
	}

	/**
	 * Public facts for a readable drawn comic.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Drawn comic facts, or [] when hidden or not drawn.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! self::is_drawn( $post ) ) {
			return [];
		}

		$strip = self::strip_block( parse_blocks( (string) $post->post_content ) );

		return [
			'drawn'  => true,
			'series' => trim( (string) get_post_meta( $post->ID, self::SERIES_META, true ) ),
			'issue'  => trim( (string) get_post_meta( $post->ID, self::ISSUE_META, true ) ),
			'panels' => null === $strip ? [] : self::panels( $strip ),
		];
	}

	/**
	 * Find the block that represents a drawn strip.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed top-level blocks.
	 * @return array<string, mixed>|null Strip block, or null when none exists.
	 */
	public static function strip_block( array $blocks ): ?array {
		$walked = self::strip_candidates( $blocks );
		$styles = [];

		/**
		 * Filters gallery style slugs that explicitly identify a comic strip.
		 *
		 * @since 1.9.0
		 *
		 * @param string[] $styles Comic strip style slugs.
		 */
		foreach ( (array) apply_filters( 'pkiw_comic_strip_styles', [] ) as $style ) {
			if ( ! is_scalar( $style ) ) {
				continue;
			}
			$slug = sanitize_title( (string) $style );
			if ( '' !== $slug ) {
				$styles[ $slug ] = true;
			}
		}

		foreach ( $walked as $block ) {
			if ( 'core/gallery' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$tokens = self::class_tokens( (string) ( $block['attrs']['className'] ?? '' ) );
			foreach ( array_keys( $styles ) as $style ) {
				if ( in_array( 'is-style-' . $style, $tokens, true ) ) {
					return $block;
				}
			}
		}

		foreach ( $walked as $block ) {
			if ( 'core/gallery' !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			$tokens    = self::class_tokens( (string) ( $block['attrs']['className'] ?? '' ) );
			$has_style = false;
			foreach ( $tokens as $token ) {
				if ( str_starts_with( $token, 'is-style-' ) ) {
					$has_style = true;
					break;
				}
			}
			if ( ! $has_style ) {
				return $block;
			}
		}

		foreach ( $walked as $block ) {
			if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * Extract panels from a selected gallery or image block.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $strip Selected strip block.
	 * @return array<int, array{position:int, attachment_id:int, src:string, alt:string, transcript:string}> Panels in saved order.
	 */
	public static function panels( array $strip ): array {
		$name = (string) ( $strip['blockName'] ?? '' );
		if ( 'core/image' === $name ) {
			$images = [ $strip ];
		} elseif ( 'core/gallery' === $name ) {
			$images = array_values(
				array_filter(
					is_array( $strip['innerBlocks'] ?? null ) ? $strip['innerBlocks'] : [],
					static fn( $block ): bool => is_array( $block ) && 'core/image' === ( $block['blockName'] ?? '' )
				)
			);
		} else {
			return [];
		}

		$panels = [];
		foreach ( $images as $index => $image ) {
			$attrs      = is_array( $image['attrs'] ?? null ) ? $image['attrs'] : [];
			$inner_html = is_string( $image['innerHTML'] ?? null ) ? $image['innerHTML'] : '';
			$processor  = new \WP_HTML_Tag_Processor( $inner_html );
			$src        = '';
			$alt        = '';
			if ( $processor->next_tag( [ 'tag_name' => 'img' ] ) ) {
				$attribute = $processor->get_attribute( 'src' );
				$src       = is_string( $attribute ) ? $attribute : '';
				$attribute = $processor->get_attribute( 'alt' );
				$alt       = is_string( $attribute ) ? $attribute : '';
			}
			if ( ! empty( $attrs['isDecorative'] ) ) {
				$alt = '';
			}

			$panels[] = [
				'position'      => $index + 1,
				'attachment_id' => isset( $attrs['id'] ) ? (int) $attrs['id'] : 0,
				'src'           => $src,
				'alt'           => $alt,
				'transcript'    => self::caption_text( $inner_html ),
			];
		}

		return $panels;
	}

	/**
	 * Meta clause selecting posts with no comic read status.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string|int, mixed>
	 */
	public static function drawn_meta_clause(): array {
		return [
			'relation' => 'OR',
			[
				'key'     => self::STATUS_META,
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => self::STATUS_META,
				'value'   => '',
				'compare' => '=',
			],
		];
	}

	/**
	 * Public series values used by the archive filter and navigation.
	 *
	 * @since 1.9.0
	 *
	 * @return string[]
	 */
	public static function series_values(): array {
		if ( null !== self::$series_cache ) {
			return self::$series_cache;
		}

		$query = new \WP_Query(
			[
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'tax_query'              => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The kind taxonomy defines archive membership.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => self::KIND,
					],
				],
				'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Series and drawn state are stored post meta.
					'relation' => 'AND',
					self::drawn_meta_clause(),
					[
						'key'     => self::SERIES_META,
						'value'   => '',
						'compare' => '!=',
					],
				],
			]
		);

		$values = [];
		// Full post objects prime the post, meta and term caches, so the checks below add no queries per strip.
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! self::is_drawn( $post ) ) {
				continue;
			}
			$value = trim( (string) get_post_meta( $post->ID, self::SERIES_META, true ) );
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		usort( $values, 'strnatcasecmp' );
		$seen               = [];
		self::$series_cache = [];
		foreach ( $values as $value ) {
			$key = mb_strtolower( $value );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ]         = true;
			self::$series_cache[] = $value;
		}

		return self::$series_cache;
	}

	/**
	 * Reset the request-local series index.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function reset_series_cache(): void {
		self::$series_cache = null;
	}

	/**
	 * URL for all drawn comics or one public series.
	 *
	 * @since 1.9.0
	 *
	 * @param string $series Stored series value, or empty for all series.
	 * @return string URL, or empty when the comics term is unavailable.
	 */
	public static function series_url( string $series = '' ): string {
		$base = get_term_link( self::KIND, Taxonomy::TAXONOMY );
		if ( is_wp_error( $base ) ) {
			return '';
		}

		$args = [ self::VIEW_VAR => self::VIEW_DRAWN ];
		if ( '' !== $series ) {
			$args[ self::SERIES_VAR ] = $series;
		}

		return add_query_arg( $args, $base );
	}

	/**
	 * Make both comic archive query variables public.
	 *
	 * @since 1.9.0
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		foreach ( [ self::VIEW_VAR, self::SERIES_VAR ] as $var ) {
			if ( ! in_array( $var, $vars, true ) ) {
				$vars[] = $var;
			}
		}

		return $vars;
	}

	/**
	 * Limit the drawn view of the main comics archive query.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Query $query Query about to run.
	 * @return void
	 */
	public static function filter_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return;
		}
		if ( self::VIEW_DRAWN !== sanitize_key( (string) $query->get( self::VIEW_VAR ) ) ) {
			return;
		}

		$clause = self::drawn_meta_clause();
		$series = self::validated_series( (string) $query->get( self::SERIES_VAR ) );
		if ( '' !== $series ) {
			$clause = [
				'relation' => 'AND',
				$clause,
				[
					'key'     => self::SERIES_META,
					'value'   => $series,
					'compare' => '=',
				],
			];
		}

		$existing = $query->get( 'meta_query' );
		$meta     = is_array( $existing ) && [] !== $existing
			? [
				'relation' => 'AND',
				$existing,
				$clause,
			]
			: [ $clause ];
		$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The drawn view is stored in post meta.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query->set( 'pkiw_view_public', 1 );
		}
	}

	/**
	 * Supply the drawn clause to REST and block-renderer view queries.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string|int, mixed> $clause Existing clause.
	 * @param mixed                    $view   Registered view.
	 * @param string                   $value  Active view value.
	 * @return array<string|int, mixed>
	 */
	public static function filtered_view_meta_query( array $clause, $view, string $value ): array {
		if ( is_array( $view ) && self::KIND === ( $view['kind'] ?? '' ) && self::VIEW_DRAWN === $value ) {
			return self::drawn_meta_clause();
		}

		return $clause;
	}

	/**
	 * Select series grouping only while the drawn comics view is active.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed          $id    Existing source ID.
	 * @param string         $kind  Kind slug.
	 * @param \WP_Query|null $query Query being grouped, or null for REST and editor requests.
	 * @return mixed
	 */
	public static function group_source( $id, string $kind = '', ?\WP_Query $query = null ) {
		if ( self::KIND !== $kind ) {
			return $id;
		}

		$active = $query instanceof \WP_Query
			? self::VIEW_DRAWN === sanitize_key( (string) $query->get( self::VIEW_VAR ) )
			: self::VIEW_DRAWN === Kind_Archive_Layouts::preview_view();

		return $active ? self::SOURCE : $id;
	}

	/**
	 * Register the series navigation editor asset and dynamic block.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		wp_register_script(
			'pkiw-comic-series-editor',
			\PKIW_URL . 'assets/js/comic-series-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-comic-series-editor', 'post-kinds-for-indieweb-in-block-themes' );

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( self::SERIES_BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Comic series', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'Links to all drawn comics and each public series.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'attributes'      => [
				'label'    => [
					'type'    => 'string',
					'default' => __( 'Series', 'post-kinds-for-indieweb-in-block-themes' ),
				],
				'allLabel' => [
					'type'    => 'string',
					'default' => __( 'All series', 'post-kinds-for-indieweb-in-block-themes' ),
				],
			],
			'render_callback' => [ self::class, 'render_series_block' ],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-comic-series-editor',
		];
		register_block_type( self::SERIES_BLOCK, $args );
	}

	/**
	 * Render the comic series navigation.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Saved block content.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Navigation markup, or an empty string off the drawn view.
	 */
	public static function render_series_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string {
		unset( $content, $block );
		$preview = Grouped_Archive::is_block_preview();
		$view    = Kind_Archive_Layouts::filtered_view();
		$active  = $preview || ( is_array( $view ) && self::KIND === $view['kind'] && self::VIEW_DRAWN === $view['value'] );
		if ( ! $active ) {
			return '';
		}

		$series = self::series_values();
		$term   = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( [] === $series || ! $term instanceof \WP_Term ) {
			return '';
		}
		$base = self::series_url();
		if ( '' === $base ) {
			return '';
		}

		$label = trim( is_string( $attributes['label'] ?? null ) ? $attributes['label'] : '' );
		if ( '' === $label ) {
			$label = __( 'Series', 'post-kinds-for-indieweb-in-block-themes' );
		}
		$all_label = trim( is_string( $attributes['allLabel'] ?? null ) ? $attributes['allLabel'] : '' );
		if ( '' === $all_label ) {
			$all_label = __( 'All series', 'post-kinds-for-indieweb-in-block-themes' );
		}

		$current = $preview ? '' : self::validated_series( (string) get_query_var( self::SERIES_VAR ) );
		$items   = sprintf(
			'<li class="pk-comic-series__item"><a class="pk-comic-series__link" href="%1$s"%2$s>%3$s</a></li>',
			esc_url( $base ),
			'' === $current ? ' aria-current="page"' : '',
			esc_html( $all_label )
		);
		foreach ( $series as $value ) {
			$is_current = '' !== $current && 0 === strcasecmp( $current, $value );
			$items     .= sprintf(
				'<li class="pk-comic-series__item"><a class="pk-comic-series__link" href="%1$s"%2$s>%3$s</a></li>',
				esc_url( self::series_url( $value ) ),
				$is_current ? ' aria-current="page"' : '',
				esc_html( $value )
			);
		}

		return sprintf(
			'<nav %1$s><ul class="pk-comic-series__list">%2$s</ul></nav>',
			get_block_wrapper_attributes(
				[
					'class'      => 'pk-comic-series',
					'aria-label' => $label,
				]
			),
			$items
		);
	}

	/**
	 * Registered meta source for comic series.
	 *
	 * @since 1.9.0
	 *
	 * @return Meta_Source
	 */
	private static function source(): Meta_Source {
		$empty_label = static function (): string {
			$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );

			return $term instanceof \WP_Term
				? $term->name
				: __( 'Comics', 'post-kinds-for-indieweb-in-block-themes' );
		};

		return new Meta_Source(
			self::SOURCE,
			[ self::SERIES_META ],
			[],
			Label_Mode::verbatim(),
			$empty_label,
			true
		);
	}

	/**
	 * Flatten top-level blocks and group wrappers without entering galleries.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks at one level.
	 * @return array<int, array<string, mixed>> Candidate blocks in saved order.
	 */
	private static function strip_candidates( array $blocks ): array {
		$candidates = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$candidates[] = $block;
			if ( 'core/group' === ( $block['blockName'] ?? '' ) ) {
				$candidates = array_merge(
					$candidates,
					self::strip_candidates( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [] )
				);
			}
		}

		return $candidates;
	}

	/**
	 * Space-separated CSS class tokens.
	 *
	 * @since 1.9.0
	 *
	 * @param string $classes CSS class list.
	 * @return string[]
	 */
	private static function class_tokens( string $classes ): array {
		return array_values(
			array_filter(
				array_map( static fn( $token ): string => trim( (string) $token ), wp_parse_list( $classes ) ),
				static fn( string $token ): bool => '' !== $token
			)
		);
	}

	/**
	 * Plain text inside the first saved figcaption.
	 *
	 * @since 1.9.0
	 *
	 * @param string $html Saved image block HTML.
	 * @return string Whitespace-folded caption text.
	 */
	private static function caption_text( string $html ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$inside    = false;
		$text      = '';
		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();
			if ( '#tag' === $type ) {
				$tag = (string) $processor->get_tag();
				if ( ! $inside && 'FIGCAPTION' === $tag && ! $processor->is_tag_closer() ) {
					$inside = true;
					continue;
				}
				if ( $inside && 'FIGCAPTION' === $tag && $processor->is_tag_closer() ) {
					break;
				}
				if ( $inside && 'BR' === $tag ) {
					$text .= ' ';
				}
			} elseif ( $inside && '#text' === $type ) {
				$text .= $processor->get_modifiable_text();
			}
		}

		$text = str_replace( [ "\r", "\n", "\t", "\f", "\v", "\u{00a0}" ], ' ', $text );
		while ( str_contains( $text, '  ' ) ) {
			$text = str_replace( '  ', ' ', $text );
		}

		return trim( $text );
	}

	/**
	 * Return a request value only when it exactly matches a public series.
	 *
	 * @since 1.9.0
	 *
	 * @param string $value Raw query value.
	 * @return string Stored series spelling, or empty when invalid.
	 */
	private static function validated_series( string $value ): string {
		$value = trim( $value );

		return in_array( $value, self::series_values(), true ) ? $value : '';
	}
}

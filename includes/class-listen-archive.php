<?php
/**
 * Podcast listen archive facts, filtering, blocks, and lineup rendering.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Archive_Group;
use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the podcast view of the listen archive.
 *
 * @since 1.9.0
 */
final class Listen_Archive {

	/**
	 * Listen kind slug.
	 */
	public const KIND = 'listen';

	/**
	 * Public query variable for listen archive views.
	 */
	public const QUERY_VAR = 'pkiw_listen_type';

	/**
	 * Supported listen archive views.
	 *
	 * @var string[]
	 */
	public const TYPES = [ 'podcast' ];

	/**
	 * Listen type navigation block.
	 */
	public const TYPES_BLOCK = 'post-kinds-indieweb/listen-types';

	/**
	 * Podcast lineup entry block.
	 */
	public const EPISODE_BLOCK = 'post-kinds-indieweb/podcast-episode';

	/**
	 * Podcast show grouping source.
	 */
	public const SHOW_SOURCE = 'listen_show';

	/**
	 * Number of stations printed during this request.
	 *
	 * @var int
	 */
	private static int $cover_counter = 0;

	/**
	 * Duplicate episode-name sets, keyed by the current page's sorted post IDs.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static array $duplicate_names = [];

	/**
	 * Register the podcast listen archive contracts.
	 *
	 * Every hooked callback is static, so a second call adds nothing.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Archive_Layouts::register_filtered_view( self::KIND, self::QUERY_VAR, self::TYPES );
		Grouped_Archive::register_source(
			new Meta_Source(
				self::SHOW_SOURCE,
				[ Meta_Fields::PREFIX . 'listen_album' ],
				[],
				Label_Mode::verbatim(),
				'',
				true
			)
		);

		add_filter( 'query_vars', [ self::class, 'add_query_var' ] );
		add_action( 'pre_get_posts', [ self::class, 'filter_main_query' ] );
		add_filter( 'pkiw_filtered_view_meta_query', [ self::class, 'filtered_view_meta_query' ], 10, 3 );
		add_filter( 'pkiw_archive_group_source', [ self::class, 'group_source' ], 10, 3 );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'pkiw_stream_card_kind_cite', [ self::class, 'cite_map' ] );
		add_filter( 'render_block_post-kinds-indieweb/listen-card', [ self::class, 'strip_card_player' ], 10, 3 );
		add_filter( 'get_block_type_variations', [ self::class, 'add_query_variation' ], 10, 2 );
		add_action( 'parse_request', [ self::class, 'reset_cover_counter' ] );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'reset_cover_counter_filter' ], 10, 1 );

		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', [ self::class, 'register_blocks' ] );
		}
	}

	/**
	 * Make the listen view query variable public.
	 *
	 * @since 1.9.0
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Limit the podcast view of the main listen archive query.
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

		$value = sanitize_key( (string) $query->get( self::QUERY_VAR ) );
		if ( ! in_array( $value, self::TYPES, true ) ) {
			return;
		}

		$clause   = self::podcast_meta_clause();
		$existing = $query->get( 'meta_query' );
		$meta     = is_array( $existing ) && [] !== $existing
			? [
				'relation' => 'AND',
				$existing,
				$clause,
			]
			: [ $clause ];
		$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The public podcast view is stored in post meta.
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			// Kind_Archive_Layouts::view_where() keeps protected posts out, so membership of the view can't reveal a protected post's media type.
			$query->set( 'pkiw_view_public', 1 );
		}
	}

	/**
	 * Supply the podcast clause to REST and block-renderer view queries.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string|int, mixed> $clause Existing clause.
	 * @param mixed                    $view   Registered view.
	 * @param string                   $value  Active view value.
	 * @return array<string|int, mixed>
	 */
	public static function filtered_view_meta_query( array $clause, $view, string $value ): array {
		if ( is_array( $view ) && self::KIND === ( $view['kind'] ?? '' ) && Listen_Media_Type::PODCAST === $value ) {
			return self::podcast_meta_clause();
		}

		return $clause;
	}

	/**
	 * Select show grouping only while the podcast view is active.
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

		$value = $query instanceof \WP_Query
			? sanitize_key( (string) $query->get( self::QUERY_VAR ) )
			: Kind_Archive_Layouts::preview_view();

		return in_array( $value, self::TYPES, true ) ? self::SHOW_SOURCE : $id;
	}

	/**
	 * Public facts for a readable listen post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Listen facts, or [] when hidden or not a listen.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		// A hidden location title reads as its safe name, a synthetic one (X8).
		$media_type    = Listen_Media_Type::stored( $post_id );
		$titled        = '' !== trim( (string) $post->post_title );
		$name          = $titled ? visitor_post_title( $post ) : untitled_name( $post, false );
		$name_is_title = $titled && ! post_title_is_hidden( $post );
		$episode       = trim( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', true ) );
		$episode_text  = '' !== $episode ? $episode : $name;
		$artist        = trim( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_artist', true ) );
		$show          = trim( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_album', true ) );
		$url           = web_url( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_url', true ) );
		$raw_rating    = get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_rating', true );
		$rating        = is_numeric( $raw_rating ) ? (float) $raw_rating : 0.0;
		$picture       = kind_picture( $post_id );
		$cover_alt     = trim( (string) $picture['alt'] );
		if ( '' === $cover_alt ) {
			$cover_alt = '' !== $artist ? $episode_text . ' — ' . $artist : $episode_text;
		}

		$dates      = self::listen_dates( $post );
		$embed_html = '';
		if ( '' !== $url && is_singular() && (int) get_queried_object_id() === $post->ID ) {
			$embed_html = (string) get_cached_embed_html( $url );
		}

		return [
			'media_type'        => $media_type,
			'is_podcast'        => Listen_Media_Type::PODCAST === $media_type,
			'name'              => $name,
			'name_is_title'     => $name_is_title,
			'episode'           => $episode,
			'episode_text'      => $episode_text,
			'episode_differs'   => '' !== $episode && mb_strtolower( trim( $episode ) ) !== mb_strtolower( trim( (string) $post->post_title ) ),
			'show'              => $show,
			'url'               => $url,
			'rating'            => $rating,
			'rating_label'      => card_rating_label( $rating ),
			'listened_value'    => $dates['value'],
			'listened_has_time' => $dates['has_time'],
			'listened_stored'   => $dates['stored'],
			'day_iso'           => $dates['day_iso'],
			'day_display'       => $dates['day_display'],
			'time_display'      => $dates['time_display'],
			'picture'           => $picture,
			'cover_alt'         => $cover_alt,
			'embed_html'        => $embed_html,
			'kind_label'        => get_kind_label( __( 'Listen', 'post-kinds-for-indieweb-in-block-themes' ), self::KIND, 'stream-card' ),
		];
	}

	/**
	 * Add the listen Stream citation callback.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $map Existing citation map.
	 * @return array<string, callable>
	 */
	public static function cite_map( $map ): array {
		$map               = is_array( $map ) ? $map : [];
		$map[ self::KIND ] = [ self::class, 'stream_cite' ];

		return $map;
	}

	/**
	 * Build a hidden podcast citation for a generic Stream card.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $facts Listen facts.
	 * @param \WP_Post             $post  Post. Unused.
	 * @return string Hidden h-cite markup, or an empty string.
	 */
	public static function stream_cite( array $facts, \WP_Post $post ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Map callback signature.
		if ( [] === $facts || empty( $facts['is_podcast'] ) ) {
			return '';
		}

		return hidden_cite_html(
			'u-listen-of',
			[
				'p-name'   => (string) ( $facts['episode_text'] ?? '' ),
				'u-url'    => (string) ( $facts['url'] ?? '' ),
				'p-author' => (string) ( $facts['show'] ?? '' ),
			]
		);
	}

	/**
	 * Keep the provider player off a podcast's listen card outside the post's own page.
	 *
	 * The card prints its oEmbed player on every render. A podcast's Stream
	 * card, and its card in a grouped or filtered archive, are lists: the
	 * player loads only on the podcast's own single. Music cards and the
	 * single are untouched.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $html     Rendered card.
	 * @param mixed $block    Parsed block.
	 * @param mixed $instance Block instance.
	 * @return mixed
	 */
	public static function strip_card_player( $html, $block = null, $instance = null ) {
		if ( ! is_string( $html ) || ! str_contains( $html, 'pk-embed--audio' ) ) {
			return $html;
		}

		$in_list = is_rendering_stream_card() || Grouped_Archive::is_sectioning() || '' !== Grouped_Archive::preview_view() || null !== Kind_Archive_Layouts::filtered_view();
		if ( ! $in_list ) {
			return $html;
		}

		$attrs   = is_array( $block ) && is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$post_id = $instance instanceof \WP_Block && ! empty( $instance->context['postId'] ) ? (int) $instance->context['postId'] : (int) get_the_ID();
		$podcast = Listen_Media_Type::PODCAST === Listen_Media_Type::sanitize( $attrs['mediaType'] ?? '' )
			|| ( $post_id > 0 && Listen_Media_Type::PODCAST === Listen_Media_Type::stored( $post_id ) );
		if ( ! $podcast ) {
			return $html;
		}

		$start = strpos( $html, '<div class="pk-embed pk-embed--audio">' );
		if ( false === $start ) {
			return $html;
		}

		// The wrapper holds the provider's own markup, which can nest divs: walk to the matching close tag.
		$depth = 0;
		$end   = false;
		if ( preg_match_all( '#<div\b|</div>#i', $html, $tags, PREG_OFFSET_CAPTURE, $start ) ) {
			foreach ( $tags[0] as $tag ) {
				$depth += 0 === stripos( $tag[0], '</' ) ? -1 : 1;
				if ( 0 === $depth ) {
					$end = $tag[1] + strlen( '</div>' );
					break;
				}
			}
		}

		return false === $end ? $html : substr( $html, 0, $start ) . substr( $html, $end );
	}

	/**
	 * Register the editor assets and both server-rendered blocks.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		wp_register_script(
			'pkiw-podcast-episode-editor',
			\PKIW_URL . 'assets/js/podcast-episode-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-podcast-episode-editor', 'post-kinds-for-indieweb-in-block-themes' );

		wp_register_script(
			'pkiw-listen-types-editor',
			\PKIW_URL . 'assets/js/listen-types-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-listen-types-editor', 'post-kinds-for-indieweb-in-block-themes' );

		$css = \PKIW_PATH . 'styles/podcast-lineup.css';
		wp_register_style( 'pkiw-podcast-lineup', \PKIW_URL . 'styles/podcast-lineup.css', [], file_exists( $css ) ? (string) filemtime( $css ) : \PKIW_VERSION );

		Grouped_Archive::register_entry_block(
			self::EPISODE_BLOCK,
			[
				'shape'        => Grouped_Archive::ENTRY_LINE,
				'expose_key'   => true,
				'heading_ids'  => true,
				'classes'      => [
					'wrapper'     => 'pkiw-lineup',
					'section'     => 'pkiw-lineup__station',
					'heading'     => 'pkiw-lineup__heading',
					'items'       => 'pkiw-lineup__episodes',
					'placeholder' => 'pkiw-lineup__episode pkiw-lineup__episode--continued',
				],
				'section_body' => [ self::class, 'station_body' ],
			]
		);

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( self::TYPES_BLOCK ) ) {
			// Core stores and compares the numeric API version; the WordPress stubs type this field as a string.
			/**
			 * Block type arguments.
			 *
			 * @var array<string, mixed> $args
			 */
			$args = [
				'api_version'     => 3,
				'title'           => __( 'Listen types', 'post-kinds-for-indieweb-in-block-themes' ),
				'description'     => __( 'Links to all listens and podcast listens.', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => [ self::class, 'render_types_block' ],
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-listen-types-editor',
			];
			register_block_type( self::TYPES_BLOCK, $args );
		}

		if ( $registry->is_registered( self::EPISODE_BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Podcast episodes', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'Podcast episodes grouped into show stations.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'linesPerPage' => [
						'default' => 12,
					],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'ancestor'        => [ 'core/post-template' ],
			'editor_script'   => 'pkiw-podcast-episode-editor',
			'style'           => 'pkiw-podcast-lineup',
		];
		register_block_type( self::EPISODE_BLOCK, $args );
	}

	/**
	 * Render the listen type navigation.
	 *
	 * @since 1.9.0
	 *
	 * @return string Navigation markup, or an empty string without the term.
	 */
	public static function render_types_block(): string {
		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		$base = get_term_link( $term );
		if ( is_wp_error( $base ) ) {
			return '';
		}

		$on_archive = is_tax( Taxonomy::TAXONOMY, self::KIND );
		$value      = $on_archive ? sanitize_key( (string) get_query_var( self::QUERY_VAR ) ) : '';
		$podcast    = $on_archive && in_array( $value, self::TYPES, true );
		$all        = Grouped_Archive::is_block_preview() || ( $on_archive && ! $podcast );

		return sprintf(
			'<nav %1$s><ul class="pk-listen-types__list"><li class="pk-listen-types__item"><a class="pk-listen-types__link" href="%2$s"%3$s>%4$s</a></li><li class="pk-listen-types__item"><a class="pk-listen-types__link" href="%5$s"%6$s>%7$s</a></li></ul></nav>',
			get_block_wrapper_attributes( [ 'aria-label' => __( 'Listen types', 'post-kinds-for-indieweb-in-block-themes' ) ] ),
			esc_url( $base ),
			$all ? ' aria-current="page"' : '',
			esc_html__( 'All', 'post-kinds-for-indieweb-in-block-themes' ),
			esc_url( add_query_arg( self::QUERY_VAR, Listen_Media_Type::PODCAST, $base ) ),
			$podcast && ! Grouped_Archive::is_block_preview() ? ' aria-current="page"' : '',
			esc_html__( 'Podcasts', 'post-kinds-for-indieweb-in-block-themes' )
		);
	}

	/**
	 * Render one podcast entry through the grouped archive engine.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused block content.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Podcast entry or section markup.
	 */
	public static function render( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry(
			self::EPISODE_BLOCK,
			$attributes,
			$block,
			static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::line_html( $post, $attributes, $in_section ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		);
	}

	/**
	 * Print a station's decorative cover followed by its episode list.
	 *
	 * @internal Registered as the entry block's section body.
	 *
	 * @since 1.9.0
	 *
	 * @param Archive_Group        $group    Show group.
	 * @param array<int, string>   $items    Engine-rendered list items keyed by post ID.
	 * @param array<string, mixed> $settings Entry settings. Unused.
	 * @return string Station body markup.
	 */
	public static function station_body( Archive_Group $group, array $items, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Section callback signature.
		$first_station = 0 === self::$cover_counter;
		++self::$cover_counter;
		$cover = '';

		if ( ! $group->is_empty() ) {
			foreach ( array_keys( $items ) as $post_id ) {
				$facts   = Kind_Facts::get( (int) $post_id );
				$picture = is_array( $facts['picture'] ?? null ) ? $facts['picture'] : [];
				$url     = is_string( $picture['url'] ?? null ) ? $picture['url'] : '';
				if ( '' === $url ) {
					continue;
				}

				$attrs = [
					'alt'      => '',
					'decoding' => 'async',
					'loading'  => $first_station ? 'eager' : 'lazy',
				];
				if ( $first_station ) {
					$attrs['fetchpriority'] = 'high';
				}

				$attachment_id = (int) ( $picture['attachment_id'] ?? 0 );
				if ( $attachment_id > 0 ) {
					$image = wp_get_attachment_image( $attachment_id, 'medium_large', false, $attrs );
				} else {
					$image = sprintf(
						'<img src="%1$s" alt="" decoding="async" loading="%2$s"%3$s />',
						esc_url( $url ),
						esc_attr( $attrs['loading'] ),
						$first_station ? ' fetchpriority="high"' : ''
					);
				}
				if ( '' !== $image ) {
					$cover = '<div class="pkiw-lineup__cover" data-pkiw-cover>' . $image . '</div>';
				}
				break;
			}
		}

		if ( '' === $cover ) {
			$cover = '<div class="pkiw-lineup__cover pkiw-lineup__cover--none" aria-hidden="true"></div>';
		}

		return $cover . '<ul class="pkiw-lineup__episodes">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Reset the station-cover loading priority for a new request.
	 *
	 * @internal Hooked to parse_request.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed ...$args Hook arguments. Unused.
	 * @return void
	 */
	public static function reset_cover_counter( ...$args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		self::$cover_counter = 0;
	}

	/**
	 * Reset cover priority before a REST callback and pass its value through.
	 *
	 * @internal Hooked to rest_request_before_callbacks.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $first First filter value.
	 * @return mixed
	 */
	public static function reset_cover_counter_filter( $first ) {
		self::$cover_counter = 0;

		return $first;
	}

	/**
	 * Add the podcast lineup Query Loop variation.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int, array<string, mixed>> $variations Existing variations.
	 * @param \WP_Block_Type                   $block_type Block type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function add_query_variation( array $variations, \WP_Block_Type $block_type ): array {
		if ( 'core/query' !== $block_type->name ) {
			return $variations;
		}

		$variations[] = [
			'name'        => 'pkiw-kind-lineup',
			'title'       => __( 'Podcast lineup', 'post-kinds-for-indieweb-in-block-themes' ),
			'description' => __( 'Podcast episodes grouped into show stations.', 'post-kinds-for-indieweb-in-block-themes' ),
			'icon'        => 'microphone',
			'scope'       => [ 'inserter', 'block' ],
			'isActive'    => [ 'namespace' ],
			'attributes'  => [
				'namespace' => 'pkiw-kind-lineup',
				'className' => 'pkiw-kind-archive',
				'query'     => [
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
				],
			],
			'innerBlocks' => [
				[
					'core/post-template',
					[ 'className' => 'is-style-pkiw-lineup' ],
					[ [ self::EPISODE_BLOCK ] ],
				],
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
				[
					'core/query-no-results',
					[],
					[ [ 'core/paragraph', [ 'placeholder' => __( 'Text shown when there are no posts.', 'post-kinds-for-indieweb-in-block-themes' ) ] ] ],
				],
			],
		];

		return $variations;
	}

	/**
	 * Meta query clause for podcast listens.
	 *
	 * @return array{key: string, value: string, compare: string}
	 */
	private static function podcast_meta_clause(): array {
		return [
			'key'     => Listen_Media_Type::META,
			'value'   => Listen_Media_Type::PODCAST,
			'compare' => '=',
		];
	}

	/**
	 * Normalize a listen's stored or fallback date.
	 *
	 * @param \WP_Post $post Listen post.
	 * @return array{value:string, has_time:bool, stored:bool, day_iso:string, day_display:string, time_display:string}
	 */
	private static function listen_dates( \WP_Post $post ): array {
		$raw       = trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . 'listen_listened_at', true ) );
		$real_date = preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $raw, $matches )
			&& checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
		$has_time  = 1 === preg_match( '/[Tt ]\d{2}:\d{2}/', $raw );

		if ( $real_date && $has_time ) {
			$day = card_wall_clock( $raw, 'Y-m-d' );
			if ( '' !== $day[1] ) {
				$display = card_wall_clock( $raw, (string) get_option( 'date_format' ) );
				$time    = card_wall_clock( $raw, (string) get_option( 'time_format' ) );

				return [
					'value'        => $raw,
					'has_time'     => true,
					'stored'       => true,
					'day_iso'      => $day[1],
					'day_display'  => $display[1],
					'time_display' => $time[1],
				];
			}
		}

		if ( $real_date && ! $has_time ) {
			$day     = card_wall_clock( $raw );
			$display = card_wall_clock( $raw, (string) get_option( 'date_format' ) );
			if ( '' !== $day[0] ) {
				return [
					'value'        => $raw,
					'has_time'     => false,
					'stored'       => true,
					'day_iso'      => $day[0],
					'day_display'  => $display[1],
					'time_display' => '',
				];
			}
		}

		return [
			'value'        => '',
			'has_time'     => false,
			'stored'       => false,
			'day_iso'      => (string) mysql2date( 'Y-m-d', $post->post_date ),
			'day_display'  => (string) mysql2date( (string) get_option( 'date_format' ), $post->post_date ),
			'time_display' => '',
		];
	}

	/**
	 * One podcast episode line.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a station heading stands above the line.
	 * @return string Episode markup.
	 */
	private static function line_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$level     = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$facts     = Kind_Facts::get( $post->ID );
		$href      = esc_url( (string) get_permalink( $post ) );
		$published = esc_attr( (string) get_the_date( 'c', $post ) );

		if ( [] === $facts ) {
			$title = trim( wp_strip_all_tags( visitor_post_title( $post ) ) );
			$date  = (string) get_the_date( 'Y-m-d', $post );
			$text  = (string) get_the_date( '', $post );

			return sprintf(
				'<div class="pkiw-episode"><h%1$d class="pkiw-episode__title"><a class="pkiw-episode__link u-url" href="%2$s">%3$s</a></h%1$d><time class="pkiw-episode__date" datetime="%4$s">%5$s</time><data class="dt-published" value="%6$s" hidden></data></div>',
				$level,
				$href,
				esc_html( $title ),
				esc_attr( $date ),
				esc_html( $text ),
				$published
			);
		}

		$episode   = (string) $facts['episode_text'];
		$duplicate = isset( self::duplicate_name_set( $post )[ mb_strtolower( $episode ) ] );
		$name_id   = 'pkiw-episode-' . $post->ID . '-name';
		$date_id   = 'pkiw-episode-' . $post->ID . '-date';
		$link_text = $duplicate
			? '<span id="' . esc_attr( $name_id ) . '">' . esc_html( $episode ) . '</span>'
			: esc_html( $episode );
		$link      = sprintf(
			'<a class="pkiw-episode__link u-url" href="%1$s"%2$s>%3$s</a>',
			$href,
			$duplicate ? ' aria-labelledby="' . esc_attr( $name_id . ' ' . $date_id ) . '"' : '',
			$link_text
		);
		$author    = entry_author_html( $post );
		$hidden    = '' !== $author ? '<span hidden>' . $author . '</span>' : '';

		return sprintf(
			'<div class="pkiw-episode"><h%1$d class="pkiw-episode__title">%2$s</h%1$d><time class="pkiw-episode__date"%3$s datetime="%4$s">%5$s</time><data class="dt-published" value="%6$s" hidden></data>%7$s%8$s</div>',
			$level,
			$link,
			$duplicate ? ' id="' . esc_attr( $date_id ) . '"' : '',
			esc_attr( (string) $facts['day_iso'] ),
			esc_html( (string) $facts['day_display'] ),
			$published,
			$hidden,
			stream_card_kind_cite( $post )
		);
	}

	/**
	 * Episode names duplicated on the grouped page.
	 *
	 * @param \WP_Post $post Current post.
	 * @return array<string, bool> Lowercase duplicate names.
	 */
	private static function duplicate_name_set( \WP_Post $post ): array {
		$ids = Grouped_Archive::current_page_ids();
		if ( [] === $ids ) {
			$ids = [ $post->ID ];
		}

		$sorted = $ids;
		sort( $sorted, SORT_NUMERIC );
		$key = implode( ',', $sorted );
		if ( isset( self::$duplicate_names[ $key ] ) ) {
			return self::$duplicate_names[ $key ];
		}

		$counts = [];
		foreach ( $ids as $post_id ) {
			$facts           = Kind_Facts::get( (int) $post_id );
			$line_post       = get_post( (int) $post_id );
			$fallback        = $line_post instanceof \WP_Post ? trim( wp_strip_all_tags( visitor_post_title( $line_post ) ) ) : '';
			$name            = [] !== $facts ? (string) ( $facts['episode_text'] ?? '' ) : $fallback;
			$name            = mb_strtolower( $name );
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
		}

		$duplicates = [];
		foreach ( $counts as $name => $count ) {
			if ( $count > 1 ) {
				$duplicates[ $name ] = true;
			}
		}
		self::$duplicate_names[ $key ] = $duplicates;

		return $duplicates;
	}
}

<?php
/**
 * Chicken archive: month sections, entry block and location suppression.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The chicken archive and its one-line entry markup.
 *
 * @since 1.9.0
 */
final class Chicken_Archive {

	/**
	 * Kind slug.
	 *
	 * @var string
	 */
	public const KIND = 'chicken';

	/**
	 * Chicken entry block.
	 *
	 * @var string
	 */
	public const ENTRY = 'post-kinds-indieweb/chicken-entry';

	/**
	 * Removed content callbacks, one frame per the_content pass.
	 *
	 * @var array<int, array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}>>
	 */
	private static array $content_stack = [];

	/**
	 * Removed feed callbacks, one frame per feed item pass.
	 *
	 * @var array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}|null>
	 */
	private static array $feed_stack = [];

	/**
	 * Cached duplicate title data by current page ID list.
	 *
	 * @var array<string, array{ids:int[], counts:array<string, int>}>
	 */
	private static array $page_titles = [];

	/**
	 * Wire hooks and registrations.
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );

		if ( did_action( 'init' ) ) {
			$this->register_entry_block();
		} else {
			add_action( 'init', [ $this, 'register_entry_block' ] );
		}

		add_filter( 'the_content', [ $this, 'gate_content' ], 10 );
		add_filter( 'the_content', [ $this, 'restore_content' ], 13 );
		foreach ( [ 'rss_item', 'rss2_item', 'atom_entry', 'rdf_item' ] as $hook ) {
			add_action( $hook, [ $this, 'gate_feed_item' ], 9 );
			add_action( $hook, [ $this, 'restore_feed_item' ], 11 );
		}
		add_filter( 'json_feed_item', [ $this, 'strip_json_feed_geo' ], 11, 2 );
		add_action( 'template_redirect', [ $this, 'suppress_head_meta' ], 10 );
		add_filter( 'rest_prepare_post', [ $this, 'strip_rest_location' ], 30, 3 );
	}

	/**
	 * Register the chicken editor script, stylesheet and dynamic entry block.
	 *
	 * @return void
	 */
	public function register_entry_block(): void {
		wp_register_script(
			'pkiw-chicken-entry-editor',
			\PKIW_URL . 'assets/js/chicken-entry-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-chicken-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );

		wp_register_style( 'pkiw-chicken-entry', \PKIW_URL . 'styles/chicken-entry.css', [], \PKIW_VERSION );

		Grouped_Archive::register_entry_block(
			self::ENTRY,
			[
				'shape'   => Grouped_Archive::ENTRY_LINE,
				'list'    => 'ol',
				'classes' => [
					'wrapper'     => 'pkiw-grouped pkiw-chicken',
					'section'     => 'pkiw-group pkiw-chicken__month',
					'heading'     => 'pkiw-group__heading pkiw-chicken__month-label',
					'items'       => 'pkiw-group__items pkiw-chicken__entries',
					'placeholder' => 'pkiw-group-placeholder pkiw-chicken__continued',
				],
			]
		);

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY ) ) {
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Chicken entries', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'linesPerPage' => [
						'default' => 24,
					],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'ancestor'        => [ 'core/post-template' ],
			'editor_script'   => 'pkiw-chicken-entry-editor',
			'style'           => 'pkiw-chicken-entry',
		];
		register_block_type( self::ENTRY, $args );
	}

	/**
	 * Public chicken facts for a chicken post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$title   = trim( wp_strip_all_tags( $post->post_title ) );
		$named   = '' !== $title;
		$display = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		$label   = get_kind_label( stream_card_kind_label( $post ), self::KIND, 'archive-entry' );

		return [
			'name'                => $named ? $display : untitled_name( $post, false ),
			'named'               => $named,
			'kind_label'          => $label,
			'published'           => get_post_time( 'c', true, $post ),
			'url'                 => get_permalink( $post ),
			'picture'             => kind_picture( $post_id ),
			'featured_in_content' => featured_in_content( $post_id ),
		];
	}

	/**
	 * Render the chicken entry block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Markup.
	 */
	public static function render( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry(
			self::ENTRY,
			$attributes,
			$block,
			static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post, $attributes, $in_section ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		);
	}

	/**
	 * Remove Simple Location content callbacks for one chicken content pass.
	 *
	 * @param mixed $content Content.
	 * @return mixed Content unchanged.
	 */
	public function gate_content( $content ) {
		self::$content_stack[] = self::is_current_chicken_post()
			? array_merge(
				self::remove_simple_location_callbacks( 'the_content', 11, 'Geo_Data', 'content_map' ),
				self::remove_simple_location_callbacks( 'the_content', 12, 'Geo_Data', 'location_content' )
			)
			: [];

		return $content;
	}

	/**
	 * Restore Simple Location content callbacks after one content pass.
	 *
	 * @param mixed $content Content.
	 * @return mixed Content unchanged.
	 */
	public function restore_content( $content ) {
		$removed = array_pop( self::$content_stack );
		foreach ( is_array( $removed ) ? $removed : [] as $entry ) {
			add_filter( $entry['hook'], $entry['callable'], $entry['priority'], $entry['accepted_args'] );
		}

		return $content;
	}

	/**
	 * Remove the current feed hook's Simple Location item callback for chicken posts.
	 *
	 * @return void
	 */
	public function gate_feed_item(): void {
		$hook = current_filter();
		if ( ! self::is_current_chicken_post() || '' === $hook ) {
			self::$feed_stack[] = null;
			return;
		}

		$removed            = self::remove_simple_location_callbacks( $hook, 10, 'Geo_Base', 'georss_item' );
		self::$feed_stack[] = $removed[0] ?? null;
	}

	/**
	 * Restore the current feed hook's Simple Location item callback.
	 *
	 * @return void
	 */
	public function restore_feed_item(): void {
		$entry = array_pop( self::$feed_stack );
		if ( ! is_array( $entry ) ) {
			return;
		}

		add_action( current_filter(), $entry['callable'], $entry['priority'], $entry['accepted_args'] );
	}

	/**
	 * Remove geo from JSON Feed items for chicken posts.
	 *
	 * @param array<string, mixed> $item Feed item.
	 * @param \WP_Post|null        $post Post.
	 * @return array<string, mixed>
	 */
	public function strip_json_feed_geo( array $item, $post = null ): array {
		$post = $post instanceof \WP_Post ? $post : get_post();
		if ( $post instanceof \WP_Post && has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			unset( $item['geo'] );
		}

		return $item;
	}

	/**
	 * Remove Simple Location head meta on chicken singles.
	 *
	 * @return void
	 */
	public function suppress_head_meta(): void {
		$post = get_queried_object();
		if ( ! is_singular() || ! $post instanceof \WP_Post || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return;
		}

		self::remove_simple_location_callbacks( 'wp_head', 10, 'Geo_Base', 'meta_tags' );
	}

	/**
	 * Remove Simple Location fields from public REST post responses for chicken posts.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function strip_rest_location( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'edit' === $request->get_param( 'context' ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return $response;
		}

		$data = $response->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone' ] as $key ) {
			unset( $data[ $key ] );
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * One chicken archive entry.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a section heading stands above the entry.
	 * @return string
	 */
	private static function entry_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$base_level = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$level      = max( 2, min( 6, $base_level + ( $in_section ? 1 : 0 ) ) );
		$title      = trim( wp_strip_all_tags( $post->post_title ) );
		$named      = '' !== $title;
		$link_text  = $named ? get_the_title( $post ) : get_kind_label( stream_card_kind_label( $post ), self::KIND, 'archive-entry' );
		$link_id    = 'pkiw-chicken-t-' . $post->ID;
		$date_id    = 'pkiw-chicken-d-' . $post->ID;
		$classes    = [ 'u-url' ];
		$format     = Plugin::get_instance()->get_microformats()?->get_kind_format( self::KIND );
		if ( $named && in_array( 'p-name', array_values( (array) ( $format['properties'] ?? [] ) ), true ) ) {
			$classes[] = 'p-name';
		}

		$attributes_html = '';
		if ( ! $named || self::title_is_duplicate_on_page( $post ) ) {
			$attributes_html = ' aria-labelledby="' . esc_attr( $link_id . ' ' . $date_id ) . '"';
		}

		$timestamp = get_post_timestamp( $post );
		$date_text = wp_date( _x( 'M d', 'Chicken archive entry date', 'post-kinds-for-indieweb-in-block-themes' ), $timestamp );
		$extra     = wp_date( 'Y', $timestamp );
		if ( self::title_date_is_duplicate_on_page( $post ) ) {
			$extra .= ', ' . wp_date( (string) get_option( 'time_format' ), $timestamp );
		}
		$author_html = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';

		return sprintf(
			'<time class="dt-published pkiw-chicken__date" id="%1$s" datetime="%2$s">%3$s<span class="pk-sr-only">, %4$s</span></time><h%5$d class="pkiw-chicken__title"><a class="%6$s" id="%7$s" href="%8$s"%9$s>%10$s</a></h%5$d><span hidden>%11$s</span>',
			esc_attr( $date_id ),
			esc_attr( (string) get_post_time( 'c', true, $post ) ),
			esc_html( $date_text ),
			esc_html( $extra ),
			$level,
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $link_id ),
			esc_url( (string) get_permalink( $post ) ),
			$attributes_html,
			esc_html( $link_text ),
			$author_html
		);
	}

	/**
	 * Whether the current global post is a chicken post.
	 *
	 * @return bool
	 */
	private static function is_current_chicken_post(): bool {
		$post = get_post();

		return $post instanceof \WP_Post && has_term( self::KIND, Taxonomy::TAXONOMY, $post );
	}

	/**
	 * Remove matching Simple Location callbacks from a hook and priority.
	 *
	 * @param string $hook       Hook name.
	 * @param int    $priority   Priority.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}>
	 */
	private static function remove_simple_location_callbacks( string $hook, int $priority, string $class_name, string $method ): array {
		$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wp_hook instanceof \WP_Hook || empty( $wp_hook->callbacks[ $priority ] ) ) {
			return [];
		}

		$removed = [];
		foreach ( $wp_hook->callbacks[ $priority ] as $callback ) {
			$callable = $callback['function'] ?? null;
			if ( ! self::is_simple_location_callback( $callable, $class_name, $method ) ) {
				continue;
			}
			if ( remove_filter( $hook, $callable, $priority ) ) {
				$removed[] = [
					'hook'          => $hook,
					'callable'      => $callable,
					'priority'      => $priority,
					'accepted_args' => (int) ( $callback['accepted_args'] ?? 1 ),
				];
			}
		}

		return $removed;
	}

	/**
	 * Whether a callable belongs to a Simple Location class and method.
	 *
	 * @param mixed  $candidate  Callable candidate.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return bool
	 */
	private static function is_simple_location_callback( $candidate, string $class_name, string $method ): bool {
		$class_name = ltrim( $class_name, '\\' );
		if ( is_string( $candidate ) ) {
			return ltrim( $candidate, '\\' ) === $class_name . '::' . $method;
		}
		if ( ! is_array( $candidate ) || 2 !== count( $candidate ) || $method !== (string) $candidate[1] ) {
			return false;
		}
		$candidate_class = is_object( $candidate[0] ) ? get_class( $candidate[0] ) : (string) $candidate[0];
		$candidate_class = ltrim( $candidate_class, '\\' );

		return $class_name === $candidate_class;
	}

	/**
	 * Whether a post title appears more than once on the current page.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function title_is_duplicate_on_page( \WP_Post $post ): bool {
		$title = self::normalized_title( $post );

		return '' !== $title && ( self::page_title_counts( $post )[ $title ] ?? 0 ) > 1;
	}

	/**
	 * Whether a post title and local calendar date appear more than once.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function title_date_is_duplicate_on_page( \WP_Post $post ): bool {
		$title = self::normalized_title( $post );
		if ( '' === $title ) {
			return false;
		}

		$day   = wp_date( 'Y-m-d', get_post_timestamp( $post ) );
		$count = 0;
		foreach ( self::current_page_posts( $post ) as $page_post ) {
			if ( self::normalized_title( $page_post ) === $title && wp_date( 'Y-m-d', get_post_timestamp( $page_post ) ) === $day ) {
				++$count;
			}
		}

		return $count > 1;
	}

	/**
	 * Normalized stored title for duplicate comparisons.
	 *
	 * @param \WP_Post $post Post.
	 * @return string Normalized title.
	 */
	private static function normalized_title( \WP_Post $post ): string {
		if ( '' === trim( wp_strip_all_tags( $post->post_title ) ) ) {
			return '';
		}

		return mb_strtolower( trim( wp_strip_all_tags( get_the_title( $post ) ) ) );
	}

	/**
	 * Title counts for the current grouped page.
	 *
	 * @param \WP_Post $post Rendered post.
	 * @return array<string, int>
	 */
	private static function page_title_counts( \WP_Post $post ): array {
		$ids = self::current_page_ids( $post );
		$key = implode( ',', $ids );
		if ( ! isset( self::$page_titles[ $key ] ) ) {
			$counts = [];
			foreach ( self::current_page_posts( $post ) as $page_post ) {
				$title = self::normalized_title( $page_post );
				if ( '' !== $title ) {
					$counts[ $title ] = ( $counts[ $title ] ?? 0 ) + 1;
				}
			}
			self::$page_titles[ $key ] = [
				'ids'    => $ids,
				'counts' => $counts,
			];
		}

		return self::$page_titles[ $key ]['counts'];
	}

	/**
	 * Posts on the current grouped page, or the current post alone.
	 *
	 * @param \WP_Post $post Rendered post.
	 * @return \WP_Post[]
	 */
	private static function current_page_posts( \WP_Post $post ): array {
		$posts = [];
		foreach ( self::current_page_ids( $post ) as $id ) {
			$page_post = get_post( $id );
			if ( $page_post instanceof \WP_Post ) {
				$posts[] = $page_post;
			}
		}

		return $posts;
	}

	/**
	 * Post IDs on the current grouped page.
	 *
	 * @param \WP_Post $post Rendered post.
	 * @return int[]
	 */
	private static function current_page_ids( \WP_Post $post ): array {
		$ids = Grouped_Archive::current_page_ids();

		return [] !== $ids ? $ids : [ $post->ID ];
	}
}

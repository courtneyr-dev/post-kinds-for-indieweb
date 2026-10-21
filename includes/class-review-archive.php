<?php
/**
 * Review archive: category sections, review entries and Four Stars.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Term_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The review archive and its review-specific render helpers.
 *
 * @since 1.9.0
 */
final class Review_Archive {

	/**
	 * Kind slug.
	 *
	 * @var string
	 */
	public const KIND = 'review';

	/**
	 * Group source id.
	 *
	 * @var string
	 */
	public const SOURCE = 'review_category';

	/**
	 * Review entry block.
	 *
	 * @var string
	 */
	public const ENTRY_BLOCK = 'post-kinds-indieweb/review-entry';

	/**
	 * Four Stars block.
	 *
	 * @var string
	 */
	public const FOUR_STARS_BLOCK = 'post-kinds-indieweb/four-stars';

	/**
	 * Wire hooks and registrations.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Grouped_Archive::register_source(
			new Term_Source(
				self::SOURCE,
				'category',
				static fn(): int => (int) get_option( 'default_category' ),
				'',
				true
			),
			self::KIND
		);
		Kind_Facts::register( self::KIND, [ self::class, 'review_facts' ] );

		if ( did_action( 'init' ) ) {
			$this->register_blocks();
		} else {
			add_action( 'init', [ $this, 'register_blocks' ] );
		}

		add_filter( 'render_block_post-kinds-indieweb/star-rating', [ $this, 'strip_star_rating_mf2' ], 10, 3 );
		add_action( 'wp', [ $this, 'suppress_simple_location' ] );
	}

	/**
	 * Register review editor scripts and dynamic blocks.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		wp_register_script(
			'pkiw-review-entry-editor',
			\PKIW_URL . 'assets/js/review-entry-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-review-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );

		Grouped_Archive::register_entry_block(
			self::ENTRY_BLOCK,
			[
				'shape'       => Grouped_Archive::ENTRY_LINE,
				'heading_ids' => true,
				'classes'     => [
					'wrapper'     => 'pkiw-review--sectioned',
					'section'     => 'pkiw-review-section',
					'heading'     => 'pkiw-review-section__heading',
					'items'       => 'pkiw-review-section__items',
					'placeholder' => 'pkiw-review-entry pkiw-review-entry--continued',
				],
			]
		);

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			/**
			 * Block type arguments. No block.json, so apiVersion 3 is declared here.
			 *
			 * @var array<string, mixed> $args
			 */
			$args = [
				'api_version'     => 3,
				'title'           => __( 'Review entry', 'post-kinds-for-indieweb-in-block-themes' ),
				'category'        => 'post-kinds-indieweb',
				'render_callback' => [ self::class, 'render_entry_block' ],
				'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
				'attributes'      => Grouped_Archive::entry_attributes(
					[
						'linesPerPage' => [
							'default' => 9,
						],
					]
				),
				'supports'        => [
					'html'     => false,
					'reusable' => false,
				],
				'editor_script'   => 'pkiw-review-entry-editor',
				'style'           => 'pkiw-kind-layouts',
			];
			register_block_type( self::ENTRY_BLOCK, $args );
		}

		wp_register_script(
			'pkiw-four-stars-editor',
			\PKIW_URL . 'assets/js/four-stars-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-four-stars-editor', 'post-kinds-for-indieweb-in-block-themes' );

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::FOUR_STARS_BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Four Stars', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_four_stars' ],
			'attributes'      => [
				'count'        => [
					'type'    => 'integer',
					'default' => 6,
				],
				'headingLevel' => [
					'type'    => 'integer',
					'default' => 2,
				],
				'heading'      => [
					'type'    => 'string',
					'default' => '',
					'role'    => 'content',
				],
			],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-four-stars-editor',
			'style'           => 'pkiw-kind-layouts',
		];
		register_block_type( self::FOUR_STARS_BLOCK, $args );
	}

	/**
	 * Public review facts for a review post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function review_facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$prefix = Meta_Fields::PREFIX . 'review_';
		$block  = Card_Meta_Sync::star_rating_facts( $post->post_content );

		$meta_rating = (float) get_post_meta( $post_id, $prefix . 'rating', true );
		if ( $meta_rating > 0 ) {
			$rating = $meta_rating;
			$best   = (int) get_post_meta( $post_id, $prefix . 'best', true );
			$best   = $best > 0 ? $best : 5;
		} elseif ( null !== $block ) {
			$rating = (float) $block['rating'];
			$best   = (int) $block['best'];
		} else {
			$rating = 0.0;
			$best   = 5;
		}

		$meta_name = trim( (string) get_post_meta( $post_id, $prefix . 'item_name', true ) );
		$item_name = '' !== $meta_name ? $meta_name : ( null !== $block ? $block['item_name'] : '' );

		$meta_url = trim( (string) get_post_meta( $post_id, $prefix . 'item_url', true ) );
		$item_url = '' !== $meta_url
			? web_url( $meta_url )
			: ( null !== $block ? web_url( (string) $block['item_url'] ) : '' );

		$group  = Grouped_Archive::group_of_post( self::KIND, $post_id );
		$cat_id = null !== $group ? (int) $group->key() : 0;
		$link   = $cat_id > 0 ? get_term_link( $cat_id, 'category' ) : '';

		return [
			'rating'       => $rating,
			'best'         => $best,
			'item_name'    => $item_name,
			'item_url'     => $item_url,
			'rating_label' => $rating > 0 ? card_rating_label( $rating, $best ) : '',
			'stars'        => card_star_counts( $rating, $best ),
			'pullquote'    => self::pullquote( $post->post_content ),
			'category'     => [
				'id'   => $cat_id,
				'name' => null !== $group ? $group->raw() : '',
				'slug' => null !== $group ? $group->slug() : '',
				'link' => $cat_id > 0 && ! is_wp_error( $link ) ? (string) $link : '',
			],
		];
	}

	/**
	 * Rating stars without microformat properties.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $facts Review facts.
	 * @return string Rating markup.
	 */
	public static function review_rating_html( array $facts ): string {
		$rating = (float) ( $facts['rating'] ?? 0 );
		$best   = (int) ( $facts['best'] ?? 5 );
		$counts = card_star_counts( $rating, $best );
		if ( $counts['value'] <= 0 ) {
			return '';
		}

		$clip  = is_rtl() ? 'inset(0 0 0 50%)' : 'inset(0 50% 0 0)';
		$path  = 'M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z';
		$full  = '<svg class="" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="' . $path . '"/></svg>';
		$empty = '<svg class="off" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="' . $path . '"/></svg>';
		$half  = '<svg class="half" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path class="off" d="' . $path . '"/><path d="' . $path . '" style="clip-path:' . $clip . '"/></svg>';
		$stars = str_repeat( $full, $counts['full'] )
			. ( $counts['half'] ? $half : '' )
			. str_repeat( $empty, $counts['empty'] );

		return sprintf(
			'<p class="pkiw-review-rating"><span class="pkiw-review-rating__label">%1$s</span> <span class="pk-stars" aria-hidden="true" role="presentation">%2$s</span></p>',
			esc_html( card_rating_label( $rating, $best ) ),
			$stars
		);
	}

	/**
	 * Review item markup.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $facts Review facts.
	 * @param bool                 $mf2   Whether to include microformat classes.
	 * @return string Item markup.
	 */
	public static function review_item_html( array $facts, bool $mf2 ): string {
		$name = trim( (string) ( $facts['item_name'] ?? '' ) );
		if ( '' === $name ) {
			return '';
		}
		$url = (string) ( $facts['item_url'] ?? '' );

		if ( $mf2 ) {
			return '' !== $url
				? '<span class="p-item h-product"><a class="p-name u-url" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></span>'
				: '<span class="p-item h-product"><span class="p-name">' . esc_html( $name ) . '</span></span>';
		}

		return '' !== $url
			? '<span class="pkiw-review-item"><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></span>'
			: '<span class="pkiw-review-item">' . esc_html( $name ) . '</span>';
	}

	/**
	 * Render the review entry block.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Markup.
	 */
	public static function render_entry_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry(
			self::ENTRY_BLOCK,
			$attributes,
			$block,
			static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post, $attributes, $in_section ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		);
	}

	/**
	 * Render Four Stars.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Markup, or '' off page 1 or when no review qualifies.
	 */
	public static function render_four_stars( array $attributes = [] ): string {
		if ( is_paged() ) {
			return '';
		}

		$picks = self::four_star_posts( max( 1, min( 6, (int) ( $attributes['count'] ?? 6 ) ) ) );
		if ( [] === $picks ) {
			return '';
		}

		$level   = max( 2, min( 4, (int) ( $attributes['headingLevel'] ?? 2 ) ) );
		$heading = trim( (string) ( $attributes['heading'] ?? '' ) );
		if ( '' === $heading ) {
			$heading = __( 'Four Stars', 'post-kinds-for-indieweb-in-block-themes' );
		}

		$items = '';
		foreach ( $picks as $post ) {
			$facts = self::review_facts( $post->ID );
			$name  = trim( (string) ( $facts['item_name'] ?? '' ) );
			if ( '' === $name ) {
				$name = trim( wp_strip_all_tags( get_the_title( $post ) ) );
			}
			if ( '' === $name ) {
				$name = untitled_name( $post );
			}
			$items .= sprintf(
				'<li class="pkiw-four-stars__item"><a class="pkiw-four-stars__link" href="%1$s">%2$s</a></li>',
				esc_url( (string) get_permalink( $post ) ),
				esc_html( $name )
			);
		}

		$heading_id = wp_unique_id( 'pkiw-four-stars-' );

		return sprintf(
			'<section %1$s><h%2$d id="%3$s" class="pkiw-four-stars__heading">%4$s</h%2$d><ul class="pkiw-four-stars__list">%5$s</ul></section>',
			get_block_wrapper_attributes(
				[
					'class'           => 'pkiw-four-stars',
					'aria-labelledby' => $heading_id,
				]
			),
			$level,
			esc_attr( $heading_id ),
			esc_html( $heading ),
			$items
		);
	}

	/**
	 * Strip star-rating microformat classes inside review posts.
	 *
	 * @since 1.9.0
	 * @internal
	 *
	 * @param string               $content      Rendered block content.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @param \WP_Block|null       $instance     Block instance.
	 * @return string Filtered block content.
	 */
	public function strip_star_rating_mf2( string $content, array $parsed_block = [], $instance = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$post_id = $instance instanceof \WP_Block && ! empty( $instance->context['postId'] )
			? (int) $instance->context['postId']
			: (int) get_the_ID();
		if ( $post_id <= 0 || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post_id ) ) {
			return $content;
		}

		$tokens = [ 'h-review', 'p-rating', 'p-best', 'p-worst', 'p-item', 'h-product', 'p-name', 'u-url' ];
		$tags   = new \WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag() ) {
			foreach ( $tokens as $token ) {
				$tags->remove_class( $token );
			}
		}

		return $tags->get_updated_html();
	}

	/**
	 * Remove Simple Location map and location appenders and geo head meta tags on review singles.
	 *
	 * @since 1.9.0
	 * @internal
	 *
	 * @return void
	 */
	public function suppress_simple_location(): void {
		if ( ! is_singular() || ! has_term( self::KIND, Taxonomy::TAXONOMY, get_queried_object_id() ) ) {
			return;
		}

		foreach (
			[
				11 => 'content_map',
				12 => 'location_content',
			] as $priority => $method
		) {
			$hook = $GLOBALS['wp_filter']['the_content'] ?? null;
			if ( ! $hook instanceof \WP_Hook || empty( $hook->callbacks[ $priority ] ) ) {
				continue;
			}
			foreach ( $hook->callbacks[ $priority ] as $callback ) {
				$callable = $callback['function'] ?? null;
				if ( self::is_simple_location_callback( $callable, 'Geo_Data', $method ) ) {
					remove_filter( 'the_content', $callable, $priority );
				}
			}
		}

		$hook = $GLOBALS['wp_filter']['wp_head'] ?? null;
		if ( ! $hook instanceof \WP_Hook || empty( $hook->callbacks[10] ) ) {
			return;
		}
		foreach ( $hook->callbacks[10] as $callback ) {
			$callable = $callback['function'] ?? null;
			if ( self::is_simple_location_callback( $callable, 'Geo_Base', 'meta_tags' ) ) {
				remove_action( 'wp_head', $callable, 10 );
			}
		}
	}

	/**
	 * One review archive entry.
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a section heading stands above the entry.
	 * @return string
	 */
	private static function entry_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$level      = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$title      = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		$real_title = '' !== $title;
		if ( ! $real_title ) {
			$title = untitled_name( $post );
		}

		// A password-protected review shows its title and the password form, nothing else.
		$protected   = post_password_required( $post );
		$facts       = $protected ? [] : self::review_facts( $post->ID );
		$item_name   = trim( (string) ( $facts['item_name'] ?? '' ) );
		$cover       = $protected ? '' : self::cover( kind_picture( $post->ID ) );
		$author_html = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';

		$html  = '<div class="pkiw-review-entry">';
		$html .= '' !== $cover ? '<figure class="pkiw-review-entry__figure">' . $cover . '</figure>' : '';
		$html .= sprintf(
			'<h%1$d class="pkiw-review-entry__title%2$s"><a class="u-url" href="%3$s">%4$s</a></h%1$d>',
			$level,
			$real_title ? ' p-name' : '',
			esc_url( (string) get_permalink( $post ) ),
			esc_html( $title )
		);
		if ( '' !== $item_name ) {
			$html .= '<p class="pkiw-review-entry__item p-item h-product"><span class="p-name">' . esc_html( $item_name ) . '</span></p>';
		}
		if ( $protected ) {
			$html .= '<div class="pkiw-review-entry__password">' . get_the_password_form( $post ) . '</div>';
		}
		$html .= '<data class="dt-published" value="' . esc_attr( (string) get_the_date( 'c', $post ) ) . '" hidden></data>';
		if ( '' !== $author_html ) {
			$html .= '<span hidden>' . $author_html . '</span>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Decorative review cover.
	 *
	 * @param array{source: string, attachment_id: int, url: string, alt: string, remote: bool, suppress_featured: bool} $picture From kind_picture().
	 * @return string Image markup, or '' with no picture.
	 */
	private static function cover( array $picture ): string {
		if ( '' === $picture['url'] ) {
			return '';
		}

		$img = $picture['attachment_id'] > 0
			? wp_get_attachment_image(
				$picture['attachment_id'],
				'medium_large',
				false,
				[
					'class'   => 'pkiw-review-entry__cover',
					'alt'     => '',
					'loading' => 'lazy',
				]
			)
			: '';
		if ( '' === $img ) {
			$img = sprintf( '<img class="pkiw-review-entry__cover" src="%s" alt="" />', esc_url( $picture['url'] ) );
		}

		$tags = new \WP_HTML_Tag_Processor( $img );
		if ( ! $tags->next_tag( [ 'tag_name' => 'img' ] ) ) {
			return '';
		}
		$tags->set_attribute( 'alt', '' );
		$tags->set_attribute( 'decoding', 'async' );
		$tags->set_attribute( 'loading', 'lazy' );
		$tags->remove_attribute( 'fetchpriority' );
		$tags->set_attribute( 'class', 'pkiw-review-entry__cover' );

		return $tags->get_updated_html();
	}

	/**
	 * The first pullquote's text.
	 *
	 * @param string $content Post content.
	 * @return string Pullquote text, or ''.
	 */
	private static function pullquote( string $content ): string {
		$block = self::first_block( parse_blocks( $content ), 'core/pullquote' );
		if ( null === $block ) {
			return '';
		}
		$html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
		$html = (string) preg_replace( '#<cite\b[^>]*>.*?</cite>#is', '', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Find the first block of a given type, depth first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param string                           $name   Block name.
	 * @return array<string, mixed>|null
	 */
	private static function first_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['blockName'] ?? '' ) === $name ) {
				return $block;
			}
			$found = self::first_block( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [], $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Four-star review posts.
	 *
	 * @param int $count Number of posts, 1 to 6.
	 * @return \WP_Post[]
	 */
	private static function four_star_posts( int $count ): array {
		global $wpdb;

		$term = get_term_by( 'slug', self::KIND, Taxonomy::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return [];
		}

		$taxonomy = get_taxonomy( Taxonomy::TAXONOMY );
		$types    = $taxonomy ? array_values( array_filter( array_map( 'strval', (array) $taxonomy->object_type ), 'is_post_type_viewable' ) ) : [];
		if ( [] === $types ) {
			return [];
		}

		$rating_key = Meta_Fields::PREFIX . 'review_rating';
		$best_key   = Meta_Fields::PREFIX . 'review_best';
		$sql        = "SELECT four.ID FROM (
				SELECT p.ID, p.post_date,
					( SELECT CAST( r.meta_value AS DECIMAL(6,2) ) FROM {$wpdb->postmeta} r WHERE r.post_id = p.ID AND r.meta_key = %s ORDER BY r.meta_id LIMIT 1 ) AS rating,
					( SELECT CAST( b.meta_value AS UNSIGNED ) FROM {$wpdb->postmeta} b WHERE b.post_id = p.ID AND b.meta_key = %s ORDER BY b.meta_id LIMIT 1 ) AS best
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				WHERE p.post_type IN (" . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ")
					AND p.post_status = 'publish'
					AND LENGTH( p.post_password ) = 0
			) AS four
			WHERE four.rating > 0 AND four.rating * 5 = COALESCE( NULLIF( four.best, 0 ), 5 ) * 4
			ORDER BY four.post_date DESC, four.ID DESC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- WP_Query cannot express the scalar first-row rating/best comparison.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The post-type IN list is one placeholder per type.
				...array_merge( [ $rating_key, $best_key, (int) $term->term_taxonomy_id ], $types, [ $count ] )
			)
		);

		$posts = [];
		foreach ( $rows as $row ) {
			$post = get_post( (int) $row->ID );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
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
}

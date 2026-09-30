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
	 * Last group key rendered per query, for deriving section headings.
	 *
	 * @var array<string, string>
	 */
	private static array $last_group = [];

	/**
	 * Resolved-template content per kind term ID for this request.
	 *
	 * @var array<int, string|null>
	 */
	private array $template_cache = [];

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
		add_filter( 'render_block_data', [ $this, 'reset_on_post_template' ] );
		add_action( 'pkiw_menu_entry_reset', [ self::class, 'reset_sections' ] );
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

		$fields = self::group_fields();
		if ( isset( $fields[ $term->slug ] ) && str_contains( $content, 'wp:' . self::MENU_ENTRY ) ) {
			$query->set( 'pkiw_group_by', $fields[ $term->slug ] );
		}
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

		$content                                = isset( $templates[0] ) ? (string) $templates[0]->content : null;
		$this->template_cache[ $term->term_id ] = $content;

		return $content;
	}

	/**
	 * Apply group ordering when `pkiw_group_by` names a known group field.
	 *
	 * ORDER BY: posts with an empty group last, group (case-insensitive)
	 * ascending, then post_date DESC, ID DESC. A correlated subquery keeps
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

		return "({$value} = '') ASC, LOWER({$value}) ASC, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
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
			$query_vars['pkiw_group_by'] = $fields[ $kind ];
		}
		return $query_vars;
	}

	/**
	 * Start heading tracking fresh for each Post Template render.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return array<string, mixed>
	 */
	public function reset_on_post_template( $parsed_block ) {
		if ( 'core/post-template' === ( $parsed_block['blockName'] ?? '' ) ) {
			self::reset_sections();
		}
		return $parsed_block;
	}

	/**
	 * Forget the last rendered group for every query.
	 *
	 * @return void
	 */
	public static function reset_sections(): void {
		self::$last_group = [];
	}

	/**
	 * Section heading label for a stored group value.
	 *
	 * @param string $kind  Kind slug.
	 * @param string $value Stored group value.
	 * @return string
	 */
	public static function group_label( string $kind, string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return __( 'Other', 'post-kinds-for-indieweb-in-block-themes' );
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

		$kinds   = wp_get_object_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
		$kind    = ( ! is_wp_error( $kinds ) && ! empty( $kinds ) ) ? (string) $kinds[0] : '';
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
		$show_venue = '' !== $venue && ! empty( $visible['name'] );

		$ts = $when ? strtotime( $when ) : false;
		if ( $ts ) {
			$iso     = gmdate( 'c', $ts );
			$display = wp_date( (string) get_option( 'date_format' ), $ts );
		} else {
			$iso     = (string) get_the_date( 'c', $post );
			$display = (string) get_the_date( '', $post );
		}

		$heading = ( $attributes['showSections'] ?? true )
			? self::section_heading( $post_id, $kind, (string) ( $block->context['queryId'] ?? 0 ), (int) ( $attributes['headingLevel'] ?? 2 ) )
			: '';

		$root_class = $property ? 'pkiw-menu-entry__item p-' . $property . ' h-food' : 'pkiw-menu-entry__item';

		ob_start();
		?>
<div class="pkiw-menu-entry">
		<?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped when built. ?>
	<div class="<?php echo esc_attr( $root_class ); ?>">
		<p class="pkiw-menu-entry__line">
			<a class="pkiw-menu-entry__name p-name" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php echo esc_html( $name ); ?></a>
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
		</p>
		<p class="pkiw-menu-entry__meta">
			<?php foreach ( $subs as $sub ) : ?>
				<span class="pkiw-menu-entry__sub"><?php echo esc_html( $sub ); ?></span>
			<?php endforeach; ?>
			<?php if ( $show_venue ) : ?>
				<span class="pkiw-menu-entry__sub p-location h-card"><span class="p-name"><?php echo esc_html( $venue ); ?></span></span>
			<?php endif; ?>
			<time class="pkiw-menu-entry__date dt-published" datetime="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $display ); ?></time>
		</p>
		<?php if ( '' !== $notes ) : ?>
			<p class="pkiw-menu-entry__notes p-content"><?php echo esc_html( $notes ); ?></p>
		<?php endif; ?>
	</div>
	<data class="u-url" value="<?php echo esc_url( get_permalink( $post ) ); ?>" hidden></data>
		<?php if ( $property ) : ?>
		<data class="u-<?php echo esc_attr( $property ); ?>" value="<?php echo esc_attr( $name ); ?>" hidden></data>
		<?php endif; ?>
</div>
		<?php
		return (string) ob_get_clean();
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
	 * Section heading for a loop item when its group differs from the
	 * previous item's in the same query; empty otherwise.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $kind      Kind slug.
	 * @param string $query_key Query ID from block context.
	 * @param int    $level     Requested heading level (clamped 2-4).
	 * @return string
	 */
	private static function section_heading( int $post_id, string $kind, string $query_key, int $level ): string {
		$fields = self::group_fields();
		if ( ! isset( $fields[ $kind ] ) ) {
			return '';
		}

		$raw       = trim( (string) get_post_meta( $post_id, $fields[ $kind ], true ) );
		$group_key = mb_strtolower( $raw );
		$is_new    = ! array_key_exists( $query_key, self::$last_group ) || self::$last_group[ $query_key ] !== $group_key;

		self::$last_group[ $query_key ] = $group_key;

		if ( ! $is_new ) {
			return '';
		}

		$level = max( 2, min( 4, $level ) );
		return sprintf(
			'<h%1$d class="pkiw-menu-entry__section">%2$s</h%1$d>',
			$level,
			esc_html( self::group_label( $kind, $raw ) )
		);
	}
}

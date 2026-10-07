<?php
/**
 * The #230 eat and drink menus, pinned before the grouped archive engine (P3).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Pins what the eat and drink menus serve: section headings and their lines
 * on pages 1 and 2, a theme-like template's settings, the REST menu orders,
 * each line's editor render, the ORDER BY clause and one page's markup.
 * The expected values are written out by hand, so this file passes on the
 * code before the engine and must keep passing after it.
 *
 * @group integration
 */
final class GroupedArchiveMenuParityTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 4 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A published post of a kind, with its card and any extra meta.
	 *
	 * @param string                $kind  Kind slug.
	 * @param string                $title Title.
	 * @param string                $date  Post date.
	 * @param array<string, mixed>  $attrs Card attributes.
	 * @param array<string, string> $meta  Meta written after save.
	 */
	private function post( string $kind, string $title, string $date, array $attrs, array $meta = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/' . $kind . '-card ' . wp_json_encode( $attrs ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, $kind, 'kind' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Eat fixtures: mixed-case cuisine, a shared date broken by ID, no cuisine.
	 *
	 * @return array<string, int>
	 */
	private function eats(): array {
		return [
			'pho'      => $this->post( 'eat', 'Pho', '2026-01-05 10:00:00', [ 'name' => 'Pho', 'cuisine' => 'Vietnamese' ] ),
			'banh_mi'  => $this->post( 'eat', 'Banh mi', '2026-01-03 10:00:00', [ 'name' => 'Banh mi', 'cuisine' => 'vietnamese' ] ),
			'pad_thai' => $this->post( 'eat', 'Pad thai', '2026-02-01 10:00:00', [ 'name' => 'Pad thai', 'cuisine' => 'Thai' ] ),
			'khao_soi' => $this->post( 'eat', 'Khao soi', '2026-02-01 10:00:00', [ 'name' => 'Khao soi', 'cuisine' => 'Thai' ] ),
			'toast'    => $this->post( 'eat', 'Toast', '2026-03-01 10:00:00', [ 'name' => 'Toast' ] ),
			'larb'     => $this->post( 'eat', 'Larb', '2026-01-10 10:00:00', [ 'name' => 'Larb', 'cuisine' => 'Lao' ] ),
		];
	}

	/**
	 * Drink fixtures: no type, "other", two coffees and a type the plugin has no label for.
	 *
	 * @return array<string, int>
	 */
	private function drinks(): array {
		return [
			'house'   => $this->post( 'drink', 'House pour', '2026-02-03 10:00:00', [ 'name' => 'House pour' ] ),
			'punch'   => $this->post( 'drink', 'Mystery punch', '2026-02-02 10:00:00', [ 'name' => 'Mystery punch', 'drinkType' => 'other' ] ),
			'cortado' => $this->post( 'drink', 'Cortado', '2026-02-04 10:00:00', [ 'name' => 'Cortado', 'drinkType' => 'coffee' ] ),
			'flat'    => $this->post( 'drink', 'Flat white', '2026-02-05 10:00:00', [ 'name' => 'Flat white', 'drinkType' => 'coffee' ] ),
			'kvass'   => $this->post( 'drink', 'Kvass', '2026-02-01 10:00:00', [ 'name' => 'Kvass' ], [ '_pkiw_drink_type' => 'kvass' ] ),
		];
	}

	/**
	 * Each section of rendered HTML: heading tag and text, then its line names.
	 *
	 * @return array<int, array{0:string,1:string[]}>
	 */
	private function sections( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$out   = [];
		foreach ( $xpath->query( '//section[contains(concat(" ", @class, " "), " pkiw-menu-section ")]' ) as $section ) {
			$names = [];
			foreach ( $xpath->query( './ul[contains(@class, "pkiw-menu-section__items")]/li//a[contains(@class, "pkiw-menu-entry__name")]', $section ) as $a ) {
				$names[] = trim( $a->textContent );
			}
			$heading = $xpath->query( './*[contains(@class, "pkiw-menu-section__heading")]', $section )->item( 0 );
			$out[]   = [ $heading ? $heading->nodeName . ':' . trim( $heading->textContent ) : '', $names ];
		}

		return $out;
	}

	/**
	 * Render the block template resolved for a kind archive request.
	 *
	 * @param string $kind Kind slug.
	 */
	private function render_template( string $kind ): string {
		$template = resolve_block_template(
			'taxonomy',
			[ "taxonomy-kind-{$kind}.php", 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ],
			''
		);

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Sections of one page of a kind archive on the plugin's template.
	 *
	 * @param string $kind Kind slug.
	 * @param int    $page Page number.
	 * @return array<int, array{0:string,1:string[]}>
	 */
	private function page( string $kind, int $page ): array {
		$url = get_term_link( $kind, 'kind' );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );

		return $this->sections( $this->render_template( $kind ) );
	}

	/**
	 * A theme-like template: the menu entry carries every setting.
	 *
	 * @param array<string, mixed> $attrs Menu entry attributes.
	 * @return string Post Template block markup inside an inherited Query Loop.
	 */
	private function theme_loop( array $attrs ): string {
		return '<!-- wp:query {"queryId":230,"query":{"perPage":6,"inherit":true},"className":"cr-menu__query"} --><div class="wp-block-query cr-menu__query"><!-- wp:post-template {"className":"is-style-pkiw-menu cr-menu__list"} --><!-- wp:post-kinds-indieweb/menu-entry ' . wp_json_encode( $attrs ) . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Serve a kind archive from a template holding the given loop and render the loop.
	 *
	 * @param string $kind Kind slug.
	 * @param string $loop Query Loop markup.
	 * @param int    $page Page number.
	 */
	private function serve_loop( string $kind, string $loop, int $page = 1 ): string {
		$swap = static function ( $templates ) use ( $kind, $loop ) {
			foreach ( $templates as $template ) {
				if ( "taxonomy-kind-{$kind}" === $template->slug ) {
					$template->content = $loop;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$url = get_term_link( $kind, 'kind' );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
		$html = do_blocks( $loop );
		remove_filter( 'get_block_templates', $swap );

		return $html;
	}

	/**
	 * Post IDs the REST posts route returns for a kind in a named order.
	 *
	 * @param string $kind    Kind slug.
	 * @param string $orderby REST orderby value.
	 * @return int[]
	 */
	private function rest_ids( string $kind, string $orderby ): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ get_term_by( 'slug', $kind, 'kind' )->term_id ] );
		$request->set_param( 'orderby', $orderby );
		$request->set_param( 'per_page', 20 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
	}

	/**
	 * The editor's render of one menu line.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $attrs   Menu entry attributes.
	 */
	private function editor_render( int $post_id, array $attrs = [] ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/menu-entry' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		if ( $attrs ) {
			$request->set_param( 'attributes', $attrs );
		}
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return (string) $response->get_data()['rendered'];
	}

	/**
	 * What the editor shows for a line: its section, or "continued" for the hidden marker.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $attrs   Menu entry attributes.
	 * @return array<int, array{0:string,1:string[]}>|string
	 */
	private function editor_line( int $post_id, array $attrs = [] ) {
		$html = $this->editor_render( $post_id, $attrs );
		if ( '<div class="pkiw-menu-entry pkiw-menu-entry--continued" hidden></div>' === $html ) {
			return 'continued';
		}
		$sections = $this->sections( $html );
		preg_match( '/data-pkiw-sections="(\d+)"/', $html, $count );

		return [ $sections, (int) ( $count[1] ?? 0 ) ];
	}

	public function test_the_eat_menu_serves_the_same_sections_on_pages_one_and_two(): void {
		$this->eats();

		$this->assertSame(
			[
				[ 'h2:Lao', [ 'Larb' ] ],
				[ 'h2:Thai', [ 'Khao soi', 'Pad thai' ] ],
				[ 'h2:Vietnamese', [ 'Pho' ] ],
			],
			$this->page( 'eat', 1 )
		);
		$this->assertSame(
			[
				[ 'h2:Vietnamese', [ 'Banh mi' ] ],
				[ 'h2:Other', [ 'Toast' ] ],
			],
			$this->page( 'eat', 2 ),
			'Vietnamese continues onto page two under its plain label, then the empty group.'
		);
		$this->assertSame( 2, (int) $GLOBALS['wp_query']->max_num_pages );
		$this->assertSame( 6, (int) $GLOBALS['wp_query']->found_posts );
	}

	public function test_the_drink_menu_serves_the_same_sections_on_pages_one_and_two(): void {
		$this->drinks();

		$this->assertSame(
			[
				[ 'h2:Coffee', [ 'Flat white', 'Cortado' ] ],
				[ 'h2:Kvass', [ 'Kvass' ] ],
				[ 'h2:Other', [ 'Mystery punch' ] ],
			],
			$this->page( 'drink', 1 )
		);
		$this->assertSame(
			[ [ 'h2:Drink', [ 'House pour' ] ] ],
			$this->page( 'drink', 2 ),
			'A drink with no type files under "Drink", after the typed ones.'
		);
	}

	public function test_a_theme_like_template_applies_every_menu_entry_setting(): void {
		$this->eats();
		$attrs = [
			'linesPerPage' => 2,
			'sectionOrder' => 'desc',
			'emptyGroup'   => 'first',
			'headingLevel' => 3,
			'emptyLabel'   => 'Kitchen sink',
		];

		$this->assertSame(
			[
				[ 'h3:Kitchen sink', [ 'Toast' ] ],
				[ 'h3:Vietnamese', [ 'Pho' ] ],
			],
			$this->sections( $this->serve_loop( 'eat', $this->theme_loop( $attrs ) ) )
		);
		$this->assertSame( 3, (int) $GLOBALS['wp_query']->max_num_pages, 'Two lines a page over six posts.' );
		$this->assertSame(
			[
				[ 'h3:Vietnamese', [ 'Banh mi' ] ],
				[ 'h3:Thai', [ 'Khao soi' ] ],
			],
			$this->sections( $this->serve_loop( 'eat', $this->theme_loop( $attrs ), 2 ) )
		);
		$this->assertSame(
			[
				[ 'h3:Thai', [ 'Pad thai' ] ],
				[ 'h3:Lao', [ 'Larb' ] ],
			],
			$this->sections( $this->serve_loop( 'eat', $this->theme_loop( $attrs ), 3 ) )
		);
	}

	public function test_the_rest_route_returns_each_menu_order(): void {
		$e = $this->eats();
		$d = $this->drinks();

		$this->assertSame( [ $e['larb'], $e['khao_soi'], $e['pad_thai'], $e['pho'], $e['banh_mi'], $e['toast'] ], $this->rest_ids( 'eat', 'pkiw_group' ) );
		$this->assertSame( [ $e['pho'], $e['banh_mi'], $e['khao_soi'], $e['pad_thai'], $e['larb'], $e['toast'] ], $this->rest_ids( 'eat', 'pkiw_group_desc' ) );
		$this->assertSame( [ $e['toast'], $e['larb'], $e['khao_soi'], $e['pad_thai'], $e['pho'], $e['banh_mi'] ], $this->rest_ids( 'eat', 'pkiw_group_empty_first' ) );
		$this->assertSame( [ $e['toast'], $e['pho'], $e['banh_mi'], $e['khao_soi'], $e['pad_thai'], $e['larb'] ], $this->rest_ids( 'eat', 'pkiw_group_desc_empty_first' ) );
		$this->assertSame( [ $d['flat'], $d['cortado'], $d['kvass'], $d['punch'], $d['house'] ], $this->rest_ids( 'drink', 'pkiw_group' ) );
		$this->assertSame( [ $d['house'], $d['punch'], $d['kvass'], $d['flat'], $d['cortado'] ], $this->rest_ids( 'drink', 'pkiw_group_desc_empty_first' ) );
	}

	public function test_each_line_renders_in_the_editor_as_before(): void {
		$e = $this->eats();

		// Four lines a page (the site setting): Lao, Thai, Vietnamese | Vietnamese, Other.
		$this->assertSame( [ [ [ 'h2:Lao', [ 'Larb' ] ] ], 3 ], $this->editor_line( $e['larb'] ) );
		$this->assertSame( [ [ [ 'h2:Thai', [ 'Khao soi', 'Pad thai' ] ] ], 3 ], $this->editor_line( $e['khao_soi'] ) );
		$this->assertSame( 'continued', $this->editor_line( $e['pad_thai'] ) );
		$this->assertSame( [ [ [ 'h2:Vietnamese', [ 'Pho' ] ] ], 3 ], $this->editor_line( $e['pho'] ) );
		$this->assertSame( [ [ [ 'h2:Vietnamese', [ 'Banh mi' ] ] ], 2 ], $this->editor_line( $e['banh_mi'] ), 'Page two opens Vietnamese again.' );
		$this->assertSame( [ [ [ 'h2:Other', [ 'Toast' ] ] ], 2 ], $this->editor_line( $e['toast'] ) );

		$attrs = [
			'linesPerPage' => 3,
			'sectionOrder' => 'desc',
			'emptyGroup'   => 'first',
			'headingLevel' => 3,
			'emptyLabel'   => 'Kitchen sink',
		];
		$this->assertSame( [ [ [ 'h3:Kitchen sink', [ 'Toast' ] ] ], 2 ], $this->editor_line( $e['toast'], $attrs ) );
		$this->assertSame( [ [ [ 'h3:Vietnamese', [ 'Pho', 'Banh mi' ] ] ], 2 ], $this->editor_line( $e['pho'], $attrs ) );
		$this->assertSame( 'continued', $this->editor_line( $e['banh_mi'], $attrs ) );
		$this->assertSame( [ [ [ 'h3:Thai', [ 'Khao soi', 'Pad thai' ] ] ], 2 ], $this->editor_line( $e['khao_soi'], $attrs ) );
		$this->assertSame( [ [ [ 'h3:Lao', [ 'Larb' ] ] ], 2 ], $this->editor_line( $e['larb'], $attrs ) );
	}

	public function test_drink_lines_render_in_the_editor_as_before(): void {
		$d = $this->drinks();

		$this->assertSame( [ [ [ 'h2:Coffee', [ 'Flat white', 'Cortado' ] ] ], 3 ], $this->editor_line( $d['flat'] ) );
		$this->assertSame( 'continued', $this->editor_line( $d['cortado'] ) );
		$this->assertSame( [ [ [ 'h2:Drink', [ 'House pour' ] ] ], 1 ], $this->editor_line( $d['house'] ) );
		$this->assertSame( [ [ [ 'h2:On tap', [ 'House pour' ] ] ], 1 ], $this->editor_line( $d['house'], [ 'emptyLabel' => 'On tap' ] ) );
	}

	public function test_the_order_by_clause_is_unchanged(): void {
		$this->eats();
		$query = new WP_Query(
			[
				'post_type'        => 'post',
				'posts_per_page'   => 2,
				'pkiw_group_by'    => '_pkiw_eat_cuisine',
				'pkiw_group_order' => 'DESC',
				'pkiw_group_empty' => 'first',
				'tax_query'        => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'eat' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);

		global $wpdb;
		$this->assertSame( 1, preg_match( '/ORDER BY (.*) LIMIT/s', $query->request, $found ) );
		$this->assertSame(
			"(COALESCE((SELECT pkiw_g.meta_value FROM wp_postmeta pkiw_g WHERE pkiw_g.post_id = wp_posts.ID AND pkiw_g.meta_key = '_pkiw_eat_cuisine' ORDER BY pkiw_g.meta_id ASC LIMIT 1), '') = '') DESC, LOWER(COALESCE((SELECT pkiw_g.meta_value FROM wp_postmeta pkiw_g WHERE pkiw_g.post_id = wp_posts.ID AND pkiw_g.meta_key = '_pkiw_eat_cuisine' ORDER BY pkiw_g.meta_id ASC LIMIT 1), '')) DESC, wp_posts.post_date DESC, wp_posts.ID DESC",
			str_replace( $wpdb->prefix, 'wp_', trim( $found[1] ) )
		);
		$this->assertSame( 6, (int) $query->found_posts, 'One row per post: grouping leaves the count alone.' );
		$this->assertSame( 3, (int) $query->max_num_pages );
	}

	public function test_one_page_of_the_eat_menu_prints_the_same_markup(): void {
		$e    = $this->eats();
		$html = $this->serve_loop( 'eat', $this->theme_loop( [ 'linesPerPage' => 3 ] ) );

		$ids = array_flip( $e );
		$out = preg_replace_callback(
			'/(post-|p=)(\d+)/',
			static fn( array $m ): string => $m[1] . '{' . ( $ids[ (int) $m[2] ] ?? $m[2] ) . '}',
			$html
		);
		$out = str_replace( home_url( '/' ), '{home}/', (string) $out );
		$out = preg_replace( '/>\s+</', '><', trim( (string) $out ) );

		$this->assertSame( self::THREE_LINE_PAGE, $out );
	}

	/**
	 * The first page of the eat menu at three lines a page, IDs and URLs replaced.
	 */
	private const THREE_LINE_PAGE = '<div class="wp-block-query cr-menu__query is-layout-flow wp-block-query-is-layout-flow">'
		. '<div class="pkiw-menu--sectioned is-style-pkiw-menu cr-menu__list wp-block-post-template is-layout-flow wp-block-post-template-is-layout-flow">'
		. '<section class="pkiw-menu-section" data-pkiw-sections="2"><h2 class="pkiw-menu-section__heading pkiw-menu-entry__section">Lao</h2><ul class="pkiw-menu-section__items">'
		. '<li class="wp-block-post post-{larb} post type-post status-publish format-standard hentry category-uncategorized kind-eat h-entry"><div class="pkiw-menu-entry"><div class="pkiw-menu-entry__item p-ate h-food"><div class="pkiw-menu-entry__line"><h3 class="pkiw-menu-entry__title"><a class="pkiw-menu-entry__name p-name" href="{home}/?p={larb}">Larb</a></h3><span class="pkiw-menu-entry__leader" aria-hidden="true"></span></div><p class="pkiw-menu-entry__meta"><time class="pkiw-menu-entry__date dt-published" datetime="2026-01-10T10:00:00+00:00">January 10, 2026</time></p></div><data class="u-url" value="{home}/?p={larb}" hidden></data><data class="dt-published" value="2026-01-10T10:00:00+00:00" hidden></data><data class="u-ate" value="Larb" hidden></data></div></li>'
		. '</ul></section>'
		. '<section class="pkiw-menu-section" data-pkiw-sections="2"><h2 class="pkiw-menu-section__heading pkiw-menu-entry__section">Thai</h2><ul class="pkiw-menu-section__items">'
		. '<li class="wp-block-post post-{khao_soi} post type-post status-publish format-standard hentry category-uncategorized kind-eat h-entry"><div class="pkiw-menu-entry"><div class="pkiw-menu-entry__item p-ate h-food"><div class="pkiw-menu-entry__line"><h3 class="pkiw-menu-entry__title"><a class="pkiw-menu-entry__name p-name" href="{home}/?p={khao_soi}">Khao soi</a></h3><span class="pkiw-menu-entry__leader" aria-hidden="true"></span></div><p class="pkiw-menu-entry__meta"><time class="pkiw-menu-entry__date dt-published" datetime="2026-02-01T10:00:00+00:00">February 1, 2026</time></p></div><data class="u-url" value="{home}/?p={khao_soi}" hidden></data><data class="dt-published" value="2026-02-01T10:00:00+00:00" hidden></data><data class="u-ate" value="Khao soi" hidden></data></div></li>'
		. '<li class="wp-block-post post-{pad_thai} post type-post status-publish format-standard hentry category-uncategorized kind-eat h-entry"><div class="pkiw-menu-entry"><div class="pkiw-menu-entry__item p-ate h-food"><div class="pkiw-menu-entry__line"><h3 class="pkiw-menu-entry__title"><a class="pkiw-menu-entry__name p-name" href="{home}/?p={pad_thai}">Pad thai</a></h3><span class="pkiw-menu-entry__leader" aria-hidden="true"></span></div><p class="pkiw-menu-entry__meta"><time class="pkiw-menu-entry__date dt-published" datetime="2026-02-01T10:00:00+00:00">February 1, 2026</time></p></div><data class="u-url" value="{home}/?p={pad_thai}" hidden></data><data class="dt-published" value="2026-02-01T10:00:00+00:00" hidden></data><data class="u-ate" value="Pad thai" hidden></data></div></li>'
		. '</ul></section>'
		. '</div></div>';
}

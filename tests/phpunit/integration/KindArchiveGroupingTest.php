<?php
/**
 * Grouped kind archives: ordering query var and menu entry rendering (issue 233).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies the `pkiw_group_by` query var orders a kind query by its group
 * field (empty group last), then date DESC, then ID DESC; that the main
 * query gets it only when the resolved template uses the menu entry; and
 * that the menu entry derives section headings, privacy-gated venue text
 * and a text rating at render time.
 *
 * @group integration
 */
final class KindArchiveGroupingTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'twentytwentyfive' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		unset( $GLOBALS['wp_theme_directories'][ array_search( dirname( __DIR__ ) . '/fixtures/themes', $GLOBALS['wp_theme_directories'], true ) ] );
		search_theme_directories( true );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * Create a published eat post.
	 *
	 * @param string               $title   Title.
	 * @param string               $date    Post date.
	 * @param array<string, mixed> $attrs   Eat card attributes.
	 */
	private function eat( string $title, string $date, array $attrs = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/eat-card ' . wp_json_encode( $attrs ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, 'eat', 'kind' );
		return $id;
	}

	/**
	 * Build the fixture set: two Italian (distinct dates), two Thai sharing a
	 * date (ID tiebreak), one with no cuisine.
	 *
	 * @return array<string, int>
	 */
	private function fixtures(): array {
		return [
			'italian_old' => $this->eat( 'Italian old', '2026-01-01 10:00:00', [ 'name' => 'Cacio e pepe', 'cuisine' => 'Italian' ] ),
			'none'        => $this->eat( 'No cuisine', '2026-03-01 10:00:00', [ 'name' => 'Toast' ] ),
			'thai_a'      => $this->eat( 'Thai A', '2026-02-01 10:00:00', [ 'name' => 'Pad see ew', 'cuisine' => 'Thai' ] ),
			'thai_b'      => $this->eat( 'Thai B', '2026-02-01 10:00:00', [ 'name' => 'Khao soi', 'cuisine' => 'thai' ] ),
			'italian_new' => $this->eat( 'Italian new', '2026-02-15 10:00:00', [ 'name' => 'Carbonara', 'cuisine' => 'Italian' ] ),
		];
	}

	public function test_group_by_orders_group_then_date_then_id_with_empty_group_last(): void {
		$p = $this->fixtures();

		$query = new WP_Query(
			[
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'pkiw_group_by'  => '_pkiw_eat_cuisine',
				'tax_query'      => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'eat' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);

		$this->assertSame(
			[ $p['italian_new'], $p['italian_old'], $p['thai_b'], $p['thai_a'], $p['none'] ],
			array_map( 'intval', $query->posts )
		);
	}

	public function test_group_by_ignores_keys_outside_the_group_field_map(): void {
		$p = $this->fixtures();

		$query = new WP_Query(
			[
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'pkiw_group_by'  => '_edit_lock',
				'tax_query'      => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'eat' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);

		$this->assertSame( $p['none'], (int) $query->posts[0], 'Unknown key falls back to plain date order.' );
	}

	public function test_checkin_archive_pages_by_24_and_the_map_follows_the_page(): void {
		$ids = [];
		for ( $i = 1; $i <= 26; $i++ ) {
			$id = self::factory()->post->create(
				[
					'post_status' => 'publish',
					'post_title'  => "Check-in {$i}",
					'post_date'   => sprintf( '2026-08-%02d 10:00:00', $i ),
				]
			);
			wp_set_object_terms( $id, 'checkin', 'kind' );
			update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
			update_post_meta( $id, '_pkiw_geo_latitude', (string) ( 40 + $i / 100 ) );
			update_post_meta( $id, '_pkiw_geo_longitude', (string) ( -75 - $i / 100 ) );
			$ids[ $i ] = $id;
		}

		// go_to() replaces the global query object, so read it after each request.
		$this->go_to( get_term_link( 'checkin', 'kind' ) );
		$wp_query = $GLOBALS['wp_query'];
		$this->assertCount( 24, $wp_query->posts );
		$this->assertSame( $ids[26], (int) $wp_query->posts[0]->ID, 'Newest first.' );
		$this->assertSame( 2, (int) $wp_query->max_num_pages );

		$this->go_to( add_query_arg( 'paged', 2, get_term_link( 'checkin', 'kind' ) ) );
		$wp_query = $GLOBALS['wp_query'];
		$this->assertCount( 2, $wp_query->posts );

		$pins = \PKIW\Checkin_Map::pins( \PKIW\Checkin_Map::entries( $wp_query->posts ) );
		$this->assertSame( [ [ $ids[2] ], [ $ids[1] ] ], array_column( $pins, 'ids' ), 'Page two maps check-ins 25 and 26 only.' );
	}

	public function test_main_query_groups_when_resolved_template_uses_menu_entry(): void {
		$p = $this->fixtures();

		$this->go_to( get_term_link( 'eat', 'kind' ) );

		global $wp_query;
		$this->assertSame( '_pkiw_eat_cuisine', $wp_query->get( 'pkiw_group_by' ) );
		$this->assertSame( $p['italian_new'], (int) $wp_query->posts[0]->ID );
		$this->assertSame( $p['none'], (int) end( $wp_query->posts )->ID );
	}

	public function test_main_query_groups_when_the_template_places_the_menu_through_a_pattern(): void {
		register_block_pattern(
			'pkiw-test/menu-loop',
			[
				'title'   => 'Menu loop',
				'content' => '<!-- wp:query {"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/menu-entry /--><!-- /wp:post-template --></div><!-- /wp:query -->',
			]
		);
		$template_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_name'    => 'taxonomy-kind-eat',
				'post_title'   => 'Eat archive',
				'post_content' => '<!-- wp:pattern {"slug":"pkiw-test/menu-loop"} /-->',
			]
		);
		wp_set_post_terms( $template_id, get_stylesheet(), 'wp_theme' );
		$p = $this->fixtures();

		$this->go_to( get_term_link( 'eat', 'kind' ) );

		global $wp_query;
		unregister_block_pattern( 'pkiw-test/menu-loop' );
		$this->assertSame( '_pkiw_eat_cuisine', $wp_query->get( 'pkiw_group_by' ) );
		$this->assertSame( $p['italian_new'], (int) $wp_query->posts[0]->ID );
		$this->assertSame( $p['none'], (int) end( $wp_query->posts )->ID );
	}

	public function test_the_template_is_looked_up_again_for_each_request(): void {
		$p = $this->fixtures();

		$this->go_to( get_term_link( 'eat', 'kind' ) );
		$this->assertSame( '_pkiw_eat_cuisine', $GLOBALS['wp_query']->get( 'pkiw_group_by' ), 'The plugin menu template groups.' );

		switch_theme( 'pkiw-kind-theme' );
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$this->assertSame( '', (string) $GLOBALS['wp_query']->get( 'pkiw_group_by' ), 'A theme template that takes over on the next request is read, not the remembered one.' );
		$this->assertSame( $p['none'], (int) $GLOBALS['wp_query']->posts[0]->ID );
	}

	public function test_main_query_untouched_when_theme_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );
		$p = $this->fixtures();

		$this->go_to( get_term_link( 'eat', 'kind' ) );

		global $wp_query;
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_by' ) );
		$this->assertSame( $p['none'], (int) $wp_query->posts[0]->ID, 'Theme archive keeps plain date order.' );
	}

	/**
	 * Each section of a rendered menu: its heading and the names of its lines, in document order.
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

	public function test_menu_archive_holds_each_group_in_its_own_section(): void {
		$this->fixtures();
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html = $this->render_template( 'eat' );

		$this->assertSame(
			[
				[ 'h2:Italian', [ 'Carbonara', 'Cacio e pepe' ] ],
				[ 'h2:Thai', [ 'Khao soi', 'Pad see ew' ] ],
				[ 'h2:Other', [ 'Toast' ] ],
			],
			$this->sections( $html ),
			'Every line sits inside the section its heading opens.'
		);
		$this->assertSame( 3, substr_count( $html, 'pkiw-menu-section__heading' ), 'One heading per section, none inside a line.' );
		$this->assertSame( 5, substr_count( $html, '<h3 class="pkiw-menu-entry__title">' ), 'A line names its dish in a heading under the section heading.' );
		$this->assertStringContainsString( 'pkiw-menu--sectioned', $html );
		$this->assertStringContainsString( 'wp-block-post-template', $html, 'The list keeps the Post Template wrapper classes.' );
		$this->assertSame( 5, substr_count( $html, 'class="wp-block-post ' ), 'Each line keeps its post classes.' );
		$this->assertStringContainsString( 'aria-hidden="true"', $html, 'Leader is hidden from assistive technology.' );
		$this->assertSame( 3, substr_count( $html, '<section class="pkiw-menu-section" data-pkiw-sections="3"><h2 ' ), 'Each section says how many the page holds. It has no name of its own, so it adds no landmark; its heading carries the structure.' );
	}

	public function test_a_section_restarts_with_its_heading_on_the_next_page(): void {
		$this->fixtures();
		$size = static function ( WP_Query $query ): void {
			if ( $query->is_main_query() ) {
				$query->set( 'posts_per_page', 3 );
			}
		};
		add_action( 'pre_get_posts', $size, 20 );
		$this->go_to( add_query_arg( 'paged', 2, get_term_link( 'eat', 'kind' ) ) );
		remove_action( 'pre_get_posts', $size, 20 );

		$this->assertSame(
			[
				[ 'h2:Thai', [ 'Pad see ew' ] ],
				[ 'h2:Other', [ 'Toast' ] ],
			],
			$this->sections( $this->render_template( 'eat' ) ),
			'Page two opens the Thai section again, with its heading.'
		);
	}

	/**
	 * Serve the eat archive from a template whose menu entry carries the given attributes.
	 *
	 * @param array<string, mixed> $attrs Menu entry attributes.
	 */
	private function sections_with( array $attrs ): array {
		$content = '<!-- wp:query {"queryId":9,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template {"className":"is-style-pkiw-menu"} --><!-- wp:post-kinds-indieweb/menu-entry ' . wp_json_encode( $attrs ) . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$swap    = static function ( $templates ) use ( $content ) {
			foreach ( $templates as $template ) {
				if ( 'taxonomy-kind-eat' === $template->slug ) {
					$template->content = $content;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$this->go_to( get_term_link( 'eat', 'kind' ) );
		$html = $this->render_template( 'eat' );
		remove_filter( 'get_block_templates', $swap );

		return $this->sections( $html );
	}

	public function test_section_order_and_the_empty_group_follow_the_menu_entry_settings(): void {
		$this->fixtures();

		$this->assertSame(
			[ 'h2:Thai', 'h2:Italian', 'h2:Other' ],
			array_column( $this->sections_with( [ 'sectionOrder' => 'desc' ] ), 0 ),
			'Z to A reverses the sections and leaves the posts with no cuisine last.'
		);
		$this->assertSame(
			[ 'h2:Kitchen sink', 'h2:Italian', 'h2:Thai' ],
			array_column(
				$this->sections_with(
					[
						'emptyGroup' => 'first',
						'emptyLabel' => 'Kitchen sink',
					]
				),
				0
			),
			'Posts with no cuisine can lead, under a heading the editor names.'
		);
	}

	public function test_lines_per_page_and_heading_level_follow_the_menu_entry_settings(): void {
		$this->fixtures();

		$sections = $this->sections_with(
			[
				'linesPerPage' => 2,
				'headingLevel' => 3,
			]
		);

		$this->assertSame( [ [ 'h3:Italian', [ 'Carbonara', 'Cacio e pepe' ] ] ], $sections, 'Two lines a page, headed at the level the entry asks for.' );
		global $wp_query;
		$this->assertSame( 3, (int) $wp_query->max_num_pages, 'Core pagination counts pages of two.' );
	}

	public function test_a_menu_without_section_headings_renders_as_core_renders_it(): void {
		$this->fixtures();

		$content = '<!-- wp:query {"queryId":9,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template {"className":"is-style-pkiw-menu"} --><!-- wp:post-kinds-indieweb/menu-entry {"showSections":false} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$swap    = static function ( $templates ) use ( $content ) {
			foreach ( $templates as $template ) {
				if ( 'taxonomy-kind-eat' === $template->slug ) {
					$template->content = $content;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$this->go_to( get_term_link( 'eat', 'kind' ) );
		$html = $this->render_template( 'eat' );
		remove_filter( 'get_block_templates', $swap );

		$this->assertStringNotContainsString( 'pkiw-menu-section', $html );
		$this->assertMatchesRegularExpression( '#<ul[^>]*class="[^"]*wp-block-post-template#', $html, 'Core prints its own list.' );
		$this->assertSame( 5, substr_count( $html, '<h2 class="pkiw-menu-entry__title">' ), 'With no section above it a line is headed at the entry\'s own level.' );
	}

	public function test_a_post_template_without_a_menu_entry_is_left_to_core(): void {
		$this->fixtures();
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html = do_blocks( '<!-- wp:query {"queryId":4,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->' );

		$this->assertStringNotContainsString( 'pkiw-menu-section', $html );
		$this->assertSame( 5, substr_count( $html, '<li class="wp-block-post ' ) );
	}

	public function test_menu_entry_rating_is_text(): void {
		$id = $this->eat( 'Rated', '2026-01-01 10:00:00', [ 'name' => 'Ramen', 'rating' => 4 ] );

		$html = $this->render_entry( $id );

		$this->assertStringContainsString( 'Rated 4 of 5', $html );
	}

	public function test_private_venue_hidden_from_anonymous_but_shown_to_editor(): void {
		$id = $this->eat(
			'Private dinner',
			'2026-01-01 10:00:00',
			[
				'name'            => 'Tasting menu',
				'restaurant'      => 'Secret Supper Club',
				'restaurantUrl'   => 'https://secret.example/',
				'locationAddress' => '12 Hidden Lane',
			]
		);
		update_post_meta( $id, '_pkiw_geo_privacy', 'private' );
		update_post_meta( $id, '_pkiw_eat_location_address', '12 Hidden Lane' );

		wp_set_current_user( 0 );
		$anon = $this->render_entry( $id );
		$this->assertStringNotContainsString( 'Secret Supper Club', $anon );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$editor = $this->render_entry( $id );
		$this->assertStringContainsString( 'Secret Supper Club', $editor );

		foreach ( [ $anon, $editor ] as $html ) {
			$this->assertStringNotContainsString( '12 Hidden Lane', $html, 'A menu line never prints a street.' );
			$this->assertStringNotContainsString( 'secret.example', $html, 'A menu line never prints a venue URL.' );
		}
	}

	public function test_venue_name_visible_to_anonymous_when_not_private(): void {
		$id = $this->eat( 'Public dinner', '2026-01-01 10:00:00', [ 'name' => 'Supplì', 'restaurant' => 'Trapizzino' ] );

		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'Trapizzino', $this->render_entry( $id ) );
	}

	/**
	 * Post IDs the REST posts route returns for a request, as an editor.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @return int[]
	 */
	private function rest_post_ids( array $params ): array {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		return array_map( 'intval', wp_list_pluck( $response->get_data(), 'id' ) );
	}

	public function test_the_editor_can_ask_the_rest_route_for_a_kind_in_menu_order(): void {
		$p    = $this->fixtures();
		$kind = get_term_by( 'slug', 'eat', 'kind' );

		$ids = $this->rest_post_ids(
			[
				'kind'     => [ $kind->term_id ],
				'orderby'  => 'pkiw_group',
				'per_page' => 10,
			]
		);

		$this->assertSame( [ $p['italian_new'], $p['italian_old'], $p['thai_b'], $p['thai_a'], $p['none'] ], $ids );
	}

	public function test_menu_order_without_one_menu_kind_falls_back_to_date_order(): void {
		$p = $this->fixtures();

		$ids = $this->rest_post_ids(
			[
				'orderby'  => 'pkiw_group',
				'per_page' => 10,
			]
		);

		$this->assertSame( $p['none'], $ids[0], 'Newest first when the request names no menu kind.' );
	}

	/**
	 * The editor's render of one menu line, with the menu entry attributes it sends.
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

	public function test_the_editor_renders_a_section_from_the_line_that_opens_it(): void {
		$p = $this->fixtures();

		$this->assertSame( [ [ 'h2:Italian', [ 'Carbonara', 'Cacio e pepe' ] ] ], $this->sections( $this->editor_render( $p['italian_new'] ) ) );
		$this->assertSame( [ [ 'h2:Thai', [ 'Khao soi', 'Pad see ew' ] ] ], $this->sections( $this->editor_render( $p['thai_b'] ) ) );
		$this->assertSame( [ [ 'h2:Other', [ 'Toast' ] ] ], $this->sections( $this->editor_render( $p['none'] ) ) );

		foreach ( [ 'italian_old', 'thai_a' ] as $continued ) {
			$html = $this->editor_render( $p[ $continued ] );
			$this->assertStringContainsString( 'pkiw-menu-entry--continued', $html, "{$continued} is already inside the section the line before it opened." );
			$this->assertStringNotContainsString( 'pkiw-menu-entry__name', $html );
			$this->assertNotSame( '', trim( $html ), 'The editor shows a placeholder for an empty render, so the marker is an element.' );
		}
	}

	public function test_the_editor_opens_a_section_again_on_the_first_line_of_a_page(): void {
		$p     = $this->fixtures();
		$sizes = static fn( int $per_page, string $slug ): int => 'eat' === $slug ? 3 : $per_page;
		add_filter( 'pkiw_kind_archive_preview_per_page', $sizes, 10, 2 );

		// Page 1 ends with the first Thai line; page 2 opens with the second, as the front end does.
		$page_one = $this->sections( $this->editor_render( $p['thai_b'] ) );
		$page_two = $this->sections( $this->editor_render( $p['thai_a'] ) );
		remove_filter( 'pkiw_kind_archive_preview_per_page', $sizes, 10 );

		$this->assertSame( [ [ 'h2:Thai', [ 'Khao soi' ] ] ], $page_one, 'A section stops at the end of its page.' );
		$this->assertSame( [ [ 'h2:Thai', [ 'Pad see ew' ] ] ], $page_two );
	}

	public function test_a_section_says_how_many_sections_its_page_holds_in_the_editor_too(): void {
		$p = $this->fixtures();

		// One page of five lines: Italian, Thai and Other.
		$this->assertStringContainsString( '<section class="pkiw-menu-section" data-pkiw-sections="3">', $this->editor_render( $p['italian_new'] ) );
		$this->assertStringContainsString( '<section class="pkiw-menu-section" data-pkiw-sections="3">', $this->editor_render( $p['none'] ) );

		// Three lines a page: Italian and Thai on page one, Thai and Other on page two.
		$this->assertStringContainsString( 'data-pkiw-sections="2"', $this->editor_render( $p['thai_b'], [ 'linesPerPage' => 3 ] ) );
		$this->assertStringContainsString( 'data-pkiw-sections="2"', $this->editor_render( $p['thai_a'], [ 'linesPerPage' => 3 ] ) );
		// Two lines a page: Italian fills page one alone.
		$this->assertStringContainsString( 'data-pkiw-sections="1"', $this->editor_render( $p['italian_new'], [ 'linesPerPage' => 2 ] ) );
	}

	public function test_the_editor_follows_the_menu_entry_settings(): void {
		$p = $this->fixtures();

		// Lines per page 2: Italian fills page one, so both Thai lines open page two together.
		$this->assertSame( [ [ 'h2:Thai', [ 'Khao soi', 'Pad see ew' ] ] ], $this->sections( $this->editor_render( $p['thai_b'], [ 'linesPerPage' => 2 ] ) ) );
		$this->assertSame(
			[ [ 'h3:No cuisine yet', [ 'Toast' ] ] ],
			$this->sections(
				$this->editor_render(
					$p['none'],
					[
						'emptyLabel'   => 'No cuisine yet',
						'headingLevel' => 3,
					]
				)
			)
		);
		$this->assertStringNotContainsString( 'pkiw-menu-section', $this->editor_render( $p['thai_a'], [ 'showSections' => false ] ), 'With section headings off every line renders on its own.' );
	}

	public function test_the_rest_route_orders_a_menu_by_each_named_order(): void {
		$p    = $this->fixtures();
		$term = get_term_by( 'slug', 'eat', 'kind' );
		$ids  = fn( string $orderby ): array => $this->rest_post_ids(
			[
				'kind'     => [ $term->term_id ],
				'orderby'  => $orderby,
				'per_page' => 10,
			]
		);

		$this->assertSame( [ $p['thai_b'], $p['thai_a'], $p['italian_new'], $p['italian_old'], $p['none'] ], $ids( 'pkiw_group_desc' ) );
		$this->assertSame( [ $p['none'], $p['italian_new'], $p['italian_old'], $p['thai_b'], $p['thai_a'] ], $ids( 'pkiw_group_empty_first' ) );
		$this->assertSame( [ $p['none'], $p['thai_b'], $p['thai_a'], $p['italian_new'], $p['italian_old'] ], $ids( 'pkiw_group_desc_empty_first' ) );
	}

	public function test_a_menu_line_is_an_h_entry_with_its_date_and_author(): void {
		$author = self::factory()->user->create(
			[
				'role'         => 'author',
				'display_name' => 'Menu Author',
			]
		);
		$id     = $this->eat( 'Tacos', '2026-03-01 10:00:00', [ 'name' => 'Mushroom Tacos', 'rating' => 4 ] );
		wp_update_post(
			[
				'ID'          => $id,
				'post_author' => $author,
			]
		);

		// The Post Template's list item is the h-entry; the block prints what goes inside it.
		$html   = '<li class="h-entry">' . $this->render_entry( $id ) . '</li>';
		$parsed = \Mf2\parse( $html, home_url( '/' ) );
		$entry  = $parsed['items'][0] ?? [];

		$this->assertSame( [ 'h-entry' ], $entry['type'] ?? [] );
		$this->assertSame( [ get_permalink( $id ) ], $entry['properties']['url'] ?? [] );
		$this->assertSame( [ get_the_date( 'c', $id ) ], $entry['properties']['published'] ?? [], 'The entry carries its own publish date, not only the meal\'s.' );
		$this->assertSame( [ 'Menu Author' ], $entry['properties']['author'][0]['properties']['name'] ?? [] );
		$this->assertArrayHasKey( 'ate', $entry['properties'] );
		$this->assertArrayNotHasKey( 'name', $entry['properties'], 'An eat entry stays title-less, as on its single.' );
	}

	public function test_eat_menu_line_names_the_restaurant_before_the_town(): void {
		$id = $this->eat( 'Tacos', '2026-03-01 10:00:00', [ 'name' => 'Mushroom Tacos', 'restaurant' => 'Mercado', 'locationLocality' => 'Reading' ] );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );

		$html = $this->render_entry( $id );

		$this->assertNotFalse( strpos( $html, 'Mercado' ) );
		$this->assertNotFalse( strpos( $html, 'Reading' ) );
		$this->assertLessThan( strpos( $html, 'Reading' ), strpos( $html, 'Mercado' ) );
	}

	public function test_menu_line_names_a_venue_that_matches_the_brand_once(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Latte',
				'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"Honey Lavender Latte","drinkType":"coffee","brand":"Commonplace Coffee","locationName":"commonplace coffee"} /-->',
			]
		);
		wp_set_object_terms( $id, 'drink', 'kind' );

		$html = $this->render_entry( $id );

		$this->assertSame( 1, substr_count( strtolower( $html ), 'commonplace coffee' ) );
	}

	public function test_drink_entry_shows_brand_and_groups_by_drink_type(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Stout',
				'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"Imperial stout","drinkType":"beer","brand":"Tröegs","rating":5} /-->',
			]
		);
		wp_set_object_terms( $id, 'drink', 'kind' );

		$html = $this->render_entry( $id );

		$this->assertStringContainsString( 'Imperial stout', $html );
		$this->assertStringContainsString( 'Tröegs', $html );
		$this->assertStringNotContainsString( 'pkiw-menu-section', $html, 'A line on its own prints no section.' );

		$this->go_to( get_term_link( 'drink', 'kind' ) );
		$this->assertSame( [ [ 'h2:Beer', [ 'Imperial stout' ] ] ], $this->sections( $this->render_template( 'drink' ) ), 'The drink menu heads the section with the drink type\'s label.' );
	}

	/**
	 * Create a published drink post.
	 *
	 * @param string               $title Title.
	 * @param string               $date  Post date.
	 * @param array<string, mixed> $attrs Drink card attributes.
	 */
	private function drink( string $title, string $date, array $attrs = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/drink-card ' . wp_json_encode( $attrs ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, 'drink', 'kind' );
		return $id;
	}

	public function test_the_empty_group_is_labelled_drink_for_drinks_and_other_elsewhere(): void {
		$this->assertSame( 'Drink', \PKIW\Kind_Archive_Layouts::group_label( 'drink', '' ), 'A drink with no type files under "Drink".' );
		$this->assertSame( 'Drink', \PKIW\Kind_Archive_Layouts::group_label( 'drink', '  ' ) );
		$this->assertSame( 'Other', \PKIW\Kind_Archive_Layouts::group_label( 'eat', '' ), 'A meal with no cuisine keeps "Other".' );
		$this->assertSame( 'Other', \PKIW\Kind_Archive_Layouts::group_label( 'recipe', '' ) );
		$this->assertSame( 'On tap', \PKIW\Kind_Archive_Layouts::group_label( 'drink', '', ' On tap ' ), 'A heading the editor names wins.' );
		$this->assertSame( 'Other', \PKIW\Kind_Archive_Layouts::group_label( 'drink', 'other' ), 'A drink stored as "other" keeps "Other".' );
		$this->assertSame( 'Coffee', \PKIW\Kind_Archive_Layouts::group_label( 'drink', 'coffee' ) );
	}

	public function test_drink_menu_files_a_drink_with_no_type_under_drink(): void {
		$this->drink( 'Stout', '2026-02-01 10:00:00', [ 'name' => 'Imperial stout', 'drinkType' => 'beer' ] );
		$this->drink( 'Punch', '2026-02-02 10:00:00', [ 'name' => 'Mystery punch', 'drinkType' => 'other' ] );
		$this->drink( 'House pour', '2026-02-03 10:00:00', [ 'name' => 'House pour' ] );
		$legacy = $this->drink( 'Cortado', '2026-02-04 10:00:00', [ 'name' => 'Cortado' ] );
		// Saved while the card still had a default: "coffee" in meta, no drinkType in the comment.
		update_post_meta( $legacy, '_pkiw_drink_type', 'coffee' );

		$this->go_to( get_term_link( 'drink', 'kind' ) );

		$this->assertSame(
			[
				[ 'h2:Beer', [ 'Imperial stout' ] ],
				[ 'h2:Coffee', [ 'Cortado' ] ],
				[ 'h2:Other', [ 'Mystery punch' ] ],
				[ 'h2:Drink', [ 'House pour' ] ],
			],
			$this->sections( $this->render_template( 'drink' ) ),
			'A drink with no type gets its own "Drink" section, after the typed ones; stored types keep their sections.'
		);
	}

	public function test_the_editor_heads_a_drink_with_no_type_as_the_front_end_does(): void {
		$unset = $this->drink( 'House pour', '2026-02-03 10:00:00', [ 'name' => 'House pour' ] );

		$this->assertSame( [ [ 'h2:Drink', [ 'House pour' ] ] ], $this->sections( $this->editor_render( $unset ) ) );
		$this->assertSame(
			[ [ 'h2:On tap', [ 'House pour' ] ] ],
			$this->sections( $this->editor_render( $unset, [ 'emptyLabel' => 'On tap' ] ) ),
			'A heading the editor names wins in the preview too.'
		);
	}

	public function test_a_drink_created_over_rest_with_no_type_stores_none_and_sorts_into_the_empty_group(): void {
		// The test case unregisters meta between tests.
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		$beer = $this->drink( 'Stout', '2026-02-01 10:00:00', [ 'name' => 'Imperial stout', 'drinkType' => 'beer' ] );
		$wine = $this->drink( 'Rioja', '2026-02-02 10:00:00', [ 'name' => 'Rioja', 'drinkType' => 'wine' ] );
		$kind = get_term_by( 'slug', 'drink', 'kind' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_param( 'title', 'House pour' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'date', '2026-02-03T10:00:00' );
		$request->set_param( 'kind', [ $kind->term_id ] );
		$request->set_param( 'content', '<!-- wp:post-kinds-indieweb/drink-card {"name":"House pour"} /-->' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$created = $response->get_data();
		$unset   = (int) $created['id'];

		$this->assertSame( '', $created['meta']['_pkiw_drink_type'], 'The REST response reports no drink type.' );
		$this->assertSame( '', get_post_meta( $unset, '_pkiw_drink_type', true ) );

		$ids = fn( string $orderby ): array => $this->rest_post_ids(
			[
				'kind'     => [ $kind->term_id ],
				'orderby'  => $orderby,
				'per_page' => 10,
			]
		);

		$this->assertSame( [ $beer, $wine, $unset ], $ids( 'pkiw_group' ), 'The drink with no type sits in the empty group, after the typed ones.' );
		$this->assertSame( [ $unset, $beer, $wine ], $ids( 'pkiw_group_empty_first' ), 'The empty group can lead.' );
	}

	public function test_a_quick_post_drink_keeps_the_type_it_was_given_or_none(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$quick_post = new \PKIW\Admin\Quick_Post( new \PKIW\Admin\Admin( \PKIW\Plugin::get_instance() ) );
		$create     = new ReflectionMethod( $quick_post, 'create_reaction_post' );

		$unset = $create->invoke(
			$quick_post,
			'drink',
			[
				'drink_name'  => 'House pour',
				'post_status' => 'publish',
			]
		);
		$tea   = $create->invoke(
			$quick_post,
			'drink',
			[
				'drink_name'  => 'Oolong',
				'drink_type'  => 'tea',
				'post_status' => 'publish',
			]
		);

		$this->assertSame( '', get_post_meta( $unset, '_pkiw_drink_type', true ), 'Quick Post stores no type when none is picked.' );
		$this->assertSame( 'tea', get_post_meta( $tea, '_pkiw_drink_type', true ), 'Quick Post stores the type it is given.' );

		$this->go_to( get_term_link( 'drink', 'kind' ) );
		$this->assertSame(
			[
				[ 'h2:Tea', [ 'Oolong' ] ],
				[ 'h2:Drink', [ 'House pour' ] ],
			],
			$this->sections( $this->render_template( 'drink' ) )
		);
	}

	/**
	 * Render one menu entry for a post as the first item of a fresh loop.
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_entry( int $post_id ): string {
		$block = new WP_Block(
			[
				'blockName'    => 'post-kinds-indieweb/menu-entry',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		);
		return $block->render();
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
}

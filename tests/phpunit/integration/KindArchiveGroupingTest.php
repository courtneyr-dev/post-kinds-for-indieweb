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

	public function test_main_query_groups_when_resolved_template_uses_menu_entry(): void {
		$p = $this->fixtures();

		$this->go_to( get_term_link( 'eat', 'kind' ) );

		global $wp_query;
		$this->assertSame( '_pkiw_eat_cuisine', $wp_query->get( 'pkiw_group_by' ) );
		$this->assertSame( $p['italian_new'], (int) $wp_query->posts[0]->ID );
		$this->assertSame( $p['none'], (int) end( $wp_query->posts )->ID );
	}

	public function test_main_query_untouched_when_theme_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );
		$p = $this->fixtures();

		$this->go_to( get_term_link( 'eat', 'kind' ) );

		global $wp_query;
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_by' ) );
		$this->assertSame( $p['none'], (int) $wp_query->posts[0]->ID, 'Theme archive keeps plain date order.' );
	}

	public function test_menu_archive_renders_each_section_heading_once_in_order(): void {
		$this->fixtures();
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html = $this->render_template( 'eat' );

		preg_match_all( '#<h2 class="pkiw-menu-entry__section">([^<]+)</h2>#', $html, $m );
		$this->assertSame( [ 'Italian', 'Thai', 'Other' ], $m[1] );
		$this->assertStringContainsString( 'Carbonara', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html, 'Leader is hidden from assistive technology.' );
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
		$this->assertStringContainsString( '>Beer</h2>', $html );
	}

	/**
	 * Render one menu entry for a post as the first item of a fresh loop.
	 *
	 * @param int $post_id Post ID.
	 */
	private function render_entry( int $post_id ): string {
		do_action( 'pkiw_menu_entry_reset' );
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

<?php
/**
 * The plugin's weather archive template (issue 246).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Almanac_Month;
use PKIW\Taxonomy;

/**
 * The weather archive template belongs to the plugin unless a theme or user
 * copy wins, and it pages one month at a time through the inherited query.
 *
 * @group integration
 */
final class WeatherArchiveTemplateTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'sloc_measurements', 'metric' );
		update_option( 'geo_public', '0' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$at = array_search( dirname( __DIR__ ) . '/fixtures/themes', $GLOBALS['wp_theme_directories'], true );
		if ( false !== $at ) {
			unset( $GLOBALS['wp_theme_directories'][ $at ] );
		}
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The template hierarchy for a weather term archive.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-weather.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flatten parsed blocks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-weather.html' );
		$flatten = static function ( array $blocks ) use ( &$flatten ): array {
			$out = [];
			foreach ( $blocks as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				$out[] = $block;
				$out   = array_merge( $out, $flatten( $block['innerBlocks'] ?? [] ) );
			}

			return $out;
		};

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-weather.html' ) ) );
	}

	/**
	 * Blocks of one name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}

	/**
	 * Render the weather archive template for a page.
	 *
	 * @param int $page Page number.
	 */
	private function render_archive( int $page = 1 ): string {
		$link = get_term_link( 'weather', Taxonomy::TAXONOMY );
		$this->assertIsString( $link );
		$this->go_to( $page > 1 ? trailingslashit( $link ) . 'page/' . $page . '/' : $link );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = (string) $template->content;

		return get_the_block_template_html();
	}

	/**
	 * XPath over HTML.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Seed three months of weather.
	 */
	private function seed(): void {
		foreach ( [ '2026-08-03 09:15:00', '2026-08-04 09:15:00', '2026-06-20 11:00:00', '2025-12-31 23:30:00' ] as $date ) {
			$id = self::factory()->post->create(
				[
					'post_status' => 'publish',
					'post_title'  => '',
					'post_date'   => $date,
				]
			);
			wp_set_object_terms( $id, 'weather', Taxonomy::TAXONOMY );
		}
	}

	public function test_the_file_parses_to_one_inheriting_query_with_the_almanac_inside_it(): void {
		$blocks = $this->blocks();

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertSame( 'pkiw-kind-weather', $queries[0]['attrs']['namespace'] ?? '' );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-title' ) );
		$this->assertFalse( $this->of_type( $blocks, 'core/query-title' )[0]['attrs']['showPrefix'] ?? true );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$almanac = $this->of_type( $queries[0]['innerBlocks'], Almanac_Month::BLOCK );
		$this->assertCount( 1, $almanac, 'The almanac block is a direct child of the query.' );
		$this->assertSame( 2, $almanac[0]['attrs']['headingLevel'] ?? null );
		$this->assertTrue( $almanac[0]['attrs']['showYearStrip'] ?? false );
		$this->assertCount( 0, $this->of_type( $blocks, 'core/post-template' ), 'The sheet replaces the post template.' );
	}

	public function test_the_pagination_uses_bare_core_blocks_with_no_label(): void {
		$blocks     = $this->blocks();
		$pagination = $this->of_type( $blocks, 'core/query-pagination' );

		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$found = $this->of_type( $blocks, $part );
			$this->assertCount( 1, $found, $part );
			$this->assertArrayNotHasKey( 'label', $found[0]['attrs'], $part . ' keeps core\'s own label (W3-G11).' );
		}
	}

	public function test_the_empty_state_is_the_generic_no_results_copy(): void {
		$blocks = $this->blocks();

		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-no-results' ) );
		$this->assertStringContainsString( 'Nothing here yet.', (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-weather.html' ) );
	}

	public function test_the_plugin_template_serves_the_weather_archive_on_a_theme_without_a_kind_template(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-weather', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-weather' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-weather', $template->id );
		$this->assertSame( 'Weather Archive', $template->title );
		$this->assertStringContainsString( 'wp:' . Almanac_Month::BLOCK, $template->content );
	}

	public function test_a_theme_kind_template_blocks_the_plugin_template(): void {
		switch_theme( 'pkiw-kind-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	public function test_a_site_editor_copy_wins(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-weather',
				'post_title'   => 'Weather archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-weather</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-weather', $template->content );
	}

	public function test_with_no_weather_posts_the_rendered_template_holds_the_no_results_copy_and_no_sheet(): void {
		$html = $this->render_archive();

		$this->assertStringContainsString( 'Nothing here yet.', $html );
		$this->assertStringNotContainsString( 'pkiw-almanac', $html );
		$this->assertStringNotContainsString( 'pkiw-year-strip', $html );
		$this->assertStringNotContainsString( 'Sample content', $html );
	}

	public function test_with_posts_the_sheet_renders_inside_the_inherited_query_and_the_pager_follows_it(): void {
		$this->seed();
		$html = $this->render_archive();
		$xp   = $this->xpath( $html );

		$this->assertSame( 1, $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " wp-block-query ")]//section[contains(@class,"pkiw-almanac")]' )->length );
		$this->assertStringNotContainsString( 'Nothing here yet.', $html );
		$this->assertSame( 'August 2026', trim( $xp->query( '//section[contains(@class,"pkiw-almanac")]//h2' )->item( 0 )->textContent ) );
		$this->assertSame( 2, $xp->query( '//section[contains(@class,"pkiw-almanac")]//li[contains(@class,"pkiw-almanac__entry")]' )->length );

		$pager = $xp->query( '//nav[contains(@class,"wp-block-query-pagination")]' );
		$this->assertSame( 1, $pager->length );
		$this->assertSame( 0, $xp->query( '//section[contains(@class,"pkiw-almanac")]//nav[contains(@class,"wp-block-query-pagination")]' )->length, 'The pager sits outside the sheet.' );
		$this->assertSame( 'Pagination', $pager->item( 0 )->getAttribute( 'aria-label' ) );
	}

	public function test_a_later_page_renders_its_own_month_and_a_page_past_the_end_is_not_found(): void {
		$this->seed();

		$xp = $this->xpath( $this->render_archive( 2 ) );
		$this->assertSame( 'June 2026', trim( $xp->query( '//section[contains(@class,"pkiw-almanac")]//h2' )->item( 0 )->textContent ) );

		$this->render_archive( 4 );
		$this->assertTrue( $GLOBALS['wp_query']->is_404() );
	}

	public function test_a_protected_post_alone_leaves_the_archive_empty(): void {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_date'     => '2026-08-03 09:15:00',
			]
		);
		wp_set_object_terms( $id, 'weather', Taxonomy::TAXONOMY );

		$html = $this->render_archive();

		$this->assertStringContainsString( 'Nothing here yet.', $html );
		$this->assertStringNotContainsString( 'pkiw-almanac', $html );
	}
}

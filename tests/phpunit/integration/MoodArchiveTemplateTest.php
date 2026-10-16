<?php
/**
 * The plugin's mood archive template (issue 248).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * The neutral mood archive: a title, the term description and one inheriting
 * Query Loop that holds the year grid, core's pager and a no-results block.
 * A theme's own taxonomy-kind template outranks it.
 *
 * @group integration
 */
final class MoodArchiveTemplateTest extends WP_UnitTestCase {

	private const SLUG = 'taxonomy-kind-mood';

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
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 10 );
		if ( ! term_exists( 'mood', 'kind' ) ) {
			wp_insert_term( 'Mood', 'kind', [ 'slug' => 'mood' ] );
		}
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		unset( $GLOBALS['wp_theme_directories'][ array_search( dirname( __DIR__ ) . '/fixtures/themes', $GLOBALS['wp_theme_directories'], true ) ] );
		search_theme_directories( true );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * Core's taxonomy hierarchy for the mood term, as block-template slugs' PHP names.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-mood.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * The file's blocks, flattened.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		return $this->flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/' . self::SLUG . '.html' ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$out[] = $block;
			$out   = array_merge( $out, $this->flatten( $block['innerBlocks'] ?? [] ) );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( $b ) => $name === $b['blockName'] ) );
	}

	/**
	 * A mood post.
	 */
	private function mood( string $date ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Happy","moodKey":"happy"} /-->',
			]
		);
		wp_set_object_terms( $id, 'mood', 'kind' );

		return $id;
	}

	/**
	 * Request a page of the mood archive and render the resolved template.
	 */
	private function render( int $page = 1 ): string {
		$url = get_term_link( 'mood', 'kind' );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
		$this->assertTrue( is_tax( 'kind', 'mood' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	public function test_the_file_parses_to_exactly_one_inheriting_query(): void {
		$blocks  = $this->blocks();
		$queries = $this->of_type( $blocks, 'core/query' );

		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-title' ) );
		$this->assertSame( 1, (int) ( $this->of_type( $blocks, 'core/query-title' )[0]['attrs']['level'] ?? 1 ), 'The query title is the h1.' );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/term-description' ) );
	}

	public function test_the_grid_sits_inside_the_query(): void {
		$queries = $this->of_type( $this->blocks(), 'core/query' );
		$inside  = $this->of_type( $this->flatten( $queries[0]['innerBlocks'] ), 'post-kinds-indieweb/mood-year-grid' );

		$this->assertCount( 1, $inside );
		$this->assertCount( 1, $this->of_type( $this->blocks(), 'post-kinds-indieweb/mood-year-grid' ), 'The grid appears once.' );
	}

	public function test_the_pager_uses_core_labels_and_an_arrow(): void {
		$blocks     = $this->blocks();
		$pagination = $this->of_type( $blocks, 'core/query-pagination' );

		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$found = $this->of_type( $blocks, $part );
			$this->assertCount( 1, $found, $part );
			$this->assertArrayNotHasKey( 'label', $found[0]['attrs'], "{$part} keeps core's label." );
		}
	}

	public function test_it_has_a_no_results_block_that_says_no_moods_yet(): void {
		$blocks = $this->blocks();
		$empty  = $this->of_type( $blocks, 'core/query-no-results' );

		$this->assertCount( 1, $empty );
		$this->assertStringContainsString( 'No moods yet.', (string) json_encode( $empty[0]['innerBlocks'] ) );
	}

	public function test_twentytwentyfive_serves_the_plugin_template_for_the_mood_term(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-mood', $template->id );
		$this->assertSame( 'plugin', $template->source );
	}

	public function test_a_theme_taxonomy_kind_template_outranks_it(): void {
		switch_theme( 'pkiw-kind-theme' );
		$this->assertSame( 'pkiw-kind-theme', get_stylesheet() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringNotContainsString( 'mood-year-grid', $template->content );
	}

	public function test_it_renders_the_grid_and_core_pager_when_there_are_moods(): void {
		$this->mood( '2026-03-01 10:00:00' );
		$this->mood( '2025-03-01 10:00:00' );
		$this->mood( '2024-03-01 10:00:00' );

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, '<h1' ), 'Exactly one h1.' );
		$this->assertStringContainsString( 'pkiw-mood-year', $html );
		$this->assertStringContainsString( 'pkiw-mood-legend', $html );
		$this->assertStringContainsString( 'wp-block-query-pagination-next-arrow', $html );
		$this->assertMatchesRegularExpression( '/wp-block-query-pagination[^"]*is-content-justification-center/', $html );
		$this->assertSame( 2, substr_count( $html, 'class="page-numbers"' ) + substr_count( $html, "class='page-numbers'" ), 'Pages 2 and 3 are links beside the current page.' );
		$this->assertStringNotContainsString( 'No moods yet.', $html );
	}

	public function test_with_no_moods_it_says_so_and_prints_no_grid_legend_or_pager(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'No moods yet.', $html );
		$this->assertStringNotContainsString( 'pkiw-mood-year', $html );
		$this->assertStringNotContainsString( 'pkiw-mood-legend', $html );
		$this->assertStringNotContainsString( 'page-numbers', $html );
		$this->assertStringNotContainsString( 'wp-block-query-pagination', $html );
	}

	public function test_a_page_past_the_last_year_is_a_404(): void {
		$this->mood( '2026-03-01 10:00:00' );

		$url = get_term_link( 'mood', 'kind' );
		$this->assertIsString( $url );
		$this->go_to( add_query_arg( 'paged', 2, $url ) );

		$this->assertTrue( $GLOBALS['wp_query']->is_404() );
	}
}

<?php
/**
 * Article archive template contract (issue 263).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class ArticleArchiveTemplateTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'twentytwentyfive' );
		update_option( 'timezone_string', 'America/New_York' );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$key = array_search( dirname( __DIR__ ) . '/fixtures/themes', $GLOBALS['wp_theme_directories'], true );
		if ( false !== $key ) {
			unset( $GLOBALS['wp_theme_directories'][ $key ] );
		}
		search_theme_directories( true );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	public function test_article_archive_resolves_to_the_plugin_template(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-article', $template->id );
	}

	public function test_article_template_has_one_archive_query_title_at_h1(): void {
		$blocks = $this->template_blocks();
		$titles = $this->of_type( $blocks, 'core/query-title' );

		$this->assertCount( 1, $titles );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], [ 'core/heading', 'core/post-title' ], true ) ) {
				$this->assertNotSame( 1, (int) ( $block['attrs']['level'] ?? 2 ) );
			}
		}
	}

	public function test_article_template_has_a_term_description(): void {
		$this->assertNotEmpty( $this->of_type( $this->template_blocks(), 'core/term-description' ) );
	}

	public function test_article_template_has_one_inherited_contents_query(): void {
		$queries = $this->of_type( $this->template_blocks(), 'core/query' );

		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertStringContainsString( 'pkiw-kind-archive--contents', $queries[0]['attrs']['className'] ?? '' );
	}

	public function test_article_template_post_template_uses_contents_style(): void {
		$templates = $this->of_type( $this->template_blocks(), 'core/post-template' );

		$this->assertCount( 1, $templates );
		$this->assertStringContainsString( 'is-style-pkiw-contents', $templates[0]['attrs']['className'] ?? '' );
	}

	public function test_archive_sections_marker_uses_default_lines_per_page(): void {
		$sections = $this->of_type( $this->template_blocks(), 'post-kinds-indieweb/archive-sections' );

		$this->assertCount( 1, $sections );
		$this->assertSame( 2, (int) ( $sections[0]['attrs']['headingLevel'] ?? 2 ) );
		$this->assertLessThanOrEqual( 0, (int) ( $sections[0]['attrs']['linesPerPage'] ?? 0 ) );
		$this->assertSame( '', (string) ( $sections[0]['attrs']['dateFormat'] ?? '' ) );
	}

	public function test_article_template_item_blocks_have_the_contents_contract(): void {
		$blocks = $this->template_blocks();

		$this->assertSame( 'pkiw-contents__picture', $this->of_type( $blocks, 'core/post-featured-image' )[0]['attrs']['className'] ?? '' );
		$this->assertSame( 'pkiw-contents__title', $this->of_type( $blocks, 'core/post-title' )[0]['attrs']['className'] ?? '' );
		$this->assertSame( 3, (int) ( $this->of_type( $blocks, 'core/post-title' )[0]['attrs']['level'] ?? 0 ) );
		$this->assertNotEmpty( $this->of_type( $blocks, 'post-kinds-indieweb/reading-time' ) );
		$this->assertSame( 'pkiw-contents__date', $this->of_type( $blocks, 'core/post-date' )[0]['attrs']['className'] ?? '' );
	}

	public function test_article_template_pagination_sits_after_the_post_template(): void {
		$blocks = $this->template_blocks();
		$names  = array_column( $blocks, 'blockName' );
		$pagination = $this->of_type( $blocks, 'core/query-pagination' );

		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		$this->assertGreaterThan( array_search( 'core/post-template', $names, true ), array_search( 'core/query-pagination', $names, true ) );
		$this->assertEmpty( $this->of_type( $blocks, 'core/query-pagination-previous' )[0]['attrs'] ?? [] );
		$this->assertEmpty( $this->of_type( $blocks, 'core/query-pagination-next' )[0]['attrs'] ?? [] );
	}

	public function test_article_template_has_a_no_results_message(): void {
		$this->assertNotEmpty( $this->of_type( $this->template_blocks(), 'core/query-no-results' ) );
		$this->assertStringContainsString( 'Nothing here yet.', $this->template_content() );
	}

	public function test_page_one_headings_are_august_then_july(): void {
		$this->seed_eighteen_articles();
		update_option( 'posts_per_page', 10 );

		$this->assertSame( [ 'August 2026', 'July 2026' ], $this->h2s( $this->render_archive_page( 1 ) ) );
	}

	public function test_page_two_headings_are_july_then_june(): void {
		$this->seed_eighteen_articles();
		update_option( 'posts_per_page', 10 );

		$this->assertSame( [ 'July 2026', 'June 2026' ], $this->h2s( $this->render_archive_page( 2 ) ) );
	}

	public function test_heading_ids_are_unique_across_the_page(): void {
		$this->seed_eighteen_articles();
		$ids = [];
		foreach ( $this->xpath( $this->render_archive_page( 1 ) )->query( '//h2[@id]' ) as $heading ) {
			$ids[] = $heading->getAttribute( 'id' );
		}

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_page_one_and_page_two_cover_all_eighteen_posts_without_repeats(): void {
		$seeded = $this->seed_eighteen_articles();
		update_option( 'posts_per_page', 10 );

		$seen = array_merge( $this->rendered_post_ids( 1 ), $this->rendered_post_ids( 2 ) );
		sort( $seeded );
		sort( $seen );

		$this->assertSame( $seeded, $seen );
	}

	public function test_the_second_archive_page_is_paged(): void {
		$this->seed_eighteen_articles();
		$this->render_archive_page( 2 );

		$this->assertTrue( is_paged() );
	}

	public function test_the_archive_h1_is_article(): void {
		$this->seed_eighteen_articles();
		$h1s = [];
		foreach ( $this->xpath( $this->render_archive_page( 1 ) )->query( '//h1' ) as $h1 ) {
			$h1s[] = trim( $h1->textContent );
		}

		$this->assertSame( [ 'Article' ], $h1s );
	}

	public function test_the_real_term_description_prints(): void {
		$this->seed_eighteen_articles();
		$term = get_term_by( 'slug', 'article', 'kind' );
		wp_update_term( $term->term_id, 'kind', [ 'description' => 'traditional long form content: a post with an explicit title and body' ] );

		$this->assertStringContainsString( 'traditional long form content: a post with an explicit title and body', $this->render_archive_page( 1 ) );
	}

	public function test_a_renamed_article_term_prints_the_new_singular_name(): void {
		$term = get_term_by( 'slug', 'article', 'kind' );
		wp_update_term( $term->term_id, 'kind', [ 'name' => 'Essay' ] );

		$this->assertStringContainsString( '<h1', $this->render_archive_page( 1 ) );
		$this->assertStringContainsString( 'Essay', $this->render_archive_page( 1 ) );
		$this->assertStringNotContainsString( 'Essays', $this->render_archive_page( 1 ) );
	}

	public function test_four_posts_per_page_makes_five_pages_and_page_two_stays_in_august(): void {
		$this->seed_eighteen_articles();
		update_option( 'posts_per_page', 4 );
		$this->render_archive_page( 1 );

		$this->assertSame( 5, (int) $GLOBALS['wp_query']->max_num_pages );
		$this->assertSame( 'August 2026', $this->h2s( $this->render_archive_page( 2 ) )[0] );
	}

	public function test_article_feed_is_newest_first_with_no_group_source_var(): void {
		$ids = $this->seed_eighteen_articles();
		update_option( 'posts_per_rss', 5 );
		$this->go_to( home_url( '/kind/article/feed/' ) );

		$feed_ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( array_slice( $ids, 0, 5 ), $feed_ids );
		$this->assertSame( '', (string) $GLOBALS['wp_query']->get( 'pkiw_group_source' ) );
	}

	public function test_a_theme_taxonomy_kind_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
	}

	private function template_content(): string {
		$file = PKIW_PATH . 'templates/taxonomy-kind-article.html';
		$this->assertFileExists( $file, 'The article archive template file must exist for issue 263.' );

		return (string) file_get_contents( $file );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function template_blocks(): array {
		return $this->flatten( parse_blocks( $this->template_content() ) );
	}

	/**
	 * @return int[]
	 */
	private function seed_eighteen_articles(): array {
		if ( ! term_exists( 'article', 'kind' ) ) {
			wp_insert_term( 'Article', 'kind', [ 'slug' => 'article' ] );
		}
		$dates = array_merge(
			[ '2026-08-31 23:30:00' ],
			array_map( static fn( int $day ): string => sprintf( '2026-08-%02d 10:00:00', $day ), range( 30, 26 ) ),
			array_map( static fn( int $day ): string => sprintf( '2026-07-%02d 10:00:00', $day ), range( 31, 26 ) ),
			array_map( static fn( int $day ): string => sprintf( '2026-06-%02d 10:00:00', $day ), range( 30, 25 ) )
		);
		$ids = [];
		foreach ( $dates as $index => $date ) {
			$id = self::factory()->post->create(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Article ' . ( $index + 1 ),
					'post_date'    => $date,
					'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				]
			);
			wp_set_object_terms( $id, 'article', 'kind' );
			$ids[] = $id;
		}

		return $ids;
	}

	private function render_archive_page( int $page ): string {
		$url = 1 === $page ? get_term_link( 'article', 'kind' ) : home_url( '/kind/article/page/' . $page . '/' );
		$this->go_to( $url );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * @return string[]
	 */
	private function h2s( string $html ): array {
		$out = [];
		foreach ( $this->xpath( $html )->query( '//h2' ) as $h2 ) {
			$out[] = trim( $h2->textContent );
		}

		return $out;
	}

	/**
	 * @return int[]
	 */
	private function rendered_post_ids( int $page ): array {
		$this->render_archive_page( $page );

		return array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}

	/**
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-article.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
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
			$out = array_merge( $out, $this->flatten( $block['innerBlocks'] ?? [] ) );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );

		return new DOMXPath( $dom );
	}
}

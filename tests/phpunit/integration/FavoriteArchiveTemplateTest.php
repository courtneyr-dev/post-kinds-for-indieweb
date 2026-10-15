<?php
/**
 * The plugin's favorite archive template (issue 259).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Taxonomy;

/**
 * Covers templates/taxonomy-kind-favorite.html: it serves /kind/favorite/
 * when the theme has no favorite or kind template, a theme's own
 * taxonomy-kind-favorite wins, and the template holds the blocks the
 * favorite archive is built from. Titles and hosts are invented.
 *
 * @group integration
 */
final class FavoriteArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Directory holding the throwaway theme, '' when none was made.
	 *
	 * @var string
	 */
	private string $theme_root = '';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		if ( '' !== $this->theme_root ) {
			$key = array_search( $this->theme_root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $key ) {
				unset( $GLOBALS['wp_theme_directories'][ $key ] );
			}
			search_theme_directories( true );
			wp_clean_themes_cache();
			$this->remove_tree( $this->theme_root );
			$this->theme_root = '';
		}
		parent::tear_down();
	}

	/**
	 * Core's taxonomy hierarchy for the favorite term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-favorite.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * The shipped template's content.
	 */
	private function template_content(): string {
		$file = PKIW_PATH . 'templates/taxonomy-kind-favorite.html';
		$this->assertFileExists( $file );

		return (string) file_get_contents( $file );
	}

	/**
	 * Parsed blocks of the shipped template, flattened.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function template_blocks(): array {
		return $this->flatten( parse_blocks( $this->template_content() ) );
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
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( $b ) => $name === $b['blockName'] ) );
	}

	/**
	 * A child theme of Twenty Twenty-Five with its own favorite archive.
	 */
	private function make_theme_with_a_favorite_template(): string {
		$this->theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-favorite-themes-' . wp_generate_password( 8, false );
		$theme_dir        = $this->theme_root . '/pkiw-favorite-theme';
		wp_mkdir_p( $theme_dir . '/templates' );
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: PKIW Favorite Theme (test)\nTemplate: twentytwentyfive\nVersion: 1.0.0\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme_dir . '/templates/taxonomy-kind-favorite.html', '<!-- wp:group {"tagName":"main","className":"theme-owned-favorite-archive"} --><main class="wp-block-group theme-owned-favorite-archive"><!-- wp:query-title {"type":"archive"} /--></main><!-- /wp:group -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		register_theme_directory( $this->theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();

		return 'pkiw-favorite-theme';
	}

	/**
	 * Remove a directory tree this test made.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * A published favorite with a card.
	 *
	 * @param string $title Post title.
	 * @param string $date  Post date.
	 */
	private function favorite( string $title, string $date ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/favorite-card ' . wp_json_encode( [ 'title' => $title, 'url' => 'https://notes.example.com/' . sanitize_title( $title ) ], JSON_UNESCAPED_SLASHES ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, 'favorite', Taxonomy::TAXONOMY );

		return $id;
	}

	// Serving.

	public function test_the_plugin_template_serves_the_favorite_archive_when_the_theme_has_none(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-favorite', $template->id );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-favorite', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-favorite' ] ] ), 'id' ) );
		$this->assertNotNull( get_block_template( 'post-kinds-for-indieweb//taxonomy-kind-favorite' ) );
	}

	public function test_a_theme_favorite_template_wins_over_the_plugin_s(): void {
		switch_theme( $this->make_theme_with_a_favorite_template() );
		$this->assertSame( 'pkiw-favorite-theme', get_stylesheet() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-favorite-theme//taxonomy-kind-favorite', $template->id );
		$this->assertStringContainsString( 'theme-owned-favorite-archive', $template->content );
	}

	public function test_a_site_editor_favorite_template_wins_over_the_plugin_s(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-favorite',
				'post_title'   => 'Favorite archive (customized)',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-favorite</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-favorite', $template->content );
	}

	// Contents.

	public function test_the_template_is_header_main_footer_with_one_title_term_description_and_inheriting_query(): void {
		$top = array_values( array_filter( parse_blocks( $this->template_content() ), static fn( $b ) => null !== $b['blockName'] ) );

		$this->assertSame( [ 'core/template-part', 'core/group', 'core/template-part' ], array_column( $top, 'blockName' ) );
		$this->assertSame( 'header', $top[0]['attrs']['slug'] ?? '' );
		$this->assertSame( 'footer', $top[2]['attrs']['slug'] ?? '' );
		$this->assertSame( 'main', $top[1]['attrs']['tagName'] ?? '' );

		$blocks = $this->template_blocks();
		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 'archive', $titles[0]['attrs']['type'] ?? '' );
		$this->assertFalse( $titles[0]['attrs']['showPrefix'] ?? true, 'The H1 reads "Favorite", not "Kind: Favorite".' );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ), 'The query title is the h1.' );
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], [ 'core/heading', 'core/post-title' ], true ) ) {
				$this->assertNotSame( 1, (int) ( $block['attrs']['level'] ?? 2 ), 'No second h1.' );
			}
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries, 'One Query Loop.' );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false, 'The Query Loop inherits the main query.' );
		$this->assertStringContainsString( 'pkiw-kind-archive', $queries[0]['attrs']['className'] ?? '' );
	}

	public function test_the_post_template_holds_a_12_line_sections_marker_above_a_level_3_stream_card(): void {
		$templates = $this->of_type( $this->template_blocks(), 'core/post-template' );
		$this->assertCount( 1, $templates );
		$this->assertDoesNotMatchRegularExpression( '/(^|\s)is-style-/', (string) ( $templates[0]['attrs']['className'] ?? '' ), 'The clipping treatment is the theme\'s block style, not the plugin\'s.' );

		$children = array_values( array_filter( $templates[0]['innerBlocks'], static fn( $b ) => null !== $b['blockName'] ) );

		$this->assertSame( [ 'post-kinds-indieweb/archive-sections', 'post-kinds-indieweb/stream-card' ], array_column( $children, 'blockName' ), 'The marker sits above the card so the editor preview prints each month heading above its first card.' );
		$this->assertSame( 12, $children[0]['attrs']['linesPerPage'] ?? null );
		$this->assertSame( 2, $children[0]['attrs']['headingLevel'] ?? null, 'H1, then H2 month labels.' );
		$this->assertArrayNotHasKey( 'dateFormat', $children[0]['attrs'], 'Month labels read "August 2026" from the source.' );
		$this->assertArrayNotHasKey( 'groupLabels', $children[0]['attrs'] );
		$this->assertSame( 3, $children[1]['attrs']['headingLevel'] ?? null, 'H3 item titles.' );
	}

	public function test_the_engine_sizes_the_archive_page_from_the_marker(): void {
		$plan = Grouped_Archive::main_query_plan( new WP_Query(), 'favorite', $this->template_content() );

		$this->assertIsArray( $plan );
		$this->assertSame( 12, $plan['per_page'] );
	}

	public function test_pagination_is_the_next_block_after_the_post_template_and_the_only_words_are_the_empty_state(): void {
		$query    = $this->of_type( $this->template_blocks(), 'core/query' )[0];
		$children = array_values( array_filter( $query['innerBlocks'], static fn( $b ) => null !== $b['blockName'] ) );
		$names    = array_column( $children, 'blockName' );
		$at       = array_search( 'core/post-template', $names, true );

		$this->assertIsInt( $at );
		$this->assertSame( 'core/query-pagination', $names[ $at + 1 ] ?? '', 'Nothing sits between the last band and the pager.' );
		$this->assertSame( [ 'core/post-template', 'core/query-pagination', 'core/query-no-results' ], $names );

		$pagination = $children[ $at + 1 ];
		$this->assertSame( 'arrow', $pagination['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination['attrs']['layout']['justifyContent'] ?? '' );
		$this->assertSame(
			[ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ],
			array_column( array_values( array_filter( $pagination['innerBlocks'], static fn( $b ) => null !== $b['blockName'] ) ), 'blockName' )
		);

		$paragraphs = array_map(
			static fn( $b ) => trim( wp_strip_all_tags( (string) $b['innerHTML'] ) ),
			$this->of_type( $this->template_blocks(), 'core/paragraph' )
		);
		$this->assertSame( [ 'Nothing here yet.' ], $paragraphs, 'No invented copy.' );
	}

	public function test_the_rendered_archive_has_one_h1_month_h2s_and_item_h3s(): void {
		update_option( 'posts_per_page', 10 );
		$this->favorite( 'August favorite', '2026-08-14 10:00:00' );
		$this->favorite( 'July favorite', '2026-07-02 10:00:00' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->go_to( (string) get_term_link( 'favorite', Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, 'favorite' ) );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();

		$this->assertSame( 1, substr_count( $html, '<h1' ), 'Exactly one h1.' );
		$this->assertMatchesRegularExpression( '#<h2[^>]*>\s*August 2026\s*</h2>#', $html );
		$this->assertMatchesRegularExpression( '#<h2[^>]*>\s*July 2026\s*</h2>#', $html );
		$this->assertSame( 2, substr_count( $html, '<h3' ), 'One h3 per item.' );
		$this->assertStringNotContainsString( 'Nothing here yet.', $html );
	}

	/**
	 * The Site Editor previews the marker once per loop post through the block
	 * renderer. It prints a month heading only for the post that opens the
	 * month, and a hidden placeholder for the others.
	 */
	public function test_the_site_editor_preview_prints_each_month_heading_for_the_post_that_opens_it(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$august_new = $this->favorite( 'Aug new', '2026-08-20 10:00:00' );
		$august_old = $this->favorite( 'Aug old', '2026-08-02 10:00:00' );
		$july       = $this->favorite( 'Jul only', '2026-07-30 10:00:00' );

		$this->go_to( (string) get_term_link( 'favorite', Taxonomy::TAXONOMY ) );

		$marker = $this->of_type( $this->template_blocks(), 'post-kinds-indieweb/archive-sections' )[0];
		$seen   = [];
		foreach ( [ $august_new, $august_old, $july ] as $id ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/archive-sections' );
			$request->set_param( 'context', 'edit' );
			$request->set_param( 'attributes', $marker['attrs'] );
			$request->set_param( 'post_id', $id );
			$request->set_param( 'pkiw_kind', 'favorite' );
			$response                  = rest_get_server()->dispatch( $request );
			$GLOBALS['wp_rest_server'] = null;
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$html = (string) ( $response->get_data()['rendered'] ?? '' );

			$seen[] = 1 === preg_match( '#<h2[^>]*>([^<]*)</h2>#', $html, $m ) ? 'H:' . trim( $m[1] ) : ( str_contains( $html, ' hidden>' ) ? '(hidden)' : 'other:' . $html );
		}

		$this->assertSame( [ 'H:August 2026', '(hidden)', 'H:July 2026' ], $seen );
	}
}

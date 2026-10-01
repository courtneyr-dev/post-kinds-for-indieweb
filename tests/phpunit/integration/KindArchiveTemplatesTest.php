<?php
/**
 * Kind archive block templates (issue 233).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies the plugin ships kind archive templates through the
 * get_block_templates path, that each template carries the shared layout
 * contract (one h1, term description, inherited Query Loop, centered arrow
 * pagination, no-results), and that a theme's own kind template or a Site
 * Editor customization keeps winning over the plugin's.
 *
 * @group integration
 */
final class KindArchiveTemplatesTest extends WP_UnitTestCase {

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
		switch_theme( $this->original_stylesheet );
		unset( $GLOBALS['wp_theme_directories'][ array_search( dirname( __DIR__ ) . '/fixtures/themes', $GLOBALS['wp_theme_directories'], true ) ] );
		search_theme_directories( true );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	/**
	 * Core's taxonomy hierarchy for a kind term, as block-template slugs' PHP names.
	 *
	 * @param string $kind Kind term slug.
	 * @return string[]
	 */
	private function hierarchy( string $kind ): array {
		return [ "taxonomy-kind-{$kind}.php", 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function shipped_templates(): array {
		return [
			'generic kind archive' => [ 'taxonomy-kind', 'is-style-pkiw-shelf' ],
			'eat menu'             => [ 'taxonomy-kind-eat', 'is-style-pkiw-menu' ],
			'drink menu'           => [ 'taxonomy-kind-drink', 'is-style-pkiw-menu' ],
		];
	}

	/**
	 * @dataProvider shipped_templates
	 */
	public function test_template_ships_through_get_block_templates( string $slug ): void {
		$templates = get_block_templates( [ 'slug__in' => [ $slug ] ] );
		$ids       = wp_list_pluck( $templates, 'id' );

		$this->assertContains( 'post-kinds-for-indieweb//' . $slug, $ids );
	}

	/**
	 * @dataProvider shipped_templates
	 */
	public function test_template_carries_the_layout_contract( string $slug, string $layout_class ): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/' . $slug . '.html' );
		$blocks  = $this->flatten( parse_blocks( $content ) );

		$titles = array_values( array_filter( $blocks, static fn( $b ) => 'core/query-title' === $b['blockName'] ) );
		$this->assertCount( 1, $titles, 'Exactly one query-title.' );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ), 'The query title is the h1.' );

		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], [ 'core/heading', 'core/post-title' ], true ) ) {
				$this->assertNotSame( 1, (int) ( $block['attrs']['level'] ?? 2 ), 'No second h1.' );
			}
		}

		$this->assertNotEmpty( $this->of_type( $blocks, 'core/term-description' ), 'Term description.' );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false, 'Query Loop inherits the main query.' );

		$templates = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $templates );
		$this->assertStringContainsString( $layout_class, $templates[0]['attrs']['className'] ?? '' );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertNotEmpty( $this->of_type( $blocks, $part ), $part );
		}

		$this->assertNotEmpty( $this->of_type( $blocks, 'core/query-no-results' ), 'No-results state.' );
	}

	public function test_generic_kind_archive_resolves_to_plugin_template(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy( 'listen' ), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind', $template->id );
	}

	public function test_eat_archive_resolves_to_plugin_menu_template(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy( 'eat' ), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-eat', $template->id );
	}

	public function test_rendered_archive_has_one_h1_and_centered_arrow_pagination(): void {
		$ids = self::factory()->post->create_many( 12, [ 'post_status' => 'publish' ] );
		foreach ( $ids as $id ) {
			wp_set_object_terms( $id, 'listen', 'kind' );
		}
		update_option( 'posts_per_page', 10 );

		$this->go_to( get_term_link( 'listen', 'kind' ) );
		$this->assertTrue( is_tax( 'kind', 'listen' ) );

		$html = $this->render_current_template( 'listen' );

		$this->assertSame( 1, substr_count( $html, '<h1' ), 'Exactly one h1 on the rendered archive.' );
		$this->assertMatchesRegularExpression( '/wp-block-query-pagination[^"]*is-content-justification-center/', $html );
		$this->assertStringContainsString( 'wp-block-query-pagination-next-arrow', $html );
		$this->assertStringContainsString( 'is-style-pkiw-shelf', $html );
	}

	public function test_theme_kind_template_wins_over_every_plugin_kind_template(): void {
		switch_theme( 'pkiw-kind-theme' );
		$this->assertSame( 'pkiw-kind-theme', get_stylesheet() );

		foreach ( [ 'listen', 'eat', 'drink' ] as $kind ) {
			$template = resolve_block_template( 'taxonomy', $this->hierarchy( $kind ), '' );

			$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id, "Theme template must win for {$kind}." );
			$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
		}
	}

	public function test_site_editor_customization_wins_over_plugin_template(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-eat',
				'post_title'   => 'Eat archive (customized)',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-eat</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy( 'eat' ), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-eat', $template->content );
	}

	public function test_file_template_filter_never_answers_for_a_theme_id(): void {
		switch_theme( 'pkiw-kind-theme' );

		$template = get_block_file_template( 'pkiw-kind-theme//taxonomy-kind' );

		$this->assertNotNull( $template );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	/**
	 * Render the block template the current request resolves to.
	 *
	 * @param string $kind Kind term slug.
	 */
	private function render_current_template( string $kind ): string {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy( $kind ), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
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
}

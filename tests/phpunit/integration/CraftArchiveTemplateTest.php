<?php
/**
 * The plugin's craft archive template (issue 249).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;
use PKIW\Taxonomy;

/**
 * The plugin serves templates/taxonomy-kind-craft.html for /kind/craft/ when
 * the theme has no kind template of its own. Its loop holds the
 * archive-sections marker beside the Stream card, twelve posts a page, with
 * the empty group labelled "Craft". A theme's taxonomy-kind-craft or
 * taxonomy-kind, or a Site Editor copy, wins.
 *
 * @group integration
 */
final class CraftArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * A block theme written for this class, holding its own taxonomy-kind-craft.
	 *
	 * @var string
	 */
	private static string $theme_root = '';

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-craft-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-craft-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Craft Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-craft.html', '<!-- wp:paragraph --><p>theme-owned-craft-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-craft.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-craft-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-craft-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-craft-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		register_theme_directory( dirname( __DIR__ ) . '/fixtures/themes' );
		register_theme_directory( self::$theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		update_option( 'posts_per_page', 20 );
		// Pretty permalinks, as the site runs: /kind/craft/page/2/. The kind
		// taxonomy registered before the structure was set, so add its permastruct.
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		foreach ( [ dirname( __DIR__ ) . '/fixtures/themes', self::$theme_root ] as $root ) {
			$at = array_search( $root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $at ) {
				unset( $GLOBALS['wp_theme_directories'][ $at ] );
			}
		}
		search_theme_directories( true );
		wp_clean_themes_cache();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Core's taxonomy hierarchy for the craft term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-craft.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened parsed blocks of the shipped template file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-craft.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-craft.html' ) ) );
	}

	/**
	 * Blocks of one name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $b ): bool => $name === $b['blockName'] ) );
	}

	/**
	 * Render the archive the template resolves to for a URL.
	 *
	 * @param string $url URL.
	 */
	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * A published craft post.
	 *
	 * @param string $title Post title.
	 * @param string $type  Stored craft type.
	 * @param string $date  Post date.
	 */
	private function craft( string $title, string $type, string $date = '2026-08-20 10:00:00' ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => $date,
			]
		);
		wp_set_object_terms( $id, 'craft', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_craft_type', $type );

		return $id;
	}

	public function test_the_plugin_template_serves_the_craft_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-craft', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-craft' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-craft', $template->id );
		$this->assertSame( 'Craft Archive', $template->title );
		$this->assertSame( 'plugin', $template->source );
	}

	public function test_the_template_has_one_h1_the_term_description_and_one_inherited_query(): void {
		$blocks = $this->blocks();

		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles, 'One h1.' );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries, 'One inherited Query.' );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false, 'The loop inherits the main query.' );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/post-template' ) );
	}

	public function test_the_loop_holds_the_marker_beside_the_stream_card_with_the_documented_attributes(): void {
		$loop  = $this->of_type( $this->blocks(), 'core/post-template' )[0];
		$inner = array_column( $loop['innerBlocks'], 'blockName' );

		$this->assertSame( [ Kind_Archive_Layouts::ARCHIVE_SECTIONS, 'post-kinds-indieweb/stream-card' ], $inner, 'The marker beside the Stream card, nothing else.' );

		$marker = $loop['innerBlocks'][0]['attrs'];
		$this->assertSame( 12, $marker['linesPerPage'] ?? null, 'Twelve posts a page.' );
		$this->assertSame( 'Craft', $marker['emptyLabel'] ?? null, 'The posts with no type, and protected ones, read "Craft".' );
		$this->assertSame( 2, $marker['headingLevel'] ?? null, 'Section headings are h2.' );
		$this->assertSame( [ 'emptyLabel', 'headingLevel', 'linesPerPage' ], $this->sorted_keys( $marker ), 'Nothing else is set; group labels come from the stored values.' );

		$card = $loop['innerBlocks'][1]['attrs'];
		$this->assertSame( 3, $card['headingLevel'] ?? null, 'Item titles are h3 under the section h2.' );
	}

	/**
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string[]
	 */
	private function sorted_keys( array $attrs ): array {
		$keys = array_keys( $attrs );
		sort( $keys );

		return $keys;
	}

	public function test_the_pager_uses_core_labels_and_the_empty_state_is_one_paragraph(): void {
		$blocks = $this->blocks();

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$found = $this->of_type( $blocks, $part );
			$this->assertCount( 1, $found, $part );
			$this->assertArrayNotHasKey( 'label', $found[0]['attrs'], $part . ' carries no label attribute: core\'s translated labels print.' );
		}

		$none = $this->of_type( $blocks, 'core/query-no-results' );
		$this->assertCount( 1, $none );
		$this->assertSame( [ 'core/paragraph' ], array_column( $none[0]['innerBlocks'], 'blockName' ), 'One paragraph.' );
	}

	public function test_the_template_has_no_read_order_links_and_no_craft_names(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-craft.html' );

		$this->assertStringNotContainsString( 'read-order', $source );
		$this->assertDoesNotMatchRegularExpression( '/knitting|woodworking|bookbinding|pottery|embroider|weaving/i', $source );
	}

	public function test_the_template_carries_the_shared_kind_archive_layout(): void {
		$blocks = $this->blocks();

		$this->assertSame(
			[ 'header', 'footer' ],
			array_column( array_map( static fn( array $b ): array => $b['attrs'], $this->of_type( $blocks, 'core/template-part' ) ), 'slug' )
		);
		$this->assertStringContainsString( 'pkiw-kind-archive', (string) ( $this->of_type( $blocks, 'core/query' )[0]['attrs']['className'] ?? '' ) );
	}

	public function test_the_rendered_archive_has_one_h1_a_section_heading_and_one_card_per_post(): void {
		$this->craft( 'Plant Stand', 'woodworking' );
		$this->craft( 'Cutting Board', 'woodworking', '2026-08-10 10:00:00' );
		$this->craft( 'Wool Scarf', 'Knitting', '2026-08-12 10:00:00' );

		$html = $this->serve( (string) get_term_link( 'craft', Taxonomy::TAXONOMY ) );

		$this->assertSame( 1, substr_count( $html, '<h1' ), 'One h1.' );
		$this->assertStringContainsString( '<h2 id="pkiw-group-knitting" class="pkiw-group__heading">Knitting</h2>', $html );
		$this->assertStringContainsString( '<h2 id="pkiw-group-woodworking" class="pkiw-group__heading">woodworking</h2>', $html );
		$this->assertSame( 3, preg_match_all( '#<h3[^>]*class="[^"]*pk-title#', $html ), 'One h3 title per post.' );
		$this->assertStringNotContainsString( 'Nothing here yet', $html );
	}

	public function test_an_empty_archive_prints_the_no_results_paragraph_and_no_section(): void {
		$html = $this->serve( (string) get_term_link( 'craft', Taxonomy::TAXONOMY ) );

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( 'Nothing here yet', $html );
		$this->assertStringNotContainsString( 'pkiw-group__heading', $html );
		$this->assertStringNotContainsString( 'data-pkiw-sections', $html );
	}

	public function test_the_pager_prints_core_labels_when_the_archive_has_a_second_page(): void {
		for ( $i = 1; $i <= 13; $i++ ) {
			$this->craft( 'Project ' . $i, 'Quilting', sprintf( '2026-08-%02d 10:00:00', $i ) );
		}

		$html = $this->serve( (string) get_term_link( 'craft', Taxonomy::TAXONOMY ) );

		$this->assertStringContainsString( 'wp-block-query-pagination-next', $html );
		$this->assertMatchesRegularExpression( '#wp-block-query-pagination-next[^>]*>\s*(?:<span[^>]*>)?\s*Next Page#', $html, 'Core\'s own "Next Page" label.' );
		$this->assertStringContainsString( 'page/2/', $html );
	}

	public function test_a_theme_craft_template_wins(): void {
		switch_theme( 'pkiw-craft-theme' );
		$this->assertSame( 'pkiw-craft-theme', get_stylesheet() );
		$this->assertTrue( wp_is_block_theme() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-craft-theme//taxonomy-kind-craft', $template->id );
		$this->assertStringContainsString( 'theme-owned-craft-archive', $template->content );
	}

	public function test_a_theme_kind_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );
		$this->assertSame( 'pkiw-kind-theme', get_stylesheet() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	public function test_a_site_editor_copy_wins(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-craft',
				'post_title'   => 'Craft archive (customized)',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-craft</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-craft', $template->content );
	}
}

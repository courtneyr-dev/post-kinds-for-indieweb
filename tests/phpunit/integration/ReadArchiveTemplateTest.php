<?php
/**
 * The plugin's read archive template (issue 234).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;
use PKIW\Read_Archive;
use PKIW\Taxonomy;

/**
 * The plugin serves templates/taxonomy-kind-read.html for /kind/read/ when
 * the theme has no kind template of its own. It holds the shared kind
 * archive layout plus the Read order links, and its loop holds the
 * archive-sections marker beside the Stream card. A theme's
 * taxonomy-kind-read or taxonomy-kind, or a Site Editor copy, wins.
 *
 * @group integration
 */
final class ReadArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * A block theme written for this class, holding its own taxonomy-kind-read.
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
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-read-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-read-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Read Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-read.html', '<!-- wp:paragraph --><p>theme-owned-read-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-read.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-read-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-read-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-read-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
		// Pretty permalinks, as the site runs: /kind/read/page/2/. The kind
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
	 * Core's taxonomy hierarchy for the read term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-read.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened parsed blocks of the shipped template file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-read.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-read.html' ) ) );
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

	public function test_the_plugin_template_serves_the_read_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-read', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-read' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-read', $template->id );
		$this->assertSame( 'Read Archive', $template->title );
	}

	public function test_the_template_carries_the_kind_archive_layout_and_the_read_order(): void {
		$blocks = $this->blocks();

		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles, 'One h1.' );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/term-description' ) );
		$this->assertCount( 1, $this->of_type( $blocks, Read_Archive::ORDER_BLOCK ), 'The Read order links.' );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false, 'The loop inherits the main query.' );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertStringContainsString( 'is-style-pkiw-shelf', $loops[0]['attrs']['className'] ?? '' );
		$inner = array_column( $loops[0]['innerBlocks'], 'blockName' );
		$this->assertSame( [ Kind_Archive_Layouts::ARCHIVE_SECTIONS, 'post-kinds-indieweb/stream-card' ], $inner, 'The marker beside the Stream card; no shelf-sections block.' );

		$marker = $loops[0]['innerBlocks'][0]['attrs'];
		$this->assertSame( 12, $marker['linesPerPage'] ?? null, 'Twelve reads a page.' );
		$this->assertSame( '', $marker['emptyLabel'] ?? '', 'The empty shelf takes "Other".' );
		$this->assertSame( 2, $loops[0]['innerBlocks'][1]['attrs']['headingLevel'] ?? 2, 'Stream card titles are h2; a theme raises them to h3 under a shelf heading.' );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertNotEmpty( $this->of_type( $blocks, $part ), $part );
		}
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/query-no-results' ) );
	}

	public function test_the_rendered_archive_has_one_h1_the_read_order_and_shelves(): void {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Salt and Paper',
			]
		);
		wp_set_object_terms( $id, 'read', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_read_status', 'reading' );
		$this->go_to( (string) get_term_link( 'read', Taxonomy::TAXONOMY ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( 'aria-label="Read order"', $html );
		$this->assertStringContainsString( '<h2 id="pkiw-group-reading" class="pkiw-group__heading">Currently Reading</h2>', $html );
	}

	public function test_a_theme_read_template_wins(): void {
		switch_theme( 'pkiw-read-theme' );
		$this->assertSame( 'pkiw-read-theme', get_stylesheet() );
		$this->assertTrue( wp_is_block_theme() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-read-theme//taxonomy-kind-read', $template->id );
		$this->assertStringContainsString( 'theme-owned-read-archive', $template->content );
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
				'post_name'    => 'taxonomy-kind-read',
				'post_title'   => 'Read archive (customized)',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-read</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-read', $template->content );
	}
}

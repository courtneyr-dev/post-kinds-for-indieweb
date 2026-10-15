<?php
/**
 * The plugin's acquisition archive template for issue 239.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;
use PKIW\Taxonomy;

/**
 * The plugin serves templates/taxonomy-kind-acquisition.html for
 * /kind/acquisition/ when the theme and Site Editor have no copy.
 *
 * @group integration
 */
final class AcquisitionArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * A block theme written for this class.
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
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-acquisition-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-acquisition-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Acquisition Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-acquisition.html', '<!-- wp:paragraph --><p>theme-owned-acquisition-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-acquisition.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-acquisition-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-acquisition-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-acquisition-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
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
	 * Core's taxonomy hierarchy for the acquisition term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-acquisition.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened parsed blocks of the shipped template file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-acquisition.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-acquisition.html' ) ) );
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

	public function test_the_plugin_template_serves_the_acquisition_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-acquisition', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-acquisition' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-acquisition', $template->id );
		$this->assertSame( 'Acquisition Archive', $template->title );
	}

	public function test_the_template_carries_the_acquisition_archive_layout(): void {
		$blocks = $this->blocks();

		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertSame( 'pkiw-kind-acquisition', $queries[0]['attrs']['namespace'] ?? '' );
		$this->assertSame( 'pkiw-kind-archive', $queries[0]['attrs']['className'] ?? '' );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertArrayNotHasKey( 'className', $loops[0]['attrs'] ?? [] );
		$this->assertSame( [ Kind_Archive_Layouts::ARCHIVE_SECTIONS, 'post-kinds-indieweb/stream-card' ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 12, $loops[0]['innerBlocks'][0]['attrs']['linesPerPage'] ?? null );
		$this->assertSame( 2, $loops[0]['innerBlocks'][0]['attrs']['headingLevel'] ?? null );
		$this->assertSame( 3, $loops[0]['innerBlocks'][1]['attrs']['headingLevel'] ?? null );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/query-pagination-previous' ) );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/query-pagination-numbers' ) );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/query-pagination-next' ) );

		$empty = $this->of_type( $blocks, 'core/query-no-results' );
		$this->assertCount( 1, $empty );
		$paragraphs = $this->of_type( $empty[0]['innerBlocks'], 'core/paragraph' );
		$this->assertCount( 1, $paragraphs );
		$this->assertSame( 'No acquisitions yet.', trim( wp_strip_all_tags( $paragraphs[0]['innerHTML'] ) ) );

		$parts = $this->of_type( $blocks, 'core/template-part' );
		$this->assertSame( [ 'header', 'footer' ], array_column( array_column( $parts, 'attrs' ), 'slug' ) );
	}

	public function test_the_rendered_archive_has_one_h1(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Field Recorder',
				'post_content' => '<!-- wp:post-kinds-indieweb/acquisition-card {"title":"Field Recorder"} /-->',
			]
		);
		wp_set_object_terms( $id, 'acquisition', Taxonomy::TAXONOMY );
		$this->go_to( (string) get_term_link( 'acquisition', Taxonomy::TAXONOMY ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
	}

	public function test_a_theme_acquisition_template_wins(): void {
		switch_theme( 'pkiw-acquisition-theme' );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-acquisition-theme//taxonomy-kind-acquisition', $template->id );
		$this->assertStringContainsString( 'theme-owned-acquisition-archive', $template->content );
	}

	public function test_a_theme_kind_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	public function test_a_site_editor_copy_wins(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-acquisition',
				'post_title'   => 'Acquisition archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-acquisition</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-acquisition', $template->content );
	}
}

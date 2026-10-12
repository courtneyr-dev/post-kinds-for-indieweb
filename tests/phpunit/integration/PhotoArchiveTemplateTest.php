<?php
/**
 * Photo archive block template tests for issue 241.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;
use PKIW\Taxonomy;

/**
 * The photo archive ships as a block template unless the theme or Site
 * Editor provides one.
 *
 * @group integration
 */
final class PhotoArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Temporary theme root.
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
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-photo-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-photo-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Photo Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-photo.html', '<!-- wp:paragraph --><p>theme-owned-photo-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-photo.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-photo-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-photo-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-photo-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
	 * Core's taxonomy hierarchy for the photo term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-photo.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened parsed blocks of the shipped template file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-photo.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-photo.html' ) ) );
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
	 * A published photo.
	 *
	 * @param string $title Title.
	 * @return int Post ID.
	 */
	private function photo( string $title = 'Boardwalk Light' ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => '2026-08-20 10:00:00',
			]
		);
		wp_set_object_terms( $id, 'photo', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * Render the current photo archive template.
	 */
	private function render_archive(): string {
		$this->go_to( (string) get_term_link( 'photo', Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Parse HTML into XPath.
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

	public function test_the_plugin_template_serves_the_photo_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-photo', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-photo' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-photo', $template->id );
		$this->assertSame( 'Photo Archive', $template->title );
	}

	public function test_the_template_carries_the_photo_archive_layout(): void {
		$blocks = $this->blocks();

		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 'archive', $titles[0]['attrs']['type'] ?? '' );
		$this->assertFalse( $titles[0]['attrs']['showPrefix'] ?? true );
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertSame( [ Kind_Archive_Layouts::ARCHIVE_SECTIONS, 'post-kinds-indieweb/stream-card' ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 12, $loops[0]['innerBlocks'][0]['attrs']['linesPerPage'] ?? null );
		$this->assertSame( 2, $loops[0]['innerBlocks'][0]['attrs']['headingLevel'] ?? null );
		$this->assertSame( 3, $loops[0]['innerBlocks'][1]['attrs']['headingLevel'] ?? null );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertNotEmpty( $this->of_type( $blocks, $part ), $part );
		}
		$this->assertNotEmpty( $this->of_type( $blocks, 'core/query-no-results' ) );
	}

	public function test_a_theme_photo_template_wins(): void {
		switch_theme( 'pkiw-photo-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-photo-theme//taxonomy-kind-photo', $template->id );
		$this->assertStringContainsString( 'theme-owned-photo-archive', $template->content );
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
				'post_name'    => 'taxonomy-kind-photo',
				'post_title'   => 'Photo archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-photo</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-photo', $template->content );
	}

	public function test_a_rendered_archive_has_one_h1_with_the_term_name(): void {
		$this->photo();

		$html  = $this->render_archive();
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertSame( 'Photo', trim( $xpath->query( '//h1' )->item( 0 )->textContent ) );
	}

	public function test_a_renamed_term_prints_the_new_name_with_no_plural(): void {
		$this->photo();
		$term = get_term_by( 'slug', 'photo', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		wp_update_term( $term->term_id, Taxonomy::TAXONOMY, [ 'name' => 'Snaps' ] );

		$html = $this->render_archive();

		$this->assertSame( 'Snaps', trim( $this->xpath( $html )->query( '//h1' )->item( 0 )->textContent ) );
	}

	public function test_an_empty_archive_renders_the_no_results_message(): void {
		$html = $this->render_archive();

		$this->assertStringContainsString( 'No photos yet.', $html );
		$this->assertStringNotContainsString( 'data-pkiw-sections', $html );
	}
}

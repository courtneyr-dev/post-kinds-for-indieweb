<?php
/**
 * The plugin's chicken archive template (issue 250).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Chicken_Archive;
use PKIW\Taxonomy;

/**
 * The chicken archive template belongs to the plugin unless a theme or user copy wins.
 *
 * @group integration
 */
final class ChickenArchiveTemplateTest extends WP_UnitTestCase {

	private static string $theme_root = '';

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-chicken-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-chicken-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Chicken Theme\n*/\n" );
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' );
		file_put_contents( $theme . '/templates/taxonomy-kind-chicken.html', '<!-- wp:paragraph --><p>theme-owned-chicken-archive</p><!-- /wp:paragraph -->' );
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-chicken.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-chicken-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-chicken-theme/templates' );
		rmdir( self::$theme_root . '/pkiw-chicken-theme' );
		rmdir( self::$theme_root );
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
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
		update_option( 'timezone_string', $this->original_timezone );
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

	public function test_the_plugin_template_serves_the_chicken_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-chicken', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-chicken' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-chicken', $template->id );
		$this->assertSame( 'Chicken Archive', $template->title );
	}

	public function test_a_theme_chicken_template_wins(): void {
		switch_theme( 'pkiw-chicken-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-chicken-theme//taxonomy-kind-chicken', $template->id );
		$this->assertStringContainsString( 'theme-owned-chicken-archive', $template->content );
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
				'post_name'    => 'taxonomy-kind-chicken',
				'post_title'   => 'Chicken archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-chicken</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-chicken', $template->content );
	}

	public function test_the_template_structure_matches_the_chicken_archive_contract(): void {
		$blocks = $this->blocks();

		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-title' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertSame( 'pkiw-kind-chicken', $queries[0]['attrs']['namespace'] ?? '' );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertSame( [ Chicken_Archive::ENTRY ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 24, $loops[0]['innerBlocks'][0]['attrs']['linesPerPage'] ?? null );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertCount( 1, $this->of_type( $blocks, $part ), $part );
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-no-results' ) );
		$this->assertStringContainsString( 'Nothing here yet.', file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-chicken.html' ) );
	}

	/**
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-chicken.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-chicken.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-chicken.html' ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}
}

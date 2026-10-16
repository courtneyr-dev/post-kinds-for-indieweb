<?php
/**
 * The plugin's note archive template and its stylesheet (issue 265).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Note_Archive;
use PKIW\Taxonomy;

/**
 * The note template belongs to the plugin unless a theme or user copy wins.
 * It is neutral: core and plugin blocks only, and a stylesheet that paints
 * nothing outside the board style.
 *
 * @group integration
 */
final class NoteArchiveTemplateTest extends WP_UnitTestCase {

	private const SCOPE = '.wp-block-post-template.is-style-pkiw-note-board';

	private static string $theme_root = '';

	private string $original_stylesheet = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-note-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-note-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Note Theme\n*/\n" );
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' );
		file_put_contents( $theme . '/templates/taxonomy-kind-note.html', '<!-- wp:paragraph --><p>theme-owned-note-archive</p><!-- /wp:paragraph -->' );
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-note.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-note-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-note-theme/templates' );
		rmdir( self::$theme_root . '/pkiw-note-theme' );
		rmdir( self::$theme_root );
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

	public function test_the_plugin_template_serves_the_note_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-note', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-note' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-note', $template->id );
		$this->assertSame( 'Note Archive', $template->title );
	}

	public function test_a_theme_note_template_wins(): void {
		switch_theme( 'pkiw-note-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-note-theme//taxonomy-kind-note', $template->id );
		$this->assertStringContainsString( 'theme-owned-note-archive', $template->content );
	}

	public function test_a_theme_kind_template_blocks_the_plugin_note_template(): void {
		$plugin = \PKIW\Plugin::get_instance();
		$theme  = new WP_Block_Template();

		$theme->id     = 'pkiw-kind-theme//taxonomy-kind';
		$theme->slug   = 'taxonomy-kind';
		$theme->theme  = 'pkiw-kind-theme';
		$theme->type   = 'wp_template';
		$theme->source = 'theme';

		$with    = $plugin->add_plugin_templates( [ $theme ], [ 'slug__in' => [ 'taxonomy-kind-note' ] ], 'wp_template' );
		$without = $plugin->add_plugin_templates( [], [ 'slug__in' => [ 'taxonomy-kind-note' ] ], 'wp_template' );

		$this->assertSame( [ 'pkiw-kind-theme//taxonomy-kind' ], wp_list_pluck( $with, 'id' ) );
		$this->assertSame( [ 'post-kinds-for-indieweb//taxonomy-kind-note' ], wp_list_pluck( $without, 'id' ) );
	}

	public function test_a_site_editor_copy_wins(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-note',
				'post_title'   => 'Note archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-note</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-note', $template->content );
	}

	public function test_the_file_parses_with_only_core_and_post_kinds_blocks_in_the_archive_order(): void {
		$blocks = $this->blocks();

		foreach ( $blocks as $block ) {
			$this->assertMatchesRegularExpression( '#^(core|post-kinds-indieweb)/#', (string) $block['blockName'] );
		}

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );

		$title = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $title );
		$this->assertSame( 'archive', $title[0]['attrs']['type'] ?? '' );
		$this->assertFalse( $title[0]['attrs']['showPrefix'] ?? true );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertSame( 'is-style-pkiw-note-board', $loops[0]['attrs']['className'] ?? '' );
		$this->assertSame( [ 'post-kinds-indieweb/archive-sections', Note_Archive::BLOCK ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 12, $loops[0]['innerBlocks'][0]['attrs']['linesPerPage'] ?? null );
		$this->assertSame( 2, $loops[0]['innerBlocks'][0]['attrs']['headingLevel'] ?? null );
		$this->assertSame( 3, $loops[0]['innerBlocks'][1]['attrs']['headingLevel'] ?? null );

		$siblings = array_column( $queries[0]['innerBlocks'], 'blockName' );
		$this->assertSame( [ 'core/post-template', 'core/query-pagination', 'core/query-no-results' ], array_values( array_filter( $siblings ) ), 'The pager is the next sibling of the loop.' );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertCount( 1, $this->of_type( $blocks, $part ), $part );
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-no-results' ) );
		$this->assertSame( 'header', $this->of_type( $blocks, 'core/template-part' )[0]['attrs']['slug'] ?? '' );
		$this->assertSame( 'footer', $this->of_type( $blocks, 'core/template-part' )[1]['attrs']['slug'] ?? '' );
	}

	public function test_the_template_and_stylesheet_hold_no_hex_color_no_theme_class_and_no_theme_font(): void {
		$template = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-note.html' );
		$css      = (string) file_get_contents( PKIW_PATH . 'styles/note-entry.css' );

		foreach ( [ $template, $css ] as $source ) {
			$this->assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $source );
			$this->assertStringNotContainsString( 'cr-', $source );
			$this->assertStringNotContainsString( 'Rock Salt', $source );
			$this->assertStringNotContainsString( 'Roboto Slab', $source );
		}
		$this->assertDoesNotMatchRegularExpression( '/\b(rgb|rgba|hsl|hsla|oklch|color-mix)\(/', $css );
	}

	public function test_every_selector_in_the_stylesheet_is_scoped_to_the_board_style(): void {
		$selectors = $this->selectors( (string) file_get_contents( PKIW_PATH . 'styles/note-entry.css' ) );

		$this->assertNotEmpty( $selectors );
		foreach ( $selectors as $selector ) {
			$this->assertStringStartsWith( self::SCOPE, $selector, $selector );
		}
		$this->assertContains( self::SCOPE . ' .pkiw-group__items', $selectors );
	}

	public function test_the_stylesheet_has_the_three_two_and_one_column_grid_and_the_overlay_link(): void {
		$css = (string) file_get_contents( PKIW_PATH . 'styles/note-entry.css' );

		$this->assertMatchesRegularExpression( '/min-width:\s*64rem/', $css );
		$this->assertMatchesRegularExpression( '/min-width:\s*48rem/', $css );
		$this->assertMatchesRegularExpression( '/repeat\(3,/', $css );
		$this->assertMatchesRegularExpression( '/repeat\(2,/', $css );
		$this->assertStringContainsString( 'position: relative', $css );
		$this->assertMatchesRegularExpression( '/__title a::after\s*\{[^}]*inset:\s*0/s', $css );
		$this->assertMatchesRegularExpression( '/--dup-title[^{]*__name[^{]*\{[^}]*clip/s', $css );
		$this->assertMatchesRegularExpression( '/line-clamp/', $css );
		$this->assertStringContainsString( 'currentColor', $css );
		$this->assertStringContainsString( '--wp--preset--spacing--', $css );
		$this->assertDoesNotMatchRegularExpression( '/display:\s*none/', $css, 'The duplicate title is clipped, never removed from the accessibility tree.' );
	}

	/**
	 * Selectors of a stylesheet, comma lists split, at-rule blocks entered.
	 *
	 * @return string[]
	 */
	private function selectors( string $css ): array {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$out = [];
		$buf = '';
		$len = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = $css[ $i ];
			if ( '{' === $char ) {
				$head = trim( $buf );
				$buf  = '';
				if ( '' !== $head && '@' !== $head[0] ) {
					foreach ( explode( ',', $head ) as $one ) {
						$out[] = trim( $one );
					}
					// Skip the declaration block.
					$depth = 1;
					while ( $depth > 0 && ++$i < $len ) {
						if ( '{' === $css[ $i ] ) {
							++$depth;
						} elseif ( '}' === $css[ $i ] ) {
							--$depth;
						}
					}
				}
				continue;
			}
			if ( '}' === $char ) {
				$buf = '';
				continue;
			}
			if ( ';' === $char ) {
				$buf = '';
				continue;
			}
			$buf .= $char;
		}

		return $out;
	}

	/**
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-note.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-note.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-note.html' ) ) );
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

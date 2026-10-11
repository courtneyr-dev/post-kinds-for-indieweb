<?php
/**
 * The plugin's review archive template (issue 252).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Review_Archive;
use PKIW\Taxonomy;

/**
 * The review archive template belongs to the plugin unless a theme or user copy wins.
 *
 * @group integration
 */
final class ReviewArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Temporary theme root.
	 *
	 * @var string
	 */
	private static string $theme_root = '';

	/**
	 * Original stylesheet.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-review-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-review-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Review Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-review.html', '<!-- wp:paragraph --><p>theme-owned-review-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-review.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-review-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-review-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-review-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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

	public function test_the_plugin_template_serves_the_review_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-review', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-review' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-review', $template->id );
		$this->assertSame( 'Review Archive', $template->title );
	}

	public function test_the_template_structure_matches_the_review_archive_contract(): void {
		$blocks = $this->blocks();
		$names  = array_column( $blocks, 'blockName' );

		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );
		$this->assertCount( 1, $this->of_type( $blocks, Review_Archive::FOUR_STARS_BLOCK ) );
		$this->assertLessThan( array_search( 'core/query', $names, true ), array_search( Review_Archive::FOUR_STARS_BLOCK, $names, true ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertSame( 'pkiw-review-list', $loops[0]['attrs']['className'] ?? '' );
		$this->assertSame( [ Review_Archive::ENTRY_BLOCK ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 9, $loops[0]['innerBlocks'][0]['attrs']['linesPerPage'] ?? null );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertCount( 1, $this->of_type( $blocks, $part ), $part );
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-no-results' ) );
	}

	public function test_the_rendered_archive_has_h1_category_section_and_four_stars(): void {
		$category = self::factory()->category->create_and_get( [ 'name' => 'Alpha Books' ] );
		$post_id  = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Four Star Review',
			]
		);
		wp_set_object_terms( $post_id, 'review', Taxonomy::TAXONOMY );
		wp_set_post_categories( $post_id, [ $category->term_id ] );
		update_post_meta( $post_id, '_pkiw_review_rating', 4 );
		update_post_meta( $post_id, '_pkiw_review_best', 5 );
		update_post_meta( $post_id, '_pkiw_review_item_name', 'Fictional Book' );
		$this->go_to( (string) get_term_link( 'review', Taxonomy::TAXONOMY ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( '<h2 id="pkiw-group-alpha-books" class="pkiw-review-section__heading">Alpha Books</h2>', $html );
		$this->assertStringContainsString( 'class="pkiw-four-stars__heading"', $html );
	}

	public function test_a_theme_review_template_wins(): void {
		switch_theme( 'pkiw-review-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-review-theme//taxonomy-kind-review', $template->id );
		$this->assertStringContainsString( 'theme-owned-review-archive', $template->content );
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
				'post_name'    => 'taxonomy-kind-review',
				'post_title'   => 'Review archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-review</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-review', $template->content );
	}

	public function test_review_glyph_is_not_the_fallback_or_neighbor_glyphs(): void {
		$review = \PKIW\get_kind_icon_paths( 'review' );

		$this->assertNotSame( '<circle cx="12" cy="12" r="4"/>', $review );
		$this->assertNotSame( \PKIW\get_kind_icon_paths( 'favorite' ), $review );
		$this->assertNotSame( \PKIW\get_kind_icon_paths( 'comics' ), $review );
	}

	/**
	 * Core's taxonomy hierarchy for the review term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-review.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened blocks from the shipped template.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-review.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-review.html' ) ) );
	}

	/**
	 * Blocks of one name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}
}

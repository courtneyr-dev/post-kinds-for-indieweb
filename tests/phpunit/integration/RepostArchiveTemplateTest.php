<?php
/**
 * The plugin's repost archive template.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Kind_Archive_Layouts;
use PKIW\Taxonomy;

/**
 * The plugin serves templates/taxonomy-kind-repost.html for /kind/repost/
 * when the theme has no kind template of its own. It holds a portable month
 * archive layout: no read-order block, no theme-only classes, and a marker
 * that drives twelve reposts per page grouped by local post month.
 *
 * @group integration
 */
final class RepostArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * A block theme written for this class, holding its own taxonomy-kind-repost.
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
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-repost-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-repost-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Repost Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-repost.html', '<!-- wp:paragraph --><p>theme-owned-repost-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-repost.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-repost-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-repost-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-repost-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
	 * Core's taxonomy hierarchy for the repost term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-repost.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened parsed blocks of the shipped template file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-repost.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-repost.html' ) ) );
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
	 * A published repost with only cite URL meta.
	 */
	private function repost( string $title, string $date ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => $date,
			]
		);
		wp_set_object_terms( $id, 'repost', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_cite_url', 'https://quiet.example.com/' . sanitize_title( $title ) );

		return $id;
	}

	public function test_the_plugin_template_serves_the_repost_archive(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-repost', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-repost' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-repost', $template->id );
		$this->assertSame( 'Repost Archive', $template->title );
	}

	public function test_a_theme_kind_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );
		$this->assertSame( 'pkiw-kind-theme', get_stylesheet() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	public function test_a_theme_repost_template_wins(): void {
		switch_theme( 'pkiw-repost-theme' );
		$this->assertSame( 'pkiw-repost-theme', get_stylesheet() );
		$this->assertTrue( wp_is_block_theme() );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-repost-theme//taxonomy-kind-repost', $template->id );
		$this->assertStringContainsString( 'theme-owned-repost-archive', $template->content );
	}

	public function test_a_site_editor_copy_wins(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-repost',
				'post_title'   => 'Repost archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-repost</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-repost', $template->content );
	}

	public function test_the_template_carries_the_portable_month_archive_layout(): void {
		$file   = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-repost.html' );
		$blocks = $this->blocks();

		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-title' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );
		$this->assertCount( 0, $this->of_type( $blocks, 'post-kinds-indieweb/read-order' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertSame( 'pkiw-kind-shelf', $queries[0]['attrs']['namespace'] ?? '' );
		$this->assertSame( 'pkiw-kind-archive', $queries[0]['attrs']['className'] ?? '' );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertArrayNotHasKey( 'className', $loops[0]['attrs'] ?? [] );
		$this->assertSame( [ Kind_Archive_Layouts::ARCHIVE_SECTIONS, 'post-kinds-indieweb/stream-card' ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );

		$marker = $loops[0]['innerBlocks'][0]['attrs'];
		$this->assertSame( 12, $marker['linesPerPage'] ?? null );
		$this->assertSame( 2, $marker['headingLevel'] ?? null );
		$this->assertCount( 1, $this->of_type( $blocks, Kind_Archive_Layouts::ARCHIVE_SECTIONS ) );
		$card = $loops[0]['innerBlocks'][1]['attrs'];
		$this->assertSame( 3, $card['headingLevel'] ?? null );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( 'center', $pagination[0]['attrs']['layout']['justifyContent'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$this->assertCount( 1, $this->of_type( $blocks, $part ), $part );
		}
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-no-results' ) );
		$this->assertStringContainsString( 'Nothing here yet.', $file );
		$this->assertStringNotContainsString( 'is-style-', $file );
		$this->assertStringNotContainsString( 'cr-', $file );
	}

	public function test_the_rendered_archive_has_one_h1_and_a_month_heading(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$this->repost( 'Quiet July link', '2026-07-01 00:30:00' );
		$this->go_to( (string) get_term_link( 'repost', Taxonomy::TAXONOMY ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;
		$html                         = get_the_block_template_html();
		$month                        = wp_date( 'F Y', strtotime( '2026-07-01 00:30:00' ), wp_timezone() );

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringContainsString( '<h2 id="pkiw-group-2026-07" class="pkiw-group__heading">' . esc_html( $month ) . '</h2>', $html );
	}

	public function test_the_repost_template_sets_page_size_and_month_group_source(): void {
		for ( $i = 0; $i < 13; $i++ ) {
			$this->repost( 'Quiet link ' . $i, '2026-08-' . sprintf( '%02d', 20 - $i ) . ' 10:00:00' );
		}
		$this->go_to( (string) get_term_link( 'repost', Taxonomy::TAXONOMY ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$plan     = Grouped_Archive::main_query_plan( $GLOBALS['wp_query'], 'repost', $template->content );

		$this->assertNotNull( $plan );
		$this->assertSame( 12, $plan['per_page'] );
		$this->assertSame( 'post_month', $plan['vars']['pkiw_group_source'] ?? '' );
	}
}

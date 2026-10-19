<?php
/**
 * The plugin's video archive template (issue 242).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;
use PKIW\Taxonomy;

/**
 * The plugin serves its video template unless the theme or Site Editor owns it.
 *
 * @group integration
 */
final class VideoArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Temporary block-theme root.
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
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-video-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-video-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Video Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-video.html', '<!-- wp:paragraph --><p>theme-owned-video-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-video.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-video-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-video-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-video-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
	 * Core's taxonomy hierarchy for the video term.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-video.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened parsed blocks of the shipped template.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-video.html' );
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

		return $flatten( parse_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-video.html' ) ) );
	}

	/**
	 * Blocks with one name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}

	/**
	 * Sorted attribute keys.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string[]
	 */
	private function sorted_keys( array $attrs ): array {
		$keys = array_keys( $attrs );
		sort( $keys );

		return $keys;
	}

	/**
	 * Render the currently resolved video archive.
	 */
	private function render_archive(): string {
		$this->go_to( (string) get_term_link( 'video', Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	public function test_the_plugin_template_file_is_listed_with_its_title_and_source(): void {
		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-video.html' );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-video', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-video' ] ] ), 'id' ) );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-video', $template->id );
		$this->assertSame( 'Video Archive', $template->title );
		$this->assertSame( 'plugin', $template->source );
	}

	public function test_the_template_has_one_heading_description_main_and_inherited_query(): void {
		$blocks = $this->blocks();

		$this->assertSame(
			[ 'header', 'footer' ],
			array_column( array_map( static fn( array $block ): array => $block['attrs'], $this->of_type( $blocks, 'core/template-part' ) ), 'slug' )
		);
		$main = $this->of_type( $blocks, 'core/group' );
		$this->assertCount( 1, $main );
		$this->assertSame( 'main', $main[0]['attrs']['tagName'] ?? '' );
		$this->assertSame( 'constrained', $main[0]['attrs']['layout']['type'] ?? '' );

		$titles = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $titles );
		$this->assertSame( 'archive', $titles[0]['attrs']['type'] ?? '' );
		$this->assertFalse( $titles[0]['attrs']['showPrefix'] ?? true );
		$this->assertSame( 1, (int) ( $titles[0]['attrs']['level'] ?? 1 ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertSame( [ 'inherit' => true ], $queries[0]['attrs']['query'] ?? [] );
		$this->assertSame( 'pkiw-kind-video', $queries[0]['attrs']['namespace'] ?? '' );
		$this->assertSame( 'pkiw-kind-archive', $queries[0]['attrs']['className'] ?? '' );
	}

	public function test_the_post_template_holds_only_the_marker_and_stream_card_with_exact_attributes(): void {
		$loops = $this->of_type( $this->blocks(), 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertArrayNotHasKey( 'className', $loops[0]['attrs'] );
		$this->assertSame( [ Kind_Archive_Layouts::ARCHIVE_SECTIONS, 'post-kinds-indieweb/stream-card' ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );

		$marker = $loops[0]['innerBlocks'][0]['attrs'];
		$this->assertSame( [ 'emptyLabel', 'linesPerPage' ], $this->sorted_keys( $marker ) );
		$this->assertSame( 12, $marker['linesPerPage'] );
		$this->assertSame( '', $marker['emptyLabel'] );

		$card = $loops[0]['innerBlocks'][1]['attrs'];
		$this->assertSame( [ 'headingLevel' ], $this->sorted_keys( $card ) );
		$this->assertSame( 3, $card['headingLevel'] );
	}

	public function test_pagination_uses_core_labels_and_no_results_is_one_exact_paragraph(): void {
		$blocks     = $this->blocks();
		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$found = $this->of_type( $blocks, $part );
			$this->assertCount( 1, $found, $part );
			$this->assertArrayNotHasKey( 'label', $found[0]['attrs'], $part );
		}

		$none = $this->of_type( $blocks, 'core/query-no-results' );
		$this->assertCount( 1, $none );
		$this->assertSame( [ 'core/paragraph' ], array_column( $none[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 'No videos yet.', trim( wp_strip_all_tags( (string) $none[0]['innerBlocks'][0]['innerHTML'] ) ) );
	}

	public function test_the_source_has_no_read_order_or_second_query(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-video.html' );

		$this->assertStringNotContainsString( 'read-order', $source );
		$this->assertCount( 1, $this->of_type( $this->blocks(), 'core/query' ) );
	}

	public function test_a_theme_video_template_wins(): void {
		switch_theme( 'pkiw-video-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'pkiw-video-theme//taxonomy-kind-video', $template->id );
		$this->assertStringContainsString( 'theme-owned-video-archive', $template->content );
	}

	public function test_a_theme_kind_template_wins(): void {
		switch_theme( 'pkiw-kind-theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'pkiw-kind-theme//taxonomy-kind', $template->id );
		$this->assertStringContainsString( 'theme-owned-kind-archive', $template->content );
	}

	public function test_a_site_editor_copy_wins(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_name'    => 'taxonomy-kind-video',
				'post_title'   => 'Video archive customized',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>user-customized-video</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'custom', $template->source );
		$this->assertStringContainsString( 'user-customized-video', $template->content );
	}

	public function test_rendered_empty_archive_prints_the_exact_message(): void {
		$html = $this->render_archive();

		$this->assertStringContainsString( 'No videos yet.', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertStringNotContainsString( 'data-pkiw-sections', $html );
	}
}

<?php
/**
 * Audio archive template coverage (issue 243).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Audio_Archive;
use PKIW\Grouped_Archive;
use PKIW\Kind_Archive_Layouts;
use PKIW\Taxonomy;

/**
 * The plugin audio archive groups one inherited query by local post month,
 * twelve entries per page, while allowing a theme template to win.
 *
 * @group integration
 */
final class AudioArchiveTemplateTest extends WP_UnitTestCase {

	/**
	 * Root for the throwaway block theme.
	 */
	private static string $theme_root = '';

	/**
	 * Stylesheet active before each test.
	 */
	private string $original_stylesheet = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-audio-template-themes-' . wp_generate_password( 8, false );
		$theme            = self::$theme_root . '/pkiw-audio-theme';
		wp_mkdir_p( $theme . '/templates' );
		file_put_contents( $theme . '/style.css', "/*\nTheme Name: PKIW Audio Theme\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme . '/templates/taxonomy-kind-audio.html', '<!-- wp:paragraph --><p>theme-owned-audio-archive</p><!-- /wp:paragraph -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public static function tear_down_after_class(): void {
		foreach ( [ 'templates/taxonomy-kind-audio.html', 'templates/index.html', 'style.css' ] as $file ) {
			wp_delete_file( self::$theme_root . '/pkiw-audio-theme/' . $file );
		}
		rmdir( self::$theme_root . '/pkiw-audio-theme/templates' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( self::$theme_root . '/pkiw-audio-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
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
		( new Audio_Archive() )->register();
		update_option( 'posts_per_page', 20 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		foreach ( [ dirname( __DIR__ ) . '/fixtures/themes', self::$theme_root ] as $root ) {
			$key = array_search( $root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $key ) {
				unset( $GLOBALS['wp_theme_directories'][ $key ] );
			}
		}
		search_theme_directories( true );
		wp_clean_themes_cache();
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Core's taxonomy template hierarchy for audio.
	 *
	 * @return string[]
	 */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-audio.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/**
	 * Flattened parsed blocks from the shipped template.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		$file = PKIW_PATH . 'templates/taxonomy-kind-audio.html';
		$this->assertFileExists( $file );

		return $this->flatten( parse_blocks( (string) file_get_contents( $file ) ) );
	}

	/**
	 * Flatten parsed blocks in document order.
	 *
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
	 * Select blocks by name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Flattened blocks.
	 * @param string                           $name   Block name.
	 * @return array<int, array<string, mixed>>
	 */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( array $block ): bool => $name === $block['blockName'] ) );
	}

	/**
	 * Render the archive template selected for a URL.
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
	 * A published audio post.
	 *
	 * @param string $title Post title.
	 * @param string $date  Local post date.
	 * @param bool   $timed Whether to add a timed local attachment.
	 * @param string $password Optional post password.
	 */
	private function audio( string $title, string $date, bool $timed = false, string $password = '' ): int {
		$content = '<!-- wp:paragraph --><p>Audio note.</p><!-- /wp:paragraph -->';
		if ( $timed ) {
			$attachment = (int) self::factory()->attachment->create( [ 'post_mime_type' => 'audio/mpeg' ] );
			update_post_meta( $attachment, '_wp_attached_file', '2026/09/archive-audio-' . $attachment . '.mp3' );
			wp_update_attachment_metadata( $attachment, [ 'length' => 75 ] );
			$url     = (string) wp_get_attachment_url( $attachment );
			$content = '<!-- wp:audio {"id":' . $attachment . '} --><figure class="wp-block-audio"><audio controls src="' . esc_url( $url ) . '"></audio></figure><!-- /wp:audio -->';
		}
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_date'     => $date,
				'post_password' => $password,
				'post_content'  => $content,
			]
		);
		wp_set_object_terms( $id, 'audio', Taxonomy::TAXONOMY );

		return $id;
	}

	public function test_shipped_file_has_the_exact_archive_block_shape(): void {
		$blocks = $this->blocks();
		$title  = $this->of_type( $blocks, 'core/query-title' );
		$this->assertCount( 1, $title );
		$this->assertSame( 1, (int) ( $title[0]['attrs']['level'] ?? 1 ) );
		$this->assertSame( 'archive', $title[0]['attrs']['type'] ?? '' );
		$this->assertFalse( $title[0]['attrs']['showPrefix'] ?? true );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/term-description' ) );

		$queries = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $queries );
		$this->assertTrue( $queries[0]['attrs']['query']['inherit'] ?? false );
		$this->assertStringContainsString( 'pkiw-kind-archive', (string) ( $queries[0]['attrs']['className'] ?? '' ) );

		$loops = $this->of_type( $blocks, 'core/post-template' );
		$this->assertCount( 1, $loops );
		$this->assertSame( [ Kind_Archive_Layouts::ARCHIVE_SECTIONS, 'post-kinds-indieweb/stream-card' ], array_column( $loops[0]['innerBlocks'], 'blockName' ) );
		$marker = $loops[0]['innerBlocks'][0]['attrs'];
		ksort( $marker );
		$this->assertSame( [ 'headingLevel' => 2, 'linesPerPage' => 12 ], $marker );
		$this->assertSame( [ 'headingLevel' => 3 ], $loops[0]['innerBlocks'][1]['attrs'] );

		$query_children = array_values( array_filter( $queries[0]['innerBlocks'], static fn( array $block ): bool => null !== $block['blockName'] ) );
		$names          = array_column( $query_children, 'blockName' );
		$this->assertSame( 'core/query-pagination', $names[ array_search( 'core/post-template', $names, true ) + 1 ] );
		$this->assertSame( 1, substr_count( implode( '|', $names ), 'core/post-template' ) );

		$pagination = $this->of_type( $blocks, 'core/query-pagination' );
		$this->assertCount( 1, $pagination );
		$this->assertSame( 'arrow', $pagination[0]['attrs']['paginationArrow'] ?? '' );
		$this->assertSame( [ 'type' => 'flex', 'justifyContent' => 'center' ], $pagination[0]['attrs']['layout'] ?? [] );
		foreach ( [ 'core/query-pagination-previous', 'core/query-pagination-numbers', 'core/query-pagination-next' ] as $part ) {
			$found = $this->of_type( $blocks, $part );
			$this->assertCount( 1, $found );
			$this->assertArrayNotHasKey( 'label', $found[0]['attrs'] );
		}

		$none = $this->of_type( $blocks, 'core/query-no-results' );
		$this->assertCount( 1, $none );
		$this->assertSame( [ 'core/paragraph' ], array_column( $none[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( 'No audio yet.', trim( wp_strip_all_tags( serialize_block( $none[0]['innerBlocks'][0] ) ) ) );
		$this->assertSame( [ 'header', 'footer' ], array_column( array_map( static fn( array $block ): array => $block['attrs'], $this->of_type( $blocks, 'core/template-part' ) ), 'slug' ) );
	}

	public function test_template_contains_no_month_names_read_order_or_nested_query(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-audio.html' );
		$this->assertDoesNotMatchRegularExpression( '/January|February|March|April|May|June|July|August|September|October|November|December/i', $source );
		$this->assertStringNotContainsString( 'read-order', $source );
		$this->assertSame( 1, substr_count( $source, '<!-- wp:query ' ) );
		$this->assertSame( 1, substr_count( strtolower( $source ), 'audio' ), 'Only the empty-state sentence names the kind.' );
	}

	public function test_plugin_template_is_listed_and_served_with_title_and_source(): void {
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-audio', wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-audio' ] ] ), 'id' ) );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-audio', $template->id );
		$this->assertSame( 'Audio Archive', $template->title );
		$this->assertSame( 'plugin', $template->source );
	}

	public function test_theme_audio_template_wins(): void {
		switch_theme( 'pkiw-audio-theme' );
		$this->assertTrue( wp_is_block_theme() );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertSame( 'pkiw-audio-theme//taxonomy-kind-audio', $template->id );
		$this->assertStringContainsString( 'theme-owned-audio-archive', $template->content );
	}

	public function test_audio_uses_the_shared_post_month_source(): void {
		$source = Grouped_Archive::source( 'post_month' );
		$this->assertNotNull( $source );
		$this->assertSame( 'post_month', $source->id() );
		$this->assertContains( 'audio', Grouped_Archive::grouped_kinds() );

		$id = $this->audio( 'Late September', '2026-09-30 23:30:00' );
		$this->assertSame( '2026-09', Grouped_Archive::group_of_post( 'audio', $id )->key() );
		$this->assertSame( 'September 2026', Grouped_Archive::group_label_of_post( 'audio', $id ) );
	}

	/**
	 * The text of each item's title link.
	 *
	 * @param string $html Rendered archive.
	 * @return string[]
	 */
	private function item_titles( string $html ): array {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$titles = [];
		foreach ( ( new DOMXPath( $doc ) )->query( '//h3[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]//a' ) as $link ) {
			$titles[] = trim( (string) $link->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
		}

		return $titles;
	}

	public function test_served_archive_pages_repeat_split_month_and_end_at_page_two(): void {
		for ( $day = 24; $day <= 30; $day++ ) {
			$this->audio( 'September ' . $day, sprintf( '2026-09-%02d 10:00:00', $day ), 30 === $day, 29 === $day ? 'seed-243-pass' : '' );
		}
		for ( $day = 20; $day <= 28; $day++ ) {
			$this->audio( 'August ' . $day, sprintf( '2026-08-%02d 10:00:00', $day ), 28 === $day );
		}
		for ( $day = 10; $day <= 12; $day++ ) {
			$this->audio( 'June ' . $day, sprintf( '2026-06-%02d 10:00:00', $day ) );
		}
		for ( $day = 10; $day <= 12; $day++ ) {
			$this->audio( 'December ' . $day, sprintf( '2025-12-%02d 10:00:00', $day ) );
		}

		$url   = get_term_link( 'audio', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$page1 = $this->serve( $url );
		$this->assertSame( 1, substr_count( $page1, '<h1' ) );
		$this->assertMatchesRegularExpression( '/<h1[^>]*>\s*Audio\s*<\/h1>/', $page1 );
		$this->assertSame( 12, preg_match_all( '/<h3[^>]*class="[^"]*pk-title/', $page1 ) );
		$this->assertMatchesRegularExpression( '/September 2026.*August 2026/s', $page1 );
		$titles = $this->item_titles( $page1 );
		$this->assertCount( 7, preg_grep( '/September \d+$/', $titles ) );
		$this->assertCount( 5, preg_grep( '/August \d+$/', $titles ) );
		$this->assertSame( 2, substr_count( $page1, 'pk-audio-duration' ), 'Both timed page-one entries expose duration.' );
		$this->assertMatchesRegularExpression( '/Protected: September 29.*pk-stream-date/s', $page1 );
		$this->assertDoesNotMatchRegularExpression( '/Protected: September 29.*pk-audio-duration/s', substr( $page1, strpos( $page1, 'Protected: September 29' ), 800 ) );

		$page2 = $this->serve( trailingslashit( $url ) . 'page/2/' );
		$this->assertSame( 10, preg_match_all( '/<h3[^>]*class="[^"]*pk-title/', $page2 ) );
		$this->assertMatchesRegularExpression( '/August 2026.*June 2026.*December 2025/s', $page2 );
		$titles = $this->item_titles( $page2 );
		$this->assertCount( 4, preg_grep( '/August \d+$/', $titles ) );
		$this->assertCount( 3, preg_grep( '/June \d+$/', $titles ) );
		$this->assertCount( 3, preg_grep( '/December \d+$/', $titles ) );

		$this->go_to( trailingslashit( $url ) . 'page/3/' );
		$this->assertTrue( is_404() );
	}
}

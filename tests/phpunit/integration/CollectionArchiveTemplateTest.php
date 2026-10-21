<?php
/**
 * Collection archive template coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * The portable collection archive template uses one inherited Stream-card query.
 *
 * @group integration
 */
final class CollectionArchiveTemplateTest extends WP_UnitTestCase {

	/** Original stylesheet. */
	private string $original_stylesheet = '';

	/** Temporary theme root. */
	private string $theme_root = '';

	/** Use a block theme with no collection-specific template. */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
	}

	/** Restore the theme and remove test files. */
	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		if ( '' !== $this->theme_root ) {
			$key = array_search( $this->theme_root, $GLOBALS['wp_theme_directories'], true );
			if ( false !== $key ) {
				unset( $GLOBALS['wp_theme_directories'][ $key ] );
			}
			search_theme_directories( true );
			wp_clean_themes_cache();
			$this->remove_tree( $this->theme_root );
			$this->theme_root = '';
		}
		parent::tear_down();
	}

	/** Core's template hierarchy for the collection term. */
	private function hierarchy(): array {
		return [ 'taxonomy-kind-collection.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ];
	}

	/** Flatten parsed blocks. */
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

	/** Select blocks by name. */
	private function of_type( array $blocks, string $name ): array {
		return array_values( array_filter( $blocks, static fn( $block ) => $name === $block['blockName'] ) );
	}

	/** Create a child theme with its own collection template. */
	private function make_theme(): string {
		$this->theme_root = trailingslashit( get_temp_dir() ) . 'pkiw-collection-theme-' . wp_generate_password( 8, false );
		$theme_dir        = $this->theme_root . '/pkiw-collection-theme';
		wp_mkdir_p( $theme_dir . '/templates' );
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: PKIW Collection Theme (test)\nTemplate: twentytwentyfive\nVersion: 1.0.0\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $theme_dir . '/templates/taxonomy-kind-collection.html', '<!-- wp:group {"className":"theme-owned-collection"} --><div class="wp-block-group theme-owned-collection"></div><!-- /wp:group -->' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		register_theme_directory( $this->theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();
		return 'pkiw-collection-theme';
	}

	/** Recursively remove the temporary theme. */
	private function remove_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		foreach ( (array) scandir( $directory ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $directory . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_tree( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/** Plugin template registration and structure match the archive contract. */
	public function test_plugin_template_registers_and_parses(): void {
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-collection', $template->id );

		$blocks = $this->flatten( parse_blocks( $template->content ) );
		$query  = $this->of_type( $blocks, 'core/query' );
		$this->assertCount( 1, $query );
		$this->assertTrue( $query[0]['attrs']['query']['inherit'] );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/post-template' ) );
		$cards = $this->of_type( $blocks, 'post-kinds-indieweb/stream-card' );
		$this->assertCount( 1, $cards );
		$this->assertSame( 2, $cards[0]['attrs']['headingLevel'] );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-pagination' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-pagination-previous' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-pagination-numbers' ) );
		$this->assertCount( 1, $this->of_type( $blocks, 'core/query-pagination-next' ) );
	}

	/** A theme-owned collection archive template takes precedence. */
	public function test_theme_template_wins(): void {
		switch_theme( $this->make_theme() );
		$template = resolve_block_template( 'taxonomy', $this->hierarchy(), '' );

		$this->assertSame( 'pkiw-collection-theme//taxonomy-kind-collection', $template->id );
		$this->assertStringContainsString( 'theme-owned-collection', $template->content );
	}
}

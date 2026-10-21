<?php
/**
 * Drawn comics archive block template (issue 266).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Comic_Strip;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicDrawnTemplateTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Comic_Strip() )->register();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function gallery( int $attachment_id ): string {
		return '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images"><!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img src="https://example.org/' . $attachment_id . '.jpg" alt="Panel" /><figcaption class="wp-element-caption">Transcript</figcaption></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
	}

	private function strip(): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Template strip',
				'post_content' => $this->gallery( self::factory()->attachment->create() ),
			]
		);
		wp_set_object_terms( $id, Comic_Strip::KIND, Taxonomy::TAXONOMY );
		update_post_meta( $id, Comic_Strip::SERIES_META, 'Field Notes' );

		return (int) $id;
	}

	private function archive_url(): string {
		return (string) get_term_link( Comic_Strip::KIND, Taxonomy::TAXONOMY );
	}

	/**
	 * Find the first parsed block by name.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( $name === ( $block['blockName'] ?? null ) ) {
				return $block;
			}
			$found = $this->find_block( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [], $name );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Assert parsed freeform nodes contain whitespace only.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private function assert_no_invalid_freeform( array $blocks ): void {
		foreach ( $blocks as $block ) {
			if ( null === ( $block['blockName'] ?? null ) ) {
				$this->assertSame( '', trim( (string) ( $block['innerHTML'] ?? '' ) ) );
			}
			$this->assert_no_invalid_freeform( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [] );
		}
	}

	public function test_template_definition_and_file_are_registered(): void {
		$file = PKIW_PATH . 'templates/taxonomy-kind-comics-drawn.html';
		$this->assertFileExists( $file );
		$this->assertSame( 1, count( glob( $file ) ?: [] ) );

		$definitions = ( new ReflectionMethod( PKIW\Plugin::class, 'get_plugin_template_definitions' ) )->invoke( PKIW\Plugin::get_instance() );
		$this->assertArrayHasKey( 'taxonomy-kind-comics-drawn', $definitions );
		$this->assertSame( 'Comics Drawn Archive', $definitions['taxonomy-kind-comics-drawn']['title'] );
		$this->assertSame( 'Drawn comics in sections by series, a view of the comics archive.', $definitions['taxonomy-kind-comics-drawn']['description'] );
		$this->assertSame( [], $definitions['taxonomy-kind-comics-drawn']['post_types'] );
		$this->assertNotNull( get_block_template( 'post-kinds-for-indieweb//taxonomy-kind-comics-drawn' ) );
	}

	public function test_drawn_template_is_first_candidate_only_for_the_registered_view(): void {
		$this->strip();
		$this->go_to( add_query_arg( Comic_Strip::VIEW_VAR, Comic_Strip::VIEW_DRAWN, $this->archive_url() ) );
		$drawn = apply_filters( 'taxonomy_template_hierarchy', [ 'taxonomy-kind-comics.php', 'taxonomy-kind.php', 'taxonomy.php' ] );
		$this->assertSame( 'taxonomy-kind-comics-drawn.php', $drawn[0] );

		$this->go_to( $this->archive_url() );
		$plain = apply_filters( 'taxonomy_template_hierarchy', [ 'taxonomy-kind-comics.php', 'taxonomy-kind.php', 'taxonomy.php' ] );
		$this->assertSame( 'taxonomy-kind-comics.php', $plain[0] );
		$this->assertNotContains( 'taxonomy-kind-comics-drawn.php', $plain );
	}

	public function test_template_structure_uses_core_query_blocks_and_archive_marker(): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-comics-drawn.html' );
		$blocks  = parse_blocks( $content );
		$query   = $this->find_block( $blocks, 'core/query' );
		$post_template = $this->find_block( $blocks, 'core/post-template' );

		$this->assertNotNull( $query );
		$this->assertTrue( $query['attrs']['query']['inherit'] );
		$this->assertSame( 'pkiw-kind-archive', $query['attrs']['className'] );
		$this->assertNotNull( $this->find_block( $blocks, 'core/query-title' ) );
		$this->assertNotNull( $this->find_block( $blocks, 'core/term-description' ) );
		$this->assertNotNull( $this->find_block( $blocks, Comic_Strip::SERIES_BLOCK ) );
		$this->assertNotNull( $post_template );
		$marker = $this->find_block( $post_template['innerBlocks'], 'post-kinds-indieweb/archive-sections' );
		$title  = $this->find_block( $post_template['innerBlocks'], 'core/post-title' );
		$this->assertNotNull( $marker );
		$this->assertSame( 12, $marker['attrs']['linesPerPage'] );
		$this->assertSame( 2, $marker['attrs']['headingLevel'] );
		$this->assertArrayNotHasKey( 'emptyLabel', $marker['attrs'] );
		$this->assertSame( 3, $title['attrs']['level'] );
		$this->assertTrue( $title['attrs']['isLink'] );
		$this->assertNotNull( $this->find_block( $query['innerBlocks'], 'core/query-pagination' ) );
		$this->assertNotNull( $this->find_block( $query['innerBlocks'], 'core/query-pagination-previous' ) );
		$this->assertNotNull( $this->find_block( $query['innerBlocks'], 'core/query-pagination-numbers' ) );
		$this->assertNotNull( $this->find_block( $query['innerBlocks'], 'core/query-pagination-next' ) );
		$this->assertNotNull( $this->find_block( $query['innerBlocks'], 'core/query-no-results' ) );
		$this->assertStringContainsString( 'No drawn comics yet.', $content );
		$this->assertStringNotContainsString( 'courtneyr', $content );
		$this->assert_no_invalid_freeform( $blocks );
	}
}

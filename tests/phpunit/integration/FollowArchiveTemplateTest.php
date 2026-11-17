<?php
/** Follow plugin-template coverage. @package PKIW */
declare(strict_types=1);

/** @group integration */
final class FollowArchiveTemplateTest extends WP_UnitTestCase {
	public function test_template_query_keeps_namespace_and_letters_before_entries(): void {
		$path   = PKIW_PATH . 'templates/taxonomy-kind-follow.html';
		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository fixture.
		$this->assertStringContainsString( '"namespace":"pkiw-kind-follow"', $source );
		$this->assertLessThan( strpos( $source, 'follow-entry' ), strpos( $source, 'follow-letters' ), 'Moving letters after entries would break the saved Query variation.' );
		$blocks = parse_blocks( $source );
		$this->assertNotEmpty( $blocks );
		$query = $this->find_block( $blocks, 'core/query' );
		$this->assertTrue( $query['attrs']['query']['inherit'] );
		$this->assertSame( 8, $query['attrs']['query']['perPage'] );
		$this->assertSame( 'pkiw-kind-follow', $query['attrs']['namespace'] );
		$this->assertSame( 'post-kinds-indieweb/follow-letters', $query['innerBlocks'][0]['blockName'] );
		$this->assertSame( 'core/post-template', $query['innerBlocks'][1]['blockName'] );
	}

	public function test_template_is_offered_by_name_and_empty_render_has_no_letters(): void {
		( new PKIW\Taxonomy() )->create_default_terms();
		$templates = get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-follow' ] ] );
		$ids       = wp_list_pluck( $templates, 'id' );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-follow', $ids );
		$template = $templates[ array_search( 'post-kinds-for-indieweb//taxonomy-kind-follow', $ids, true ) ];
		$this->assertSame( 'Follow Archive', $template->title );

		$this->go_to( (string) get_term_link( 'follow', PKIW\Taxonomy::TAXONOMY ) );
		$html = do_blocks( (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-follow.html' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository fixture.
		$this->assertStringContainsString( 'Nothing here yet.', $html );
		$this->assertStringNotContainsString( 'pkiw-follow-letters', $html, 'An empty archive must not print the letters navigation.' );
	}

	private function find_block( array $blocks, string $name ): array {
		foreach ( $blocks as $block ) {
			if ( $name === ( $block['blockName'] ?? '' ) ) {
				return $block;
			}
			$found = $this->find_block( $block['innerBlocks'] ?? [], $name );
			if ( [] !== $found ) {
				return $found;
			}
		}
		return [];
	}
}

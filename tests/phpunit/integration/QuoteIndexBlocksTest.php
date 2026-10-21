<?php
/**
 * Quote author tabs and source-work index blocks.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuoteIndexBlocksTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
	}

	private function quote( string $author = '', string $work = '', array $args = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish', 'post_date' => '2026-09-04 10:00:00' ], $args ) );
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		if ( '' !== $author ) {
			add_post_meta( $id, '_pkiw_cite_author', $author );
		}
		if ( '' !== $work ) {
			add_post_meta( $id, '_pkiw_cite_name', $work );
		}

		return (int) $id;
	}

	private function render( string $name, array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	public function test_filter_links_encode_reserved_characters_so_values_round_trip(): void {
		$this->quote( 'Ann & Bob #1', 'Cats & Dogs #1 + More' );
		$html = $this->render( Quote_Archive::WORKS_BLOCK );

		$this->assertSame( 1, preg_match( '/href="([^"]*pkiw_quote_work=[^"]*)"/', $html, $found ) );
		$query = (string) wp_parse_url( html_entity_decode( $found[1] ), PHP_URL_QUERY );
		parse_str( $query, $vars );
		$this->assertSame( 'Cats & Dogs #1 + More', $vars['pkiw_quote_work'] ?? null );

		$group = Quote_Archive::source()->group_of( get_post( (int) get_posts( [ 'post_type' => 'post', 'fields' => 'ids', 'numberposts' => 1 ] )[0] ) );
		$query = (string) wp_parse_url( Quote_Archive::author_url( $group ), PHP_URL_QUERY );
		parse_str( $query, $vars );
		$this->assertSame( 'Ann & Bob #1', $vars['pkiw_quote_author'] ?? null );
	}

	public function test_author_heading_always_prints_but_nav_needs_a_stored_author(): void {
		$this->quote();
		$html = $this->render( Quote_Archive::AUTHORS_BLOCK );

		$this->assertStringContainsString( '<h2 id="pkiw-quote-by-author"', $html );
		$this->assertStringContainsString( '>By author</h2>', $html );
		$this->assertStringNotContainsString( '<nav', $html );
	}

	public function test_author_tabs_follow_index_order_and_all_is_current_without_a_filter(): void {
		$this->quote( 'Riley', 'Book' );
		$this->quote( 'Álvaro', 'Atlas', [ 'post_date' => '2026-09-03 10:00:00' ] );
		$html = $this->render( Quote_Archive::AUTHORS_BLOCK );

		preg_match_all( '/<a href="[^"]*pkiw_quote_initial=[^"]*"[^>]*>([^<]+)<\/a>/', $html, $letters );
		$this->assertSame( array_keys( Quote_Archive::index()['initials'] ), $letters[1] );
		$this->assertEqualsCanonicalizing( [ 'R', 'Á' ], $letters[1] );
		$this->assertLessThan( strpos( $html, '>R</a>' ), strpos( $html, '>All</a>' ) );
		$this->assertMatchesRegularExpression( '/<a[^>]+aria-current="page"[^>]*>All<\/a>/', $html );
	}

	public function test_active_initial_and_work_links_get_aria_current(): void {
		$this->quote( 'Riley', 'Collected Essays' );
		$GLOBALS['wp_query']->set( Quote_Archive::VAR_INITIAL, 'R' );
		$this->assertMatchesRegularExpression( '/<a[^>]+aria-current="page"[^>]*>R<\/a>/', $this->render( Quote_Archive::AUTHORS_BLOCK ) );

		$GLOBALS['wp_query']->set( Quote_Archive::VAR_WORK, 'Collected Essays' );
		$this->assertMatchesRegularExpression( '/<a class="pkiw-quote-work__title"[^>]+aria-current="page"/', $this->render( Quote_Archive::WORKS_BLOCK ) );
	}

	public function test_all_is_current_in_the_editor_block_preview_even_with_a_filter_in_the_url(): void {
		$this->quote( 'Riley', 'Book' );
		$GLOBALS['wp_query']->set( Quote_Archive::VAR_INITIAL, 'R' );
		add_filter( 'rest_request_before_callbacks', [ \PKIW\Grouped_Archive::class, 'track_block_preview' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ \PKIW\Grouped_Archive::class, 'track_block_preview' ], 10, 3 );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Quote_Archive::AUTHORS_BLOCK );
		apply_filters( 'rest_request_before_callbacks', null, [], $request );

		try {
			$html = $this->render( Quote_Archive::AUTHORS_BLOCK );
		} finally {
			// The engine's preview flag is static; leave it as the editor request does when it ends.
			apply_filters( 'rest_request_after_callbacks', null, [], $request );
		}

		$this->assertMatchesRegularExpression( '/<a[^>]+aria-current="page"[^>]*>All<\/a>/', $html );
		$this->assertDoesNotMatchRegularExpression( '/aria-current="page"[^>]*>R<\/a>/', $html );
		$this->assertFalse( \PKIW\Grouped_Archive::is_block_preview() );
	}

	public function test_works_block_is_empty_without_a_work_and_long_above_the_threshold(): void {
		$this->quote( 'Avery' );
		$this->assertSame( '', $this->render( Quote_Archive::WORKS_BLOCK ) );

		$this->quote( 'Avery', 'First Work' );
		$this->quote( 'Bryn', 'Second Work', [ 'post_date' => '2026-09-03 10:00:00' ] );
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
		$html = $this->render( Quote_Archive::WORKS_BLOCK, [ 'twoPageMax' => 1 ] );
		$this->assertStringContainsString( 'pkiw-quote-works is-long', $html );
		$this->assertStringContainsString( 'data-pkiw-works="2"', $html );
	}

	public function test_shared_title_links_include_author_and_have_distinct_accessible_descriptions(): void {
		$this->quote( 'Avery', 'Shared Work' );
		$this->quote( 'Bryn', 'Shared Work', [ 'post_date' => '2026-09-03 10:00:00' ] );
		$html = $this->render( Quote_Archive::WORKS_BLOCK );

		$this->assertSame( 2, substr_count( $html, 'pkiw_quote_work=Shared%20Work' ) );
		$this->assertSame( 2, substr_count( $html, 'pkiw_quote_author=' ) );
		$this->assertSame( 2, substr_count( $html, 'aria-describedby=' ) );
		$this->assertStringContainsString( '>1 quote</span>', $html );
	}

	public function test_pluralized_count_comes_from_the_pair_count(): void {
		$this->quote( 'Avery', 'Repeated Work' );
		$this->quote( 'avery', 'repeated work', [ 'post_date' => '2026-09-03 10:00:00' ] );

		$this->assertStringContainsString( '>2 quotes</span>', $this->render( Quote_Archive::WORKS_BLOCK ) );
	}
}

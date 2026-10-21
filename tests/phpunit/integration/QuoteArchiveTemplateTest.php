<?php
/**
 * Quote archive block template.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class QuoteArchiveTemplateTest extends WP_UnitTestCase {

	public function test_template_is_offered_as_quote_archive(): void {
		$file = PKIW_PATH . 'templates/taxonomy-kind-quote.html';
		$this->assertFileExists( $file );
		$template = get_block_template( 'post-kinds-for-indieweb//taxonomy-kind-quote' );
		$this->assertNotNull( $template );
		$this->assertSame( 'Quote Archive', $template->title );
	}

	public function test_template_parses_with_the_inherited_query_and_three_quote_blocks(): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-quote.html' );
		$blocks  = parse_blocks( $content );

		$this->assertNotEmpty( $blocks );
		$this->assertStringContainsString( '<!-- wp:query {"queryId":0,"query":{"inherit":true}', $content );
		$this->assertStringContainsString( '<!-- wp:post-kinds-indieweb/quote-authors', $content );
		$this->assertStringContainsString( '<!-- wp:post-kinds-indieweb/quote-entry', $content );
		$this->assertStringContainsString( '"className":"is-style-cr-commonplace"', $content );
		$this->assertStringContainsString( '<!-- wp:post-kinds-indieweb/quote-works', $content );
		$this->assertStringContainsString( '<!-- wp:query-pagination ', $content );
		$this->assertStringContainsString( '<!-- wp:query-no-results -->', $content );
	}
}

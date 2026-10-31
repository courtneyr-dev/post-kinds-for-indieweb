<?php
/**
 * RSVP archive block template coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class RsvpArchiveTemplateTest extends WP_UnitTestCase {

	public function test_template_is_offered_with_the_frozen_title_and_description(): void {
		$file = PKIW_PATH . 'templates/taxonomy-kind-rsvp.html';
		$this->assertFileExists( $file );
		$template = get_block_template( 'post-kinds-for-indieweb//taxonomy-kind-rsvp' );
		$this->assertNotNull( $template );
		$this->assertSame( 'RSVP Archive', $template->title );
		$this->assertSame( 'RSVP archive in two groups: upcoming events soonest first, then past events latest first.', $template->description );
	}

	public function test_template_parses_with_an_inherited_query_entry_pagination_and_empty_state(): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-rsvp.html' );
		$this->assertNotEmpty( parse_blocks( $content ) );
		$this->assertStringContainsString( '<!-- wp:query-title {"type":"archive","showPrefix":false} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:term-description /-->', $content );
		$this->assertStringContainsString( '<!-- wp:query {"queryId":0,"query":{"inherit":true}', $content );
		$this->assertStringContainsString( '<!-- wp:post-kinds-indieweb/rsvp-entry {"linesPerPage":9} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:query-pagination ', $content );
		$this->assertStringContainsString( '<!-- wp:query-no-results -->', $content );
		$this->assertStringContainsString( 'Nothing here yet.', $content );
	}
}

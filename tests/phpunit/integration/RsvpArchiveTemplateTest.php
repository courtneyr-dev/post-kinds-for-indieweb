<?php
/**
 * RSVP archive block template coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpArchiveTemplateTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function render_archive(): string {
		$this->go_to( (string) get_term_link( 'rsvp', Taxonomy::TAXONOMY ) );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-rsvp.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-rsvp', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

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

	public function test_rendered_archive_with_no_rsvps_prints_header_and_no_results(): void {
		$html = $this->render_archive();

		$this->assertStringContainsString( '<h1', $html );
		$this->assertStringContainsString( 'RSVP', $html );
		$this->assertStringContainsString( 'Nothing here yet.', $html );
		$this->assertStringNotContainsString( 'pkiw-rsvp-entry', $html );
	}
}

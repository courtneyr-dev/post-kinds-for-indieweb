<?php
/** Follow OPML coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowOpmlTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_document_is_well_formed_and_omits_rows_without_urls(): void {
		$xml = Follow_Archive::opml_document( [ [ 'name' => 'A & B', 'url' => 'https://ab.example/', 'modified_gmt' => '' ], [ 'name' => 'No URL', 'url' => '', 'modified_gmt' => '' ] ] );
		$this->assertInstanceOf( SimpleXMLElement::class, simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET ) );
		$this->assertSame( 1, substr_count( $xml, '<outline' ), 'Restoring URL-less outlines would expose a name without a follow target.' );
		$this->assertStringNotContainsString( 'No URL', $xml );
		$this->assertSame( 405, Follow_Archive::opml_response( 'POST' )['status'] );
	}

	public function test_taxonomy_archive_prints_the_opml_alternate_link(): void {
		global $wp_query;

		$previous_query           = $wp_query;
		$wp_query                 = new WP_Query();
		$wp_query->is_tax         = true;
		$wp_query->queried_object = get_term_by( 'slug', 'follow', Taxonomy::TAXONOMY );

		$buffer_level = ob_get_level();
		ob_start();
		try {
			Follow_Archive::opml_alternate_link();
			$html = (string) ob_get_clean();
		} finally {
			$wp_query = $previous_query;
			if ( $buffer_level < ob_get_level() ) {
				ob_end_clean();
			}
		}

		$this->assertStringContainsString( 'rel="alternate"', $html );
		$this->assertStringContainsString( 'type="text/x-opml"', $html );
		$this->assertStringContainsString( 'follow/opml/', $html, 'Removing discovery would leave OPML reachable only by a known URL.' );
	}
}

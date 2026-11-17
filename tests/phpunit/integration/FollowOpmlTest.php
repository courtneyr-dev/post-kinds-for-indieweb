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

	public function test_zero_one_and_twenty_rows_are_well_formed_in_archive_order(): void {
		foreach ( [ [], [ [ 'name' => 'One', 'url' => 'https://one.example/', 'modified_gmt' => '' ] ], array_map( static fn( int $i ): array => [ 'name' => 'Person ' . $i, 'url' => 'https://person-' . $i . '.example/', 'modified_gmt' => '' ], range( 1, 20 ) ) ] as $rows ) {
			$xml = Follow_Archive::opml_document( $rows );
			$this->assertInstanceOf( SimpleXMLElement::class, simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET ) );
			$this->assertSame( count( $rows ), substr_count( $xml, '<outline' ) );
		}
		$rows = [ [ 'name' => 'First', 'url' => 'https://first.example/' ], [ 'name' => 'Second', 'url' => 'https://second.example/' ] ];
		$xml  = Follow_Archive::opml_document( $rows );
		$this->assertLessThan( strpos( $xml, 'Second' ), strpos( $xml, 'First' ), 'OPML must retain archive row order.' );
	}

	public function test_xml_escaping_forbidden_characters_and_modified_date(): void {
		$xml = Follow_Archive::opml_document(
			[
				[ 'name' => "A < B & \"Quoted\"\x01", 'url' => 'https://escaped.example/?a=1&b=2', 'modified_gmt' => '2026-11-15 12:00:00' ],
				[ 'name' => 'Older', 'url' => 'https://older.example/', 'modified_gmt' => '2026-11-14 12:00:00' ],
				[ 'name' => 'Script', 'url' => 'javascript:alert(1)', 'modified_gmt' => '' ],
				[ 'name' => 'Bare', 'url' => 'example.com', 'modified_gmt' => '' ],
			]
		);
		$this->assertStringContainsString( 'A &lt; B &amp; &quot;Quoted&quot;', $xml );
		$this->assertStringNotContainsString( "\x01", $xml );
		$this->assertSame( 2, substr_count( $xml, '<outline' ), 'Rejected javascript and bare-name URLs must have no outline.' );
		$this->assertStringContainsString( '<dateModified>' . gmdate( DATE_RSS, strtotime( '2026-11-15 12:00:00' ) ) . '</dateModified>', $xml );
		$this->assertStringNotContainsString( '<dateModified>', Follow_Archive::opml_document( [] ) );
	}

	public function test_missing_term_is_404_and_bare_query_paths_share_one_body(): void {
		$term = get_term_by( 'slug', 'follow', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );
		$this->assertSame( 404, Follow_Archive::opml_response( 'GET' )['status'] );
		( new Taxonomy() )->create_default_terms();

		$_SERVER['REQUEST_URI'] = '/?pkiw_follow_opml=1';
		$root_body              = Follow_Archive::opml_response( 'GET' )['body'];
		$_SERVER['REQUEST_URI'] = '/stream/?pkiw_follow_opml=1';
		$this->assertSame( $root_body, Follow_Archive::opml_response( 'GET' )['body'], 'OPML depends on the query var, not the request path.' );
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

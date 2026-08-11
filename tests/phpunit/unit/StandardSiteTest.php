<?php
/**
 * Tests for the Standard_Site reader.
 *
 * HTTP is mocked here. A companion check at tests/manual/standard-site-resolve.php
 * runs the same resolver against live records, because mocked responses cannot
 * catch a request that is malformed in a way the mock happens to tolerate --
 * which is exactly how the double-encoding bug got in.
 *
 * @package PKIW
 * @group   standard-site
 */

namespace PKIW\Tests\Unit;

use PKIW\Standard_Site;
use PKIW\Tests\ApiTestCase;

/**
 * Standard_Site tests.
 */
class StandardSiteTest extends ApiTestCase {

	private const DID  = 'did:plc:revjuqmkvrw6fnkxppqtszpv';
	private const PDS  = 'https://pds.example.test';
	private const PAGE = 'https://notes.example.test/a-post';

	/**
	 * Clear resolver caches so tests do not leak into one another.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->flush_resolver_cache();
	}

	/**
	 * Remove every transient the resolver may have written.
	 */
	private function flush_resolver_cache(): void {
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pkiw_ss_%' OR option_name LIKE '_transient_timeout_pkiw_ss_%'"
		);
		wp_cache_flush();
	}

	/**
	 * A page carrying the document link tag, with everything wired to verify.
	 *
	 * @param string $path Document path, as stored on the record.
	 */
	private function mock_happy_path( string $path = '/a-post' ): void {
		$this->mock_http_raw_response(
			'notes.example.test/a-post',
			'<html><head><link rel="site.standard.document" href="at://' . self::DID . '/site.standard.document/3mp3nrm5leyim" /></head><body>hi</body></html>',
			200,
			[ 'content-type' => 'text/html' ]
		);

		$this->mock_http_response(
			'plc.directory',
			[
				'service' => [
					[
						'type'            => 'AtprotoPersonalDataServer',
						'serviceEndpoint' => self::PDS,
					],
				],
			]
		);

		$this->mock_http_response(
			'collection=site.standard.document',
			[
				'value' => [
					'site'        => 'at://' . self::DID . '/site.standard.publication/3lwafzkjqm25s',
					'path'        => $path,
					'title'       => 'A post',
					'publishedAt' => '2026-07-17T19:44:04.000Z',
				],
			]
		);

		$this->mock_http_response(
			'collection=site.standard.publication',
			[
				'value' => [
					'url'  => 'https://notes.example.test',
					'name' => 'Example Notes',
				],
			]
		);
	}

	public function test_resolves_document_and_verifies_backlink() {
		$this->mock_happy_path();

		$result = Standard_Site::resolve_url( self::PAGE );

		$this->assertIsArray( $result );
		$this->assertSame( 'at://' . self::DID . '/site.standard.document/3mp3nrm5leyim', $result['uri'] );
		$this->assertSame( 'A post', $result['record']['title'] );
		$this->assertTrue( $result['verified'] );
	}

	public function test_publication_is_returned_with_the_document() {
		$this->mock_happy_path();

		$result = Standard_Site::resolve_url( self::PAGE );

		$this->assertSame( 'Example Notes', $result['publication']['record']['name'] );
	}

	public function test_backlink_mismatch_is_not_verified() {
		// The record claims a different path than the page it was found on.
		$this->mock_happy_path( '/some-other-post' );

		$result = Standard_Site::resolve_url( self::PAGE );

		$this->assertIsArray( $result, 'the record still resolves' );
		$this->assertFalse( $result['verified'], 'but it must not be treated as verified' );
	}

	public function test_unverified_result_is_not_stored_on_the_post() {
		$this->mock_happy_path( '/some-other-post' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_cite_url', self::PAGE );

		Standard_Site::resolve_post( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Standard_Site::META_DOCUMENT_URI, true ) );
	}

	public function test_verified_result_is_stored_on_the_post() {
		$this->mock_happy_path();

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_pkiw_cite_url', self::PAGE );

		Standard_Site::resolve_post( $post_id );

		$this->assertSame(
			'at://' . self::DID . '/site.standard.document/3mp3nrm5leyim',
			Standard_Site::get_post_document_uri( $post_id )
		);
	}

	public function test_page_without_link_tag_resolves_to_null() {
		$this->mock_http_raw_response(
			'notes.example.test/a-post',
			'<html><head><title>Nothing here</title></head><body>text</body></html>',
			200,
			[ 'content-type' => 'text/html' ]
		);

		$this->assertNull( Standard_Site::resolve_url( self::PAGE ) );
	}

	public function test_link_tag_outside_head_is_ignored() {
		// An at:// URI quoted in body prose must not be mistaken for a claim.
		$this->mock_http_raw_response(
			'notes.example.test/a-post',
			'<html><head><title>t</title></head><body><link rel="site.standard.document" href="at://' . self::DID . '/site.standard.document/3mp3nrm5leyim"></body></html>',
			200,
			[ 'content-type' => 'text/html' ]
		);

		$this->assertNull( Standard_Site::resolve_url( self::PAGE ) );
	}

	/**
	 * @dataProvider malformed_at_uris
	 *
	 * @param string $uri A value that must not be accepted.
	 */
	public function test_malformed_at_uri_is_rejected( string $uri ) {
		$this->mock_http_raw_response(
			'notes.example.test/a-post',
			'<html><head><link rel="site.standard.document" href="' . esc_attr( $uri ) . '"></head></html>',
			200,
			[ 'content-type' => 'text/html' ]
		);

		$this->assertNull( Standard_Site::resolve_url( self::PAGE ), "accepted: $uri" );
	}

	public function malformed_at_uris(): array {
		return [
			'not an at uri'      => [ 'https://example.test/x' ],
			'no collection'      => [ 'at://' . self::DID ],
			'bad did method'     => [ 'at://did:example:abc/site.standard.document/3mp3nrm5leyim' ],
			'short plc did'      => [ 'at://did:plc:tooshort/site.standard.document/3mp3nrm5leyim' ],
			'wrong collection'   => [ 'at://' . self::DID . '/site.standard.publication/3lwafzkjqm25s' ],
			'path traversal'     => [ 'at://' . self::DID . '/site.standard.document/../../etc' ],
			'query injection'    => [ 'at://' . self::DID . '/site.standard.document/x?evil=1' ],
			'empty'              => [ '' ],
		];
	}

	/**
	 * Regression: the DID was encoded twice, producing did%253Aplc%253A and a
	 * 400 from the PDS. WordPress's add_query_arg() does not encode values, so
	 * pre-encoding then delegating to it worked here and failed elsewhere.
	 */
	public function test_record_request_encodes_the_did_exactly_once() {
		$this->mock_happy_path();

		Standard_Site::resolve_url( self::PAGE );

		$requests = $this->get_recorded_request_urls();
		$record   = '';
		foreach ( $requests as $url ) {
			if ( str_contains( $url, 'com.atproto.repo.getRecord' ) ) {
				$record = $url;
				break;
			}
		}

		$this->assertNotSame( '', $record, 'no getRecord request was made' );
		$this->assertStringNotContainsString( '%253A', $record, 'DID was encoded twice' );
		$this->assertStringContainsString( 'repo=did%3Aplc%3A', $record );
	}

	public function test_resolves_publication_from_well_known() {
		$this->mock_http_raw_response(
			'.well-known/site.standard.publication',
			'at://' . self::DID . '/site.standard.publication/3lwafzkjqm25s',
			200,
			[ 'content-type' => 'text/plain' ]
		);

		$this->mock_http_response(
			'plc.directory',
			[
				'service' => [
					[
						'type'            => 'AtprotoPersonalDataServer',
						'serviceEndpoint' => self::PDS,
					],
				],
			]
		);

		$this->mock_http_response(
			'collection=site.standard.publication',
			[
				'value' => [
					'url'  => 'https://notes.example.test',
					'name' => 'Example Notes',
				],
			]
		);

		$pub = Standard_Site::resolve_publication( 'https://notes.example.test/any/page' );

		$this->assertIsArray( $pub );
		$this->assertSame( 'Example Notes', $pub['record']['name'] );
	}

	public function test_site_without_well_known_resolves_to_null() {
		$this->mock_http_raw_response( '.well-known/site.standard.publication', 'Not Found', 404 );

		$this->assertNull( Standard_Site::resolve_publication( 'https://notes.example.test' ) );
	}

	public function test_http_failure_resolves_to_null() {
		$this->mock_http_error( 'notes.example.test/a-post' );

		$this->assertNull( Standard_Site::resolve_url( self::PAGE ) );
	}

	/**
	 * Misses are cached too. Most of the web is not on AT Protocol, and an
	 * uncached miss means re-fetching the target on every save.
	 */
	public function test_a_miss_is_cached() {
		$this->mock_http_raw_response(
			'notes.example.test/a-post',
			'<html><head><title>none</title></head></html>',
			200,
			[ 'content-type' => 'text/html' ]
		);

		$this->assertNull( Standard_Site::resolve_url( self::PAGE ) );
		$first = count( $this->get_recorded_request_urls() );

		$this->assertNull( Standard_Site::resolve_url( self::PAGE ) );
		$this->assertCount( $first, $this->get_recorded_request_urls(), 'second call refetched' );
	}
}

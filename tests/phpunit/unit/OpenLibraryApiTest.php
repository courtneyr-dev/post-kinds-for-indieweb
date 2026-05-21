<?php
/**
 * Test the OpenLibrary API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\OpenLibrary;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the OpenLibrary API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\OpenLibrary
 */
class OpenLibraryApiTest extends ApiTestCase {

	/**
	 * OpenLibrary instance.
	 *
	 * @var OpenLibrary
	 */
	private OpenLibrary $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new OpenLibrary();
	}

	/**
	 * Test search returns normalized book results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'openlibrary.org/search.json', 'openlibrary/search.json' );

		$results = $this->api->search( 'Dune' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Dune', $results[0]['title'] );
		$this->assertSame( 'OL45883W', $results[0]['ol_work_id'] );
		$this->assertSame( 'book', $results[0]['type'] );
		$this->assertSame( 'openlibrary', $results[0]['source'] );
		$this->assertContains( 'Frank Herbert', $results[0]['authors'] );
		$this->assertSame( 1965, $results[0]['first_publish_year'] );
		$this->assertSame( 150, $results[0]['edition_count'] );
		$this->assertNotNull( $results[0]['cover'] );
		$this->assertStringContainsString( 'covers.openlibrary.org', $results[0]['cover'] );
		$this->assert_api_request_made( 'openlibrary.org' );
	}

	/**
	 * Test search with author filter.
	 */
	public function test_search_with_author_filter(): void {
		$this->mock_http_response( 'openlibrary.org/search.json', 'openlibrary/search.json' );

		$results = $this->api->search( 'Dune', 'Frank Herbert' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Dune', $results[0]['title'] );
	}

	/**
	 * Test search returns empty on no results.
	 */
	public function test_search_returns_empty_on_no_results(): void {
		$this->mock_http_response( 'openlibrary.org/search.json', [ 'numFound' => 0, 'docs' => [] ] );

		$results = $this->api->search( 'nonexistent book xyz' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'openlibrary.org', 'Connection failed' );

		$results = $this->api->search( 'Dune' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_by_id with ISBN dispatches to get_by_isbn.
	 */
	public function test_get_by_id_with_isbn(): void {
		$this->mock_http_response( 'openlibrary.org/api/books', 'openlibrary/books-api-isbn.json' );

		$result = $this->api->get_by_id( '9780441172719' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Dune', $result['title'] );
		$this->assertSame( 'book', $result['type'] );
		$this->assertSame( 'openlibrary', $result['source'] );
	}

	/**
	 * Test get_by_id with work key dispatches to get_work.
	 */
	public function test_get_by_id_with_work_key(): void {
		$this->mock_http_response( 'openlibrary.org/works/OL45883W.json', 'openlibrary/work-detail.json' );
		$this->mock_http_response( 'openlibrary.org/authors/OL34184A.json', 'openlibrary/author-detail.json' );
		$this->mock_http_response( 'openlibrary.org/works/OL45883W/editions.json', 'openlibrary/work-editions.json' );

		$result = $this->api->get_by_id( 'OL45883W' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Dune', $result['title'] );
		$this->assertSame( 'work', $result['type'] );
	}

	/**
	 * Test get_by_isbn via books API.
	 */
	public function test_get_by_isbn_via_books_api(): void {
		$this->mock_http_response( 'openlibrary.org/api/books', 'openlibrary/books-api-isbn.json' );

		$result = $this->api->get_by_isbn( '9780441172719' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Dune', $result['title'] );
		$this->assertContains( 'Frank Herbert', $result['authors'] );
		$this->assertSame( '9780441172719', $result['isbn'] );
	}

	/**
	 * Test get_work returns detailed work data with author and editions.
	 */
	public function test_get_work_returns_detailed_data(): void {
		$this->mock_http_response( 'openlibrary.org/works/OL45883W.json', 'openlibrary/work-detail.json' );
		$this->mock_http_response( 'openlibrary.org/authors/OL34184A.json', 'openlibrary/author-detail.json' );
		$this->mock_http_response( 'openlibrary.org/works/OL45883W/editions.json', 'openlibrary/work-editions.json' );

		$result = $this->api->get_work( 'OL45883W' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Dune', $result['title'] );
		$this->assertSame( 'OL45883W', $result['ol_work_id'] );
		$this->assertSame( 'work', $result['type'] );
		$this->assertNotEmpty( $result['authors'] );
		$this->assertSame( 'Frank Herbert', $result['authors'][0]['name'] );
		$this->assertNotEmpty( $result['editions'] );
		$this->assertNotNull( $result['cover'] );
	}

	/**
	 * Test get_cover_url generates proper URL.
	 */
	public function test_get_cover_url(): void {
		$url = $this->api->get_cover_url( 8232516, 'M' );

		$this->assertStringContainsString( 'covers.openlibrary.org', $url );
		$this->assertStringContainsString( '8232516', $url );
		$this->assertStringContainsString( '-M.jpg', $url );
	}

	/**
	 * Test get_cover_by_isbn generates proper URL.
	 */
	public function test_get_cover_by_isbn(): void {
		$url = $this->api->get_cover_by_isbn( '978-0-441-17271-9', 'L' );

		$this->assertStringContainsString( 'covers.openlibrary.org', $url );
		$this->assertStringContainsString( '9780441172719', $url );
		$this->assertStringContainsString( '-L.jpg', $url );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'openlibrary.org/search.json', 'openlibrary/search.json' );

		$first = $this->api->search( 'Dune' );
		$this->assertCount( 1, $first );

		$api2   = new OpenLibrary();
		$second = $api2->search( 'Dune' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'openlibrary.org/search.json', [ 'numFound' => 1, 'docs' => [] ] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'openlibrary.org', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'openlibrary.org', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

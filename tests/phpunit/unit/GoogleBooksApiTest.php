<?php
/**
 * Test the Google Books API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

// File doesn't match PSR-4 or the plugin autoloader naming (class-google-books vs class-googlebooks).
require_once dirname( __DIR__, 3 ) . '/includes/apis/class-google-books.php';

use PostKindsForIndieWeb\APIs\GoogleBooks;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Google Books API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\GoogleBooks
 */
class GoogleBooksApiTest extends ApiTestCase {

	/**
	 * GoogleBooks instance.
	 *
	 * @var GoogleBooks
	 */
	private GoogleBooks $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new GoogleBooks();
		$this->api->set_api_key( 'test-google-books-key' );
	}

	/**
	 * Test search returns normalized book results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'googleapis.com/books/v1/volumes', 'google-books/search-volumes.json' );

		$results = $this->api->search( 'The Great Gatsby' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'The Great Gatsby', $results[0]['title'] );
		$this->assertSame( 'A Novel', $results[0]['subtitle'] );
		$this->assertSame( 'fiWeKwMFr5YC', $results[0]['id'] );
		$this->assertSame( 'fiWeKwMFr5YC', $results[0]['google_id'] );
		$this->assertSame( 'book', $results[0]['type'] );
		$this->assertSame( 'googlebooks', $results[0]['source'] );
		$this->assertContains( 'F. Scott Fitzgerald', $results[0]['authors'] );
		$this->assertSame( 'Scribner', $results[0]['publisher'] );
		$this->assertSame( '0743273567', $results[0]['isbn_10'] );
		$this->assertSame( '9780743273565', $results[0]['isbn_13'] );
		$this->assertSame( '9780743273565', $results[0]['isbn'] );
		$this->assertSame( 180, $results[0]['page_count'] );
		$this->assertNotNull( $results[0]['cover'] );
		$this->assertStringStartsWith( 'https://', $results[0]['cover'] );
		$this->assert_api_request_made( 'googleapis.com' );
	}

	/**
	 * Test search returns empty on no results.
	 */
	public function test_search_returns_empty_on_no_results(): void {
		$this->mock_http_response( 'googleapis.com/books/v1/volumes', [ 'kind' => 'books#volumes', 'totalItems' => 0 ] );

		$results = $this->api->search( 'nonexistent book xyz' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'googleapis.com', 'Connection failed' );

		$results = $this->api->search( 'The Great Gatsby' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_volume returns detailed data.
	 */
	public function test_get_volume_returns_detailed_data(): void {
		$this->mock_http_response( 'googleapis.com/books/v1/volumes/fiWeKwMFr5YC', 'google-books/volume-detail.json' );

		$result = $this->api->get_volume( 'fiWeKwMFr5YC' );

		$this->assertNotNull( $result );
		$this->assertSame( 'The Great Gatsby', $result['title'] );
		$this->assertSame( 'fiWeKwMFr5YC', $result['id'] );
		$this->assertSame( 180, $result['page_count'] );
		$this->assertSame( 'book', $result['type'] );
		$this->assertSame( 'googlebooks', $result['source'] );
		$this->assertTrue( $result['for_sale'] );
		$this->assertTrue( $result['embeddable'] );
	}

	/**
	 * Test get_by_id delegates to get_volume.
	 */
	public function test_get_by_id_delegates_to_get_volume(): void {
		$this->mock_http_response( 'googleapis.com/books/v1/volumes/fiWeKwMFr5YC', 'google-books/volume-detail.json' );

		$result = $this->api->get_by_id( 'fiWeKwMFr5YC' );

		$this->assertNotNull( $result );
		$this->assertSame( 'The Great Gatsby', $result['title'] );
	}

	/**
	 * Test get_by_isbn returns book data.
	 */
	public function test_get_by_isbn_returns_book_data(): void {
		$this->mock_http_response( 'googleapis.com/books/v1/volumes', 'google-books/search-volumes.json' );

		$result = $this->api->get_by_isbn( '9780743273565' );

		$this->assertNotNull( $result );
		$this->assertSame( 'The Great Gatsby', $result['title'] );
		$this->assertSame( '9780743273565', $result['isbn'] );
	}

	/**
	 * Test get_volume returns null on error.
	 */
	public function test_get_volume_returns_null_on_error(): void {
		$this->mock_http_error( 'googleapis.com', 'Not found' );

		$this->assertNull( $this->api->get_volume( 'nonexistent' ) );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'googleapis.com/books/v1/volumes', 'google-books/search-volumes.json' );

		$first = $this->api->search( 'The Great Gatsby' );
		$this->assertCount( 1, $first );

		$api2 = new GoogleBooks();
		$api2->set_api_key( 'test-google-books-key' );
		$second = $api2->search( 'The Great Gatsby' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'googleapis.com/books/v1/volumes', [ 'kind' => 'books#volumes', 'totalItems' => 1, 'items' => [] ] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'googleapis.com', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test set_api_key changes the key.
	 */
	public function test_set_api_key(): void {
		$api = new GoogleBooks();
		$api->set_api_key( 'new-key-value' );

		$this->mock_http_response( 'googleapis.com/books/v1/volumes', [ 'kind' => 'books#volumes', 'totalItems' => 1, 'items' => [] ] );

		$this->assertTrue( $api->test_connection() );
		$this->assert_api_request_made( 'key=new-key-value' );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'google.com', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

<?php
/**
 * Test the Hardcover API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\Hardcover;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Hardcover API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\Hardcover
 */
class HardcoverApiTest extends ApiTestCase {

	/**
	 * Hardcover instance.
	 *
	 * @var Hardcover
	 */
	private Hardcover $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new Hardcover();
		$this->api->set_token( 'test-hardcover-token' );
	}

	/**
	 * Test search returns normalized book results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'hardcover.app', 'hardcover/search-books.json' );

		$results = $this->api->search( 'Dune' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Dune', $results[0]['title'] );
		$this->assertSame( 12345, $results[0]['id'] );
		$this->assertSame( 12345, $results[0]['hardcover_id'] );
		$this->assertSame( 'dune', $results[0]['slug'] );
		$this->assertSame( 'book', $results[0]['type'] );
		$this->assertSame( 'hardcover', $results[0]['source'] );
		$this->assertSame( 412, $results[0]['pages'] );
		$this->assertNotEmpty( $results[0]['authors'] );
		$this->assertSame( 'Frank Herbert', $results[0]['authors'][0]['name'] );
		$this->assertSame( 'https://assets.hardcover.app/covers/dune.jpg', $results[0]['cover'] );
		$this->assert_api_request_made( 'hardcover.app' );
	}

	/**
	 * Test search returns empty on no results.
	 */
	public function test_search_returns_empty_on_no_results(): void {
		$this->mock_http_response( 'hardcover.app', [ 'data' => [ 'search_books' => [] ] ] );

		$results = $this->api->search( 'nonexistent book xyz' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'hardcover.app', 'Connection failed' );

		$results = $this->api->search( 'Dune' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_book returns detailed data.
	 */
	public function test_get_book_returns_detailed_data(): void {
		$this->mock_http_response( 'hardcover.app', 'hardcover/book-detail.json' );

		$result = $this->api->get_book( '12345' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Dune', $result['title'] );
		$this->assertSame( 12345, $result['id'] );
		$this->assertSame( 'dune', $result['slug'] );
		$this->assertSame( 'book', $result['type'] );
		$this->assertSame( 'hardcover', $result['source'] );
		$this->assertSame( 412, $result['pages'] );
		$this->assertNotEmpty( $result['authors'] );
		$this->assertSame( 'Frank Herbert', $result['authors'][0]['name'] );
		$this->assertSame( 'author', $result['authors'][0]['role'] );
		$this->assertNotEmpty( $result['editions'] );
		$this->assertSame( 5001, $result['editions'][0]['id'] );
		$this->assertSame( '9780441172719', $result['editions'][0]['isbn_13'] );
		$this->assertNotEmpty( $result['genres'] );
		$this->assertContains( 'Science Fiction', $result['genres'] );
		$this->assertSame( 8500, $result['reviews_count'] );
		$this->assertSame( 200, $result['series']['id'] );
		$this->assertSame( 'Dune Saga', $result['series']['name'] );
	}

	/**
	 * Test get_by_id delegates to get_book.
	 */
	public function test_get_by_id_delegates_to_get_book(): void {
		$this->mock_http_response( 'hardcover.app', 'hardcover/book-detail.json' );

		$result = $this->api->get_by_id( '12345' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Dune', $result['title'] );
	}

	/**
	 * Test get_by_isbn returns book data with edition.
	 */
	public function test_get_by_isbn_returns_book_data(): void {
		$this->mock_http_response( 'hardcover.app', 'hardcover/isbn-edition.json' );

		$result = $this->api->get_by_isbn( '9780441172719' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Dune', $result['title'] );
		$this->assertSame( '9780441172719', $result['isbn'] );
		$this->assertSame( 'book', $result['type'] );
		$this->assertSame( 'hardcover', $result['source'] );
		$this->assertNotEmpty( $result['edition'] );
		$this->assertSame( 5001, $result['edition']['id'] );
		$this->assertSame( 'Ace Books', $result['edition']['publisher'] );
	}

	/**
	 * Test get_me returns user data.
	 */
	public function test_get_me_returns_user_data(): void {
		$this->mock_http_response( 'hardcover.app', 'hardcover/me.json' );

		$result = $this->api->get_me();

		$this->assertNotNull( $result );
		$this->assertSame( 999, $result['id'] );
		$this->assertSame( 'booklover', $result['username'] );
		$this->assertSame( 'Book Lover', $result['name'] );
		$this->assertSame( 250, $result['books_count'] );
		$this->assertSame( 'user', $result['type'] );
		$this->assertSame( 'hardcover', $result['source'] );
	}

	/**
	 * Test get_me returns null when not authenticated.
	 */
	public function test_get_me_returns_null_when_not_authenticated(): void {
		$api = new Hardcover();

		$this->assertNull( $api->get_me() );
	}

	/**
	 * Test get_book returns null on error.
	 */
	public function test_get_book_returns_null_on_error(): void {
		$this->mock_http_error( 'hardcover.app', 'Not found' );

		$this->assertNull( $this->api->get_book( 'nonexistent' ) );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'hardcover.app', 'hardcover/search-books.json' );

		$first = $this->api->search( 'Dune' );
		$this->assertCount( 1, $first );

		$api2 = new Hardcover();
		$api2->set_token( 'test-hardcover-token' );
		$second = $api2->search( 'Dune' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'hardcover.app', 'hardcover/test-connection.json' );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'hardcover.app', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails without token.
	 */
	public function test_test_connection_fails_without_token(): void {
		$api = new Hardcover();

		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test is_configured returns false without token.
	 */
	public function test_is_configured_false_without_token(): void {
		$api = new Hardcover();

		$this->assertFalse( $api->is_configured() );
	}

	/**
	 * Test is_configured returns true with token.
	 */
	public function test_is_configured_true_with_token(): void {
		$this->assertTrue( $this->api->is_configured() );
	}

	/**
	 * Test set_token changes the token.
	 */
	public function test_set_token(): void {
		$api = new Hardcover();
		$api->set_token( 'new-token-value' );

		$this->assertTrue( $api->is_configured() );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'hardcover.app', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

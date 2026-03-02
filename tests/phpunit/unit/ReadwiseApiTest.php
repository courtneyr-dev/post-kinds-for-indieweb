<?php
/**
 * Test the Readwise API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\Readwise;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Readwise API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\Readwise
 */
class ReadwiseApiTest extends ApiTestCase {

	/**
	 * Readwise instance.
	 *
	 * @var Readwise
	 */
	private Readwise $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			'post_kinds_indieweb_api_credentials',
			[ 'readwise' => [ 'access_token' => 'test-readwise-token' ] ]
		);
		$this->api = new Readwise();
	}

	/**
	 * Test get_books returns normalized book data.
	 */
	public function test_get_books_returns_normalized_data(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/books.json' );

		$books = $this->api->get_books();

		$this->assertCount( 1, $books );
		$this->assertSame( 12345, $books[0]['id'] );
		$this->assertSame( 'Atomic Habits', $books[0]['title'] );
		$this->assertSame( 'James Clear', $books[0]['author'] );
		$this->assertSame( 'books', $books[0]['category'] );
		$this->assertSame( 'kindle', $books[0]['source'] );
		$this->assertSame( 42, $books[0]['highlight_count'] );
		$this->assertSame( 'B07D23CFGR', $books[0]['asin'] );
		$this->assertNotEmpty( $books[0]['cover_image'] );
		$this->assertNotEmpty( $books[0]['source_url'] );
	}

	/**
	 * Test get_books returns empty when not configured.
	 */
	public function test_get_books_returns_empty_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new Readwise();

		$books = $api->get_books();

		$this->assertSame( [], $books );
	}

	/**
	 * Test get_books with category filter.
	 */
	public function test_get_books_with_category_filter(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/books.json' );

		$books = $this->api->get_books( 'books' );

		$this->assertCount( 1, $books );
		$this->assert_api_request_made( 'readwise.io' );
	}

	/**
	 * Test get_books returns empty on error.
	 */
	public function test_get_books_returns_empty_on_error(): void {
		$this->mock_http_error( 'readwise.io', 'Connection failed' );

		$books = $this->api->get_books();

		$this->assertSame( [], $books );
	}

	/**
	 * Test get_highlights returns normalized highlights.
	 */
	public function test_get_highlights_returns_normalized_data(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/highlights.json' );

		$highlights = $this->api->get_highlights();

		$this->assertCount( 1, $highlights );
		$this->assertSame( 67890, $highlights[0]['id'] );
		$this->assertStringContainsString( 'Every action you take', $highlights[0]['text'] );
		$this->assertSame( 'yellow', $highlights[0]['color'] );
		$this->assertSame( 1234, $highlights[0]['location'] );
		$this->assertSame( 12345, $highlights[0]['book_id'] );
		$this->assertSame( 'Atomic Habits', $highlights[0]['book']['title'] );
	}

	/**
	 * Test get_highlights with book_id filter.
	 */
	public function test_get_highlights_with_book_id(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/highlights.json' );

		$highlights = $this->api->get_highlights( 12345 );

		$this->assertCount( 1, $highlights );
	}

	/**
	 * Test get_highlights returns empty when not configured.
	 */
	public function test_get_highlights_returns_empty_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new Readwise();

		$this->assertSame( [], $api->get_highlights() );
	}

	/**
	 * Test get_articles delegates to get_books with articles category.
	 */
	public function test_get_articles_delegates_to_get_books(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/books.json' );

		$articles = $this->api->get_articles();

		$this->assertCount( 1, $articles );
		$this->assert_api_request_made( 'readwise.io' );
	}

	/**
	 * Test get_tweets delegates to get_books with tweets category.
	 */
	public function test_get_tweets_delegates_to_get_books(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/books.json' );

		$tweets = $this->api->get_tweets();

		$this->assertCount( 1, $tweets );
	}

	/**
	 * Test get_book_highlights delegates to get_books with books category.
	 */
	public function test_get_book_highlights_delegates_to_get_books(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/books.json' );

		$books = $this->api->get_book_highlights();

		$this->assertCount( 1, $books );
	}

	/**
	 * Test get_podcast_episodes returns podcast data.
	 */
	public function test_get_podcast_episodes_returns_data(): void {
		// First call gets books with podcasts category, second gets highlights.
		$this->mock_http_response( 'readwise.io', 'readwise/podcasts.json' );

		$episodes = $this->api->get_podcast_episodes( 100, false );

		$this->assertCount( 1, $episodes );
		$this->assertSame( 99999, $episodes[0]['id'] );
		$this->assertStringContainsString( 'Tim Ferriss', $episodes[0]['show_name'] );
		$this->assertSame( 3, $episodes[0]['highlight_count'] );
	}

	/**
	 * Test get_by_id returns book data.
	 */
	public function test_get_by_id_returns_book(): void {
		$this->mock_http_response( 'readwise.io', [
			'id'               => 12345,
			'title'            => 'Atomic Habits',
			'author'           => 'James Clear',
			'category'         => 'books',
			'source'           => 'kindle',
			'source_url'       => 'https://www.amazon.com/dp/B07D23CFGR',
			'cover_image_url'  => 'https://readwise-assets.s3.amazonaws.com/atomic-habits.jpg',
			'num_highlights'   => 42,
			'last_highlight_at' => '2024-06-15T10:30:00Z',
			'updated'          => '2024-06-15T12:00:00Z',
			'asin'             => 'B07D23CFGR',
			'tags'             => [],
			'document_note'    => '',
		] );

		$result = $this->api->get_by_id( '12345' );

		$this->assertNotNull( $result );
		$this->assertSame( 12345, $result['id'] );
		$this->assertSame( 'Atomic Habits', $result['title'] );
	}

	/**
	 * Test get_by_id returns null when not configured.
	 */
	public function test_get_by_id_returns_null_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new Readwise();

		$this->assertNull( $api->get_by_id( '12345' ) );
	}

	/**
	 * Test get_by_id returns null on error.
	 */
	public function test_get_by_id_returns_null_on_error(): void {
		$this->mock_http_error( 'readwise.io', 'Not found' );

		$this->assertNull( $this->api->get_by_id( '99999' ) );
	}

	/**
	 * Test search filters books by title and author.
	 */
	public function test_search_filters_by_title_and_author(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/books.json' );

		$results = $this->api->search( 'Atomic' );

		$this->assertCount( 1, $results );
	}

	/**
	 * Test search returns empty for non-matching query.
	 */
	public function test_search_returns_empty_for_non_matching(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/books.json' );

		$results = $this->api->search( 'xyznonexistent' );

		$this->assertEmpty( $results );
	}

	/**
	 * Test is_configured returns true with token.
	 */
	public function test_is_configured_returns_true_with_token(): void {
		$this->assertTrue( $this->api->is_configured() );
	}

	/**
	 * Test is_configured returns false without token.
	 */
	public function test_is_configured_returns_false_without_token(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new Readwise();

		$this->assertFalse( $api->is_configured() );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'readwise.io', 'readwise/auth.json' );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails without config.
	 */
	public function test_test_connection_fails_without_config(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new Readwise();

		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test test_connection fails on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'readwise.io', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test SOURCE_CATEGORIES constant.
	 */
	public function test_source_categories_constant(): void {
		$this->assertArrayHasKey( 'books', Readwise::SOURCE_CATEGORIES );
		$this->assertArrayHasKey( 'articles', Readwise::SOURCE_CATEGORIES );
		$this->assertArrayHasKey( 'tweets', Readwise::SOURCE_CATEGORIES );
		$this->assertArrayHasKey( 'podcasts', Readwise::SOURCE_CATEGORIES );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'readwise', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

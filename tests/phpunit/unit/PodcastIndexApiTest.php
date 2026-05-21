<?php
/**
 * Test the PodcastIndex API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\PodcastIndex;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the PodcastIndex API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\PodcastIndex
 */
class PodcastIndexApiTest extends ApiTestCase {

	/**
	 * PodcastIndex instance.
	 *
	 * @var PodcastIndex
	 */
	private PodcastIndex $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new PodcastIndex();
		$this->api->set_credentials( 'test-api-key', 'test-api-secret' );
	}

	/**
	 * Test search returns normalized podcast results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'podcastindex.org', 'podcastindex/search-byterm.json' );

		$results = $this->api->search( 'Joe Rogan' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'The Joe Rogan Experience', $results[0]['title'] );
		$this->assertSame( 75075, $results[0]['id'] );
		$this->assertSame( 75075, $results[0]['podcastindex_id'] );
		$this->assertSame( 'podcast', $results[0]['type'] );
		$this->assertSame( 'podcastindex', $results[0]['source'] );
		$this->assertSame( 'Joe Rogan', $results[0]['author'] );
		$this->assertSame( 'en', $results[0]['language'] );
		$this->assertSame( 360084272, $results[0]['itunes_id'] );
		$this->assertSame( 2000, $results[0]['episode_count'] );
		$this->assertNotEmpty( $results[0]['image'] );
		$this->assertNotEmpty( $results[0]['artwork'] );
		$this->assertNotEmpty( $results[0]['categories'] );
		$this->assertContains( 'Comedy', $results[0]['categories'] );
		$this->assert_api_request_made( 'podcastindex.org' );
	}

	/**
	 * Test search returns empty on no results.
	 */
	public function test_search_returns_empty_on_no_results(): void {
		$this->mock_http_response( 'podcastindex.org', [ 'status' => 'true', 'feeds' => [], 'count' => 0 ] );

		$results = $this->api->search( 'nonexistent podcast xyz' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'podcastindex.org', 'Connection failed' );

		$results = $this->api->search( 'Joe Rogan' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search_by_title returns results.
	 */
	public function test_search_by_title_returns_results(): void {
		$this->mock_http_response( 'podcastindex.org', 'podcastindex/search-bytitle.json' );

		$results = $this->api->search_by_title( 'Joe Rogan' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'The Joe Rogan Experience', $results[0]['title'] );
		$this->assertSame( 'podcast', $results[0]['type'] );
	}

	/**
	 * Test get_podcast returns detailed data.
	 */
	public function test_get_podcast_returns_detailed_data(): void {
		$this->mock_http_response( 'podcastindex.org', 'podcastindex/podcast-detail.json' );

		$result = $this->api->get_podcast( 75075 );

		$this->assertNotNull( $result );
		$this->assertSame( 'The Joe Rogan Experience', $result['title'] );
		$this->assertSame( 75075, $result['id'] );
		$this->assertSame( 'podcast', $result['type'] );
		$this->assertSame( 'podcastindex', $result['source'] );
		$this->assertSame( 2000, $result['episode_count'] );
		$this->assertArrayHasKey( 'last_update_time', $result );
		$this->assertArrayHasKey( 'last_crawl_time', $result );
		$this->assertArrayHasKey( 'content_type', $result );
	}

	/**
	 * Test get_by_id delegates to get_podcast.
	 */
	public function test_get_by_id_delegates_to_get_podcast(): void {
		$this->mock_http_response( 'podcastindex.org', 'podcastindex/podcast-detail.json' );

		$result = $this->api->get_by_id( '75075' );

		$this->assertNotNull( $result );
		$this->assertSame( 'The Joe Rogan Experience', $result['title'] );
	}

	/**
	 * Test get_episodes returns episode list.
	 */
	public function test_get_episodes_returns_episode_list(): void {
		$this->mock_http_response( 'podcastindex.org', 'podcastindex/episodes.json' );

		$episodes = $this->api->get_episodes( 75075 );

		$this->assertCount( 1, $episodes );
		$this->assertSame( 'Episode 2100 - Guest Name', $episodes[0]['title'] );
		$this->assertSame( 123456789, $episodes[0]['id'] );
		$this->assertSame( 'episode', $episodes[0]['type'] );
		$this->assertSame( 'podcastindex', $episodes[0]['source'] );
		$this->assertSame( 7200, $episodes[0]['duration'] );
		$this->assertSame( 2100, $episodes[0]['episode'] );
		$this->assertSame( 75075, $episodes[0]['feed_id'] );
		$this->assertNotEmpty( $episodes[0]['enclosure_url'] );
	}

	/**
	 * Test get_episode returns detailed episode data.
	 */
	public function test_get_episode_returns_detailed_data(): void {
		$this->mock_http_response( 'podcastindex.org', 'podcastindex/episode-detail.json' );

		$result = $this->api->get_episode( 123456789 );

		$this->assertNotNull( $result );
		$this->assertSame( 'Episode 2100 - Guest Name', $result['title'] );
		$this->assertSame( 123456789, $result['id'] );
		$this->assertSame( 'episode', $result['type'] );
		$this->assertArrayHasKey( 'chapters_url', $result );
		$this->assertArrayHasKey( 'transcript_url', $result );
	}

	/**
	 * Test get_podcast returns null on error.
	 */
	public function test_get_podcast_returns_null_on_error(): void {
		$this->mock_http_error( 'podcastindex.org', 'Not found' );

		$this->assertNull( $this->api->get_podcast( 99999 ) );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'podcastindex.org', 'podcastindex/search-byterm.json' );

		$first = $this->api->search( 'Joe Rogan' );
		$this->assertCount( 1, $first );

		$api2 = new PodcastIndex();
		$api2->set_credentials( 'test-api-key', 'test-api-secret' );
		$second = $api2->search( 'Joe Rogan' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'podcastindex.org', [ 'status' => 'true', 'feeds' => [], 'count' => 0 ] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'podcastindex.org', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails without credentials.
	 */
	public function test_test_connection_fails_without_credentials(): void {
		$api = new PodcastIndex();

		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test set_credentials changes key and secret.
	 */
	public function test_set_credentials(): void {
		$api = new PodcastIndex();
		$api->set_credentials( 'new-key', 'new-secret' );

		$this->mock_http_response( 'podcastindex.org', [ 'status' => 'true', 'feeds' => [], 'count' => 0 ] );

		$this->assertTrue( $api->test_connection() );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'podcastindex', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

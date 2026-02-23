<?php
/**
 * Test the TVmaze API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\TVmaze;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the TVmaze API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\TVmaze
 */
class TVmazeApiTest extends ApiTestCase {

	/**
	 * TVmaze instance.
	 *
	 * @var TVmaze
	 */
	private TVmaze $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new TVmaze();
	}

	/**
	 * Test search returns normalized show results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'api.tvmaze.com/search/shows', 'tvmaze/search-shows.json' );

		$results = $this->api->search( 'Breaking Bad' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Breaking Bad', $results[0]['title'] );
		$this->assertSame( 169, $results[0]['id'] );
		$this->assertSame( 169, $results[0]['tvmaze_id'] );
		$this->assertSame( 'tv', $results[0]['type'] );
		$this->assertSame( 'tvmaze', $results[0]['source'] );
		$this->assertSame( 'English', $results[0]['language'] );
		$this->assertContains( 'Drama', $results[0]['genres'] );
		$this->assertSame( 'Ended', $results[0]['status'] );
		$this->assertSame( 9.2, $results[0]['rating'] );
		$this->assertSame( 'AMC', $results[0]['network'] );
		$this->assertSame( 0.9, $results[0]['score'] );
		$this->assert_api_request_made( 'api.tvmaze.com' );
	}

	/**
	 * Test search returns empty array on empty results.
	 */
	public function test_search_returns_empty_on_no_results(): void {
		$this->mock_http_response( 'api.tvmaze.com/search/shows', [] );

		$results = $this->api->search( 'nonexistent show xyz' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on HTTP error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.tvmaze.com', 'Connection failed' );

		$results = $this->api->search( 'Breaking Bad' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_show returns normalized show data.
	 */
	public function test_get_show_returns_normalized_data(): void {
		$this->mock_http_response( 'api.tvmaze.com/shows/169', 'tvmaze/show-detail.json' );

		$result = $this->api->get_show( 169 );

		$this->assertNotNull( $result );
		$this->assertSame( 'Breaking Bad', $result['title'] );
		$this->assertSame( 169, $result['id'] );
		$this->assertSame( 60, $result['runtime'] );
		$this->assertSame( '2008-01-20', $result['premiered'] );
		$this->assertSame( '2013-09-29', $result['ended'] );
		$this->assertSame( 'https://www.amc.com/shows/breaking-bad', $result['official_site'] );
		$this->assertSame( 'tv', $result['type'] );
		$this->assertSame( 'tvmaze', $result['source'] );
	}

	/**
	 * Test get_by_id delegates to get_show.
	 */
	public function test_get_by_id_delegates_to_get_show(): void {
		$this->mock_http_response( 'api.tvmaze.com/shows/169', 'tvmaze/show-detail.json' );

		$result = $this->api->get_by_id( '169' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Breaking Bad', $result['title'] );
	}

	/**
	 * Test lookup by external ID returns show data.
	 */
	public function test_lookup_returns_show_data(): void {
		$this->mock_http_response( 'api.tvmaze.com/lookup/shows', 'tvmaze/lookup-show.json' );

		$result = $this->api->lookup( 'imdb', 'tt0903747' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Breaking Bad', $result['title'] );
		$this->assertSame( 169, $result['id'] );
		$this->assertSame( 'tvmaze', $result['source'] );
	}

	/**
	 * Test get_episodes returns normalized episodes.
	 */
	public function test_get_episodes_returns_normalized_episodes(): void {
		$this->mock_http_response( 'api.tvmaze.com/shows/169/episodes', 'tvmaze/episodes.json' );

		$episodes = $this->api->get_episodes( 169 );

		$this->assertCount( 2, $episodes );
		$this->assertSame( 'Pilot', $episodes[0]['title'] );
		$this->assertSame( 1, $episodes[0]['season'] );
		$this->assertSame( 1, $episodes[0]['number'] );
		$this->assertSame( 'episode', $episodes[0]['content_type'] );
		$this->assertSame( 'tvmaze', $episodes[0]['source'] );
	}

	/**
	 * Test single_search returns best match.
	 */
	public function test_single_search_returns_best_match(): void {
		$this->mock_http_response( 'api.tvmaze.com/singlesearch/shows', 'tvmaze/single-search.json' );

		$result = $this->api->single_search( 'Breaking Bad' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Breaking Bad', $result['title'] );
		$this->assertSame( 169, $result['id'] );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'api.tvmaze.com/search/shows', 'tvmaze/search-shows.json' );

		$first = $this->api->search( 'Breaking Bad' );
		$this->assertCount( 1, $first );

		$api2   = new TVmaze();
		$second = $api2->search( 'Breaking Bad' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test test_connection returns true on success.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'api.tvmaze.com/shows/1', 'tvmaze/show-detail.json' );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection returns false on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'api.tvmaze.com', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test is_configured returns false without API key (free tier).
	 */
	public function test_is_configured_false_without_key(): void {
		$this->assertFalse( $this->api->is_configured() );
	}

	/**
	 * Test is_configured returns true with premium API key.
	 */
	public function test_is_configured_true_with_key(): void {
		update_option(
			'post_kinds_indieweb_api_credentials',
			[ 'tvmaze' => [ 'api_key' => 'test-premium-key' ] ]
		);

		$api = new TVmaze();

		$this->assertTrue( $api->is_configured() );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'tvmaze.com', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

<?php
/**
 * Test the Trakt API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\Trakt;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Trakt API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\Trakt
 */
class TraktApiTest extends ApiTestCase {

	/**
	 * Trakt instance.
	 *
	 * @var Trakt
	 */
	private Trakt $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			'post_kinds_indieweb_api_credentials',
			[
				'trakt' => [
					'client_id'    => 'test-client-id-123',
					'access_token' => 'test-access-token-456',
				],
			]
		);
		$this->api = new Trakt();
	}

	/**
	 * Test is_configured returns true with credentials.
	 */
	public function test_is_configured_with_credentials(): void {
		$this->assertTrue( $this->api->is_configured() );
	}

	/**
	 * Test is_configured returns false without credentials.
	 */
	public function test_is_configured_without_credentials(): void {
		delete_option( 'post_kinds_indieweb_api_credentials' );
		$api = new Trakt();
		$this->assertFalse( $api->is_configured() );
	}

	/**
	 * Test search returns normalized movie results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'api.trakt.tv/search/', 'trakt/search-movie.json' );

		$results = $this->api->search( 'Inception' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Inception', $results[0]['title'] );
		$this->assertSame( 2010, $results[0]['year'] );
		$this->assertSame( 16662, $results[0]['trakt_id'] );
		$this->assertSame( 'tt1375666', $results[0]['imdb_id'] );
		$this->assertSame( 27205, $results[0]['tmdb_id'] );
		$this->assertSame( 'movie', $results[0]['type'] );
		$this->assertSame( 'trakt', $results[0]['source'] );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_handles_error(): void {
		$this->mock_http_error( 'api.trakt.tv' );

		$results = $this->api->search( 'Inception' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'api.trakt.tv/search/', 'trakt/search-movie.json' );

		$first = $this->api->search( 'Inception' );
		$this->assertCount( 1, $first );

		$api2   = new Trakt();
		$second = $api2->search( 'Inception' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test get_movie returns normalized movie data.
	 */
	public function test_get_movie_returns_data(): void {
		$this->mock_http_response( 'api.trakt.tv/movies/inception', 'trakt/movie-detail.json' );

		$result = $this->api->get_movie( 'inception-2010' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Inception', $result['title'] );
		$this->assertSame( 2010, $result['year'] );
		$this->assertSame( 148, $result['runtime'] );
		$this->assertSame( 'tt1375666', $result['imdb_id'] );
		$this->assertSame( 'PG-13', $result['certification'] );
		$this->assertSame( 'movie', $result['type'] );
		$this->assertSame( 'trakt', $result['source'] );
	}

	/**
	 * Test get_movie returns null on error.
	 */
	public function test_get_movie_returns_null_on_error(): void {
		$this->mock_http_error( 'api.trakt.tv' );

		$this->assertNull( $this->api->get_movie( 'nonexistent' ) );
	}

	/**
	 * Test get_show returns normalized show data.
	 */
	public function test_get_show_returns_data(): void {
		$this->mock_http_response( 'api.trakt.tv/shows/breaking-bad', 'trakt/show-detail.json' );

		$result = $this->api->get_show( 'breaking-bad' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Breaking Bad', $result['title'] );
		$this->assertSame( 2008, $result['year'] );
		$this->assertSame( 'AMC', $result['network'] );
		$this->assertSame( 'ended', $result['status'] );
		$this->assertSame( 62, $result['aired_episodes'] );
		$this->assertSame( 'tv', $result['type'] );
		$this->assertSame( 'trakt', $result['source'] );
	}

	/**
	 * Test get_by_id with "movie:inception-2010" format.
	 */
	public function test_get_by_id_movie_format(): void {
		$this->mock_http_response( 'api.trakt.tv/movies/inception', 'trakt/movie-detail.json' );

		$result = $this->api->get_by_id( 'movie:inception-2010' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Inception', $result['title'] );
	}

	/**
	 * Test get_by_id with "show:breaking-bad" format.
	 */
	public function test_get_by_id_show_format(): void {
		$this->mock_http_response( 'api.trakt.tv/shows/breaking-bad', 'trakt/show-detail.json' );

		$result = $this->api->get_by_id( 'show:breaking-bad' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Breaking Bad', $result['title'] );
	}

	/**
	 * Test get_by_id returns null for invalid format.
	 */
	public function test_get_by_id_invalid_format(): void {
		$this->assertNull( $this->api->get_by_id( 'no-colon' ) );
	}

	/**
	 * Test test_connection success.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'api.trakt.tv/users/settings', [ 'user' => [ 'username' => 'testuser' ] ] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection falls back to trending endpoint.
	 */
	public function test_test_connection_fallback(): void {
		$this->mock_http_response( 'api.trakt.tv/users/settings', [ 'error' => 'Unauthorized' ], 401 );
		$this->mock_http_response( 'api.trakt.tv/movies/trending', [] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection returns false without client ID.
	 */
	public function test_test_connection_without_client_id(): void {
		delete_option( 'post_kinds_indieweb_api_credentials' );
		$api = new Trakt();
		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test docs URL contains trakt.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'trakt', $this->api->get_docs_url() );
	}
}

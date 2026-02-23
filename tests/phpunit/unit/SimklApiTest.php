<?php
/**
 * Test the Simkl API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\Simkl;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Simkl API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\Simkl
 */
class SimklApiTest extends ApiTestCase {

	/**
	 * Simkl instance.
	 *
	 * @var Simkl
	 */
	private Simkl $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			'post_kinds_indieweb_api_credentials',
			[
				'simkl' => [
					'client_id'    => 'test-client-id-123',
					'access_token' => 'test-access-token-456',
				],
			]
		);
		$this->api = new Simkl();
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
		$api = new Simkl();
		$this->assertFalse( $api->is_configured() );
	}

	/**
	 * Test search returns normalized results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'api.simkl.com/search/', 'simkl/search-movie.json' );

		$results = $this->api->search( 'The Matrix' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'The Matrix', $results[0]['title'] );
		$this->assertSame( 1999, $results[0]['year'] );
		$this->assertSame( 38612, $results[0]['simkl_id'] );
		$this->assertSame( 'tt0133093', $results[0]['imdb_id'] );
		$this->assertSame( 603, $results[0]['tmdb_id'] );
		$this->assertSame( 'movie', $results[0]['type'] );
		$this->assertSame( 'simkl', $results[0]['source'] );
		$this->assertNotNull( $results[0]['poster'] );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_handles_error(): void {
		$this->mock_http_error( 'api.simkl.com' );

		$results = $this->api->search( 'The Matrix' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'api.simkl.com/search/', 'simkl/search-movie.json' );

		$first = $this->api->search( 'The Matrix' );
		$this->assertCount( 1, $first );

		$api2   = new Simkl();
		$second = $api2->search( 'The Matrix' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test get_movie returns normalized movie data.
	 */
	public function test_get_movie_returns_data(): void {
		$this->mock_http_response( 'api.simkl.com/movies/38612', 'simkl/movie-detail.json' );

		$result = $this->api->get_movie( 38612 );

		$this->assertNotNull( $result );
		$this->assertSame( 'The Matrix', $result['title'] );
		$this->assertSame( 1999, $result['year'] );
		$this->assertSame( 38612, $result['simkl_id'] );
		$this->assertSame( 136, $result['runtime'] );
		$this->assertSame( 'movie', $result['type'] );
		$this->assertSame( 'simkl', $result['source'] );
	}

	/**
	 * Test get_movie returns null on error.
	 */
	public function test_get_movie_returns_null_on_error(): void {
		$this->mock_http_error( 'api.simkl.com' );

		$this->assertNull( $this->api->get_movie( 999999 ) );
	}

	/**
	 * Test get_by_id with "movie:38612" format.
	 */
	public function test_get_by_id_movie_format(): void {
		$this->mock_http_response( 'api.simkl.com/movies/38612', 'simkl/movie-detail.json' );

		$result = $this->api->get_by_id( 'movie:38612' );

		$this->assertNotNull( $result );
		$this->assertSame( 'The Matrix', $result['title'] );
	}

	/**
	 * Test get_by_id returns null for invalid format.
	 */
	public function test_get_by_id_invalid_format(): void {
		$this->assertNull( $this->api->get_by_id( 'no-colon' ) );
	}

	/**
	 * Test get_movie caches results.
	 */
	public function test_get_movie_caches_results(): void {
		$this->mock_http_response( 'api.simkl.com/movies/38612', 'simkl/movie-detail.json' );

		$first = $this->api->get_movie( 38612 );
		$this->assertNotNull( $first );

		$api2   = new Simkl();
		$second = $api2->get_movie( 38612 );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test test_connection success.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'api.simkl.com/search/movie', [] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection returns false without client ID.
	 */
	public function test_test_connection_without_client_id(): void {
		delete_option( 'post_kinds_indieweb_api_credentials' );
		$api = new Simkl();
		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test test_connection returns false on network error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'api.simkl.com' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test docs URL contains simkl.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'simkl', $this->api->get_docs_url() );
	}
}

<?php
/**
 * Test the TMDB API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\TMDB;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the TMDB API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\TMDB
 */
class TMDBApiTest extends ApiTestCase {

	/**
	 * TMDB instance.
	 *
	 * @var TMDB
	 */
	private TMDB $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new TMDB();
		$this->api->set_api_key( 'test-tmdb-api-key' );
	}

	/**
	 * Test search returns normalized movie results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/search/multi', 'tmdb/search-multi.json' );

		$results = $this->api->search( 'Fight Club' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Fight Club', $results[0]['title'] );
		$this->assertSame( 550, $results[0]['id'] );
		$this->assertSame( '1999', $results[0]['year'] );
		$this->assertSame( 'movie', $results[0]['type'] );
		$this->assertSame( 'tmdb', $results[0]['source'] );
		$this->assertSame( 8.4, $results[0]['vote_average'] );
		$this->assertNotNull( $results[0]['poster'] );
		$this->assert_api_request_made( 'api.themoviedb.org' );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_handles_error(): void {
		$this->mock_http_error( 'api.themoviedb.org' );

		$results = $this->api->search( 'Fight Club' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/search/multi', 'tmdb/search-multi.json' );

		$first = $this->api->search( 'Fight Club' );
		$this->assertCount( 1, $first );

		$api2 = new TMDB();
		$api2->set_api_key( 'test-tmdb-api-key' );
		$second = $api2->search( 'Fight Club' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test search_movies returns movie results.
	 */
	public function test_search_movies(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/search/movie', 'tmdb/search-movie.json' );

		$results = $this->api->search_movies( 'Fight Club' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Fight Club', $results[0]['title'] );
		$this->assertSame( 'movie', $results[0]['type'] );
	}

	/**
	 * Test get_movie returns detailed movie data.
	 */
	public function test_get_movie_returns_detailed_data(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/movie/550', 'tmdb/movie-detail.json' );

		$result = $this->api->get_movie( 550 );

		$this->assertNotNull( $result );
		$this->assertSame( 'Fight Club', $result['title'] );
		$this->assertSame( 550, $result['id'] );
		$this->assertSame( 139, $result['runtime'] );
		$this->assertSame( 'Mischief. Mayhem. Soap.', $result['tagline'] );
		$this->assertSame( 'Released', $result['status'] );
		$this->assertContains( 'Drama', $result['genres'] );
		$this->assertContains( 'Thriller', $result['genres'] );
		$this->assertSame( 'David Fincher', $result['director'] );
		$this->assertSame( 'tt0137523', $result['imdb_id'] );
		$this->assertNotNull( $result['trailer'] );
		$this->assertSame( 'SUXWAEX2jlg', $result['trailer']['key'] );
		$this->assertSame( 'movie', $result['type'] );
		$this->assertSame( 'tmdb', $result['source'] );
	}

	/**
	 * Test get_movie returns null on error.
	 */
	public function test_get_movie_returns_null_on_error(): void {
		$this->mock_http_error( 'api.themoviedb.org' );

		$this->assertNull( $this->api->get_movie( 999999 ) );
	}

	/**
	 * Test get_by_id with "movie:550" format.
	 */
	public function test_get_by_id_movie_format(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/movie/550', 'tmdb/movie-detail.json' );

		$result = $this->api->get_by_id( 'movie:550' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Fight Club', $result['title'] );
	}

	/**
	 * Test get_by_id without prefix assumes movie.
	 */
	public function test_get_by_id_plain_id(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/movie/550', 'tmdb/movie-detail.json' );

		$result = $this->api->get_by_id( '550' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Fight Club', $result['title'] );
	}

	/**
	 * Test test_connection succeeds when API responds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/configuration', [ 'images' => [] ] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection throws exception without credentials.
	 */
	public function test_test_connection_throws_without_credentials(): void {
		$api = new TMDB();
		// No API key or access token set.

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'No API credentials loaded' );
		$api->test_connection();
	}

	/**
	 * Test test_connection throws exception on API failure.
	 */
	public function test_test_connection_throws_on_api_failure(): void {
		$this->mock_http_error( 'api.themoviedb.org' );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'API request failed' );
		$this->api->test_connection();
	}

	/**
	 * Test get_movie caches results.
	 */
	public function test_get_movie_caches_results(): void {
		$this->mock_http_response( 'api.themoviedb.org/3/movie/550', 'tmdb/movie-detail.json' );

		$first = $this->api->get_movie( 550 );
		$this->assertNotNull( $first );

		$api2 = new TMDB();
		$api2->set_api_key( 'test-tmdb-api-key' );
		$second = $api2->get_movie( 550 );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test docs URL contains themoviedb.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'themoviedb.org', $this->api->get_docs_url() );
	}
}

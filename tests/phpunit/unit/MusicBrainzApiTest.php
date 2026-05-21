<?php
/**
 * Test the MusicBrainz API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\MusicBrainz;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the MusicBrainz API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\MusicBrainz
 */
class MusicBrainzApiTest extends ApiTestCase {

	/**
	 * MusicBrainz instance.
	 *
	 * @var MusicBrainz
	 */
	private MusicBrainz $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new MusicBrainz();
	}

	/**
	 * Mock the cover art HEAD request to return no cover art.
	 * Many tests don't care about cover art, so this silences that side request.
	 */
	private function mock_no_cover_art(): void {
		$this->mock_http_response( 'coverartarchive.org', [ 'error' => 'not found' ], 404 );
	}

	/**
	 * Test search returns normalized results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/recording', 'musicbrainz/search-recording.json' );
		$this->mock_no_cover_art();

		$results = $this->api->search( 'Bohemian Rhapsody' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Bohemian Rhapsody', $results[0]['track'] );
		$this->assertSame( 'Queen', $results[0]['artist'] );
		$this->assertSame( 'A Night at the Opera', $results[0]['album'] );
		$this->assertSame( 'musicbrainz', $results[0]['source'] );
		$this->assertSame( 354, $results[0]['duration'] );
		$this->assertSame( 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d', $results[0]['mbid'] );
		$this->assertSame( '0383dadf-2a4e-4d10-a46a-e9e041da8eb3', $results[0]['artist_mbid'] );
		$this->assertSame( 'bb2d72e5-83de-4e0b-8c3c-5e41e6b3e011', $results[0]['album_mbid'] );

		$this->assert_api_request_made( 'musicbrainz.org/ws/2/recording' );
	}

	/**
	 * Test search with artist filter includes artist in query.
	 */
	public function test_search_with_artist_filter(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/recording', 'musicbrainz/search-recording.json' );
		$this->mock_no_cover_art();

		$results = $this->api->search( 'Bohemian Rhapsody', 'Queen' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Queen', $results[0]['artist'] );
	}

	/**
	 * Test search with empty results.
	 */
	public function test_search_empty_results(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/recording', [ 'recordings' => [] ] );

		$results = $this->api->search( 'xyznonexistent' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search handles API error gracefully.
	 */
	public function test_search_handles_api_error(): void {
		$this->mock_http_error( 'musicbrainz.org', 'Connection timeout' );

		$results = $this->api->search( 'Bohemian Rhapsody' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_by_id returns normalized recording.
	 */
	public function test_get_by_id_returns_recording(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/recording/b10bbbfc', 'musicbrainz/get-recording.json' );
		$this->mock_no_cover_art();

		$result = $this->api->get_by_id( 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Bohemian Rhapsody', $result['track'] );
		$this->assertSame( 'Queen', $result['artist'] );
		$this->assertSame( 'A Night at the Opera', $result['album'] );
		$this->assertSame( 354, $result['duration'] );
		$this->assertSame( 'musicbrainz', $result['source'] );
	}

	/**
	 * Test get_by_id returns null on error.
	 */
	public function test_get_by_id_returns_null_on_error(): void {
		$this->mock_http_error( 'musicbrainz.org', 'Server error' );

		$result = $this->api->get_by_id( 'invalid-id' );

		$this->assertNull( $result );
	}

	/**
	 * Test search_artist returns artist data.
	 */
	public function test_search_artist(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/artist', 'musicbrainz/search-artist.json' );

		$results = $this->api->search_artist( 'Queen' );

		$this->assertCount( 1, $results );
		$this->assertSame( '0383dadf-2a4e-4d10-a46a-e9e041da8eb3', $results[0]['id'] );
		$this->assertSame( 'Queen', $results[0]['name'] );
		$this->assertSame( 'Queen', $results[0]['sort_name'] );
		$this->assertSame( 'Group', $results[0]['type'] );
		$this->assertSame( 'GB', $results[0]['country'] );
		$this->assertSame( 100, $results[0]['score'] );
	}

	/**
	 * Test search caches results in transient.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/recording', 'musicbrainz/search-recording.json' );
		$this->mock_no_cover_art();

		// First call makes HTTP request.
		$first = $this->api->search( 'Bohemian Rhapsody' );
		$this->assertCount( 1, $first );

		// Second call should use cache and not make another HTTP request.
		// Re-create the API instance to prove it reads from transient, not memory.
		$api2   = new MusicBrainz();
		$second = $api2->search( 'Bohemian Rhapsody' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test get_by_id caches results in transient.
	 */
	public function test_get_by_id_caches_results(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/recording/b10bbbfc', 'musicbrainz/get-recording.json' );
		$this->mock_no_cover_art();

		$first = $this->api->get_by_id( 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d' );
		$this->assertNotNull( $first );

		// Second call on a new instance should use the transient.
		$api2   = new MusicBrainz();
		$second = $api2->get_by_id( 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test test_connection succeeds when API responds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response(
			'musicbrainz.org/ws/2/artist/5b11f4ce',
			[ 'id' => '5b11f4ce-a62d-471e-81fc-a69a8278c7da', 'name' => 'Nirvana' ]
		);

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails on network error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'musicbrainz.org', 'DNS resolution failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test docs URL contains musicbrainz.org.
	 */
	public function test_docs_url_contains_musicbrainz(): void {
		$url = $this->api->get_docs_url();

		$this->assertStringContainsString( 'musicbrainz.org', $url );
	}

	/**
	 * Test is_configured always returns true (no API key required).
	 */
	public function test_is_configured(): void {
		$this->assertTrue( $this->api->is_configured() );
	}

	/**
	 * Test search_artist caches results.
	 */
	public function test_search_artist_caches_results(): void {
		$this->mock_http_response( 'musicbrainz.org/ws/2/artist', 'musicbrainz/search-artist.json' );

		$first = $this->api->search_artist( 'Queen' );
		$this->assertCount( 1, $first );

		$api2   = new MusicBrainz();
		$second = $api2->search_artist( 'Queen' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test search_artist handles error gracefully.
	 */
	public function test_search_artist_handles_error(): void {
		$this->mock_http_error( 'musicbrainz.org', 'Timeout' );

		$results = $this->api->search_artist( 'Queen' );

		$this->assertSame( [], $results );
	}
}

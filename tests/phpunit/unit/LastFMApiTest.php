<?php
/**
 * Test the Last.fm API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\LastFM;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Last.fm API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\LastFM
 */
class LastFMApiTest extends ApiTestCase {

	/**
	 * LastFM instance.
	 *
	 * @var LastFM
	 */
	private LastFM $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new LastFM();
		$this->api->set_api_key( 'test-api-key-123' );
	}

	/**
	 * Test search returns normalized results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'audioscrobbler.com/2.0/', 'lastfm/search-track.json' );

		$results = $this->api->search( 'Creep' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Creep', $results[0]['track'] );
		$this->assertSame( 'Radiohead', $results[0]['artist'] );
		$this->assertSame( 'd11fcceb-dfc5-4d19-b45d-f4e8f6d9b1b7', $results[0]['mbid'] );
		$this->assertSame( 'lastfm', $results[0]['source'] );
		$this->assertNotNull( $results[0]['image'] );
		$this->assert_api_request_made( 'audioscrobbler.com' );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_handles_error(): void {
		$this->mock_http_error( 'audioscrobbler.com' );

		$results = $this->api->search( 'Creep' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'audioscrobbler.com/2.0/', 'lastfm/search-track.json' );

		$first = $this->api->search( 'Creep' );
		$this->assertCount( 1, $first );

		$api2 = new LastFM();
		$api2->set_api_key( 'test-api-key-123' );
		$second = $api2->search( 'Creep' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test get_track_info returns normalized track.
	 */
	public function test_get_track_info_returns_normalized_track(): void {
		$this->mock_http_response( 'audioscrobbler.com/2.0/', 'lastfm/track-getinfo.json' );

		$result = $this->api->get_track_info( 'Creep', 'Radiohead' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Creep', $result['track'] );
		$this->assertSame( 'Radiohead', $result['artist'] );
		$this->assertSame( 'Pablo Honey', $result['album'] );
		$this->assertSame( 'd11fcceb-dfc5-4d19-b45d-f4e8f6d9b1b7', $result['mbid'] );
		$this->assertSame( 'a74b1b7f-71a5-4011-9441-d0b5e4122711', $result['artist_mbid'] );
		$this->assertSame( 238, $result['duration'] );
		$this->assertSame( 'lastfm', $result['source'] );
		$this->assertContains( 'alternative', $result['tags'] );
		$this->assertContains( 'rock', $result['tags'] );
	}

	/**
	 * Test get_track_info returns null on error.
	 */
	public function test_get_track_info_returns_null_on_error(): void {
		$this->mock_http_error( 'audioscrobbler.com' );

		$this->assertNull( $this->api->get_track_info( 'Creep', 'Radiohead' ) );
	}

	/**
	 * Test get_by_id with pipe format calls get_track_info.
	 */
	public function test_get_by_id_with_pipe_format(): void {
		$this->mock_http_response( 'audioscrobbler.com/2.0/', 'lastfm/track-getinfo.json' );

		$result = $this->api->get_by_id( 'Creep|Radiohead' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Creep', $result['track'] );
	}

	/**
	 * Test get_by_id returns null for invalid format.
	 */
	public function test_get_by_id_invalid_format(): void {
		$this->assertNull( $this->api->get_by_id( 'invalid-no-pipe' ) );
	}

	/**
	 * Test get_recent_tracks returns normalized scrobbles.
	 */
	public function test_get_recent_tracks_returns_scrobbles(): void {
		$this->mock_http_response( 'audioscrobbler.com/2.0/', 'lastfm/recent-tracks.json' );

		$result = $this->api->get_recent_tracks( 'testuser' );

		$this->assertArrayHasKey( 'tracks', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 250, $result['total'] );
		$this->assertSame( 5, $result['total_pages'] );
		$this->assertCount( 1, $result['tracks'] );
		$this->assertSame( 'Everything In Its Right Place', $result['tracks'][0]['track'] );
		$this->assertSame( 'Radiohead', $result['tracks'][0]['artist'] );
		$this->assertSame( 'Kid A', $result['tracks'][0]['album'] );
		$this->assertSame( 1700000000, $result['tracks'][0]['listened_at'] );
		$this->assertTrue( $result['tracks'][0]['loved'] );
		$this->assertSame( 'lastfm', $result['tracks'][0]['source'] );
	}

	/**
	 * Test get_recent_tracks handles error.
	 */
	public function test_get_recent_tracks_handles_error(): void {
		$this->mock_http_error( 'audioscrobbler.com' );

		$result = $this->api->get_recent_tracks( 'testuser' );

		$this->assertSame( [], $result['tracks'] );
		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * Test test_connection success.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'audioscrobbler.com/2.0/', [ 'artist' => [ 'name' => 'Radiohead' ] ] );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails without API key.
	 */
	public function test_test_connection_without_api_key(): void {
		$api = new LastFM();
		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test test_connection fails on network error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'audioscrobbler.com' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test is_authenticated returns false without session key.
	 */
	public function test_is_authenticated_without_session_key(): void {
		$this->assertFalse( $this->api->is_authenticated() );
	}

	/**
	 * Test is_authenticated returns true with session key.
	 */
	public function test_is_authenticated_with_session_key(): void {
		$this->api->set_session_key( 'test-session' );
		$this->assertTrue( $this->api->is_authenticated() );
	}

	/**
	 * Test docs URL contains last.fm.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'last.fm', $this->api->get_docs_url() );
	}
}

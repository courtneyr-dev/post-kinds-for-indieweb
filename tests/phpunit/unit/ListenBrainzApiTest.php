<?php
/**
 * Test the ListenBrainz API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\ListenBrainz;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the ListenBrainz API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\ListenBrainz
 */
class ListenBrainzApiTest extends ApiTestCase {

	/**
	 * ListenBrainz instance.
	 *
	 * @var ListenBrainz
	 */
	private ListenBrainz $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new ListenBrainz();
		$this->api->set_token( 'test-token-abc123' );
	}

	/**
	 * Test test_connection returns true when token is valid.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'listenbrainz.org/1/validate-token', 'listenbrainz/validate-token.json' );

		$this->assertTrue( $this->api->test_connection() );
		$this->assert_api_request_made( 'validate-token' );
	}

	/**
	 * Test test_connection returns false without a token.
	 */
	public function test_test_connection_without_token(): void {
		$api = new ListenBrainz();
		// No token set, should return false without making a request.
		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test test_connection returns false on network error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'listenbrainz.org' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test validate_token returns user info.
	 */
	public function test_validate_token_returns_user_info(): void {
		$this->mock_http_response( 'listenbrainz.org/1/validate-token', 'listenbrainz/validate-token.json' );

		$result = $this->api->validate_token();

		$this->assertNotNull( $result );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'testuser', $result['username'] );
	}

	/**
	 * Test validate_token returns null without token.
	 */
	public function test_validate_token_without_token(): void {
		$api = new ListenBrainz();
		$this->assertNull( $api->validate_token() );
	}

	/**
	 * Test get_listens returns normalized listens.
	 */
	public function test_get_listens_returns_normalized_data(): void {
		$this->mock_http_response( 'listenbrainz.org/1/user/testuser/listens', 'listenbrainz/get-listens.json' );

		$results = $this->api->get_listens( 'testuser' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Paranoid Android', $results[0]['track'] );
		$this->assertSame( 'Radiohead', $results[0]['artist'] );
		$this->assertSame( 'OK Computer', $results[0]['album'] );
		$this->assertSame( 1700000000, $results[0]['listened_at'] );
		$this->assertSame( 'abc12345-def6-7890-abcd-ef1234567890', $results[0]['recording_msid'] );
		$this->assertSame( '9db0f3f4-e1fc-4380-a352-bcc222f76809', $results[0]['mbid'] );
		$this->assertSame( 'a74b1b7f-71a5-4011-9441-d0b5e4122711', $results[0]['artist_mbid'] );
		$this->assertSame( '50fbeab5-b455-30fe-a48a-dab6b0f3dfe5', $results[0]['album_mbid'] );
		$this->assertSame( 383, $results[0]['duration'] );
		$this->assertSame( 'listenbrainz', $results[0]['source'] );
		$this->assert_api_request_made( 'user/testuser/listens' );
	}

	/**
	 * Test get_listens handles error gracefully.
	 */
	public function test_get_listens_handles_error(): void {
		$this->mock_http_error( 'listenbrainz.org' );

		$results = $this->api->get_listens( 'testuser' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_listens caches results.
	 */
	public function test_get_listens_caches_results(): void {
		$this->mock_http_response( 'listenbrainz.org/1/user/testuser/listens', 'listenbrainz/get-listens.json' );

		$first = $this->api->get_listens( 'testuser' );
		$this->assertCount( 1, $first );

		$api2   = new ListenBrainz();
		$api2->set_token( 'test-token-abc123' );
		$second = $api2->get_listens( 'testuser' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test get_listen_count returns count.
	 */
	public function test_get_listen_count_returns_count(): void {
		$this->mock_http_response( 'listenbrainz.org/1/user/testuser/listen-count', 'listenbrainz/listen-count.json' );

		$count = $this->api->get_listen_count( 'testuser' );

		$this->assertSame( 12345, $count );
		$this->assert_api_request_made( 'user/testuser/listen-count' );
	}

	/**
	 * Test get_listen_count returns null on error.
	 */
	public function test_get_listen_count_returns_null_on_error(): void {
		$this->mock_http_error( 'listenbrainz.org' );

		$this->assertNull( $this->api->get_listen_count( 'testuser' ) );
	}

	/**
	 * Test search returns empty array (stub).
	 */
	public function test_search_returns_empty(): void {
		$this->assertSame( [], $this->api->search( 'anything' ) );
	}

	/**
	 * Test get_by_id returns null (stub).
	 */
	public function test_get_by_id_returns_null(): void {
		$this->assertNull( $this->api->get_by_id( 'anything' ) );
	}

	/**
	 * Test docs URL contains listenbrainz.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'listenbrainz', $this->api->get_docs_url() );
	}
}

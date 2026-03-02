<?php
/**
 * Test the RAWG API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\RAWG;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the RAWG API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\RAWG
 */
class RAWGApiTest extends ApiTestCase {

	/**
	 * RAWG instance.
	 *
	 * @var RAWG
	 */
	private RAWG $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			'post_kinds_indieweb_api_credentials',
			[ 'rawg' => [ 'api_key' => 'test-rawg-key' ] ]
		);
		$this->api = new RAWG();
	}

	/**
	 * Test search returns normalized results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'api.rawg.io', 'rawg/search-games.json' );

		$results = $this->api->search( 'Mario' );

		$this->assertCount( 1, $results );
		$this->assertSame( 28199, $results[0]['id'] );
		$this->assertSame( 'super-mario-odyssey', $results[0]['slug'] );
		$this->assertSame( 'Super Mario Odyssey', $results[0]['name'] );
		$this->assertSame( '2017', $results[0]['year'] );
		$this->assertSame( 97, $results[0]['metacritic'] );
		$this->assertSame( 'rawg', $results[0]['source'] );
		$this->assertContains( 'Nintendo Switch', $results[0]['platforms'] );
	}

	/**
	 * Test search returns empty for empty query.
	 */
	public function test_search_returns_empty_for_empty_query(): void {
		$results = $this->api->search( '' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty when not configured.
	 */
	public function test_search_returns_empty_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new RAWG();

		$results = $api->search( 'Mario' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.rawg.io', 'Connection failed' );

		$results = $this->api->search( 'Mario' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on no results.
	 */
	public function test_search_returns_empty_on_no_results(): void {
		$this->mock_http_response( 'api.rawg.io', [ 'count' => 0, 'results' => [] ] );

		$results = $this->api->search( 'xyznonexistent' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_by_id returns detailed game data.
	 */
	public function test_get_by_id_returns_game_details(): void {
		$this->mock_http_response( 'api.rawg.io', 'rawg/game-detail.json' );

		$result = $this->api->get_by_id( '28199' );

		$this->assertNotNull( $result );
		$this->assertSame( 28199, $result['id'] );
		$this->assertSame( 'Super Mario Odyssey', $result['title'] );
		$this->assertSame( '2017', $result['year'] );
		$this->assertSame( '2017-10-27', $result['released'] );
		$this->assertSame( 97, $result['metacritic'] );
		$this->assertSame( 25, $result['playtime'] );
		$this->assertSame( 'rawg', $result['source'] );
		$this->assertContains( 'Nintendo Switch', $result['platforms'] );
		$this->assertContains( 'Platformer', $result['genres'] );
		$this->assertContains( 'Nintendo EPD', $result['developers'] );
		$this->assertContains( 'Nintendo', $result['publishers'] );
		$this->assertNotEmpty( $result['stores'] );
		$this->assertNotEmpty( $result['website'] );
		$this->assertStringContainsString( 'rawg.io/games/', $result['url'] );
	}

	/**
	 * Test get_by_id returns null for empty ID.
	 */
	public function test_get_by_id_returns_null_for_empty_id(): void {
		$this->assertNull( $this->api->get_by_id( '' ) );
	}

	/**
	 * Test get_by_id returns null when not configured.
	 */
	public function test_get_by_id_returns_null_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new RAWG();

		$this->assertNull( $api->get_by_id( '28199' ) );
	}

	/**
	 * Test get_by_id returns null on error.
	 */
	public function test_get_by_id_returns_null_on_error(): void {
		$this->mock_http_error( 'api.rawg.io', 'Not found' );

		$this->assertNull( $this->api->get_by_id( '99999' ) );
	}

	/**
	 * Test get_by_platform returns results.
	 */
	public function test_get_by_platform_returns_results(): void {
		$this->mock_http_response( 'api.rawg.io', 'rawg/search-games.json' );

		$results = $this->api->get_by_platform( 7 );

		$this->assertCount( 1, $results );
		$this->assertSame( 'rawg', $results[0]['source'] );
	}

	/**
	 * Test get_by_platform returns empty when not configured.
	 */
	public function test_get_by_platform_returns_empty_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new RAWG();

		$this->assertSame( [], $api->get_by_platform( 7 ) );
	}

	/**
	 * Test get_platforms returns platform list.
	 */
	public function test_get_platforms_returns_list(): void {
		$this->mock_http_response( 'api.rawg.io', 'rawg/platforms.json' );

		$platforms = $this->api->get_platforms();

		$this->assertCount( 4, $platforms );
		$this->assertSame( 4, $platforms[0]['id'] );
		$this->assertSame( 'PC', $platforms[0]['name'] );
	}

	/**
	 * Test get_platforms returns empty when not configured.
	 */
	public function test_get_platforms_returns_empty_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new RAWG();

		$this->assertSame( [], $api->get_platforms() );
	}

	/**
	 * Test is_configured returns true with key.
	 */
	public function test_is_configured_returns_true_with_key(): void {
		$this->assertTrue( $this->api->is_configured() );
	}

	/**
	 * Test is_configured returns false without key.
	 */
	public function test_is_configured_returns_false_without_key(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new RAWG();

		$this->assertFalse( $api->is_configured() );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'api.rawg.io', 'rawg/search-games.json' );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails without config.
	 */
	public function test_test_connection_fails_without_config(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new RAWG();

		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'rawg', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}

	/**
	 * Test signup URL is valid.
	 */
	public function test_signup_url(): void {
		$this->assertStringStartsWith( 'https://', $this->api->get_signup_url() );
	}
}

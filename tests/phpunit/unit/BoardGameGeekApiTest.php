<?php
/**
 * Test the BoardGameGeek API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\BoardGameGeek;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the BoardGameGeek API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\BoardGameGeek
 */
class BoardGameGeekApiTest extends ApiTestCase {

	/**
	 * BoardGameGeek instance.
	 *
	 * @var BoardGameGeek
	 */
	private BoardGameGeek $api;

	/**
	 * Sample search XML response.
	 *
	 * @var string
	 */
	private string $search_xml = '<?xml version="1.0" encoding="utf-8"?>
<items total="1" termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
	<item type="boardgame" id="13">
		<name type="primary" value="Catan"/>
		<yearpublished value="1995"/>
	</item>
</items>';

	/**
	 * Sample thing detail XML response.
	 *
	 * @var string
	 */
	private string $thing_xml = '<?xml version="1.0" encoding="utf-8"?>
<items termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">
	<item type="boardgame" id="13">
		<thumbnail>https://cf.geekdo-images.com/catan_thumb.jpg</thumbnail>
		<image>https://cf.geekdo-images.com/catan.jpg</image>
		<name type="primary" sortindex="1" value="Catan"/>
		<name type="alternate" sortindex="1" value="Settlers of Catan"/>
		<description>In Catan, players try to be the dominant force on the island of Catan.</description>
		<yearpublished value="1995"/>
		<minplayers value="3"/>
		<maxplayers value="4"/>
		<playingtime value="120"/>
		<link type="boardgamecategory" id="1029" value="Negotiation"/>
		<link type="boardgamemechanic" id="2072" value="Dice Rolling"/>
		<link type="boardgamedesigner" id="11" value="Klaus Teuber"/>
		<link type="boardgamepublisher" id="267" value="999 Games"/>
		<statistics page="1">
			<ratings>
				<average value="7.1"/>
				<usersrated value="100000"/>
			</ratings>
		</statistics>
	</item>
</items>';

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			'post_kinds_indieweb_api_credentials',
			[ 'bgg' => [ 'api_token' => 'test-token-123' ] ]
		);
		$this->api = new BoardGameGeek();
	}

	/**
	 * Test search returns normalized results.
	 */
	public function test_search_returns_results(): void {
		$this->mock_http_raw_response( 'boardgamegeek.com', $this->search_xml );

		$results = $this->api->search( 'Catan' );

		$this->assertCount( 1, $results );
		$this->assertSame( '13', $results[0]['id'] );
		$this->assertSame( 'Catan', $results[0]['name'] );
		$this->assertSame( '1995', $results[0]['year'] );
		$this->assertSame( 'boardgame', $results[0]['type'] );
	}

	/**
	 * Test search returns empty for empty query.
	 */
	public function test_search_returns_empty_for_empty_query(): void {
		$results = $this->api->search( '' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'boardgamegeek.com', 'Connection failed' );

		$results = $this->api->search( 'Catan' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search returns empty on invalid XML.
	 */
	public function test_search_returns_empty_on_invalid_xml(): void {
		$this->mock_http_raw_response( 'boardgamegeek.com', 'not xml at all' );

		$results = $this->api->search( 'Catan' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test get_by_id returns game details.
	 */
	public function test_get_by_id_returns_game_details(): void {
		$this->mock_http_raw_response( 'boardgamegeek.com', $this->thing_xml );

		$result = $this->api->get_by_id( '13' );

		$this->assertNotNull( $result );
		$this->assertSame( '13', $result['id'] );
		$this->assertSame( 'Catan', $result['name'] );
		$this->assertSame( '1995', $result['year'] );
		$this->assertSame( 'boardgame', $result['type'] );
		$this->assertSame( 3, $result['min_players'] );
		$this->assertSame( 4, $result['max_players'] );
		$this->assertSame( 120, $result['play_time'] );
		$this->assertSame( 7.1, $result['rating'] );
		$this->assertSame( 100000, $result['rating_count'] );
		$this->assertContains( 'Klaus Teuber', $result['designers'] );
		$this->assertContains( '999 Games', $result['publishers'] );
		$this->assertContains( 'Negotiation', $result['categories'] );
		$this->assertContains( 'Dice Rolling', $result['mechanics'] );
		$this->assertNotEmpty( $result['image'] );
		$this->assertNotEmpty( $result['thumbnail'] );
	}

	/**
	 * Test get_by_id returns null for empty ID.
	 */
	public function test_get_by_id_returns_null_for_empty_id(): void {
		$this->assertNull( $this->api->get_by_id( '' ) );
	}

	/**
	 * Test get_by_id returns null on error.
	 */
	public function test_get_by_id_returns_null_on_error(): void {
		$this->mock_http_error( 'boardgamegeek.com', 'Not found' );

		$this->assertNull( $this->api->get_by_id( '99999' ) );
	}

	/**
	 * Test normalize_result produces standard format.
	 */
	public function test_normalize_result(): void {
		$raw = [
			'id'          => '13',
			'name'        => 'Catan',
			'year'        => '1995',
			'type'        => 'boardgame',
			'image'       => 'https://example.com/image.jpg',
			'thumbnail'   => 'https://example.com/thumb.jpg',
			'description' => 'A trading game.',
			'designers'   => [ 'Klaus Teuber' ],
			'min_players' => 3,
			'max_players' => 4,
		];

		$normalized = $this->api->normalize_result( $raw );

		$this->assertSame( '13', $normalized['id'] );
		$this->assertSame( 'Catan', $normalized['title'] );
		$this->assertSame( 'bgg', $normalized['source'] );
		$this->assertStringContainsString( 'boardgamegeek.com/boardgame/13', $normalized['url'] );
	}

	/**
	 * Test normalize_result generates video game URL for video games.
	 */
	public function test_normalize_result_videogame_url(): void {
		$raw = [
			'id'   => '42',
			'name' => 'Test Game',
			'type' => 'videogame',
		];

		$normalized = $this->api->normalize_result( $raw );

		$this->assertStringContainsString( 'videogamegeek.com/videogame/42', $normalized['url'] );
	}

	/**
	 * Test is_configured returns false without token.
	 */
	public function test_is_configured_returns_false_without_token(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new BoardGameGeek();

		$this->assertFalse( $api->is_configured() );
	}

	/**
	 * Test is_configured returns true with token.
	 */
	public function test_is_configured_returns_true_with_token(): void {
		$this->assertTrue( $this->api->is_configured() );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_raw_response( 'boardgamegeek.com', $this->search_xml );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails without config.
	 */
	public function test_test_connection_fails_without_config(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new BoardGameGeek();

		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test get returns error when not configured.
	 */
	public function test_get_returns_error_when_not_configured(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new BoardGameGeek();

		$result = $api->get( 'search', [ 'query' => 'test' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test get handles 401 auth error.
	 */
	public function test_get_handles_auth_error(): void {
		$this->mock_http_raw_response( 'boardgamegeek.com', 'Unauthorized', 401 );

		$result = $this->api->get( 'search', [ 'query' => 'test' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_raw_response( 'boardgamegeek.com', $this->search_xml );

		$first = $this->api->search( 'Catan' );
		$this->assertCount( 1, $first );

		$api2 = new BoardGameGeek();
		$second = $api2->search( 'Catan' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'boardgamegeek', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}

	/**
	 * Test get_game_types returns expected types.
	 */
	public function test_get_game_types(): void {
		$types = $this->api->get_game_types();

		$this->assertArrayHasKey( 'boardgame', $types );
		$this->assertArrayHasKey( 'videogame', $types );
		$this->assertArrayHasKey( 'rpgitem', $types );
	}

	/**
	 * Test get_config_fields returns token field.
	 */
	public function test_get_config_fields(): void {
		$fields = $this->api->get_config_fields();

		$this->assertArrayHasKey( 'bgg_api_token', $fields );
		$this->assertSame( 'password', $fields['bgg_api_token']['type'] );
	}
}

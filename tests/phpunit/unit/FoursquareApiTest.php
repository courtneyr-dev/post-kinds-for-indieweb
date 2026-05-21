<?php
/**
 * Test the Foursquare API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\Foursquare;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Foursquare API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\Foursquare
 */
class FoursquareApiTest extends ApiTestCase {

	/**
	 * Foursquare instance.
	 *
	 * @var Foursquare
	 */
	private Foursquare $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			'post_kinds_indieweb_api_credentials',
			[ 'foursquare' => [ 'api_key' => 'test-foursquare-key' ] ]
		);
		$this->api = new Foursquare();
	}

	/**
	 * Test search returns normalized place results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$results = $this->api->search( 'Blue Bottle Coffee' );

		$this->assertCount( 1, $results );
		$this->assertSame( '4b4e89e4f964a520e0a926e3', $results[0]['id'] );
		$this->assertSame( '4b4e89e4f964a520e0a926e3', $results[0]['fsq_id'] );
		$this->assertSame( 'Blue Bottle Coffee', $results[0]['name'] );
		$this->assertSame( '160 Berry St', $results[0]['address'] );
		$this->assertSame( 'Brooklyn', $results[0]['locality'] );
		$this->assertSame( 'NY', $results[0]['region'] );
		$this->assertSame( 40.7196, $results[0]['latitude'] );
		$this->assertSame( -73.9601, $results[0]['longitude'] );
		$this->assertSame( 'Coffee Shop', $results[0]['category'] );
		$this->assertSame( 'venue', $results[0]['type'] );
		$this->assertSame( 'foursquare', $results[0]['source'] );
	}

	/**
	 * Test search with location parameters.
	 */
	public function test_search_with_lat_lng(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$results = $this->api->search( 'coffee', null, 40.7196, -73.9601 );

		$this->assertCount( 1, $results );
		$this->assert_api_request_made( 'api.foursquare.com' );
	}

	/**
	 * Test search with near parameter.
	 */
	public function test_search_with_near(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$results = $this->api->search( 'coffee', 'Brooklyn, NY' );

		$this->assertCount( 1, $results );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.foursquare.com', 'Connection failed' );

		$results = $this->api->search( 'coffee' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$first = $this->api->search( 'Blue Bottle Coffee' );
		$this->assertCount( 1, $first );

		$api2 = new Foursquare();
		$second = $api2->search( 'Blue Bottle Coffee' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test get_place returns detailed place data.
	 */
	public function test_get_place_returns_detailed_data(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/place-detail.json' );

		$result = $this->api->get_place( '4b4e89e4f964a520e0a926e3' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Blue Bottle Coffee', $result['name'] );
		$this->assertSame( 'Specialty coffee roaster and retailer', $result['description'] );
		$this->assertSame( 'https://bluebottlecoffee.com', $result['website'] );
		$this->assertSame( 8.5, $result['rating'] );
		$this->assertSame( 2, $result['price'] );
		$this->assertTrue( $result['verified'] );
		$this->assertNotEmpty( $result['photos'] );
		$this->assertNotEmpty( $result['stats'] );
		$this->assertSame( 500, $result['stats']['total_photos'] );
		$this->assertContains( 'coffee', $result['tastes'] );
		$this->assertNotEmpty( $result['chains'] );
	}

	/**
	 * Test get_place returns null on error.
	 */
	public function test_get_place_returns_null_on_error(): void {
		$this->mock_http_error( 'api.foursquare.com', 'Not found' );

		$this->assertNull( $this->api->get_place( 'invalid-id' ) );
	}

	/**
	 * Test get_by_id delegates to get_place.
	 */
	public function test_get_by_id_delegates_to_get_place(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/place-detail.json' );

		$result = $this->api->get_by_id( '4b4e89e4f964a520e0a926e3' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Blue Bottle Coffee', $result['name'] );
	}

	/**
	 * Test search_nearby returns results.
	 */
	public function test_search_nearby_returns_results(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$results = $this->api->search_nearby( 40.7196, -73.9601 );

		$this->assertCount( 1, $results );
		$this->assertSame( 'foursquare', $results[0]['source'] );
	}

	/**
	 * Test search_nearby returns empty on error.
	 */
	public function test_search_nearby_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.foursquare.com', 'Connection failed' );

		$this->assertSame( [], $this->api->search_nearby( 40.7196, -73.9601 ) );
	}

	/**
	 * Test get_photos returns photo data.
	 */
	public function test_get_photos_returns_data(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/photos.json' );

		$photos = $this->api->get_photos( '4b4e89e4f964a520e0a926e3' );

		$this->assertCount( 1, $photos );
		$this->assertSame( 'photo123', $photos[0]['id'] );
		$this->assertNotEmpty( $photos[0]['url_small'] );
		$this->assertNotEmpty( $photos[0]['url_medium'] );
		$this->assertNotEmpty( $photos[0]['url_large'] );
		$this->assertNotEmpty( $photos[0]['url_original'] );
	}

	/**
	 * Test get_photos returns empty on error.
	 */
	public function test_get_photos_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.foursquare.com', 'Connection failed' );

		$this->assertSame( [], $this->api->get_photos( 'invalid-id' ) );
	}

	/**
	 * Test get_tips returns tip data.
	 */
	public function test_get_tips_returns_data(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/tips.json' );

		$tips = $this->api->get_tips( '4b4e89e4f964a520e0a926e3' );

		$this->assertCount( 1, $tips );
		$this->assertSame( 'tip456', $tips[0]['id'] );
		$this->assertStringContainsString( 'pour over', $tips[0]['text'] );
		$this->assertSame( 15, $tips[0]['agree_count'] );
	}

	/**
	 * Test get_tips returns empty on error.
	 */
	public function test_get_tips_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.foursquare.com', 'Connection failed' );

		$this->assertSame( [], $this->api->get_tips( 'invalid-id' ) );
	}

	/**
	 * Test autocomplete returns place suggestions.
	 */
	public function test_autocomplete_returns_suggestions(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/autocomplete.json' );

		$results = $this->api->autocomplete( 'Blue Bottle' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'Blue Bottle Coffee', $results[0]['name'] );
	}

	/**
	 * Test autocomplete returns empty on error.
	 */
	public function test_autocomplete_returns_empty_on_error(): void {
		$this->mock_http_error( 'api.foursquare.com', 'Connection failed' );

		$this->assertSame( [], $this->api->autocomplete( 'test' ) );
	}

	/**
	 * Test match_place returns matched place.
	 */
	public function test_match_place_returns_result(): void {
		$this->mock_http_response( 'api.foursquare.com', [
			'place' => [
				'fsq_id'     => '4b4e89e4f964a520e0a926e3',
				'name'       => 'Blue Bottle Coffee',
				'location'   => [ 'address' => '160 Berry St', 'locality' => 'Brooklyn' ],
				'geocodes'   => [ 'main' => [ 'latitude' => 40.7196, 'longitude' => -73.9601 ] ],
				'categories' => [],
			],
		] );

		$result = $this->api->match_place( 'Blue Bottle Coffee', '160 Berry St', 'Brooklyn' );

		$this->assertNotNull( $result );
		$this->assertSame( 'Blue Bottle Coffee', $result['name'] );
	}

	/**
	 * Test match_place returns null on no match.
	 */
	public function test_match_place_returns_null_on_no_match(): void {
		$this->mock_http_response( 'api.foursquare.com', [] );

		$this->assertNull( $this->api->match_place( 'Nonexistent Place' ) );
	}

	/**
	 * Test search_by_category returns results.
	 */
	public function test_search_by_category_returns_results(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$results = $this->api->search_by_category( [ '13035' ], 40.7196, -73.9601 );

		$this->assertCount( 1, $results );
	}

	/**
	 * Test get_categories returns category list.
	 */
	public function test_get_categories_returns_list(): void {
		$categories = $this->api->get_categories();

		$this->assertNotEmpty( $categories );
		$this->assertArrayHasKey( 'id', $categories[0] );
		$this->assertArrayHasKey( 'name', $categories[0] );
	}

	/**
	 * Test set_api_key changes the key.
	 */
	public function test_set_api_key(): void {
		$api = new Foursquare();
		$api->set_api_key( 'new-test-key' );

		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$results = $api->search( 'coffee' );
		$this->assertCount( 1, $results );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails without key.
	 */
	public function test_test_connection_fails_without_key(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );
		$api = new Foursquare();

		$this->assertFalse( $api->test_connection() );
	}

	/**
	 * Test test_connection fails on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'api.foursquare.com', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test category icon URL construction.
	 */
	public function test_category_icon_in_results(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/search-places.json' );

		$results = $this->api->search( 'coffee' );

		$this->assertNotNull( $results[0]['category_icon'] );
		$this->assertStringContainsString( 'coffeeshop', $results[0]['category_icon'] );
	}

	/**
	 * Test photo URL construction.
	 */
	public function test_photo_url_construction(): void {
		$this->mock_http_response( 'api.foursquare.com', 'foursquare/photos.json' );

		$photos = $this->api->get_photos( 'test-id' );

		$this->assertStringContainsString( '100x100', $photos[0]['url_small'] );
		$this->assertStringContainsString( '300x300', $photos[0]['url_medium'] );
		$this->assertStringContainsString( '500x500', $photos[0]['url_large'] );
		$this->assertStringContainsString( 'original', $photos[0]['url_original'] );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'foursquare', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

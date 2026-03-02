<?php
/**
 * Test the Nominatim API client.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\APIs\Nominatim;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Nominatim API integration.
 *
 * @covers \PostKindsForIndieWeb\APIs\Nominatim
 */
class NominatimApiTest extends ApiTestCase {

	/**
	 * Nominatim instance.
	 *
	 * @var Nominatim
	 */
	private Nominatim $api;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->api = new Nominatim();
	}

	/**
	 * Test search returns normalized location results.
	 */
	public function test_search_returns_normalized_results(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$results = $this->api->search( 'New York' );

		$this->assertCount( 1, $results );
		$this->assertSame( 'New York', $results[0]['name'] );
		$this->assertSame( 40.7127281, $results[0]['latitude'] );
		$this->assertSame( -74.0060152, $results[0]['longitude'] );
		$this->assertSame( 'nominatim', $results[0]['source'] );
		$this->assertSame( 'New York', $results[0]['address']['locality'] );
		$this->assertSame( 'New York', $results[0]['address']['region'] );
		$this->assertSame( 'United States', $results[0]['address']['country'] );
		$this->assertSame( 'us', $results[0]['address']['country_code'] );
		$this->assertNotEmpty( $results[0]['formatted_address'] );
		$this->assertNotEmpty( $results[0]['osm_full_id'] );
		$this->assertSame( 'R175905', $results[0]['osm_full_id'] );
	}

	/**
	 * Test search returns empty on error.
	 */
	public function test_search_returns_empty_on_error(): void {
		$this->mock_http_error( 'nominatim.openstreetmap.org', 'Connection failed' );

		$results = $this->api->search( 'New York' );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search caches results.
	 */
	public function test_search_caches_results(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$first = $this->api->search( 'New York' );
		$this->assertCount( 1, $first );

		$api2 = new Nominatim();
		$second = $api2->search( 'New York' );

		$this->assertSame( $first, $second );
	}

	/**
	 * Test geocode returns first result.
	 */
	public function test_geocode_returns_first_result(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$result = $this->api->geocode( 'New York' );

		$this->assertNotNull( $result );
		$this->assertSame( 'New York', $result['name'] );
		$this->assertSame( 40.7127281, $result['latitude'] );
	}

	/**
	 * Test geocode returns null on no results.
	 */
	public function test_geocode_returns_null_on_no_results(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', [] );

		$result = $this->api->geocode( 'xyznonexistent' );

		$this->assertNull( $result );
	}

	/**
	 * Test reverse geocode.
	 */
	public function test_reverse_returns_location(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/reverse.json' );

		$result = $this->api->reverse( 40.7127281, -74.0060152 );

		$this->assertNotNull( $result );
		$this->assertSame( 'New York', $result['name'] );
		$this->assertSame( 'nominatim', $result['source'] );
		$this->assertSame( 'New York', $result['address']['locality'] );
	}

	/**
	 * Test reverse returns null on error.
	 */
	public function test_reverse_returns_null_on_error(): void {
		$this->mock_http_error( 'nominatim.openstreetmap.org', 'Connection failed' );

		$result = $this->api->reverse( 0.0, 0.0 );

		$this->assertNull( $result );
	}

	/**
	 * Test lookup by OSM ID.
	 */
	public function test_lookup_returns_location(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/lookup.json' );

		$result = $this->api->lookup( 'R175905' );

		$this->assertNotNull( $result );
		$this->assertSame( 'New York', $result['name'] );
		$this->assertSame( 40.7127281, $result['latitude'] );
	}

	/**
	 * Test lookup returns null on error.
	 */
	public function test_lookup_returns_null_on_error(): void {
		$this->mock_http_error( 'nominatim.openstreetmap.org', 'Connection failed' );

		$result = $this->api->lookup( 'R999999' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_by_id delegates to lookup.
	 */
	public function test_get_by_id_delegates_to_lookup(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/lookup.json' );

		$result = $this->api->get_by_id( 'R175905' );

		$this->assertNotNull( $result );
		$this->assertSame( 'New York', $result['name'] );
	}

	/**
	 * Test lookup_multiple returns locations.
	 */
	public function test_lookup_multiple_returns_locations(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/lookup.json' );

		$results = $this->api->lookup_multiple( [ 'R175905' ] );

		$this->assertCount( 1, $results );
		$this->assertSame( 'New York', $results[0]['name'] );
	}

	/**
	 * Test lookup_multiple returns empty for empty input.
	 */
	public function test_lookup_multiple_returns_empty_for_empty_input(): void {
		$results = $this->api->lookup_multiple( [] );

		$this->assertSame( [], $results );
	}

	/**
	 * Test structured_search with address components.
	 */
	public function test_structured_search_returns_results(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$results = $this->api->structured_search( [
			'city'    => 'New York',
			'country' => 'United States',
		] );

		$this->assertCount( 1, $results );
		$this->assertSame( 'New York', $results[0]['name'] );
	}

	/**
	 * Test structured_search returns empty for empty params.
	 */
	public function test_structured_search_returns_empty_for_empty_params(): void {
		$results = $this->api->structured_search( [] );

		$this->assertSame( [], $results );
	}

	/**
	 * Test structured_search filters invalid params.
	 */
	public function test_structured_search_filters_invalid_params(): void {
		$results = $this->api->structured_search( [ 'invalid_param' => 'value' ] );

		$this->assertSame( [], $results );
	}

	/**
	 * Test search_bounded with bounding box.
	 */
	public function test_search_bounded_returns_results(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$results = $this->api->search_bounded( 'cafe', 40.0, 41.0, -75.0, -73.0 );

		$this->assertCount( 1, $results );
		$this->assert_api_request_made( 'nominatim.openstreetmap.org' );
	}

	/**
	 * Test search_by_type returns results.
	 */
	public function test_search_by_type_returns_results(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$results = $this->api->search_by_type( 'restaurant', 'Brooklyn' );

		$this->assertCount( 1, $results );
	}

	/**
	 * Test bounding box parsing.
	 */
	public function test_bounding_box_parsed_correctly(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$results = $this->api->search( 'New York' );

		$this->assertNotNull( $results[0]['bounding_box'] );
		$this->assertSame( 40.4773991, $results[0]['bounding_box']['min_lat'] );
		$this->assertSame( 40.9175771, $results[0]['bounding_box']['max_lat'] );
	}

	/**
	 * Test extra tags parsed correctly.
	 */
	public function test_extra_tags_parsed(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$results = $this->api->search( 'New York' );

		$this->assertSame( 'en:New York City', $results[0]['extra']['wikipedia'] );
		$this->assertSame( 'Q60', $results[0]['extra']['wikidata'] );
	}

	/**
	 * Test set_server changes base URL.
	 */
	public function test_set_server_changes_base_url(): void {
		$this->api->set_server( 'https://custom.nominatim.org' );

		$this->mock_http_response( 'custom.nominatim.org', 'nominatim/search.json' );

		$results = $this->api->search( 'test' );
		$this->assertCount( 1, $results );
		$this->assert_api_request_made( 'custom.nominatim.org' );
	}

	/**
	 * Test test_connection succeeds.
	 */
	public function test_test_connection_success(): void {
		$this->mock_http_response( 'nominatim.openstreetmap.org', 'nominatim/search.json' );

		$this->assertTrue( $this->api->test_connection() );
	}

	/**
	 * Test test_connection fails on error.
	 */
	public function test_test_connection_failure(): void {
		$this->mock_http_error( 'nominatim.openstreetmap.org', 'Connection failed' );

		$this->assertFalse( $this->api->test_connection() );
	}

	/**
	 * Test docs URL is valid.
	 */
	public function test_docs_url(): void {
		$this->assertStringContainsString( 'nominatim', $this->api->get_docs_url() );
		$this->assertStringStartsWith( 'https://', $this->api->get_docs_url() );
	}
}

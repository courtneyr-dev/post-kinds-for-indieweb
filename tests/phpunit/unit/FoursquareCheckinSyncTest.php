<?php
/**
 * Test the Foursquare Checkin Sync class.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\Sync\Foursquare_Checkin_Sync;
use PKIW\Tests\ApiTestCase;

/**
 * Test the Foursquare Checkin Sync integration.
 *
 * @covers \PKIW\Sync\Foursquare_Checkin_Sync
 */
class FoursquareCheckinSyncTest extends ApiTestCase {

	/**
	 * Sync instance.
	 *
	 * @var Foursquare_Checkin_Sync
	 */
	private Foursquare_Checkin_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'pkiw_api_credentials', [
			'foursquare' => [
				'client_id'         => 'test-client-id',
				'client_secret'     => 'test-client-secret',
				'user_access_token' => 'test-access-token',
			],
		] );

		$this->sync = new Foursquare_Checkin_Sync();
	}

	/**
	 * Test is_connected returns true with token.
	 */
	public function test_is_connected_true(): void {
		$this->assertTrue( $this->sync->is_connected() );
	}

	/**
	 * Test is_connected returns false without token.
	 */
	public function test_is_connected_false(): void {
		update_option( 'pkiw_api_credentials', [
			'foursquare' => [
				'client_id' => 'test-client-id',
			],
		] );

		$sync = new Foursquare_Checkin_Sync();
		$this->assertFalse( $sync->is_connected() );
	}

	/**
	 * Test service properties.
	 */
	public function test_service_properties(): void {
		$this->assertSame( 'foursquare', $this->sync->get_service_id() );
		$this->assertSame( 'Foursquare', $this->sync->get_service_name() );
	}

	/**
	 * Test get_auth_url returns URL with client_id.
	 */
	public function test_get_auth_url(): void {
		$url = $this->sync->get_auth_url();

		$this->assertStringContainsString( 'foursquare.com/oauth2', $url );
		$this->assertStringContainsString( 'test-client-id', $url );
	}

	/**
	 * Test get_auth_url returns empty without client_id.
	 */
	public function test_get_auth_url_empty_without_client_id(): void {
		update_option( 'pkiw_api_credentials', [] );

		$sync = new Foursquare_Checkin_Sync();
		$this->assertSame( '', $sync->get_auth_url() );
	}

	/**
	 * Test handle_oauth_callback stores token.
	 */
	public function test_handle_oauth_callback_success(): void {
		$this->mock_http_response( 'foursquare.com/oauth2/access_token', [
			'access_token' => 'new-access-token',
		] );

		// Mock the user info request too.
		$this->mock_http_response( 'api.foursquare.com/v2/users/self', [
			'response' => [
				'user' => [
					'id'        => 'user123',
					'firstName' => 'Test',
				],
			],
		] );

		$result = $this->sync->handle_oauth_callback( 'auth-code-123' );

		$this->assertTrue( $result );
	}

	/**
	 * Test handle_oauth_callback fails on error.
	 */
	public function test_handle_oauth_callback_failure(): void {
		$this->mock_http_error( 'foursquare.com/oauth2', 'Connection failed' );

		$result = $this->sync->handle_oauth_callback( 'bad-code' );

		$this->assertFalse( $result );
	}

	/**
	 * Test handle_oauth_callback fails when no token in response.
	 */
	public function test_handle_oauth_callback_missing_token(): void {
		$this->mock_http_response( 'foursquare.com/oauth2/access_token', [
			'error' => 'invalid_grant',
		] );

		$result = $this->sync->handle_oauth_callback( 'expired-code' );

		$this->assertFalse( $result );
	}

	/**
	 * Test fetch_recent_checkins returns items.
	 */
	public function test_fetch_recent_checkins(): void {
		$this->mock_http_response( 'api.foursquare.com', [
			'response' => [
				'checkins' => [
					'items' => [
						[
							'id'        => 'ci-1',
							'createdAt' => 1700000000,
							'venue'     => [ 'name' => 'Test Cafe' ],
						],
					],
				],
			],
		] );

		$checkins = $this->sync->fetch_recent_checkins( 10 );

		$this->assertCount( 1, $checkins );
		$this->assertSame( 'ci-1', $checkins[0]['id'] );
	}

	/**
	 * Every caller gets the same keys: Import_Manager, the Import page
	 * preview and the sync's own import.
	 */
	public function test_fetch_recent_checkins_normalizes_each_item(): void {
		$this->mock_http_response( 'api.foursquare.com', [
			'response' => [
				'checkins' => [
					'items' => [
						[
							'id'        => 'ci-1',
							'createdAt' => 1700000000,
							'shout'     => 'Hi.',
							'venue'     => [
								'id'       => 'v-1',
								'name'     => 'Test Cafe',
								'location' => [
									'address'    => '1 Main St',
									'city'       => 'Oakland',
									'state'      => 'CA',
									'country'    => 'United States',
									'postalCode' => '94607',
									'lat'        => 37.8,
									'lng'        => -122.27,
								],
							],
						],
					],
				],
			],
		] );

		$checkins = $this->sync->fetch_recent_checkins( 10 );

		$this->assertSame(
			[
				'id'          => 'ci-1',
				'timestamp'   => 1700000000,
				'shout'       => 'Hi.',
				'url'         => '',
				'venue_id'    => 'v-1',
				'venue_name'  => 'Test Cafe',
				'address'     => '1 Main St',
				'locality'    => 'Oakland',
				'region'      => 'CA',
				'country'     => 'United States',
				'postal_code' => '94607',
				'latitude'    => 37.8,
				'longitude'   => -122.27,
			],
			$checkins[0]
		);
	}

	/**
	 * A check-in with no venue or date normalizes to empty values, not errors.
	 */
	public function test_normalize_checkin_without_a_venue(): void {
		$item = Foursquare_Checkin_Sync::normalize_checkin( [ 'id' => 'ci-2' ] );

		$this->assertSame( 'ci-2', $item['id'] );
		$this->assertSame( '', $item['venue_name'] );
		$this->assertSame( '', $item['venue_id'] );
		$this->assertNull( $item['timestamp'] );
		$this->assertNull( $item['latitude'] );
	}

	/**
	 * Test fetch_recent_checkins returns empty when disconnected.
	 */
	public function test_fetch_recent_checkins_disconnected(): void {
		update_option( 'pkiw_api_credentials', [] );

		$sync     = new Foursquare_Checkin_Sync();
		$checkins = $sync->fetch_recent_checkins();

		$this->assertSame( [], $checkins );
	}

	/**
	 * Test add_syndication_target when connected.
	 */
	public function test_add_syndication_target(): void {
		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayHasKey( 'foursquare', $targets );
		$this->assertSame( 'Foursquare', $targets['foursquare']['name'] );
	}
}

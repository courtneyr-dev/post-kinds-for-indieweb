<?php
/**
 * Test the Foursquare Checkin Sync class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Sync\Foursquare_Checkin_Sync;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Foursquare Checkin Sync integration.
 *
 * @covers \PostKindsForIndieWeb\Sync\Foursquare_Checkin_Sync
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

		update_option( 'post_kinds_indieweb_api_credentials', [
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
		update_option( 'post_kinds_indieweb_api_credentials', [
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
		update_option( 'post_kinds_indieweb_api_credentials', [] );

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
	 * Test fetch_recent_checkins returns empty when disconnected.
	 */
	public function test_fetch_recent_checkins_disconnected(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );

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

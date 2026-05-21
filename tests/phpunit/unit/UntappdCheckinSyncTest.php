<?php
/**
 * Test the Untappd Checkin Sync class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Sync\Untappd_Checkin_Sync;
use PostKindsForIndieWeb\Tests\ApiTestCase;

/**
 * Test the Untappd Checkin Sync integration.
 *
 * @covers \PostKindsForIndieWeb\Sync\Untappd_Checkin_Sync
 */
class UntappdCheckinSyncTest extends ApiTestCase {

	/**
	 * Sync instance.
	 *
	 * @var Untappd_Checkin_Sync
	 */
	private Untappd_Checkin_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'post_kinds_indieweb_api_credentials', [
			'untappd' => [
				'client_id'     => 'test-client-id',
				'client_secret' => 'test-client-secret',
				'access_token'  => 'test-access-token',
				'username'      => 'testuser',
			],
		] );

		$this->sync = new Untappd_Checkin_Sync();
	}

	/**
	 * Test service properties.
	 */
	public function test_service_properties(): void {
		$this->assertSame( 'untappd', $this->sync->get_service_id() );
		$this->assertSame( 'Untappd', $this->sync->get_service_name() );
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
		update_option( 'post_kinds_indieweb_api_credentials', [] );

		$sync = new Untappd_Checkin_Sync();
		$this->assertFalse( $sync->is_connected() );
	}

	/**
	 * Test get_auth_url returns Untappd OAuth URL.
	 */
	public function test_get_auth_url(): void {
		$url = $this->sync->get_auth_url();

		$this->assertStringContainsString( 'untappd.com/oauth/authenticate', $url );
		$this->assertStringContainsString( 'test-client-id', $url );
	}

	/**
	 * Test handle_oauth_callback stores token.
	 */
	public function test_handle_oauth_callback_success(): void {
		$this->mock_http_response( 'untappd.com/oauth/authorize', [
			'response' => [
				'access_token' => 'new-token',
			],
		] );

		// Mock user info request.
		$this->mock_http_response( 'api.untappd.com', [
			'response' => [
				'user' => [
					'user_name' => 'newuser',
				],
			],
		] );

		$result = $this->sync->handle_oauth_callback( 'auth-code' );

		$this->assertTrue( $result );
	}

	/**
	 * Test handle_oauth_callback fails on error.
	 */
	public function test_handle_oauth_callback_failure(): void {
		$this->mock_http_error( 'untappd.com', 'Connection failed' );

		$this->assertFalse( $this->sync->handle_oauth_callback( 'bad-code' ) );
	}

	/**
	 * Test handle_oauth_callback fails on missing token.
	 */
	public function test_handle_oauth_callback_missing_token(): void {
		$this->mock_http_response( 'untappd.com/oauth/authorize', [
			'response' => [],
		] );

		$this->assertFalse( $this->sync->handle_oauth_callback( 'code' ) );
	}

	/**
	 * Test fetch_recent_checkins returns checkins.
	 */
	public function test_fetch_recent_checkins(): void {
		$this->mock_http_response( 'api.untappd.com', [
			'response' => [
				'checkins' => [
					'items' => [
						[
							'checkin_id'      => 12345,
							'checkin_comment' => 'Great beer!',
							'rating_score'    => 4.5,
							'created_at'      => '2024-01-15 12:00:00',
							'beer'            => [
								'beer_name' => 'Test IPA',
								'bid'       => 100,
							],
							'brewery'         => [
								'brewery_name' => 'Test Brewery',
							],
						],
					],
				],
			],
		] );

		$checkins = $this->sync->fetch_recent_checkins( 10 );

		$this->assertCount( 1, $checkins );
		$this->assertSame( 12345, $checkins[0]['checkin_id'] );
		$this->assertSame( 'Test IPA', $checkins[0]['beer_name'] );
	}

	/**
	 * Test fetch_recent_checkins returns empty without token.
	 */
	public function test_fetch_recent_checkins_no_token(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );

		$sync     = new Untappd_Checkin_Sync();
		$checkins = $sync->fetch_recent_checkins();

		$this->assertSame( [], $checkins );
	}

	/**
	 * Test add_syndication_target.
	 */
	public function test_add_syndication_target(): void {
		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayHasKey( 'untappd', $targets );
		$this->assertSame( 'Untappd', $targets['untappd']['name'] );
	}
}

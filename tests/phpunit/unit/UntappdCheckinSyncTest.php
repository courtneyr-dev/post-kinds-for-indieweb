<?php
/**
 * Test the Untappd Checkin Sync class.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\Sync\Untappd_Checkin_Sync;
use PKIW\Tests\ApiTestCase;

/**
 * Test the Untappd Checkin Sync integration.
 *
 * @covers \PKIW\Sync\Untappd_Checkin_Sync
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

		update_option( 'pkiw_api_credentials', [
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
		update_option( 'pkiw_api_credentials', [] );

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
		update_option( 'pkiw_api_credentials', [] );

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

	/**
	 * Serve one Untappd check-in from the mocked API.
	 *
	 * @param string               $created_at When Untappd says it happened.
	 * @param array<string, mixed> $extra      More fields for the check-in.
	 */
	private function serve_checkin( string $created_at, array $extra = [] ): void {
		$this->mock_http_response(
			'api.untappd.com/v4/user/checkins/testuser',
			[
				'response' => [
					'checkins' => [
						'items' => [
							array_merge(
								[
									'checkin_id' => 7001,
									'created_at' => $created_at,
									'beer'       => [ 'beer_name' => 'Test IPA' ],
									'brewery'    => [ 'brewery_name' => 'Test Brewery' ],
								],
								$extra
							),
						],
					],
				],
			]
		);
	}

	/**
	 * Import the served check-in and return its post.
	 *
	 * @return \WP_Post
	 */
	private function import_served_checkin(): \WP_Post {
		wp_set_current_user( 0 );

		$result = $this->sync->import_checkins( 10 );
		$this->assertSame( 1, $result['imported'] );

		$ids = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => 'any',
				'meta_key'    => '_pkiw_untappd_checkin_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => '7001', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'      => 'ids',
			]
		);
		$this->assertCount( 1, $ids );

		$post = get_post( $ids[0] );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post;
	}

	/**
	 * An import stores site-local time in post_date and UTC in post_date_gmt.
	 *
	 * 20:00 UTC on 2026-09-12 is 16:00 in America/New_York (EDT).
	 */
	public function test_import_stores_local_date_and_utc_gmt_on_a_non_utc_site(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$this->serve_checkin( 'Sat, 12 Sep 2026 20:00:00 +0000' );

		$post = $this->import_served_checkin();

		$this->assertSame( '2026-09-12 16:00:00', $post->post_date );
		$this->assertSame( '2026-09-12 20:00:00', $post->post_date_gmt );
	}

	/**
	 * Untappd sends the offset with the date; the moment still lands in UTC.
	 */
	public function test_import_converts_a_dated_offset_to_utc(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$this->serve_checkin( 'Sat, 12 Sep 2026 16:00:00 -0400' );

		$post = $this->import_served_checkin();

		$this->assertSame( '2026-09-12 16:00:00', $post->post_date );
		$this->assertSame( '2026-09-12 20:00:00', $post->post_date_gmt );
	}

	/**
	 * On a UTC site both columns hold the same time.
	 */
	public function test_import_dates_match_on_a_utc_site(): void {
		$this->serve_checkin( 'Sat, 12 Sep 2026 20:00:00 +0000' );

		$post = $this->import_served_checkin();

		$this->assertSame( '2026-09-12 20:00:00', $post->post_date );
		$this->assertSame( '2026-09-12 20:00:00', $post->post_date_gmt );
	}
}

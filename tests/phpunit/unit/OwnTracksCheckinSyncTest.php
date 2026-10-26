<?php
/**
 * Test the OwnTracks Checkin Sync class.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\Sync\OwnTracks_Checkin_Sync;
use WP_UnitTestCase;

/**
 * Test the OwnTracks Checkin Sync integration.
 *
 * @covers \PKIW\Sync\OwnTracks_Checkin_Sync
 */
class OwnTracksCheckinSyncTest extends WP_UnitTestCase {

	/**
	 * Sync instance.
	 *
	 * @var OwnTracks_Checkin_Sync
	 */
	private OwnTracks_Checkin_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'pkiw_settings', [
			'owntracks_enabled'  => true,
			'owntracks_username' => 'testuser',
			'owntracks_password' => 'testpass',
		] );

		$this->sync = new OwnTracks_Checkin_Sync();
	}

	/**
	 * Test service properties.
	 */
	public function test_service_properties(): void {
		$this->assertSame( 'owntracks', $this->sync->get_service_id() );
		$this->assertSame( 'OwnTracks', $this->sync->get_service_name() );
	}

	/**
	 * Test is_connected returns true when enabled.
	 */
	public function test_is_connected_true(): void {
		$this->assertTrue( $this->sync->is_connected() );
	}

	/**
	 * Test is_connected returns false when disabled.
	 */
	public function test_is_connected_false(): void {
		update_option( 'pkiw_settings', [] );

		$sync = new OwnTracks_Checkin_Sync();
		$this->assertFalse( $sync->is_connected() );
	}

	/**
	 * Test get_auth_url returns empty (no OAuth).
	 */
	public function test_get_auth_url_empty(): void {
		$this->assertSame( '', $this->sync->get_auth_url() );
	}

	/**
	 * Test handle_oauth_callback always returns false.
	 */
	public function test_handle_oauth_callback_false(): void {
		$this->assertFalse( $this->sync->handle_oauth_callback( 'code' ) );
	}

	/**
	 * Test fetch_recent_checkins returns empty (push only).
	 */
	public function test_fetch_recent_checkins_empty(): void {
		$this->assertSame( [], $this->sync->fetch_recent_checkins() );
	}

	/**
	 * Test get_webhook_url returns REST URL.
	 */
	public function test_get_webhook_url(): void {
		$url = $this->sync->get_webhook_url();
		$this->assertStringContainsString( 'post-kinds-indieweb/v1/owntracks', $url );
	}

	/**
	 * Test verify_webhook_auth rejects when disabled.
	 */
	public function test_verify_webhook_auth_disabled(): void {
		update_option( 'pkiw_settings', [] );

		$sync    = new OwnTracks_Checkin_Sync();
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );

		$result = $sync->verify_webhook_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test verify_webhook_auth denies when no credentials are configured.
	 *
	 * The integration being enabled with nothing configured is not the same
	 * as an operator choosing to leave it open, so it must fail closed.
	 */
	public function test_verify_webhook_auth_no_credentials(): void {
		update_option( 'pkiw_settings', [
			'owntracks_enabled' => true,
		] );

		$sync    = new OwnTracks_Checkin_Sync();
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );

		$this->assertInstanceOf( \WP_Error::class, $sync->verify_webhook_auth( $request ) );
	}

	/**
	 * Test verify_webhook_auth rejects missing auth header.
	 */
	public function test_verify_webhook_auth_missing_header(): void {
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );

		$result = $this->sync->verify_webhook_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test verify_webhook_auth accepts valid credentials.
	 */
	public function test_verify_webhook_auth_valid(): void {
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( 'testuser:testpass' ) );

		$this->assertTrue( $this->sync->verify_webhook_auth( $request ) );
	}

	/**
	 * Test verify_webhook_auth rejects wrong credentials.
	 */
	public function test_verify_webhook_auth_wrong_credentials(): void {
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( 'wrong:creds' ) );

		$result = $this->sync->verify_webhook_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_webhook returns error on empty payload.
	 */
	public function test_handle_webhook_empty_payload(): void {
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );

		$result = $this->sync->handle_webhook( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_webhook accepts waypoint type.
	 */
	public function test_handle_webhook_waypoint(): void {
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );
		$request->set_body( wp_json_encode( [ '_type' => 'waypoint' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$result = $this->sync->handle_webhook( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}

	/**
	 * Test handle_webhook accepts unknown type.
	 */
	public function test_handle_webhook_unknown_type(): void {
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );
		$request->set_body( wp_json_encode( [ '_type' => 'card' ] ) );
		$request->set_header( 'Content-Type', 'application/json' );

		$result = $this->sync->handle_webhook( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}

	/**
	 * Test add_syndication_target when connected.
	 */
	public function test_add_syndication_target_connected(): void {
		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayHasKey( 'owntracks', $targets );
	}

	/**
	 * Test add_syndication_target when disconnected.
	 */
	public function test_add_syndication_target_disconnected(): void {
		update_option( 'pkiw_settings', [] );
		$sync = new OwnTracks_Checkin_Sync();

		$targets = $sync->add_syndication_target( [] );

		$this->assertArrayNotHasKey( 'owntracks', $targets );
	}

	/**
	 * 2026-07-15 13:00:00 UTC, which is 09:00 in America/New_York (EDT).
	 */
	private const CHECKIN_TIMESTAMP = 1784120400;

	/**
	 * Turn on auto check-ins and transition check-ins, and stub Nominatim.
	 *
	 * Nothing here reaches the network: every outbound request gets a
	 * fixture response, and a request to any other host fails the test.
	 */
	private function enable_checkin_creation(): void {
		update_option( 'pkiw_settings', [
			'owntracks_enabled'            => true,
			'owntracks_username'           => 'testuser',
			'owntracks_password'           => 'testpass',
			'owntracks_auto_checkin'       => true,
			'owntracks_transition_checkin' => true,
		] );

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->assertStringStartsWith( 'https://nominatim.openstreetmap.org/reverse', $url );

				return [
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'name'    => 'Test Cafe',
							'address' => [
								'amenity' => 'Test Cafe',
								'city'    => 'New York',
								'state'   => 'New York',
							],
						]
					),
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	/**
	 * Send a payload through the webhook entry point.
	 *
	 * @param array<string, mixed> $payload OwnTracks message.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function send_webhook( array $payload ) {
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );
		$request->set_body( wp_json_encode( $payload ) );
		$request->set_header( 'Content-Type', 'application/json' );

		return $this->sync->handle_webhook( $request );
	}

	/**
	 * Build a manual-trigger location payload.
	 *
	 * @param int $timestamp Unix timestamp the device recorded.
	 * @return array<string, mixed>
	 */
	private function location_payload( int $timestamp = self::CHECKIN_TIMESTAMP ): array {
		return [
			'_type' => 'location',
			't'     => 'u',
			'lat'   => 40.7128,
			'lon'   => -74.0060,
			'tst'   => $timestamp,
			'tid'   => 'cr',
		];
	}

	/**
	 * Build a region-enter transition payload.
	 *
	 * @param int $timestamp Unix timestamp the device recorded.
	 * @return array<string, mixed>
	 */
	private function transition_payload( int $timestamp = self::CHECKIN_TIMESTAMP ): array {
		return [
			'_type' => 'transition',
			'event' => 'enter',
			'desc'  => 'Home',
			'lat'   => 40.7128,
			'lon'   => -74.0060,
			'tst'   => $timestamp,
		];
	}

	/**
	 * Run the webhook and return the created post.
	 *
	 * @param array<string, mixed> $payload OwnTracks message.
	 * @return \WP_Post
	 */
	private function create_post_through_webhook( array $payload ): \WP_Post {
		$response = $this->send_webhook( $payload );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$post_id = $response->get_data()['post_id'] ?? false;
		$this->assertIsInt( $post_id, 'The webhook should report the created post ID.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		return $post;
	}

	/**
	 * A location check-in from the webhook gets the default author.
	 *
	 * The webhook runs with no logged-in user, so the author comes from the
	 * pkiw_default_author option, the same rule the other importers use.
	 */
	public function test_location_checkin_gets_default_author(): void {
		$this->enable_checkin_creation();
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( 'pkiw_default_author', $editor_id );
		wp_set_current_user( 0 );

		$post = $this->create_post_through_webhook( $this->location_payload() );

		$this->assertSame( $editor_id, (int) $post->post_author );
	}

	/**
	 * A transition check-in from the webhook gets the default author.
	 */
	public function test_transition_checkin_gets_default_author(): void {
		$this->enable_checkin_creation();
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( 'pkiw_default_author', $editor_id );
		wp_set_current_user( 0 );

		$post = $this->create_post_through_webhook( $this->transition_payload() );

		$this->assertSame( $editor_id, (int) $post->post_author );
	}

	/**
	 * A location check-in stores site-local time in post_date and UTC in post_date_gmt.
	 */
	public function test_location_checkin_date_is_local_time_with_utc_gmt(): void {
		$this->enable_checkin_creation();
		update_option( 'timezone_string', 'America/New_York' );

		$post = $this->create_post_through_webhook( $this->location_payload() );

		$this->assertSame( '2026-07-15 09:00:00', $post->post_date );
		$this->assertSame( '2026-07-15 13:00:00', $post->post_date_gmt );
	}

	/**
	 * A transition check-in stores site-local time in post_date and UTC in post_date_gmt.
	 */
	public function test_transition_checkin_date_is_local_time_with_utc_gmt(): void {
		$this->enable_checkin_creation();
		update_option( 'timezone_string', 'America/New_York' );

		$post = $this->create_post_through_webhook( $this->transition_payload() );

		$this->assertSame( '2026-07-15 09:00:00', $post->post_date );
		$this->assertSame( '2026-07-15 13:00:00', $post->post_date_gmt );
	}

	/**
	 * The duplicate window matches a stored check-in on a non-UTC site.
	 *
	 * The window is built from UTC timestamps, so it has to compare against
	 * post_date_gmt. Against the local post_date it would miss the first
	 * check-in and create a second one a minute later.
	 */
	public function test_duplicate_checkin_is_detected_on_non_utc_site(): void {
		$this->enable_checkin_creation();
		update_option( 'timezone_string', 'America/New_York' );

		$this->create_post_through_webhook( $this->location_payload() );
		$second = $this->send_webhook( $this->location_payload( self::CHECKIN_TIMESTAMP + 60 ) );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'duplicate', $second->get_error_code() );
	}
}

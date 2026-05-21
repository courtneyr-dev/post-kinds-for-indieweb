<?php
/**
 * Test the OwnTracks Checkin Sync class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Sync\OwnTracks_Checkin_Sync;
use WP_UnitTestCase;

/**
 * Test the OwnTracks Checkin Sync integration.
 *
 * @covers \PostKindsForIndieWeb\Sync\OwnTracks_Checkin_Sync
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

		update_option( 'post_kinds_indieweb_settings', [
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
		update_option( 'post_kinds_indieweb_settings', [] );

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
		update_option( 'post_kinds_indieweb_settings', [] );

		$sync    = new OwnTracks_Checkin_Sync();
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );

		$result = $sync->verify_webhook_auth( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test verify_webhook_auth allows when no credentials set.
	 */
	public function test_verify_webhook_auth_no_credentials(): void {
		update_option( 'post_kinds_indieweb_settings', [
			'owntracks_enabled' => true,
		] );

		$sync    = new OwnTracks_Checkin_Sync();
		$request = new \WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/owntracks' );

		$this->assertTrue( $sync->verify_webhook_auth( $request ) );
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
		update_option( 'post_kinds_indieweb_settings', [] );
		$sync = new OwnTracks_Checkin_Sync();

		$targets = $sync->add_syndication_target( [] );

		$this->assertArrayNotHasKey( 'owntracks', $targets );
	}
}

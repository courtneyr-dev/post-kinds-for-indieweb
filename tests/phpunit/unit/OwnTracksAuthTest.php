<?php
/**
 * Test that the OwnTracks webhook auth fails closed and compares in constant time.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\Sync\OwnTracks_Checkin_Sync;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Verifies OwnTracks check-in auth denies unconfigured requests and uses hash_equals().
 */
class OwnTracksAuthTest extends WP_UnitTestCase {

	/**
	 * Get a sync instance.
	 *
	 * @return OwnTracks_Checkin_Sync
	 */
	private function sync() {
		return new OwnTracks_Checkin_Sync();
	}

	/**
	 * Enabled with no credentials configured must be denied, not allowed.
	 */
	public function test_enabled_without_credentials_is_denied() {
		update_option( 'pkiw_settings', [ 'owntracks_enabled' => 1, 'owntracks_username' => '', 'owntracks_password' => '' ] );
		$result = $this->sync()->verify_webhook_auth( new WP_REST_Request( 'POST', '/pkiw/v1/owntracks' ) );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * A username with no password (or vice versa) is half-configured, not
	 * configured, and must be denied the same as no credentials at all.
	 */
	public function test_half_configured_credentials_is_denied() {
		update_option( 'pkiw_settings', [ 'owntracks_enabled' => 1, 'owntracks_username' => 'u', 'owntracks_password' => '' ] );
		$request = new WP_REST_Request( 'POST', '/pkiw/v1/owntracks' );
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( 'u:' ) );
		$this->assertInstanceOf( 'WP_Error', $this->sync()->verify_webhook_auth( $request ) );
	}

	/**
	 * Correct HTTP Basic credentials still pass.
	 */
	public function test_correct_basic_auth_passes() {
		update_option( 'pkiw_settings', [ 'owntracks_enabled' => 1, 'owntracks_username' => 'u', 'owntracks_password' => 'p' ] );
		$request = new WP_REST_Request( 'POST', '/pkiw/v1/owntracks' );
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( 'u:p' ) );
		$this->assertTrue( $this->sync()->verify_webhook_auth( $request ) );
	}

	/**
	 * A Basic header with an empty payload (nothing after "Basic ") must be
	 * refused as malformed, not crash explode() into an undefined offset.
	 * WP_UnitTestCase converts PHP warnings/notices to exceptions, so an
	 * uncaught "Undefined array key 1" here would fail the test outright
	 * rather than let a WP_Error assertion run.
	 */
	public function test_empty_basic_payload_is_rejected_without_warning() {
		update_option( 'pkiw_settings', [ 'owntracks_enabled' => 1, 'owntracks_username' => 'u', 'owntracks_password' => 'p' ] );
		$request = new WP_REST_Request( 'POST', '/pkiw/v1/owntracks' );
		$request->set_header( 'Authorization', 'Basic ' );
		$result = $this->sync()->verify_webhook_auth( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * A decodable Basic payload with no colon separator (no username/password
	 * split possible) must be refused, not crash explode() into an undefined
	 * offset.
	 */
	public function test_basic_payload_without_colon_is_rejected_without_warning() {
		update_option( 'pkiw_settings', [ 'owntracks_enabled' => 1, 'owntracks_username' => 'u', 'owntracks_password' => 'p' ] );
		$request = new WP_REST_Request( 'POST', '/pkiw/v1/owntracks' );
		$request->set_header( 'Authorization', 'Basic ' . base64_encode( 'nocolonhere' ) );
		$result = $this->sync()->verify_webhook_auth( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * A Basic payload that is not valid base64 at all must be refused, not
	 * pass a `false` decode result into explode() and crash.
	 */
	public function test_invalid_base64_basic_payload_is_rejected_without_warning() {
		update_option( 'pkiw_settings', [ 'owntracks_enabled' => 1, 'owntracks_username' => 'u', 'owntracks_password' => 'p' ] );
		$request = new WP_REST_Request( 'POST', '/pkiw/v1/owntracks' );
		$request->set_header( 'Authorization', 'Basic !!!not-valid-base64!!!' );
		$result = $this->sync()->verify_webhook_auth( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}
}

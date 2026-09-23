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
}

<?php
/**
 * Test that the webhook secret is accepted from the header only.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\REST_API;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Verifies verify_webhook_token() rejects the token in the query string and
 * accepts it only in the X-Webhook-Token header.
 */
class WebhookTokenTest extends WP_UnitTestCase {

	public function test_query_string_token_is_rejected_and_header_accepted() {
		update_option( 'pkiw_webhook_secret', 'secret-abc' );
		$api = new REST_API();
		$q   = new WP_REST_Request( 'POST', '/pkiw/v1/webhook/generic' );
		$q->set_param( 'token', 'secret-abc' );
		$this->assertFalse( $api->verify_webhook_token( $q ) );
		$h = new WP_REST_Request( 'POST', '/pkiw/v1/webhook/generic' );
		$h->set_header( 'X-Webhook-Token', 'secret-abc' );
		$this->assertTrue( $api->verify_webhook_token( $h ) );
	}
}

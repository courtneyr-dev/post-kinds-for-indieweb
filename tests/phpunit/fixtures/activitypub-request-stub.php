<?php
/**
 * Stand-in for ActivityPub's request check, driven by a global flag.
 *
 * ActivityPub is not loaded in the test suite. The real function lives in the
 * Activitypub namespace; this stand-in answers from $GLOBALS['pkiw_test_activitypub_request'],
 * which is false unless a test sets it.
 *
 * @package PKIW
 */

namespace Activitypub;

if ( ! function_exists( __NAMESPACE__ . '\\is_activitypub_request' ) ) {
	/**
	 * Whether the current request asks for ActivityPub JSON.
	 *
	 * @return bool
	 */
	function is_activitypub_request() {
		return ! empty( $GLOBALS['pkiw_test_activitypub_request'] );
	}
}

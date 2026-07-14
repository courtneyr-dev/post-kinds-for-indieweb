<?php
/**
 * wp-env test fixture: grant Micropub auth to application-password requests.
 *
 * The Micropub endpoint requires is_user_logged_in() PLUS a non-empty
 * IndieAuth token response and scopes (see micropub_get_response()/
 * micropub_get_scopes()). Real IndieAuth token flows are impractical in
 * integration tests, so this mu-plugin (mapped only in .wp-env.json)
 * supplies the response/scopes for any authenticated request that did
 * not already get them from the IndieAuth plugin.
 *
 * Never ship this outside the test environment.
 *
 * @package PKIW
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'indieauth_scopes',
	static function ( $scopes ) {
		if ( ! empty( $scopes ) || ! is_user_logged_in() ) {
			return $scopes;
		}
		return array( 'create', 'update', 'media' );
	},
	20
);

add_filter(
	'indieauth_response',
	static function ( $response ) {
		if ( ! empty( $response ) || ! is_user_logged_in() ) {
			return $response;
		}
		return array(
			'me'        => home_url( '/' ),
			'client_id' => 'https://pkiw-tests.example/',
			'scope'     => 'create update media',
		);
	},
	20
);

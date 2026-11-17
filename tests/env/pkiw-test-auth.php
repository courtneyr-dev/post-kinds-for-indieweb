<?php
/**
 * wp-env test fixture: grant Micropub auth to application-password requests.
 *
 * The Micropub endpoint requires is_user_logged_in() PLUS a non-empty
 * IndieAuth token response and scopes (see micropub_get_response()/
 * micropub_get_scopes()). Real IndieAuth token flows are impractical in
 * integration tests, so this mu-plugin (mapped only in .wp-env.json)
 * supplies the response/scopes for application-password requests that did
 * not already get them from the IndieAuth plugin. Cookie sessions keep their
 * own capabilities: IndieAuth scopes limit a session to posts, so giving them
 * to a logged-in admin made REST page creation fail with 403 in E2E specs.
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
		if ( ! empty( $scopes ) || ! is_user_logged_in() || ! did_action( 'application_password_did_authenticate' ) ) {
			return $scopes;
		}
		return array( 'create', 'update', 'media' );
	},
	20
);

add_filter(
	'indieauth_response',
	static function ( $response ) {
		if ( ! empty( $response ) || ! is_user_logged_in() || ! did_action( 'application_password_did_authenticate' ) ) {
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

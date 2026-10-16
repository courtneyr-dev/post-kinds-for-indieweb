<?php
/**
 * Public, pluggable location-privacy helpers.
 *
 * Deliberately declared in the global namespace (unlike the rest of the
 * plugin) so themes and other consumers can call it unprefixed, guarded
 * by function_exists() the way WordPress pluggable functions normally
 * are. Requires its own file because includes/functions-checkin.php and
 * friends use unbracketed `namespace PKIW;`, and PHP forbids mixing
 * unbracketed and bracketed namespace declarations in the same file.
 *
 * @package PKIW
 * @since   1.8.2
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'pkiw_get_visible_location_fields' ) ) {
	/**
	 * Public wrapper for Meta_Fields::get_visible_location_fields(), for
	 * themes and other consumers. See that method for the contract.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, bool>
	 */
	function pkiw_get_visible_location_fields( int $post_id ): array {
		return \PKIW\Meta_Fields::get_visible_location_fields( $post_id );
	}
}

if ( ! function_exists( 'pkiw_get_visible_health_fields' ) ) {
	/**
	 * Public wrapper for Meta_Fields::get_visible_health_fields(), for
	 * themes and other consumers: which exercise or sleep fields print for a
	 * post, by field key without the prefix. Activity and sleep duration are
	 * always true. The opt-in fields are true only when the post's toggle is
	 * on, for every requester. Heart rate, calories, other biometrics and
	 * start and end clock times have no key and never print.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, bool> Empty for a post of any other kind.
	 */
	function pkiw_get_visible_health_fields( int $post_id ): array {
		return \PKIW\Meta_Fields::get_visible_health_fields( $post_id );
	}
}

if ( ! function_exists( 'pkiw_get_safe_title' ) ) {
	/**
	 * The title to print for a post: the stored title, or "Check-in, <date>"
	 * when the stored title was generated from a location that is hidden.
	 * The same value the `the_title` filter returns outside wp-admin.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	function pkiw_get_safe_title( int $post_id ): string {
		return \PKIW\Title_Privacy::safe_title( $post_id );
	}
}

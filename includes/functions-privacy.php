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

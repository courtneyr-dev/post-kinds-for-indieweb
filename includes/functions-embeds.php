<?php
/**
 * Embed Helper Functions
 *
 * Cached oEmbed lookups for dynamic block rendering.
 *
 * @package PostKindsForIndieWeb
 * @since 1.2.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get oEmbed HTML for a URL through the WP_Embed cache.
 *
 * Calling wp_oembed_get() performs a live HTTP request on every call, so
 * using it during server-side block render blocks the page on a remote
 * fetch. This wrapper goes through WP_Embed::shortcode() instead: it caches
 * results (and failures) in post meta or the oembed_cache post type. Link-tag
 * discovery is disabled so only URLs matching a registered oEmbed provider
 * are ever fetched; anything else fails fast with no network request.
 *
 * @param string $url URL to embed.
 * @return string|false Embed HTML, or false when no embed is available.
 */
function get_cached_embed_html( string $url ) {
	global $wp_embed;

	if ( '' === $url || ! $wp_embed instanceof \WP_Embed ) {
		return false;
	}

	$previous_fail_state            = $wp_embed->return_false_on_fail;
	$wp_embed->return_false_on_fail = true;
	add_filter( 'embed_oembed_discover', '__return_false', 100 );

	$html = $wp_embed->shortcode( [], $url );

	remove_filter( 'embed_oembed_discover', '__return_false', 100 );
	$wp_embed->return_false_on_fail = $previous_fail_state;

	return is_string( $html ) && '' !== $html ? $html : false;
}

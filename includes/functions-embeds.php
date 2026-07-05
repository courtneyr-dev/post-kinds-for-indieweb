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
 * @param mixed $url URL to embed.
 * @return string|false Embed HTML, or false when no embed is available.
 */
function get_cached_embed_html( mixed $url ) {
	global $wp_embed;

	if ( ! is_string( $url ) || '' === $url || ! $wp_embed instanceof \WP_Embed ) {
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

/**
 * Get the embed HTML for a card's media, letting a site substitute its own
 * player before any oEmbed fetch.
 *
 * A site can short-circuit the default oEmbed by returning a non-null string
 * from the `pkiw_card_embed_html` filter — for example to render a YouTube URL
 * through an accessible player (with captions and a transcript) instead of the
 * raw provider iframe. Returning null (the default, when nothing hooks the
 * filter) falls through to the cached oEmbed lookup, so the plugin has no hard
 * dependency on any particular player.
 *
 * @param mixed  $url  URL to embed.
 * @param string $kind Card kind slug (watch, listen, …) for filter context.
 * @return string|false Embed HTML, or false when no embed is available.
 */
function get_card_embed_html( mixed $url, string $kind = '' ) {
	/**
	 * Filter a card's media embed before the default oEmbed lookup runs.
	 *
	 * @param string|null $embed Replacement embed HTML, or null to use oEmbed.
	 * @param mixed       $url   The URL being embedded.
	 * @param string      $kind  The card kind slug (watch, listen, checkin, …).
	 */
	$pre = apply_filters( 'pkiw_card_embed_html', null, $url, $kind );

	if ( null !== $pre ) {
		return is_string( $pre ) && '' !== $pre ? $pre : false;
	}

	return get_cached_embed_html( $url );
}

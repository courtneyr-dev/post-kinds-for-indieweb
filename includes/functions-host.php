<?php
/**
 * One rule for a URL's host, and the cite host meta derived from it.
 *
 * Archives group cited posts by site, and labels name the site, so
 * www.example.com, Example.com and example.com have to be one value.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta key holding the normalized host of `_pkiw_cite_url`.
 *
 * Written whenever the cite URL is added, changed or deleted, so it can
 * group and order a query. Nothing else writes it.
 *
 * @since 1.9.0
 */
const CITE_HOST_META = '_pkiw_cite_host';

/**
 * A host in the one form this plugin compares and prints.
 *
 * Lowercase, with no leading `www.`, port or trailing dot. An
 * internationalized name reads in Unicode whether it was stored that way
 * or as punycode, so `xn--bcher-kva.example` and `BÜCHER.example` are both
 * `bücher.example`. Without the intl extension, punycode stays punycode.
 * Text that can't be a host, such as `not%20a%20url`, gives ''.
 *
 * @since 1.9.0
 *
 * @param string $host Host, without a scheme or path.
 * @return string Normalized host, or '' for none.
 */
function normalize_host( string $host ): string {
	$host = trim( $host );
	if ( '' === $host ) {
		return '';
	}

	if ( str_starts_with( $host, '[' ) ) {
		// An IPv6 literal: the brackets end the host, a port may follow.
		$end     = strpos( $host, ']' );
		$literal = false === $end ? '' : strtolower( substr( $host, 0, $end + 1 ) );
		return 1 === preg_match( '/^\[[0-9a-f:.]+\]$/', $literal ) ? $literal : '';
	}

	$host = rtrim( (string) preg_replace( '/:\d*$/', '', $host ), '.' );

	if ( function_exists( 'idn_to_utf8' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) ) {
		// UTS #46 maps case and decodes punycode labels. It refuses some
		// names DNS allows (an underscore, say), and those keep their text.
		$unicode = idn_to_utf8( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		if ( is_string( $unicode ) && '' !== $unicode ) {
			$host = $unicode;
		}
	}

	$host = function_exists( 'mb_strtolower' ) ? mb_strtolower( $host, 'UTF-8' ) : strtolower( $host );
	$host = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;

	// Dot-separated labels of letters, digits, hyphens and underscores in
	// any script. Anything else (a space, a percent sign) names no host.
	return 1 === preg_match( '/^[\p{L}\p{M}\p{N}_-]+(?:\.[\p{L}\p{M}\p{N}_-]+)*$/u', $host ) ? $host : '';
}

/**
 * The normalized host of a URL.
 *
 * Reads the host from the URL text itself: parse_url() can mangle a
 * non-ASCII host under some locales.
 *
 * @since 1.9.0
 *
 * @param string $url Absolute or protocol-relative URL.
 * @return string Normalized host, or '' when the URL names none.
 */
function url_host( string $url ): string {
	if ( 1 !== preg_match( '#^(?:[a-z][a-z0-9+.\-]*:)?//(?:[^/?\#@]*@)?(\[[^\]]*\]|[^:/?\#]*)#i', trim( $url ), $matches ) ) {
		return '';
	}

	return normalize_host( $matches[1] );
}

/**
 * Keep `_pkiw_cite_host` in step with `_pkiw_cite_url`.
 *
 * Runs on every write of the cite URL, whoever writes it: the card sync,
 * Micropub, Quick Post, an import or REST.
 *
 * @since 1.9.0
 *
 * @param int|int[] $meta_id   Meta ID (an array of IDs on delete). Unused.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 * @param mixed     $value     Meta value.
 * @return void
 */
function sync_cite_host( $meta_id, int $object_id, string $meta_key, $value ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
	if ( Meta_Fields::PREFIX . 'cite_url' !== $meta_key ) {
		return;
	}

	$host = doing_action( 'deleted_post_meta' ) ? '' : url_host( is_string( $value ) ? $value : '' );

	if ( '' === $host ) {
		delete_post_meta( $object_id, CITE_HOST_META );
		return;
	}

	update_post_meta( $object_id, CITE_HOST_META, $host );
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\sync_cite_host', 10, 4 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\sync_cite_host', 10, 4 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\\sync_cite_host', 10, 4 );

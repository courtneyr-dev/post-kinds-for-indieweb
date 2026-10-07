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
 * Lowercase, with no leading `www.`, port or trailing dot. Both spellings
 * of an internationalized name give one value. It reads in Unicode when
 * the intl extension is loaded and ICU's Spoofchecker flags nothing in
 * it, so `xn--bcher-kva.example` and `BÜCHER.example` are both
 * `bücher.example`. A name it flags stays punycode, as browsers print it:
 * `аpple.com` with a Cyrillic а is `xn--pple-43d.com`. Without intl every
 * internationalized name reads as punycode; ASCII names are the same
 * either way. Text that can't be a host, such as `not%20a%20url`, gives ''.
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
		// An IPv6 literal: the brackets end the host, and only a port may follow.
		if ( 1 !== preg_match( '/^\[([^\]]*)\](?::\d*)?$/', $host, $matches )
			|| false === filter_var( $matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return '';
		}
		return '[' . strtolower( $matches[1] ) . ']';
	}

	$host = rtrim( (string) preg_replace( '/:\d*$/', '', $host ), '.' );
	$host = function_exists( 'mb_strtolower' ) ? mb_strtolower( $host, 'UTF-8' ) : strtolower( $host );

	if ( 1 === preg_match( '/[^\x00-\x7f]/', $host ) ) {
		// Core's encoder needs no intl, so the punycode is the same everywhere.
		// It throws a Requests exception for a label too long to encode.
		try {
			$host = \WpOrg\Requests\IdnaEncoder::encode( $host );
		} catch ( \Exception ) {
			return '';
		}
	}

	$host = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;

	// Dot-separated labels of letters, digits, hyphens and underscores.
	// Anything else (a space, a percent sign, a colon) names no host.
	if ( 1 !== preg_match( '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/', $host ) ) {
		return '';
	}

	return unicode_host( $host );
}

/**
 * The Unicode spelling of a punycode host, when nothing in it can pass
 * for another name.
 *
 * @since 1.9.0
 *
 * @param string $host Lowercase ASCII host.
 * @return string Unicode host, or $host when it has no punycode label,
 *                intl isn't loaded, or ICU's Spoofchecker flags the name.
 */
function unicode_host( string $host ): string {
	if ( ! str_contains( $host, 'xn--' ) || ! function_exists( 'idn_to_utf8' )
		|| ! defined( 'INTL_IDNA_VARIANT_UTS46' ) || ! class_exists( \Spoofchecker::class ) ) {
		return $host;
	}

	$unicode = idn_to_utf8( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
	if ( ! is_string( $unicode ) || '' === $unicode ) {
		return $host;
	}

	static $checker = null;
	$checker      ??= new \Spoofchecker();

	return $checker->isSuspicious( $unicode ) ? $host : $unicode;
}

/**
 * The normalized host of a URL.
 *
 * Reads the host from the URL text itself, because parse_url() can mangle
 * a non-ASCII host under some locales. It splits the authority the way
 * parse_url() and the HTTP client do: user info runs to the last `@`, and
 * only a numeric port may follow the host.
 *
 * @since 1.9.0
 *
 * @param string $url Absolute or protocol-relative URL.
 * @return string Normalized host, or '' when the URL names none.
 */
function url_host( string $url ): string {
	if ( 1 !== preg_match( '#^(?:[a-z][a-z0-9+.\-]*:)?//([^/?\#]*)#i', trim( $url ), $matches ) ) {
		return '';
	}

	$authority = $matches[1];
	$at        = strrpos( $authority, '@' );
	$host_port = false === $at ? $authority : substr( $authority, $at + 1 );

	if ( 1 !== preg_match( '/^(\[[^\]]*\]|[^:\[\]]*)(?::\d*)?$/', $host_port, $parts ) ) {
		return '';
	}

	return normalize_host( $parts[1] );
}

/**
 * Keep `_pkiw_cite_host` in step with `_pkiw_cite_url`.
 *
 * Runs on every write of the cite URL, whoever writes it: the card sync,
 * Micropub, Quick Post, an import or REST. Reads the stored rows rather
 * than the hook's value, so the host names the URL get_post_meta()
 * returns after any write, including a delete of one row of several or a
 * write made inside another meta hook.
 *
 * @since 1.9.0
 *
 * @param int|int[] $meta_id   Meta ID (an array of IDs on delete). Unused.
 * @param int       $object_id Post ID.
 * @param string    $meta_key  Meta key.
 * @return void
 */
function sync_cite_host( $meta_id, int $object_id, string $meta_key ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
	if ( Meta_Fields::PREFIX . 'cite_url' !== $meta_key ) {
		return;
	}

	$url  = get_metadata_raw( 'post', $object_id, $meta_key, true );
	$host = url_host( is_string( $url ) ? $url : '' );

	if ( '' === $host ) {
		delete_post_meta( $object_id, CITE_HOST_META );
		return;
	}

	update_post_meta( $object_id, CITE_HOST_META, $host );
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\sync_cite_host', 10, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\sync_cite_host', 10, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\\sync_cite_host', 10, 3 );

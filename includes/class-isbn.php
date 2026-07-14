<?php
/**
 * ISBN-10/13 validation and conversion plus Amazon ASIN helpers.
 *
 * @package PKIW
 * @since   1.2.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ISBN-10/13 validation and conversion plus Amazon ASIN helpers.
 *
 * Print-book ASINs equal the ISBN-10, which is what makes Kindle
 * preview URLs derivable from a completed ISBN. Kindle-edition ASINs
 * (B0…) are NOT derivable — they arrive only via an Amazon URL.
 *
 * @since 1.2.0
 */
final class Isbn {

	/**
	 * Validate an ISBN-10 or ISBN-13 checksum.
	 *
	 * @param string $isbn ISBN, with or without hyphens.
	 * @return bool True if the checksum is valid.
	 */
	public static function validate( string $isbn ): bool {
		$isbn = self::clean( $isbn );
		if ( 10 === strlen( $isbn ) ) {
			return self::check10( $isbn );
		}
		if ( 13 === strlen( $isbn ) && ctype_digit( $isbn ) ) {
			return self::check13( $isbn );
		}
		return false;
	}

	/**
	 * Convert an ISBN-13 to its ISBN-10 form.
	 *
	 * @param string $isbn13 ISBN-13, with or without hyphens.
	 * @return string|null ISBN-10, or null if not convertible (e.g. 979- prefix).
	 */
	public static function to10( string $isbn13 ): ?string {
		$isbn13 = self::clean( $isbn13 );
		if ( 13 !== strlen( $isbn13 ) || ! self::check13( $isbn13 ) || 0 !== strpos( $isbn13, '978' ) ) {
			return null;
		}
		$core = substr( $isbn13, 3, 9 );
		$sum  = 0;
		foreach ( str_split( $core ) as $i => $digit ) {
			$sum += ( 10 - $i ) * (int) $digit;
		}
		$check = ( 11 - ( $sum % 11 ) ) % 11;
		return $core . ( 10 === $check ? 'X' : (string) $check );
	}

	/**
	 * Convert an ISBN-10 to its ISBN-13 form.
	 *
	 * @param string $isbn10 ISBN-10, with or without hyphens.
	 * @return string|null ISBN-13, or null if the ISBN-10 is invalid.
	 */
	public static function to13( string $isbn10 ): ?string {
		$isbn10 = self::clean( $isbn10 );
		if ( 10 !== strlen( $isbn10 ) || ! self::check10( $isbn10 ) ) {
			return null;
		}
		$core = '978' . substr( $isbn10, 0, 9 );
		$sum  = 0;
		foreach ( str_split( $core ) as $i => $digit ) {
			$sum += ( 0 === $i % 2 ? 1 : 3 ) * (int) $digit;
		}
		return $core . (string) ( ( 10 - ( $sum % 10 ) ) % 10 );
	}

	/**
	 * Extract an Amazon ASIN from a product or Kindle preview URL.
	 *
	 * @param string $url Amazon URL.
	 * @return string|null ASIN, or null if the URL isn't a recognized Amazon URL.
	 */
	public static function asin_from_url( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		// Host must END at a real Amazon registrable domain: amazon.<suffix> or
		// *.amazon.<suffix> (covers read.amazon.com). An open-ended pattern like
		// amazon\.[a-z.]+$ would accept spoofed hosts such as amazon.evil.com.
		$amazon_host = '/(^|\.)amazon\.(com|ca|de|fr|es|it|nl|se|pl|in|cn|sg|ae|sa|com\.au|com\.br|com\.mx|com\.tr|co\.uk|co\.jp)$/';
		if ( ! is_string( $host ) || ! preg_match( $amazon_host, strtolower( $host ) ) ) {
			return null;
		}
		if ( preg_match( '#/(?:dp|gp/product)/([A-Z0-9]{10})#', $url, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]asin=([A-Z0-9]{10})#', $url, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Build a Kindle preview embed URL for an ASIN.
	 *
	 * @param string $asin Amazon ASIN (Kindle or print-equivalent ISBN-10).
	 * @return string Embed URL.
	 */
	public static function kindle_embed_url( string $asin ): string {
		return 'https://read.amazon.com/kp/embed?asin=' . rawurlencode( $asin ) . '&preview=inline';
	}

	/**
	 * Strip hyphens/spaces and uppercase (for the ISBN-10 check digit 'X').
	 *
	 * @param string $isbn Raw ISBN.
	 * @return string Cleaned ISBN.
	 */
	private static function clean( string $isbn ): string {
		return strtoupper( str_replace( [ '-', ' ' ], '', $isbn ) );
	}

	/**
	 * Validate an ISBN-10 checksum.
	 *
	 * @param string $isbn Cleaned 10-character ISBN.
	 * @return bool True if valid.
	 */
	private static function check10( string $isbn ): bool {
		if ( ! preg_match( '/^\d{9}[\dX]$/', $isbn ) ) {
			return false;
		}
		$sum = 0;
		foreach ( str_split( $isbn ) as $i => $char ) {
			$sum += ( 10 - $i ) * ( 'X' === $char ? 10 : (int) $char );
		}
		return 0 === $sum % 11;
	}

	/**
	 * Validate an ISBN-13 checksum.
	 *
	 * @param string $isbn Cleaned 13-character ISBN.
	 * @return bool True if valid.
	 */
	private static function check13( string $isbn ): bool {
		$sum = 0;
		foreach ( str_split( $isbn ) as $i => $digit ) {
			$sum += ( 0 === $i % 2 ? 1 : 3 ) * (int) $digit;
		}
		return 0 === $sum % 10;
	}
}

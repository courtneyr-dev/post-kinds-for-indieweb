<?php
/**
 * Guard remote poster and comic-cover sideloads.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and pins remote watch-poster requests.
 */
final class Artwork_Fetch_Guard {

	/**
	 * Test-only DNS resolver.
	 *
	 * @var callable|null
	 */
	private static $resolver = null;

	/**
	 * Replace DNS lookup for tests.
	 *
	 * @internal
	 *
	 * @param callable|null $resolver Callback receiving a host and returning IP address strings.
	 */
	public static function use_resolver( ?callable $resolver ): void {
		self::$resolver = $resolver;
	}

	/**
	 * Validate an artwork URL and resolve its pinned IPv4 address.
	 *
	 * @param string $url  Artwork URL.
	 * @param string $kind Kind slug.
	 * @return array{ok:bool,retryable:bool,code:string,ip:string}
	 */
	public static function check( string $url, string $kind = 'watch' ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| array_key_exists( 'user', $parts )
			|| array_key_exists( 'pass', $parts )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return self::refused();
		}

		$host = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
		if ( ! self::is_dns_name( $host ) ) {
			return self::refused();
		}

		$defaults = [
			'watch'  => [ 'image.tmdb.org', 'static.tvmaze.com', 'simkl.in' ],
			'comics' => [ 'static.metron.cloud' ],
		];
		$hosts    = (array) apply_filters(
			'pkiw_artwork_sideload_hosts',
			$defaults[ $kind ] ?? [],
			$kind
		);
		$hosts    = array_values(
			array_filter(
				array_map(
					static fn( $allowed ): string => strtolower( trim( is_string( $allowed ) ? $allowed : '' ) ),
					$hosts
				),
				[ self::class, 'is_dns_name' ]
			)
		);
		if ( ! in_array( $host, $hosts, true ) ) {
			return self::refused();
		}

		$addresses = self::resolve( $host );
		$ipv4      = [];
		foreach ( $addresses as $address ) {
			if ( ! self::is_public_address( $address ) ) {
				return self::refused();
			}
			if ( false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$ipv4[] = $address;
			}
		}

		if ( [] === $ipv4 ) {
			return [
				'ok'        => false,
				'retryable' => true,
				'code'      => 'pkiw_artwork_unresolved',
				'ip'        => '',
			];
		}

		if ( ! class_exists( '\\WpOrg\\Requests\\Transport\\Curl' )
			|| ! \WpOrg\Requests\Transport\Curl::test( [ \WpOrg\Requests\Capability::SSL => true ] ) ) {
			return [
				'ok'        => false,
				'retryable' => false,
				'code'      => 'pkiw_artwork_no_curl',
				'ip'        => '',
			];
		}

		return [
			'ok'        => true,
			'retryable' => false,
			'code'      => '',
			'ip'        => $ipv4[0],
		];
	}

	/**
	 * Run one request while its HTTP arguments and cURL destination are pinned.
	 *
	 * @internal
	 *
	 * @param string   $url      Exact URL being fetched.
	 * @param string   $ip       Validated public IPv4 address.
	 * @param callable $callback Fetch callback.
	 * @return mixed Callback result.
	 */
	public static function with_pinned_request( string $url, string $ip, callable $callback ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$args = static function ( array $request_args, string $request_url ) use ( $url ): array {
			if ( $url !== $request_url ) {
				return $request_args;
			}

			$request_args['timeout']             = 20;
			$request_args['redirection']         = 0;
			$request_args['limit_response_size'] = 10 * MB_IN_BYTES;
			$request_args['reject_unsafe_urls']  = true;

			return $request_args;
		};
		$curl = static function ( $handle, array $request_args, string $request_url ) use ( $host, $ip, $url ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( $url !== $request_url ) {
				return;
			}

			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- This hook pins WordPress's cURL transport after validation.
			curl_setopt( $handle, CURLOPT_RESOLVE, [ "{$host}:443:{$ip}" ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- This hook pins WordPress's cURL transport after validation.
		};

		add_filter( 'http_request_args', $args, 10, 2 );
		add_action( 'http_api_curl', $curl, 10, 3 );
		try {
			return $callback();
		} finally {
			remove_filter( 'http_request_args', $args, 10 );
			remove_action( 'http_api_curl', $curl, 10 );
		}
	}

	/**
	 * Resolve A and AAAA records.
	 *
	 * @param string $host DNS host.
	 * @return string[] Address strings.
	 */
	private static function resolve( string $host ): array {
		if ( is_callable( self::$resolver ) ) {
			$addresses = (array) call_user_func( self::$resolver, $host );
			return array_values( array_filter( $addresses, 'is_string' ) );
		}

		$records = dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( ! is_array( $records ) ) {
			return [];
		}

		$addresses = [];
		foreach ( $records as $record ) {
			if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
				$addresses[] = $record['ip'];
			} elseif ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
				$addresses[] = $record['ipv6'];
			}
		}

		return $addresses;
	}

	/**
	 * Whether a host is a DNS name rather than an address literal.
	 *
	 * @param mixed $host Candidate host.
	 */
	private static function is_dns_name( $host ): bool {
		if ( ! is_string( $host ) || '' === $host || strlen( $host ) > 253
			|| str_contains( $host, '[' ) || str_contains( $host, ':' ) || str_ends_with( $host, '.' )
			|| false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		foreach ( explode( '.', $host ) as $label ) {
			if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $label ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an address is globally routable and outside core's refused ranges.
	 *
	 * @param string $ip Address string.
	 */
	private static function is_public_address( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return ! self::is_refused_ipv4( $ip );
		}

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return false;
		}

		foreach ( [ '::/128', '::1/128', '::/96', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8', '2001:db8::/32', '100::/64', '64:ff9b::/96', '2002::/16' ] as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return false;
			}
		}

		if ( self::in_range( $ip, '::ffff:0:0/96' ) ) {
			$packed = inet_pton( $ip );
			$mapped = false === $packed ? false : inet_ntop( substr( $packed, 12, 4 ) );
			return is_string( $mapped ) && ! self::is_refused_ipv4( $mapped );
		}

		return true;
	}

	/**
	 * Match WordPress core's refused IPv4 table.
	 *
	 * @param string $ip Valid IPv4 address.
	 */
	private static function is_refused_ipv4( string $ip ): bool {
		$parts = array_map( 'intval', explode( '.', $ip ) );

		return 127 === $parts[0] || 10 === $parts[0] || 0 === $parts[0]
			|| ( 172 === $parts[0] && 16 <= $parts[1] && 31 >= $parts[1] )
			|| ( 192 === $parts[0] && 168 === $parts[1] )
			|| ( 192 === $parts[0] && 0 === $parts[1] && 0 === $parts[2] )
			|| ( 192 === $parts[0] && 0 === $parts[1] && 2 === $parts[2] )
			|| ( 192 === $parts[0] && 88 === $parts[1] && 99 === $parts[2] )
			|| ( 198 === $parts[0] && 51 === $parts[1] && 100 === $parts[2] )
			|| ( 203 === $parts[0] && 0 === $parts[1] && 113 === $parts[2] )
			|| ( 169 === $parts[0] && 254 === $parts[1] )
			|| ( 100 === $parts[0] && 64 <= $parts[1] && 127 >= $parts[1] )
			|| ( 198 === $parts[0] && 18 <= $parts[1] && 19 >= $parts[1] )
			|| ( 224 <= $parts[0] && 239 >= $parts[0] )
			|| 240 <= $parts[0];
	}

	/**
	 * Test an IP address against a CIDR range.
	 *
	 * @param string $ip    Address.
	 * @param string $range CIDR range.
	 */
	private static function in_range( string $ip, string $range ): bool {
		[ $network, $prefix ] = explode( '/', $range, 2 );

		$address = inet_pton( $ip );
		$network = inet_pton( $network );
		if ( false === $address || false === $network || strlen( $address ) !== strlen( $network ) ) {
			return false;
		}

		$bits  = (int) $prefix;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $address, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}

		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $remaining ) ) & 0xff;
		return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}

	/**
	 * Standard permanent refusal result.
	 *
	 * @return array{ok:bool,retryable:bool,code:string,ip:string}
	 */
	private static function refused(): array {
		return [
			'ok'        => false,
			'retryable' => false,
			'code'      => 'pkiw_artwork_refused',
			'ip'        => '',
		];
	}
}

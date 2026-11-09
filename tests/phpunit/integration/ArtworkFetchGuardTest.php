<?php
/**
 * Artwork_Fetch_Guard integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW {
	/**
	 * Test-aware wrapper for the cURL options installed by Artwork_Fetch_Guard.
	 *
	 * @param mixed $handle cURL handle or test stand-in.
	 * @param int   $option cURL option.
	 * @param mixed $value  Option value.
	 */
	function curl_setopt( $handle, int $option, $value ): bool {
		if ( isset( $GLOBALS['pkiw_artwork_curl_calls'] ) && is_array( $GLOBALS['pkiw_artwork_curl_calls'] ) ) {
			$GLOBALS['pkiw_artwork_curl_calls'][ $option ] = $value;
			return true;
		}

		return \curl_setopt( $handle, $option, $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Delegates production calls when the test spy is inactive.
	}
}

namespace {
	use PKIW\Artwork_Fetch_Guard;

	/**
	 * @group integration
	 */
	final class ArtworkFetchGuardTest extends WP_UnitTestCase {

		/** @var string[] */
		private array $addresses = [ '93.184.216.34' ];

		/** @var string[] */
		private array $resolved_hosts = [];

		private int $http_requests = 0;

		public function set_up(): void {
			parent::set_up();
			$this->addresses      = [ '93.184.216.34' ];
			$this->resolved_hosts = [];
			$this->http_requests  = 0;
			Artwork_Fetch_Guard::use_resolver(
				function ( string $host ): array {
					$this->resolved_hosts[] = $host;
					return $this->addresses;
				}
			);
			add_filter(
				'pre_http_request',
				function ( $preempt ) {
					unset( $preempt );
					++$this->http_requests;
					return new WP_Error( 'http_request_failed', 'Test request stopped before network access.' );
				}
			);
		}

		public function tear_down(): void {
			Artwork_Fetch_Guard::use_resolver( null );
			unset( $GLOBALS['pkiw_artwork_curl_calls'] );
			parent::tear_down();
		}

		/**
		 * @dataProvider refused_url_provider
		 */
		public function test_refuses_invalid_url_classes_before_http( string $url ): void {
			$result = Artwork_Fetch_Guard::check( $url );

			$this->assertFalse( $result['ok'] );
			$this->assertFalse( $result['retryable'] );
			$this->assertSame( 'pkiw_artwork_refused', $result['code'] );
			$this->assertSame( 0, $this->http_requests );
		}

		/**
		 * @return array<string,array{0:string}>
		 */
		public static function refused_url_provider(): array {
			return [
				'http'          => [ 'http://image.tmdb.org/poster.jpg' ],
				'user'          => [ 'https://user@image.tmdb.org/poster.jpg' ],
				'password'      => [ 'https://user:pass@image.tmdb.org/poster.jpg' ],
				'port'          => [ 'https://image.tmdb.org:8443/poster.jpg' ],
				'foreign host'  => [ 'https://example.net/poster.jpg' ],
				'IPv4 literal'  => [ 'https://169.254.169.254/poster.jpg' ],
				'IPv6 literal'  => [ 'https://[2606:4700::1111]/poster.jpg' ],
				'trailing dot'  => [ 'https://image.tmdb.org./poster.jpg' ],
			];
		}

		/**
		 * @dataProvider refused_address_provider
		 */
		public function test_refuses_every_private_or_reserved_address_before_http( string $ip ): void {
			$this->addresses = [ $ip ];

			$result = Artwork_Fetch_Guard::check( 'https://image.tmdb.org/poster.jpg' );

			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'pkiw_artwork_refused', $result['code'] );
			$this->assertSame( 0, $this->http_requests );
		}

		/**
		 * @return array<string,array{0:string}>
		 */
		public static function refused_address_provider(): array {
			return [
				'loopback'       => [ '127.0.0.1' ],
				'private 10'     => [ '10.0.0.5' ],
				'this network'   => [ '0.0.0.1' ],
				'private 172'    => [ '172.16.0.1' ],
				'private 192'    => [ '192.168.1.1' ],
				'protocol'       => [ '192.0.0.1' ],
				'test net 1'     => [ '192.0.2.1' ],
				'relay anycast'  => [ '192.88.99.1' ],
				'test net 2'     => [ '198.51.100.1' ],
				'test net 3'     => [ '203.0.113.1' ],
				'link local'     => [ '169.254.169.254' ],
				'CGNAT'          => [ '100.64.0.1' ],
				'benchmark'      => [ '198.18.0.1' ],
				'multicast'      => [ '224.0.0.1' ],
				'reserved'       => [ '240.0.0.1' ],
				'unspecified v6' => [ '::' ],
				'loopback v6'    => [ '::1' ],
				'private v6'     => [ 'fd00::1' ],
				'link local v6'  => [ 'fe80::1' ],
				'mapped v6'      => [ '::ffff:127.0.0.1' ],
				'NAT64'          => [ '64:ff9b::1' ],
				'6to4'           => [ '2002::1' ],
				'IPv4 compatible' => [ '::192.0.2.1' ],
				'site local v6'   => [ 'fec0::1' ],
				'multicast v6'    => [ 'ff02::1' ],
				'documentation v6' => [ '2001:db8::1' ],
				'discard only v6' => [ '100::1' ],
			];
		}

		public function test_refuses_mixed_answers_when_one_is_private(): void {
			$this->addresses = [ '93.184.216.34', '10.0.0.5' ];

			$result = Artwork_Fetch_Guard::check( 'https://image.tmdb.org/poster.jpg' );

			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'pkiw_artwork_refused', $result['code'] );
			$this->assertSame( 0, $this->http_requests );
		}

		public function test_unresolved_and_public_aaaa_only_answers_are_retryable(): void {
			foreach ( [ [], [ '2606:4700::1111' ] ] as $addresses ) {
				$this->addresses = $addresses;
				$result          = Artwork_Fetch_Guard::check( 'https://image.tmdb.org/poster.jpg' );
				$this->assertFalse( $result['ok'] );
				$this->assertTrue( $result['retryable'] );
				$this->assertSame( 'pkiw_artwork_unresolved', $result['code'] );
			}
			$this->assertSame( 0, $this->http_requests );
		}

		public function test_aaaa_only_private_answer_is_refused_before_unresolved_result(): void {
			$this->addresses = [ 'fe80::1' ];

			$result = Artwork_Fetch_Guard::check( 'https://image.tmdb.org/poster.jpg' );

			$this->assertSame( 'pkiw_artwork_refused', $result['code'] );
			$this->assertFalse( $result['retryable'] );
		}

		public function test_public_a_and_aaaa_return_the_first_ipv4(): void {
			$this->addresses = [ '2606:4700::1111', '93.184.216.34', '8.8.8.8' ];

			$result = Artwork_Fetch_Guard::check( 'https://image.tmdb.org/poster.jpg' );

			$this->assertTrue( $result['ok'] );
			$this->assertSame( '93.184.216.34', $result['ip'] );
			$this->assertSame( [ 'image.tmdb.org' ], $this->resolved_hosts );
		}

		public function test_filter_adds_a_dns_host_but_cannot_add_an_ip_literal(): void {
			$filter = static function ( array $hosts, string $kind ): array {
				self::assertSame( 'watch', $kind );
				$hosts[] = 'posters.example';
				$hosts[] = '93.184.216.34';
				return $hosts;
			};
			add_filter( 'pkiw_artwork_sideload_hosts', $filter, 10, 2 );
			try {
				$this->assertTrue( Artwork_Fetch_Guard::check( 'https://posters.example/x.jpg' )['ok'] );
				$this->assertFalse( Artwork_Fetch_Guard::check( 'https://93.184.216.34/x.jpg' )['ok'] );
			} finally {
				remove_filter( 'pkiw_artwork_sideload_hosts', $filter, 10 );
			}
		}

		public function test_filter_added_dns_host_with_link_local_answer_is_refused_before_http(): void {
			$this->addresses = [ '169.254.169.254' ];
			$filter          = static function ( array $hosts ): array {
				$hosts[] = 'linklocal-320.example';
				return $hosts;
			};
			add_filter( 'pkiw_artwork_sideload_hosts', $filter );
			try {
				$result = Artwork_Fetch_Guard::check( 'https://linklocal-320.example/x.jpg' );
				$this->assertFalse( $result['ok'] );
				$this->assertFalse( $result['retryable'] );
				$this->assertSame( 'pkiw_artwork_refused', $result['code'] );
				$this->assertSame( 0, $this->http_requests );
			} finally {
				remove_filter( 'pkiw_artwork_sideload_hosts', $filter );
			}
		}

		public function test_allowed_host_reaches_the_http_layer_once(): void {
			$url    = 'https://image.tmdb.org/poster.jpg';
			$result = Artwork_Fetch_Guard::check( $url );
			$this->assertTrue( $result['ok'] );

			$response = Artwork_Fetch_Guard::with_pinned_request(
				$url,
				$result['ip'],
				static fn() => wp_remote_get( $url )
			);

			$this->assertWPError( $response );
			$this->assertSame( 1, $this->http_requests );
		}

		public function test_resolver_receives_the_host_and_empty_result_means_unresolved(): void {
			$this->addresses = [];

			$result = Artwork_Fetch_Guard::check( 'https://static.tvmaze.com/poster.jpg' );

			$this->assertSame( [ 'static.tvmaze.com' ], $this->resolved_hosts );
			$this->assertSame( 'pkiw_artwork_unresolved', $result['code'] );
		}

		public function test_pinned_request_limits_http_and_sets_curl_destination_for_exact_url(): void {
			global $wp_filter;

			$url = 'https://image.tmdb.org/poster.jpg';
			$GLOBALS['pkiw_artwork_curl_calls'] = [];
			$before_callbacks = array_keys( $wp_filter['http_request_args']->callbacks[10] ?? [] );
			$args_filter      = null;

			$result = Artwork_Fetch_Guard::with_pinned_request(
				$url,
				'93.184.216.34',
				function () use ( $before_callbacks, &$args_filter, $url ): string {
					global $wp_filter;

					foreach ( $wp_filter['http_request_args']->callbacks[10] ?? [] as $id => $callback ) {
						if ( ! in_array( $id, $before_callbacks, true ) && $callback['function'] instanceof Closure ) {
							$args_filter = $callback['function'];
						}
					}
					$args = apply_filters( 'http_request_args', [], $url );
					$this->assertSame( 20, $args['timeout'] );
					$this->assertSame( 0, $args['redirection'] );
					$this->assertSame( 10 * MB_IN_BYTES, $args['limit_response_size'] );
					$this->assertTrue( $args['reject_unsafe_urls'] );
					do_action( 'http_api_curl', new stdClass(), [], $url );
					return 'done';
				}
			);

			$this->assertSame( 'done', $result );
			$this->assertSame( CURL_IPRESOLVE_V4, $GLOBALS['pkiw_artwork_curl_calls'][ CURLOPT_IPRESOLVE ] );
			$this->assertSame( [ 'image.tmdb.org:443:93.184.216.34' ], $GLOBALS['pkiw_artwork_curl_calls'][ CURLOPT_RESOLVE ] );
			$this->assertInstanceOf( Closure::class, $args_filter );
			$this->assertFalse( has_filter( 'http_request_args', $args_filter ) );
		}
	}
}

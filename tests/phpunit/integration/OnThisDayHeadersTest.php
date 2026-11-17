<?php
/** On This Day cache-header coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
use PKIW\Taxonomy;

if ( ! function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
	function pantheon_wp_clear_edge_paths( array $paths ): void {
		$GLOBALS['pkiw_otd_test_path_purges'][] = $paths;
	}
}
if ( ! function_exists( 'pantheon_clear_edge_paths' ) ) {
	function pantheon_clear_edge_paths( array $paths ): void {
		$GLOBALS['pkiw_otd_test_platform_paths'][] = $paths;
	}
}
if ( ! function_exists( 'pantheon_wp_clear_edge_keys' ) ) {
	function pantheon_wp_clear_edge_keys( array $keys ): void {
		$GLOBALS['pkiw_otd_test_key_purges'][] = $keys;
	}
}
if ( ! function_exists( 'pantheon_clear_edge_keys' ) ) {
	function pantheon_clear_edge_keys( array $keys ): void {
		$GLOBALS['pkiw_otd_test_platform_keys'][] = $keys;
	}
}

/** @group integration */
final class OnThisDayHeadersTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->today = new DateTimeImmutable( '2026-08-19 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
		On_This_Day::reset();
		$GLOBALS['pkiw_otd_test_path_purges'] = [];
		$GLOBALS['pkiw_otd_test_key_purges'] = [];
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		On_This_Day::override_headers_for_tests( null );
		On_This_Day::reset();
		remove_all_filters( 'pkiw_otd_edge_purge' );
		parent::tear_down();
	}

	public function test_branch_b_runs_last_and_overwrites_public_cache_control(): void {
		$this->assertSame( PHP_INT_MAX, has_action( 'send_headers', [ On_This_Day::class, 'send_day_headers' ] ) );
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		$lines = [];
		$sink = static function ( string $line ) use ( &$lines ): void { $lines[] = $line; };
		On_This_Day::override_headers_for_tests( $sink );
		$rival = static function () use ( &$lines ): void { $lines[] = 'Cache-Control: public, max-age=600, must-revalidate'; };
		add_action( 'send_headers', $rival, 99 );
		add_action( 'send_headers', $rival, 999 );
		try {
			do_action( 'send_headers', $GLOBALS['wp'] );
		} finally {
			remove_action( 'send_headers', $rival, 99 );
			remove_action( 'send_headers', $rival, 999 );
		}
		$cache = array_values( array_filter( $lines, static fn( string $line ): bool => str_starts_with( $line, 'Cache-Control:' ) ) );
		$this->assertSame( 'Cache-Control: ' . wp_get_nocache_headers()['Cache-Control'], end( $cache ) );
		$this->assertTrue( On_This_Day::is_uncached() );
		$this->assertSame( [], $GLOBALS['pkiw_otd_test_path_purges'] );
	}

	public function test_headers_already_sent_other_pages_and_filter_false_emit_nothing(): void {
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		$lines = [];
		On_This_Day::override_headers_for_tests( static function ( string $line ) use ( &$lines ): void { $lines[] = $line; }, true );
		On_This_Day::send_day_headers();
		$this->assertSame( [], $lines );
		$this->assertFalse( On_This_Day::is_uncached() );
		$this->assertFalse( On_This_Day::edge_can_purge(), 'All four functions remain insufficient while the opt-in filter is false.' );
		$this->go_to( home_url( '/?p=0' ) );
		$this->assertFalse( apply_filters( 'pantheon_skip_cache_control', false ) );
	}

	public function test_branch_a_caps_ttl_and_deduplicates_paths_and_keys_at_shutdown(): void {
		$enable = '__return_true';
		add_filter( 'pkiw_otd_edge_purge', $enable );
		$this->assertTrue( On_This_Day::edge_can_purge() );
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2025-08-19 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		On_This_Day::invalidate( $id );
		On_This_Day::invalidate( $id );
		On_This_Day::flush_invalidation();
		$this->assertSame( [ [ '/on-this-day/', '/on-this-day', '/stream/', '/stream' ] ], $GLOBALS['pkiw_otd_test_path_purges'] );
		$this->assertSame( [ [ 'pkiw-otd-0819' ] ], $GLOBALS['pkiw_otd_test_key_purges'] );
		$this->assertSame( 600, On_This_Day::max_age( 600 ) );
	}

	public function test_midnight_arithmetic_handles_fixed_dst_and_non_dst_zones(): void {
		$cases = [
			[ 'America/New_York', '2026-03-08 00:00:00', 82800 ],
			[ 'America/New_York', '2026-11-01 00:00:00', 90000 ],
			[ 'Asia/Kolkata', '2026-03-08 00:00:00', 86400 ],
		];
		foreach ( $cases as [ $zone, $time, $expected ] ) {
			update_option( 'timezone_string', $zone );
			$this->assertSame( $expected, On_This_Day::seconds_until_midnight( new DateTimeImmutable( $time, new DateTimeZone( $zone ) ) ) );
		}
	}

	public function test_rucss_exclusion_and_empty_route_stylesheet_are_registered(): void {
		$this->assertContains( 'styles/on-this-day.css', apply_filters( 'perfmatters_rucss_excluded_stylesheets', [] ) );
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		On_This_Day::enqueue_style();
		$this->assertTrue( wp_style_is( On_This_Day::STYLE, 'enqueued' ) );
	}
}

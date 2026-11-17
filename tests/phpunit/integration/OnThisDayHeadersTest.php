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
		$GLOBALS['pkiw_otd_test_platform_paths'] = [];
		$GLOBALS['pkiw_otd_test_platform_keys'] = [];
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

	public function test_day_dependent_predicate_covers_route_stream_paging_and_embed_only(): void {
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		$this->assertTrue( apply_filters( 'pantheon_skip_cache_control', false ) );

		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:post-kinds-indieweb/on-this-day {"context":"stream"} /-->' ] );
		foreach ( [ get_permalink( $page ), add_query_arg( 'query-1-page', 2, get_permalink( $page ) ), trailingslashit( get_permalink( $page ) ) . 'embed/' ] as $url ) {
			On_This_Day::reset();
			$this->go_to( $url );
			$this->assertTrue( apply_filters( 'pantheon_skip_cache_control', false ), $url );
		}

		$other_page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'No block' ] );
		$single = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => 'Single' ] );
		foreach ( [ get_permalink( $other_page ), get_permalink( $single ) ] as $url ) {
			On_This_Day::reset();
			$this->go_to( $url );
			$this->assertFalse( apply_filters( 'pantheon_skip_cache_control', false ), $url );
			On_This_Day::override_headers_for_tests( static function (): void {} );
			On_This_Day::send_day_headers();
			$this->assertFalse( On_This_Day::is_uncached(), $url );
		}
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

	/** @dataProvider invalidation_adapter_provider */
	public function test_each_c7_event_adapter_sends_one_exact_path_and_key_purge( string $event ): void {
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2025-08-19 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		$post = get_post( $id );
		if ( 'status' === $event ) {
			On_This_Day::status_changed( 'private', 'publish', $post );
		} elseif ( 'post-updated' === $event ) {
			$before = clone $post;
			$before->post_date = '2025-08-18 10:00:00';
			On_This_Day::post_updated( $id, $post, $before );
		} elseif ( str_starts_with( $event, 'post-event-' ) ) {
			On_This_Day::post_event( $id, $post );
		} elseif ( 'terms' === $event ) {
			On_This_Day::terms_changed( $id, [], [], Taxonomy::TAXONOMY, false, [] );
		} elseif ( 'relationships' === $event ) {
			On_This_Day::term_relationships_deleted( $id, [], Taxonomy::TAXONOMY );
		} elseif ( str_starts_with( $event, 'meta-' ) ) {
			$meta_key = 'meta-visibility' === $event ? \PKIW\Public_Listing::meta_keys()[0] : ( 'meta-thumbnail' === $event ? '_thumbnail_id' : \PKIW\Title_Privacy::deciding_meta()[0] );
			On_This_Day::meta_changed( 1, $id, $meta_key, '' );
		} else {
			On_This_Day::site_rule_changed();
		}
		On_This_Day::flush_invalidation();
		$this->assertSame( [ [ '/on-this-day/', '/on-this-day', '/stream/', '/stream' ] ], $GLOBALS['pkiw_otd_test_path_purges'], $event );
		$expected_keys = 'post-updated' === $event ? [ 'pkiw-otd-0818', 'pkiw-otd-0819' ] : [ 'pkiw-otd-0819' ];
		$this->assertCount( 1, $GLOBALS['pkiw_otd_test_key_purges'], $event );
		$sent = $GLOBALS['pkiw_otd_test_key_purges'][0];
		sort( $sent );
		$this->assertSame( $expected_keys, $sent, $event );
	}

	public static function invalidation_adapter_provider(): array {
		return [
			'transition_post_status' => [ 'status' ],
			'post_updated' => [ 'post-updated' ],
			'trashed_post' => [ 'post-event-trash' ],
			'untrashed_post' => [ 'post-event-untrash' ],
			'deleted_post' => [ 'post-event-delete' ],
			'set_object_terms' => [ 'terms' ],
			'deleted_term_relationships' => [ 'relationships' ],
			'visibility meta' => [ 'meta-visibility' ],
			'thumbnail meta' => [ 'meta-thumbnail' ],
			'title privacy meta' => [ 'meta-title' ],
			'timezone string' => [ 'site-rule-timezone' ],
			'gmt offset' => [ 'site-rule-gmt' ],
			'kind edited' => [ 'site-rule-edited-kind' ],
			'kind deleted' => [ 'site-rule-delete-kind' ],
		];
	}

	public function test_surrogate_keys_match_each_surface_and_skip_single_without_pick(): void {
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		$this->assertSame( [ 'base', 'pkiw-otd-0819' ], On_This_Day::surrogate_keys( [ 'base' ] ) );

		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:post-kinds-indieweb/on-this-day {"context":"stream"} /-->' ] );
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( [ 'pkiw-otd-0819' ], On_This_Day::surrogate_keys( [] ) );

		$viewed = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2025-08-18 12:00:00' ] );
		$pick = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2024-08-18 12:00:00' ] );
		wp_set_object_terms( $pick, 'note', Taxonomy::TAXONOMY );
		$this->go_to( get_permalink( $viewed ) );
		$this->assertSame( [ 'pkiw-otd-0818' ], On_This_Day::surrogate_keys( [] ) );

		$without_pick = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2025-08-17 12:00:00' ] );
		$this->go_to( get_permalink( $without_pick ) );
		$this->assertSame( [], On_This_Day::surrogate_keys( [] ) );
	}

	public function test_max_age_priority_midnight_floor_and_final_public_cap(): void {
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$this->assertSame( PHP_INT_MAX, has_filter( 'pantheon_cache_default_max_age', [ On_This_Day::class, 'max_age' ] ) );
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		$this->assertSame( On_This_Day::seconds_until_midnight(), On_This_Day::max_age( PHP_INT_MAX ) );

		On_This_Day::override_now( new DateTimeImmutable( '2026-08-19 23:59:01', wp_timezone() ) );
		$lines = [];
		On_This_Day::override_headers_for_tests( static function ( string $line ) use ( &$lines ): void { $lines[] = $line; } );
		On_This_Day::send_day_headers();
		$this->assertTrue( On_This_Day::is_uncached() );
		$this->assertContains( 'Cache-Control: ' . wp_get_nocache_headers()['Cache-Control'], $lines );

		On_This_Day::reset();
		On_This_Day::override_now( new DateTimeImmutable( '2026-08-19 23:58:59', wp_timezone() ) );
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		$lines = [];
		On_This_Day::override_headers_for_tests( static function ( string $line ) use ( &$lines ): void { $lines[] = $line; } );
		$rival = static function () use ( &$lines ): void { $lines[] = 'Cache-Control: public, max-age=600, must-revalidate'; };
		add_action( 'send_headers', $rival, 99 );
		try {
			do_action( 'send_headers', $GLOBALS['wp'] );
		} finally {
			remove_action( 'send_headers', $rival, 99 );
		}
		$cache = array_values( array_filter( $lines, static fn( string $line ): bool => str_starts_with( $line, 'Cache-Control:' ) ) );
		$this->assertMatchesRegularExpression( '/max-age=([0-9]+)/', (string) end( $cache ) );
		preg_match( '/max-age=([0-9]+)/', (string) end( $cache ), $match );
		$this->assertLessThanOrEqual( On_This_Day::seconds_until_midnight(), (int) $match[1] );
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

	public function test_midnight_arithmetic_exact_boundaries_across_dst_and_fixed_zones(): void {
		$days = [
			[ 'America/New_York', '2026-03-09 00:00:00' ],
			[ 'America/New_York', '2026-11-02 00:00:00' ],
			[ 'Asia/Kolkata', '2026-03-09 00:00:00' ],
		];
		foreach ( $days as [ $zone, $midnight_text ] ) {
			update_option( 'timezone_string', $zone );
			$midnight = new DateTimeImmutable( $midnight_text, new DateTimeZone( $zone ) );
			foreach ( [ 7200, 61, 59, 1 ] as $seconds ) {
				$before = ( new DateTimeImmutable( '@' . ( $midnight->getTimestamp() - $seconds ) ) )->setTimezone( new DateTimeZone( $zone ) );
				$this->assertSame( $seconds, On_This_Day::seconds_until_midnight( $before ), $zone . ' ' . $midnight_text . ' -' . $seconds );
			}
		}
	}

	public function test_rucss_exclusion_and_empty_route_stylesheet_are_registered(): void {
		$this->assertContains( 'styles/on-this-day.css', apply_filters( 'perfmatters_rucss_excluded_stylesheets', [] ) );
		$this->go_to( home_url( '/?pkiw_on_this_day=1' ) );
		On_This_Day::enqueue_style();
		$this->assertTrue( wp_style_is( On_This_Day::STYLE, 'enqueued' ) );
	}
}

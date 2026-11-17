<?php
/** Wrapped public-listing eligibility coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Abilities\Wrapped_Abilities;
use PKIW\Public_Eligibility;
use PKIW\Public_Listing;
use PKIW\Taxonomy;
use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedEligibilityTest extends WrappedTestCase {
	private const TITLE  = 'Sentinel Hidden Read Qx7';
	private const COVER  = 'https://example.invalid/qx7-cover.jpg';
	private const AUTHOR = 'Sentinel Author Qx7';

	public function set_up(): void {
		parent::set_up();
		register_post_meta( 'post', 'activitypub_content_visibility', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true ] );
		if ( null === wp_get_ability( 'post-kinds/kind-year-stats' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
	}

	public function test_sentinel_variants_never_enter_metrics_top_cache_ability_or_bindings(): void {
		$year        = (int) wp_date( 'Y' );
		$future_date = $year . '-12-20 12:00:00';
		$variants = [
			'private' => [ 'post_status' => 'private' ],
			'password' => [ 'post_status' => 'publish', 'post_password' => 'secret' ],
			'draft' => [ 'post_status' => 'draft' ],
			'future' => [ 'post_status' => 'future', 'post_date' => $future_date, 'post_date_gmt' => $future_date ],
			'quiet' => [ 'post_status' => 'publish' ],
			'follow-password' => [ 'post_status' => 'publish', 'post_password' => 'secret' ],
			'trash' => [ 'post_status' => 'publish' ],
		];
		foreach ( $variants as $case => $post ) {
			$id = $this->read_post(
				2026,
				[ 'read_pages' => 7777, 'read_rating' => 5, 'read_cover' => self::COVER, 'read_author' => self::AUTHOR ],
				array_merge( [ 'post_title' => self::TITLE ], $post )
			);
			if ( 'quiet' === $case ) {
				update_post_meta( $id, 'activitypub_content_visibility', 'quiet_public' );
			}
			if ( 'follow-password' === $case ) {
				wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
			}
			if ( 'trash' === $case ) {
				wp_trash_post( $id );
			}
		}
		$control = $this->read_post( 2026, [ 'read_pages' => 111 ], [ 'post_title' => 'Local control' ] );
		update_post_meta( $control, 'activitypub_content_visibility', 'local' );
		Wrapped_Stats::bump();
		$data    = Wrapped_Stats::for_kind_year( 'read', 2026 );
		$ability = Wrapped_Abilities::instance()->stats( [ 'kind' => 'read', 'year' => 2026 ] );
		$wrapped = $this->wrapped_post();
		$binding = [ $this->binding( $wrapped, 'finished' ), $this->binding( $wrapped, 'pages' ), $this->binding( $wrapped, 'count_line' ) ];
		$gen     = (string) get_option( 'pkiw_wrapped_gen' );
		$cache   = get_transient( Wrapped_Stats::transient_key( 'read', 2026, $gen ) );
		$this->assertIsArray( $cache, 'the payload must be cached for the cache check to mean anything' );
		$joined  = maybe_serialize( [ $data, $ability, $binding, $cache ] );
		foreach ( [ self::TITLE, self::COVER, self::AUTHOR, '7777' ] as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $joined, $sentinel );
		}
		$this->assertSame( 1, $this->metric( $data, 'finished' )['value'] );
		$this->assertSame( [ $control ], array_column( $data['top'], 'post_id' ) );
		$this->assertSame( 111, $this->metric( $data, 'pages' )['value'], 'only the local control contributes pages' );
	}

	public function test_cli_override_and_admin_rest_edit_do_not_widen_numbers(): void {
		$this->read_post();
		$this->read_post( 2026, [], [ 'post_status' => 'private' ] );
		$expected = Wrapped_Stats::compute( 'read', 2026 );
		Public_Eligibility::override_cli_for_tests( true );
		try {
			$this->assertSame( $expected, Wrapped_Stats::compute( 'read', 2026 ) );
		} finally {
			Public_Eligibility::override_cli_for_tests( null );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/post-kinds/kind-year-stats/run' );
		$request->set_query_params( [ 'context' => 'edit', 'input' => [ 'kind' => 'read', 'year' => 2026 ] ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'REST context=edit ability failed; response=' . print_r( $response->get_data(), true ) );
		$this->assertSame( 1, $this->metric( $response->get_data(), 'finished' )['value'] );
	}

	public function test_candidate_sql_contains_public_eligibility_and_q10_not_exists_without_join(): void {
		global $wpdb;
		$sql = '';
		$capture = static function ( string $request ) use ( &$sql ): string {
			$sql = $request;
			return $request;
		};
		$this->read_post();
		$all     = [];
		$capture = static function ( string $request ) use ( &$all ): string {
			$all[] = $request;
			return $request;
		};
		// The SQL Wrapped_Stats::compute() itself runs (A5), not a query built by the test.
		add_filter( 'posts_request', $capture );
		Wrapped_Stats::compute( 'read', 2026 );
		remove_filter( 'posts_request', $capture );
		$this->assertCount( 2, $all, 'set A (finished date) and set B (post date)' );
		$sql = implode( "\n", $all );
		foreach ( $all as $one ) {
			$this->assertStringContainsString( Public_Eligibility::sql( $wpdb->posts ), $one );
			$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1 FROM', $one );
			$this->assertStringContainsString( "post_type = 'post'", $one );
		}
		$this->assertStringContainsString( Public_Eligibility::sql( $wpdb->posts ), $sql );
		$this->assertStringContainsString( 'NOT EXISTS ( SELECT 1 FROM', $sql );
		$this->assertStringNotContainsString( 'JOIN ' . $wpdb->postmeta . ' pkiw_apv', $sql );
	}

	public function test_only_post_type_post_counts_so_every_counted_write_bumps(): void {
		register_post_type( 'x267_cpt', [ 'public' => true ] );
		register_taxonomy_for_object_type( Taxonomy::TAXONOMY, 'x267_cpt' );
		$year = (int) wp_date( 'Y' ) - 1;
		$this->kind_post( 'watch', $year, [ 'watch_status' => 'watched' ], [ 'post_type' => 'x267_cpt' ] );
		$this->kind_post( 'watch', $year, [ 'watch_status' => 'watched' ] );
		Wrapped_Stats::bump();
		$this->assertSame( 1, $this->metric( Wrapped_Stats::for_kind_year( 'watch', $year ), 'watched' )['value'], 'a non-post type attached to kind is not counted (C4 tracks post only)' );
		unregister_post_type( 'x267_cpt' );
	}

	public function test_guard_reads_primed_caches(): void {
		global $wpdb;
		$year = (int) wp_date( 'Y' ) - 1;
		for ( $i = 0; $i < 20; ++$i ) {
			$this->kind_post( 'watch', $year, [ 'watch_status' => 'watched' ] );
		}
		wp_cache_flush();
		$before = $wpdb->num_queries;
		Wrapped_Stats::compute( 'watch', $year );
		$this->assertLessThan( 20, $wpdb->num_queries - $before, 'C3 step 2: prime per chunk before the per-post guard' );
	}

	public function test_hidden_governed_follow_post_never_counts(): void {
		$this->kind_post( 'follow', 2026, [], [ 'post_password' => 'secret' ] );
		$this->assertSame( 0, $this->metric( Wrapped_Stats::compute( 'follow', 2026 ), 'posts' )['value'] );
	}
}

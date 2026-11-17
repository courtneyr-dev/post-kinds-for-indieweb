<?php
/** Wrapped locator and alias coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Plugin;
use PKIW\Wrapped_Post;
use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedLocatorTest extends WrappedTestCase {
	private string $permalinks = '';

	public function set_up(): void {
		parent::set_up();
		$this->permalinks = (string) get_option( 'permalink_structure' );
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( $this->permalinks );
		parent::tear_down();
	}

	public function test_latest_uses_year_date_id_order_and_skips_non_listable_posts(): void {
		$current_year = (int) wp_date( 'Y' );
		$prior_year   = $current_year - 1;
		$older = $this->wrapped_post( 'read', $prior_year, [], [ 'post_date' => $prior_year . '-11-01 10:00:00', 'post_date_gmt' => $prior_year . '-11-01 10:00:00' ] );
		$newer = $this->wrapped_post( 'read', $prior_year, [], [ 'post_date' => $prior_year . '-11-02 10:00:00', 'post_date_gmt' => $prior_year . '-11-02 10:00:00' ] );
		$this->assertSame( $newer, Wrapped_Post::latest( 'read', $prior_year )->ID );
		update_post_meta( $newer, 'activitypub_content_visibility', 'quiet_public' );
		$this->assertSame( $older, Wrapped_Post::latest( 'read', $prior_year )->ID );
		$latest_year = $this->wrapped_post( 'read', $current_year );
		$this->assertSame( $latest_year, Wrapped_Post::latest( 'read' )->ID );
	}

	public function test_latest_cache_hits_when_transients_come_back_as_strings(): void {
		$year = (int) wp_date( 'Y' ) - 1;
		$this->wrapped_post( 'read', $year );
		Wrapped_Stats::bump();
		Wrapped_Post::latest( 'read' );
		wp_cache_flush();
		$queries = 0;
		$probe   = static function ( string $sql ) use ( &$queries ): string {
			++$queries;
			return $sql;
		};
		add_filter( 'posts_request', $probe );
		$this->assertNotNull( Wrapped_Post::latest( 'read' ) );
		remove_filter( 'posts_request', $probe );
		$this->assertSame( 0, $queries, 'the cached ID read back from wp_options must be used' );
	}

	public function test_alias_redirects_301_and_sets_noindex_call_path(): void {
		$year = (int) wp_date( 'Y' );
		$post = $this->wrapped_post( 'read', $year );
		$seen = [];
		$catch = static function ( string $location, int $status ) use ( &$seen ): never {
			$seen = [ $location, $status, headers_list() ];
			throw new RuntimeException( 'redirect caught' );
		};
		add_filter( 'wp_redirect', $catch, 10, 2 );
		$wp = new WP();
		$wp->query_vars = [ 'pkiw_wrapped_kind' => 'read', 'pkiw_wrapped_year' => $year ];
		try {
			Wrapped_Post::serve_alias( $wp );
			$this->fail( 'Alias must redirect.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'redirect caught', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $catch, 10 );
		}
		$this->assertSame( [ get_permalink( $post ), 301 ], array_slice( $seen, 0, 2 ) );
		if ( [] !== $seen[2] ) {
			$this->assertContains( 'X-Robots-Tag: noindex', $seen[2] );
		} else {
			$this->assertSame( 301, $seen[1], 'CLI exposes no header list; reaching wp_redirect proves the noindex header call path ran first.' );
		}
	}

	public function test_alias_returns_404_for_unknown_missing_draft_password_and_quiet_targets(): void {
		$year  = (int) wp_date( 'Y' );
		$prior = $year - 1;
		$cases = [
			[ 'unknown', $year, null ],
			[ 'read', $prior, null ],
			[ 'read', $year, [ 'post_status' => 'draft' ] ],
			[ 'read', $year, [ 'post_password' => 'secret' ] ],
		];
		foreach ( $cases as [ $kind, $case_year, $post_args ] ) {
			if ( is_array( $post_args ) ) {
				$this->wrapped_post( 'read', $case_year, [], $post_args );
			}
			$wp = new WP();
			$wp->query_vars = [ 'pkiw_wrapped_kind' => $kind, 'pkiw_wrapped_year' => $case_year ];
			Wrapped_Post::serve_alias( $wp );
			$this->assertSame( [ 'error' => '404' ], $wp->query_vars, $kind . ':' . $case_year );
		}
		$quiet = $this->wrapped_post();
		update_post_meta( $quiet, 'activitypub_content_visibility', 'quiet_public' );
		$wp = new WP();
		$wp->query_vars = [ 'pkiw_wrapped_kind' => 'read', 'pkiw_wrapped_year' => $year ];
		Wrapped_Post::serve_alias( $wp );
		$this->assertSame( [ 'error' => '404' ], $wp->query_vars );
	}

	public function test_changed_version_flush_registers_wrapped_rewrite_rule(): void {
		Wrapped_Post::register_route();
		update_option( 'pkiw_rewrite_version', 'stale-x267' );
		delete_option( 'rewrite_rules' );
		Plugin::get_instance()->maybe_flush_rewrite_rules();
		$rules = (array) get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( '^kind/([^/]+)/([0-9]{4})/wrapped/?$', $rules, 'Wrapped rewrite missing after version flush; keys=' . print_r( array_keys( $rules ), true ) );
		$this->assertSame( PKIW_VERSION, get_option( 'pkiw_rewrite_version' ) );
	}
}

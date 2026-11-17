<?php
/** Wrapped non-listable host coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedHostGateTest extends WrappedTestCase {
	public function set_up(): void {
		parent::set_up();
		for ( $index = 0; $index < 3; ++$index ) {
			$this->read_post( 2026, [ 'read_pages' => 100, 'read_rating' => 5 ], [ 'post_title' => 'Host top ' . $index ] );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_quiet_draft_private_password_future_hosts_print_no_wrapped_data(): void {
		$year        = (int) wp_date( 'Y' );
		$future_date = $year . '-12-20 12:00:00';
		$variants = [
			'quiet_public' => [ 'post_status' => 'publish' ],
			'draft' => [ 'post_status' => 'draft' ],
			'private' => [ 'post_status' => 'private' ],
			'password' => [ 'post_status' => 'publish', 'post_password' => 'secret' ],
			'future' => [ 'post_status' => 'future', 'post_date' => $future_date, 'post_date_gmt' => $future_date ],
		];
		foreach ( $variants as $case => $args ) {
			$id = $this->wrapped_post( 'read', 2026, [], $args );
			if ( 'quiet_public' === $case ) {
				update_post_meta( $id, 'activitypub_content_visibility', 'quiet_public' );
			}
			$url = get_permalink( $id );
			if ( in_array( $case, [ 'draft', 'future' ], true ) ) {
				$url = add_query_arg( 'preview', 'true', $url );
			}
			$this->go_to( $url );
			$this->assertHostAbsent( $id, $case . ' without cookie' );
			if ( 'password' === $case ) {
				$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = wp_hash_password( 'secret' );
				$this->assertHostAbsent( $id, $case . ' with cookie' );
				unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
			}
		}
	}

	public function test_listable_twin_prints_metrics_on_own_single(): void {
		$id = $this->wrapped_post();
		$this->go_to( get_permalink( $id ) );
		$html = $this->render_wrapped( $id );
		$this->assertStringContainsString( 'pkiw-wrapped', $html, 'The listable twin must render Wrapped markup; html=' . $html );
		$this->assertStringContainsString( $this->metric( Wrapped_Stats::for_kind_year( 'read', 2026 ), 'finished' )['label'], $html );
		$this->assertNotSame( [], PKIW\wrapped_facts( $id ) );
	}

	public function test_withdrawn_singular_exception_calls_are_absent_from_block_class(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-wrapped-blocks.php' );
		$this->assertIsString( $source );
		$this->assertStringNotContainsString( 'is_singular', $source );
		$this->assertStringNotContainsString( 'get_queried_object_id', $source );
	}

	private function assertHostAbsent( int $id, string $case ): void {
		$html = $this->render_wrapped( $id );
		$this->assertSame( '', $html, $case );
		$keys = [ 'title', 'url', 'count_line' ];
		foreach ( Wrapped_Stats::for_kind_year( 'read', 2026 )['metrics'] as $metric ) {
			$keys[] = $metric['key'];
			$keys[] = 'label:' . $metric['key'];
		}
		foreach ( $keys as $key ) {
			$this->assertSame( '', $this->binding( $id, $key, [ 'post_id' => $id ] ), $case . ' args ' . $key );
			$this->assertSame( '', $this->binding( $id, $key ), $case . ' context ' . $key );
		}
		$this->assertSame( [], PKIW\wrapped_facts( $id ), $case . ' facts' );
	}
}

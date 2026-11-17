<?php
/** Wrapped calendar-year coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedStatsYearTest extends WrappedTestCase {
	public function test_finished_date_boundary_and_invalid_fallback_in_both_timezones(): void {
		$current_year  = (int) wp_date( 'Y' );
		$finished_year = $current_year - 2;
		$posted_year   = $current_year - 1;
		foreach ( [ 'America/Los_Angeles', 'Pacific/Auckland' ] as $timezone ) {
			update_option( 'timezone_string', $timezone );
			$boundary = $this->read_post(
				$posted_year,
				[ 'read_finished_at' => $finished_year . '-12-30', 'read_pages' => 321, 'read_rating' => 5 ],
				[ 'post_title' => 'Boundary ' . $timezone, 'post_date' => $posted_year . '-01-02 10:00:00', 'post_date_gmt' => $posted_year . '-01-02 10:00:00' ]
			);
			$fallback = $this->read_post( $finished_year, [ 'read_finished_at' => 'invalid date' ], [ 'post_title' => 'Fallback ' . $timezone ] );
			$anchor   = $this->read_post( $finished_year, [ 'read_finished_at' => $finished_year . '-06-01', 'read_rating' => 3 ], [ 'post_title' => 'Rating anchor ' . $timezone ] );
			$data     = Wrapped_Stats::compute( 'read', $finished_year );
			$this->assertSame( 3, $this->metric( $data, 'finished' )['value'], $timezone );
			$this->assertContains( $boundary, array_column( $data['top'], 'post_id' ), $timezone );
			$this->assertContains( $fallback, array_column( $data['top'], 'post_id' ), $timezone );
			$this->assertSame( 521, $this->metric( $data, 'pages' )['value'], $timezone );
			$this->assertSame( 4.0, $this->metric( $data, 'average_rating' )['value'], $timezone );
			$posted_data = Wrapped_Stats::compute( 'read', $posted_year );
			$this->assertSame( 0, $this->metric( $posted_data, 'finished' )['value'], $timezone . '; posted-year data=' . print_r( $posted_data, true ) );
			wp_delete_post( $boundary, true );
			wp_delete_post( $fallback, true );
			wp_delete_post( $anchor, true );
		}
	}

	public function test_streak_uses_post_date_not_finished_date(): void {
		$year = (int) wp_date( 'Y' ) - 1;
		foreach ( [ "$year-01-05", "$year-01-12", "$year-01-19", "$year-02-09" ] as $day ) {
			$this->read_post( $year, [ 'read_finished_at' => ( $year - 1 ) . '-12-30' ], [ 'post_date' => $day . ' 12:00:00', 'post_date_gmt' => $day . ' 12:00:00' ] );
		}
		$this->assertSame( 3, $this->metric( Wrapped_Stats::compute( 'read', $year ), 'streak' )['value'] );
	}

	public function test_stored_post_date_year_survives_timezone_and_offset_changes_without_resave(): void {
		$year = (int) wp_date( 'Y' ) - 1;
		$id = $this->kind_post(
			'watch',
			$year,
			[ 'watch_status' => 'watched' ],
			[ 'post_date' => $year . '-12-31 23:30:00', 'post_date_gmt' => ( $year + 1 ) . '-01-01 07:30:00' ]
		);
		foreach ( [ [ 'America/Los_Angeles', -8 ], [ 'Pacific/Auckland', 13 ], [ '', 9.5 ] ] as [ $timezone, $offset ] ) {
			update_option( 'timezone_string', $timezone );
			update_option( 'gmt_offset', $offset );
			$data = Wrapped_Stats::compute( 'watch', $year );
			$this->assertSame( 1, $this->metric( $data, 'watched' )['value'], (string) $offset );
			$this->assertSame( 0, $this->metric( Wrapped_Stats::compute( 'watch', $year + 1 ), 'watched' )['value'], 'counted in exactly one year at offset ' . $offset );
			$this->assertSame( $year . '-12-31 23:30:00', get_post_field( 'post_date', $id ), 'the fixture is never re-saved' );
		}
	}
}

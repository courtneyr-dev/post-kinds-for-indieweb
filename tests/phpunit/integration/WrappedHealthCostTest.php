<?php
/** Wrapped health, cost, and place coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedHealthCostTest extends WrappedTestCase {
	public function test_exercise_duration_uses_only_three_opted_in_posts_out_of_twelve(): void {
		for ( $index = 0; $index < 12; ++$index ) {
			$meta = [
				'exercise_duration'       => 'PT1H20M',
				'exercise_details_public' => $index < 3 ? '1' : '0',
				'exercise_distance'       => 9999,
				'exercise_splits'         => 'Sentinel split 03:14',
			];
			$this->kind_post( 'exercise', 2026, $meta );
		}
		$data = Wrapped_Stats::compute( 'exercise', 2026 );
		$this->assertSame( 12, $this->metric( $data, 'posts' )['value'] );
		$this->assertSame( 14400, $this->metric( $data, 'duration' )['value'], 'Three opted-in PT1H20M posts must total 14,400 seconds; data=' . print_r( $data, true ) );
		$this->assertSame( 'Exercise time from 3 posts', $this->metric( $data, 'duration' )['label'] );
		$this->assertStringNotContainsString( '03:14', maybe_serialize( $data ) );
	}

	public function test_sleep_duration_ignores_bed_wake_and_clock_times(): void {
		for ( $index = 0; $index < 3; ++$index ) {
			$this->kind_post( 'sleep', 2026, [ 'sleep_duration' => 480, 'sleep_bedtime' => '23:47', 'sleep_wake' => '07:47' ] );
		}
		$data = Wrapped_Stats::compute( 'sleep', 2026 );
		$this->assertSame( 3, $this->metric( $data, 'posts' )['value'] );
		$this->assertSame( 'Sleep time from 3 posts', $this->metric( $data, 'duration' )['label'], 'Sleep duration must report its three-post basis; data=' . print_r( $data, true ) );
		$this->assertStringNotContainsString( '23:47', maybe_serialize( $data ) );
		$this->assertStringNotContainsString( '07:47', maybe_serialize( $data ) );
	}

	public function test_acquisition_is_count_only_regardless_of_cost_visibility(): void {
		$this->kind_post( 'acquisition', 2026, [ 'acquisition_price' => '999.99', 'acquisition_cost_public' => '0' ] );
		$this->kind_post( 'acquisition', 2026, [ 'acquisition_price' => '12.34', 'acquisition_cost_public' => '1' ] );
		$data = Wrapped_Stats::compute( 'acquisition', 2026 );
		$this->assertSame( [ 'posts' ], array_column( $data['metrics'], 'key' ) );
		$this->assertSame( 2, $data['metrics'][0]['value'] );
		$this->assertStringNotContainsString( '999.99', maybe_serialize( $data ) );
	}

	public function test_checkin_private_location_counts_without_place_output(): void {
		$this->kind_post( 'checkin', 2026, [ 'location_name' => 'Sentinel Private Place', 'location_visibility' => 'private' ] );
		$data = Wrapped_Stats::compute( 'checkin', 2026 );
		$this->assertSame( 1, $this->metric( $data, 'posts' )['value'] );
		$this->assertStringNotContainsString( 'Sentinel Private Place', maybe_serialize( $data ) );
	}
}

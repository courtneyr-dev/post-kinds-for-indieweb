<?php
/** Wrapped small-sample suppression coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedSuppressionTest extends WrappedTestCase {
	public function test_average_rating_requires_three_rated_posts_but_main_counts_do_not(): void {
		$this->read_post( 2026, [ 'read_rating' => 3 ] );
		$this->read_post( 2026, [ 'read_rating' => 5 ] );
		$this->assertNull( $this->metric( Wrapped_Stats::compute( 'read', 2026 ), 'average_rating' ) );
		$this->read_post( 2026, [ 'read_rating' => 4 ] );
		$this->assertSame( 4.0, $this->metric( Wrapped_Stats::compute( 'read', 2026 ), 'average_rating' )['value'] );
		$this->assertSame( 3, $this->metric( Wrapped_Stats::compute( 'read', 2026 ), 'finished' )['value'] );
		$this->assertSame( 0, $this->metric( Wrapped_Stats::compute( 'listen', 2026 ), 'listens' )['value'] );
	}

	public function test_exercise_and_sleep_duration_require_three_contributors(): void {
		foreach ( [ 'exercise', 'sleep' ] as $kind ) {
			for ( $index = 0; $index < 2; ++$index ) {
				$meta = 'exercise' === $kind
					? [ 'exercise_duration' => 'PT1H', 'exercise_details_public' => '1' ]
					: [ 'sleep_duration' => 480 ];
				$this->kind_post( $kind, 2026, $meta );
			}
			$this->assertNull( $this->metric( Wrapped_Stats::compute( $kind, 2026 ), 'duration' ), $kind );
			$meta = 'exercise' === $kind
				? [ 'exercise_duration' => 'PT1H', 'exercise_details_public' => '1' ]
				: [ 'sleep_duration' => 480 ];
			$this->kind_post( $kind, 2026, $meta );
			$data = Wrapped_Stats::compute( $kind, 2026 );
			$this->assertNotNull( $this->metric( $data, 'duration' ), $kind . ': three contributors must expose duration; data=' . print_r( $data, true ) );
		}
	}

	public function test_one_finished_post_and_zero_main_count_print_real_values(): void {
		$this->read_post();
		$this->assertSame( 1, $this->metric( Wrapped_Stats::compute( 'read', 2026 ), 'finished' )['value'] );
		$watch = Wrapped_Stats::compute( 'watch', 2026 );
		$this->assertSame( '0', $this->metric( $watch, 'watched' )['display'], 'A zero main metric must retain its display value; data=' . print_r( $watch, true ) );
	}
}

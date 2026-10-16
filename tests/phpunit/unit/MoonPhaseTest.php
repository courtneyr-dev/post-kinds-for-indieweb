<?php
/**
 * Moon phases for a sleep night (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use PKIW\Moon_Phase;
use WP_UnitTestCase;

/**
 * Moon_Phase computes the principal phases with Meeus, Astronomical
 * Algorithms 2nd ed., chapter 49, and names a night by the phase in force
 * between noon and the next noon. The fixture is NASA GSFC's catalog
 * (Espenak, Six Millennium Catalog of Phases of the Moon), not the
 * algorithm's own output, so a wrong coefficient shows as a missed minute.
 *
 * @covers \PKIW\Moon_Phase
 */
final class MoonPhaseTest extends WP_UnitTestCase {

	private const FIXTURE = __DIR__ . '/../fixtures/moon-phases-2026-2027.json';

	/**
	 * Requests that left the process while a test ran.
	 *
	 * @var int
	 */
	private int $http_calls = 0;

	public function set_up(): void {
		parent::set_up();
		$this->http_calls = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt ) {
				++$this->http_calls;
				return $preempt;
			},
			1
		);
	}

	/**
	 * The catalog rows: phase slug and instant.
	 *
	 * @return array<int, array{phase: string, utc: \DateTimeImmutable}>
	 */
	private function catalog(): array {
		$rows = json_decode( (string) file_get_contents( self::FIXTURE ), true );
		$this->assertIsArray( $rows );
		$out = [];
		foreach ( $rows['phases'] as $row ) {
			$out[] = [
				'phase' => $row['phase'],
				'utc'   => new \DateTimeImmutable( $row['utc'], new \DateTimeZone( 'UTC' ) ),
			];
		}

		return $out;
	}

	public function test_the_fixture_is_the_nasa_catalog_for_two_years(): void {
		$text = (string) file_get_contents( self::FIXTURE );
		$this->assertStringContainsString( 'NASA GSFC', $text );
		$this->assertStringContainsString( 'eclipse.gsfc.nasa.gov/phase/phases2001.html', $text );
		$this->assertCount( 99, $this->catalog(), '50 phases in 2026 and 49 in 2027.' );
	}

	public function test_every_principal_instant_of_2026_and_2027_is_within_two_minutes_of_the_catalog(): void {
		$computed = [];
		foreach ( [ 2026, 2027 ] as $year ) {
			foreach ( Moon_Phase::principal_instants( $year ) as $row ) {
				$computed[] = $row;
			}
		}
		$catalog = $this->catalog();
		$this->assertCount( count( $catalog ), $computed, 'One computed instant per catalog row.' );

		foreach ( $catalog as $i => $expected ) {
			$this->assertSame( $expected['phase'], $computed[ $i ]['phase'], "Row $i phase" );
			$gap = abs( $computed[ $i ]['utc']->getTimestamp() - $expected['utc']->getTimestamp() );
			$this->assertLessThanOrEqual( 120, $gap, sprintf( '%s %s is %d seconds from the catalog.', $expected['phase'], $expected['utc']->format( 'Y-m-d H:i' ), $gap ) );
		}
	}

	public function test_instants_come_back_in_time_order_in_utc(): void {
		$rows = Moon_Phase::principal_instants( 2026 );
		$last = 0;
		foreach ( $rows as $row ) {
			$this->assertSame( 'UTC', $row['utc']->getTimezone()->getName() );
			$this->assertGreaterThan( $last, $row['utc']->getTimestamp() );
			$this->assertSame( '2026', $row['utc']->format( 'Y' ) );
			$last = $row['utc']->getTimestamp();
		}
	}

	/**
	 * Nights in New York, from the catalog's UT instants.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public function new_york_nights(): array {
		return [
			'new, 23:27 EDT on the 10th'            => [ '2026-09-10', 'new', true ],
			'first quarter, 16:44 EDT'              => [ '2026-09-18', 'first-quarter', true ],
			'the day after the quarter'             => [ '2026-09-19', 'waxing-gibbous', false ],
			'two days after'                        => [ '2026-09-20', 'waxing-gibbous', false ],
			'full, 12:49 EDT just past noon'        => [ '2026-09-26', 'full', true ],
			'last quarter 09:25 EDT on the 3rd'     => [ '2026-10-02', 'last-quarter', true ],
			'new 11:50 EDT, 10 minutes before edge' => [ '2026-10-09', 'new', true ],
			'the night after it'                    => [ '2026-10-10', 'waxing-crescent', false ],
			'first quarter 12:13 EDT, after edge'   => [ '2026-10-18', 'first-quarter', true ],
			'the night before it'                   => [ '2026-10-17', 'waxing-crescent', false ],
			'last quarter on the DST end day'       => [ '2026-11-01', 'last-quarter', true ],
			'waning crescent after last quarter'    => [ '2026-08-08', 'waning-crescent', false ],
			'waning gibbous before it'              => [ '2026-08-03', 'waning-gibbous', false ],
		];
	}

	/**
	 * @dataProvider new_york_nights
	 */
	public function test_the_sentinel_nights_in_new_york( string $ymd, string $slug, bool $principal ): void {
		$night = Moon_Phase::for_night( $ymd, new \DateTimeZone( 'America/New_York' ) );

		$this->assertSame( $slug, $night['slug'] );
		$this->assertSame( $principal, $night['principal'] );
		$this->assertNotSame( '', $night['label'] );
	}

	public function test_the_same_instant_lands_on_different_nights_in_different_timezones(): void {
		$ny       = new \DateTimeZone( 'America/New_York' );
		$auckland = new \DateTimeZone( 'Pacific/Auckland' );
		$kolkata  = new \DateTimeZone( 'Asia/Kolkata' );

		// First quarter is 2026-08-20 02:46 UT: 22:46 EDT on the 19th, 14:46 NZST on the 20th.
		$this->assertSame( [ 'first-quarter', true ], $this->slug_and_principal( '2026-08-19', $ny ) );
		$this->assertSame( [ 'waxing-gibbous', false ], $this->slug_and_principal( '2026-08-20', $ny ) );
		$this->assertSame( [ 'first-quarter', true ], $this->slug_and_principal( '2026-08-20', $auckland ) );
		$this->assertSame( [ 'waxing-crescent', false ], $this->slug_and_principal( '2026-08-19', $auckland ) );

		// First quarter is 2026-06-21 21:55 UT: 03:25 IST on the 22nd, before noon, so the night of the 21st.
		$this->assertSame( [ 'first-quarter', true ], $this->slug_and_principal( '2026-06-21', $kolkata ) );
		$this->assertSame( [ 'waxing-gibbous', false ], $this->slug_and_principal( '2026-06-22', $kolkata ) );
	}

	/**
	 * @return array{0: string, 1: bool}
	 */
	private function slug_and_principal( string $ymd, \DateTimeZone $tz ): array {
		$night = Moon_Phase::for_night( $ymd, $tz );

		return [ $night['slug'], $night['principal'] ];
	}

	public function test_exactly_one_night_is_principal_for_each_instant_in_each_timezone(): void {
		$zones = [ 'America/New_York', 'America/Los_Angeles', 'UTC', 'Europe/London', 'Asia/Kolkata', 'Pacific/Auckland', 'Pacific/Honolulu', 'Pacific/Kiritimati' ];
		foreach ( $this->catalog() as $row ) {
			foreach ( $zones as $zone ) {
				$tz    = new \DateTimeZone( $zone );
				$local = $row['utc']->setTimezone( $tz );
				$found = [];
				for ( $offset = -2; $offset <= 2; $offset++ ) {
					$ymd   = $local->modify( "$offset days" )->format( 'Y-m-d' );
					$night = Moon_Phase::for_night( $ymd, $tz );
					if ( $night['principal'] ) {
						$found[ $ymd ] = $night['slug'];
					}
				}
				$this->assertCount( 1, $found, sprintf( '%s %s in %s', $row['phase'], $row['utc']->format( 'c' ), $zone ) );
				$this->assertSame( [ $row['phase'] ], array_values( $found ) );
			}
		}
	}

	public function test_a_run_of_nights_walks_the_cycle_in_order_and_never_skips_a_phase(): void {
		$cycle = [ 'new', 'waxing-crescent', 'first-quarter', 'waxing-gibbous', 'full', 'waning-gibbous', 'last-quarter', 'waning-crescent' ];
		$tz    = new \DateTimeZone( 'America/New_York' );
		$night = new \DateTimeImmutable( '2026-01-01', $tz );
		$prev  = null;
		$seen  = [];
		for ( $day = 0; $day < 400; $day++ ) {
			$slug = Moon_Phase::for_night( $night->modify( "+$day days" )->format( 'Y-m-d' ), $tz )['slug'];
			$this->assertContains( $slug, $cycle );
			if ( null !== $prev && $slug !== $prev ) {
				$this->assertSame( ( array_search( $prev, $cycle, true ) + 1 ) % 8, array_search( $slug, $cycle, true ), "$prev then $slug" );
			}
			$seen[ $slug ] = true;
			$prev          = $slug;
		}
		$this->assertCount( 8, $seen );
	}

	public function test_labels_are_the_plain_phase_names(): void {
		$tz = new \DateTimeZone( 'America/New_York' );

		$this->assertSame( 'New moon', Moon_Phase::for_night( '2026-09-10', $tz )['label'] );
		$this->assertSame( 'First quarter', Moon_Phase::for_night( '2026-09-18', $tz )['label'] );
		$this->assertSame( 'Waxing gibbous', Moon_Phase::for_night( '2026-09-19', $tz )['label'] );
		$this->assertSame( 'Full moon', Moon_Phase::for_night( '2026-09-26', $tz )['label'] );
		$this->assertSame( 'Waning gibbous', Moon_Phase::for_night( '2026-08-03', $tz )['label'] );
		$this->assertSame( 'Last quarter', Moon_Phase::for_night( '2026-10-02', $tz )['label'] );
		$this->assertSame( 'Waning crescent', Moon_Phase::for_night( '2026-08-08', $tz )['label'] );
		$this->assertSame( 'Waxing crescent', Moon_Phase::for_night( '2026-08-16', $tz )['label'] );
	}

	public function test_a_malformed_night_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Moon_Phase::for_night( '2026-02-30', new \DateTimeZone( 'UTC' ) );
	}

	public function test_nothing_leaves_the_process_and_no_option_is_read(): void {
		$reads = 0;
		add_filter(
			'pre_option_timezone_string',
			static function ( $value ) use ( &$reads ) {
				++$reads;
				return $value;
			}
		);

		Moon_Phase::principal_instants( 2027 );
		Moon_Phase::for_night( '2027-03-03', new \DateTimeZone( 'Asia/Kolkata' ) );

		$this->assertSame( 0, $this->http_calls, 'pre_http_request never fired.' );
		$this->assertSame( 0, $reads );
	}
}

<?php
/**
 * Moon phases for a sleep night (issue 247).
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The moon phase of a night, in pure PHP.
 *
 * Algorithm: Jean Meeus, Astronomical Algorithms, 2nd ed., chapter 49. It
 * takes the mean phase for a lunation number, adds the periodic terms for
 * new and full moon or for the quarters (with the W correction), then the
 * 14 planetary arguments, and gets a time in dynamical time. Delta T, from
 * the Espenak and Meeus polynomials, turns that into universal time. Over
 * 2026 and 2027 it lands within 2 minutes of NASA GSFC's catalog of phases
 * (MoonPhaseTest checks all 99 rows).
 *
 * A night is the noon-to-noon window starting at noon on its date in the
 * given timezone. It carries a principal phase when one of the four
 * instants falls inside the window. Any other night takes the name of the
 * phase interval the instants around it bound: after new moon is a waxing
 * crescent, after first quarter a waxing gibbous, after full moon a
 * waning gibbous and after last quarter a waning crescent.
 *
 * Nothing here reads an option, the network or the database, so a night's
 * phase is the same on every request.
 *
 * @since 1.9.0
 */
final class Moon_Phase {

	/**
	 * Principal phases in the order they follow each other, by the lunation
	 * fraction Meeus adds to the lunation number.
	 *
	 * @var array<string, float>
	 */
	private const PRINCIPAL = [
		'new'           => 0.0,
		'first-quarter' => 0.25,
		'full'          => 0.5,
		'last-quarter'  => 0.75,
	];

	/**
	 * What follows each principal phase, until the next one.
	 *
	 * @var array<string, string>
	 */
	private const INTERVAL = [
		'new'           => 'waxing-crescent',
		'first-quarter' => 'waxing-gibbous',
		'full'          => 'waning-gibbous',
		'last-quarter'  => 'waning-crescent',
	];

	/**
	 * Julian day of the Unix epoch.
	 */
	private const JD_UNIX_EPOCH = 2440587.5;

	/**
	 * Phase events by lunation number (k times 4), remembered for one request.
	 *
	 * @var array<int, array{phase: string, ts: int}>
	 */
	private static array $events = [];

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * The principal phases that fall in a UTC calendar year.
	 *
	 * @param int $year Year, such as 2026.
	 * @return array<int, array{phase: string, utc: \DateTimeImmutable}> Time order, instants in UTC to the second.
	 */
	public static function principal_instants( int $year ): array {
		$start = gmmktime( 0, 0, 0, 1, 1, $year );
		$end   = gmmktime( 0, 0, 0, 1, 1, $year + 1 );
		$rows  = [];
		foreach ( self::events_between( $start - 40 * DAY_IN_SECONDS, $end + 40 * DAY_IN_SECONDS ) as $event ) {
			if ( $event['ts'] >= $start && $event['ts'] < $end ) {
				$rows[] = [
					'phase' => $event['phase'],
					'utc'   => ( new \DateTimeImmutable( '@' . $event['ts'] ) )->setTimezone( new \DateTimeZone( 'UTC' ) ),
				];
			}
		}

		return $rows;
	}

	/**
	 * The moon for a night.
	 *
	 * @param string        $ymd The night's date, Y-m-d: the window runs from noon that day to noon the next.
	 * @param \DateTimeZone $tz  Timezone the noons are read in.
	 * @return array{slug: string, label: string, principal: bool}
	 * @throws \InvalidArgumentException When $ymd isn't a real calendar date.
	 */
	public static function for_night( string $ymd, \DateTimeZone $tz ): array {
		$from = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $ymd . ' 12:00:00', $tz );
		if ( ! $from instanceof \DateTimeImmutable || $from->format( 'Y-m-d' ) !== $ymd ) {
			throw new \InvalidArgumentException( 'A night is a real calendar date, Y-m-d.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed text.
		}
		// The next date's noon, built from the date, so a 23 or 25 hour day still ends at noon.
		$to = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $from->modify( '+1 day' )->format( 'Y-m-d' ) . ' 12:00:00', $tz );
		if ( ! $to instanceof \DateTimeImmutable ) {
			throw new \InvalidArgumentException( 'A night is a real calendar date, Y-m-d.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed text.
		}

		$start  = $from->getTimestamp();
		$end    = $to->getTimestamp();
		$events = self::events_between( $start - 40 * DAY_IN_SECONDS, $end + 40 * DAY_IN_SECONDS );

		$before = null;
		foreach ( $events as $event ) {
			if ( $event['ts'] >= $start && $event['ts'] < $end ) {
				return self::result( $event['phase'], true );
			}
			if ( $event['ts'] < $start ) {
				$before = $event;
			}
		}

		return self::result( null === $before ? 'new' : self::INTERVAL[ $before['phase'] ], false );
	}

	/**
	 * A night's name, slug and label.
	 *
	 * @param string $slug      Phase slug.
	 * @param bool   $principal Whether the phase's instant falls inside the night.
	 * @return array{slug: string, label: string, principal: bool}
	 */
	private static function result( string $slug, bool $principal ): array {
		return [
			'slug'      => $slug,
			'label'     => self::label( $slug ),
			'principal' => $principal,
		];
	}

	/**
	 * The translated name of a phase.
	 *
	 * @param string $slug Phase slug.
	 * @return string
	 */
	private static function label( string $slug ): string {
		switch ( $slug ) {
			case 'new':
				return __( 'New moon', 'post-kinds-for-indieweb-in-block-themes' );
			case 'waxing-crescent':
				return __( 'Waxing crescent', 'post-kinds-for-indieweb-in-block-themes' );
			case 'first-quarter':
				return __( 'First quarter', 'post-kinds-for-indieweb-in-block-themes' );
			case 'waxing-gibbous':
				return __( 'Waxing gibbous', 'post-kinds-for-indieweb-in-block-themes' );
			case 'full':
				return __( 'Full moon', 'post-kinds-for-indieweb-in-block-themes' );
			case 'waning-gibbous':
				return __( 'Waning gibbous', 'post-kinds-for-indieweb-in-block-themes' );
			case 'last-quarter':
				return __( 'Last quarter', 'post-kinds-for-indieweb-in-block-themes' );
			case 'waning-crescent':
				return __( 'Waning crescent', 'post-kinds-for-indieweb-in-block-themes' );
		}

		return '';
	}

	/**
	 * Every principal phase between two Unix times, in time order.
	 *
	 * @param int $from Unix time.
	 * @param int $to   Unix time.
	 * @return array<int, array{phase: string, ts: int}>
	 */
	private static function events_between( int $from, int $to ): array {
		// Lunation number, to a whole number below: about 12.3685 per year from 2000.
		$first = (int) floor( ( ( $from - 946728000 ) / ( 365.25 * DAY_IN_SECONDS ) ) * 12.3685 ) - 1;
		$last  = (int) ceil( ( ( $to - 946728000 ) / ( 365.25 * DAY_IN_SECONDS ) ) * 12.3685 ) + 1;
		$rows  = [];
		for ( $lunation = $first; $lunation <= $last; $lunation++ ) {
			foreach ( array_keys( self::PRINCIPAL ) as $phase ) {
				$event = self::event( $lunation, $phase );
				if ( $event['ts'] >= $from && $event['ts'] <= $to ) {
					$rows[] = $event;
				}
			}
		}
		usort( $rows, static fn( array $a, array $b ): int => $a['ts'] <=> $b['ts'] );

		return $rows;
	}

	/**
	 * One phase of one lunation, remembered for the request.
	 *
	 * @param int    $lunation Lunation number: 0 is the new moon of 2000-01-06.
	 * @param string $phase    Principal phase slug.
	 * @return array{phase: string, ts: int}
	 */
	private static function event( int $lunation, string $phase ): array {
		$key = $lunation * 4 + (int) round( self::PRINCIPAL[ $phase ] * 4 );
		if ( ! isset( self::$events[ $key ] ) ) {
			$jde                  = self::julian_day_ephemeris( (float) $lunation + self::PRINCIPAL[ $phase ], $phase );
			$ts                   = (int) round( ( $jde - self::JD_UNIX_EPOCH ) * DAY_IN_SECONDS - self::delta_t( $jde ) );
			self::$events[ $key ] = [
				'phase' => $phase,
				'ts'    => $ts,
			];
		}

		return self::$events[ $key ];
	}

	/**
	 * Meeus 49: the Julian ephemeris day of a phase, in dynamical time.
	 *
	 * @param float  $k     Lunation number plus 0, 0.25, 0.5 or 0.75.
	 * @param string $phase Principal phase slug.
	 * @return float
	 */
	private static function julian_day_ephemeris( float $k, string $phase ): float {
		$t  = $k / 1236.85;
		$t2 = $t * $t;
		$t3 = $t2 * $t;
		$t4 = $t3 * $t;

		$jde = 2451550.09766 + 29.530588861 * $k + 0.00015437 * $t2 - 0.000000150 * $t3 + 0.00000000073 * $t4;

		$e = 1 - 0.002516 * $t - 0.0000074 * $t2;

		// Sun's mean anomaly, Moon's mean anomaly, Moon's argument of latitude, longitude of the ascending node.
		$m  = deg2rad( fmod( 2.5534 + 29.10535670 * $k - 0.0000014 * $t2 - 0.00000011 * $t3, 360.0 ) );
		$mp = deg2rad( fmod( 201.5643 + 385.81693528 * $k + 0.0107582 * $t2 + 0.00001238 * $t3 - 0.000000058 * $t4, 360.0 ) );
		$f  = deg2rad( fmod( 160.7108 + 390.67050284 * $k - 0.0016118 * $t2 - 0.00000227 * $t3 + 0.000000011 * $t4, 360.0 ) );
		$om = deg2rad( fmod( 124.7746 - 1.56375588 * $k + 0.0020672 * $t2 + 0.00000215 * $t3, 360.0 ) );

		$e2 = $e * $e;

		if ( 'new' === $phase ) {
			$jde += -0.40720 * sin( $mp )
				+ 0.17241 * $e * sin( $m )
				+ 0.01608 * sin( 2 * $mp )
				+ 0.01039 * sin( 2 * $f )
				+ 0.00739 * $e * sin( $mp - $m )
				- 0.00514 * $e * sin( $mp + $m )
				+ 0.00208 * $e2 * sin( 2 * $m )
				- 0.00111 * sin( $mp - 2 * $f )
				- 0.00057 * sin( $mp + 2 * $f )
				+ 0.00056 * $e * sin( 2 * $mp + $m )
				- 0.00042 * sin( 3 * $mp )
				+ 0.00042 * $e * sin( $m + 2 * $f )
				+ 0.00038 * $e * sin( $m - 2 * $f )
				- 0.00024 * $e * sin( 2 * $mp - $m )
				- 0.00017 * sin( $om )
				- 0.00007 * sin( $mp + 2 * $m )
				+ 0.00004 * sin( 2 * $mp - 2 * $f )
				+ 0.00004 * sin( 3 * $m )
				+ 0.00003 * sin( $mp + $m - 2 * $f )
				+ 0.00003 * sin( 2 * $mp + 2 * $f )
				- 0.00003 * sin( $mp + $m + 2 * $f )
				+ 0.00003 * sin( $mp - $m + 2 * $f )
				- 0.00002 * sin( $mp - $m - 2 * $f )
				- 0.00002 * sin( 3 * $mp + $m )
				+ 0.00002 * sin( 4 * $mp );
		} elseif ( 'full' === $phase ) {
			$jde += -0.40614 * sin( $mp )
				+ 0.17302 * $e * sin( $m )
				+ 0.01614 * sin( 2 * $mp )
				+ 0.01043 * sin( 2 * $f )
				+ 0.00734 * $e * sin( $mp - $m )
				- 0.00515 * $e * sin( $mp + $m )
				+ 0.00209 * $e2 * sin( 2 * $m )
				- 0.00111 * sin( $mp - 2 * $f )
				- 0.00057 * sin( $mp + 2 * $f )
				+ 0.00056 * $e * sin( 2 * $mp + $m )
				- 0.00042 * sin( 3 * $mp )
				+ 0.00042 * $e * sin( $m + 2 * $f )
				+ 0.00038 * $e * sin( $m - 2 * $f )
				- 0.00024 * $e * sin( 2 * $mp - $m )
				- 0.00017 * sin( $om )
				- 0.00007 * sin( $mp + 2 * $m )
				+ 0.00004 * sin( 2 * $mp - 2 * $f )
				+ 0.00004 * sin( 3 * $m )
				+ 0.00003 * sin( $mp + $m - 2 * $f )
				+ 0.00003 * sin( 2 * $mp + 2 * $f )
				- 0.00003 * sin( $mp + $m + 2 * $f )
				+ 0.00003 * sin( $mp - $m + 2 * $f )
				- 0.00002 * sin( $mp - $m - 2 * $f )
				- 0.00002 * sin( 3 * $mp + $m )
				+ 0.00002 * sin( 4 * $mp );
		} else {
			$jde += -0.62801 * sin( $mp )
				+ 0.17172 * $e * sin( $m )
				- 0.01183 * $e * sin( $mp + $m )
				+ 0.00862 * sin( 2 * $mp )
				+ 0.00804 * sin( 2 * $f )
				+ 0.00454 * $e * sin( $mp - $m )
				+ 0.00204 * $e2 * sin( 2 * $m )
				- 0.00180 * sin( $mp - 2 * $f )
				- 0.00070 * sin( $mp + 2 * $f )
				- 0.00040 * sin( 3 * $mp )
				- 0.00034 * $e * sin( 2 * $mp - $m )
				+ 0.00032 * $e * sin( $m + 2 * $f )
				+ 0.00032 * $e * sin( $m - 2 * $f )
				- 0.00028 * $e2 * sin( $mp + 2 * $m )
				+ 0.00027 * $e * sin( 2 * $mp + $m )
				- 0.00017 * sin( $om )
				- 0.00005 * sin( $mp - $m - 2 * $f )
				+ 0.00004 * sin( 2 * $mp + 2 * $f )
				- 0.00004 * sin( $mp + $m + 2 * $f )
				+ 0.00004 * sin( $mp - 2 * $m )
				+ 0.00003 * sin( $mp + $m - 2 * $f )
				+ 0.00003 * sin( 3 * $m )
				+ 0.00002 * sin( 2 * $mp - 2 * $f )
				+ 0.00002 * sin( $mp - $m + 2 * $f )
				- 0.00002 * sin( 3 * $mp + $m );

			// W, added at first quarter and taken off at last quarter.
			$w    = 0.00306
				- 0.00038 * $e * cos( $m )
				+ 0.00026 * cos( $mp )
				- 0.00002 * cos( $mp - $m )
				+ 0.00002 * cos( $mp + $m )
				+ 0.00002 * cos( 2 * $f );
			$jde += 'first-quarter' === $phase ? $w : -$w;
		}

		// The 14 planetary arguments, in days.
		$arguments = [
			[ 299.77, 0.107408, 0.000325 ],
			[ 251.88, 0.016321, 0.000165 ],
			[ 251.83, 26.651886, 0.000164 ],
			[ 349.42, 36.412478, 0.000126 ],
			[ 84.66, 18.206239, 0.000110 ],
			[ 141.74, 53.303771, 0.000062 ],
			[ 207.14, 2.453732, 0.000060 ],
			[ 154.84, 7.306860, 0.000056 ],
			[ 34.52, 27.261239, 0.000047 ],
			[ 207.19, 0.121824, 0.000042 ],
			[ 291.34, 1.844379, 0.000040 ],
			[ 161.72, 24.198154, 0.000037 ],
			[ 239.56, 25.513099, 0.000035 ],
			[ 331.55, 3.592518, 0.000023 ],
		];
		foreach ( $arguments as $index => [ $base, $rate, $amplitude ] ) {
			$degrees = $base + $rate * $k - ( 0 === $index ? 0.009173 * $t2 : 0.0 );
			$jde    += $amplitude * sin( deg2rad( fmod( $degrees, 360.0 ) ) );
		}

		return $jde;
	}

	/**
	 * Delta T in seconds (dynamical time minus universal time), from the
	 * Espenak and Meeus polynomials for 1986 to 2150 and their long-term
	 * parabola outside it.
	 *
	 * @param float $jde Julian ephemeris day.
	 * @return float
	 */
	private static function delta_t( float $jde ): float {
		$year = 2000.0 + ( $jde - 2451545.0 ) / 365.25;

		if ( $year >= 1986.0 && $year < 2005.0 ) {
			$t = $year - 2000.0;

			return 63.86 + 0.3345 * $t - 0.060374 * $t ** 2 + 0.0017275 * $t ** 3 + 0.000651814 * $t ** 4 + 0.00002373599 * $t ** 5;
		}
		if ( $year >= 2005.0 && $year < 2050.0 ) {
			$t = $year - 2000.0;

			return 62.92 + 0.32217 * $t + 0.005589 * $t ** 2;
		}
		if ( $year >= 2050.0 && $year <= 2150.0 ) {
			return -20.0 + 32.0 * ( ( $year - 1820.0 ) / 100.0 ) ** 2 - 0.5628 * ( 2150.0 - $year );
		}

		return -20.0 + 32.0 * ( ( $year - 1820.0 ) / 100.0 ) ** 2;
	}
}

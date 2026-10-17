<?php
/**
 * Coverage for the shared card duration helpers (issues 245, 247 and 249).
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;
use function PKIW\card_clock_duration;
use function PKIW\card_duration_iso;
use function PKIW\card_duration_seconds;
use function PKIW\card_minutes_from_iso;
use function PKIW\card_minutes_iso;
use function PKIW\card_minutes_label;
use function PKIW\recipe_duration_from_minutes;
use function PKIW\recipe_minutes_from_duration;
use function PKIW\recipe_time_label;

/**
 * Exercise cards time a workout to the second, sleep and craft cards to the
 * minute. The minutes helpers moved out of the recipe module, which keeps
 * one-line wrappers, so a recipe's output stays byte for byte what it was.
 *
 * @covers \PKIW\card_duration_seconds
 * @covers \PKIW\card_duration_iso
 * @covers \PKIW\card_clock_duration
 * @covers \PKIW\card_minutes_iso
 * @covers \PKIW\card_minutes_from_iso
 * @covers \PKIW\card_minutes_label
 */
final class CardDurationTest extends WP_UnitTestCase {

	/**
	 * Seconds, ISO 8601 and clock form of the same duration.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public function seconds_cases(): array {
		return [
			'29 minutes 12 seconds' => [ 'PT29M12S', 1752, '29:12' ],
			'one hour two three'    => [ 'PT1H2M3S', 3723, '1:02:03' ],
			'exact hour'            => [ 'PT1H', 3600, '1:00:00' ],
			'seconds only'          => [ 'PT59S', 59, '0:59' ],
			'minutes only'          => [ 'PT5M', 300, '5:00' ],
			'single digit seconds'  => [ 'PT5M3S', 303, '5:03' ],
			'over ten hours'        => [ 'PT10H15M', 36900, '10:15:00' ],
		];
	}

	/**
	 * @dataProvider seconds_cases
	 *
	 * @param string $iso     ISO 8601 duration.
	 * @param int    $seconds Seconds.
	 * @param string $clock   Clock form.
	 */
	public function test_seconds_iso_and_clock_agree( string $iso, int $seconds, string $clock ): void {
		$this->assertSame( $seconds, card_duration_seconds( $iso ), 'ISO to seconds.' );
		$this->assertSame( $iso, card_duration_iso( $seconds ), 'Seconds to ISO.' );
		$this->assertSame( $clock, card_clock_duration( $seconds ), 'Seconds to clock form.' );
	}

	public function test_a_day_is_86400_seconds_and_1440_minutes(): void {
		$this->assertSame( 86400, card_duration_seconds( 'P1D' ) );
		$this->assertSame( 1440, card_minutes_from_iso( 'P1D' ) );
		$this->assertSame( 'PT24H', card_duration_iso( 86400 ), 'An hour count, never a day.' );
	}

	public function test_a_duration_is_read_whatever_its_case(): void {
		$this->assertSame( 1752, card_duration_seconds( 'pt29m12s' ) );
		$this->assertSame( 1752, card_duration_seconds( '  PT29M12S ' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function not_durations(): array {
		return [
			'text'            => [ 'soon' ],
			'empty'           => [ '' ],
			'bare P'          => [ 'P' ],
			'bare PT'         => [ 'PT' ],
			'clock form'      => [ '29:12' ],
			'negative'        => [ '-PT5M' ],
			'week'            => [ 'P1W' ],
			'trailing text'   => [ 'PT5M later' ],
			'decimal seconds' => [ 'PT5.5S' ],
		];
	}

	/**
	 * @dataProvider not_durations
	 *
	 * @param string $text Text that is no duration.
	 */
	public function test_text_that_is_no_duration_reads_as_nothing( string $text ): void {
		$this->assertSame( 0, card_duration_seconds( $text ) );
		$this->assertSame( 0, card_minutes_from_iso( $text ) );
	}

	public function test_no_time_has_no_iso_and_no_clock_form(): void {
		foreach ( [ 0, -1, -3600 ] as $seconds ) {
			$this->assertSame( '', card_duration_iso( $seconds ), "{$seconds} seconds." );
			$this->assertSame( '', card_clock_duration( $seconds ), "{$seconds} seconds." );
		}
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}>
	 */
	public function minutes_cases(): array {
		return [
			'45 minutes'    => [ 45, 'PT45M', '45 min' ],
			'one hour'      => [ 60, 'PT1H', '1 hr' ],
			'2 hr 30 min'   => [ 150, 'PT2H30M', '2 hr 30 min' ],
			'eighteen hrs'  => [ 1080, 'PT18H', '18 hr' ],
			'a whole day'   => [ 1440, 'PT24H', '24 hr' ],
		];
	}

	/**
	 * @dataProvider minutes_cases
	 *
	 * @param int    $minutes Minutes.
	 * @param string $iso     ISO 8601 duration.
	 * @param string $label   Label for people.
	 */
	public function test_minutes_iso_and_label( int $minutes, string $iso, string $label ): void {
		$this->assertSame( $iso, card_minutes_iso( $minutes ) );
		$this->assertSame( $label, card_minutes_label( $minutes ) );
		$this->assertSame( $minutes, card_minutes_from_iso( $iso ) );
	}

	public function test_no_minutes_have_no_iso_and_no_label(): void {
		foreach ( [ 0, -5 ] as $minutes ) {
			$this->assertSame( '', card_minutes_iso( $minutes ), "{$minutes} minutes." );
			$this->assertSame( '', card_minutes_label( $minutes ), "{$minutes} minutes." );
		}
	}

	public function test_minutes_from_iso_drops_seconds_as_the_recipe_helper_always_did(): void {
		$this->assertSame( 29, card_minutes_from_iso( 'PT29M12S' ) );
		$this->assertSame( 150, card_minutes_from_iso( 'PT2H30M' ) );
		$this->assertSame( 0, card_minutes_from_iso( 'PT45S' ) );
	}

	/**
	 * Every recipe helper is a one-line wrapper over its card twin.
	 *
	 * @return array<string, array{0: int}>
	 */
	public function recipe_wrapper_minutes(): array {
		return [
			'zero'       => [ 0 ],
			'negative'   => [ -5 ],
			'minutes'    => [ 45 ],
			'hour'       => [ 60 ],
			'mixed'      => [ 150 ],
			'long'       => [ 1080 ],
			'multi-day'  => [ 3000 ],
		];
	}

	/**
	 * @dataProvider recipe_wrapper_minutes
	 *
	 * @param int $minutes Minutes.
	 */
	public function test_each_recipe_wrapper_equals_its_card_twin( int $minutes ): void {
		$this->assertSame( card_minutes_iso( $minutes ), recipe_duration_from_minutes( $minutes ) );
		$this->assertSame( card_minutes_label( $minutes ), recipe_time_label( $minutes ) );
		$this->assertSame( card_minutes_from_iso( card_minutes_iso( $minutes ) ), recipe_minutes_from_duration( recipe_duration_from_minutes( $minutes ) ) );
	}

	/**
	 * @dataProvider not_durations
	 *
	 * @param string $text Text that is no duration.
	 */
	public function test_the_recipe_reader_agrees_on_text_that_is_no_duration( string $text ): void {
		$this->assertSame( card_minutes_from_iso( $text ), recipe_minutes_from_duration( $text ) );
	}

	public function test_the_recipe_reader_agrees_on_stored_values(): void {
		foreach ( [ 'PT1H30M', 'P1D', 'PT29M12S', 'PT45S', 'pt20m', ' PT5M ' ] as $stored ) {
			$this->assertSame( card_minutes_from_iso( $stored ), recipe_minutes_from_duration( $stored ), $stored );
		}
	}
}

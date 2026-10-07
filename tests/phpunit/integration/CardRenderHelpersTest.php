<?php
/**
 * Card render helper coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class CardRenderHelpersTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'America/Chicago' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * @dataProvider datetime_values
	 */
	public function test_card_datetime_parses_strict_stored_values( string $raw, string $expected ): void {
		$date = \PKIW\card_datetime( $raw );

		$this->assertInstanceOf( DateTimeImmutable::class, $date );
		$this->assertSame( $expected, $date->format( 'c' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function datetime_values(): array {
		return [
			'naive seconds' => [ '2026-09-24T18:00:00', '2026-09-24T18:00:00-05:00' ],
			'naive minutes' => [ '2026-09-24 18:00', '2026-09-24T18:00:00-05:00' ],
			'UTC'           => [ '2026-09-24T23:30:00Z', '2026-09-24T18:30:00-05:00' ],
			'offset'        => [ '2026-09-24T18:00:00+02:00', '2026-09-24T11:00:00-05:00' ],
		];

	}

	public function test_card_wall_clock_formats_datetime_and_calendar_date(): void {
		$this->assertSame(
			[ '2026-09-24T18:00:00-05:00', 'September 24, 2026 6:00 pm' ],
			\PKIW\card_wall_clock( '2026-09-24T18:00:00' )
		);
		$this->assertSame(
			[ '2026-09-24', 'September 24, 2026' ],
			\PKIW\card_wall_clock( '2026-09-24' )
		);
	}

	/**
	 * @dataProvider invalid_values
	 */
	public function test_card_wall_clock_rejects_invalid_values( string $raw ): void {
		$this->assertSame( [ '', '' ], \PKIW\card_wall_clock( $raw ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalid_values(): array {
		return [
			'empty'        => [ '' ],
			'relative'     => [ 'tomorrow' ],
			'invalid date' => [ '2026-02-30T10:00:00' ],
			'invalid hour' => [ '2026-09-24T25:00:00' ],
		];
	}
}

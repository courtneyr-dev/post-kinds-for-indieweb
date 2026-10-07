<?php
/**
 * Card render helpers.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a stored card datetime.
 *
 * Naive values are wall-clock times in the site timezone. Values carrying
 * an offset are instants and are converted to the site timezone.
 *
 * @since 1.9.0
 *
 * @param string $raw Stored value.
 * @return \DateTimeImmutable|null Parsed datetime, or null for invalid input.
 */
function card_datetime( string $raw ): ?\DateTimeImmutable {
	$raw = trim( $raw );
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2})(?::(\d{2})(\.\d+)?)?([Zz]|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?$/', $raw, $matches ) ) {
		return null;
	}

	$year   = (int) $matches[1];
	$month  = (int) $matches[2];
	$day    = (int) $matches[3];
	$hour   = (int) $matches[4];
	$minute = (int) $matches[5];
	$second = isset( $matches[6] ) && '' !== $matches[6] ? (int) $matches[6] : 0;

	if ( ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
		return null;
	}

	$fraction = $matches[7] ?? '';
	$offset   = $matches[8] ?? '';
	if ( 'z' === strtolower( $offset ) ) {
		$offset = '+00:00';
	} elseif ( '' !== $offset && false === strpos( $offset, ':' ) ) {
		$offset = substr( $offset, 0, 3 ) . ':' . substr( $offset, 3 );
	}

	$normalized = sprintf( '%04d-%02d-%02dT%02d:%02d:%02d%s%s', $year, $month, $day, $hour, $minute, $second, $fraction, $offset );

	try {
		$date = '' === $offset
			? new \DateTimeImmutable( $normalized, wp_timezone() )
			: new \DateTimeImmutable( $normalized );
	} catch ( \Exception $exception ) {
		return null;
	}

	return $date->setTimezone( wp_timezone() );
}

/**
 * Format a stored card wall-clock value.
 *
 * Datetimes retain the stored local time unless they carry an explicit
 * offset. Date-only values retain their calendar day.
 *
 * @since 1.9.0
 *
 * @param string $raw    Stored value.
 * @param string $format Optional display format.
 * @return array{0: string, 1: string} Machine value and display value, or two empty strings.
 */
function card_wall_clock( string $raw, string $format = '' ): array {
	$date = card_datetime( $raw );
	if ( $date instanceof \DateTimeImmutable ) {
		$display_format = '' !== $format
			? $format
			: (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return [
			$date->format( 'c' ),
			(string) wp_date( $display_format, $date->getTimestamp(), wp_timezone() ),
		];
	}

	if ( ! preg_match( '/[Tt ]\d{2}:\d{2}/', trim( $raw ) ) ) {
		return card_calendar_date( $raw );
	}

	return [ '', '' ];
}

/**
 * Calculate full, half, and empty star counts for a rating.
 *
 * @since 1.9.0
 *
 * @param float $rating Stored rating.
 * @param int   $best   Best possible rating.
 * @return array{value: float, best: int, full: int, half: bool, empty: int} Normalized rating and star counts.
 */
function card_star_counts( float $rating, int $best = 5 ): array {
	$best  = $best < 1 ? 5 : $best;
	$value = max( 0.0, min( (float) $best, $rating ) );
	$full  = (int) floor( $value );
	$half  = ( $value - $full ) >= 0.5;
	$empty = max( 0, $best - $full - ( $half ? 1 : 0 ) );

	return compact( 'value', 'best', 'full', 'half', 'empty' );
}

/**
 * Build the accessible label for a card rating.
 *
 * @since 1.9.0
 *
 * @param float $rating Stored rating.
 * @param int   $best   Best possible rating.
 * @return string Rating label, or an empty string for no rating.
 */
function card_rating_label( float $rating, int $best = 5 ): string {
	$counts = card_star_counts( $rating, $best );
	if ( $counts['value'] <= 0 ) {
		return '';
	}

	if ( floor( $counts['value'] ) === $counts['value'] ) {
		$number = number_format_i18n( $counts['value'], 0 );
	} else {
		$number = rtrim( rtrim( number_format_i18n( $counts['value'], 2 ), '0' ), '.,' );
	}

	return sprintf(
		/* translators: 1: rating, 2: best possible rating */
		__( 'Rated %1$s of %2$s', 'post-kinds-for-indieweb-in-block-themes' ),
		$number,
		number_format_i18n( $counts['best'], 0 )
	);
}

/**
 * Render a card rating as accessible SVG stars and hidden machine data.
 *
 * @since 1.9.0
 *
 * @param mixed $rating Stored rating.
 * @param int   $best   Best possible rating.
 * @return string Rating HTML, or an empty string for no rating.
 */
function card_rating_html( $rating, int $best = 5 ): string {
	$counts = card_star_counts( (float) $rating, $best );
	if ( $counts['value'] <= 0 ) {
		return '';
	}

	$path    = 'M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z';
	$full    = '<svg class="" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="' . $path . '"/></svg>';
	$empty   = '<svg class="off" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="' . $path . '"/></svg>';
	$half    = '<svg class="half" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path class="off" d="' . $path . '"/><path d="' . $path . '" style="clip-path:inset(0 50% 0 0)"/></svg>';
	$machine = rtrim( rtrim( number_format( $counts['value'], 2, '.', '' ), '0' ), '.' );

	return '<div class="pk-stars" role="img" aria-label="' . esc_attr( card_rating_label( $counts['value'], $counts['best'] ) ) . '">'
		. str_repeat( $full, $counts['full'] )
		. ( $counts['half'] ? $half : '' )
		. str_repeat( $empty, $counts['empty'] )
		. '</div><data class="p-rating" value="' . esc_attr( $machine ) . '" hidden></data>';
}

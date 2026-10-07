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

<?php
/**
 * Calendar Events integration facade.
 *
 * Resolves event data (name, start, end, location, url) for the event-card
 * block from an installed calendar plugin. Sources are feature-detected at
 * call time — both calendar plugins stay optional, and an inactive or
 * unknown source degrades to null so the block falls back to its own
 * attributes instead of fataling.
 *
 * @package PKIW
 * @since   1.4.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Facade over the per-plugin calendar integrations.
 *
 * @since 1.4.0
 */
class Calendar_Events {

	/**
	 * Source slug for The Events Calendar.
	 *
	 * @var string
	 */
	public const SOURCE_THE_EVENTS_CALENDAR = 'the-events-calendar';

	/**
	 * Source slug for My Calendar.
	 *
	 * @var string
	 */
	public const SOURCE_MY_CALENDAR = 'my-calendar';

	/**
	 * Resolve normalized event data from a calendar source.
	 *
	 * @param string $source   Calendar source slug.
	 * @param int    $event_id Event ID within that source.
	 * @return array{name: string, start: string, end: string, location: string, url: string}|null
	 *         Normalized event data, or null when the source is inactive,
	 *         unknown, or the event cannot be found.
	 */
	public static function get_event( string $source, int $event_id ): ?array {
		if ( '' === $source || $event_id <= 0 ) {
			return null;
		}

		/**
		 * Short-circuit calendar event resolution.
		 *
		 * Return an array with any of the keys name, start, end, location,
		 * url to skip the plugin lookup entirely. Lets tests stub event data
		 * and lets sites wire up calendar plugins this integration doesn't
		 * know about.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string, string>|null $pre      Event data to use, or null to continue.
		 * @param string                     $source   Calendar source slug.
		 * @param int                        $event_id Event ID within that source.
		 */
		$pre = apply_filters( 'pkiw_pre_calendar_event', null, $source, $event_id );

		if ( is_array( $pre ) ) {
			return self::normalize( $pre );
		}

		switch ( $source ) {
			case self::SOURCE_THE_EVENTS_CALENDAR:
				$event = ( new The_Events_Calendar() )->get_event( $event_id );
				break;
			case self::SOURCE_MY_CALENDAR:
				$event = ( new My_Calendar() )->get_event( $event_id );
				break;
			default:
				$event = null;
		}

		return is_array( $event ) ? self::normalize( $event ) : null;
	}

	/**
	 * Coerce raw event data into the five normalized string fields.
	 *
	 * @param array<string, mixed> $event Raw event data.
	 * @return array{name: string, start: string, end: string, location: string, url: string}
	 */
	private static function normalize( array $event ): array {
		$normalized = [];

		foreach ( [ 'name', 'start', 'end', 'location', 'url' ] as $key ) {
			$value              = $event[ $key ] ?? '';
			$normalized[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		return $normalized;
	}
}

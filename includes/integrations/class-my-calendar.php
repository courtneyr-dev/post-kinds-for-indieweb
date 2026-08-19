<?php
/**
 * My Calendar integration.
 *
 * Reads event data from My Calendar (my-calendar), which stores events in
 * its own mc_events table rather than a post type. All access goes through
 * My Calendar's own functions (mc_get_event_core / mc_get_event) — never
 * direct table queries, since the table only exists while the plugin is
 * installed. Feature-detected: the plugin is optional and every lookup
 * degrades to null when it's inactive.
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
 * My Calendar integration class.
 *
 * @since 1.4.0
 */
class My_Calendar {

	/**
	 * Whether My Calendar is active.
	 *
	 * @return bool True when the plugin is detected.
	 */
	public function is_active(): bool {
		$active = function_exists( 'mc_get_event_core' ) || function_exists( 'mc_get_event' );

		/** This filter is documented in includes/integrations/class-the-events-calendar.php */
		return (bool) apply_filters( 'pkiw_calendar_source_active', $active, Calendar_Events::SOURCE_MY_CALENDAR );
	}

	/**
	 * Fetch an event's data by My Calendar event ID.
	 *
	 * @param int $event_id My Calendar event ID.
	 * @return array{name: string, start: string, end: string, location: string, url: string}|null
	 *         Event data, or null when inactive or the event isn't found.
	 */
	public function get_event( int $event_id ): ?array {
		if ( ! $this->is_active() ) {
			return null;
		}

		$event = $this->fetch_event( $event_id );

		if ( ! is_object( $event ) ) {
			return null;
		}

		return [
			'name'     => $this->field( $event, 'event_title' ),
			'start'    => $this->event_datetime( $event, 'event_begin', 'event_time' ),
			'end'      => $this->event_datetime( $event, 'event_end', 'event_endtime' ),
			'location' => $this->location_name( $event ),
			'url'      => $this->field( $event, 'event_link' ),
		];
	}

	/**
	 * Fetch the raw event object through My Calendar's own functions.
	 *
	 * The core lookup (mc_get_event_core) resolves by event ID and
	 * mc_get_event() by occurrence ID; core is preferred since the block
	 * stores the event ID.
	 *
	 * @param int $event_id My Calendar event ID.
	 * @return object|null Event object, or null when unavailable.
	 */
	private function fetch_event( int $event_id ): ?object {
		foreach ( [ 'mc_get_event_core', 'mc_get_event' ] as $helper ) {
			if ( ! function_exists( $helper ) ) {
				continue;
			}

			$event = call_user_func( $helper, $event_id );

			if ( is_object( $event ) ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * A string field off the event object, defensively.
	 *
	 * My Calendar's event object shape has shifted across versions, so every
	 * property read is optional.
	 *
	 * @param object $event    Event object.
	 * @param string $property Property name.
	 * @return string Trimmed value, or '' when absent or non-scalar.
	 */
	private function field( object $event, string $property ): string {
		$value = $event->{$property} ?? '';

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Combine My Calendar's separate date and time columns into one string.
	 *
	 * @param object $event         Event object.
	 * @param string $date_property Date property (event_begin / event_end).
	 * @param string $time_property Time property (event_time / event_endtime).
	 * @return string "Y-m-d H:i:s"-shaped string, or '' when the date is unset.
	 */
	private function event_datetime( object $event, string $date_property, string $time_property ): string {
		$date = $this->field( $event, $date_property );

		if ( '' === $date || str_starts_with( $date, '0000' ) ) {
			return '';
		}

		return trim( $date . ' ' . $this->field( $event, $time_property ) );
	}

	/**
	 * The event's location name: the event_label column, else the linked
	 * location record via mc_get_location().
	 *
	 * @param object $event Event object.
	 * @return string Location name, or '' when the event has no location.
	 */
	private function location_name( object $event ): string {
		$label = $this->field( $event, 'event_label' );

		if ( '' !== $label ) {
			return $label;
		}

		$location_id = (int) $this->field( $event, 'event_location' );

		if ( $location_id > 0 && function_exists( 'mc_get_location' ) ) {
			$location = \mc_get_location( $location_id );

			if ( is_object( $location ) && isset( $location->location_label ) && is_scalar( $location->location_label ) ) {
				return trim( (string) $location->location_label );
			}
		}

		return '';
	}
}

<?php
/**
 * My Calendar integration.
 *
 * Reads event data from My Calendar (my-calendar), which stores events in
 * its own mc_events table rather than a post type. Event reads go through
 * My Calendar's event-ID function (mc_get_event_core) — never
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
	 * @return array{name: string, start: string, end: string, location: string, url: string, status: string}|null
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

		if ( ! $this->is_public_event( $event ) ) {
			return null;
		}

		return [
			'name'     => $this->field( $event, 'event_title' ),
			'start'    => $this->event_datetime( $event, 'event_begin', 'event_time' ),
			'end'      => $this->event_datetime( $event, 'event_end', 'event_endtime' ),
			'location' => $this->location_name( $event ),
			'url'      => $this->field( $event, 'event_link' ),
			'status'   => 3 === (int) $event->event_approved ? 'cancelled' : '',
		];
	}

	/**
	 * Whether My Calendar may expose an event to an anonymous reader.
	 *
	 * @param object $event Event object.
	 */
	public function is_public_event( object $event ): bool {
		$state = isset( $event->event_approved ) ? (string) $event->event_approved : '';
		if ( function_exists( 'mc_event_states_type' ) ) {
			$public = 'public' === \mc_event_states_type( $state );
		} else {
			$public = in_array( (int) $state, [ 1, 3 ], true );
		}

		$public = $public && 1 !== (int) ( $event->event_flagged ?? 0 );
		$public = $public && 1 !== (int) ( $event->category_private ?? 0 );

		if ( $public && isset( $event->categories ) && is_array( $event->categories ) ) {
			foreach ( $event->categories as $category ) {
				if ( is_object( $category ) && 1 === (int) ( $category->category_private ?? 0 ) ) {
					$public = false;
					break;
				}
			}
		}

		if ( $public && function_exists( 'mc_get_private_categories' ) ) {
			$private_categories = array_map( 'intval', (array) \mc_get_private_categories() );
			$public             = ! in_array( (int) ( $event->event_category ?? 0 ), $private_categories, true );
		}

		return ! (bool) apply_filters( 'mc_private_event', ! $public, $event ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- My Calendar's public privacy filter.
	}

	/**
	 * Fetch the raw event object through My Calendar's own functions.
	 *
	 * The block stores an event ID, so occurrence lookups are never used.
	 *
	 * @param int $event_id My Calendar event ID.
	 * @return object|null Event object, or null when unavailable.
	 */
	private function fetch_event( int $event_id ): ?object {
		if ( function_exists( 'mc_get_event_core' ) ) {
			$event = \mc_get_event_core( $event_id );
			return is_object( $event ) ? $event : null;
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

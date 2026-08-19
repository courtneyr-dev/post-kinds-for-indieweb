<?php
/**
 * The Events Calendar integration.
 *
 * Reads event data from The Events Calendar (the-events-calendar) so an
 * event-card block can reference a tribe_events post instead of duplicating
 * its data. Feature-detected: the plugin is optional and every lookup
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
 * The Events Calendar integration class.
 *
 * @since 1.4.0
 */
class The_Events_Calendar {

	/**
	 * The Events Calendar's event post type.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'tribe_events';

	/**
	 * Whether The Events Calendar is active.
	 *
	 * @return bool True when the plugin is detected.
	 */
	public function is_active(): bool {
		$active = class_exists( 'Tribe__Events__Main' )
			|| function_exists( 'tribe_get_start_date' )
			|| post_type_exists( self::POST_TYPE );

		/**
		 * Filter whether a calendar source is considered active.
		 *
		 * @since 1.4.0
		 *
		 * @param bool   $active Detected active state.
		 * @param string $source Calendar source slug.
		 */
		return (bool) apply_filters( 'pkiw_calendar_source_active', $active, Calendar_Events::SOURCE_THE_EVENTS_CALENDAR );
	}

	/**
	 * Fetch an event's data by tribe_events post ID.
	 *
	 * @param int $event_id Event post ID.
	 * @return array{name: string, start: string, end: string, location: string, url: string}|null
	 *         Event data, or null when inactive or not a tribe event.
	 */
	public function get_event( int $event_id ): ?array {
		if ( ! $this->is_active() ) {
			return null;
		}

		$event = get_post( $event_id );

		if ( ! $event instanceof \WP_Post || self::POST_TYPE !== $event->post_type ) {
			return null;
		}

		// Only publicly viewable events resolve. The card renders to anonymous
		// visitors, so a draft, private, pending, or password-protected event
		// referenced by ID must not leak its title, dates, or venue.
		if ( ! is_post_publicly_viewable( $event ) || '' !== $event->post_password ) {
			return null;
		}

		return [
			'name'     => $event->post_title,
			'start'    => $this->event_date( $event_id, '_EventStartDate', 'tribe_get_start_date' ),
			'end'      => $this->event_date( $event_id, '_EventEndDate', 'tribe_get_end_date' ),
			'location' => $this->venue_name( $event_id ),
			'url'      => (string) get_permalink( $event ),
		];
	}

	/**
	 * An event date, via the tribe helper when available, else post meta.
	 *
	 * @param int    $event_id Event post ID.
	 * @param string $meta_key Fallback meta key (_EventStartDate / _EventEndDate).
	 * @param string $helper   Tribe helper function name.
	 * @return string Date string (Y-m-d H:i:s), or '' when unset.
	 */
	private function event_date( int $event_id, string $meta_key, string $helper ): string {
		if ( function_exists( $helper ) ) {
			$value = call_user_func( $helper, $event_id, true, 'Y-m-d H:i:s' );

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$meta = get_post_meta( $event_id, $meta_key, true );

		return is_string( $meta ) ? $meta : '';
	}

	/**
	 * The event's venue name, via the tribe helper when available, else the
	 * _EventVenueID meta pointing at a venue post.
	 *
	 * @param int $event_id Event post ID.
	 * @return string Venue name, or '' when the event has no venue.
	 */
	private function venue_name( int $event_id ): string {
		if ( function_exists( 'tribe_get_venue' ) ) {
			$venue = trim( \tribe_get_venue( $event_id ) );

			if ( '' !== $venue ) {
				return $venue;
			}
		}

		$venue_id = (int) get_post_meta( $event_id, '_EventVenueID', true );

		if ( $venue_id <= 0 ) {
			return '';
		}

		$venue = get_post( $venue_id );

		return $venue instanceof \WP_Post ? $venue->post_title : '';
	}
}

<?php
/**
 * Privacy filters for My Calendar event locations.
 *
 * @package PKIW
 * @since   1.10.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Event_Calendar;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove opted-out event locations from every My Calendar output path.
 *
 * @since 1.10.0
 */
final class My_Calendar_Privacy {

	/**
	 * Request-local location needles, keyed by event ID.
	 *
	 * @var array<int, string[]>
	 */
	private static array $needles = [];

	/** Register provider filters even when My Calendar is inactive. */
	public static function register(): void {
		$filters = [
			[ 'mc_event_object', 'filter_event_object', 10, 1 ],
			[ 'mc_filter_shortcodes', 'filter_shortcodes', 99, 2 ],
			[ 'mc_event_schema', 'filter_schema', 10, 3 ],
			[ 'mc_output_is_visible', 'filter_visibility', 10, 4 ],
			[ 'mc_category_key_array', 'filter_category_key', 10, 3 ],
			[ 'my_calendar_events_args', 'filter_event_args', 10, 1 ],
			[ 'mc_location_limit_sql', 'filter_location_limit_sql', 10, 3 ],
		];

		foreach ( $filters as [ $hook, $method, $priority, $accepted_args ] ) {
			$callback = [ self::class, $method ];
			if ( false === has_filter( $hook, $callback ) ) {
				add_filter( $hook, $callback, $priority, $accepted_args );
			}
		}
	}

	/**
	 * Whether an event's location must be hidden.
	 *
	 * @param object $event Event object.
	 */
	public static function hides( object $event ): bool {
		$settings = get_option( 'pkiw_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		if ( ! empty( $settings[ Event_Calendar::SETTING_HIDE_ALL ] ) ) {
			return true;
		}

		$slugs = apply_filters( 'pkiw_my_calendar_hide_location_slugs', [ 'hide-location' ] );
		$slugs = is_array( $slugs ) ? array_map( 'sanitize_title', $slugs ) : [ 'hide-location' ];

		foreach ( self::category_names( $event ) as $name ) {
			if ( in_array( sanitize_title( $name ), $slugs, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clone and blank a hidden event's location fields.
	 *
	 * @param object $event Event object.
	 */
	public static function filter_event_object( object $event ): object {
		if ( self::is_admin_editor() || ! self::hides( $event ) ) {
			return $event;
		}

		$event_id                   = isset( $event->event_id ) ? (int) $event->event_id : 0;
		self::$needles[ $event_id ] = self::location_needles( $event );
		$filtered                   = clone $event;
		unset( $filtered->location );
		$filtered->event_location       = 0;
		$filtered->pkiw_location_hidden = true;

		foreach ( self::legacy_location_fields() as $field ) {
			$filtered->{$field} = '';
		}

		return $filtered;
	}

	/**
	 * Scrub every location tag and any encoded location-bearing link.
	 *
	 * @param array<string, mixed> $tags  My Calendar template tags.
	 * @param object               $event Event object.
	 * @return array<string, mixed>
	 */
	public static function filter_shortcodes( array $tags, object $event ): array {
		if ( self::is_admin_editor() || ! self::hidden_event( $event ) ) {
			return $tags;
		}

		$location_keys = [
			'location',
			'street',
			'street2',
			'city',
			'state',
			'postcode',
			'region',
			'country',
			'phone',
			'phone2',
			'hcard',
			'link_map',
			'map_url',
			'map',
			'location_access',
			'ical_location',
			'sitelink',
			'sitelink_html',
			'location_source',
		];

		foreach ( $tags as $key => $value ) {
			if ( in_array( $key, $location_keys, true ) || str_starts_with( (string) $key, 'location_' ) ) {
				$tags[ $key ] = '';
			}
		}

		$event_id = isset( $event->event_id ) ? (int) $event->event_id : 0;
		$needles  = self::$needles[ $event_id ] ?? self::location_needles( $event );
		foreach ( $tags as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			foreach ( $needles as $needle ) {
				$forms = [ $needle, urlencode( $needle ), rawurlencode( $needle ), esc_html( $needle ) ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- My Calendar's Google links use application/x-www-form-urlencoded values.
				foreach ( array_unique( $forms ) as $form ) {
					if ( '' !== $form && str_contains( $value, $form ) ) {
						$tags[ $key ] = '';
						continue 3;
					}
				}
			}
		}

		return $tags;
	}

	/**
	 * Remove schema location without misclassifying an in-person event as online.
	 *
	 * @param array<string, mixed> $schema Schema.org Event data.
	 * @param object               $event  Event object.
	 * @param array<string, mixed> $tags   Template tags (unused).
	 * @return array<string, mixed>
	 */
	public static function filter_schema( array $schema, object $event, array $tags = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::is_admin_editor() || ! self::hidden_event( $event ) ) {
			return $schema;
		}

		unset( $schema['location'] );
		$schema['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';

		return $schema;
	}

	/**
	 * Hide My Calendar's address and map feature output.
	 *
	 * @param bool         $visible Existing visibility.
	 * @param string       $feature Feature key.
	 * @param string       $type    Display type (unused).
	 * @param object|false $event   Event object.
	 */
	public static function filter_visibility( bool $visible, string $feature, string $type, $event ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::is_admin_editor() || ! is_object( $event ) || ! self::hidden_event( $event ) ) {
			return $visible;
		}

		return in_array( $feature, [ 'address', 'gmap', 'gmap_link', 'map' ], true ) ? false : $visible;
	}

	/**
	 * Remove hide-rule categories from the public legend.
	 *
	 * @param object[] $categories Category objects.
	 * @param string   $selected   Selected categories (unused).
	 * @param string   $id         Calendar ID (unused).
	 * @return object[]
	 */
	public static function filter_category_key( array $categories, string $selected = '', string $id = '' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::is_admin_editor() ) {
			return $categories;
		}

		$slugs = apply_filters( 'pkiw_my_calendar_hide_location_slugs', [ 'hide-location' ] );
		$slugs = is_array( $slugs ) ? array_map( 'sanitize_title', $slugs ) : [ 'hide-location' ];

		return array_values(
			array_filter(
				$categories,
				static function ( $category ) use ( $slugs ): bool {
					$name = is_object( $category ) && isset( $category->category_name ) && is_scalar( $category->category_name )
						? (string) $category->category_name
						: '';

					return ! in_array( sanitize_title( $name ), $slugs, true );
				}
			)
		);
	}

	/**
	 * Disable public location filtering as a location-presence oracle.
	 *
	 * @param array<string, mixed> $args My Calendar event query arguments.
	 * @return array<string, mixed>
	 */
	public static function filter_event_args( array $args ): array {
		if ( self::is_admin_editor() ) {
			return $args;
		}

		unset( $args['ltype'], $args['lvalue'] );

		return $args;
	}

	/**
	 * Remove My Calendar's final public location SQL clause.
	 *
	 * My Calendar rebuilds this clause from request parameters after filtering
	 * the event arguments, so both choke points are required.
	 *
	 * @param mixed $limit_string Location SQL clause.
	 * @param mixed $ltype        Location field type (unused; may be an array from $_GET).
	 * @param mixed $lvalue       Location values (unused).
	 */
	public static function filter_location_limit_sql( $limit_string, $ltype = '', $lvalue = [] ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Untyped: $ltype comes from $_GET through map_deep(), so ?ltype[]= passes an array.
		return self::is_admin_editor() ? (string) $limit_string : '';
	}

	/** Whether callbacks should leave the event editor's stored data intact. */
	private static function is_admin_editor(): bool {
		return is_admin() && ! wp_doing_ajax();
	}

	/**
	 * Whether an event is already marked hidden or matches the live rule.
	 *
	 * @param object $event Event object.
	 */
	private static function hidden_event( object $event ): bool {
		return ! empty( $event->pkiw_location_hidden ) || self::hides( $event );
	}

	/**
	 * Resolve category names without requiring My Calendar to be active.
	 *
	 * @param object $event Event object.
	 * @return string[]
	 */
	private static function category_names( object $event ): array {
		$categories = [];
		if ( isset( $event->categories ) && is_array( $event->categories ) && [] !== $event->categories ) {
			$categories = $event->categories;
		} elseif ( isset( $event->category_name ) && is_scalar( $event->category_name ) ) {
			return [ (string) $event->category_name ];
		} elseif ( function_exists( 'mc_get_categories' ) && self::uses_optional_helper( 'mc_get_categories' ) ) {
			$resolved   = \mc_get_categories( $event, 'objects' );
			$categories = is_array( $resolved ) ? $resolved : [];
		}

		$names = [];
		foreach ( $categories as $category ) {
			if ( is_object( $category ) && isset( $category->category_name ) && is_scalar( $category->category_name ) ) {
				$names[] = (string) $category->category_name;
			}
		}

		return $names;
	}

	/**
	 * Capture location strings that can survive inside generated links.
	 *
	 * @param object $event Event object.
	 * @return string[]
	 */
	private static function location_needles( object $event ): array {
		$location = isset( $event->location ) && is_object( $event->location ) ? $event->location : null;
		$prefix   = 'location_';
		if ( null === $location ) {
			$location = $event;
			$prefix   = 'event_';
		}

		$needles = [];
		foreach ( [ 'label', 'street', 'street2', 'latitude', 'longitude' ] as $field ) {
			$property = $prefix . $field;
			$value    = $location->{$property} ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$needles[] = trim( (string) $value );
			}
		}

		if ( [] === $needles && ! empty( $event->event_location ) && function_exists( 'mc_get_location' ) && self::uses_optional_helper( 'mc_get_location' ) ) {
			$resolved = \mc_get_location( (int) $event->event_location );
			if ( is_object( $resolved ) ) {
				return self::location_needles( (object) [ 'location' => $resolved ] );
			}
		}

		return array_values( array_unique( $needles ) );
	}

	/**
	 * Whether an optional My Calendar helper may run.
	 *
	 * @param string $helper_name Global helper function name.
	 */
	private static function uses_optional_helper( string $helper_name ): bool {
		/**
		 * Filters whether a detected optional My Calendar helper may run.
		 *
		 * @param bool   $use_helper Whether to call the helper.
		 * @param string $helper_name Global helper function name.
		 */
		return (bool) apply_filters( 'pkiw_my_calendar_use_optional_helper', true, $helper_name );
	}

	/**
	 * Legacy event-table location columns My Calendar still serializes.
	 *
	 * @return string[]
	 */
	private static function legacy_location_fields(): array {
		return [
			'event_label',
			'event_street',
			'event_street2',
			'event_city',
			'event_state',
			'event_postcode',
			'event_region',
			'event_country',
			'event_url',
			'event_phone',
			'event_phone2',
			'event_latitude',
			'event_longitude',
		];
	}
}

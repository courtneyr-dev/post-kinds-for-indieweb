<?php
/**
 * Simple Location weather integration (read-only).
 *
 * Simple Location saves one weather observation per post as `weather_<prop>`
 * post meta, in metric units, at capture time. This adapter reads that
 * snapshot for display. It never fetches weather, never calls a Simple
 * Location provider, and never writes, migrates, or deletes weather meta.
 *
 * Verified against Simple Location 5.0.25:
 * - Meta registration: includes/class-weather-data.php:84-163
 *   (`show_in_rest => false`).
 * - Read API: get_post_weatherdata() (includes/data-functions.php:171) →
 *   Sloc_Weather_Data::get_object_weatherdata() (class-weather-data.php:300),
 *   which first calls migrate_weather() (:339). migrate_weather() (:363-383)
 *   returns without writing when the post has no legacy `geo_weather` row,
 *   and otherwise rewrites it into `weather_*` rows and deletes it. This
 *   adapter only calls the getter for posts with no `geo_weather` row, and
 *   parses a legacy row itself, read-only.
 * - Labels and icons: weather_condition_codes() / weather_condition_icons()
 *   (trait-weather-info.php:342, :440), applied the way get_the_weather()
 *   does (class-weather-data.php:442-445).
 * - Display units: `sloc_units` query var, else the `sloc_measurements`
 *   option (class-weather-data.php:477, :497), converted by
 *   Weather_Provider::metric_to_imperial() (class-weather-provider.php:639).
 *
 * Simple Location's own inline weather output is left alone. A site that
 * shows both can turn Simple Location's off with its
 * `simple_location_display_defaults` filter (`weather => false`).
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Meta_Fields;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only facade over Simple Location's stored weather observation.
 *
 * @since 1.9.0
 */
final class Simple_Location_Weather {

	/**
	 * Numeric Simple Location properties the observation exposes.
	 *
	 * @var array<int, string>
	 */
	private const NUMERIC_FIELDS = [
		'temperature',
		'humidity',
		'pressure',
		'windspeed',
		'winddegree',
		'windgust',
		'cloudiness',
		'rain',
		'snow',
		'visibility',
		'uv',
	];

	/**
	 * Whether Simple Location's weather API is loaded.
	 *
	 * Checked at call time so load order doesn't matter.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$active = function_exists( 'get_post_weatherdata' )
			&& class_exists( 'Sloc_Weather_Data' )
			&& class_exists( 'Weather_Provider' );

		/**
		 * Filters whether Simple Location is treated as the weather source.
		 *
		 * Return false to hide every Simple Location weather value Post
		 * Kinds would show.
		 *
		 * @since 1.9.0
		 *
		 * @param bool $active Whether Simple Location's weather API is loaded.
		 */
		return (bool) apply_filters( 'pkiw_weather_source_active', $active );
	}

	/**
	 * Whether the current viewer may see a post's weather.
	 *
	 * A reading tied to the post's timestamp narrows location to a forecast
	 * grid cell, so it gets the same city-level visibility as the
	 * `locality` tier of Meta_Fields::get_visible_location_fields(). On top
	 * of that, Simple Location's own visibility must not resolve to
	 * private: an empty per-post `geo_public` falls back to the site's
	 * `geo_public` option the way Geo_Data::get_default_visibility() does
	 * (class-geo-data.php:77-89, :494-500), and an unset option is private.
	 * Anyone who can edit the post always sees it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_view( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		$visible = Meta_Fields::get_visible_location_fields( $post_id );
		if ( empty( $visible['locality'] ) ) {
			return false;
		}

		$geo_public = (string) get_post_meta( $post_id, 'geo_public', true );
		if ( '' === $geo_public ) {
			$geo_public = (string) (int) get_option( 'geo_public' );
		}

		return in_array( $geo_public, [ '1', '2' ], true );
	}

	/**
	 * The post's stored observation, normalized for display.
	 *
	 * Keys appear only when Simple Location stored a value: summary,
	 * condition_code, condition_label, icon, and the numeric fields in
	 * NUMERIC_FIELDS (converted to the display units). When temperature is
	 * present, temperature_unit (°C or °F) comes with it. `units` is always
	 * present on a non-null result.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string|float>|null Null when Simple Location is
	 *         inactive, the viewer may not see the post's location, or no
	 *         observation is stored.
	 */
	public static function get_observation( int $post_id ): ?array {
		if ( $post_id <= 0 || ! get_post( $post_id ) instanceof \WP_Post ) {
			return null;
		}

		if ( ! self::is_active() || ! self::can_view( $post_id ) ) {
			return null;
		}

		$raw = self::read_stored( $post_id );
		if ( [] === $raw ) {
			return null;
		}

		$imperial = self::uses_imperial();
		$numeric  = [];
		foreach ( self::NUMERIC_FIELDS as $field ) {
			if ( isset( $raw[ $field ] ) && is_numeric( $raw[ $field ] ) ) {
				$numeric[ $field ] = (float) $raw[ $field ];
			}
		}
		if ( $imperial && [] !== $numeric ) {
			$converted = \Weather_Provider::metric_to_imperial( $numeric );
			$numeric   = is_array( $converted ) ? array_map( 'floatval', $converted ) : $numeric;
		}

		$observation = [];

		$code = isset( $raw['code'] ) && is_numeric( $raw['code'] ) ? (string) (int) $raw['code'] : '';
		if ( '' !== $code ) {
			$observation['condition_code'] = $code;
			$label                         = \Sloc_Weather_Data::weather_condition_codes( $code );
			if ( is_string( $label ) && '' !== $label ) {
				$observation['condition_label'] = $label;
			}
		}

		$summary = isset( $raw['summary'] ) && is_scalar( $raw['summary'] ) ? trim( sanitize_text_field( (string) $raw['summary'] ) ) : '';
		if ( '' === $summary && isset( $observation['condition_label'] ) ) {
			$summary = $observation['condition_label'];
		}
		if ( '' !== $summary ) {
			$observation['summary'] = $summary;
		}

		$icon = '' !== $code ? \Sloc_Weather_Data::weather_condition_icons( $code ) : ( $raw['icon'] ?? '' );
		$icon = is_string( $icon ) ? sanitize_html_class( $icon ) : '';
		if ( '' !== $icon ) {
			$observation['icon'] = $icon;
		}

		foreach ( $numeric as $field => $value ) {
			$observation[ $field ] = $value;
			if ( 'temperature' === $field ) {
				$observation['temperature_unit'] = $imperial ? '°F' : '°C';
			}
		}

		if ( [] === $observation ) {
			return null;
		}

		$observation['units'] = $imperial ? 'imperial' : 'metric';

		return $observation;
	}

	/**
	 * One observation field formatted for display, with its unit.
	 *
	 * Temperature rounds to a whole degree, as Simple Location's own
	 * display does (class-weather-data.php:483-490); other values keep up
	 * to two decimals (Weather_Provider::markup_value()'s default).
	 *
	 * @param string $field   summary, condition, code, or a numeric field.
	 * @param int    $post_id Post ID.
	 * @return string|null Null when the value isn't available to this viewer.
	 */
	public static function format( string $field, int $post_id ): ?string {
		$observation = self::get_observation( $post_id );
		if ( null === $observation ) {
			return null;
		}

		return self::format_field( $field, $observation );
	}

	/**
	 * A `p-weather` paragraph for the post's observation, or ''.
	 *
	 * Text only: the condition and temperature, e.g. "Clear Sky, 27 °C".
	 * The icon slug stays in get_observation() for themes that want it.
	 *
	 * @param int $post_id Post ID.
	 * @return string Escaped HTML.
	 */
	public static function render( int $post_id ): string {
		$observation = self::get_observation( $post_id );
		if ( null === $observation ) {
			return '';
		}

		$parts = array_filter(
			[
				self::format_field( 'summary', $observation ),
				self::format_field( 'temperature', $observation ),
			]
		);
		if ( [] === $parts ) {
			return '';
		}

		return '<p class="pk-weather p-weather">' . esc_html( implode( ', ', $parts ) ) . '</p>';
	}

	/**
	 * Format one field from a normalized observation.
	 *
	 * @param string                      $field       Field name.
	 * @param array<string, string|float> $observation Result of get_observation().
	 * @return string|null
	 */
	private static function format_field( string $field, array $observation ): ?string {
		$imperial = 'imperial' === ( $observation['units'] ?? 'metric' );

		switch ( $field ) {
			case 'summary':
				return isset( $observation['summary'] ) ? (string) $observation['summary'] : null;
			case 'condition':
				return isset( $observation['condition_label'] ) ? (string) $observation['condition_label'] : null;
			case 'code':
				return isset( $observation['condition_code'] ) ? (string) $observation['condition_code'] : null;
		}

		if ( ! in_array( $field, self::NUMERIC_FIELDS, true ) || ! isset( $observation[ $field ] ) ) {
			return null;
		}

		$value = (float) $observation[ $field ];

		switch ( $field ) {
			case 'temperature':
				return self::number( round( $value ) ) . ' ' . ( $imperial ? '°F' : '°C' );
			case 'humidity':
			case 'cloudiness':
				return self::number( $value ) . '%';
			case 'winddegree':
				return self::number( $value ) . '°';
			case 'pressure':
				return self::number( $value ) . ' ' . ( $imperial ? 'inHg' : 'hPa' );
			case 'windspeed':
			case 'windgust':
				return self::number( $value ) . ' ' . ( $imperial ? 'mph' : 'm/s' );
			case 'rain':
			case 'snow':
				return self::number( $value ) . ' ' . ( $imperial ? 'in' : 'mm' );
			case 'visibility':
				return self::number( $value ) . ' ' . ( $imperial ? 'mi' : 'm' );
			default:
				return self::number( $value );
		}
	}

	/**
	 * A number rounded to two decimals, without trailing zeros.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function number( float $value ): string {
		return (string) round( $value, 2 );
	}

	/**
	 * Whether Simple Location displays imperial units for this request.
	 *
	 * @return bool
	 */
	private static function uses_imperial(): bool {
		return 'imperial' === get_query_var( 'sloc_units', get_option( 'sloc_measurements' ) );
	}

	/**
	 * The stored weather properties, without triggering any write.
	 *
	 * A post still holding Simple Location's legacy `geo_weather` array is
	 * parsed here, the way migrate_weather() maps it, so Simple Location's
	 * getter never runs its migration from a Post Kinds read.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private static function read_stored( int $post_id ): array {
		$legacy = get_post_meta( $post_id, 'geo_weather', true );
		if ( ! empty( $legacy ) ) {
			return is_array( $legacy ) ? self::read_legacy( $post_id, $legacy ) : [];
		}

		$stored = get_post_weatherdata( $post_id );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Merge a legacy `geo_weather` array over the `weather_*` rows, as
	 * migrate_weather() would, without writing.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $legacy  Legacy weather array.
	 * @return array<string, mixed>
	 */
	private static function read_legacy( int $post_id, array $legacy ): array {
		$properties = \Sloc_Weather_Data::$properties;

		$stored = [];
		foreach ( $properties as $prop ) {
			$value = get_post_meta( $post_id, 'weather_' . $prop, true );
			if ( ! empty( $value ) ) {
				$stored[ $prop ] = $value;
			}
		}

		if ( isset( $legacy['wind'] ) && is_array( $legacy['wind'] ) ) {
			$wind = array_filter(
				[
					'windgust'   => $legacy['wind']['gust'] ?? false,
					'winddegree' => $legacy['wind']['degree'] ?? false,
					'windspeed'  => $legacy['wind']['speed'] ?? false,
				]
			);
			unset( $legacy['wind'] );
			$legacy = array_merge( $wind, $legacy );
		}

		foreach ( wp_array_slice_assoc( $legacy, $properties ) as $prop => $value ) {
			if ( ! empty( $value ) ) {
				$stored[ $prop ] = $value;
			} else {
				unset( $stored[ $prop ] );
			}
		}

		return $stored;
	}
}

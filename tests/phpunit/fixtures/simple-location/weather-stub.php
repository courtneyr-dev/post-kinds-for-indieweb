<?php
/**
 * Minimal Simple Location 5.0.25 weather API for tests.
 *
 * CI has no Simple Location checkout, so this mirrors the parts of its
 * weather API that Post Kinds reads. Each symbol keeps the real signature
 * and body (trimmed maps aside) from simple-location 5.0.25:
 *
 * - get_post_weatherdata()                    includes/data-functions.php:171
 * - Sloc_Weather_Data::$properties            includes/class-weather-data.php:20
 * - Sloc_Weather_Data::set_object_weatherdata includes/class-weather-data.php:177
 * - Sloc_Weather_Data::get_object_weatherdata includes/class-weather-data.php:300
 *   (calls migrate_weather() at :339)
 * - Sloc_Weather_Data::migrate_weather        includes/class-weather-data.php:363
 * - Sloc_Weather_Data::get_the_weather        includes/class-weather-data.php:429
 * - weather_condition_codes()/_icons()        includes/trait-weather-info.php:342/:440
 * - Weather_Provider::get_icon()               includes/trait-weather-info.php:69
 * - Weather_Provider::markup_value()           includes/class-weather-provider.php:225
 * - Weather_Provider::metric_to_imperial()    includes/class-weather-provider.php:639
 * - unit conversions                          trait-weather-info.php:19/:39,
 *                                             class-sloc-provider.php:373/:415/:488
 *
 * migrate_weather() keeps its write, as in the real plugin, so a test
 * that reads a legacy `geo_weather` post through Simple Location's getter
 * would see the row rewritten. Post Kinds must not take that path.
 *
 * Every symbol is guarded: when the real plugin is loaded
 * (PKIW_TESTS_SIMPLE_LOCATION_FILE, see tests/phpunit/bootstrap.php) this
 * file defines nothing.
 *
 * @package PKIW
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, WordPress.NamingConventions, Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! class_exists( 'Weather_Provider' ) ) {
	/**
	 * Unit conversions from Simple Location's Weather_Provider.
	 */
	abstract class Weather_Provider {

		public static function get_icon( $icon, $summary = '' ) {
			return sprintf( '<span class="sloc-weather-icon %1$s" aria-hidden="true" title="%2$s"></span>', esc_attr( $icon ), esc_attr( $summary ) );
		}

		public static function markup_value( $property, $value, $args = array() ) {
			$defaults      = array(
				'container' => 'li',
				'units'     => get_query_var( 'sloc_units', get_option( 'sloc_measurements' ) ),
				'round'     => false,
			);
			$args          = wp_parse_args( $args, $defaults );
			$args['units'] = ( 'imperial' === $args['units'] );

			if ( is_numeric( $value ) ) {
				if ( is_numeric( $args['round'] ) ) {
					$value = round( $value, $args['round'] );
				} elseif ( true === $args['round'] ) {
					$value = round( $value );
				} else {
					$value = round( $value, 2 );
				}
			}

			$unit = $args['units'] ? '°F' : '°C';
			return sprintf(
				'<%1$s class="sloc-%2$s p-%2$s h-measure"><data class="p-type" value="Temperature"></data><data class="p-num" value="%3$s">%3$s</data><data class="p-unit" value="%4$s">%4$s</data></%1$s>',
				$args['container'],
				$property,
				$value,
				$unit
			);
		}

		public static function celsius_to_fahrenheit( $temp ) {
			return round( ( $temp * 9 / 5 ) + 32, 2 );
		}

		public static function hpa_to_inhg( $hpa ) {
			return round( $hpa * 0.03, 2 );
		}

		public static function mm_to_inches( $mm ) {
			return floatval( $mm ) / 25.4;
		}

		public static function meters_to_miles( $meters ) {
			return round( $meters / 1609, 2 );
		}

		public static function mps_to_miph( $mps ) {
			return round( $mps * 2.237, 2 );
		}

		public static function metric_to_imperial( $conditions ) {
			if ( ! is_array( $conditions ) ) {
				return $conditions;
			}
			foreach ( array( 'temperature', 'heatindex', 'windchill', 'dewpoint' ) as $temp ) {
				if ( array_key_exists( $temp, $conditions ) && is_numeric( $conditions[ $temp ] ) ) {
					$conditions[ $temp ] = self::celsius_to_fahrenheit( $conditions[ $temp ] );
				}
			}
			if ( array_key_exists( 'pressure', $conditions ) && is_numeric( $conditions['pressure'] ) ) {
				$conditions['pressure'] = self::hpa_to_inhg( $conditions['pressure'] );
			}
			if ( array_key_exists( 'visibility', $conditions ) && is_numeric( $conditions['visibility'] ) ) {
				$conditions['visibility'] = self::meters_to_miles( $conditions['visibility'] );
			}
			foreach ( array( 'windspeed', 'windgust' ) as $speed ) {
				if ( array_key_exists( $speed, $conditions ) && is_numeric( $conditions[ $speed ] ) ) {
					$conditions[ $speed ] = self::mps_to_miph( $conditions[ $speed ] );
				}
			}
			foreach ( array( 'rain', 'snow' ) as $fall ) {
				if ( array_key_exists( $fall, $conditions ) && is_numeric( $conditions[ $fall ] ) ) {
					$conditions[ $fall ] = self::mm_to_inches( $conditions[ $fall ] );
				}
			}
			return $conditions;
		}
	}
}

if ( ! class_exists( 'Sloc_Weather_Data' ) ) {
	/**
	 * Simple Location's weather storage and lookup.
	 */
	class Sloc_Weather_Data {

		public static $properties = array(
			'temperature',
			'humidity',
			'heatindex',
			'windchill',
			'dewpoint',
			'pressure',
			'cloudiness',
			'rain',
			'snow',
			'visibility',
			'radiation',
			'illuminance',
			'uv',
			'aqi',
			'pm1_0',
			'pm2_5',
			'pm10_0',
			'co',
			'co2',
			'nh3',
			'o3',
			'pb',
			'so2',
			'windspeed',
			'winddegree',
			'windgust',
			'summary',
			'icon',
			'code',
		);

		public static function set_object_weatherdata( $type, $id, $key, $weather ) {
			if ( ! $type || ! is_numeric( $id ) ) {
				return false;
			}
			$id = absint( $id );
			if ( ! $id ) {
				return false;
			}
			if ( ! empty( $key ) && ! in_array( $key, static::$properties, true ) ) {
				return false;
			}
			$check = apply_filters( "set_{$type}_weatherdata", null, $id, $type, $key, $weather );
			if ( ! is_null( $check ) ) {
				return $check;
			}
			if ( $key ) {
				return update_metadata( $type, $id, 'weather_' . $key, $weather );
			}
			$weather = wp_array_slice_assoc( $weather, static::$properties );
			foreach ( $weather as $prop => $value ) {
				if ( ! empty( $value ) ) {
					update_metadata( $type, $id, 'weather_' . $prop, $value );
				} else {
					delete_metadata( $type, $id, 'weather_' . $prop );
				}
			}
			return true;
		}

		public static function get_object_weatherdata( $type, $id, $key = '' ) {
			if ( ! $type || ! is_numeric( $id ) ) {
				return false;
			}
			$id = absint( $id );
			if ( ! $id ) {
				return false;
			}
			if ( ! empty( $key ) && ! in_array( $key, static::$properties, true ) ) {
				return false;
			}
			$check = apply_filters( "get_{$type}_weatherdata", null, $id, $key, $type );
			if ( null !== $check ) {
				return $check;
			}
			self::migrate_weather( $type, $id );
			if ( $key ) {
				return get_metadata( $type, $id, 'weather_' . $key, true );
			}
			$weather = array();
			foreach ( static::$properties as $prop ) {
				$weather[ $prop ] = get_metadata( $type, $id, 'weather_' . $prop, true );
			}
			return array_filter( $weather );
		}

		public static function migrate_weather( $type, $id ) {
			$weather = get_metadata( $type, $id, 'geo_weather', true );
			if ( ! $weather ) {
				return;
			}
			if ( array_key_exists( 'wind', $weather ) ) {
				$w = array(
					'windgust'   => $weather['wind']['gust'] ?? false,
					'winddegree' => $weather['wind']['degree'] ?? false,
					'windspeed'  => $weather['wind']['speed'] ?? false,
				);
				$w = array_filter( $w );
				unset( $weather['wind'] );
				$weather = array_merge( $w, $weather );
			}
			if ( self::set_object_weatherdata( $type, $id, '', $weather ) ) {
				delete_metadata( $type, $id, 'geo_weather' );
			}
		}

		public static function weather_condition_codes( $code = null ) {
			$map = array(
				'none' => 'None',
				'500'  => 'light rain',
				'800'  => 'Clear Sky',
				'801'  => 'Few Clouds',
			);
			if ( ! is_numeric( $code ) ) {
				return $map;
			}
			if ( array_key_exists( $code, $map ) ) {
				return $map[ $code ];
			}
			return '';
		}

		public static function weather_condition_icons( $code, $is_day = true ) {
			switch ( $code ) {
				case '500':
					return $is_day ? 'wi-day-rain' : 'wi-night-rain';
				case '800':
					return $is_day ? 'wi-day-sunny' : 'wi-night-clear';
				case '801':
					return $is_day ? 'wi-day-cloudy' : 'wi-night-cloudy';
				default:
					return '';
			}
		}

		public static function get_the_weather( $type, $id, $args = null ) {
			$weather  = self::get_object_weatherdata( $type, $id );
			$defaults = array(
				'style'         => 'simple',
				'description'   => 'Weather: ',
				'wrapper-class' => array( 'sloc-weather' ),
				'wrapper-type'  => 'p',
			);
			$args     = wp_parse_args( $args, $defaults );
			if ( ! is_array( $weather ) || empty( $weather ) ) {
				return '';
			}

			if ( isset( $weather['code'] ) ) {
				$weather['icon']    = self::weather_condition_icons( $weather['code'] );
				$weather['summary'] = self::weather_condition_codes( $weather['code'] );
			}
			if ( empty( $weather['icon'] ) ) {
				$weather['icon'] = 'wi-thermometer';
			}

			$class    = implode( ' ', $args['wrapper-class'] );
			$return   = array( PHP_EOL );
			$return[] = Weather_Provider::get_icon( $weather['icon'], $weather['summary'] ?? '' );
			if ( 'graphic' !== $args['style'] ) {
				if ( isset( $weather['temperature'] ) ) {
					$units = get_query_var( 'sloc_units', get_option( 'sloc_measurements' ) );
					if ( 'imperial' === $units ) {
						$weather = Weather_Provider::metric_to_imperial( $weather );
					}
					$return[] = Weather_Provider::markup_value(
						'temperature',
						$weather['temperature'],
						array(
							'container' => 'span',
							'round'     => true,
							'units'     => $units,
						)
					) . PHP_EOL;
				}
				if ( ! empty( $weather['summary'] ) ) {
					$return[] = sprintf( '<span class="p-weather">%1$s</span>', $weather['summary'] );
				}
			}

			return sprintf( '<%1$s class="%2$s">%3$s</%1$s>', $args['wrapper-type'], esc_attr( $class ), implode( PHP_EOL, array_filter( $return ) ) );
		}
	}
}

if ( ! function_exists( 'get_post_weatherdata' ) ) {
	function get_post_weatherdata( $post = null, $key = '' ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		return Sloc_Weather_Data::get_object_weatherdata( 'post', $post->ID, $key );
	}
}

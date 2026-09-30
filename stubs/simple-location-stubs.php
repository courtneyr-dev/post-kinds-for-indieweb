<?php
/**
 * PHPStan discovery stubs for the Simple Location plugin.
 *
 * Signatures only, mirroring the Simple Location 5.0.25 weather read API
 * that includes/integrations/class-simple-location-weather.php references.
 * Never loaded at runtime and never shipped — stubs/ is excluded from the
 * distribution; the real symbols come from the Simple Location plugin.
 *
 * @package PKIW
 */

// phpcs:ignoreFile -- Analyzer stubs, not runtime code.

/**
 * Weather data for a post (includes/data-functions.php:171).
 *
 * @param int|\WP_Post|null $post Post.
 * @param string            $key  Optional single property.
 * @return array<string, mixed>|string|false
 */
function get_post_weatherdata( $post = null, $key = '' ) {
	return false;
}

/**
 * Weather provider base (includes/class-weather-provider.php).
 */
abstract class Weather_Provider {
	/**
	 * Convert metric conditions to imperial (class-weather-provider.php:639).
	 *
	 * @param mixed $conditions Conditions.
	 * @return mixed
	 */
	public static function metric_to_imperial( $conditions ) {
		return $conditions;
	}
}

/**
 * Weather storage (includes/class-weather-data.php).
 */
class Sloc_Weather_Data {
	/**
	 * Stored weather properties (class-weather-data.php:20).
	 *
	 * @var array<int, string>
	 */
	public static $properties = array();

	/**
	 * Condition label for a code, or the whole map for a non-numeric code
	 * (trait-weather-info.php:342).
	 *
	 * @param string|int|null $code Condition code.
	 * @return string|array<string, string>
	 */
	public static function weather_condition_codes( $code = null ) {
		return '';
	}

	/**
	 * Icon slug for a code (trait-weather-info.php:440).
	 *
	 * @param string|int $code   Condition code.
	 * @param bool       $is_day Daytime.
	 * @return string
	 */
	public static function weather_condition_icons( $code, $is_day = true ) {
		return '';
	}
}

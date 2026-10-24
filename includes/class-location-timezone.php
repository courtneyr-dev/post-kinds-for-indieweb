<?php
/**
 * A hidden location's timezone stays out of its post's dates (issue 412).
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts a hidden-location post's dates back in the site timezone.
 *
 * Simple Location stores a timezone for a post that arrives with a location
 * (`geo_timezone`) and rewrites the post's dates into it, offset included:
 * the get_the_date() family at priority 9 and the REST `date` and `modified`
 * at 10 (Loc_Timezone, 5.0.25 class-loc-timezone.php:7-18 and :79-89). On a
 * post whose location is hidden, "+09:00" in a REST date or in the mf2
 * wrapper's dt-published narrows the location to a UTC offset.
 *
 * A location is hidden here when its venue name may not print to a visitor
 * (Meta_Fields::get_public_location_fields(), the test Title_Privacy uses):
 * `_pkiw_geo_privacy` 'private' or Simple Location `geo_public` '0'. An
 * approximate location prints its locality, region and country, which
 * already say more than an offset, so its dates keep Simple Location's
 * local time. Sleep posts keep their own, stricter gate (Sleep_Location).
 *
 * The REST edit context keeps Simple Location's value: it needs edit
 * rights, and the block editor reads it.
 */
final class Location_Timezone {

	/**
	 * Date and time getters Simple Location's Loc_Timezone reformats at
	 * priority 9.
	 *
	 * @var string[]
	 */
	private const DATE_HOOKS = [ 'get_the_date', 'get_the_time', 'get_the_modified_date', 'get_the_modified_time' ];

	/**
	 * Wire the filters. Safe to call more than once.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::DATE_HOOKS as $hook ) {
			add_filter( $hook, [ self::class, 'keep_site_timezone' ], 10, 3 );
		}
		add_filter( 'rest_prepare_post', [ self::class, 'rest_site_dates' ], 30, 3 );
	}

	/**
	 * Whether a post has a stored timezone and a hidden location.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function hides_timezone( \WP_Post $post ): bool {
		if ( '' === (string) get_post_meta( $post->ID, 'geo_timezone', true ) ) {
			return false;
		}

		return ! Meta_Fields::get_public_location_fields( $post->ID )['name'];
	}

	/**
	 * Print a hidden-location post's dates and times in the site timezone.
	 *
	 * Runs after Simple Location, at priority 10, and asks core for the
	 * post's own date fields in the site timezone, in the requested format,
	 * so 'U' and 'G' answer as core answers. It applies on admin screens too,
	 * because records built there (feeds, federation) leave the request.
	 *
	 * @internal Hooked to `get_the_date`, `get_the_time`, `get_the_modified_date` and `get_the_modified_time` at 10.
	 *
	 * @param mixed $value  The formatted value.
	 * @param mixed $format Requested format.
	 * @param mixed $post   Post, post ID or null.
	 * @return mixed
	 */
	public static function keep_site_timezone( $value, $format = '', $post = null ) {
		$post = get_post( is_object( $post ) || is_int( $post ) ? $post : null );
		if ( ! $post instanceof \WP_Post || ! self::hides_timezone( $post ) ) {
			return $value;
		}

		$hook     = current_filter();
		$option   = str_ends_with( $hook, '_time' ) ? 'time_format' : 'date_format';
		$format   = is_string( $format ) && '' !== $format ? $format : (string) get_option( $option );
		$modified = str_contains( $hook, 'modified' );

		$answer = $modified ? get_post_modified_time( $format, false, $post, true ) : get_post_time( $format, false, $post, true );

		return false === $answer ? $value : $answer;
	}

	/**
	 * Give a hidden-location post's REST `date` and `modified` core's
	 * site-time values outside the edit context.
	 *
	 * @internal Hooked to `rest_prepare_post` at 30.
	 *
	 * @param mixed $response Response.
	 * @param mixed $post     Post.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function rest_site_dates( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'edit' === $request->get_param( 'context' ) || ! self::hides_timezone( $post ) ) {
			return $response;
		}

		$data    = $response->get_data();
		$columns = [
			'date'     => 'post_date',
			'modified' => 'post_modified',
		];
		foreach ( $columns as $key => $column ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = mysql_to_rfc3339( $post->$column );
			}
		}
		$response->set_data( $data );

		return $response;
	}
}

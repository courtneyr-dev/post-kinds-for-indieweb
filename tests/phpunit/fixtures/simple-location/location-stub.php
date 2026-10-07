<?php
/**
 * Minimal Simple Location 5.0.25 location display API for tests.
 *
 * Reduced, faithful copies from includes/class-geo-data.php:
 *
 * - Geo_Data::get_default_visibility() lines 77-89
 * - Geo_Data::get_geodata() lines 493-529
 * - Geo_Data::location_content() lines 799-805
 * - Geo_Data::get_location() lines 958-972, 976-993, 1010-1014,
 *   1031-1034, and 1094-1099
 *
 * The class is guarded so the fixture defines nothing when the real Simple
 * Location plugin is loaded. Requiring this file registers no hooks.
 *
 * @package PKIW
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting, WordPress.NamingConventions

if ( ! class_exists( 'Geo_Data' ) ) {
	/**
	 * Simple Location geodata lookup and inline location rendering.
	 */
	class Geo_Data {

		public static function get_default_visibility() {
			$status = (int) get_option( 'geo_public' );
			switch ( (int) $status ) {
				case 0:
					return 'private';
				case 1:
					return 'public';
				case 2:
					return 'protected';
				default:
					return false;
			}
		}

		public static function get_geodata( $type, $id, $key = '' ) {
			if ( 'visibility' === $key ) {
				$visibility = get_metadata( $type, $id, 'geo_public', true );
				if ( empty( $visibility ) ) {
					return self::get_default_visibility();
				}
				switch ( (int) $visibility ) {
					case 0:
						return 'private';
					case 1:
						return 'public';
					case 2:
						return 'protected';
					default:
						return self::get_default_visibility();
				}
			}

			if ( ! empty( $key ) ) {
				return get_metadata( $type, $id, 'geo_' . $key, true );
			}

			$geodata = array(
				'latitude'   => get_metadata( $type, $id, 'geo_latitude', true ),
				'longitude'  => get_metadata( $type, $id, 'geo_longitude', true ),
				'address'    => get_metadata( $type, $id, 'geo_address', true ),
				'visibility' => self::get_geodata( $type, $id, 'visibility' ),
			);

			if ( empty( $geodata['longitude'] ) && empty( $geodata['address'] ) ) {
				return null;
			}

			return array_filter( $geodata );
		}

		public static function location_content( $content ) {
			$loc = self::get_location( 'post', get_the_ID() );
			if ( ! empty( $loc ) ) {
				$content .= $loc;
			}
			return $content;
		}

		public static function get_location( $type, $id, $args = array() ) {
			$loc = self::get_geodata( $type, $id );
			if ( ! is_array( $loc ) ) {
				return '';
			}
			if ( current_user_can( 'edit_post', $id ) && 'public' !== $loc['visibility'] ) {
				$loc['visibility'] = 'public';
			}
			if ( 'private' === $loc['visibility'] ) {
				return '';
			}

			$defaults = array(
				'weather'       => true,
				'icon'          => true,
				'text'          => false,
				'markup'        => true,
				'description'   => 'Location: ',
				'wrapper-class' => array( 'sloc-display' ),
				'wrapper-type'  => 'div',
			);
			$defaults = apply_filters( 'simple_location_display_defaults', $defaults );
			$args     = wp_parse_args( $args, $defaults );
			$args     = array_merge( $loc, $args );

			$class = is_array( $args['wrapper-class'] ) ? $args['wrapper-class'] : explode( ' ', $args['wrapper-class'] );
			if ( $args['markup'] ) {
				$class[] = 'p-location';
				$class[] = 'h-adr';
			}

			$c = array( PHP_EOL );
			if ( $args['text'] ) {
				$c[] = $args['description'];
			}
			if ( 'public' === $args['visibility'] ) {
				$address_class = $args['markup'] ? 'p-label' : '';
				$c[]            = sprintf( '<span class="%1$s">%2$s</span>', $address_class, $loc['address'] ?? '' );
			} elseif ( isset( $loc['address'] ) ) {
				$c[] = $loc['address'];
			}
			if ( $args['weather'] ) {
				$c[] = Sloc_Weather_Data::get_the_weather( $type, $id );
			}

			$return = implode( PHP_EOL, $c );
			return sprintf( '<%1$s class="%2$s">%3$s</%1$s>', $args['wrapper-type'], implode( ' ', $class ), $return );
		}
	}
}

<?php
/**
 * Simple Location Geo_Data fixture.
 *
 * @package PKIW
 */

declare(strict_types=1);

if ( ! class_exists( 'Geo_Data', false ) ) {
	/**
	 * Minimal Simple Location stand-in for content filters.
	 */
	class Geo_Data {

		/**
		 * Pass content through.
		 *
		 * @param string $content Post content.
		 * @return string
		 */
		public static function content_map( string $content ): string {
			return $content;
		}

		/**
		 * Pass content through.
		 *
		 * @param string $content Post content.
		 * @return string
		 */
		public static function location_content( string $content ): string {
			return $content;
		}
	}
}

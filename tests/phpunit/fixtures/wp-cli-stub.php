<?php
/**
 * Minimal WP-CLI stand-in for tests of PKIW\CLI_Commands.
 *
 * The real WP-CLI is not loaded under PHPUnit. This stub records every call
 * the command class makes, in order, so a test can compare the transcript
 * with the output the command produced before it was refactored. It defines
 * classes and functions only; it never defines the WP_CLI constant, because
 * WordPress core and other plugins read that constant to decide whether they
 * run under a command line.
 *
 * @package PKIW
 */

namespace {

	if ( ! class_exists( 'WP_CLI_Stub_Halt' ) ) {
		/**
		 * Thrown by WP_CLI::error(), which halts the real command.
		 */
		class WP_CLI_Stub_Halt extends \RuntimeException {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	}

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Records calls made to the WP_CLI facade.
		 */
		class WP_CLI { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

			/**
			 * Recorded calls as [ method, argument ] pairs.
			 *
			 * @var array<int, array{0:string,1:mixed}>
			 */
			public static array $calls = [];

			/**
			 * Forget recorded calls.
			 */
			public static function reset(): void {
				self::$calls = [];
			}

			/**
			 * @param string $message Message.
			 */
			public static function log( $message ): void {
				self::$calls[] = [ 'log', (string) $message ];
			}

			/**
			 * @param string $message Message.
			 */
			public static function success( $message ): void {
				self::$calls[] = [ 'success', (string) $message ];
			}

			/**
			 * @param string $message Message.
			 */
			public static function warning( $message ): void {
				self::$calls[] = [ 'warning', (string) $message ];
			}

			/**
			 * @param string $message Message.
			 * @throws \WP_CLI_Stub_Halt Always; the real method exits.
			 */
			public static function error( $message ): void {
				self::$calls[] = [ 'error', (string) $message ];
				throw new \WP_CLI_Stub_Halt( (string) $message );
			}

			/**
			 * Returns the string unchanged so the transcript shows the color tokens.
			 *
			 * @param string $string String with color tokens.
			 * @return string
			 */
			public static function colorize( $string ): string {
				self::$calls[] = [ 'colorize', (string) $string ];
				return (string) $string;
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( __NAMESPACE__ . '\\get_flag_value' ) ) {
		/**
		 * Same contract as the WP-CLI helper.
		 *
		 * @param array<string, mixed> $assoc_args Associative arguments.
		 * @param string               $flag       Flag name.
		 * @param mixed                $default_value Value when the flag is absent.
		 * @return mixed
		 */
		function get_flag_value( $assoc_args, $flag, $default_value = null ) {
			return $assoc_args[ $flag ] ?? $default_value;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		/**
		 * Records the arguments WP-CLI would render.
		 *
		 * @param string $format Output format.
		 * @param array  $items  Rows.
		 * @param array  $fields Column names.
		 */
		function format_items( $format, $items, $fields ): void {
			\WP_CLI::$calls[] = [
				'format_items',
				[
					'format' => $format,
					'items'  => $items,
					'fields' => $fields,
				],
			];
		}
	}
}

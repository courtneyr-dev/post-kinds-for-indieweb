<?php
/**
 * PHPStan stubs for symbols PHPStan cannot otherwise resolve.
 *
 * Three categories live here:
 *
 * 1. Plugin classes excluded from analysis in phpstan.neon's excludePaths
 *    (memory-exhaustion guard). Code outside those classes still calls
 *    them (`new REST_API()`, `REST_API::NAMESPACE`, etc.) so we declare
 *    no-body stubs; PHPStan picks them up via `scanFiles`.
 *
 * 2. Plugin-defined constants from `post-kinds-for-indieweb.php`. PHPStan
 *    sees the `define()` calls but doesn't always resolve their values
 *    through `plugin_dir_url(__FILE__)`-style dynamic expressions, so we
 *    re-declare them with literal placeholder values here.
 *
 * 3. WP_CLI's static API. WP-CLI isn't a composer dependency; the
 *    classes only exist when commands are run under `wp` CLI. PHPStan
 *    can't infer their signatures otherwise.
 *
 * 4. `External_APIs` — a runtime-optional component the plugin
 *    instantiates only when `class_exists()` succeeds. PHPStan doesn't
 *    track that class-existence guard, so we stub the class here.
 *
 * @phpstan-ignore-file
 */

// 1. Excluded plugin classes — only the methods/constants other code calls.
namespace PKIW {
	class REST_API {
		public const NAMESPACE = 'post-kinds-indieweb/v1';
		public function __construct() {}
	}

	class Import_Manager {
		public function __construct() {}
		public function start_import( string $source, array $options = [] ): mixed {
			return null;
		}
		public function get_status( string $job_id ): mixed {
			return null;
		}
		public function process_import_batch( string $job_id, string $source ): mixed {
			return null;
		}
		public function cancel( string $job_id ): mixed {
			return null;
		}
	}

	class External_APIs {
		public function __construct() {}
	}
}

namespace PKIW\Admin {
	class Settings_Page {
		public function __construct() {}
		public function init(): void {}
	}

	class API_Settings {
		/**
		 * @param mixed $admin Optional admin context the real implementation accepts.
		 */
		public function __construct( $admin = null ) {}
		public function init(): void {}
	}
}

// 2. Plugin-defined constants (mirror of post-kinds-for-indieweb.php).
namespace {
	if ( ! defined( 'PKIW_VERSION' ) ) {
		define( 'PKIW_VERSION', '1.0.1' );
	}
	if ( ! defined( 'PKIW_PATH' ) ) {
		define( 'PKIW_PATH', '' );
	}
	if ( ! defined( 'PKIW_URL' ) ) {
		define( 'PKIW_URL', '' );
	}
	if ( ! defined( 'PKIW_BASENAME' ) ) {
		define( 'PKIW_BASENAME', '' );
	}
	if ( ! defined( 'PKIW_PLUGIN_FILE' ) ) {
		define( 'PKIW_PLUGIN_FILE', '' );
	}
	if ( ! defined( 'PKIW_PLUGIN_URL' ) ) {
		define( 'PKIW_PLUGIN_URL', '' );
	}
	if ( ! defined( 'PKIW_MIN_PHP' ) ) {
		define( 'PKIW_MIN_PHP', '8.2' );
	}
	if ( ! defined( 'PKIW_MIN_WP' ) ) {
		define( 'PKIW_MIN_WP', '7.0' );
	}

	// 3. WP_CLI static API. Only the methods this plugin actually calls.
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			public static function log( string $message ): void {}
			public static function error( string $message, $exit = true ): void {}
			public static function success( string $message ): void {}
			public static function warning( string $message ): void {}
			public static function colorize( string $message ): string {
				return $message;
			}
			public static function add_command( string $name, $callable, array $args = [] ): bool {
				return true;
			}
		}
	}
}

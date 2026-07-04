<?php
/**
 * PHPUnit bootstrap file for Post Kinds for IndieWeb.
 *
 * @package PostKindsForIndieWeb
 */

// Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// WordPress tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Polyfills path for PHPUnit compatibility.
if ( file_exists( dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/' );
}

// Check if WordPress test suite is available.
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo "Could not find $_tests_dir/includes/functions.php. Please run bin/install-wp-tests.sh first.\n";
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/post-kinds-for-indieweb.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Default the book-completion service to a no-op stub for the whole suite.
// Book_Completion_Controller::complete_on_save fires on every save_post that
// carries a read-card block, so without this any test that creates such a
// post (CardMetaSyncTest, MicropubContentBuilderTest, ...) would construct
// the real API clients and make live HTTP requests — non-deterministic CI.
// Priority 5 so individual tests that install their own stub at the default
// priority 10 (BookCompletionControllerTest) still win.
add_filter(
	'pkiw_book_completion_service',
	static function () {
		return new class() {
			/**
			 * Return the book unchanged — no lookups, no HTTP.
			 *
			 * @param array<string, string> $book Partial book data.
			 * @return array<string, string> The same data, untouched.
			 */
			public function complete( array $book ): array {
				return $book;
			}
		};
	},
	5
);

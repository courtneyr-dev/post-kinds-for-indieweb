<?php
/** Zine production-literal boundary tests. @package PKIW */
declare(strict_types=1);

use PKIW\Public_Listing;

/** @group integration */
final class ZineGrepTest extends WP_UnitTestCase {
	public function test_zine_production_files_do_not_copy_public_listing_literals(): void {
		$root = dirname( __DIR__, 3 );
		$files = array_merge(
			glob( $root . '/includes/class-zine-*.php' ),
			[
				$root . '/templates/zine-print.php',
				$root . '/styles/zine.css',
				$root . '/assets/js/zine-export-editor.js',
				$root . '/assets/js/zine-print.js',
				$root . '/includes/class-plugin.php',
			]
		);
		foreach ( $files as $file ) {
			$text = file_get_contents( $file );
			$this->assertIsString( $text );
			foreach ( array_merge( Public_Listing::meta_keys(), [ 'quiet_public' ] ) as $literal ) {
				$this->assertStringNotContainsString( $literal, $text, $file );
			}
		}
	}

	public function test_eval_uses_the_helper_for_the_key(): void {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/x-269-zine.php' );
		$this->assertIsString( $text );
		foreach ( Public_Listing::meta_keys() as $key ) {
			$this->assertStringNotContainsString( $key, $text );
		}
	}
}

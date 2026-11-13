<?php
/**
 * Stored Micropub-property reader allowlist.
 *
 * @package PKIW
 */

declare(strict_types=1);

/** @group integration */
final class MetaMf2ReadersTest extends WP_UnitTestCase {

	public function test_only_the_frozen_mf2_readers_exist(): void {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PKIW_DIR . 'includes', FilesystemIterator::SKIP_DOTS ) );
		$hits  = [];
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$content = file_get_contents( $file->getPathname() );
			if ( str_contains( $content, "'mf2_" ) || str_contains( $content, '"mf2_' ) || str_contains( $content, 'micropub_get_mf2' ) ) {
				$hits[] = str_replace( PKIW_DIR, '', $file->getPathname() );
			}
		}
		sort( $hits );
		$this->assertSame(
			[
				'includes/class-micropub-content-builder.php',
				'includes/sync/class-checkin-sync-base.php',
				'includes/sync/class-listen-sync-base.php',
				'includes/sync/class-watch-sync-base.php',
			],
			$hits
		);
	}
}

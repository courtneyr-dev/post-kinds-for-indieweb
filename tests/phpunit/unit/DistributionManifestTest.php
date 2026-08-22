<?php
/**
 * Distribution manifest contract.
 *
 * Two files decide what ships: `.distignore` (the documented contract) and
 * the `files` array in `package.json` (what `wp-scripts plugin-zip`
 * actually reads). When they disagree, the zip silently loses code — the
 * 1.5.0 packaging step dropped `styles/kind-tokens.css` and all of
 * `admin/`, both enqueued at runtime, because `files` had never been
 * updated as the plugin grew.
 *
 * Nothing else catches this: the source tree is complete, CI builds from
 * the source tree, and Plugin Check runs against a zip that looks
 * plausible. Only installing the zip would reveal it.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;

/**
 * Verifies the shipped-file manifest matches the distribution contract.
 */
class DistributionManifestTest extends WP_UnitTestCase {

	/**
	 * Repository root.
	 */
	private function repo_root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Top-level entries `.distignore` excludes.
	 *
	 * @return array<int, string>
	 */
	private function ignored_entries(): array {
		$lines   = file( $this->repo_root() . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$ignored = [];

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$ignored[] = ltrim( $line, '/' );
		}

		return $ignored;
	}

	/**
	 * Top-level entries that should ship, per `.distignore`.
	 *
	 * @return array<int, string>
	 */
	private function expected_shipped(): array {
		$root    = $this->repo_root();
		$ignored = $this->ignored_entries();
		$shipped = [];

		foreach ( (array) scandir( $root ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || str_starts_with( $entry, '.' ) ) {
				continue;
			}
			if ( in_array( $entry, $ignored, true ) ) {
				continue;
			}
			// Build artifacts and local-only directories that are not tracked.
			if ( in_array( $entry, [ 'coverage', 'playwright-report', 'test-results', 'dist-check' ], true ) ) {
				continue;
			}
			if ( str_ends_with( $entry, '.zip' ) ) {
				continue;
			}

			$shipped[] = is_dir( $root . '/' . $entry ) ? $entry . '/' : $entry;
		}

		sort( $shipped );

		return $shipped;
	}

	/**
	 * `package.json` files array drives `wp-scripts plugin-zip`.
	 *
	 * @return array<int, string>
	 */
	private function manifest_files(): array {
		$package = json_decode(
			(string) file_get_contents( $this->repo_root() . '/package.json' ),
			true
		);

		$files = $package['files'] ?? [];
		sort( $files );

		return $files;
	}

	/**
	 * Heavy dot-directories are excluded by name.
	 *
	 * `expected_shipped()` skips every dot-entry, so a dot-directory missing
	 * from `.distignore` is invisible to the manifest comparison — but
	 * `wp dist-archive` reads `.distignore` alone and would package it.
	 * `.wordpress-org/` (banners and screenshots for the directory listing,
	 * ~6M) belongs in the SVN repo's assets/, never in trunk, and shipped
	 * that way only because the 1.7.0 build excluded it by hand.
	 */
	public function test_distignore_excludes_directory_listing_assets(): void {
		$ignored = $this->ignored_entries();

		foreach ( [ '.wordpress-org', '.git', '.github' ] as $entry ) {
			if ( ! is_dir( $this->repo_root() . '/' . $entry ) ) {
				continue;
			}

			$this->assertContains(
				$entry,
				$ignored,
				"$entry exists in the repo but .distignore does not exclude it, so wp dist-archive would ship it"
			);
		}
	}

	/**
	 * The manifest ships exactly what `.distignore` does not exclude.
	 */
	public function test_manifest_matches_distignore_contract(): void {
		$expected = $this->expected_shipped();
		$actual   = $this->manifest_files();

		// Guard the guard.
		$this->assertNotEmpty( $expected, 'Derived nothing from .distignore — the scan is broken.' );

		$missing = array_values( array_diff( $expected, $actual ) );
		$extra   = array_values( array_diff( $actual, $expected ) );

		$this->assertSame(
			[],
			$missing,
			"package.json \"files\" omits paths that .distignore says should ship.\n"
				. "These would be silently absent from the distribution zip:\n  "
				. implode( "\n  ", $missing )
		);

		$this->assertSame(
			[],
			$extra,
			"package.json \"files\" ships paths .distignore excludes:\n  "
				. implode( "\n  ", $extra )
		);
	}

	/**
	 * Every runtime-enqueued asset outside build/ is in the manifest.
	 */
	public function test_enqueued_asset_directories_ship(): void {
		$manifest = $this->manifest_files();

		foreach ( [ 'styles/', 'admin/', 'includes/', 'build/' ] as $required ) {
			$this->assertContains(
				$required,
				$manifest,
				"$required is enqueued at runtime but would not ship."
			);
		}
	}
}

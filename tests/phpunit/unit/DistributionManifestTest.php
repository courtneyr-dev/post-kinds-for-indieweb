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

	/**
	 * Every `assets/` file the PHP enqueues exists in the tree.
	 *
	 * The Check-in Dashboard enqueued Leaflet from `assets/vendor/` for
	 * months while `.gitignore`'s unanchored `vendor/` kept the files out of
	 * the repo, so every install served 404s for the map library (#308).
	 */
	public function test_enqueued_asset_files_exist(): void {
		$root  = $this->repo_root();
		$found = [];

		foreach ( [ 'includes', 'src/blocks', 'build/blocks' ] as $dir ) {
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );

			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				preg_match_all( "/PKIW_URL \\. '(assets\\/[^']+\\.(?:js|css))'/", (string) file_get_contents( $file->getPathname() ), $matches );

				foreach ( $matches[1] as $asset ) {
					$found[ $asset ] = $file->getPathname();
				}
			}
		}

		// Guard the guard.
		$this->assertArrayHasKey( 'assets/vendor/leaflet/leaflet.js', $found, 'The scan no longer finds the Leaflet enqueue.' );

		foreach ( $found as $asset => $source ) {
			$this->assertFileExists( $root . '/' . $asset, "$source enqueues $asset, which is not in the tree" );
		}
	}

	/**
	 * Every `file:` asset a built `block.json` names exists in `build/`.
	 *
	 * Blocks register from `build/blocks/`, and the zip ships no `src/`.
	 * The Check-in Dashboard named `"viewScript": "file:./view.js"`, which
	 * only `src/` held, so core registered the handle with no source and the
	 * dashboard's view buttons and map never ran (#313).
	 */
	public function test_block_json_file_assets_exist_in_build(): void {
		$root    = $this->repo_root();
		$fields  = [ 'script', 'viewScript', 'editorScript', 'style', 'viewStyle', 'editorStyle', 'render', 'scriptModule', 'viewScriptModule' ];
		$checked = 0;
		$missing = [];

		foreach ( (array) glob( $root . '/build/blocks/*/block.json' ) as $json ) {
			$metadata = json_decode( (string) file_get_contents( $json ), true );

			foreach ( $fields as $field ) {
				foreach ( (array) ( $metadata[ $field ] ?? [] ) as $value ) {
					if ( ! is_string( $value ) || ! str_starts_with( $value, 'file:' ) ) {
						continue;
					}

					++$checked;
					$asset = dirname( $json ) . '/' . preg_replace( '#^\./#', '', substr( $value, 5 ) );

					if ( ! file_exists( $asset ) ) {
						$missing[] = substr( $asset, strlen( $root ) + 1 ) . " ($field in " . basename( dirname( $json ) ) . '/block.json)';
					}
				}
			}
		}

		// Guard the guard.
		$this->assertGreaterThan( 0, $checked, 'Found no file: references in build/blocks/*/block.json — the scan is broken.' );

		$this->assertSame(
			[],
			$missing,
			"build/blocks/*/block.json names files build/ doesn't ship:\n  " . implode( "\n  ", $missing )
		);
	}

	/**
	 * `.gitignore` ignores Composer's root `vendor/` only.
	 *
	 * An unanchored `vendor/` also matches `assets/vendor/`.
	 */
	public function test_gitignore_anchors_vendor_to_the_root(): void {
		$lines = array_map( 'trim', (array) file( $this->repo_root() . '/.gitignore', FILE_IGNORE_NEW_LINES ) );

		$this->assertNotContains( 'vendor/', $lines, 'An unanchored vendor/ rule ignores assets/vendor/ too.' );
		$this->assertContains( '/vendor/', $lines );
	}

	/**
	 * Every PHP file under includes/ guards direct access within the first
	 * 50 lines after `<?php`.
	 *
	 * Plugin Check's AST pass looks only at top-level statements, and in a
	 * file with an unbraced `namespace` the guard sits inside the namespace
	 * node. Plugin Check then falls back to a regex over the first 50
	 * lines (Direct_File_Access_Check::has_direct_access_protection_regex())
	 * and reports missing_direct_file_access_protection when the guard is
	 * further down. This runs the same regex.
	 */
	public function test_includes_files_guard_direct_access_within_plugin_checks_window(): void {
		$root    = $this->repo_root();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS ) );
		$checked = 0;
		$missing = [];

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			++$checked;
			$contents  = (string) preg_replace( '/^<\?php\s*/i', '', (string) file_get_contents( $file->getPathname() ) );
			$beginning = implode( "\n", array_slice( explode( "\n", $contents ), 0, 50 ) );
			$code      = (string) preg_replace( '#/\*.*?\*/#s', '', $beginning );
			$code      = (string) preg_replace( '#//.*$#m', '', $code );
			$guarded   = preg_match( "/defined\s*\(\s*['\"](?:ABSPATH|WPINC)['\"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)/i", $code )
				|| preg_match( "/if\s*\(\s*!\s*defined\s*\(\s*['\"](?:ABSPATH|WPINC)['\"]\s*\)\s*\)\s*(?:\{|exit|die)/i", $code );

			if ( ! $guarded ) {
				$missing[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}

		$this->assertGreaterThan( 0, $checked, 'Found no PHP file under includes/, so the scan is broken.' );
		$this->assertSame( [], $missing, "No direct-access guard in Plugin Check's first 50 lines:\n  " . implode( "\n  ", $missing ) );
	}
}

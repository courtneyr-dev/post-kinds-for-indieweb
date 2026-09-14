<?php
/**
 * Guard against the `indieblocks_kind` taxonomy-name regression.
 *
 * Nothing in this plugin (or IndieBlocks upstream) ever registers a
 * taxonomy called `indieblocks_kind` — the real, registered taxonomy is
 * `kind` (Taxonomy::TAXONOMY, rest_base `kind`). A copy-paste artifact from
 * the check-in CPT removal (ed5295a) reintroduced `indieblocks_kind` string
 * literals in `includes/class-block-bindings-source.php`,
 * `includes/functions-checkin.php`, and every card block's `edit.js`, which
 * silently broke kind assignment and kind-meta block bindings in
 * production while unit tests kept passing because they registered a fake
 * `indieblocks_kind` taxonomy to match.
 *
 * This test scans `includes/` and `src/` for the literal string and fails
 * if it reappears, so a future edit can't reintroduce the same mismatch
 * without the test suite catching it.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;

/**
 * Fails if `indieblocks_kind` reappears in production PHP or JS source.
 */
class NoIndieblocksKindGuardTest extends WP_UnitTestCase {

	/**
	 * Scan includes/ and src/ for the literal string `indieblocks_kind`.
	 */
	public function test_indieblocks_kind_does_not_reappear_in_includes_or_src(): void {
		$violations = [];

		foreach ( $this->files( [ 'includes', 'src' ], [ 'php', 'js' ] ) as $file ) {
			$content = (string) file_get_contents( $file );

			foreach ( explode( "\n", $content ) as $index => $line ) {
				if ( str_contains( $line, 'indieblocks_kind' ) ) {
					$violations[] = sprintf( '%s:%d: %s', $this->relative( $file ), $index + 1, trim( $line ) );
				}
			}
		}

		$this->assertSame(
			[],
			$violations,
			sprintf(
				"Found %d reference(s) to the nonexistent 'indieblocks_kind' taxonomy. " .
				"Use PKIW\\Taxonomy::TAXONOMY (PHP) or the REST attribute 'kind' (JS) instead:\n%s",
				count( $violations ),
				implode( "\n", $violations )
			)
		);
	}

	/**
	 * Collect files with any of the given extensions under the given
	 * plugin-root directories.
	 *
	 * @param string[] $dirs       Directories relative to the plugin root.
	 * @param string[] $extensions File extensions without the dot.
	 * @return string[] Absolute file paths.
	 */
	private function files( array $dirs, array $extensions ): array {
		$found = [];

		foreach ( $dirs as $dir ) {
			$path = $this->root() . $dir;

			if ( ! is_dir( $path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
					$found[] = $file->getPathname();
				}
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Plugin root with trailing slash.
	 */
	private function root(): string {
		return dirname( __DIR__, 3 ) . '/';
	}

	/**
	 * Path relative to the plugin root.
	 *
	 * @param string $file Absolute path.
	 */
	private function relative( string $file ): string {
		return str_replace( $this->root(), '', $file );
	}
}

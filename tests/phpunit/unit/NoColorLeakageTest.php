<?php
/**
 * Design-token contract enforcement (audit Session C5).
 *
 * Non-admin plugin code may only reference colors through
 * `var(--pkiw-*, neutral-fallback)` token lookups. This test scans block
 * CSS, block JS, non-admin PHP, and pattern files for raw color literals
 * (hex, rgb()/rgba(), hsl()/hsla()) and fails listing each leak.
 *
 * Documented exemptions (see docs/audit/DESIGN-TOKENS.md):
 * - `includes/admin/` and `admin/` — WP admin chrome integration.
 * - `src/editor/` — editor sidebar chrome mirroring core components.
 * - Per-literal allowlist below, each with a reason.
 *
 * HTML character references such as `&#127925;` (emoji icons) contain
 * valid-looking hex color sequences; the hex pattern uses a negative
 * lookbehind for `&` so entities are never flagged as colors.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;

/**
 * Verifies no raw color literals leak into token-governed code paths.
 */
class NoColorLeakageTest extends WP_UnitTestCase {

	/**
	 * Hex color literal, excluding HTML character references (&#127925;).
	 */
	private const HEX_PATTERN = '/(?<!&)#[0-9a-fA-F]{3,8}\b/';

	/**
	 * Functional color notations.
	 */
	private const FUNC_PATTERN = '/\b(?:rgb|rgba|hsl|hsla)\s*\(/i';

	/**
	 * Allowed raw literals: relative file => literal => reason.
	 */
	private const ALLOWLIST = [
		'src/blocks/checkin-dashboard/style.css' => [
			'#666' => 'Leaflet popup text — popups are always white regardless of theme.',
		],
		'src/blocks/play-card/edit.js'           => [
			'#007cba' => 'Fallback for the core --wp-components-color-accent editor token.',
		],
	];

	/**
	 * Block and catalog CSS must reference colors only via --pkiw-* tokens.
	 */
	public function test_css_has_no_raw_colors() {
		$violations = [];

		foreach ( $this->files( [ 'src/blocks', 'styles' ], 'css' ) as $file ) {
			// The token catalog is the documented home of default paint
			// values (2026-07-03 bridge: palette presets with pre-2.0 hex
			// fallbacks), so raw literals are expected there. Consumers
			// stay literal-free and fully scanned.
			if ( 'styles/kind-tokens.css' === $this->relative( $file ) ) {
				continue;
			}

			$content = (string) file_get_contents( $file );
			// Strip comments so prose mentioning colors is not flagged.
			$content = (string) preg_replace( '#/\*.*?\*/#s', '', $content );

			$violations = array_merge( $violations, $this->scan( $file, $content ) );
		}

		$this->assertSame( [], $violations, $this->report( $violations ) );
	}

	/**
	 * Block editor JS (canvas-side) must not inline raw colors.
	 */
	public function test_block_js_has_no_raw_colors() {
		$violations = [];

		foreach ( $this->files( [ 'src/blocks' ], 'js' ) as $file ) {
			$content = (string) file_get_contents( $file );
			$content = (string) preg_replace( '#/\*.*?\*/#s', '', $content );

			$violations = array_merge( $violations, $this->scan( $file, $content ) );
		}

		$this->assertSame( [], $violations, $this->report( $violations ) );
	}

	/**
	 * Non-admin PHP (render callbacks, patterns) must not inline raw colors.
	 */
	public function test_php_has_no_raw_colors() {
		$violations = [];

		foreach ( $this->files( [ 'includes', 'patterns' ], 'php' ) as $file ) {
			if ( str_contains( $this->relative( $file ), 'includes/admin/' ) ) {
				continue;
			}

			$content = (string) file_get_contents( $file );

			$violations = array_merge( $violations, $this->scan( $file, $content ) );
		}

		$this->assertSame( [], $violations, $this->report( $violations ) );
	}

	/**
	 * Collect files with the given extension under the given plugin-root dirs.
	 *
	 * @param string[] $dirs      Directories relative to the plugin root.
	 * @param string   $extension File extension without the dot.
	 * @return string[] Absolute file paths.
	 */
	private function files( array $dirs, string $extension ): array {
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
				if ( strtolower( $file->getExtension() ) === $extension ) {
					$found[] = $file->getPathname();
				}
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Scan file content for color literals, honoring the allowlist.
	 *
	 * @param string $file    Absolute file path.
	 * @param string $content File content (comments may be pre-stripped).
	 * @return string[] Violation lines as "file:line: literal".
	 */
	private function scan( string $file, string $content ): array {
		$relative   = $this->relative( $file );
		$allowed    = self::ALLOWLIST[ $relative ] ?? [];
		$violations = [];

		foreach ( explode( "\n", $content ) as $index => $line ) {
			$matches = [];
			preg_match_all( self::HEX_PATTERN, $line, $hex );
			preg_match_all( self::FUNC_PATTERN, $line, $func );
			$matches = array_merge( $hex[0], $func[0] );

			foreach ( $matches as $literal ) {
				if ( isset( $allowed[ rtrim( $literal, '(' ) ] ) || isset( $allowed[ $literal ] ) ) {
					continue;
				}

				$violations[] = sprintf( '%s:%d: %s', $relative, $index + 1, $literal );
			}
		}

		return $violations;
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

	/**
	 * Human-readable failure message.
	 *
	 * @param string[] $violations Violation lines.
	 */
	private function report( array $violations ): string {
		return sprintf(
			"%d raw color literal(s) found. Replace each with a var(--pkiw-*) token lookup\nper docs/audit/DESIGN-TOKENS.md, or add a justified allowlist entry:\n%s",
			count( $violations ),
			implode( "\n", $violations )
		);
	}
}

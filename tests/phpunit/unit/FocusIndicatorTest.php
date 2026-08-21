<?php
/**
 * Focus-indicator contract enforcement (WCAG 2.2 AA, SC 2.4.7).
 *
 * A custom property with no fallback resolves to the guaranteed-invalid
 * value when nothing defines it, and an invalid value inside a shorthand
 * discards the whole declaration. For `outline` that means
 * `outline-style: none` — the focus ring silently disappears, and only a
 * keyboard pass on a theme that never defines the token reveals it.
 *
 * `.pk-card :focus-visible` shipped that way: it referenced
 * `var(--pk-accent)`, which no stylesheet in the plugin ever defines, so
 * card links had no visible focus indicator on any theme that did not
 * happen to supply the token. Found in the 1.5.0 pre-release keyboard
 * gate; this test keeps it from coming back anywhere.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;

/**
 * Verifies focus indicators never depend on an undefined custom property.
 */
class FocusIndicatorTest extends WP_UnitTestCase {

	/**
	 * Directories whose CSS must satisfy the contract.
	 */
	private const SOURCE_DIRS = [ 'src', 'build' ];

	/**
	 * Declarations that make a focus indicator visible. A bare
	 * `var(--token)` in any of these is a silent-failure risk.
	 */
	private const INDICATOR_PROPERTIES = [ 'outline', 'outline-color', 'box-shadow', 'border-color' ];

	/**
	 * Collect every CSS file under the source directories.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function css_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = [];

		foreach ( self::SOURCE_DIRS as $dir ) {
			$path = $root . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'css' === strtolower( $file->getExtension() ) ) {
					$files[] = $file->getPathname();
				}
			}
		}

		return $files;
	}

	/**
	 * Focus rules must not rely on a fallback-less custom property.
	 */
	public function test_focus_indicators_declare_a_fallback(): void {
		$files = $this->css_files();

		// Guard the guard: no files means a vacuous pass.
		$this->assertNotEmpty( $files, 'No CSS files found to scan.' );

		$properties = implode( '|', array_map( 'preg_quote', self::INDICATOR_PROPERTIES ) );
		$offenders  = [];

		foreach ( $files as $file ) {
			$css = (string) file_get_contents( $file );

			// Every rule block whose selector mentions a focus state.
			if ( ! preg_match_all( '/([^{}]*:focus[^{}]*)\{([^}]*)\}/i', $css, $rules, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				[ , $selector, $body ] = $rule;

				if ( ! preg_match_all( '/(?:^|;)\s*(' . $properties . ')\s*:([^;]*)/i', $body, $decls, PREG_SET_ORDER ) ) {
					continue;
				}

				foreach ( $decls as $decl ) {
					// A var() reference with no comma has no fallback.
					if ( preg_match( '/var\(\s*--[\w-]+\s*\)/', $decl[2] ) ) {
						$offenders[] = sprintf(
							'%s: %s { %s:%s }',
							str_replace( dirname( __DIR__, 3 ) . '/', '', $file ),
							trim( $selector ),
							trim( $decl[1] ),
							rtrim( $decl[2] )
						);
					}
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"Focus indicators referencing a custom property without a fallback.\n"
				. "An undefined token invalidates the whole declaration, so the indicator vanishes.\n"
				. "Add a fallback, e.g. var(--pk-accent, currentColor):\n  "
				. implode( "\n  ", $offenders )
		);
	}
}

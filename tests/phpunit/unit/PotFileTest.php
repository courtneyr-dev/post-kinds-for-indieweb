<?php
/**
 * Translation template contract.
 *
 * `languages/<text-domain>.pot` is the file translators and translate.wordpress.org
 * start from. Issue 353 found it stale and found the `i18n:make-pot` composer script
 * writing a second, wrongly named file with the wrong domain, so regenerating it
 * never fixed anything.
 *
 * This test pins the contract a regeneration must keep: the domain and file name
 * match the plugin header, the version matches the release, strings from current
 * code are present, and strings the plugin removed are gone. A release bump that
 * forgets `composer i18n:make-pot` fails the version assertion here.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;

/**
 * Verifies the committed POT matches the plugin header and current code.
 */
class PotFileTest extends WP_UnitTestCase {

	/**
	 * Plugin main file, relative to the repository root.
	 */
	private const MAIN_FILE = 'post-kinds-for-indieweb-in-block-themes.php';

	/**
	 * Plugin header values.
	 *
	 * @var array<string,string>
	 */
	private array $header = array();

	/**
	 * Committed POT contents.
	 *
	 * @var string
	 */
	private string $pot = '';

	/**
	 * Repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Load the plugin header and the committed POT.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->header = get_file_data(
			$this->root() . '/' . self::MAIN_FILE,
			array(
				'version' => 'Version',
				'domain'  => 'Text Domain',
			)
		);

		$path = $this->root() . '/languages/' . $this->header['domain'] . '.pot';
		$this->assertFileExists( $path, 'The POT must be named after the Text Domain in the plugin header.' );

		$this->pot = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture read.
	}

	/**
	 * The header must declare a domain, or every assertion below checks nothing.
	 */
	public function test_plugin_header_declares_a_text_domain(): void {
		$this->assertSame( 'post-kinds-for-indieweb-in-block-themes', $this->header['domain'] );
	}

	/**
	 * Only one POT may live in languages/, so a wrongly named copy cannot shadow the real one.
	 */
	public function test_languages_directory_holds_a_single_pot(): void {
		$found = array_map( 'basename', (array) glob( $this->root() . '/languages/*.pot' ) );

		$this->assertSame( array( $this->header['domain'] . '.pot' ), $found );
	}

	/**
	 * The X-Domain header must match the plugin's real text domain.
	 */
	public function test_pot_header_domain_matches_plugin_header(): void {
		$this->assertStringContainsString( '"X-Domain: ' . $this->header['domain'] . '\n"', $this->pot );
	}

	/**
	 * The POT must be regenerated for each release.
	 */
	public function test_pot_project_version_matches_plugin_version(): void {
		$this->assertMatchesRegularExpression(
			'/"Project-Id-Version: [^"\\\\]+ ' . preg_quote( $this->header['version'], '/' ) . '\\\\n"/',
			$this->pot,
			'Run `composer i18n:make-pot` after bumping the plugin version.'
		);
	}

	/**
	 * Translators report issues to the support forum of the plugin's wp.org slug.
	 */
	public function test_pot_bug_report_url_uses_the_plugin_slug(): void {
		$this->assertStringContainsString(
			'"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/' . $this->header['domain'] . '\n"',
			$this->pot
		);
	}

	/**
	 * Strings added to includes/ recently must be in the template.
	 *
	 * @return array<string,array{string}>
	 */
	public function recent_strings(): array {
		return array(
			'owntracks author error' => array( 'No user can author imported posts. Set pkiw_default_author to a user who can create posts.' ),
			'comics drawn archive'   => array( 'Comics Drawn Archive' ),
			'collection items tool'  => array( 'Return the visible ordered items in a collection.' ),
		);
	}

	/**
	 * A string that ships in current code is in the POT.
	 *
	 * @dataProvider recent_strings
	 *
	 * @param string $msgid Source string.
	 */
	public function test_pot_contains_strings_from_current_code( string $msgid ): void {
		$this->assertStringContainsString( "\nmsgid \"" . $msgid . "\"\n", $this->pot );
	}

	/**
	 * Strings for settings the plugin removed are gone.
	 *
	 * @return array<string,array{string}>
	 */
	public function removed_strings(): array {
		return array(
			'coordinate handling'        => array( 'Coordinate Handling' ),
			'store but hide coordinates' => array( 'Store but hide coordinates' ),
		);
	}

	/**
	 * A string the plugin removed is not offered to translators.
	 *
	 * @dataProvider removed_strings
	 *
	 * @param string $msgid Removed source string.
	 */
	public function test_pot_omits_strings_removed_from_code( string $msgid ): void {
		$this->assertStringNotContainsString( "\nmsgid \"" . $msgid . "\"\n", $this->pot );
	}

	/**
	 * The composer script must write the committed file with the real domain.
	 */
	public function test_composer_script_targets_the_real_domain_and_file(): void {
		$composer = json_decode( (string) file_get_contents( $this->root() . '/composer.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture read.
		$script   = (string) ( $composer['scripts']['i18n:make-pot'] ?? '' );
		$domain   = $this->header['domain'];

		$this->assertStringContainsString( 'languages/' . $domain . '.pot', $script );
		$this->assertStringContainsString( '--domain=' . $domain . ' ', $script . ' ' );
		$this->assertStringContainsString( '--slug=' . $domain . ' ', $script . ' ' );
	}
}

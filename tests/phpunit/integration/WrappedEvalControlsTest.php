<?php
/** Wrapped privacy eval controls. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Plugin;
use PKIW\Wrapped_Post;

/** @group integration */
final class WrappedEvalControlsTest extends WrappedTestCase {
	private const IDS = [ 'SEAMS', 'TOP', 'REGISTRY', 'ORACLE', 'ABILITIES', 'BINDING', 'REWRITE', 'TERMS' ];
	private string $permalinks = '';

	public function set_up(): void {
		parent::set_up();
		$this->permalinks = (string) get_option( 'permalink_structure' );
		$this->set_permalink_structure( '/%postname%/' );
		if ( null === wp_get_ability( 'post-kinds/kind-year-stats' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
		Wrapped_Post::register_route();
		update_option( 'pkiw_rewrite_version', 'x267-eval-stale' );
		delete_option( 'rewrite_rules' );
		Plugin::get_instance()->maybe_flush_rewrite_rules();
	}

	public function tear_down(): void {
		$this->set_permalink_structure( $this->permalinks );
		parent::tear_down();
	}

	public function test_unpatched_eval_passes_with_and_without_wrapped_data(): void {
		$this->assertSame( 'RESULT PASS', $this->evaluate( $this->fixture() ) );
		$this->read_post();
		$this->wrapped_post();
		$this->assertSame( 'RESULT PASS', $this->evaluate( $this->fixture() ) );
	}

	public function test_every_marked_assertion_has_a_failing_control(): void {
		$fixture = $this->fixture();
		foreach ( self::IDS as $id ) {
			$start = '/*X267:' . $id . '*/';
			$end   = '/*X267:/' . $id . '*/';
			$this->assertSame( 1, substr_count( $fixture, $start ), $id . ' start' );
			$this->assertSame( 1, substr_count( $fixture, $end ), $id . ' end' );
			$patched = $this->replaceSpan( $fixture, $start, $end, $start . ' $checks[' . var_export( strtolower( $id ), true ) . '] = false; ' . $end );
			$this->assertStringStartsWith( 'RESULT FAIL', $this->evaluate( $patched ), $id );
		}
	}

	public function test_eval_calls_only_uncached_compute_and_writes_no_wrapped_cache_state(): void {
		global $wpdb;
		delete_option( 'pkiw_wrapped_gen' );
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_pkiw_wrapped_%'" );
		$this->assertSame( 'RESULT PASS', $this->evaluate( $this->fixture() ) );
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_pkiw_wrapped_%'" );
		$this->assertSame( $before, $after );
		$this->assertFalse( get_option( 'pkiw_wrapped_gen' ) );
		$source = $this->fixture();
		$this->assertStringContainsString( 'Wrapped_Stats::compute', $source );
		$this->assertStringNotContainsString( 'for_kind_year', $source );
		$this->assertStringNotContainsString( 'Wrapped_Post::latest', $source );
	}

	public function test_fixture_has_no_php_opener_and_prints_one_line_only(): void {
		$fixture = $this->fixture();
		$this->assertStringNotContainsString( '<?php', $fixture );
		$output = $this->evaluate( $fixture );
		$this->assertSame( 1, count( preg_split( '/\R/', $output ) ) );
	}

	private function fixture(): string {
		$source = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/x-267-wrapped.php' );
		$this->assertIsString( $source );
		return $source;
	}

	private function evaluate( string $source ): string {
		ob_start();
		try {
			eval( $source );
		} finally {
			$output = (string) ob_get_clean();
		}
		return trim( $output );
	}

	private function replaceSpan( string $source, string $start, string $end, string $replacement ): string {
		$from = strpos( $source, $start );
		$to   = strpos( $source, $end );
		$this->assertNotFalse( $from );
		$this->assertNotFalse( $to );
		$length = $to + strlen( $end ) - $from;
		return substr_replace( $source, $replacement, $from, $length );
	}
}

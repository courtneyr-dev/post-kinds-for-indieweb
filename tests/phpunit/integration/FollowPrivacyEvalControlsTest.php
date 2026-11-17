<?php
/**
 * Follow privacy eval negative controls.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Public_Eligibility;
use PKIW\Taxonomy;

/** @group integration */
final class FollowPrivacyEvalControlsTest extends WP_UnitTestCase {
	private const FACTS_SPAN = "/*W8-260:F*/ [ 'PKIW\\Follow_Archive', 'facts_for' ] /*W8-260:/F*/";
	private const QUERY_SPAN = '/*W8-260:Q*/ \PKIW\Follow_Archive::index_query_args() /*W8-260:/Q*/';
	private const REST_SPAN  = "/*W8-260:R*/ [ 'PKIW\\Follow_Archive', 'rest_meta_for' ] /*W8-260:/R*/";

	private string $permalink_structure = '';

	public function set_up(): void {
		parent::set_up();
		$this->permalink_structure = (string) get_option( 'permalink_structure' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules( false );
		if ( null === wp_get_ability( 'post-kinds/get-follow-index' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
		Public_Eligibility::override_cli_for_tests( true );
	}

	public function tear_down(): void {
		Public_Eligibility::override_cli_for_tests( null );
		$this->set_permalink_structure( $this->permalink_structure );
		parent::tear_down();
	}

	public function test_unpatched_eval_passes(): void {
		$this->assertSame( 'RESULT PASS', $this->last_line( $this->fixture() ), 'The positive control proves the complete eval can pass.' );
	}

	public function test_unpatched_eval_restores_the_current_user(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		$this->assertSame( 'RESULT PASS', $this->last_line( $this->fixture() ) );
		$this->assertSame( $user_id, get_current_user_id(), 'The eval must restore the caller it replaced.' );
	}

	public function test_facts_gate_negative_control_fails_with_exact_leak_list(): void {
		$replacement = '/*W8-260:F*/ static fn( \WP_Post $p, string $k ): array => \PKIW\Follow_Archive::facts_body( $p ) /*W8-260:/F*/';
		$this->assertSame( 'RESULT FAIL facts_leak:private,draft,pending,future,password,trash', $this->last_line( $this->patched( self::FACTS_SPAN, $replacement ) ) );
	}

	public function test_index_gate_negative_control_fails(): void {
		$replacement = "/*W8-260:Q*/ array_diff_key( \\PKIW\\Follow_Archive::index_query_args(), [ 'pkiw_public_only' => 1 ] ) /*W8-260:/Q*/";
		$this->assertSame( 'RESULT FAIL index_query_unfiltered', $this->last_line( $this->patched( self::QUERY_SPAN, $replacement ) ) );
	}

	public function test_rest_gate_negative_control_fails_with_exact_leak_list(): void {
		$replacement = '/*W8-260:R*/ static fn( $r ) => $r /*W8-260:/R*/';
		$this->assertSame( 'RESULT FAIL rest_leak:private,draft,pending,future,password,trash', $this->last_line( $this->patched( self::REST_SPAN, $replacement ) ) );
	}

	private function fixture(): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/w8-260-follows.php' );
		$this->assertIsString( $text );
		return $text;
	}

	private function patched( string $span, string $replacement ): string {
		$text = $this->fixture();
		$this->assertSame( 1, substr_count( $text, $span ), 'Each frozen marker span must occur exactly once.' );
		return str_replace( $span, $replacement, $text );
	}

	private function last_line( string $code ): string {
		ob_start();
		try {
			eval( $code );
		} finally {
			$output = (string) ob_get_clean();
		}
		$lines = preg_split( '/\R/', trim( $output ) );
		return (string) end( $lines );
	}
}

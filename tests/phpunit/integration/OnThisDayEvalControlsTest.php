<?php
/** On This Day privacy eval negative controls. @package PKIW */
declare(strict_types=1);

use PKIW\Abilities_Manager;
use PKIW\On_This_Day;
use PKIW\Public_Eligibility;
use PKIW\Taxonomy;

/** @group integration */
final class OnThisDayEvalControlsTest extends WP_UnitTestCase {
	private const FILTER_SPAN = "/*X268:F*/ [ 'PKIW\\On_This_Day', 'row_visible' ] /*X268:/F*/";
	private const QUERY_SPAN = '/*X268:Q*/ \PKIW\On_This_Day::query_args( $day ) /*X268:/Q*/';
	private $theme_rucss;
	private string $permalinks = '';

	public function set_up(): void {
		parent::set_up();
		$this->permalinks = (string) get_option( 'permalink_structure' );
		$this->set_permalink_structure( '/%postname%/' );
		( new Taxonomy() )->create_default_terms();
		if ( ! term_exists( 'follow', Taxonomy::TAXONOMY ) ) {
			wp_insert_term( 'Follow', Taxonomy::TAXONOMY, [ 'slug' => 'follow' ] );
		}
		On_This_Day::override_now( new DateTimeImmutable( '2026-11-17 12:00:00', wp_timezone() ) );
		Public_Eligibility::override_cli_for_tests( true );
		$this->theme_rucss = static function ( array $styles ): array { $styles[] = 'cr-on-this-day.css'; return $styles; };
		add_filter( 'perfmatters_rucss_excluded_stylesheets', $this->theme_rucss );
		if ( null === wp_get_ability( 'post-kinds/get-on-this-day' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
		On_This_Day::register_route();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		remove_filter( 'perfmatters_rucss_excluded_stylesheets', $this->theme_rucss );
		Public_Eligibility::override_cli_for_tests( null );
		On_This_Day::override_now( null );
		On_This_Day::override_headers_for_tests( null );
		On_This_Day::reset();
		$this->set_permalink_structure( $this->permalinks );
		parent::tear_down();
	}

	public function test_unpatched_eval_passes(): void {
		$this->assertSame( 'RESULT PASS', $this->last_line( $this->fixture() ) );
	}

	public function test_row_gate_negative_control_fails_with_exact_leak_list(): void {
		$replacement = '/*X268:F*/ static fn(): bool => true /*X268:/F*/';
		$this->assertSame( 'RESULT FAIL row_leak:private,draft,pending,future,password,trash,quiet_public', $this->last_line( $this->patched( self::FILTER_SPAN, $replacement ) ) );
	}

	public function test_query_gate_negative_control_fails(): void {
		$replacement = "/*X268:Q*/ array_diff_key( \\PKIW\\On_This_Day::query_args( \$day ), [ 'pkiw_public_only' => 1, 'pkiw_public_listing' => 1 ] ) /*X268:/Q*/";
		$this->assertSame( 'RESULT FAIL query_unfiltered', $this->last_line( $this->patched( self::QUERY_SPAN, $replacement ) ) );
	}

	public function test_fixture_markers_are_unique_and_edge_info_reports_branch_b(): void {
		$text = $this->fixture();
		$this->assertSame( 1, substr_count( $text, self::FILTER_SPAN ) );
		$this->assertSame( 1, substr_count( $text, self::QUERY_SPAN ) );
		$output = $this->evaluate( $text );
		$this->assertMatchesRegularExpression( '/^INFO edge switch=[01] apc_paths=[01] platform_paths=[01] apc_keys=[01] platform_keys=[01] can_purge=0$/m', $output );
		$this->assertSame( 'RESULT PASS', $this->last_line_from_output( $output ) );
	}

	public function test_rucss_negative_control_fails_for_theme_file_first(): void {
		remove_filter( 'perfmatters_rucss_excluded_stylesheets', $this->theme_rucss );
		remove_filter( 'perfmatters_rucss_excluded_stylesheets', [ On_This_Day::class, 'exclude_rucss' ] );
		try {
			$this->assertSame( 'RESULT FAIL rucss_exclusion:cr-on-this-day.css', $this->last_line( $this->fixture() ) );
		} finally {
			add_filter( 'perfmatters_rucss_excluded_stylesheets', [ On_This_Day::class, 'exclude_rucss' ] );
		}
	}

	private function fixture(): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/x-268-on-this-day.php' );
		$this->assertIsString( $text );
		return $text;
	}

	private function patched( string $span, string $replacement ): string {
		$text = $this->fixture();
		$this->assertSame( 1, substr_count( $text, $span ) );
		return str_replace( $span, $replacement, $text );
	}

	private function last_line( string $code ): string {
		return $this->last_line_from_output( $this->evaluate( $code ) );
	}

	private function evaluate( string $code ): string {
		ob_start();
		try {
			eval( $code );
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}

	private function last_line_from_output( string $output ): string {
		$lines = preg_split( '/\R/', trim( $output ) );
		return (string) end( $lines );
	}
}

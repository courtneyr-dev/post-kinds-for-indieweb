<?php
/**
 * Check-in Dashboard privacy eval controls.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Public_Eligibility;
use PKIW\Taxonomy;

/** @group integration */
final class CheckinDashboardPrivacyEvalControlsTest extends WP_UnitTestCase {
	private const QUERY_SPAN = '/*X-N5:U*/ null /*X-N5:/U*/';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		Public_Eligibility::override_cli_for_tests( true );
		$this->register_source_block();
		$this->create_fixtures();
	}

	public function tear_down(): void {
		Public_Eligibility::override_cli_for_tests( null );
		$this->register_build_block();
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

	public function test_no_governed_terms_control_passes(): void {
		foreach ( [ 'follow', 'tag' ] as $slug ) {
			$term = get_term_by( 'slug', $slug, Taxonomy::TAXONOMY );
			if ( $term instanceof WP_Term ) {
				$this->assertNotWPError( wp_delete_term( $term->term_id, Taxonomy::TAXONOMY ) );
			}
		}

		$output = $this->evaluate( $this->fixture() );

		$this->assertStringContainsString( 'INFO no-governed-terms', $output );
		$this->assertSame( 'RESULT PASS', $this->last_line_from_output( $output ) );
	}

	public function test_clause_negative_control_fails(): void {
		$replacement = "/*X-N5:U*/ static function ( \\WP_Query \$query ): void { if ( 'post' === \$query->get( 'post_type' ) ) { \$query->set( 'pkiw_public_only', false ); } } /*X-N5:/U*/";
		$this->assertSame( 'RESULT FAIL clause_missing', $this->last_line( $this->patched( self::QUERY_SPAN, $replacement ) ) );
	}

	public function test_write_negative_control_blocks_the_database_write(): void {
		global $wpdb;

		$option = 'pkiw_n5_k4_write_control';
		add_option( $option, 'before' );
		$filter = static function ( string $html, array $block ) use ( $option ): string {
			if ( 'post-kinds-indieweb/checkin-dashboard' === ( $block['blockName'] ?? '' ) ) {
				update_option( $option, 'after' );
			}
			return $html;
		};
		add_filter( 'render_block', $filter, 10, 2 );

		try {
			$line = $this->last_line( $this->fixture() );
		} finally {
			remove_filter( 'render_block', $filter, 10 );
		}

		$this->assertMatchesRegularExpression( '/^RESULT FAIL write_attempted:[1-9][0-9]*$/', $line );
		$this->assertSame( 'before', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ) );
	}

	private function create_fixtures(): void {
		for ( $index = 1; $index <= 3; ++$index ) {
			$this->create_checkin( "N5K4 Public Venue {$index}" );
		}
		$this->create_checkin( 'Sentinel Hidden Venue Qx7', 'n5k4-a' );
		$this->create_checkin( 'Sentinel Hidden Venue Qx7', 'n5k4-b', true );
	}

	private function create_checkin( string $venue, string $password = '', bool $follow = false ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $venue,
				'post_password' => $password,
			]
		);
		$terms = $follow ? [ 'checkin', 'follow' ] : [ 'checkin' ];
		$this->assertNotWPError( wp_set_object_terms( $post_id, $terms, Taxonomy::TAXONOMY ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', $venue );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_address', '9 Sentinel Way Qx7' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_latitude', '12.987654' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_longitude', '-45.123456' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'public' );
		return $post_id;
	}

	private function register_source_block(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'post-kinds-indieweb/checkin-dashboard' ) ) {
			unregister_block_type( 'post-kinds-indieweb/checkin-dashboard' );
		}
		wp_deregister_script( 'post-kinds-indieweb-checkin-dashboard-view-script' );
		register_block_type( PKIW_PATH . 'src/blocks/checkin-dashboard' );
	}

	private function register_build_block(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'post-kinds-indieweb/checkin-dashboard' ) ) {
			unregister_block_type( 'post-kinds-indieweb/checkin-dashboard' );
		}
		wp_script_modules()->deregister( 'post-kinds-indieweb-checkin-dashboard-view-script-module' );
		register_block_type( PKIW_PATH . 'build/blocks/checkin-dashboard' );
	}

	private function fixture(): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/x-n5-k4-checkin-dashboard.php' );
		$this->assertIsString( $text );
		return $text;
	}

	private function patched( string $span, string $replacement ): string {
		$text = $this->fixture();
		$this->assertSame( 1, substr_count( $text, $span ), 'The frozen marker span must occur exactly once.' );
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

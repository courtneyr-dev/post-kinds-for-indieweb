<?php
/** Wrapped Abilities API coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Abilities\Wrapped_Abilities;
use PKIW\Abilities_Manager;
use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedAbilitiesTest extends WrappedTestCase {
	public function set_up(): void {
		parent::set_up();
		if ( null === wp_get_ability( 'post-kinds/kind-year-stats' ) ) {
			do_action( 'wp_abilities_api_init' );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_registration_schemas_and_public_flags_are_complete(): void {
		$stats = wp_get_ability( 'post-kinds/kind-year-stats' );
		$draft = wp_get_ability( 'post-kinds/create-wrapped-draft' );
		$this->assertNotNull( $stats );
		$this->assertNotNull( $draft );
		$this->assertContains( 'post-kinds/kind-year-stats', Abilities_Manager::MCP_PUBLIC_ABILITIES );
		$this->assertNotContains( 'post-kinds/create-wrapped-draft', Abilities_Manager::MCP_PUBLIC_ABILITIES );
		$this->assertTrue( $stats->get_meta()['mcp']['public'] ?? false );
		$this->assertFalse( $draft->get_meta()['mcp']['public'] ?? true );
		$this->assertFalse( $stats->get_input_schema()['additionalProperties'] );
		$this->assertFalse( $stats->get_output_schema()['additionalProperties'] );
		$this->assertSame( [ 'key', 'label', 'value', 'display', 'basis' ], $stats->get_output_schema()['properties']['metrics']['items']['required'] );
		$this->assertSame( [ 'post_id', 'rating', 'finished', 'title', 'permalink' ], $stats->get_output_schema()['properties']['top']['items']['required'] );
		$this->assertArrayHasKey( 'would_create', $draft->get_output_schema()['properties'] );
		$this->assertArrayHasKey( 'created', $draft->get_output_schema()['properties'] );
	}

	public function test_stats_output_validates_and_equals_shared_function_with_expanded_top_rows(): void {
		for ( $index = 0; $index < Wrapped_Stats::TOP_MAX; ++$index ) {
			$this->read_post( 2026, [ 'read_rating' => 5 ], [ 'post_title' => 'Ability top ' . $index ] );
		}
		$expected = Wrapped_Stats::for_kind_year( 'read', 2026 );
		unset( $expected['schema'], $expected['gen'] );
		foreach ( $expected['top'] as &$row ) {
			$row['title']     = get_the_title( $row['post_id'] );
			$row['permalink'] = get_permalink( $row['post_id'] );
		}
		unset( $row );
		$output = Wrapped_Abilities::instance()->stats( [ 'kind' => 'read', 'year' => 2026 ] );
		$this->assertSame( $expected, $output );
		$this->assertCount( Wrapped_Stats::TOP_MAX, $output['top'] );
		$validation = rest_validate_value_from_schema( $output, wp_get_ability( 'post-kinds/kind-year-stats' )->get_output_schema() );
		$this->assertTrue( $validation, 'Stats output must satisfy its registered schema; validation=' . print_r( $validation, true ) . '; output=' . print_r( $output, true ) );
	}

	public function test_stats_execution_rejects_year_outside_2000_through_current(): void {
		foreach ( [ 0, 1999, (int) wp_date( 'Y' ) + 1 ] as $year ) {
			$result = Wrapped_Abilities::instance()->stats( [ 'kind' => 'read', 'year' => $year ] );
			$this->assertWPError( $result, (string) $year );
			$this->assertSame( 'invalid_wrapped_scope', $result->get_error_code() );
		}
	}

	public function test_dry_run_writes_nothing_and_real_call_creates_exact_draft_content(): void {
		$before = (int) wp_count_posts( 'post' )->draft;
		$dry    = Wrapped_Abilities::instance()->create_draft( [ 'kind' => 'read', 'year' => 2026 ] );
		$this->assertSame( $before, (int) wp_count_posts( 'post' )->draft );
		$this->assertTrue( $dry['dry_run'] );
		$this->assertTrue( rest_validate_value_from_schema( $dry, wp_get_ability( 'post-kinds/create-wrapped-draft' )->get_output_schema() ) );
		$real = Wrapped_Abilities::instance()->create_draft( [ 'kind' => 'read', 'year' => 2026, 'dry_run' => false ] );
		$this->assertFalse( $real['dry_run'] );
		$post = get_post( $real['created']['post_id'] );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Read 2026 Wrapped', $post->post_title );
		$this->assertSame( '<!-- wp:post-kinds-indieweb/wrapped {"kind":"read","year":2026} /-->' . "\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->", $post->post_content );
		$this->assertTrue( rest_validate_value_from_schema( $real, wp_get_ability( 'post-kinds/create-wrapped-draft' )->get_output_schema() ) );
	}

	public function test_publish_status_input_is_rejected_by_schema(): void {
		$schema = wp_get_ability( 'post-kinds/create-wrapped-draft' )->get_input_schema();
		$result = rest_validate_value_from_schema( [ 'kind' => 'read', 'year' => 2026, 'status' => 'publish' ], $schema );
		$this->assertWPError( $result );
	}
}

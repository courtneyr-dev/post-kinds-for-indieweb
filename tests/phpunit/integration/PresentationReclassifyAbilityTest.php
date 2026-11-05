<?php
/** Presentation reclassification ability. */

declare(strict_types=1);

use PKIW\Abilities\Presentation_Abilities;
use PKIW\CLI_Commands;
use PKIW\Plugin;
use PKIW\Presentation_Classifier;
use PKIW\Taxonomy;

require_once dirname( __DIR__ ) . '/fixtures/wp-cli-stub.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-cli-commands.php';

final class PresentationReclassifyAbilityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'presentation', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( $slug, Taxonomy::TAXONOMY );
			}
		}
		WP_CLI::reset();
	}

	public function test_dry_run_defaults_true_and_category_and_ids_scope_the_report(): void {
		$category = self::factory()->category->create( [ 'slug' => 'speaking' ] );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '[slideshare id=12345]', 'post_category' => [ $category ] ] );
		wp_set_object_terms( $post_id, 'note', 'kind' );
		delete_post_meta( $post_id, PKIW\Taxonomy::AUTO_KIND_META_KEY );
		$provider = Presentation_Abilities::instance();
		$report = $provider->execute_reclassify_presentations( [ 'category' => 'speaking', 'ids' => [ $post_id ] ] );
		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 1, $report['would_change'] );
		$this->assertSame( [ 'note' ], wp_get_object_terms( $post_id, 'kind', [ 'fields' => 'slugs' ] ) );
		$this->assertSame( 0, Presentation_Classifier::backfill( Plugin::get_instance()->get_taxonomy(), true, [ 'category' => 'missing' ] )['scanned'] );
		foreach ( [ [], [ 'dry_run' => true ], [ 'dry_run' => 'true' ] ] as $input ) {
			$this->assertTrue( $provider->execute_reclassify_presentations( $input )['dry_run'] );
		}
	}

	public function test_only_explicit_false_values_write(): void {
		foreach ( [ false, 0, '0', 'false' ] as $index => $value ) {
			$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '[slideshare id=' . ( 12346 + $index ) . ']' ] );
			wp_set_object_terms( $post_id, 'note', 'kind' );
			delete_post_meta( $post_id, PKIW\Taxonomy::AUTO_KIND_META_KEY );
			$report = Presentation_Abilities::instance()->execute_reclassify_presentations( [ 'dry_run' => $value, 'ids' => [ $post_id ] ] );
			$this->assertFalse( $report['dry_run'] );
			$this->assertSame( 1, $report['changed'] );
		}
	}

	public function test_permission_exposure_and_output_schema_match_the_contract(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable.' );
		}
		$ability = wp_get_ability( 'post-kinds/reclassify-presentations' );
		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_meta()['mcp']['public'] ?? true );
		$this->assertSame( [ 'dry_run', 'scanned', 'would_change', 'changed', 'protected', 'unchanged', 'failed', 'rows' ], $ability->get_output_schema()['required'] ?? [] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( $ability->check_permissions( [ 'dry_run' => true ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $ability->check_permissions( [ 'dry_run' => true ] ) );
	}

	public function test_cli_and_ability_report_the_same_rows_for_one_scope(): void {
		$category = self::factory()->category->create( [ 'slug' => 'speaking-cli' ] );
		$post_id  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '[slideshare id=22222]', 'post_category' => [ $category ] ] );
		wp_set_object_terms( $post_id, 'note', 'kind' );
		delete_post_meta( $post_id, PKIW\Taxonomy::AUTO_KIND_META_KEY );
		$input  = [ 'dry_run' => true, 'category' => 'speaking-cli', 'ids' => [ $post_id ] ];
		$report = Presentation_Abilities::instance()->execute_reclassify_presentations( $input );

		WP_CLI::reset();
		( new CLI_Commands() )->presentation( [ 'backfill' ], [ 'dry-run' => true, 'category' => 'speaking-cli', 'ids' => (string) $post_id ] );
		$logs = array_values( array_map( static fn( array $call ) => $call[1], array_filter( WP_CLI::$calls, static fn( array $call ): bool => 'log' === $call[0] ) ) );
		$this->assertSame( array_map( [ Presentation_Classifier::class, 'backfill_line' ], $report['rows'] ), $logs );
		$this->assertSame( 1, $report['scanned'] );
	}

	public function test_cli_rejects_every_non_positive_ids_token_without_writing(): void {
		foreach ( [ 'abc', '', '0', '-7', '12,abc' ] as $ids ) {
			$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '[slideshare id=33333]' ] );
			wp_set_object_terms( $post_id, 'note', Taxonomy::TAXONOMY );
			delete_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY );
			WP_CLI::reset();
			try {
				( new CLI_Commands() )->presentation( [ 'backfill' ], [ 'ids' => $ids ] );
				$this->fail( 'Invalid --ids must halt the command.' );
			} catch ( WP_CLI_Stub_Halt $error ) {
				$this->assertSame( '--ids must be a comma-separated list of positive integers.', $error->getMessage() );
			}
			$this->assertSame( [ 'note' ], wp_get_object_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ), $ids );
		}
	}

	public function test_cli_without_new_flags_keeps_the_base_commit_transcript(): void {
		$url     = 'https://wordpress.tv/2026/01/02/example-talk/';
		$content = '<!-- wp:embed {"url":"' . $url . '","providerNameSlug":"wordpress-tv-embed"} --><figure></figure><!-- /wp:embed -->';
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Base transcript talk', 'post_content' => $content ] );
		wp_set_object_terms( $post_id, 'note', Taxonomy::TAXONOMY );
		delete_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY );

		WP_CLI::reset();
		( new CLI_Commands() )->presentation( [ 'backfill' ], [ 'dry-run' => true ] );
		$this->assertSame(
			[
				[ 'log', sprintf( '#%d "Base transcript talk": would change to presentation; kind note, auto marker none; talk_recording: %s', $post_id, $url ) ],
				[ 'success', 'Dry run: 1 post(s) scanned, 1 would change, 0 protected, 0 already presentation.' ],
			],
			WP_CLI::$calls
		);
	}
}

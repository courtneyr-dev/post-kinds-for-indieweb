<?php
/**
 * Issue fields exposed by the core abilities provider.
 *
 * @package PKIW
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/fixtures/issue-render-state-reset.php';

use PKIW\Abilities\Core_Abilities;
use PKIW\Abilities_Manager;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class IssueKindFieldsAbilityTest extends WP_UnitTestCase {

	use Issue_Render_State_Reset;

	private Core_Abilities $abilities;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		Core_Abilities::reset();
		$this->abilities = Core_Abilities::instance();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->reset_render_state();
		wp_set_current_user( 0 );
		Core_Abilities::reset();
		parent::tear_down();
	}

	public function test_issue_lists_only_citation_and_issue_fields(): void {
		$result = $this->abilities->execute_list_kind_fields( [ 'kind' => 'issue' ] );
		$this->assertIsArray( $result );
		$keys = wp_list_pluck( $result['fields'], 'key' );

		foreach ( [ 'cite_name', 'cite_url', 'issue_number', 'issue_status' ] as $key ) {
			$this->assertContains( $key, $keys );
		}
		foreach ( $keys as $key ) {
			$this->assertTrue( str_starts_with( $key, 'cite_' ) || str_starts_with( $key, 'issue_' ), $key );
		}
	}

	public function test_issue_field_map_has_the_frozen_two_prefixes(): void {
		$map = ( new ReflectionClass( Core_Abilities::class ) )->getReflectionConstant( 'KIND_FIELD_MAP' )->getValue();

		$this->assertSame( [ 'cite_', 'issue_' ], $map['issue'] );
	}

	public function test_get_post_meta_returns_the_four_requested_issue_values(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'issue', Taxonomy::TAXONOMY );
		$values = [
			'cite_url'     => 'https://a.example.net/acorn-ledger',
			'cite_name'    => 'acorn-ledger',
			'issue_number' => '101',
			'issue_status' => 'open',
		];
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $post_id, 'meta_keys' => array_keys( $values ) ] );
		$this->assertIsArray( $result );
		$this->assertSame( $values, $result['meta'] );
	}

	public function test_logged_out_caller_gets_no_meta_for_a_protected_issue(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'ticket-secret' ] );
		wp_set_object_terms( $post_id, 'issue', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_number', '110' );

		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $post_id, 'meta_keys' => [ 'issue_number' ] ] );
		$this->assertIsArray( $result );
		$this->assertSame( [], $result['meta'] );
	}

	public function test_issue_adds_no_public_mcp_ability(): void {
		// Other lanes add entries anywhere in the list; the ten listed here are the ones this lane found and it adds none.
		$original = [
			'post-kinds/list-kinds',
			'post-kinds/list-kind-fields',
			'post-kinds/get-kind',
			'post-kinds/get-post-meta',
			'post-kinds/lookup-music',
			'post-kinds/lookup-video',
			'post-kinds/lookup-book',
			'post-kinds/lookup-podcast',
			'post-kinds/lookup-venue',
			'post-kinds/lookup-game',
		];
		$this->assertSame( $original, array_values( array_intersect( Abilities_Manager::MCP_PUBLIC_ABILITIES, $original ) ) );
		foreach ( Abilities_Manager::MCP_PUBLIC_ABILITIES as $name ) {
			$this->assertStringNotContainsString( 'issue', $name, 'The issue lane exposes no ability of its own.' );
		}
	}
}

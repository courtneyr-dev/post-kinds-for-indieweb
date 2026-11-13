<?php
/**
 * Tag ability contract coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Tag_Abilities;
use PKIW\Abilities_Manager;
use PKIW\Tag_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class TagAbilitiesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_get_facts_uses_one_not_found_error_for_every_refusal(): void {
		$ability = Tag_Abilities::instance();
		$ids     = [ 987654, self::factory()->post->create(), self::factory()->post->create( [ 'post_status' => 'private' ] ), self::factory()->post->create( [ 'post_password' => 'secret' ] ) ];
		wp_set_object_terms( $ids[2], 'tag', Taxonomy::TAXONOMY );
		wp_set_object_terms( $ids[3], 'tag', Taxonomy::TAXONOMY );
		foreach ( $ids as $id ) {
			$result = $ability->execute_get_tag_facts( [ 'post_id' => $id ] );
			$this->assertWPError( $result );
			$this->assertSame( 'not_found', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}
	}

	public function test_public_and_private_ability_lists_have_the_frozen_delta(): void {
		$this->assertContains( 'post-kinds/get-tag-facts', Abilities_Manager::MCP_PUBLIC_ABILITIES );
		$this->assertNotContains( 'post-kinds/backfill-tag-labels', Abilities_Manager::MCP_PUBLIC_ABILITIES );
		$this->assertContains( 'post-kinds/backfill-tag-labels', Abilities_Manager::get_ability_names() );
		$this->assertSame( '_pkiw_tag_label', Tag_Archive::LABEL_KEY );
	}
}

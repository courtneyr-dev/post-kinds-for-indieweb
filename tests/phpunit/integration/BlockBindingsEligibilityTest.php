<?php
/**
 * Block binding eligibility coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings;
use PKIW\Block_Bindings_Source;
use PKIW\Taxonomy;

/** @group integration */
final class BlockBindingsEligibilityTest extends WP_UnitTestCase {

	public function test_both_binding_sources_hide_every_governed_value_from_an_admin(): void {
		( new Taxonomy() )->create_default_terms();
		$id = self::factory()->post->create( [ 'post_status' => 'private' ] );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Sentinel name' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://sentinel.example/' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$block = $this->createMock( WP_Block::class );
		$block->context = [ 'postId' => $id ];

		$legacy = new Block_Bindings_Source();
		$current = new Block_Bindings();
		$this->assertSame( '', $legacy->get_value( [ 'key' => 'title' ], $block, 'content' ) );
		$this->assertSame( '', $legacy->get_value( [ 'key' => 'url' ], $block, 'content' ) );
		$this->assertSame( '', $current->get_binding_value( [ 'key' => 'cite_name' ], $block, 'content' ) );
		$this->assertSame( '', $current->get_binding_value( [ 'key' => 'cite_url' ], $block, 'content' ) );
	}
}

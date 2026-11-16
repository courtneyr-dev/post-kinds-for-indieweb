<?php
/** Follow entry block coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowEntryBlockTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_entry_has_one_link_and_hidden_follow_property(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Lin Chen' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://lin.example/' );
		$block = new WP_Block( [ 'blockName' => Follow_Archive::ENTRY_BLOCK, 'attrs' => [ 'headingLevel' => 2 ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'postId' => $id ] );
		$html  = $block->render();
		$this->assertSame( 1, substr_count( $html, '<a ' ), 'A second anchor would break the one-tab-stop card contract.' );
		$this->assertStringContainsString( 'class="u-follow-of" value="https://lin.example/"', $html );
		$this->assertStringContainsString( 'h-entry', $html );
	}

	public function test_repeated_host_names_print_the_host_for_the_accessible_label(): void {
		$ids = [];
		foreach ( [ 'first', 'second' ] as $slug ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
			update_post_meta( $id, '_pkiw_cite_url', 'https://shared.example/' . $slug . '/' );
			$ids[] = $id;
		}
		$previous_query             = $GLOBALS['wp_query'] ?? null;
		$GLOBALS['wp_query']        = new WP_Query();
		$GLOBALS['wp_query']->posts = array_map( 'get_post', $ids );
		try {
			$block = new WP_Block( [ 'blockName' => Follow_Archive::ENTRY_BLOCK, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'postId' => $ids[0] ] );
			$html  = $block->render();
			$this->assertStringContainsString( 'class="pkiw-follow-entry__host"', $html );
			$this->assertStringContainsString( 'aria-labelledby="pkiw-follow-' . $ids[0] . '-name pkiw-follow-' . $ids[0] . '-host"', $html, 'Dropping the repeat branch would leave two cards with the same accessible name.' );
		} finally {
			$GLOBALS['wp_query'] = $previous_query;
		}
	}
}

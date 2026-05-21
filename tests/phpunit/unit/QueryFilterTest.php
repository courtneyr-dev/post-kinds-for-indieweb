<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Query_Filter;

class QueryFilterTest extends WP_UnitTestCase {

	public function test_is_imported_post_with_imported_meta_returns_true() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_imported_from', 'https://example.com/feed' );

		$this->assertTrue( Query_Filter::is_imported_post( $post_id ) );
	}

	public function test_is_imported_post_without_meta_returns_false() {
		$post_id = self::factory()->post->create();

		$this->assertFalse( Query_Filter::is_imported_post( $post_id ) );
	}

	public function test_is_imported_post_with_empty_meta_returns_false() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_imported_from', '' );

		$this->assertFalse( Query_Filter::is_imported_post( $post_id ) );
	}
}

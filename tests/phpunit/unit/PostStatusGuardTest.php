<?php
/**
 * Test the shared post-status resolution guard.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\Admin\Quick_Post;
use WP_UnitTestCase;

/**
 * Verifies a request cannot resolve to a post status beyond the caller's capability.
 */
class PostStatusGuardTest extends WP_UnitTestCase {

	/**
	 * A contributor is capped at pending regardless of what they request.
	 */
	public function test_contributor_cannot_publish() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$this->assertSame( 'pending', Quick_Post::resolve_post_status( 'publish', 'publish' ) );
		$this->assertSame( 'pending', Quick_Post::resolve_post_status( 'private', 'publish' ) );
		$this->assertSame( 'draft', Quick_Post::resolve_post_status( 'draft', 'publish' ) );
	}

	/**
	 * An editor can publish, and an unrecognized status falls back to the caller's default.
	 */
	public function test_editor_can_publish_and_unknown_status_falls_back() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 'publish', Quick_Post::resolve_post_status( 'publish', 'draft' ) );
		$this->assertSame( 'draft', Quick_Post::resolve_post_status( 'trash', 'draft' ) );
		$this->assertSame( 'draft', Quick_Post::resolve_post_status( '', 'draft' ) );
	}
}

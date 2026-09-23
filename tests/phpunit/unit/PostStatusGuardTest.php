<?php
/**
 * Test the shared post-status resolution guard.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use PKIW\Admin\Admin;
use PKIW\Admin\Quick_Post;
use PKIW\Admin\Webhooks_Page;
use PKIW\Plugin;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Verifies a request cannot resolve to a post status beyond the caller's capability.
 */
class PostStatusGuardTest extends WP_UnitTestCase {

	/**
	 * Invoke a private instance method by name.
	 *
	 * No setAccessible(): Reflection needs it only below PHP 8.1, and this
	 * plugin requires PHP 8.2+.
	 *
	 * @param object            $object Instance to invoke the method on.
	 * @param string            $method Method name.
	 * @param array<int, mixed> $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke_private( object $object, string $method, array $args = [] ) {
		return ( new ReflectionMethod( $object, $method ) )->invoke( $object, ...$args );
	}

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

	/**
	 * A logged-out request (user ID 0) cannot publish either — the guard
	 * isn't only checked against a role that has *some* capabilities.
	 */
	public function test_logged_out_user_cannot_publish() {
		wp_set_current_user( 0 );
		$this->assertSame( 'pending', Quick_Post::resolve_post_status( 'publish', 'publish' ) );
	}

	/**
	 * Quick_Post::create_reaction_post() actually routes its post status
	 * through the guard, not just the standalone resolve_post_status() call.
	 */
	public function test_create_reaction_post_routes_through_guard() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$quick_post = new Quick_Post( new Admin( Plugin::get_instance() ) );

		$post_id = $this->invoke_private(
			$quick_post,
			'create_reaction_post',
			[ 'like', [ 'post_status' => 'publish' ] ]
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( 'pending', get_post_status( $post_id ) );
	}

	/**
	 * Webhooks_Page::create_post_from_scrobble() (scrobble approval) also
	 * routes through the guard.
	 */
	public function test_create_post_from_scrobble_routes_through_guard() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		update_option( 'pkiw_settings', [ 'default_post_status' => 'publish' ] );
		$webhooks_page = new Webhooks_Page( new Admin( Plugin::get_instance() ) );

		$post_id = $this->invoke_private(
			$webhooks_page,
			'create_post_from_scrobble',
			[ [ 'type' => 'track', 'track' => 'Song', 'artist' => 'Artist' ] ]
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( 'pending', get_post_status( $post_id ) );
	}

	/**
	 * create_reaction_post() must set post_author to the caller explicitly
	 * (security review close-out item, finding K3) rather than leaving it
	 * to wp_insert_post()'s current-user default, so authorship can't
	 * silently drift if this method is ever called from a context with no
	 * reliably-current user.
	 */
	public function test_create_reaction_post_sets_post_author_to_caller() {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );
		$quick_post = new Quick_Post( new Admin( Plugin::get_instance() ) );

		$post_id = $this->invoke_private(
			$quick_post,
			'create_reaction_post',
			[ 'like', [] ]
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( $user_id, (int) get_post( $post_id )->post_author );
	}

	/**
	 * create_post_from_scrobble() (scrobble approval) also sets
	 * post_author to the caller explicitly.
	 */
	public function test_create_post_from_scrobble_sets_post_author_to_caller() {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );
		$webhooks_page = new Webhooks_Page( new Admin( Plugin::get_instance() ) );

		$post_id = $this->invoke_private(
			$webhooks_page,
			'create_post_from_scrobble',
			[ [ 'type' => 'track', 'track' => 'Song', 'artist' => 'Artist' ] ]
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( $user_id, (int) get_post( $post_id )->post_author );
	}
}

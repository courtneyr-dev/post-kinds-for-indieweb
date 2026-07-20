<?php
/**
 * Test per-post authorization for syndication handlers.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Integration;

use PKIW\Admin\Admin;
use PKIW\Admin\Syndication_Page;
use PKIW\Meta_Fields;
use PKIW\Plugin;
use WP_Ajax_UnitTestCase;

/**
 * Test syndication handler authorization.
 *
 * @covers \PKIW\Admin\Syndication_Page
 */
class SyndicationPageAuthorizationTest extends WP_Ajax_UnitTestCase {

	/**
	 * Syndication page instance.
	 *
	 * @var Syndication_Page
	 */
	private Syndication_Page $page;

	/**
	 * Syndication meta key.
	 *
	 * @var string
	 */
	private string $meta_key;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->page     = new Syndication_Page( new Admin( Plugin::get_instance() ) );
		$this->meta_key = Meta_Fields::PREFIX . 'syndicate_lastfm';

		update_option( 'pkiw_settings', [ 'listen_sync_to_lastfm' => true ] );
		update_option( 'pkiw_api_credentials', [ 'lastfm' => [ 'session_key' => 'test-session' ] ] );
	}

	/**
	 * Reset request globals.
	 */
	public function tear_down(): void {
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];

		parent::tear_down();
	}

	/**
	 * Test the GET handler cannot syndicate another user's post.
	 */
	public function test_handle_actions_does_not_syndicate_post_user_cannot_edit(): void {
		$post_id = $this->create_post_owned_by_another_user();

		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );

		$this->set_get_request( $post_id );
		$this->page->handle_actions();

		$this->assertFalse( metadata_exists( 'post', $post_id, $this->meta_key ) );
	}

	/**
	 * Test the GET handler syndicates a post the user can edit.
	 */
	public function test_handle_actions_syndicates_post_user_can_edit(): void {
		$post_id = $this->create_post_owned_by_current_user();

		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		$this->set_get_request( $post_id );
		$this->page->handle_actions();

		$this->assertSame( '1', get_post_meta( $post_id, $this->meta_key, true ) );
	}

	/**
	 * Test the AJAX handler cannot syndicate another user's post.
	 */
	public function test_ajax_syndicate_now_does_not_syndicate_post_user_cannot_edit(): void {
		$post_id = $this->create_post_owned_by_another_user();

		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );

		$this->set_ajax_request( $post_id );
		$this->call_ajax_handler();

		$this->assertFalse( metadata_exists( 'post', $post_id, $this->meta_key ) );
	}

	/**
	 * Test the AJAX handler syndicates a post the user can edit.
	 */
	public function test_ajax_syndicate_now_syndicates_post_user_can_edit(): void {
		$post_id = $this->create_post_owned_by_current_user();

		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		$this->set_ajax_request( $post_id );
		$this->call_ajax_handler();

		$this->assertSame( '1', get_post_meta( $post_id, $this->meta_key, true ) );
	}

	/**
	 * Create a post owned by another user and select a contributor.
	 *
	 * @return int Post ID.
	 */
	private function create_post_owned_by_another_user(): int {
		$owner_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id  = self::factory()->post->create(
			[
				'post_author' => $owner_id,
				'post_status' => 'draft',
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );

		return $post_id;
	}

	/**
	 * Create a post owned by the current contributor.
	 *
	 * @return int Post ID.
	 */
	private function create_post_owned_by_current_user(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'contributor' ] );

		wp_set_current_user( $user_id );

		return self::factory()->post->create(
			[
				'post_author' => $user_id,
				'post_status' => 'draft',
			]
		);
	}

	/**
	 * Populate a valid GET syndication request.
	 *
	 * @param int $post_id Post ID.
	 */
	private function set_get_request( int $post_id ): void {
		$_GET = [
			'action'   => 'syndicate_now',
			'page'     => 'post-kinds-indieweb-syndication',
			'post_id'  => (string) $post_id,
			'service'  => 'lastfm',
			'_wpnonce' => wp_create_nonce( 'syndicate_now_' . $post_id ),
		];

		$_REQUEST = $_GET;
	}

	/**
	 * Populate a valid AJAX syndication request.
	 *
	 * @param int $post_id Post ID.
	 */
	private function set_ajax_request( int $post_id ): void {
		$_POST = [
			'post_id' => (string) $post_id,
			'service' => 'lastfm',
			'nonce'   => wp_create_nonce( 'pkiw_syndicate_now' ),
		];

		$_REQUEST = $_POST;
	}

	/**
	 * Call the AJAX handler and accept its expected JSON termination.
	 */
	private function call_ajax_handler(): void {
		try {
			$this->page->ajax_syndicate_now();
		} catch ( \WPAjaxDieContinueException $exception ) {
			return;
		}

		$this->fail( 'AJAX handler did not terminate after sending a response.' );
	}
}

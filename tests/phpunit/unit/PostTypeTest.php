<?php
namespace PKIW\Tests\Unit;

use WP_UnitTestCase;
use PKIW\Post_Type;

class PostTypeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Clean up any leftover settings between tests.
		delete_option( 'pkiw_settings' );
	}

	public function tear_down(): void {
		delete_option( 'pkiw_settings' );
		parent::tear_down();
	}

	public function test_post_type_constant() {
		$this->assertSame( 'pkiw_reaction', Post_Type::POST_TYPE );
	}

	public function test_is_cpt_mode_false_by_default() {
		$this->assertFalse( Post_Type::is_cpt_mode() );
	}

	public function test_is_cpt_mode_true_when_set() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$this->assertTrue( Post_Type::is_cpt_mode() );
	}

	public function test_is_cpt_mode_false_when_standard() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'standard' ] );
		$this->assertFalse( Post_Type::is_cpt_mode() );
	}

	public function test_is_hidden_mode_false_by_default() {
		$this->assertFalse( Post_Type::is_hidden_mode() );
	}

	public function test_is_hidden_mode_true_when_set() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'hidden' ] );
		$this->assertTrue( Post_Type::is_hidden_mode() );
	}

	public function test_is_hidden_mode_false_when_cpt() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$this->assertFalse( Post_Type::is_hidden_mode() );
	}

	public function test_get_storage_mode_defaults_to_standard() {
		$this->assertSame( 'standard', Post_Type::get_storage_mode() );
	}

	public function test_get_storage_mode_returns_cpt() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$this->assertSame( 'cpt', Post_Type::get_storage_mode() );
	}

	public function test_get_storage_mode_returns_hidden() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'hidden' ] );
		$this->assertSame( 'hidden', Post_Type::get_storage_mode() );
	}

	public function test_get_storage_mode_returns_standard_for_invalid() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'invalid_mode' ] );
		$this->assertSame( 'standard', Post_Type::get_storage_mode() );
	}

	public function test_get_import_post_type_returns_post_by_default() {
		$this->assertSame( 'post', Post_Type::get_import_post_type() );
	}

	public function test_get_import_post_type_returns_reaction_in_cpt_mode() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$this->assertSame( 'pkiw_reaction', Post_Type::get_import_post_type() );
	}

	public function test_get_import_post_type_returns_post_in_hidden_mode() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'hidden' ] );
		$this->assertSame( 'post', Post_Type::get_import_post_type() );
	}

	public function test_post_type_not_registered_in_standard_mode() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'standard' ] );
		$pt = new Post_Type();
		$pt->maybe_register_post_type();

		$this->assertFalse( post_type_exists( 'pkiw_reaction' ) );
	}

	public function test_post_type_registered_in_cpt_mode() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$pt = new Post_Type();
		$pt->maybe_register_post_type();

		$this->assertTrue( post_type_exists( 'pkiw_reaction' ) );
	}

	public function test_post_type_args_in_cpt_mode() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$pt = new Post_Type();
		$pt->maybe_register_post_type();

		$post_type = get_post_type_object( 'pkiw_reaction' );
		$this->assertTrue( $post_type->public );
		$this->assertTrue( $post_type->show_in_rest );
		$this->assertSame( 'post-kinds', $post_type->rest_base );
		$this->assertTrue( $post_type->has_archive );
		$this->assertFalse( $post_type->hierarchical );
		$this->assertSame( 'dashicons-heart', $post_type->menu_icon );
	}

	public function test_post_type_supports_in_cpt_mode() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$pt = new Post_Type();
		$pt->maybe_register_post_type();

		$this->assertTrue( post_type_supports( 'pkiw_reaction', 'title' ) );
		$this->assertTrue( post_type_supports( 'pkiw_reaction', 'editor' ) );
		$this->assertSame( [ [ 'notes' => true ] ], get_all_post_type_supports( 'pkiw_reaction' )['editor'] );
		$this->assertTrue( post_type_supports( 'pkiw_reaction', 'author' ) );
		$this->assertTrue( post_type_supports( 'pkiw_reaction', 'thumbnail' ) );
		$this->assertTrue( post_type_supports( 'pkiw_reaction', 'excerpt' ) );
		$this->assertTrue( post_type_supports( 'pkiw_reaction', 'custom-fields' ) );
		$this->assertTrue( post_type_supports( 'pkiw_reaction', 'comments' ) );
	}

	public function test_post_type_rewrite_in_cpt_mode() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$pt = new Post_Type();
		$pt->maybe_register_post_type();

		$post_type = get_post_type_object( 'pkiw_reaction' );
		$this->assertSame( 'post-kinds', $post_type->rewrite['slug'] );
		$this->assertFalse( $post_type->rewrite['with_front'] );
	}

	public function test_reaction_notes_are_editorial() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		( new Post_Type() )->maybe_register_post_type();

		$previous_user = get_current_user_id();
		$editor        = $this->factory->user->create( [ 'role' => 'editor' ] );
		$contributor   = $this->factory->user->create( [ 'role' => 'contributor' ] );
		$post_id       = $this->factory->post->create(
			[
				'post_type'   => Post_Type::POST_TYPE,
				'post_author' => $editor,
			]
		);

		try {
			wp_set_current_user( $editor );
			$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
			$request->set_body_params(
				[
					'post'    => $post_id,
					'type'    => 'note',
					'content' => 'Check the attribution.',
				]
			);
			$response = rest_do_request( $request );
			$this->assertSame( 201, $response->get_status() );
			$note_id = $response->get_data()['id'];
			$this->assertSame( 0, (int) get_comments_number( $post_id ) );

			wp_set_current_user( $contributor );
			$request = new \WP_REST_Request( 'GET', '/wp/v2/comments/' . $note_id );
			$request->set_query_params( [ 'type' => 'note' ] );
			$this->assertSame( 403, rest_do_request( $request )->get_status() );

			wp_set_current_user( 0 );
			$request = new \WP_REST_Request( 'GET', '/wp/v2/comments' );
			$request->set_query_params( [ 'post' => [ $post_id ] ] );
			$this->assertSame( [], rest_do_request( $request )->get_data() );
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	public function test_post_type_has_kind_taxonomy() {
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'cpt' ] );
		$pt = new Post_Type();
		$pt->maybe_register_post_type();

		$post_type = get_post_type_object( 'pkiw_reaction' );
		$this->assertContains( 'kind', $post_type->taxonomies );
	}
}

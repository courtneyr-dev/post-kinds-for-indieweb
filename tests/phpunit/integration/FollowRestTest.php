<?php
/** Follow REST gate coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/** @group integration */
final class FollowRestTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
	}

	public function test_hidden_follow_redacts_meta_and_public_control_keeps_name(): void {
		$private = get_post( self::factory()->post->create( [ 'post_status' => 'private' ] ) );
		wp_set_object_terms( $private->ID, 'follow', Taxonomy::TAXONOMY );
		$hidden  = new WP_REST_Response( [ 'featured_media' => 9, 'meta' => [ '_pkiw_cite_name' => 'Secret' ] ] );
		$data    = Follow_Archive::rest_meta_for( $hidden, $private, 'follow' )->get_data();
		$this->assertSame( 0, $data['featured_media'] );
		$this->assertArrayNotHasKey( '_pkiw_cite_name', $data['meta'], 'Removing the REST gate would expose the private name.' );

		$public = get_post( self::factory()->post->create( [ 'post_status' => 'publish' ] ) );
		$open   = Follow_Archive::rest_meta_for( new WP_REST_Response( [ 'featured_media' => 0, 'meta' => [ '_pkiw_cite_name' => 'Public' ] ] ), $public, 'follow' )->get_data();
		$this->assertSame( 'Public', $open['meta']['_pkiw_cite_name'] );
	}

	public function test_rest_gate_hooks_post_and_each_kind_enabled_type_at_priority_21(): void {
		$this->assertSame( 21, has_filter( 'rest_prepare_post', [ Follow_Archive::class, 'rest_meta' ] ) );
		register_post_type( 'follow_storage', [ 'public' => true, 'show_in_rest' => true ] );
		register_taxonomy_for_object_type( Taxonomy::TAXONOMY, 'follow_storage' );
		Follow_Archive::register_rest_hooks();
		try {
			$this->assertSame( 21, has_filter( 'rest_prepare_follow_storage', [ Follow_Archive::class, 'rest_meta' ] ), 'Every kind-enabled storage type needs the same REST gate.' );
		} finally {
			remove_filter( 'rest_prepare_follow_storage', [ Follow_Archive::class, 'rest_meta' ], 21 );
			unregister_post_type( 'follow_storage' );
		}
	}

	public function test_multi_kind_follow_uses_membership_for_rest_and_stream_gates(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$private = self::factory()->post->create( [ 'post_status' => 'private' ] );
		wp_set_object_terms( $private, [ 'bookmark', 'follow' ], Taxonomy::TAXONOMY );
		update_post_meta( $private, '_pkiw_cite_name', 'Private multi-kind name' );
		update_post_meta( $private, '_pkiw_cite_url', 'https://private-multi.example/' );

		$view = $this->get_post( $private, 'view' )->get_data();
		foreach ( array_keys( (array) ( $view['meta'] ?? [] ) ) as $key ) {
			$this->assertStringStartsNotWith( '_pkiw_', (string) $key, 'View context must remove every PKIW key from a hidden multi-kind follow.' );
		}
		$this->assertSame( 0, $view['featured_media'], 'View context must remove featured media from a hidden multi-kind follow.' );

		$edit = $this->get_post( $private, 'edit' )->get_data();
		$this->assertSame( 'Private multi-kind name', $edit['meta']['_pkiw_cite_name'], 'Edit context must keep follow meta for an editor.' );
		$this->assertSame( 'https://private-multi.example/', $edit['meta']['_pkiw_cite_url'] );

		$GLOBALS['post'] = get_post( $private ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Stream fixture.
		setup_postdata( $GLOBALS['post'] );
		$this->assertSame( '', PKIW\render_stream_card(), 'A hidden multi-kind follow must not render cite text in a Stream card.' );

		$bookmark = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $bookmark, 'bookmark', Taxonomy::TAXONOMY );
		$response = new WP_REST_Response( [ 'featured_media' => 7, 'meta' => [ '_pkiw_control' => 'kept' ] ] );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $bookmark );
		$request->set_param( 'context', 'view' );
		$data = Follow_Archive::rest_meta( $response, get_post( $bookmark ), $request )->get_data();
		$this->assertSame( 'kept', $data['meta']['_pkiw_control'], 'A bookmark without the follow term is the positive control.' );
		$this->assertSame( 7, $data['featured_media'] );
	}

	private function get_post( int $post_id, string $context ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', $context );
		return rest_get_server()->dispatch( $request );
	}
}

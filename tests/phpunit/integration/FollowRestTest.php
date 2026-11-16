<?php
/** Follow REST gate coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowRestTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
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
}

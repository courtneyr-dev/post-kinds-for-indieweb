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

		$eligible = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $eligible, 'tag', Taxonomy::TAXONOMY );
		update_post_meta( $eligible, '_pkiw_cite_name', 'Eligible sentinel name' );
		update_post_meta( $eligible, '_pkiw_cite_url', 'https://eligible-sentinel.example/' );
		$block->context = [ 'postId' => $eligible ];
		$this->assertSame( 'Eligible sentinel name', $legacy->get_value( [ 'key' => 'title' ], $block, 'content' ) );
		$this->assertSame( 'https://eligible-sentinel.example/', $legacy->get_value( [ 'key' => 'url' ], $block, 'content' ) );
		$this->assertSame( 'Eligible sentinel name', $current->get_binding_value( [ 'key' => 'cite_name' ], $block, 'content' ) );
		$this->assertSame( 'https://eligible-sentinel.example/', $current->get_binding_value( [ 'key' => 'cite_url' ], $block, 'content' ) );
	}

	public function test_follow_photo_bindings_and_meta_id_writes_allow_only_public_local_images(): void {
		global $wpdb;
		( new Taxonomy() )->create_default_terms();
		$remote = 'https://remote-photo.example/photo.jpg';
		$legacy = new Block_Bindings_Source();
		$current = new Block_Bindings();
		$block   = $this->createMock( WP_Block::class );

		$remote_follow = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $remote_follow, 'follow', Taxonomy::TAXONOMY );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $remote_follow, 'meta_key' => '_pkiw_cite_photo', 'meta_value' => $remote ] );
		wp_cache_delete( $remote_follow, 'post_meta' );
		$block->context = [ 'postId' => $remote_follow ];
		$this->assertEmpty( $legacy->get_value( [ 'key' => 'cover_image' ], $block, 'url' ), 'The legacy binding must not print a remote legacy row.' );
		$this->assertEmpty( $current->get_binding_value( [ 'key' => 'cite_photo' ], $block, 'url' ), 'The current binding must not print a remote legacy row.' );

		$parent = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$image  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent );
		$url    = (string) wp_get_attachment_url( $image );
		$parent_follow = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $parent_follow, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $parent_follow, '_pkiw_cite_photo', $url );
		wp_update_post( [ 'ID' => $parent, 'post_status' => 'private' ] );
		$block->context = [ 'postId' => $parent_follow ];
		$this->assertEmpty( $legacy->get_value( [ 'key' => 'cover_image' ], $block, 'url' ), 'A local image whose parent became private is no longer public.' );
		$this->assertEmpty( $current->get_binding_value( [ 'key' => 'cite_photo' ], $block, 'url' ) );

		$public_image  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$public_url    = (string) wp_get_attachment_url( $public_image );
		$public_follow = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $public_follow, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $public_follow, '_pkiw_cite_photo', $public_url );
		$block->context = [ 'postId' => $public_follow ];
		$this->assertSame( $public_url, $legacy->get_value( [ 'key' => 'cover_image' ], $block, 'url' ), 'A public media-library image remains available.' );
		$this->assertSame( $public_url, $current->get_binding_value( [ 'key' => 'cite_photo' ], $block, 'url' ) );

		$meta_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_pkiw_cite_photo'", $public_follow ) );
		update_metadata_by_mid( 'post', $meta_id, $remote );
		$this->assertSame( $public_url, get_metadata_by_mid( 'post', $meta_id )->meta_value, 'The by-mid path must apply the same write guard.' );

		$plain = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $plain, 'meta_key' => '_pkiw_cite_photo', 'meta_value' => $remote ] );
		wp_cache_delete( $plain, 'post_meta' );
		$block->context = [ 'postId' => $plain ];
		$this->assertSame( $remote, $legacy->get_value( [ 'key' => 'cover_image' ], $block, 'url' ), 'A non-follow keeps its remote value.' );
		$this->assertSame( $remote, $current->get_binding_value( [ 'key' => 'cite_photo' ], $block, 'url' ) );

		$becoming_follow = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $becoming_follow, 'meta_key' => '_pkiw_cite_photo', 'meta_value' => $remote ] );
		$raw_id = (int) $wpdb->insert_id;
		wp_cache_delete( $becoming_follow, 'post_meta' );
		wp_set_object_terms( $becoming_follow, 'follow', Taxonomy::TAXONOMY );
		$this->assertFalse( get_metadata_by_mid( 'post', $raw_id ), 'The kind-change cleanup must read and delete the raw unsafe row.' );
	}
}

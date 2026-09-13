<?php
/**
 * Simple Location / IndieBlocks coordinates must not leak through REST for
 * non-public posts (audit SG-04), while stored meta stays intact.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\Meta_Fields;
use WP_REST_Request;
use WP_UnitTestCase;

final class LocationRestRedactionTest extends WP_UnitTestCase {

	private int $post_id = 0;

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'geo_latitude', 'geo_longitude', 'geo_address', 'geo_public' ] as $key ) {
			register_post_meta( 'post', $key, [ 'show_in_rest' => true, 'single' => true, 'type' => 'string' ] );
		}
		register_rest_field( 'post', 'indieblocks_location', [ 'get_callback' => static fn( $p ) => [ 'geo_latitude' => get_post_meta( $p['id'], 'geo_latitude', true ), 'geo_address' => get_post_meta( $p['id'], 'geo_address', true ) ] ] );
		$this->post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $this->post_id, 'geo_latitude', '40.20192' );
		update_post_meta( $this->post_id, 'geo_longitude', '-77.19256' );
		update_post_meta( $this->post_id, 'geo_address', 'Molly Pitcher Brewing Company, Carlisle, PA' );
		add_filter( 'rest_prepare_post', [ new Meta_Fields(), 'redact_location_meta' ], 20, 3 );
		wp_set_current_user( 0 );
	}

	private function response(): array {
		$GLOBALS['wp_rest_server'] = null;
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->post_id );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	public function test_private_location_is_blank_in_rest_but_stored(): void {
		update_post_meta( $this->post_id, 'geo_public', '0' );
		$data = $this->response();
		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( '', $data['meta']['geo_longitude'] );
		$this->assertSame( '', $data['meta']['geo_address'] );
		$this->assertSame( '', $data['indieblocks_location']['geo_latitude'] );
		$this->assertSame( '', $data['indieblocks_location']['geo_address'] );
		$this->assertSame( '40.20192', get_post_meta( $this->post_id, 'geo_latitude', true ), 'stored data must stay intact' );
	}

	public function test_protected_location_keeps_text_hides_coordinates(): void {
		update_post_meta( $this->post_id, 'geo_public', '2' );
		$data = $this->response();
		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( 'Molly Pitcher Brewing Company, Carlisle, PA', $data['meta']['geo_address'] );
		$this->assertSame( '', $data['indieblocks_location']['geo_latitude'] );
	}

	public function test_public_location_is_untouched(): void {
		update_post_meta( $this->post_id, 'geo_public', '1' );
		$data = $this->response();
		$this->assertSame( '40.20192', $data['meta']['geo_latitude'] );
		$this->assertSame( 'Molly Pitcher Brewing Company, Carlisle, PA', $data['meta']['geo_address'] );
	}

	public function test_editor_sees_everything(): void {
		update_post_meta( $this->post_id, 'geo_public', '0' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$data = $this->response();
		$this->assertSame( '40.20192', $data['meta']['geo_latitude'] );
	}

	public function test_pkiw_osm_id_is_blank_for_non_public_location(): void {
		// The test framework wipes registered meta between tests; register the plugin's fields again.
		( new Meta_Fields() )->register_meta_fields();
		update_post_meta( $this->post_id, '_pkiw_checkin_osm_id', 'node/123456' );
		update_post_meta( $this->post_id, '_pkiw_geo_privacy', 'approximate' );
		$data = $this->response();
		$this->assertArrayHasKey( '_pkiw_checkin_osm_id', $data['meta'] );
		$this->assertSame( '', $data['meta']['_pkiw_checkin_osm_id'] );
		$this->assertSame( 'node/123456', get_post_meta( $this->post_id, '_pkiw_checkin_osm_id', true ) );
	}

	public function test_reaction_post_type_rest_response_is_redacted(): void {
		if ( ! post_type_exists( \PKIW\Post_Type::POST_TYPE ) ) {
			register_post_type( \PKIW\Post_Type::POST_TYPE, [ 'public' => true, 'show_in_rest' => true, 'rest_base' => 'post-kinds', 'supports' => [ 'title', 'custom-fields' ] ] );
		}
		register_post_meta( \PKIW\Post_Type::POST_TYPE, '_pkiw_geo_latitude', [ 'show_in_rest' => true, 'single' => true, 'type' => 'number', 'auth_callback' => '__return_true' ] );
		new Meta_Fields();
		$this->assertNotFalse( has_filter( 'rest_prepare_' . \PKIW\Post_Type::POST_TYPE ), 'redaction must be hooked for the reaction post type' );
		$id = self::factory()->post->create( [ 'post_type' => \PKIW\Post_Type::POST_TYPE, 'post_status' => 'publish' ] );
		update_post_meta( $id, '_pkiw_geo_latitude', 40.20192 );
		update_post_meta( $id, '_pkiw_geo_privacy', 'approximate' );
		$GLOBALS['wp_rest_server'] = null;
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/post-kinds/' . $id ) )->get_data();
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertEquals( 0, $data['meta']['_pkiw_geo_latitude'] );
	}
}

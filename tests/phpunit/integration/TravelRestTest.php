<?php
/** Travel REST meta redaction. @package PKIW */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;

/** @group integration */
final class TravelRestTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Travel() )->register();
	}

	private function post( array $post = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish', 'post_date' => '2000-01-01 09:00:00' ], $post ) );
		wp_set_object_terms( $id, 'trip', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', 'approximate' );
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( [ [ 'name' => 'Harbor', 'locality' => 'Portland', 'street' => '1 Sentinel Way', 'postal_code' => 'SE244', 'lat' => 0.244244, 'lng' => 0.442442, 'date' => '2000-01-01', 'privacy' => 'public' ] ] ) );
		return (int) $id;
	}

	private function get( int $id, string $context = 'view' ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$request->set_param( 'context', $context );
		return rest_get_server()->dispatch( $request );
	}

	public function test_view_context_is_public_json_for_logged_out_and_editor(): void {
		$id = $this->post();
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user ) {
			wp_set_current_user( $user );
			$data = $this->get( $id )->get_data();
			$json = (string) ( $data['meta'][ Travel::STOPS_KEY ] ?? '' );
			$this->assertStringContainsString( 'Portland', $json );
			$this->assertStringNotContainsString( 'Sentinel', $json );
			$this->assertStringNotContainsString( '0.244', $json );
		}
	}

	public function test_editor_edit_context_receives_stored_json(): void {
		$id = $this->post();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$data = $this->get( $id, 'edit' )->get_data();
		$this->assertStringContainsString( '1 Sentinel Way', (string) $data['meta'][ Travel::STOPS_KEY ] );
	}

	public function test_password_post_has_no_pkiw_meta_key(): void {
		$id = $this->post( [ 'post_password' => 'secret' ] );
		$data = $this->get( $id )->get_data();
		$this->assertArrayNotHasKey( Travel::STOPS_KEY, (array) ( $data['meta'] ?? [] ) );
	}
}

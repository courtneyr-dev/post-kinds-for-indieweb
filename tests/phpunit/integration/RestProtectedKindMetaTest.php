<?php
/**
 * Password-protected posts must not expose Post Kinds meta in REST.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

final class RestProtectedKindMetaTest extends WP_UnitTestCase {

	private const META = [
		'_pkiw_cite_url'         => 'https://example.test/source',
		'_pkiw_review_item_name' => 'Hidden Review Subject',
		'_pkiw_review_rating'    => 4.0,
		'_pkiw_read_title'       => 'Hidden Read Title',
	];

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_anonymous_protected_post_omits_kind_meta(): void {
		$data = $this->rest_post( $this->kind_post( [ 'post_password' => 'secret' ] ) );

		$this->assertKindMetaOmitted( $data['meta'] );
	}

	public function test_correct_password_param_still_omits_kind_meta(): void {
		$data = $this->rest_post( $this->kind_post( [ 'post_password' => 'secret' ] ), [ 'password' => 'secret' ] );

		$this->assertTrue( $data['content']['protected'] );
		$this->assertKindMetaOmitted( $data['meta'] );
	}

	public function test_editor_gets_protected_post_kind_meta(): void {
		$post_id = $this->kind_post( [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$data = $this->rest_post( $post_id );

		$this->assertKindMetaPresent( $data['meta'] );
	}

	public function test_anonymous_public_post_keeps_kind_meta(): void {
		$data = $this->rest_post( $this->kind_post() );

		$this->assertKindMetaPresent( $data['meta'] );
	}

	public function test_collection_endpoint_omits_protected_kind_meta(): void {
		$post_id = $this->kind_post( [ 'post_password' => 'secret' ] );
		$GLOBALS['wp_rest_server'] = null;
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'include', [ $post_id ] );

		$response = rest_do_request( $request );
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( $post_id, (int) $data[0]['id'] );
		$this->assertKindMetaOmitted( $data[0]['meta'] );
	}

	/**
	 * @param array<string, mixed> $post Post overrides.
	 */
	private function kind_post( array $post = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Protected kind meta sentinel',
				],
				$post
			)
		);

		foreach ( self::META as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 * @return array<string, mixed>
	 */
	private function rest_post( int $post_id, array $params = [] ): array {
		$GLOBALS['wp_rest_server'] = null;
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return (array) rest_do_request( $request )->get_data();
	}

	/**
	 * @param array<string, mixed> $meta REST meta payload.
	 */
	private function assertKindMetaOmitted( array $meta ): void {
		foreach ( array_keys( self::META ) as $key ) {
			$this->assertArrayNotHasKey( $key, $meta );
		}
	}

	/**
	 * @param array<string, mixed> $meta REST meta payload.
	 */
	private function assertKindMetaPresent( array $meta ): void {
		foreach ( self::META as $key => $value ) {
			$this->assertArrayHasKey( $key, $meta );
			$this->assertSame( $value, $meta[ $key ] );
		}
	}
}

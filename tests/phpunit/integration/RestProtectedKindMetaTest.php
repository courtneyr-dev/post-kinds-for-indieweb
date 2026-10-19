<?php
/**
 * Password-protected posts must not expose Post Kinds meta in REST.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings_Source;
use PKIW\Meta_Fields;
use PKIW\Post_Type;

final class RestProtectedKindMetaTest extends WP_UnitTestCase {

	private const FILTERED_POST_TYPE = 'pkiw_meta_filter';

	private const META = [
		'_pkiw_cite_url'         => 'https://example.test/source',
		'_pkiw_review_item_name' => 'Hidden Review Subject',
		'_pkiw_review_rating'    => 4.0,
		'_pkiw_read_title'       => 'Hidden Read Title',
	];

	private const WISH_META = [
		'_pkiw_wish_name'     => 'Hidden Greenhouse',
		'_pkiw_wish_url'      => 'https://example.test/greenhouse',
		'_pkiw_wish_photo'    => 'https://example.test/greenhouse.jpg',
		'_pkiw_wish_type'     => 'product',
		'_pkiw_wish_priority' => 'high',
	];

	private bool $registered_reaction_post_type = false;

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_filter( 'pkiw_meta_post_types', [ $this, 'add_filtered_meta_post_type' ] );
		if ( post_type_exists( self::FILTERED_POST_TYPE ) ) {
			unregister_post_type( self::FILTERED_POST_TYPE );
		}
		if ( $this->registered_reaction_post_type && post_type_exists( Post_Type::POST_TYPE ) ) {
			unregister_post_type( Post_Type::POST_TYPE );
		}
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

	/**
	 * Wish meta follows the same protected-post gate on every REST access path.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_wish_meta_is_redacted_for_protected_visitors_and_kept_for_editors_and_public_posts(): void {
		$protected = $this->wish_post( [ 'post_password' => 'secret' ] );

		$this->assertWishMetaOmitted( $this->rest_post( $protected )['meta'] );
		$this->assertWishMetaOmitted( $this->rest_post( $protected, [ 'password' => 'secret' ] )['meta'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertWishMetaPresent( $this->rest_post( $protected )['meta'] );

		wp_set_current_user( 0 );
		$this->assertWishMetaPresent( $this->rest_post( $this->wish_post() )['meta'] );
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

	public function test_reaction_post_type_omits_protected_kind_meta(): void {
		$this->register_reaction_post_type();
		( new Meta_Fields() )->register_meta_fields();
		$post_id = $this->kind_post(
			[
				'post_type'     => Post_Type::POST_TYPE,
				'post_password' => 'secret',
			]
		);

		$data = $this->rest_post( $post_id, [], '/wp/v2/post-kinds/' );

		$this->assertKindMetaOmitted( $data['meta'] );
	}

	public function test_filtered_meta_post_type_omits_protected_kind_meta(): void {
		$result = register_post_type(
			self::FILTERED_POST_TYPE,
			[
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'custom-fields' ],
			]
		);
		$this->assertNotWPError( $result );
		$this->assertTrue( post_type_exists( self::FILTERED_POST_TYPE ) );
		add_filter( 'pkiw_meta_post_types', [ $this, 'add_filtered_meta_post_type' ] );
		( new Meta_Fields() )->register_meta_fields();
		$post_id = $this->kind_post(
			[
				'post_type'     => self::FILTERED_POST_TYPE,
				'post_password' => 'secret',
			]
		);

		$data = $this->rest_post( $post_id, [], '/wp/v2/' . self::FILTERED_POST_TYPE . '/' );

		$this->assertKindMetaOmitted( $data['meta'] );
	}

	public function test_anonymous_protected_post_omits_pk_alias_meta(): void {
		( new Block_Bindings_Source() )->register_meta();
		$post_id = $this->kind_post( [ 'post_password' => 'secret' ] );
		update_post_meta( $post_id, 'pk_title', 'Hidden PK Title' );

		$data = $this->rest_post( $post_id );

		$this->assertArrayNotHasKey( 'pk_title', $data['meta'] );
	}

	public function test_anonymous_public_post_keeps_pk_alias_meta(): void {
		( new Block_Bindings_Source() )->register_meta();
		$post_id = $this->kind_post();
		update_post_meta( $post_id, 'pk_title', 'Hidden PK Title' );

		$data = $this->rest_post( $post_id );

		$this->assertArrayHasKey( 'pk_title', $data['meta'] );
		$this->assertSame( 'Hidden PK Title', $data['meta']['pk_title'] );
	}

	/**
	 * @param array<string> $post_types Post types.
	 * @return array<string>
	 */
	public function add_filtered_meta_post_type( array $post_types ): array {
		$post_types[] = self::FILTERED_POST_TYPE;
		return $post_types;
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
	 * Create a wish carrying all five public wish meta keys.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $post Post overrides.
	 * @return int Post ID.
	 */
	private function wish_post( array $post = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Wish meta sentinel',
				],
				$post
			)
		);
		wp_set_object_terms( $post_id, 'wish', 'kind' );

		foreach ( self::WISH_META as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 * @return array<string, mixed>
	 */
	private function rest_post( int $post_id, array $params = [], string $route_base = '/wp/v2/posts/' ): array {
		$GLOBALS['wp_rest_server'] = null;
		$request = new WP_REST_Request( 'GET', $route_base . $post_id );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return (array) rest_do_request( $request )->get_data();
	}

	private function register_reaction_post_type(): void {
		if ( post_type_exists( Post_Type::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			Post_Type::POST_TYPE,
			[
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'post-kinds',
				'supports'     => [ 'title', 'custom-fields' ],
			]
		);
		$this->registered_reaction_post_type = true;
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

	/**
	 * Assert that no wish meta key crosses the protected REST boundary.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $meta REST meta payload.
	 * @return void
	 */
	private function assertWishMetaOmitted( array $meta ): void {
		foreach ( array_keys( self::WISH_META ) as $key ) {
			$this->assertArrayNotHasKey( $key, $meta );
		}
	}

	/**
	 * Assert that all wish meta remains available to an authorized reader.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $meta REST meta payload.
	 * @return void
	 */
	private function assertWishMetaPresent( array $meta ): void {
		foreach ( self::WISH_META as $key => $value ) {
			$this->assertArrayHasKey( $key, $meta );
			$this->assertSame( $value, $meta[ $key ] );
		}
	}
}

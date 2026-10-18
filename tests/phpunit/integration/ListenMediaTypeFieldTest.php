<?php
/**
 * Listen media type registration, REST, and privacy coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Listen_Media_Type;
use PKIW\Meta_Fields;

/**
 * @group integration
 */
final class ListenMediaTypeFieldTest extends WP_UnitTestCase {

	private Meta_Fields $meta_fields;

	public function set_up(): void {
		parent::set_up();
		$this->meta_fields = new Meta_Fields();
		$this->meta_fields->register_meta_fields();
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_field_is_registered_for_rest_without_an_enum(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertArrayHasKey( Listen_Media_Type::META, $registered );
		$this->assertIsArray( $registered[ Listen_Media_Type::META ]['show_in_rest'] );
		$this->assertArrayNotHasKey( 'enum', $registered[ Listen_Media_Type::META ]['show_in_rest']['schema'] );
		$this->assertTrue( $this->meta_fields->is_valid_field( 'listen_media_type' ) );
	}

	/**
	 * @dataProvider media_type_values
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $expected Sanitized value.
	 */
	public function test_field_sanitizes_to_the_closed_vocabulary( mixed $value, string $expected ): void {
		$this->assertSame( $expected, $this->meta_fields->sanitize_listen_media_type( $value ) );
	}

	/**
	 * @return array<string, array{0:mixed, 1:string}>
	 */
	public function media_type_values(): array {
		return [
			'podcast with whitespace' => [ 'Podcast ', 'podcast' ],
			'uppercase music'         => [ 'MUSIC', 'music' ],
			'unknown value'           => [ 'banana', '' ],
			'array'                   => [ [], '' ],
		];
	}

	/**
	 * @dataProvider rest_update_values
	 */
	public function test_editor_can_update_and_clear_the_field_through_rest( string $value ): void {
		$post_id = $this->listen_post();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( [ 'meta' => [ Listen_Media_Type::META => $value ] ] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $value, (string) get_post_meta( $post_id, Listen_Media_Type::META, true ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function rest_update_values(): array {
		return [
			'podcast' => [ 'podcast' ],
			'unset'   => [ '' ],
		];
	}

	public function test_anonymous_public_response_exposes_the_stored_type(): void {
		$post_id = $this->listen_post();
		update_post_meta( $post_id, Listen_Media_Type::META, Listen_Media_Type::PODCAST );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = (array) $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( Listen_Media_Type::META, $data['meta'] );
		$this->assertSame( Listen_Media_Type::PODCAST, $data['meta'][ Listen_Media_Type::META ] );
	}

	public function test_anonymous_protected_response_omits_the_type(): void {
		$post_id = $this->listen_post( [ 'post_password' => 'secret' ] );
		update_post_meta( $post_id, Listen_Media_Type::META, Listen_Media_Type::PODCAST );

		$data = $this->rest_post( $post_id );

		$this->assertArrayNotHasKey( Listen_Media_Type::META, $data['meta'] );
	}

	public function test_password_cookie_does_not_expose_the_type_through_rest(): void {
		$post_id = $this->listen_post( [ 'post_password' => 'secret' ] );
		update_post_meta( $post_id, Listen_Media_Type::META, Listen_Media_Type::PODCAST );
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'secret' );

		$this->assertFalse( post_password_required( $post_id ), 'The cookie unlocks normal post content.' );
		$data = $this->rest_post( $post_id );

		$this->assertArrayNotHasKey( Listen_Media_Type::META, $data['meta'] );
	}

	public function test_stored_does_not_turn_a_registered_default_into_a_row(): void {
		$post_id = $this->listen_post();

		$this->assertFalse( metadata_exists( 'post', $post_id, Listen_Media_Type::META ) );
		$this->assertSame( '', Listen_Media_Type::stored( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Listen_Media_Type::META ) );
	}

	public function test_integrations_and_standard_site_do_not_infer_the_field(): void {
		$paths   = (array) glob( PKIW_PATH . 'includes/integrations/*.php' );
		$paths[] = PKIW_PATH . 'includes/class-standard-site.php';
		$source  = '';
		foreach ( $paths as $path ) {
			$source .= (string) file_get_contents( $path );
		}

		$this->assertStringNotContainsString( 'listen_media_type', $source );
	}

	/**
	 * @param array<string, mixed> $args Post overrides.
	 */
	private function listen_post( array $args = [] ): int {
		$this->ensure_listen_term();
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Podcast listen fixture',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'listen', 'kind' );

		return $post_id;
	}

	/**
	 * Return one post REST payload as an anonymous visitor.
	 *
	 * @return array<string, mixed>
	 */
	private function rest_post( int $post_id ): array {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		return (array) $response->get_data();
	}

	private function ensure_listen_term(): void {
		if ( ! term_exists( 'listen', 'kind' ) ) {
			wp_insert_term( 'Listen', 'kind', [ 'slug' => 'listen' ] );
		}
	}
}

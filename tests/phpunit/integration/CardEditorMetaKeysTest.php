<?php
/**
 * Every post meta key a block editor script names is registered.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * The REST API drops meta keys nobody registered, without an error, so an
 * editor that writes one looks like it saves and stores nothing.
 *
 * @group integration
 */
final class CardEditorMetaKeysTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The test case unregisters meta between tests.
		( new Meta_Fields() )->register_meta_fields();
	}

	public function test_rest_drops_an_unregistered_meta_key_without_an_error(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post_id = self::factory()->post->create();

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params(
			[
				'meta' => [
					'_pkiw_jam_title' => 'Blue in Green',
					'_pkiw_jam_track' => 'Blue in Green',
				],
			]
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Blue in Green', get_metadata_raw( 'post', $post_id, '_pkiw_jam_track', true ) );
		$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_jam_title', true ) );
	}

	public function test_every_meta_key_a_block_editor_names_is_registered(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );
		$missing    = [];

		foreach ( glob( dirname( __DIR__, 3 ) . '/src/blocks/*/edit.js' ) as $file ) {
			preg_match_all( '/\b_pkiw_[a-z0-9_]+\b/', (string) file_get_contents( $file ), $matches ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source file.
			foreach ( array_unique( $matches[0] ) as $key ) {
				// A prefix in a comment, such as _pkiw_mood_*, names no key.
				if ( str_ends_with( $key, '_' ) || isset( $registered[ $key ] ) ) {
					continue;
				}
				$missing[] = basename( dirname( $file ) ) . ': ' . $key;
			}
		}

		$this->assertSame( [], $missing );
	}
}

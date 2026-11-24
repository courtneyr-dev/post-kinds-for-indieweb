<?php
/**
 * Every post meta key a block editor script names is registered.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Sync\Foursquare_Checkin_Sync;
use PKIW\Sync\Lastfm_Listen_Sync;
use PKIW\Sync\Trakt_Watch_Sync;

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

	/**
	 * @dataProvider syndication_services
	 */
	public function test_rest_syndication_preferences_reach_the_publish_gate( string $kind, string $service, string $class ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'pkiw_settings', [ $kind . '_sync_to_' . $service => true ] );
		$post_id  = self::factory()->post->create();
		$meta_key = Meta_Fields::PREFIX . 'syndicate_' . $service;
		$sync     = $this->getMockBuilder( $class )->disableOriginalConstructor()->onlyMethods( [ 'is_connected' ] )->getMock();
		$sync->method( 'is_connected' )->willReturn( true );
		$enabled = new ReflectionMethod( $class, 'is_syndication_enabled' );

		$this->assertNull( get_metadata_raw( 'post', $post_id, $meta_key, true ) );
		$this->assertTrue( $enabled->invoke( $sync, $post_id ) );

		foreach ( [ false, true, false ] as $value ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
			$request->set_body_params( [ 'meta' => [ $meta_key => $value ] ] );
			$response = rest_do_request( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $value, $response->get_data()['meta'][ $meta_key ] ?? null );
			$this->assertSame( $value ? '1' : '', get_metadata_raw( 'post', $post_id, $meta_key, true ) );
			$this->assertSame( $value, $enabled->invoke( $sync, $post_id ) );
		}
	}

	/**
	 * @dataProvider syndication_services
	 */
	public function test_rest_publish_uses_the_preference_from_that_request( string $kind, string $service, string $class ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'pkiw_settings', [ $kind . '_sync_to_' . $service => true ] );
		$term     = term_exists( $kind, 'kind' ) ?: wp_insert_term( ucfirst( $kind ), 'kind', [ 'slug' => $kind ] );
		$this->assertIsArray( $term );
		$disabled = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$enabled  = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_object_terms( $disabled, (int) $term['term_id'], 'kind' );
		wp_set_object_terms( $enabled, (int) $term['term_id'], 'kind' );
		$meta_key = Meta_Fields::PREFIX . 'syndicate_' . $service;
		update_post_meta( $enabled, $meta_key, false );

		$sync = $this->getMockBuilder( $class )
			->onlyMethods( [ 'is_connected', "get_{$kind}_data_from_post", "syndicate_{$kind}" ] )->getMock();
		$sync->method( 'is_connected' )->willReturn( true );
		$sync->method( "get_{$kind}_data_from_post" )->willReturn( [ 'track' => 'Track', 'artist' => 'Artist', 'title' => 'Title' ] );
		$sync->expects( $this->once() )->method( "syndicate_{$kind}" )->with( $enabled )->willReturn( false );
		$sync->init();

		foreach ( [ $disabled => false, $enabled => true ] as $post_id => $value ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
			$request->set_body_params( [ 'status' => 'publish', 'meta' => [ $meta_key => $value ] ] );
			$response = rest_do_request( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 'publish', get_post_status( $post_id ) );
			$this->assertSame( $value, $response->get_data()['meta'][ $meta_key ] ?? null );
		}
	}

	public static function syndication_services(): array {
		return [
			'Last.fm'    => [ 'listen', 'lastfm', Lastfm_Listen_Sync::class ],
			'Trakt'      => [ 'watch', 'trakt', Trakt_Watch_Sync::class ],
			'Foursquare' => [ 'checkin', 'foursquare', Foursquare_Checkin_Sync::class ],
		];
	}
}

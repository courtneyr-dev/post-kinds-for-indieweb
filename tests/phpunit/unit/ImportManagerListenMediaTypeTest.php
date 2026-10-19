<?php
/**
 * Import manager listen media type coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use PKIW\Import_Manager;
use PKIW\Listen_Media_Type;
use ReflectionMethod;
use WP_UnitTestCase;

final class ImportManagerListenMediaTypeTest extends WP_UnitTestCase {

	private Import_Manager $manager;

	public function set_up(): void {
		parent::set_up();
		$this->manager = new Import_Manager();
	}

	public function test_podcast_payload_carries_podcast_media_type(): void {
		[ , $meta ] = $this->listen_payload(
			[
				'episode_title' => 'How the Web Works',
				'show_name'     => 'Open Systems',
			]
		);

		$this->assertSame( Listen_Media_Type::PODCAST, $meta[ Listen_Media_Type::META ] );
	}

	public function test_lastfm_shaped_payload_carries_no_media_type(): void {
		[ , $meta ] = $this->listen_payload(
			[
				'track'  => 'One Tree Hill',
				'artist' => 'U2',
				'album'  => 'The Joshua Tree',
			]
		);

		$this->assertArrayNotHasKey( Listen_Media_Type::META, $meta );
	}

	public function test_podcast_metadata_update_writes_type_without_touching_modified_date(): void {
		global $wpdb;

		$post_id = self::factory()->post->create(
			[
				'post_date'     => '2025-01-02 03:04:05',
				'post_date_gmt' => '2025-01-02 03:04:05',
			]
		);
		$wpdb->update( $wpdb->posts, [ 'post_modified' => '2020-01-02 03:04:05' ], [ 'ID' => $post_id ], [ '%s' ], [ '%d' ] );
		clean_post_cache( $post_id );
		$modified = (string) get_post_field( 'post_modified', $post_id );

		$updated = $this->manager->update_post_metadata(
			$post_id,
			[
				'episode_title' => 'How the Web Works',
				'show_name'     => 'Open Systems',
			],
			[ 'kind' => 'listen' ]
		);

		$this->assertTrue( $updated );
		$this->assertSame( Listen_Media_Type::PODCAST, get_post_meta( $post_id, Listen_Media_Type::META, true ) );
		$this->assertSame( $modified, (string) get_post_field( 'post_modified', $post_id ) );
	}

	public function test_podcast_reimport_replaces_an_editor_selected_music_type(): void {
		$post_id = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		update_post_meta( $post_id, Listen_Media_Type::META, Listen_Media_Type::MUSIC );

		$this->manager->update_post_metadata(
			$post_id,
			[
				'episode_title' => 'A Podcast Episode',
				'show_name'     => 'A Podcast Show',
			],
			[ 'kind' => 'listen' ]
		);

		$this->assertSame( Listen_Media_Type::PODCAST, get_post_meta( $post_id, Listen_Media_Type::META, true ) );
	}

	/**
	 * Build a listen payload through the private router.
	 *
	 * @param array<string, mixed> $item Imported item.
	 * @return array{0:array<string, mixed>, 1:array<string, mixed>, 2:string}
	 */
	private function listen_payload( array $item ): array {
		$method = new ReflectionMethod( Import_Manager::class, 'build_listen_payload' );
		$method->setAccessible( true );

		/** @var array{0:array<string, mixed>, 1:array<string, mixed>, 2:string} $payload */
		$payload = $method->invoke( $this->manager, $item );
		return $payload;
	}
}

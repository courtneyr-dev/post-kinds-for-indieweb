<?php
/** Follow Standard Site no-contact coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Standard_Site;
use PKIW\Taxonomy;

/** @group integration */
final class FollowStandardSiteTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_follow_never_schedules_but_bookmark_positive_control_does(): void {
		$controls = [
			'publish'  => [ 'post_status' => 'publish' ],
			'private'  => [ 'post_status' => 'private' ],
			'draft'    => [ 'post_status' => 'draft' ],
			'pending'  => [ 'post_status' => 'pending' ],
			'future'   => [ 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ],
			'password' => [ 'post_status' => 'publish', 'post_password' => 'secret' ],
		];
		$follow_ids = [];
		foreach ( $controls as $case => $post ) {
			$follow = self::factory()->post->create( $post );
			$follow_ids[] = $follow;
			wp_set_object_terms( $follow, 'follow', Taxonomy::TAXONOMY );
			update_post_meta( $follow, '_pkiw_cite_url', 'https://' . $case . '.example/' );
			wp_update_post( [ 'ID' => $follow, 'post_title' => 'Saved ' . $case ] );
			$this->assertFalse( wp_next_scheduled( Standard_Site::CRON_HOOK, [ $follow ] ), $case . ' follow must not schedule.' );
		}

		$trash = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $trash, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $trash, '_pkiw_cite_url', 'https://trash.example/' );
		wp_trash_post( $trash );
		$this->assertFalse( wp_next_scheduled( Standard_Site::CRON_HOOK, [ $trash ] ) );

		$requests = 0;
		$recorder = static function () use ( &$requests ) {
			++$requests;
			return new WP_Error( 'blocked', 'No HTTP in follow tests.' );
		};
		add_filter( 'pre_http_request', $recorder );
		try {
			foreach ( $follow_ids as $follow_id ) {
				do_action( Standard_Site::CRON_HOOK, $follow_id );
			}
			do_action( Standard_Site::CRON_HOOK, $trash );
		} finally {
			remove_filter( 'pre_http_request', $recorder );
		}
		$this->assertSame( 0, $requests, 'Forced follow resolution must send zero HTTP requests.' );

		$bookmark = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $bookmark, 'bookmark', Taxonomy::TAXONOMY );
		update_post_meta( $bookmark, '_pkiw_cite_url', 'https://bookmark.example/' );
		Standard_Site::schedule_resolution( $bookmark, get_post( $bookmark ) );
		$this->assertNotFalse( wp_next_scheduled( Standard_Site::CRON_HOOK, [ $bookmark ] ) );
		wp_clear_scheduled_hook( Standard_Site::CRON_HOOK, [ $bookmark ] );
	}

	public function test_oembed_filter_refuses_hidden_tag_and_follow_posts(): void {
		foreach ( [ 'tag', 'follow' ] as $kind ) {
			$post_id = self::factory()->post->create( [ 'post_status' => 'private' ] );
			wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
			$this->assertFalse( apply_filters( 'is_post_embeddable', true, get_post( $post_id ) ), $kind );
		}

		$public = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $public, 'follow', Taxonomy::TAXONOMY );
		$this->assertTrue( apply_filters( 'is_post_embeddable', true, get_post( $public ) ), 'public follow control' );
	}

	public function test_filter_callbacks_normalize_third_party_input_types(): void {
		$follow = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $follow, 'follow', Taxonomy::TAXONOMY );
		$this->assertFalse( apply_filters( 'micropub_dynamic_render', null, get_post( $follow ) ) );

		$post = get_post( self::factory()->post->create( [ 'post_status' => 'publish' ] ) );
		$this->assertTrue( apply_filters( 'is_post_embeddable', 1, $post ) );
		$this->assertSame( [], PKIW\Follow_Archive::notify_links( null, $follow ) );
	}
}

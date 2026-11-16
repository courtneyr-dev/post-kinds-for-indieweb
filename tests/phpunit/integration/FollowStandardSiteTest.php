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
		$follow = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $follow, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $follow, '_pkiw_cite_url', 'https://follow.example/' );
		Standard_Site::schedule_resolution( $follow, get_post( $follow ) );
		$this->assertFalse( wp_next_scheduled( Standard_Site::CRON_HOOK, [ $follow ] ), 'Removing the governed-kind gate would schedule this event.' );

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
}

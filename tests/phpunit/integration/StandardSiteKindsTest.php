<?php
/**
 * Standard Site no-contact coverage for governed kinds.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Standard_Site;
use PKIW\Taxonomy;

/** @group integration */
final class StandardSiteKindsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_tag_and_follow_never_schedule_or_resolve_in_any_status(): void {
		foreach ( [ 'tag', 'follow' ] as $kind ) {
			foreach ( [ 'draft', 'private', 'publish' ] as $status ) {
				$id = self::factory()->post->create( [ 'post_status' => $status ] );
				wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
				update_post_meta( $id, '_pkiw_cite_url', 'https://target.example/' );
				Standard_Site::schedule_resolution( $id, get_post( $id ) );
				$this->assertFalse( wp_next_scheduled( Standard_Site::CRON_HOOK, [ $id ] ) );
				Standard_Site::resolve_post( $id );
				$this->assertNull( Standard_Site::get_post_document_uri( $id ) );
			}
		}
	}

	public function test_published_bookmark_control_still_schedules(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'bookmark', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_url', 'https://target.example/' );
		Standard_Site::schedule_resolution( $id, get_post( $id ) );
		$this->assertNotFalse( wp_next_scheduled( Standard_Site::CRON_HOOK, [ $id ] ) );
		wp_clear_scheduled_hook( Standard_Site::CRON_HOOK, [ $id ] );
	}
}

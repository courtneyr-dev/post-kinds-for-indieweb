<?php
/** Follow facts coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowFactsTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_stored_name_and_host_are_public_but_private_follow_is_empty(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Ada Lovelace' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://www.ada.example/' );
		$facts = Follow_Archive::facts( $id );
		$this->assertSame( 'Ada Lovelace', $facts['name'] );
		$this->assertSame( 'ada.example', $facts['host'] );
		$this->assertSame( 'A', $facts['initial'] );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		$this->assertSame( [], Follow_Archive::facts( $id ), 'Removing the facts gate would expose the private control.' );
	}
}

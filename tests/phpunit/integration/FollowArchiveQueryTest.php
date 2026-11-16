<?php
/** Follow archive query coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowArchiveQueryTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
	}

	public function test_initials_and_forced_public_query_contract(): void {
		$this->assertSame( 'É', Follow_Archive::initial( ' Émile' ) );
		$this->assertSame( '#', Follow_Archive::initial( '9th Street' ) );
		$args = Follow_Archive::index_query_args();
		$this->assertTrue( $args['pkiw_public_only'] );
		$this->assertArrayNotHasKey( 'post_status', $args, 'Adding a local status rule would fork the shared predicate.' );
		$this->assertSame( 1, $args['pkiw_follow_az'] );
	}

	public function test_purge_urls_preserve_shape_and_add_the_affected_letter(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, '_pkiw_cite_name', 'Zora' );
		update_post_meta( $post_id, '_pkiw_cite_url', 'https://zora.example/' );

		$paths  = [ 'urls' => [ home_url( '/existing/' ) ], 'regex' => [ '#existing#' ] ];
		$purged = Follow_Archive::purge_urls( $paths, get_post( $post_id ) );

		$this->assertSame( [ '#existing#' ], $purged['regex'], 'The shared purge regex list must pass through unchanged.' );
		$this->assertContains( home_url( '/existing/' ), $purged['urls'] );
		$this->assertContains( home_url( '/kind/follow/?pkiw_follow_initial=Z' ), $purged['urls'], 'Removing the letter URL would leave the affected archive cached.' );
		$this->assertCount( count( array_unique( $purged['urls'] ) ), $purged['urls'], 'Purge URLs must remain de-duplicated.' );
	}
}

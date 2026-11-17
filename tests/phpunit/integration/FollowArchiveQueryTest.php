<?php
/** Follow archive query coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Public_Eligibility;
use PKIW\Taxonomy;

/** @group integration */
final class FollowArchiveQueryTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		$this->set_permalink_structure( '' );
		parent::tear_down();
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

	public function test_archive_order_paginates_and_keeps_fallback_rows_last(): void {
		$names = [ 'Ada', 'Bea', 'Cleo', 'Dara', 'Émile', 'Farah', 'Gita', 'Hana' ];
		$ids   = [];
		foreach ( $names as $offset => $name ) {
			$ids[ $name ] = $this->follow( $name, 'https://' . strtolower( remove_accents( $name ) ) . '.example/', sprintf( '2026-01-%02d 10:00:00', $offset + 1 ) );
		}
		$host = $this->follow( '', 'https://www.iris.example/', '2026-01-20 10:00:00' );
		$url  = $this->follow( 'https://juno.example/', 'https://juno.example/', '2026-01-19 10:00:00' );
		$last = $this->follow( '', '', '2026-01-18 10:00:00' );

		$page_one = $this->archive_query( 1, 8 );
		$page_two = $this->archive_query( 2, 8 );
		$this->assertSame( array_values( array_slice( $ids, 0, 8 ) ), array_map( 'intval', $page_one->posts ), 'Page 1 must keep A-Z name order.' );
		$this->assertSame( [ $host, $url, $last ], array_map( 'intval', $page_two->posts ), 'Page 2 must continue with host fallback, URL-like host, then nameless.' );
	}

	public function test_letter_filters_keep_accents_and_pagination_query_value(): void {
		$e       = $this->follow( 'Eden', 'https://eden.example/' );
		$accent  = $this->follow( 'Émile', 'https://emile.example/' );
		$numeric = $this->follow( '9th Street', 'https://ninth.example/' );
		$index   = Follow_Archive::served_index();

		$this->assertSame( [ $accent ], $index['letters']['É']['ids'] );
		$this->assertSame( [ $e ], $index['letters'][ Follow_Archive::initial( 'e' ) ]['ids'] );
		$this->assertSame( [ $numeric ], $index['letters']['#']['ids'] );

		$this->go_to( home_url( '/?kind=follow&pkiw_follow_initial=%C3%89' ) );
		$this->assertSame( [ $accent ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'The É query must return only É rows.' );
		$this->go_to( home_url( '/?kind=follow&pkiw_follow_initial=e' ) );
		$this->assertSame( [ $e ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'A lowercase e query must normalize to E.' );
		$this->go_to( home_url( '/?kind=follow&pkiw_follow_initial=%23' ) );
		$this->assertSame( [ $numeric ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'The catch-all query must return digit-led names.' );

		$this->go_to( home_url( '/kind/follow/?pkiw_follow_initial=%C3%89' ) );
		$this->assertStringContainsString( 'pkiw_follow_initial=%C3%89', wp_specialchars_decode( get_pagenum_link( 2 ), ENT_QUOTES ), 'Pagination must retain the active accented letter.' );
	}

	public function test_public_index_sql_survives_admin_and_rest_edit_contexts(): void {
		global $wpdb;
		$expected = Public_Eligibility::sql( $wpdb->posts );
		$args     = Follow_Archive::index_query_args();
		$this->assertTrue( $args['pkiw_public_only'] );
		$this->assertArrayNotHasKey( 'post_status', $args );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );
		$this->assertStringContainsString( $expected, ( new WP_Query( $args ) )->request, 'Admin context must keep the shared SQL clause.' );
		set_current_screen( 'front' );

		$request = new WP_REST_Request( 'GET', '/pkiw-test/v1/follows' );
		$request->set_param( 'context', 'edit' );
		Public_Eligibility::rest_request_before( null, [], $request );
		try {
			$this->assertStringContainsString( $expected, ( new WP_Query( $args ) )->request, 'REST edit context must keep the shared SQL clause.' );
		} finally {
			Public_Eligibility::rest_request_after( null, [], $request );
		}
	}

	public function test_index_flushes_through_existing_post_and_term_hooks_only(): void {
		$this->assertFalse(
			has_action( 'transition_post_status', [ Follow_Archive::class, 'flush_index' ] ),
			'The follow index must not register a redundant transition_post_status callback.'
		);

		$future = self::factory()->post->create(
			[
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			]
		);
		wp_set_object_terms( $future, 'follow', Taxonomy::TAXONOMY );
		$this->prime_index();
		wp_publish_post( $future );
		$this->assertFalse( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'wp_publish_post() must flush the primed index.' );

		$this->prime_index();
		wp_update_post( [ 'ID' => $future, 'post_status' => 'private' ] );
		$this->assertFalse( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'A private status update must flush the primed index.' );

		wp_update_post( [ 'ID' => $future, 'post_status' => 'publish' ] );
		$this->prime_index();
		wp_update_post( [ 'ID' => $future, 'post_password' => 'secret' ] );
		$this->assertFalse( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'Setting a password must flush the primed index.' );

		$this->prime_index();
		wp_update_post( [ 'ID' => $future, 'post_password' => '' ] );
		$this->assertFalse( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'Clearing a password must flush the primed index.' );

		$this->prime_index();
		wp_trash_post( $future );
		$this->assertFalse( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'Trashing a follow must flush the primed index.' );

		$this->prime_index();
		wp_untrash_post( $future );
		$this->assertFalse( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'Untrashing a follow must flush the primed index.' );

		$this->prime_index();
		wp_remove_object_terms( $future, 'follow', Taxonomy::TAXONOMY );
		$this->assertFalse( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'Removing the follow term must flush the primed index.' );

		$like = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $like, 'like', Taxonomy::TAXONOMY );
		$this->prime_index();
		update_post_meta( $like, '_unrelated_control', 'unchanged' );
		$this->assertIsArray( get_transient( Follow_Archive::INDEX_TRANSIENT ), 'Unrelated meta on a like must keep the primed index.' );
	}

	private function prime_index(): void {
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
		Follow_Archive::index();
		$this->assertIsArray( get_transient( Follow_Archive::INDEX_TRANSIENT ) );
	}

	private function follow( string $name, string $url, string $date = '2026-01-01 10:00:00' ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => $date, 'post_title' => '' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		if ( '' !== $name ) {
			update_post_meta( $id, '_pkiw_cite_name', $name );
		}
		if ( '' !== $url ) {
			update_post_meta( $id, '_pkiw_cite_url', $url );
		}
		return (int) $id;
	}

	private function archive_query( int $paged, int $per_page ): WP_Query {
		return new WP_Query(
			[
				'post_type'      => 'post',
				'fields'         => 'ids',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'pkiw_follow_az' => 1,
				'tax_query'      => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'follow' ] ],
			]
		);
	}
}

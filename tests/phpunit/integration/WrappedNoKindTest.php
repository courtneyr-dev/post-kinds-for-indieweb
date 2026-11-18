<?php
/** Wrapped no-kind invariant coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Taxonomy;

/** @group integration */
final class WrappedNoKindTest extends WrappedTestCase {
	public function test_create_update_publish_and_hand_set_paths_end_with_no_kind(): void {
		$id = $this->wrapped_post( 'read', 2026, [], [ 'post_status' => 'draft' ] );
		$this->assertSame( [], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ), 'create' );
		wp_update_post( [ 'ID' => $id, 'post_title' => 'Updated Wrapped' ] );
		$this->assertSame( [], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ), 'update' );
		wp_publish_post( $id );
		$this->assertSame( [], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ), 'publish' );
		wp_set_object_terms( $id, 'read', Taxonomy::TAXONOMY );
		$this->assertSame( [], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ), 'hand set' );
	}

	public function test_rest_create_and_update_ignore_sent_note_kind(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$year    = (int) wp_date( 'Y' );
		$note    = get_term_by( 'slug', 'note', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $note );
		$note_id = $note instanceof WP_Term ? $note->term_id : 0;
		$content = '<!-- wp:post-kinds-indieweb/wrapped {"kind":"read","year":' . $year . '} /-->';
		$create  = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$create->set_header( 'Content-Type', 'application/json' );
		$create->set_body( wp_json_encode( [ 'status' => 'draft', 'title' => 'REST Wrapped', 'content' => $content, 'kind' => [ $note_id ] ] ) );
		$response = rest_get_server()->dispatch( $create );
		$this->assertSame( 201, $response->get_status(), 'REST create failed; response=' . print_r( $response->get_data(), true ) );
		$id = (int) $response->get_data()['id'];
		$this->assertSame( [], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ) );
		$update = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $id );
		$update->set_header( 'Content-Type', 'application/json' );
		$update->set_body( wp_json_encode( [ 'title' => 'REST Updated', 'kind' => [ $note_id ] ] ) );
		$update_response = rest_get_server()->dispatch( $update );
		$this->assertSame( 200, $update_response->get_status(), 'REST update failed; response=' . print_r( $update_response->get_data(), true ) );
		$this->assertSame( [], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ) );
	}

	public function test_wrapped_publish_and_unpublish_do_not_change_kind_archive_found_posts(): void {
		$this->kind_post( 'note' );
		$this->kind_post( 'read' );
		$before_note = ( new WP_Query( [ 'post_status' => 'publish', 'tax_query' => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'note' ] ] ] ) )->found_posts;
		$before_read = ( new WP_Query( [ 'post_status' => 'publish', 'tax_query' => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'read' ] ] ] ) )->found_posts;
		$id = $this->wrapped_post();
		foreach ( [ 'publish', 'draft' ] as $status ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => $status ] );
			$note = ( new WP_Query( [ 'post_status' => 'publish', 'tax_query' => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'note' ] ] ] ) )->found_posts;
			$read = ( new WP_Query( [ 'post_status' => 'publish', 'tax_query' => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => 'read' ] ] ] ) )->found_posts;
			$this->assertSame( $before_note, $note, $status . ' note' );
			$this->assertSame( $before_read, $read, $status . ' read' );
		}
	}
}

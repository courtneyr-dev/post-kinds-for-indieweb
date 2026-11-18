<?php
/** Wrapped surface-routing coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Post_Surface;

/** @group integration */
final class WrappedSurfaceTest extends WrappedTestCase {
	public function test_wrapped_wins_over_priority_ten_longform_surface_on_first_save(): void {
		$site_rule = static function ( string $surface, WP_Post $post ): string {
			return strlen( $post->post_content ) >= 4000 ? Post_Surface::MAIN : $surface;
		};
		add_filter( 'pkiw_post_surface', $site_rule, 10, 2 );
		try {
			$id = $this->wrapped_post( 'read', 2026, [], [ 'post_content' => '<!-- wp:post-kinds-indieweb/wrapped {"kind":"read","year":2026} /-->' . str_repeat( 'x', 4100 ) ] );
			$this->assertSame( Post_Surface::STREAM, get_post_meta( $id, '_pkiw_surface', true ) );
			$this->assertSame( Post_Surface::STREAM, Post_Surface::get( $id ) );
		} finally {
			remove_filter( 'pkiw_post_surface', $site_rule, 10 );
		}
	}

	public function test_promoted_wrapped_routes_to_main_and_stored_surface_queries_separate_it(): void {
		$stream = $this->wrapped_post();
		$main   = $this->wrapped_post( 'read', 2025 );
		update_post_meta( $main, 'pkiw_promote', '1' );
		$this->assertSame( Post_Surface::STREAM, get_post_meta( $stream, '_pkiw_surface', true ) );
		$this->assertSame( Post_Surface::MAIN, get_post_meta( $main, '_pkiw_surface', true ) );
		$stream_ids = get_posts( [ 'fields' => 'ids', 'post_status' => 'publish', 'meta_key' => '_pkiw_surface', 'meta_value' => Post_Surface::STREAM ] );
		$main_ids   = get_posts( [ 'fields' => 'ids', 'post_status' => 'publish', 'meta_key' => '_pkiw_surface', 'meta_value' => Post_Surface::MAIN ] );
		$this->assertContains( $stream, array_map( 'intval', $stream_ids ) );
		$this->assertNotContains( $stream, array_map( 'intval', $main_ids ) );
		$this->assertContains( $main, array_map( 'intval', $main_ids ), 'The pkiw_promote fixture must enter the main-surface query; main IDs=' . print_r( $main_ids, true ) );
	}
}

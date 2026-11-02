<?php
/** Presentation alternate-surface guards. */

declare(strict_types=1);

use PKIW\Presentation;

final class PresentationSurfacesTest extends WP_UnitTestCase {
	private function feed_document( string $url ): string {
		$this->go_to( $url );
		$this->assertTrue( is_feed() );
		ob_start();
		try {
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}

	public function test_feed_has_no_record_or_presentation_sentinel(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Feed talk', 'post_content' => '<p>Stored body.</p>' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'SENTINEL_EVENT' );
		foreach ( [ '/?feed=rss2', (string) get_term_feed_link( get_term_by( 'slug', 'presentation', 'kind' )->term_id, 'kind' ) ] as $url ) {
			$feed = $this->feed_document( $url );
			$this->assertStringContainsString( 'Stored body.', $feed );
			$this->assertStringNotContainsString( 'pkiw-presentation-record', $feed );
			$this->assertStringNotContainsString( 'SENTINEL_EVENT', $feed );
		}
	}

	public function test_markdown_and_atproto_requests_have_no_record(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		set_query_var( 'markdown_request', '1' );
		$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ) );
		set_query_var( 'markdown_request', '' );
		set_query_var( 'format', 'markdown' );
		$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ) );
		set_query_var( 'format', '' );
		set_query_var( 'atproto', '1' );
		$this->assertSame( 'Body', Presentation::prepend_record( 'Body' ) );
		set_query_var( 'atproto', null );
	}
}

<?php
/** Presentation facts. */

declare(strict_types=1);

use PKIW\Presentation;

final class PresentationFactsTest extends WP_UnitTestCase {

	public function test_full_facts_use_public_calendar_name_without_location(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'A talk', 'post_date' => '2026-04-05 12:00:00' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_calendar_source', 'my-calendar' );
		update_post_meta( $post_id, '_pkiw_presentation_calendar_event_id', 44 );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'Authored fallback' );
		update_post_meta( $post_id, '_pkiw_presentation_event_type', 'keynote' );
		$stub = static fn() => [ 'name' => 'Public event', 'url' => 'https://example.com/event', 'location' => 'Sentinel Hall', 'start' => '', 'end' => '' ];
		add_filter( 'pkiw_pre_calendar_event', $stub );
		$facts = Presentation::facts( $post_id );
		remove_filter( 'pkiw_pre_calendar_event', $stub );
		$this->assertSame( 'Public event', $facts['event_name'] );
		$this->assertSame( 'calendar', $facts['event_source'] );
		$this->assertSame( 'Keynote', $facts['event_type_label'] );
		$this->assertSame(
			[ 'event_name', 'event_url', 'event_source', 'event_type', 'event_type_label', 'slides_url', 'recording_url', 'date_iso', 'date_ymd', 'date_display', 'title' ],
			array_keys( $facts )
		);
		$this->assertStringNotContainsString( 'Sentinel Hall', wp_json_encode( $facts ) );
	}

	public function test_null_calendar_falls_back_and_unreadable_posts_return_empty(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'Sentinel locked' );
		$this->assertSame( [], Presentation::facts( $post_id ) );

		$public = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $public, 'presentation', 'kind' );
		update_post_meta( $public, '_pkiw_presentation_calendar_source', 'my-calendar' );
		update_post_meta( $public, '_pkiw_presentation_calendar_event_id', 999999 );
		update_post_meta( $public, '_pkiw_presentation_event_name', 'Authored fallback' );
		$this->assertSame( 'Authored fallback', Presentation::facts( $public )['event_name'] );
	}

	public function test_content_fallbacks_unmapped_type_and_empty_event_are_normalized(): void {
		$deck      = 'https://speakerdeck.com/example/content-only';
		$recording = 'https://wordpress.tv/2026/01/01/content-only/';
		$content   = '<!-- wp:embed {"url":"' . $deck . '","providerNameSlug":"speaker-deck"} --><figure></figure><!-- /wp:embed -->'
			. '<!-- wp:embed {"url":"' . $recording . '","providerNameSlug":"wordpress-tv"} --><figure></figure><!-- /wp:embed -->';
		$post_id   = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $content ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		delete_post_meta( $post_id, '_pkiw_presentation_slides_url' );
		delete_post_meta( $post_id, '_pkiw_presentation_recording_url' );
		update_post_meta( $post_id, '_pkiw_presentation_event_type', 'retired-type' );
		update_post_meta( $post_id, '_pkiw_presentation_event_url', 'https://example.com/orphaned-url' );

		$facts = Presentation::facts( $post_id );
		$this->assertSame( $deck, $facts['slides_url'] );
		$this->assertSame( $recording, $facts['recording_url'] );
		$this->assertSame( '', $facts['event_type'] );
		$this->assertSame( '', $facts['event_type_label'] );
		$this->assertSame( '', $facts['event_name'] );
		$this->assertSame( '', $facts['event_url'] );
		$this->assertSame( '', $facts['event_source'] );

		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', 'kind' );
		$this->assertSame( [], Presentation::facts( $note ) );
		$private = self::factory()->post->create( [ 'post_status' => 'private' ] );
		wp_set_object_terms( $private, 'presentation', 'kind' );
		$this->assertSame( [], Presentation::facts( $private ) );
	}
}

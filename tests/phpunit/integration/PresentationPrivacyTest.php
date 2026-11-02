<?php
/** Presentation privacy surfaces. */

declare(strict_types=1);

use PKIW\Presentation;
use PKIW\Presentation_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Abilities\Core_Abilities;

final class PresentationPrivacyTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Presentation_Archive() )->register();
		Presentation_Archive::register_entry_block();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	private function render_archive(): string {
		$link = get_term_link( 'presentation', Taxonomy::TAXONOMY );
		$this->assertIsString( $link );
		$this->go_to( $link );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-presentation.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	public function test_archive_hides_locked_and_private(): void {
		$locked = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret', 'post_title' => 'SENTINEL_LOCKED', 'post_date' => '2026-08-01 12:00:00' ] );
		$private = self::factory()->post->create( [ 'post_status' => 'private', 'post_title' => 'SENTINEL_PRIVATE', 'post_date' => '2026-08-02 12:00:00' ] );
		foreach ( [ $locked, $private ] as $post_id ) {
			wp_set_object_terms( $post_id, 'presentation', Taxonomy::TAXONOMY );
		}
		update_post_meta( $locked, '_pkiw_presentation_slides_url', 'https://sentinel-slides.example/locked' );
		update_post_meta( $locked, '_pkiw_presentation_event_name', 'SENTINEL_LOCKED_EVENT' );

		$html = $this->render_archive();
		$this->assertStringContainsString( 'pkiw-presentation-badge--protected', $html );
		$this->assertStringContainsString( 'SENTINEL_LOCKED', $html );
		$this->assertStringNotContainsString( 'SENTINEL_LOCKED_EVENT', $html );
		$this->assertStringNotContainsString( 'sentinel-slides.example', $html );
		$this->assertStringNotContainsString( 'SENTINEL_PRIVATE', $html );
	}

	public function test_get_post_meta_redacts_locked(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_object_terms( $post_id, 'presentation', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, '_pkiw_presentation_slides_url', 'https://sentinel-slides.example/locked' );

		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $post_id ] );
		$this->assertIsArray( $result );
		$this->assertSame( [], $result['meta'] );
		$this->assertStringNotContainsString( 'sentinel-slides', (string) wp_json_encode( $result ) );
	}

	public function test_calendar_location_never_prints(): void {
		$stub = static fn() => [ 'name' => 'Public event', 'url' => 'https://example.com/event', 'location' => 'Sentinel Hall, 1 Sentinel Way', 'start' => '', 'end' => '' ];
		add_filter( 'pkiw_pre_calendar_event', $stub );
		$event = Presentation::event_for( 'my-calendar', 9 );
		remove_filter( 'pkiw_pre_calendar_event', $stub );
		$html = Presentation::record_html( $event + [ 'title' => 'Talk', 'event_type' => '', 'event_type_label' => '', 'slides_url' => '', 'recording_url' => '', 'date_iso' => '', 'date_display' => '' ] );
		$this->assertSame( [ 'name', 'url', 'source' ], array_keys( $event ) );
		$this->assertStringNotContainsString( 'Sentinel Hall', wp_json_encode( $event ) . $html );
	}

	public function test_protected_facts_hide_registered_meta(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_slides_url', 'https://sentinel-slides.example/locked' );
		$this->assertSame( [], Presentation::facts( $post_id ) );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		$this->assertStringNotContainsString( 'sentinel-slides', apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) ) );
		$GLOBALS['wp_rest_server'] = null;
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$this->assertStringNotContainsString( 'sentinel-slides', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_location_is_absent_from_facts_single_mf2_and_rest(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Location-free talk', 'post_content' => '<p>Body</p>' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_calendar_source', 'my-calendar' );
		update_post_meta( $post_id, '_pkiw_presentation_calendar_event_id', 9 );
		$stub = static fn() => [ 'name' => 'Public event', 'url' => 'https://example.com/event', 'location' => 'SENTINEL_LOC', 'start' => 'private', 'end' => 'private' ];
		add_filter( 'pkiw_pre_calendar_event', $stub );
		try {
			$facts = Presentation::facts( $post_id );
			$this->go_to( get_permalink( $post_id ) );
			$GLOBALS['post'] = get_post( $post_id );
			$single = apply_filters( 'the_content', '<p>Body</p>' );
			$mf2    = ( new PKIW\Microformats() )->add_hidden_mf2_data( $single );
			$GLOBALS['wp_rest_server'] = null;
			$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data();
			$this->assertStringNotContainsString( 'SENTINEL_LOC', wp_json_encode( [ $facts, $single, $mf2, $rest ] ) );
		} finally {
			remove_filter( 'pkiw_pre_calendar_event', $stub );
		}
	}

	public function test_null_calendar_event_falls_back_to_authored_name(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_calendar_source', 'my-calendar' );
		update_post_meta( $post_id, '_pkiw_presentation_calendar_event_id', 999999 );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'Authored event' );
		$this->assertSame( 'Authored event', Presentation::facts( $post_id )['event_name'] );
	}
}

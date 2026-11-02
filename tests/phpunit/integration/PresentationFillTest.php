<?php
/** Presentation save-time fill. */

declare(strict_types=1);

use PKIW\Presentation;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

final class PresentationFillTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'presentation', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( $slug, Taxonomy::TAXONOMY );
			}
		}
	}

	public function test_fill_uses_first_deck_and_talk_recording_without_http(): void {
		$deck = 'https://speakerdeck.com/example/deck';
		$talk = 'https://wordpress.tv/2026/01/01/example-talk/';
		$body = '<!-- wp:embed {"url":"' . $deck . '","providerNameSlug":"speaker-deck"} --><figure></figure><!-- /wp:embed -->'
			. '<!-- wp:embed {"url":"' . $talk . '","providerNameSlug":"wordpress-tv"} --><figure></figure><!-- /wp:embed -->'
			. '<!-- wp:embed {"url":"https://youtube.com/watch?v=abc123","providerNameSlug":"youtube"} --><figure></figure><!-- /wp:embed -->';
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $body ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		$count = 0;
		$guard = static function () use ( &$count ) { ++$count; return new WP_Error( 'no_http' ); };
		add_filter( 'pre_http_request', $guard );
		Presentation::fill_on_save( $post_id, get_post( $post_id ) );
		remove_filter( 'pre_http_request', $guard );
		$this->assertSame( $deck, get_post_meta( $post_id, '_pkiw_presentation_slides_url', true ) );
		$this->assertSame( $talk, get_post_meta( $post_id, '_pkiw_presentation_recording_url', true ) );
		$this->assertSame( 0, $count );
	}

	public function test_fill_keeps_an_edited_value_and_ignores_other_kinds(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => '[ableplayer youtube-id="edited123"]' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_recording_url', 'https://example.com/edited' );
		Presentation::fill_on_save( $post_id, get_post( $post_id ) );
		$this->assertSame( 'https://example.com/edited', get_post_meta( $post_id, '_pkiw_presentation_recording_url', true ) );

		$other = self::factory()->post->create( [ 'post_content' => '[ableplayer youtube-id="other123"]' ] );
		wp_set_object_terms( $other, 'note', 'kind' );
		Presentation::fill_on_save( $other, get_post( $other ) );
		$this->assertSame( '', get_post_meta( $other, '_pkiw_presentation_recording_url', true ) );
	}

	public function test_ableplayer_fills_recording_and_revision_posts_write_nothing(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => '[ableplayer youtube-id="37404case"]' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		Presentation::fill_on_save( $post_id, get_post( $post_id ) );
		$this->assertStringContainsString( '37404case', (string) get_post_meta( $post_id, '_pkiw_presentation_recording_url', true ) );

		$revision_id = wp_insert_post(
			[
				'post_parent'  => $post_id,
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_name'    => $post_id . '-revision-v1',
				'post_content' => '[ableplayer youtube-id="revision"]',
			]
		);
		Presentation::fill_on_save( $revision_id, get_post( $revision_id ) );
		$this->assertSame( '', get_post_meta( $revision_id, '_pkiw_presentation_recording_url', true ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$autosave_id = wp_create_post_autosave(
			[
				'post_ID'      => $post_id,
				'post_content' => '[ableplayer youtube-id="autosave"]',
				'post_type'    => 'post',
			]
		);
		$this->assertNotWPError( $autosave_id );
		$this->assertIsInt( $autosave_id );
		$this->assertGreaterThan( 0, $autosave_id );
		$this->assertSame( $post_id, wp_is_post_autosave( $autosave_id ) );
		Presentation::fill_on_save( $autosave_id, get_post( $autosave_id ) );
		$this->assertSame( '', get_post_meta( $autosave_id, '_pkiw_presentation_recording_url', true ) );
	}

	public function test_classifier_first_save_is_filled_after_kind_sync(): void {
		$deck = 'https://speakerdeck.com/example/first-save';
		$body = '<!-- wp:embed {"url":"' . $deck . '","providerNameSlug":"speaker-deck"} --><figure></figure><!-- /wp:embed -->';
		$id   = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $body ] );
		$this->assertTrue( has_term( 'presentation', 'kind', $id ) );
		$this->assertSame( $deck, get_post_meta( $id, '_pkiw_presentation_slides_url', true ) );
	}
}

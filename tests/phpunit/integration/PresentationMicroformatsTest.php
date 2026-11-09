<?php
/** Presentation microformats. */

declare(strict_types=1);

final class PresentationMicroformatsTest extends WP_UnitTestCase {

	public function test_single_adds_slides_and_recording_once(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<p>Body</p>' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_slides_url', 'https://example.com/slides' );
		update_post_meta( $post_id, '_pkiw_presentation_recording_url', 'https://example.com/recording' );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		$html = ( new PKIW\Microformats() )->add_hidden_mf2_data( '<p>Body</p>' );
		$this->assertSame( 1, substr_count( $html, 'u-slides' ) );
		$this->assertSame( 1, substr_count( $html, 'u-recording' ) );
		$this->assertSame( 1, substr_count( ( new PKIW\Microformats() )->add_hidden_mf2_data( '<data class="u-slides"></data>' ), 'u-slides' ) );
		$this->assertSame( 1, substr_count( ( new PKIW\Microformats() )->add_hidden_mf2_data( '<data class="u-recording"></data>' ), 'u-recording' ) );

		wp_update_post( [ 'ID' => $post_id, 'post_password' => 'secret' ] );
		$GLOBALS['post'] = get_post( $post_id );
		$protected = ( new PKIW\Microformats() )->add_hidden_mf2_data( '<p>Body</p>' );
		$this->assertStringNotContainsString( 'u-slides', $protected );
		$this->assertStringNotContainsString( 'u-recording', $protected );

		$empty = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $empty, 'presentation', 'kind' );
		$this->go_to( get_permalink( $empty ) );
		$GLOBALS['post'] = get_post( $empty );
		$this->assertSame( '<p>Body</p>', ( new PKIW\Microformats() )->add_hidden_mf2_data( '<p>Body</p>' ) );
	}

	public function test_format_map_names_both_url_properties(): void {
		$format = ( new PKIW\Microformats() )->get_kind_format( 'presentation' );
		$this->assertSame( 'u-slides', $format['properties']['slides'] );
		$this->assertSame( 'u-recording', $format['properties']['recording'] );
	}
}

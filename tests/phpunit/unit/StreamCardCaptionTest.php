<?php
namespace PKIW\Tests\Unit;

use WP_UnitTestCase;

/**
 * @covers ::PKIW\stream_card_media_extras
 */
class StreamCardCaptionTest extends WP_UnitTestCase {
	public function test_caption_markup_is_rendered_not_escaped() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$att_id  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		wp_update_post( array( 'ID' => $att_id, 'post_excerpt' => 'Photo by <a href="https://example.test/" rel="nofollow">Someone</a> is licensed <em>CC0</em>' ) );
		set_post_thumbnail( $post_id, $att_id );
		$html = \PKIW\stream_card_media_extras( get_post( $post_id ) );
		$this->assertStringContainsString( '<a href="https://example.test/" rel="nofollow">Someone</a>', $html );
		$this->assertStringContainsString( '<em>CC0</em>', $html );
		$this->assertStringNotContainsString( '&lt;a href', $html );
	}

	public function test_caption_scripts_are_stripped() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$att_id  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		wp_update_post( array( 'ID' => $att_id, 'post_excerpt' => 'x<script>alert(1)</script><a href="javascript:alert(1)">y</a>' ) );
		set_post_thumbnail( $post_id, $att_id );
		$html = \PKIW\stream_card_media_extras( get_post( $post_id ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}
}

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

	/**
	 * `headingLevel` controls the Stream card title's heading tag, so a theme's
	 * own heading outline survives wherever the Stream sits on the page.
	 * Clamped 2–4; the default (unset) still emits `<h2>`.
	 *
	 * @covers ::PKIW\render_generic_stream_card
	 */
	public function test_heading_level_attribute_changes_title_tag() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Just an essay',
				'post_content' => "<!-- wp:paragraph -->\n<p>The full body of the essay.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = render_block(
			array(
				'blockName'    => 'post-kinds-indieweb/stream-card',
				'attrs'        => array( 'headingLevel' => 4 ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( '<h4 class="pk-title', $html );

		$default_html = render_block(
			array(
				'blockName'    => 'post-kinds-indieweb/stream-card',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( '<h2 class="pk-title', $default_html );
	}
}

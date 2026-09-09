<?php
/**
 * Coverage for captions and galleries on the Stream card.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * The Stream card shows the featured image only, so a caption written on the
 * attachment never reaches the feed and a six-photo post is indistinguishable
 * from a one-photo post.
 *
 * @group integration
 */
final class StreamCardCaptionsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Create an attachment parented to $post_id, with alt and caption.
	 */
	private function make_image( int $post_id, string $alt, string $caption ): int {
		$id = self::factory()->attachment->create_object(
			[
				'file'           => 'image-' . wp_generate_password( 6, false ) . '.jpg',
				'post_parent'    => $post_id,
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => $caption,
			]
		);
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return (int) $id;
	}

	public function test_card_shows_the_featured_images_caption(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Wild turkeys' ] );
		$hero    = $this->make_image( $post_id, 'Turkeys on a road', 'They own this street now' );
		set_post_thumbnail( $post_id, $hero );

		$html = PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertStringContainsString( 'pk-media__caption', $html );
		$this->assertStringContainsString( 'They own this street now', $html );
	}

	public function test_card_omits_the_caption_element_when_there_is_none(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'No caption' ] );
		$hero    = $this->make_image( $post_id, 'Some alt', '' );
		set_post_thumbnail( $post_id, $hero );

		$html = PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertStringNotContainsString( 'pk-media__caption', $html );
	}

	public function test_multi_image_post_renders_a_thumbnail_row(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Gallery post' ] );
		$hero    = $this->make_image( $post_id, 'Hero alt', 'Hero caption' );
		set_post_thumbnail( $post_id, $hero );
		$this->make_image( $post_id, 'Second alt', 'Second caption' );
		$this->make_image( $post_id, 'Third alt', 'Third caption' );

		$html = PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertStringContainsString( 'pk-media__thumbs', $html );
		// Thumbnails are buttons so they are reachable by keyboard, not just hover.
		$this->assertStringContainsString( '<button', $html );
		$this->assertStringContainsString( 'Second caption', $html );
	}

	public function test_single_image_post_renders_no_thumbnail_row(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Just one' ] );
		$hero    = $this->make_image( $post_id, 'Only alt', 'Only caption' );
		set_post_thumbnail( $post_id, $hero );

		$html = PKIW\render_generic_stream_card( get_post( $post_id ) );

		$this->assertStringNotContainsString( 'pk-media__thumbs', $html );
	}
}

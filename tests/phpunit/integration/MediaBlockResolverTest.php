<?php
/**
 * Media block resolver coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class MediaBlockResolverTest extends WP_UnitTestCase {

	/**
	 * Create a post containing block markup.
	 *
	 * @param string $content Block markup.
	 * @return WP_Post Post object.
	 */
	private function post_with_content( string $content ): WP_Post {
		return get_post(
			self::factory()->post->create(
				[
					'post_status'  => 'publish',
					'post_content' => $content,
				]
			)
		);
	}

	/**
	 * Create an attachment whose URL resolves back to its post ID.
	 *
	 * @param string $mime      Attachment MIME type.
	 * @param string $extension File extension.
	 * @return int Attachment ID.
	 */
	private function media_attachment( string $mime, string $extension ): int {
		$id   = self::factory()->attachment->create( [ 'post_mime_type' => $mime ] );
		$file = '2026/10/pkiw-media-' . $id . '.' . $extension;
		update_post_meta( $id, '_wp_attached_file', $file );

		return $id;
	}

	/**
	 * A core audio block.
	 *
	 * @param string   $src Audio source URL.
	 * @param int|null $id  Optional attachment ID attribute.
	 * @return string Block markup.
	 */
	private function audio_block( string $src, ?int $id = null ): string {
		$attrs = null === $id ? '' : ' {"id":' . $id . '}';

		return '<!-- wp:audio' . $attrs . ' --><figure class="wp-block-audio"><audio controls src="' . esc_attr( $src ) . '"></audio></figure><!-- /wp:audio -->';
	}

	/**
	 * A core embed block.
	 *
	 * @param string $provider Provider slug.
	 * @param string $url      Embed URL.
	 * @return string Block markup.
	 */
	private function embed_block( string $provider, string $url ): string {
		return '<!-- wp:embed {"url":"' . esc_url( $url ) . '","providerNameSlug":"' . esc_attr( $provider ) . '"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">' . esc_html( $url ) . '</div></figure><!-- /wp:embed -->';
	}

	public function test_document_order_wins_for_audio_shapes(): void {
		$audio = $this->audio_block( 'https://example.org/audio.mp3' );
		$embed = $this->embed_block( 'mixcloud', 'https://www.mixcloud.com/example/show/' );

		$this->assertSame( 'core/audio', \PKIW\first_media_block( $this->post_with_content( $audio . $embed ), 'audio' )['block'] );
		$this->assertSame( 'core/embed', \PKIW\first_media_block( $this->post_with_content( $embed . $audio ), 'audio' )['block'] );

		$paragraph = '<!-- wp:paragraph --><p><a class="u-audio" href="https://example.org/micropub.mp3">Listen</a></p><!-- /wp:paragraph -->';
		$this->assertSame( 'core/paragraph', \PKIW\first_media_block( $this->post_with_content( $paragraph . $audio ), 'audio' )['block'] );
	}

	public function test_a_span_u_audio_is_not_a_source(): void {
		$content = '<!-- wp:paragraph --><p><span class="u-audio">No anchor</span></p><!-- /wp:paragraph -->'
			. $this->audio_block( 'https://example.org/real.mp3' );

		$this->assertSame( 'core/audio', \PKIW\first_media_block( $this->post_with_content( $content ), 'audio' )['block'] );
	}

	public function test_nested_audio_is_found_depth_first(): void {
		$content = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:group --><div class="wp-block-group">' . $this->audio_block( 'https://example.org/nested.mp3' ) . '</div><!-- /wp:group -->'
			. '</div><!-- /wp:column --></div><!-- /wp:columns -->';

		$result = \PKIW\first_media_block( $this->post_with_content( $content ), 'audio' );

		$this->assertIsArray( $result );
		$this->assertSame( 'core/audio', $result['block'] );
		$this->assertSame( 'https://example.org/nested.mp3', $result['url'] );
	}

	public function test_a_valid_attachment_id_wins_over_a_stale_source(): void {
		$audio_id = $this->media_attachment( 'audio/mpeg', 'mp3' );
		$post     = $this->post_with_content( $this->audio_block( 'https://stale.example.org/old.mp3', $audio_id ) );

		$this->assertSame(
			[
				'type'          => 'attachment',
				'block'         => 'core/audio',
				'attachment_id' => $audio_id,
				'url'           => wp_get_attachment_url( $audio_id ),
				'provider'      => '',
			],
			\PKIW\first_media_block( $post, 'audio' )
		);
	}

	public function test_a_nonmatching_attachment_id_falls_back_to_the_stored_url(): void {
		$image_id = $this->media_attachment( 'image/jpeg', 'jpg' );
		$post     = $this->post_with_content( $this->audio_block( 'https://example.org/audio.mp3', $image_id ) );

		$result = \PKIW\first_media_block( $post, 'audio' );

		$this->assertIsArray( $result );
		$this->assertSame( 'url', $result['type'] );
		$this->assertSame( 0, $result['attachment_id'] );
		$this->assertSame( 'https://example.org/audio.mp3', $result['url'] );
	}

	public function test_a_source_url_can_resolve_to_an_attachment(): void {
		$audio_id = $this->media_attachment( 'audio/mpeg', 'mp3' );
		$url      = (string) wp_get_attachment_url( $audio_id );

		$result = \PKIW\first_media_block( $this->post_with_content( $this->audio_block( $url ) ), 'audio' );

		$this->assertIsArray( $result );
		$this->assertSame( 'attachment', $result['type'] );
		$this->assertSame( $audio_id, $result['attachment_id'] );
		$this->assertSame( $url, $result['url'] );
	}

	public function test_embed_provider_rules_differ_for_audio_and_video(): void {
		$mixcloud = $this->embed_block( 'mixcloud', 'https://www.mixcloud.com/example/show/' );
		$youtube  = $this->embed_block( 'youtube', 'https://www.youtube.com/watch?v=abcdefghijk' );

		$this->assertSame( 1, \PKIW\count_media_blocks( $this->post_with_content( $mixcloud ), 'audio' ) );
		$this->assertSame( 0, \PKIW\count_media_blocks( $this->post_with_content( $youtube ), 'audio' ) );
		$this->assertSame( 1, \PKIW\count_media_blocks( $this->post_with_content( $youtube ), 'video' ) );
	}

	public function test_nested_embed_precedes_a_later_video(): void {
		$embed   = $this->embed_block( 'youtube', 'https://www.youtube.com/watch?v=abcdefghijk' );
		$content = '<!-- wp:paragraph --><p>Introduction</p><!-- /wp:paragraph -->'
			. '<!-- wp:group --><div class="wp-block-group">' . $embed . '</div><!-- /wp:group -->'
			. '<!-- wp:video --><figure class="wp-block-video"><video controls src="https://example.org/video.mp4"></video></figure><!-- /wp:video -->';
		$post    = $this->post_with_content( $content );

		$media = \PKIW\first_media_block( $post, 'video' );
		$named = \PKIW\first_block_named( $post, [ 'core/embed' ] );

		$this->assertIsArray( $media );
		$this->assertSame( 'core/embed', $media['block'] );
		$this->assertSame( 'embed', $media['type'] );
		$this->assertIsArray( $named );
		$this->assertSame( 2, $named['index'] );
		$this->assertFalse( $named['top_level'] );
	}

	public function test_first_block_named_skips_blank_blocks_and_narrows_names(): void {
		$content = "Loose text\n" . $this->audio_block( 'https://example.org/audio.mp3' )
			. $this->embed_block( 'mixcloud', 'https://www.mixcloud.com/example/show/' );
		$post    = $this->post_with_content( $content );

		$result = \PKIW\first_block_named( $post, [ '', 'core/embed' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'core/embed', $result['block']['blockName'] );
		$this->assertSame( 1, $result['index'] );
		$this->assertTrue( $result['top_level'] );
	}

	public function test_a_synced_pattern_reference_is_not_expanded(): void {
		$pattern_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_content' => $this->audio_block( 'https://example.org/pattern.mp3' ),
			]
		);
		$post       = $this->post_with_content( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$this->assertNull( \PKIW\first_media_block( $post, 'audio' ) );
		$this->assertSame( 0, \PKIW\count_media_blocks( $post, 'audio' ) );
	}

	public function test_count_caps_at_ninety_nine(): void {
		$content = str_repeat( $this->audio_block( 'https://example.org/audio.mp3' ), 105 );

		$this->assertSame( 99, \PKIW\count_media_blocks( $this->post_with_content( $content ), 'audio' ) );
	}

	public function test_missing_posts_and_posts_without_players_return_nothing(): void {
		$post = $this->post_with_content( '<!-- wp:paragraph --><p>Nothing to play.</p><!-- /wp:paragraph -->' );

		$this->assertNull( \PKIW\first_media_block( $post, 'audio' ) );
		$this->assertNull( \PKIW\first_media_block( PHP_INT_MAX, 'audio' ) );
		$this->assertNull( \PKIW\first_media_block( $post, 'image' ) );
		$this->assertSame( 0, \PKIW\count_media_blocks( PHP_INT_MAX, 'audio' ) );
		$this->assertSame( 0, \PKIW\count_media_blocks( $post, 'image' ) );
	}
}

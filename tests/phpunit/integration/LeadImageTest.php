<?php
/**
 * Lead image and featured-in-content helpers (issue 241, W3-P0C).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * \PKIW\lead_image() picks the one picture a photo post leads with, and
 * \PKIW\featured_in_content() says whether the featured image already sits
 * in the body.
 *
 * @group integration
 */
final class LeadImageTest extends WP_UnitTestCase {

	/**
	 * Outbound HTTP requests seen during a test.
	 *
	 * @var int
	 */
	private int $http_calls = 0;

	public function set_up(): void {
		parent::set_up();
		$this->http_calls = 0;
		add_filter(
			'pre_http_request',
			function (): array {
				++$this->http_calls;
				return [];
			}
		);
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * An image attachment row with no file on disk.
	 *
	 * @param int    $parent  Parent post ID.
	 * @param string $alt     Stored alt text.
	 * @param string $caption Stored caption (post_excerpt).
	 * @param string $file    Path relative to the uploads directory.
	 * @param string $title   Attachment title.
	 * @return int Attachment ID.
	 */
	private function attachment( int $parent = 0, string $alt = '', string $caption = '', string $file = '', string $title = '' ): int {
		$file = '' === $file ? '2026/10/img-' . wp_generate_password( 8, false ) . '.jpg' : $file;
		$args = [
			'file'           => $file,
			'post_parent'    => $parent,
			'post_mime_type' => 'image/jpeg',
			'post_excerpt'   => $caption,
		];
		if ( '' !== $title ) {
			$args['post_title'] = $title;
		}
		$id = (int) self::factory()->attachment->create_object( $args );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * A core/image block for an attachment.
	 *
	 * @param int    $id      Attachment ID, or 0 for an id-less block.
	 * @param string $src     Image src.
	 * @param string $alt     Alt text in the markup.
	 * @param string $caption Figcaption HTML, or ''.
	 * @return string Block markup.
	 */
	private function image_block( int $id, string $src, string $alt = '', string $caption = '' ): string {
		$attrs = $id > 0 ? ' {"id":' . $id . '}' : '';
		$class = $id > 0 ? ' class="wp-image-' . $id . '"' : '';
		$cap   = '' === $caption ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';

		return '<!-- wp:image' . $attrs . ' --><figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"' . $class . '/>' . $cap . '</figure><!-- /wp:image -->';
	}

	/**
	 * A published photo post.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $args    Extra post arguments.
	 * @return int Post ID.
	 */
	private function photo_post( string $content = '', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Harbor at dusk',
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'photo', 'kind' );

		return (int) $id;
	}

	public function test_a_block_image_beats_an_attached_image_and_the_featured_image(): void {
		$post_id  = $this->photo_post();
		$block    = $this->attachment( $post_id, 'Block alt' );
		$attached = $this->attachment( $post_id );
		$featured = $this->attachment();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( $block, wp_get_attachment_url( $block ), 'Block alt' ),
			]
		);
		set_post_thumbnail( $post_id, $featured );

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( $block, $lead['attachment_id'] );
		$this->assertSame( 'block', $lead['source'] );
		$this->assertNotSame( $attached, $lead['attachment_id'] );
	}

	public function test_an_attached_image_beats_the_featured_image(): void {
		$post_id  = $this->photo_post( '<!-- wp:paragraph --><p>No image block.</p><!-- /wp:paragraph -->' );
		$first    = $this->attachment( $post_id );
		$second   = $this->attachment( $post_id );
		$featured = $this->attachment();
		set_post_thumbnail( $post_id, $featured );

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( $first, $lead['attachment_id'], 'Attached media sort by menu order, then ID.' );
		$this->assertSame( 'attached', $lead['source'] );
		$this->assertNotSame( $second, $lead['attachment_id'] );
	}

	public function test_the_featured_image_is_the_last_resort(): void {
		$post_id  = $this->photo_post( '<!-- wp:paragraph --><p>Words only.</p><!-- /wp:paragraph -->' );
		$featured = $this->attachment();
		set_post_thumbnail( $post_id, $featured );

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( $featured, $lead['attachment_id'] );
		$this->assertSame( 'featured', $lead['source'] );
	}

	public function test_a_post_with_no_image_gives_an_empty_lead(): void {
		$post_id = $this->photo_post( '<!-- wp:paragraph --><p>Words only.</p><!-- /wp:paragraph -->' );

		$this->assertSame(
			[
				'attachment_id' => 0,
				'alt'           => '',
				'caption'       => '',
				'source'        => '',
			],
			\PKIW\lead_image( $post_id )
		);
	}

	public function test_a_nested_gallery_image_counts(): void {
		$post_id = $this->photo_post();
		$first   = $this->attachment( $post_id );
		$second  = $this->attachment( $post_id );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">'
					. $this->image_block( $second, wp_get_attachment_url( $second ), 'Second' )
					. $this->image_block( $first, wp_get_attachment_url( $first ), 'First' )
					. '</figure><!-- /wp:gallery -->',
			]
		);

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( $second, $lead['attachment_id'], 'Saved order wins, not attachment order.' );
		$this->assertSame( 'block', $lead['source'] );
	}

	public function test_a_block_id_that_names_a_non_attachment_post_is_skipped(): void {
		$other    = self::factory()->post->create( [ 'post_title' => 'Not an attachment' ] );
		$post_id  = $this->photo_post();
		$attached = $this->attachment( $post_id );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( (int) $other, 'https://elsewhere.example/a.jpg', 'Stray' ),
			]
		);

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( $attached, $lead['attachment_id'] );
		$this->assertSame( 'attached', $lead['source'] );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_a_block_id_that_names_no_post_is_skipped(): void {
		$post_id = $this->photo_post( $this->image_block( 987654, 'https://elsewhere.example/a.jpg', 'Stray' ) );

		$this->assertSame( '', \PKIW\lead_image( $post_id )['source'] );
	}

	public function test_an_id_less_src_under_the_upload_base_resolves_to_its_attachment(): void {
		$post_id = $this->photo_post();
		$id      = $this->attachment( 0, 'Stored alt', '', '2026/10/harbor-lights.jpg' );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( 0, wp_get_attachment_url( $id ), 'Block alt' ),
			]
		);

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( $id, $lead['attachment_id'] );
		$this->assertSame( 'block', $lead['source'] );
		$this->assertSame( 'Block alt', $lead['alt'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function skipped_srcs(): array {
		return [
			'uploads path on another host' => [ 'https://old-host.example/wp-content/uploads/2026/10/harbor-lights.jpg' ],
			'remote image'                 => [ 'https://photos.example.net/harbor-lights.jpg' ],
			'relative path'                => [ '/wp-content/uploads/2026/10/harbor-lights.jpg' ],
		];
	}

	/**
	 * A src that doesn't sit under this site's upload base is not looked up
	 * and never fetched, even when an attachment with the same path exists.
	 *
	 * @dataProvider skipped_srcs
	 *
	 * @param string $src Image src.
	 */
	public function test_an_id_less_src_from_another_host_is_skipped_without_a_request( string $src ): void {
		$this->attachment( 0, '', '', '2026/10/harbor-lights.jpg' );
		$post_id = $this->photo_post( $this->image_block( 0, $src, 'Remote' ) );

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( '', $lead['source'] );
		$this->assertSame( 0, $lead['attachment_id'] );
		$this->assertSame( 0, $this->http_calls, 'No request leaves the site.' );
	}

	public function test_a_featured_id_that_names_no_attachment_is_skipped(): void {
		$post_id = $this->photo_post( '<!-- wp:paragraph --><p>Words only.</p><!-- /wp:paragraph -->' );
		update_post_meta( $post_id, '_thumbnail_id', 987654 );

		$this->assertSame( '', \PKIW\lead_image( $post_id )['source'] );

		$not_attachment = self::factory()->post->create( [ 'post_title' => 'A plain post' ] );
		update_post_meta( $post_id, '_thumbnail_id', $not_attachment );

		$this->assertSame( '', \PKIW\lead_image( $post_id )['source'] );
	}

	public function test_the_caption_is_the_figcaption_else_the_attachment_caption_else_empty(): void {
		$post_id = $this->photo_post();
		$id      = $this->attachment( $post_id, '', 'Attachment caption' );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( $id, wp_get_attachment_url( $id ), 'Alt', 'Figure <em>caption</em> by <a href="https://example.org/credit">Ana</a><span class="x"> here</span><script>alert(1)</script>' ),
			]
		);

		$caption = \PKIW\lead_image( $post_id )['caption'];

		$this->assertStringContainsString( 'Figure <em>caption</em> by <a href="https://example.org/credit">Ana</a>', $caption );
		$this->assertStringNotContainsString( '<span', $caption, 'kses drops tags outside the Stream caption allowlist.' );
		$this->assertStringNotContainsString( '<script', $caption );
		$this->assertStringNotContainsString( 'Attachment caption', $caption );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( $id, wp_get_attachment_url( $id ), 'Alt' ),
			]
		);
		$this->assertSame( 'Attachment caption', \PKIW\lead_image( $post_id )['caption'], 'No figcaption: the attachment caption.' );

		$bare_post = $this->photo_post();
		$this->attachment( $bare_post );
		$this->assertSame( '', \PKIW\lead_image( $bare_post )['caption'] );
	}

	public function test_alt_is_the_block_alt_else_the_attachment_alt_else_empty_and_never_the_title(): void {
		$post_id = $this->photo_post();
		$id      = $this->attachment( $post_id, 'Attachment alt', '', '', 'Attachment title' );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( $id, wp_get_attachment_url( $id ), 'Block alt' ),
			]
		);
		$this->assertSame( 'Block alt', \PKIW\lead_image( $post_id )['alt'] );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( $id, wp_get_attachment_url( $id ), '' ),
			]
		);
		$this->assertSame( 'Attachment alt', \PKIW\lead_image( $post_id )['alt'], 'An empty block alt falls through to the attachment.' );

		$bare_post = $this->photo_post();
		$this->attachment( $bare_post, '', '', '', 'Only a title' );
		$this->assertSame( '', \PKIW\lead_image( $bare_post )['alt'], 'The title never stands in for alt.' );
	}

	public function test_a_protected_post_gives_no_lead_image(): void {
		$post_id = $this->photo_post( '', [ 'post_password' => 'secret' ] );
		$this->attachment( $post_id, 'Hidden alt' );

		$lead = \PKIW\lead_image( $post_id );

		$this->assertSame( '', $lead['source'] );
		$this->assertSame( 0, $lead['attachment_id'] );
		$this->assertSame( '', $lead['alt'] );
	}

	public function test_a_private_post_gives_no_lead_image_to_a_logged_out_visitor(): void {
		$post_id = $this->photo_post( '', [ 'post_status' => 'private' ] );
		$this->attachment( $post_id, 'Hidden alt' );

		wp_set_current_user( 0 );
		$this->assertSame( '', \PKIW\lead_image( $post_id )['source'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'attached', \PKIW\lead_image( $post_id )['source'], 'An editor can see it.' );
	}

	public function test_a_missing_post_gives_an_empty_lead(): void {
		$this->assertSame( '', \PKIW\lead_image( 987654 )['source'] );
		$this->assertFalse( \PKIW\featured_in_content( 987654 ) );
	}

	public function test_featured_in_content_is_true_for_the_second_gallery_image(): void {
		$post_id  = $this->photo_post();
		$first    = $this->attachment( $post_id );
		$featured = $this->attachment( $post_id );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images">'
					. $this->image_block( $first, wp_get_attachment_url( $first ) )
					. $this->image_block( $featured, wp_get_attachment_url( $featured ) )
					. '</figure><!-- /wp:gallery -->',
			]
		);
		set_post_thumbnail( $post_id, $featured );

		$this->assertTrue( \PKIW\featured_in_content( $post_id ) );
	}

	public function test_featured_in_content_is_true_for_an_image_in_a_nested_group(): void {
		$post_id  = $this->photo_post();
		$featured = $this->attachment( $post_id );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
					. $this->image_block( $featured, wp_get_attachment_url( $featured ) )
					. '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->',
			]
		);
		set_post_thumbnail( $post_id, $featured );

		$this->assertTrue( \PKIW\featured_in_content( $post_id ) );
	}

	/**
	 * Attachment file, then the src a body block carries for it.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function resolvable_srcs(): array {
		return [
			'exact file'                         => [ '2026/10/lighthouse.jpg', '2026/10/lighthouse.jpg' ],
			'sub-size of the file'               => [ '2026/10/lighthouse.jpg', '2026/10/lighthouse-1024x768.jpg' ],
			'exact scaled file'                  => [ '2026/10/lighthouse-scaled.jpg', '2026/10/lighthouse-scaled.jpg' ],
			'sub-size of a scaled original'      => [ '2026/10/lighthouse-scaled.jpg', '2026/10/lighthouse-768x512.jpg' ],
			'unscaled name of a scaled original' => [ '2026/10/lighthouse-scaled.jpg', '2026/10/lighthouse.jpg' ],
		];
	}

	/**
	 * An id-less block counts as the featured image when its src is the
	 * featured file, a -WxH sub-size of it, or its -scaled twin.
	 *
	 * @dataProvider resolvable_srcs
	 *
	 * @param string $attached_file Featured attachment file.
	 * @param string $src_file      File the body block's src names.
	 */
	public function test_featured_in_content_resolves_scaled_and_sub_size_srcs( string $attached_file, string $src_file ): void {
		$post_id  = $this->photo_post();
		$featured = $this->attachment( 0, '', '', $attached_file );
		$base     = wp_get_upload_dir()['baseurl'];
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( 0, $base . '/' . $src_file ),
			]
		);
		set_post_thumbnail( $post_id, $featured );

		$this->assertTrue( \PKIW\featured_in_content( $post_id ) );
		$this->assertSame( $featured, \PKIW\lead_image( $post_id )['attachment_id'], 'The same resolver feeds the lead image.' );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_featured_in_content_is_false_otherwise(): void {
		$post_id  = $this->photo_post();
		$inside   = $this->attachment( $post_id );
		$featured = $this->attachment();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( $inside, wp_get_attachment_url( $inside ) ),
			]
		);

		$this->assertFalse( \PKIW\featured_in_content( $post_id ), 'No featured image.' );

		set_post_thumbnail( $post_id, $featured );
		$this->assertFalse( \PKIW\featured_in_content( $post_id ), 'A featured image outside the body.' );

		$words = $this->photo_post( '<!-- wp:paragraph --><p>Words only.</p><!-- /wp:paragraph -->' );
		set_post_thumbnail( $words, $featured );
		$this->assertFalse( \PKIW\featured_in_content( $words ), 'A body with no image block.' );
	}

	public function test_featured_in_content_is_false_for_a_post_the_visitor_cannot_read(): void {
		$post_id  = $this->photo_post( '', [ 'post_password' => 'secret' ] );
		$featured = $this->attachment( $post_id );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $this->image_block( $featured, wp_get_attachment_url( $featured ) ),
			]
		);
		set_post_thumbnail( $post_id, $featured );

		$this->assertFalse( \PKIW\featured_in_content( $post_id ) );
	}
}

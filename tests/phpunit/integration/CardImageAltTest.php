<?php
/**
 * Preview image alt coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class CardImageAltTest extends WP_UnitTestCase {

	/**
	 * @dataProvider preview_image_cards
	 *
	 * @param array<string, string> $attributes Base block attributes.
	 */
	public function test_preview_images_use_stored_alt_or_empty_alt( string $kind, array $attributes, string $alt_key ): void {
		$with_alt    = $this->render_card( $kind, array_merge( $attributes, [ $alt_key => 'A fictional preview' ] ) );
		$without_alt = $this->render_card( $kind, $attributes );

		$this->assertSame( 'A fictional preview', $this->image_alt( $with_alt ) );
		$this->assertSame( '', $this->image_alt( $without_alt ), 'The img must carry alt="", not omit the attribute.' );

		$parsed = \Mf2\parse( '<div class="h-entry">' . $without_alt . '</div>' );
		$photo  = $attributes[ 'acquisition' === $kind ? 'photo' : 'image' ];
		$this->assertStringContainsString( '"photo":[{"value":"' . $photo . '","alt":""}]', (string) wp_json_encode( $parsed, JSON_UNESCAPED_SLASHES ) );
		$this->assertStringNotContainsString( 'Preview image for', $without_alt );
		$this->assertStringNotContainsString( 'Cover of', $without_alt );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>, 2: string}>
	 */
	public function preview_image_cards(): array {
		return [
			'reply'       => [ 'reply', [ 'title' => 'Example', 'url' => 'https://example.com/reply', 'image' => 'https://example.org/reply.jpg' ], 'imageAlt' ],
			'like'        => [ 'like', [ 'title' => 'Example', 'url' => 'https://example.com/like', 'image' => 'https://example.org/like.jpg' ], 'imageAlt' ],
			'bookmark'    => [ 'bookmark', [ 'title' => 'Example', 'url' => 'https://example.com/bookmark', 'image' => 'https://example.org/bookmark.jpg' ], 'imageAlt' ],
			'favorite'    => [ 'favorite', [ 'title' => 'Example', 'url' => 'https://example.com/favorite', 'image' => 'https://example.org/favorite.jpg' ], 'imageAlt' ],
			'repost'      => [ 'repost', [ 'title' => 'Example', 'url' => 'https://example.com/repost', 'image' => 'https://example.org/repost.jpg' ], 'imageAlt' ],
			'wish'        => [ 'wish', [ 'title' => 'Example', 'url' => 'https://example.com/wish', 'image' => 'https://example.org/wish.jpg' ], 'imageAlt' ],
			'acquisition' => [ 'acquisition', [ 'title' => 'Example', 'photo' => 'https://example.org/acquisition.jpg' ], 'photoAlt' ],
		];
	}

	public function test_play_cover_keeps_its_box_art_fallback(): void {
		$html = $this->render_card( 'play', [ 'title' => 'Fictional Quest', 'cover' => 'https://example.org/play.jpg' ] );

		$this->assertSame( 'Box art for Fictional Quest', $this->image_alt( $html ) );
	}

	public function test_a_listen_cover_in_the_media_library_keeps_core_srcset_and_its_stored_url(): void {
		$image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$url      = wp_get_attachment_url( $image_id );
		$html     = $this->render_card(
			'listen',
			[
				'trackTitle'    => 'Fictional Song',
				'artistName'    => 'Fictional Band',
				'coverImage'    => $url,
				'coverImageAlt' => 'A fictional sleeve',
			]
		);

		$img = new WP_HTML_Tag_Processor( $html );
		$this->assertTrue( $img->next_tag( 'img' ) );
		$this->assertSame( $url, $img->get_attribute( 'src' ), 'At full size the src is the stored cover URL.' );
		$this->assertSame( 'u-photo', $img->get_attribute( 'class' ) );
		$this->assertSame( 'A fictional sleeve', $img->get_attribute( 'alt' ) );
		$this->assertSame( 'lazy', $img->get_attribute( 'loading' ) );
		$this->assertStringContainsString( wp_get_attachment_image_url( $image_id, 'medium' ) . ' ', (string) $img->get_attribute( 'srcset' ) );
		$this->assertNotEmpty( $img->get_attribute( 'sizes' ) );
		$this->assertSame( '640', $img->get_attribute( 'width' ) );
		$this->assertSame( '480', $img->get_attribute( 'height' ) );

		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$this->assertStringContainsString( '"photo":[{"value":"' . $url . '","alt":"A fictional sleeve"}]', (string) wp_json_encode( $parsed, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * @dataProvider stored_listen_cover_urls
	 *
	 * @param string $url Cover URL with no media library image behind it; a
	 *                    path starting with / is in this site's uploads.
	 */
	public function test_a_listen_cover_with_no_library_image_prints_as_stored( string $url ): void {
		if ( str_starts_with( $url, '/' ) ) {
			$url = wp_get_upload_dir()['baseurl'] . $url;
		}
		$html = $this->render_card(
			'listen',
			[
				'trackTitle' => 'Fictional Song',
				'artistName' => 'Fictional Band',
				'coverImage' => $url,
			]
		);

		$img = new WP_HTML_Tag_Processor( $html );
		$this->assertTrue( $img->next_tag( 'img' ) );
		$this->assertSame( $url, $img->get_attribute( 'src' ) );
		$this->assertNull( $img->get_attribute( 'srcset' ) );
		$this->assertSame( 'Fictional Song — Fictional Band', $img->get_attribute( 'alt' ) );
		$this->assertSame( 'lazy', $img->get_attribute( 'loading' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function stored_listen_cover_urls(): array {
		return [
			'hotlink from another host' => [ 'https://example.com/sleeve.jpg' ],
			'upload not in the library' => [ '/2026/10/not-in-the-library.jpg' ],
		];
	}

	/**
	 * @param array<string, string> $attributes Block attributes.
	 */
	private function render_card( string $kind, array $attributes ): string {
		return do_blocks( sprintf( '<!-- wp:post-kinds-indieweb/%s-card %s /-->', $kind, wp_json_encode( $attributes ) ) );
	}

	/**
	 * The img alt attribute as printed; null when the attribute is missing.
	 *
	 * @param string $html Rendered card.
	 * @return string|true|null
	 */
	private function image_alt( string $html ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$this->assertTrue( $processor->next_tag( 'img' ) );

		return $processor->get_attribute( 'alt' );
	}
}

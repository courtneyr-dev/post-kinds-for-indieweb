<?php
/**
 * Wish picture resolution across featured, card and meta sources.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Wish_Archive;

/**
 * Covers the single picture precedence contract used by every wish surface.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishPictureTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Prepare kind terms and registered meta.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Restore shared request state.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A published wish post.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return int Post ID.
	 */
	private function wish( array $args = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $args ) );
		wp_set_object_terms( $id, 'wish', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Create a real image attachment with optional alt text.
	 *
	 * @since 1.9.0
	 *
	 * @param string $alt Attachment alt text.
	 * @return int Attachment ID.
	 */
	private function image( string $alt = '' ): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		return $id;
	}

	/**
	 * A card image supplies its own URL and alt on a plain wish post.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_card_image_and_alt_are_returned(): void {
		$picture = Wish_Archive::picture(
			$this->wish(),
			[
				'image'    => 'https://images.example/lamp.jpg',
				'imageAlt' => 'A brass reading lamp',
			]
		);

		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( 'https://images.example/lamp.jpg', $picture['url'] );
		$this->assertSame( 'A brass reading lamp', $picture['alt'] );
		$this->assertTrue( $picture['remote'] );
	}

	/**
	 * Missing card alt remains empty without generated cover prose.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_empty_card_alt_stays_empty(): void {
		$picture = Wish_Archive::picture( $this->wish(), [ 'image' => 'https://images.example/lamp.jpg' ] );

		$this->assertSame( '', $picture['alt'] );
		$this->assertStringNotContainsString( 'Cover of', $picture['alt'] );
	}

	/**
	 * A valid featured image overrides a different card image.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_featured_image_wins_over_card_image(): void {
		$post_id       = $this->wish();
		$attachment_id = $this->image( 'Yellow canola flowers' );
		set_post_thumbnail( $post_id, $attachment_id );

		$picture = Wish_Archive::picture( $post_id, [ 'image' => 'https://images.example/card.jpg', 'imageAlt' => 'Card alt' ] );

		$this->assertSame( 'featured', $picture['source'] );
		$this->assertSame( $attachment_id, $picture['attachment_id'] );
		$this->assertSame( 'Yellow canola flowers', $picture['alt'] );
		$this->assertFalse( $picture['remote'] );
	}

	/**
	 * A card URL already in uploads resolves to its attachment.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_upload_card_image_is_a_local_cover(): void {
		$attachment_id = $this->image( 'Library alt is not used' );
		$url           = (string) wp_get_attachment_url( $attachment_id );
		$picture       = Wish_Archive::picture( $this->wish(), [ 'image' => $url, 'imageAlt' => 'Card-owned alt' ] );

		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( $attachment_id, $picture['attachment_id'] );
		$this->assertSame( $url, $picture['url'] );
		$this->assertSame( 'Card-owned alt', $picture['alt'] );
		$this->assertFalse( $picture['remote'] );
	}

	/**
	 * A remote card image is marked remote.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_remote_card_image_is_marked_remote(): void {
		$picture = Wish_Archive::picture( $this->wish(), [ 'image' => 'https://cdn.example/remote.jpg', 'imageAlt' => '' ] );

		$this->assertTrue( $picture['remote'] );
		$this->assertSame( 0, $picture['attachment_id'] );
	}

	/**
	 * Sidebar photo meta supplies the picture when the card has none.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_meta_only_photo_uses_kind_picture_result(): void {
		$post_id = $this->wish();
		update_post_meta( $post_id, '_pkiw_wish_photo', 'https://images.example/meta.jpg' );

		$picture = Wish_Archive::picture( $post_id, [] );

		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( 'https://images.example/meta.jpg', $picture['url'] );
		$this->assertSame( '', $picture['alt'] );
		$this->assertTrue( $picture['remote'] );
	}

	/**
	 * A thumbnail row for a missing file follows kind_picture's image check.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_missing_featured_file_matches_kind_picture_fallback(): void {
		$post_id       = $this->wish();
		$attachment_id = self::factory()->attachment->create_object(
			[
				'file'           => '2026/10/missing-wish.jpg',
				'post_mime_type' => 'image/jpeg',
			]
		);
		update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
		$base    = \PKIW\kind_picture( $post_id );
		$picture = Wish_Archive::picture( $post_id, [ 'image' => 'https://images.example/fallback.jpg', 'imageAlt' => 'Fallback' ] );

		if ( 'featured' === $base['source'] ) {
			$this->assertSame( $base, $picture );
			$this->assertSame( $attachment_id, $picture['attachment_id'] );
		} else {
			$this->assertSame( 'https://images.example/fallback.jpg', $picture['url'] );
			$this->assertSame( 'Fallback', $picture['alt'] );
		}
	}

	/**
	 * Post ID zero never resolves the current global post's featured image.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_zero_post_id_ignores_a_stale_global_post(): void {
		$post_id = $this->wish();
		set_post_thumbnail( $post_id, $this->image( 'Global featured image' ) );
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = get_post( $post_id );

		$picture = Wish_Archive::picture( 0, [ 'image' => 'https://example.org/a.jpg' ] );

		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( 'https://example.org/a.jpg', $picture['url'] );
		$this->assertSame( 0, $picture['attachment_id'] );
		$GLOBALS['post'] = $previous;
	}

	/**
	 * A non-web card image produces the empty picture shape.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_non_web_card_image_is_no_picture(): void {
		$this->assertSame(
			[ 'source' => '', 'attachment_id' => 0, 'url' => '', 'alt' => '', 'remote' => false, 'suppress_featured' => false ],
			Wish_Archive::picture( $this->wish(), [ 'image' => 'javascript:alert(1)', 'imageAlt' => 'Unsafe' ] )
		);
	}

	/**
	 * The picture resolver remains usable for protected rendering contexts.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_protected_post_picture_is_resolved_but_facts_are_hidden(): void {
		$post_id = $this->wish( [ 'post_password' => 'secret' ] );
		$card    = [ 'image' => 'https://images.example/protected.jpg', 'imageAlt' => 'Protected card image' ];

		$this->assertSame( 'https://images.example/protected.jpg', Wish_Archive::picture( $post_id, $card )['url'] );
		$this->assertSame( [], Wish_Archive::facts( $post_id ) );
	}

	/**
	 * Featured, card and meta sources keep their documented precedence.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_picture_order_is_featured_then_card_then_meta(): void {
		$post_id       = $this->wish();
		$attachment_id = $this->image( 'Featured alt' );
		update_post_meta( $post_id, '_pkiw_wish_photo', 'https://images.example/meta.jpg' );
		set_post_thumbnail( $post_id, $attachment_id );
		$card = [ 'image' => 'https://images.example/card.jpg', 'imageAlt' => 'Card alt' ];

		$this->assertSame( 'featured', Wish_Archive::picture( $post_id, $card )['source'] );
		delete_post_thumbnail( $post_id );
		$this->assertSame( 'https://images.example/card.jpg', Wish_Archive::picture( $post_id, $card )['url'] );
		$this->assertSame( 'https://images.example/meta.jpg', Wish_Archive::picture( $post_id, [] )['url'] );
	}
}

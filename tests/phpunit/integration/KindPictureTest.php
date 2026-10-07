<?php
/**
 * One picture per kind post: the featured image, else the card's cover.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Featured_Artwork;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * kind_picture() returns the featured image when the post has one, so a
 * remote host is asked for the cover only when there's no local copy, and
 * tells the theme when a template featured image would repeat it.
 *
 * @group integration
 */
final class KindPictureTest extends WP_UnitTestCase {

	/**
	 * Remote requests made during a test.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->requests = [];
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = $url;
				return new WP_Error( 'blocked', 'No network in tests.' );
			},
			10,
			3
		);
	}

	/**
	 * A published post of one kind with some card meta.
	 *
	 * @param string                $kind Kind slug.
	 * @param array<string, string> $meta Meta suffix => value.
	 * @param array<string, mixed>  $args Post args.
	 */
	private function kind_post( string $kind, array $meta = [], array $args = [] ): int {
		$post_id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $args ) );
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $value );
		}

		return $post_id;
	}

	/**
	 * An image attachment with stored alt text.
	 *
	 * @param string $alt Alt text.
	 */
	private function image( string $alt = '' ): int {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

		return $attachment_id;
	}

	public function test_the_featured_image_wins_over_a_remote_cover(): void {
		$post_id  = $this->kind_post( 'read', [ 'read_cover' => 'https://covers.example.org/b/id/1-L.jpg' ] );
		$image_id = $this->image( 'A yellow field of canola' );
		set_post_thumbnail( $post_id, $image_id );

		$picture = \PKIW\kind_picture( $post_id );

		$this->assertSame( 'featured', $picture['source'] );
		$this->assertSame( $image_id, $picture['attachment_id'] );
		$this->assertSame( wp_get_attachment_url( $image_id ), $picture['url'] );
		$this->assertSame( 'A yellow field of canola', $picture['alt'] );
		$this->assertFalse( $picture['remote'] );
		$this->assertTrue( $picture['suppress_featured'] );
		$this->assertSame( [], $this->requests );
	}

	public function test_a_featured_image_with_no_alt_gives_empty_alt(): void {
		$post_id = $this->kind_post( 'watch' );
		set_post_thumbnail( $post_id, $this->image() );

		$this->assertSame( '', \PKIW\kind_picture( $post_id )['alt'] );
	}

	public function test_with_no_featured_image_the_remote_cover_is_the_picture(): void {
		$post_id = $this->kind_post( 'read', [ 'read_cover' => 'https://covers.example.org/b/id/1-L.jpg' ] );

		$picture = \PKIW\kind_picture( $post_id );

		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( 0, $picture['attachment_id'] );
		$this->assertSame( 'https://covers.example.org/b/id/1-L.jpg', $picture['url'] );
		$this->assertSame( '', $picture['alt'] );
		$this->assertTrue( $picture['remote'] );
		$this->assertFalse( $picture['suppress_featured'] );
		$this->assertSame( [], $this->requests );
	}

	public function test_a_cover_in_the_media_library_is_local(): void {
		$image_id = $this->image( 'Front cover' );
		$post_id  = $this->kind_post( 'play', [ 'play_cover' => wp_get_attachment_url( $image_id ) ] );

		$picture = \PKIW\kind_picture( $post_id );

		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( $image_id, $picture['attachment_id'] );
		$this->assertFalse( $picture['remote'] );
		$this->assertSame( 'Front cover', $picture['alt'] );
	}

	public function test_a_sideloaded_copy_of_the_cover_is_used_after_its_featured_image_is_removed(): void {
		$cover    = 'https://coverartarchive.org/release/abc/front-500.jpg';
		$copy_id  = $this->image( 'Poster for Achtung Baby' );
		$post_id  = $this->kind_post( 'listen', [ 'listen_cover' => $cover ] );
		update_post_meta( $post_id, Featured_Artwork::SOURCE_META, $cover );
		update_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, $copy_id );

		$picture = \PKIW\kind_picture( $post_id );

		$this->assertSame( 'cover', $picture['source'] );
		$this->assertSame( $copy_id, $picture['attachment_id'] );
		$this->assertSame( wp_get_attachment_url( $copy_id ), $picture['url'] );
		$this->assertFalse( $picture['remote'] );
		$this->assertFalse( $picture['suppress_featured'] );
	}

	public function test_a_sideloaded_copy_of_an_older_cover_is_not_used(): void {
		$copy_id = $this->image();
		$post_id = $this->kind_post( 'listen', [ 'listen_cover' => 'https://coverartarchive.org/release/new/front-500.jpg' ] );
		update_post_meta( $post_id, Featured_Artwork::SOURCE_META, 'https://coverartarchive.org/release/old/front-500.jpg' );
		update_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, $copy_id );

		$picture = \PKIW\kind_picture( $post_id );

		$this->assertSame( 0, $picture['attachment_id'] );
		$this->assertTrue( $picture['remote'] );
	}

	public function test_a_comic_cover_carries_its_stored_alt(): void {
		$post_id = $this->kind_post(
			'comics',
			[
				'comic_cover'     => 'https://covers.example.org/comics/saga-1.jpg',
				'comic_cover_alt' => 'Two winged figures holding a baby',
			]
		);

		$this->assertSame( 'Two winged figures holding a baby', \PKIW\kind_picture( $post_id )['alt'] );
	}

	public function test_a_cover_that_is_not_a_web_url_is_no_picture(): void {
		$post_id = $this->kind_post( 'read' );
		// Stored straight to the database, past the registered sanitizer.
		add_metadata( 'post', $post_id, Meta_Fields::PREFIX . 'read_cover', 'javascript:alert(1)' );

		$this->assertSame( '', \PKIW\kind_picture( $post_id )['source'] );
	}

	public function test_card_block_attributes_are_never_read(): void {
		$post_id = $this->kind_post(
			'wish',
			[],
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/wish-card {"title":"Desk lamp","image":"https://shop.example.com/lamp.jpg"} /-->' ]
		);

		$this->assertSame( '', \PKIW\kind_picture( $post_id )['source'] );
	}

	public function test_a_post_with_no_picture_returns_the_empty_shape(): void {
		$this->assertSame(
			[
				'source'            => '',
				'attachment_id'     => 0,
				'url'               => '',
				'alt'               => '',
				'remote'            => false,
				'suppress_featured' => false,
			],
			\PKIW\kind_picture( $this->kind_post( 'note' ) )
		);
	}

	public function test_a_password_protected_post_shows_no_picture(): void {
		$post_id = $this->kind_post( 'read', [ 'read_cover' => 'https://covers.example.org/b/id/1-L.jpg' ], [ 'post_password' => 'secret' ] );

		$this->assertSame( '', \PKIW\kind_picture( $post_id )['source'] );
	}
}

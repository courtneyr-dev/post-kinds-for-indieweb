<?php
/**
 * Collection media privacy for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;
use PKIW\Taxonomy;

/**
 * An item's picture, external or an internal reference's featured image, prints only when
 * its attachment has no parent or a parent that passes the same public-reference gate.
 *
 * @group integration
 */
final class CollectionMediaPrivacyTest extends WP_UnitTestCase {

	/** Register kinds and reset request state. */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		update_option( 'date_format', 'F j, Y' );
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Restore request state. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/** One item block. */
	private function item( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** Create a published collection post. */
	private function collection( string $content ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Media cabinet',
				'post_content' => wp_slash( $content ),
			]
		);
		wp_set_object_terms( $id, Collection_Items::KIND, Taxonomy::TAXONOMY );
		Collection_Items::reset_cache();
		return $id;
	}

	/**
	 * An image attached to a parent post in the given state, with a recognisable alt text.
	 *
	 * @param string $status   Parent status, or 'none' for no parent.
	 * @param string $password Parent password.
	 */
	private function attachment( string $status, string $password = '' ): int {
		$parent = 0;
		if ( 'none' !== $status ) {
			$parent = self::factory()->post->create( [ 'post_status' => $status, 'post_password' => $password, 'post_title' => 'Parent ' . $status ] );
			if ( 'trash' === $status ) {
				wp_trash_post( $parent );
			}
		}
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Secret alt 240' );
		clean_post_cache( $id );
		Collection_Items::reset_cache();
		return $id;
	}

	/** Parent states that must hide the picture. */
	public static function hidden_parents(): array {
		return [
			'private'  => [ 'private', '' ],
			'draft'    => [ 'draft', '' ],
			'pending'  => [ 'pending', '' ],
			'password' => [ 'publish', 'secret' ],
			'trash'    => [ 'trash', '' ],
		];
	}

	/**
	 * Assert that no surface carries the attachment.
	 *
	 * @param int $collection Collection post ID.
	 */
	private function assert_no_media_anywhere( int $collection, int $attachment, bool $expect_item_visible ): void {
		$file = basename( (string) get_post_meta( $attachment, '_wp_attached_file', true ), '.jpg' );

		$html = do_blocks( (string) get_post_field( 'post_content', $collection ) );
		$this->assertStringNotContainsString( '<img', $html, 'render' );
		$this->assertStringNotContainsString( 'Secret alt 240', $html, 'render alt' );
		$this->assertStringNotContainsString( $file, $html, 'render file' );
		$this->assertSame( $expect_item_visible, str_contains( $html, 'pkiw-collection-item' ), 'item presence' );

		$this->assertSame( [], Collection_Items::preview_media( $collection ), 'preview_media' );
		foreach ( Collection_Items::items( $collection ) as $item ) {
			$this->assertSame( 0, $item['media_id'], 'items media_id' );
		}
		$facts = Collection_Items::facts( $collection );
		$this->assertSame( [], $facts['preview_media_ids'], 'facts preview' );
		$this->assertTrue( null === $facts['newest'] || 0 === $facts['newest']['media_id'], 'facts newest media' );

		$GLOBALS['post'] = get_post( $collection ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$stream = \PKIW\render_stream_card();
		$this->assertStringNotContainsString( '<img', $stream, 'stream card' );
		$this->assertStringNotContainsString( $file, $stream, 'stream card file' );
		$this->go_to( get_term_link( 'collection', Taxonomy::TAXONOMY ) );
		$GLOBALS['post'] = get_post( $collection ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$listing         = \PKIW\render_stream_card();
		$this->assertStringContainsString( 'pk-card--collection-listing', $listing );
		$this->assertStringNotContainsString( '<img', $listing, 'listing card' );
		$this->assertStringNotContainsString( $file, $listing, 'listing card file' );

		if ( function_exists( 'wp_get_ability' ) && wp_get_ability( 'post-kinds/get-collection-items' ) ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$result = wp_get_ability( 'post-kinds/get-collection-items' )->execute( [ 'post_id' => $collection ] );
			wp_set_current_user( 0 );
			$this->assertIsArray( $result );
			$this->assertSame( [ 0 ], array_values( array_unique( array_merge( [ 0 ], array_column( $result['items'], 'media_id' ) ) ) ), 'ability media_id' );
		}
	}

	/**
	 * An external item whose picture belongs to a hidden post keeps its text and loses the picture.
	 *
	 * @dataProvider hidden_parents
	 */
	public function test_external_media_of_a_hidden_parent_never_prints( string $status, string $password ): void {
		$attachment = $this->attachment( $status, $password );
		$id         = $this->collection( $this->item( [ 'title' => 'Kept caption', 'mediaId' => $attachment ] ) );

		$this->assert_no_media_anywhere( $id, $attachment, true );
		$this->assertStringContainsString( 'Kept caption', do_blocks( (string) get_post_field( 'post_content', $id ) ) );
	}

	/** A picture-only item with a hidden-parent picture is an empty item. */
	public function test_picture_only_item_with_a_hidden_parent_renders_nothing(): void {
		$attachment = $this->attachment( 'private' );
		$id         = $this->collection( $this->item( [ 'mediaId' => $attachment ] ) );

		$this->assertSame( '', trim( do_blocks( $this->item( [ 'mediaId' => $attachment ] ) ) ) );
		$this->assertSame( 0, Collection_Items::visible_count( $id ) );
		$this->assert_no_media_anywhere( $id, $attachment, false );
	}

	/** An internal reference's featured image follows the same parent gate. */
	public function test_featured_image_of_a_hidden_parent_never_prints(): void {
		$attachment = $this->attachment( 'private' );
		$reference  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Public reference' ] );
		set_post_thumbnail( $reference, $attachment );
		$id = $this->collection( $this->item( [ 'postId' => $reference ] ) );

		$this->assert_no_media_anywhere( $id, $attachment, true );
		$this->assertStringContainsString( 'Public reference', do_blocks( (string) get_post_field( 'post_content', $id ) ) );
	}

	/** Positive controls: no parent, or a public parent, still prints everywhere. */
	public function test_public_and_parentless_media_still_print(): void {
		foreach ( [ 'none', 'publish' ] as $status ) {
			$attachment = $this->attachment( $status );
			$id         = $this->collection( $this->item( [ 'title' => 'Shown caption', 'mediaId' => $attachment ] ) );

			$this->assertStringContainsString( '<img', do_blocks( (string) get_post_field( 'post_content', $id ) ), $status );
			$this->assertSame( [ $attachment ], Collection_Items::preview_media( $id ), $status );
			$this->assertSame( [ $attachment ], Collection_Items::facts( $id )['preview_media_ids'], $status );
			$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $GLOBALS['post'] );
			$this->assertStringContainsString( '<img', \PKIW\render_stream_card(), $status );
		}
	}

	/** The gate is live: hiding the parent after the first read removes the picture. */
	public function test_parent_turning_private_removes_the_picture(): void {
		$attachment = $this->attachment( 'publish' );
		$id         = $this->collection( $this->item( [ 'title' => 'Caption', 'mediaId' => $attachment ] ) );
		$this->assertSame( [ $attachment ], Collection_Items::preview_media( $id ) );

		wp_update_post( [ 'ID' => wp_get_post_parent_id( $attachment ), 'post_status' => 'private' ] );
		$this->assertSame( [], Collection_Items::preview_media( $id ) );
	}
}

<?php
/**
 * Collection resolver coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;

/**
 * Collection item resolution filters hidden references before position or summary data exists.
 *
 * @group integration
 */
final class CollectionResolverTest extends WP_UnitTestCase {

	/** Real image attachments for preview order assertions. */
	private array $images = [];

	/** Create media and reset request-local state. */
	public function set_up(): void {
		parent::set_up();
		update_option( 'date_format', 'F j, Y' );
		$this->set_permalink_structure( '/%postname%/' );
		for ( $i = 0; $i < 6; ++$i ) {
			$this->images[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		}
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Restore global request state. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/** A serialized collection item block. */
	private function item( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** A serialized cabinet group containing item markup. */
	private function cabinet( string $items ): string {
		return '<!-- wp:group {"className":"pkiw-collection"} --><div class="wp-block-group pkiw-collection">' . $items . '</div><!-- /wp:group -->';
	}

	/** Create a post with the supplied status and visibility controls. */
	private function reference( string $status = 'publish', string $password = '' ): int {
		return self::factory()->post->create(
			[
				'post_status'   => $status,
				'post_title'    => ucfirst( $status ) . ' reference',
				'post_password' => $password,
				'post_date'     => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : current_time( 'mysql' ),
			]
		);
	}

	/** Public references stay public even when an administrator is logged in. */
	public function test_public_reference_gate_has_no_editor_override(): void {
		$cases = [
			'draft'   => $this->reference( 'draft' ),
			'pending' => $this->reference( 'pending' ),
			'private' => $this->reference( 'private' ),
			'future'  => $this->reference( 'future' ),
			'password'=> $this->reference( 'publish', 'secret' ),
			'trash'   => $this->reference( 'publish' ),
			'publish' => $this->reference( 'publish' ),
		];
		wp_trash_post( $cases['trash'] );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			foreach ( [ 'draft', 'pending', 'private', 'future', 'password', 'trash' ] as $hidden ) {
				$this->assertFalse( Collection_Items::is_public_reference( $cases[ $hidden ] ), $hidden );
			}
			$this->assertFalse( Collection_Items::is_public_reference( 999999 ), 'missing' );
			$this->assertTrue( Collection_Items::is_public_reference( $cases['publish'] ) );
		}
	}

	/** A published post of a type with no public page is not a public reference. */
	public function test_published_post_of_a_non_public_type_is_not_a_reference(): void {
		register_post_type( 'pkiw240_private', [ 'public' => false ] );
		$id = self::factory()->post->create( [ 'post_type' => 'pkiw240_private', 'post_status' => 'publish' ] );

		$this->assertFalse( Collection_Items::is_public_reference( $id ) );
		unregister_post_type( 'pkiw240_private' );
	}

	/** Only the publish status counts: an attachment of a public parent is viewable but inherits its status. */
	public function test_inherit_status_attachment_is_not_a_reference(): void {
		$parent     = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent );

		wp_update_post( [ 'ID' => $attachment, 'post_status' => 'inherit' ] );
		clean_post_cache( $attachment );
		$this->assertSame( 'inherit', get_post( $attachment )->post_status );
		$this->assertTrue( is_post_publicly_viewable( $attachment ) );
		$this->assertFalse( Collection_Items::is_public_reference( $attachment ) );
	}

	/** Items flatten across cabinets, stray blocks, and nested groups without leaking gaps. */
	public function test_items_keep_document_order_and_position_only_visible_rows(): void {
		$hidden = $this->reference( 'draft' );
		$content = $this->cabinet(
			$this->item( [ 'title' => 'First' ] )
			. $this->item( [ 'postId' => $hidden ] )
		)
		. $this->item( [ 'title' => 'Second' ] )
		. $this->cabinet(
			'<!-- wp:group --><div class="wp-block-group">'
			. $this->item( [ 'title' => 'Third' ] )
			. '</div><!-- /wp:group -->'
		);
		$collection = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => wp_slash( $content ) ] );

		$items = Collection_Items::items( $collection );
		$this->assertSame( [ 'First', 'Second', 'Third' ], array_column( $items, 'title' ) );
		$this->assertSame( [ 1, 2, 3 ], array_column( $items, 'position' ) );
		$this->assertSame( 3, Collection_Items::visible_count( $collection ) );
	}

	/** Preview media follows visible document order, skips gaps, and stops at four. */
	public function test_preview_media_uses_the_first_four_visible_images(): void {
		$hidden = $this->reference( 'draft' );
		set_post_thumbnail( $hidden, $this->images[0] );
		$items = $this->item( [ 'mediaId' => $this->images[1] ] )
			. $this->item( [ 'title' => 'No image' ] )
			. $this->item( [ 'postId' => $hidden ] );
		foreach ( array_slice( $this->images, 2 ) as $image_id ) {
			$items .= $this->item( [ 'mediaId' => $image_id ] );
		}
		$collection = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => wp_slash( $items ) ] );

		$this->assertSame( [ $this->images[1], $this->images[2], $this->images[3], $this->images[4] ], Collection_Items::preview_media( $collection ) );
		$this->assertNotContains( $this->images[0], Collection_Items::preview_media( $collection, 10 ) );
	}

	/** Newest uses addedAt, breaks ties by later position, and falls back to the last visible item. */
	public function test_newest_item_rules(): void {
		$c07 = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$this->item( [ 'title' => 'Rain at the window', 'addedAt' => '2026-10-01T10:00:00Z' ] )
					. $this->item( [ 'title' => 'Morning birds', 'addedAt' => '2026-10-02T10:00:00Z' ] )
					. $this->item( [ 'title' => 'Old reel', 'addedAt' => '2026-10-05T10:00:00Z' ] )
				),
			]
		);
		$this->assertSame( 'Old reel', Collection_Items::newest( $c07 )['title'] );

		$c08 = self::factory()->post->create( [ 'post_content' => wp_slash( $this->item( [ 'title' => 'One' ] ) . $this->item( [ 'title' => 'Two' ] ) . $this->item( [ 'title' => 'Three' ] ) ) ] );
		$this->assertSame( 'Three', Collection_Items::newest( $c08 )['title'] );

		$c09 = self::factory()->post->create( [ 'post_content' => wp_slash( $this->item( [ 'title' => 'Earlier position', 'addedAt' => '2026-10-05T10:00:00Z' ] ) . $this->item( [ 'title' => 'Later position', 'addedAt' => '2026-10-05T10:00:00Z' ] ) ) ] );
		$this->assertSame( 'Later position', Collection_Items::newest( $c09 )['title'] );

		$empty = self::factory()->post->create( [ 'post_content' => '' ] );
		$this->assertNull( Collection_Items::newest( $empty ) );
	}

	/** A hidden newer reference cannot win newest. */
	public function test_newest_skips_hidden_references(): void {
		$hidden = $this->reference( 'draft' );
		$collection = self::factory()->post->create(
			[
				'post_content' => wp_slash(
					$this->item( [ 'title' => 'Visible', 'addedAt' => '2026-10-01T10:00:00Z' ] )
					. $this->item( [ 'postId' => $hidden, 'addedAt' => '2026-10-20T10:00:00Z' ] )
				),
			]
		);
		$this->assertSame( 'Visible', Collection_Items::newest( $collection )['title'] );
	}

	/** Post cache hooks invalidate a collection after its reference becomes hidden. */
	public function test_cache_invalidation_drops_a_reference_after_status_change(): void {
		$reference  = $this->reference( 'publish' );
		$collection = self::factory()->post->create( [ 'post_content' => wp_slash( $this->item( [ 'postId' => $reference ] ) ) ] );

		$this->assertCount( 1, Collection_Items::items( $collection ) );
		wp_update_post( [ 'ID' => $reference, 'post_status' => 'draft' ] );
		$this->assertSame( [], Collection_Items::items( $collection ) );
	}
}

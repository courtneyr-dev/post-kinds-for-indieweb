<?php
/**
 * Collection fact coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;
use PKIW\Kind_Facts;
use PKIW\Taxonomy;

/**
 * Collection facts expose summaries, not storage or referenced private fields.
 *
 * @group integration
 */
final class CollectionFactsTest extends WP_UnitTestCase {

	/** Register kinds and reset request state. */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		update_option( 'date_format', 'F j, Y' );
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Reset the current user. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** One serialized item block. */
	private function item( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** Create a collection post with a number of public external items. */
	private function collection( int $count, array $post_args = [] ): int {
		$content = '';
		for ( $i = 1; $i <= $count; ++$i ) {
			$content .= $this->item( [ 'title' => 'Item ' . $i, 'addedAt' => '2026-10-0' . min( $i, 9 ) . 'T10:00:00Z' ] );
		}
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Desk Tools',
					'post_content' => wp_slash( $content ),
				],
				$post_args
			)
		);
		wp_set_object_terms( $id, Collection_Items::KIND, Taxonomy::TAXONOMY );

		return $id;
	}

	/** Facts use the exact public summary schema and the registered reader agrees. */
	public function test_facts_have_the_exact_keys_and_registered_reader_matches(): void {
		$id    = $this->collection( 1 );
		$facts = Collection_Items::facts( $id );
		$this->assertSame(
			[ 'name', 'name_is_title', 'kind_label', 'item_count', 'item_count_label', 'preview_media_ids', 'newest', 'published_iso' ],
			array_keys( $facts )
		);
		$this->assertSame( $facts, Kind_Facts::get( $id ) );
	}

	/** Logged-out facts hide password-protected and private collections. */
	public function test_hidden_collections_have_no_facts(): void {
		$protected = $this->collection( 1, [ 'post_password' => 'secret' ] );
		$private   = $this->collection( 1, [ 'post_status' => 'private' ] );

		$this->assertSame( [], Collection_Items::facts( $protected ) );
		$this->assertSame( [], Collection_Items::facts( $private ) );
	}

	/** Item count copy handles zero, one, and plural counts. */
	public function test_item_count_labels(): void {
		$this->assertSame( '', Collection_Items::facts( $this->collection( 0 ) )['item_count_label'] );
		$this->assertSame( '1 item', Collection_Items::facts( $this->collection( 1 ) )['item_count_label'] );
		$this->assertSame( '5 items', Collection_Items::facts( $this->collection( 5 ) )['item_count_label'] );
	}

	/** Facts never expose storage-only, location, cost, or positional data. */
	public function test_facts_omit_private_and_storage_fields_at_every_depth(): void {
		$facts   = Collection_Items::facts( $this->collection( 2 ) );
		$encoded = wp_json_encode( $facts );
		foreach ( [ 'added_at', 'addedAt', 'location', 'cost', 'position' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $encoded, $forbidden );
		}
	}

	/** A real title is an entry name; an untitled fallback is not p-name material. */
	public function test_name_is_title_tracks_the_stored_post_title(): void {
		$titled   = Collection_Items::facts( $this->collection( 0, [ 'post_title' => 'Desk Tools' ] ) );
		$untitled = Collection_Items::facts( $this->collection( 0, [ 'post_title' => '' ] ) );

		$this->assertTrue( $titled['name_is_title'] );
		$this->assertSame( 'Desk Tools', $titled['name'] );
		$this->assertFalse( $untitled['name_is_title'] );
		$this->assertNotSame( '', $untitled['name'] );
	}
}

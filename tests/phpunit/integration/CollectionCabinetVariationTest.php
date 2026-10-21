<?php
/**
 * Collection cabinet behavior for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;

/**
 * The core Group variation seeds a cabinet and empty cabinets disappear.
 *
 * @group integration
 */
final class CollectionCabinetVariationTest extends WP_UnitTestCase {

	/** Reset resolver state. */
	public function set_up(): void {
		parent::set_up();
		Collection_Items::reset_cache();
		wp_set_current_user( 0 );
	}

	/** One parsed item block. */
	private function item( array $attributes ): array {
		return parse_blocks( '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->' )[0];
	}

	/** The Group variation carries its stable class, name, and one seeded item. */
	public function test_core_group_gets_the_collection_variation(): void {
		$group = WP_Block_Type_Registry::get_instance()->get_registered( 'core/group' );
		$this->assertInstanceOf( WP_Block_Type::class, $group );
		$variations = apply_filters( 'get_block_type_variations', [], $group );
		$collection = array_values( array_filter( $variations, static fn( $variation ) => 'pkiw-collection' === ( $variation['name'] ?? '' ) ) );

		$this->assertCount( 1, $collection );
		$this->assertSame( 'pkiw-collection', $collection[0]['attributes']['className'] );
		$this->assertSame( 'Collection', $collection[0]['attributes']['metadata']['name'] );
		$this->assertSame( [ [ Collection_Items::ITEM_BLOCK, [] ] ], $collection[0]['innerBlocks'] );
	}

	/** Block types other than core/group receive no collection variation. */
	public function test_other_block_types_are_unchanged(): void {
		$paragraph = WP_Block_Type_Registry::get_instance()->get_registered( 'core/paragraph' );
		$this->assertInstanceOf( WP_Block_Type::class, $paragraph );
		$before = [ [ 'name' => 'existing' ] ];
		$this->assertSame( $before, Collection_Items::cabinet_variation( $before, $paragraph ) );
	}

	/** Hidden-only cabinets disappear, visible cabinets remain, and unrelated groups are byte-identical. */
	public function test_drop_empty_cabinet_only_owns_collection_groups(): void {
		$hidden = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Hidden reference' ] );
		$empty  = [
			'blockName'   => 'core/group',
			'attrs'       => [ 'className' => 'other pkiw-collection' ],
			'innerBlocks' => [ $this->item( [ 'postId' => $hidden ] ) ],
		];
		$this->assertSame( '', Collection_Items::drop_empty_cabinet( '<div class="original">Hidden</div>', $empty ) );

		$visible = $empty;
		$visible['innerBlocks'][] = $this->item( [ 'title' => 'Visible item' ] );
		$html = '<div class="original">Visible</div>';
		$this->assertSame( $html, Collection_Items::drop_empty_cabinet( $html, $visible ) );

		$plain = $empty;
		$plain['attrs']['className'] = 'plain-group';
		$this->assertSame( $html, Collection_Items::drop_empty_cabinet( $html, $plain ) );

		$other = $empty;
		$other['blockName'] = 'core/columns';
		$this->assertSame( $html, Collection_Items::drop_empty_cabinet( $html, $other ) );
	}
}

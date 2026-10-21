<?php
/**
 * Collection ability coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;
use PKIW\Taxonomy;

/**
 * The collection read ability returns the public resolver shape and enforces post access.
 *
 * @group integration
 */
final class CollectionAbilityTest extends WP_UnitTestCase {

	/** Register kinds and reset users. */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Reset current user. */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Sign in a subscriber: the read capability is the floor for every ability call. */
	private function as_reader(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
	}

	/** One item block. */
	private function item( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** Create a collection post. */
	private function collection( string $content, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Desk Tools',
					'post_content' => wp_slash( $content ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, Collection_Items::KIND, Taxonomy::TAXONOMY );
		return $id;
	}

	/** Skip when WordPress lacks the Abilities API. */
	private function ability(): WP_Ability {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version.' );
		}
		$ability = wp_get_ability( 'post-kinds/get-collection-items' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability;
	}

	/** Registration exposes a read-only, public ability with the promised schema. */
	public function test_registration_meta_and_output_schema(): void {
		$ability = $this->ability();
		$meta    = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertTrue( $meta['mcp']['public'] );
		$this->assertSame( [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ], $meta['annotations'] );

		$schema = $ability->get_output_schema();
		$this->assertSame( [ 'post_id', 'count', 'newest_position', 'items' ], array_keys( $schema['properties'] ) );
		$this->assertSame( [ 'position', 'post_id', 'title', 'url', 'date', 'source', 'media_id', 'added_at' ], array_keys( $schema['properties']['items']['items']['properties'] ) );
	}

	/** Execute returns resolver order, count, and the newest visible position. */
	public function test_execute_returns_the_public_schema_shape(): void {
		$id = $this->collection(
			$this->item( [ 'title' => 'Rain', 'addedAt' => '2026-10-01T10:00:00Z' ] )
			. $this->item( [ 'title' => 'Morning birds', 'addedAt' => '2026-10-02T10:00:00Z' ] )
			. $this->item( [ 'title' => 'Old reel', 'date' => '2020-03-04', 'addedAt' => '2026-10-05T10:00:00Z' ] )
		);
		$this->as_reader();
		$result = $this->ability()->execute( [ 'post_id' => $id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $id, $result['post_id'] );
		$this->assertSame( 3, $result['count'] );
		$this->assertSame( 3, $result['newest_position'] );
		$this->assertSame( [ 1, 2, 3 ], array_column( $result['items'], 'position' ) );
		$this->assertSame( '2020-03-04', $result['items'][2]['date'] );
	}

	/** An all-hidden collection, missing post, and non-collection return an empty shape. */
	public function test_execute_returns_empty_shape_for_ineligible_posts(): void {
		$hidden = self::factory()->post->create( [ 'post_status' => 'draft', 'post_title' => 'Hidden' ] );
		$all_hidden = $this->collection( $this->item( [ 'postId' => $hidden ] ) );
		$note       = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$this->as_reader();
		foreach ( [ $all_hidden, $note ] as $post_id ) {
			$result = $this->ability()->execute( [ 'post_id' => $post_id ] );
			$this->assertIsArray( $result );
			$this->assertSame( 0, $result['count'] );
			$this->assertNull( $result['newest_position'] );
			$this->assertSame( [], $result['items'] );
		}

		$missing = $this->ability()->execute( [ 'post_id' => 999999 ] );
		$this->assertWPError( $missing );
		$this->assertSame( 'ability_invalid_permissions', $missing->get_error_code() );
	}

	/** Logged-out protected/private access is denied; an editor can read the private post. */
	public function test_permission_rules_for_password_and_private_collections(): void {
		$password = $this->collection( '', [ 'post_password' => 'secret' ] );
		$private  = $this->collection( '', [ 'post_status' => 'private' ] );
		$ability  = $this->ability();

		$this->assertFalse( $ability->check_permissions( [ 'post_id' => $password ] ) );
		$this->assertFalse( $ability->check_permissions( [ 'post_id' => $private ] ) );

		// A subscriber holds the read capability, which alone would pass a published post.
		$this->as_reader();
		$this->assertFalse( $ability->check_permissions( [ 'post_id' => $password ] ), 'Reader without edit rights is denied a password collection.' );
		$this->assertFalse( $ability->check_permissions( [ 'post_id' => $private ] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( $ability->check_permissions( [ 'post_id' => $private ] ) );
	}

	/** An editor passes permission on a password collection but still gets no items. */
	public function test_password_collection_returns_no_items_even_to_an_editor(): void {
		$id = $this->collection( $this->item( [ 'title' => 'Protected item 240' ] ), [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $id ] );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( [], $result['items'] );
		$this->assertStringNotContainsString( 'Protected item 240', (string) wp_json_encode( $result ) );
	}

	/** Cost-like referenced meta never enters the ability payload. */
	public function test_ability_never_exposes_referenced_costs(): void {
		$private_cost = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Brass desk lamp' ] );
		$public_cost  = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Field recorder' ] );
		update_post_meta( $private_cost, '_pkiw_acquisition_price', '84.00' );
		update_post_meta( $public_cost, '_pkiw_acquisition_price', '219.00' );
		update_post_meta( $public_cost, \PKIW\Meta_Fields::COST_PUBLIC_KEY, '1' );
		$id      = $this->collection( $this->item( [ 'postId' => $private_cost ] ) . $this->item( [ 'postId' => $public_cost ] ) );
		$this->as_reader();
		$result  = $this->ability()->execute( [ 'post_id' => $id ] );
		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['count'] );
		$encoded = wp_json_encode( $result );

		$this->assertStringNotContainsString( '84.00', $encoded );
		$this->assertStringNotContainsString( '219.00', $encoded );
	}
}

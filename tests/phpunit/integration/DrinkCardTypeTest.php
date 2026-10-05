<?php
/**
 * The drink card's type has no default.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * The card prints the block attribute when it names a type, else the post's
 * stored `_pkiw_drink_type`, else no type at all. A stored type comes from
 * the author or from imported data, never from the block.
 *
 * @group integration
 */
final class DrinkCardTypeTest extends WP_UnitTestCase {

	/**
	 * Create a published post holding one drink card.
	 *
	 * @param array<string, mixed> $attrs Drink card attributes.
	 */
	private function drink( array $attrs ): int {
		return self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:post-kinds-indieweb/drink-card ' . wp_json_encode( $attrs ) . ' /-->',
			]
		);
	}

	/**
	 * The type the card prints for a post, rendered from its saved content
	 * on its own page.
	 *
	 * @param int $post_id Post ID.
	 */
	private function type_label( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$label = ( new DOMXPath( $dom ) )->query( '(//article[contains(concat(" ", @class, " "), " k-drink ")]//p[contains(concat(" ", @class, " "), " pk-sub ")])[1]/span[not(@class)]' )->item( 0 );

		return $label ? trim( $label->textContent ) : '';
	}

	public function test_the_block_declares_no_default_type(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'post-kinds-indieweb/drink-card' );

		$this->assertNotNull( $type );
		$this->assertArrayNotHasKey( 'default', $type->attributes['drinkType'], 'The registered drink card gives drinkType no default.' );
	}

	public function test_a_drink_with_no_type_prints_no_type(): void {
		$post_id = $this->drink( [ 'name' => 'House pour' ] );

		$this->assertSame( '', $this->type_label( $post_id ), 'A drink with no type prints no type.' );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_drink_type', true ), 'No type is stored for a drink saved without one.' );

		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );
		$this->assertStringNotContainsString( 'Coffee', $html );
		$this->assertStringNotContainsString( 'pk-sub', $html, 'With no type and no brand the card prints no line under the name.' );
	}

	public function test_a_drink_with_a_brand_and_no_type_prints_the_brand_alone(): void {
		$post_id = $this->drink( [ 'name' => 'House pour', 'brand' => 'Corner Cafe' ] );
		$this->go_to( get_permalink( $post_id ) );
		$html = (string) preg_replace( '/\s+/', ' ', do_blocks( (string) get_post_field( 'post_content', $post_id ) ) );

		$this->assertSame( '', $this->type_label( $post_id ) );
		$this->assertStringContainsString( 'Corner Cafe', $html );
		$this->assertStringNotContainsString( '<span></span>', $html, 'No empty type is printed.' );
		$this->assertStringNotContainsString( '&mdash;', $html, 'No dash stands before a brand with no type.' );
		$this->assertStringNotContainsString( '—', $html );
	}

	/**
	 * Types an author picked, and the label each has always printed.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function chosen_types(): array {
		return [
			'coffee'          => [ 'coffee', 'Coffee' ],
			'beer'            => [ 'beer', 'Beer' ],
			'other'           => [ 'other', 'Drink' ],
			'not in the list' => [ 'whiskey', 'whiskey' ],
		];
	}

	/**
	 * @dataProvider chosen_types
	 *
	 * @param string $type  Stored type.
	 * @param string $label Label the card prints.
	 */
	public function test_a_type_in_the_card_prints_as_before( string $type, string $label ): void {
		$post_id = $this->drink( [ 'name' => 'Sample', 'drinkType' => $type ] );

		$this->assertSame( $type, get_post_meta( $post_id, '_pkiw_drink_type', true ) );
		$this->assertSame( $label, $this->type_label( $post_id ) );
	}

	/**
	 * @dataProvider chosen_types
	 *
	 * @param string $type  Stored type.
	 * @param string $label Label the card prints.
	 */
	public function test_a_card_without_the_attribute_prints_the_stored_type( string $type, string $label ): void {
		$post_id = $this->drink( [ 'name' => 'Sample' ] );
		update_post_meta( $post_id, '_pkiw_drink_type', $type );

		$this->assertSame( $label, $this->type_label( $post_id ), 'The post\'s stored type stands in for a missing attribute.' );
	}

	public function test_a_type_in_the_card_wins_over_the_stored_type(): void {
		$post_id = $this->drink( [ 'name' => 'Imperial stout', 'drinkType' => 'beer' ] );
		update_post_meta( $post_id, '_pkiw_drink_type', 'tea' );

		$this->assertSame( 'Beer', $this->type_label( $post_id ) );
	}

	public function test_an_empty_attribute_is_no_type(): void {
		$stored = $this->drink( [ 'name' => 'Rioja', 'drinkType' => '' ] );
		update_post_meta( $stored, '_pkiw_drink_type', 'wine' );
		$unset = $this->drink( [ 'name' => 'House pour', 'drinkType' => '' ] );

		$this->assertSame( 'Wine', $this->type_label( $stored ), 'An empty attribute falls back to the stored type.' );
		$this->assertSame( '', $this->type_label( $unset ), 'An empty attribute with nothing stored prints no type.' );
	}

	public function test_a_drink_saved_under_the_old_default_stays_coffee_after_a_resave(): void {
		// Saved while the block still had a default: no drinkType in the
		// comment, "coffee" in the meta.
		$post_id = $this->drink( [ 'name' => 'Cortado' ] );
		update_post_meta( $post_id, '_pkiw_drink_type', 'coffee' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"Cortado","rating":4} /-->',
			]
		);

		$this->assertSame( 'coffee', get_post_meta( $post_id, '_pkiw_drink_type', true ), 'A re-save without drinkType keeps the stored type.' );
		$this->assertSame( 'Coffee', $this->type_label( $post_id ) );
	}
}

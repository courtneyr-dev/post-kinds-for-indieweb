<?php
/**
 * REST shows the card's public cost, not the sidebar Price (issue 381).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * With "Show cost publicly" on, redact_cost_array() let a visitor's REST
 * read see `_pkiw_acquisition_price`, the sidebar Price, while the card and
 * the #239 facts reader print the card's own `cost`. For anyone who can't
 * edit the post, REST now carries the card's cost when the card shows it,
 * and '' otherwise. Editors keep the stored meta.
 *
 * @group integration
 */
final class AcquisitionRestCostTest extends WP_UnitTestCase {

	private const PRICE_KEY = '_pkiw_acquisition_price';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		if ( ! term_exists( 'acquisition', 'kind' ) ) {
			wp_insert_term( 'Acquisition', 'kind', [ 'slug' => 'acquisition' ] );
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published acquisition with a card, then a sidebar Price set after the save.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param string               $price Sidebar Price stored after the save.
	 * @return int Post ID.
	 */
	private function acquisition( array $attrs, string $price ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:post-kinds-indieweb/acquisition-card ' . wp_json_encode( array_merge( [ 'title' => 'Walnut lamp' ], $attrs ) ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, 'acquisition', 'kind' );
		update_post_meta( $id, self::PRICE_KEY, $price );

		return $id;
	}

	/**
	 * `_pkiw_acquisition_price` in a post's REST view.
	 *
	 * @param int $id Post ID.
	 */
	private function rest_price( int $id ): string {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
		$this->assertArrayHasKey( self::PRICE_KEY, (array) ( $data['meta'] ?? [] ), 'REST exposes the field.' );

		return (string) $data['meta'][ self::PRICE_KEY ];
	}

	public function test_a_visitor_gets_the_card_cost_when_it_differs_from_the_sidebar_price(): void {
		$id = $this->acquisition(
			[
				'cost'             => '$20',
				'showCostPublicly' => true,
			],
			'$99'
		);
		$this->assertTrue( Meta_Fields::acquisition_cost_visible( $id ), 'The card shows its cost.' );

		$this->assertSame( '$20', $this->rest_price( $id ) );
	}

	public function test_a_visitor_gets_nothing_when_a_showing_card_has_no_cost(): void {
		$id = $this->acquisition( [ 'showCostPublicly' => true ], '$99' );
		$this->assertTrue( Meta_Fields::acquisition_cost_visible( $id ) );

		$this->assertSame( '', $this->rest_price( $id ) );
	}

	public function test_a_visitor_gets_nothing_when_the_card_hides_its_cost(): void {
		$id = $this->acquisition( [ 'cost' => '$20' ], '$99' );

		$this->assertSame( '', $this->rest_price( $id ) );
	}

	public function test_an_editor_keeps_the_stored_sidebar_price(): void {
		$id = $this->acquisition(
			[
				'cost'             => '$20',
				'showCostPublicly' => true,
			],
			'$99'
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( '$99', $this->rest_price( $id ) );
	}

	public function test_the_get_post_meta_ability_matches_rest_for_a_visitor(): void {
		$id   = $this->acquisition(
			[
				'cost'             => '$20',
				'showCostPublicly' => true,
			],
			'$99'
		);
		$meta = Meta_Fields::redact_cost_array( [ self::PRICE_KEY => '$99' ], $id );

		$this->assertSame( '$20', $meta[ self::PRICE_KEY ] );
	}
}

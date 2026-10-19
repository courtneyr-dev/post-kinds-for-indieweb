<?php
/**
 * Wish facts from block editor, sidebar, Quick Post and Micropub shapes.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Wish_Archive;

/**
 * Covers the normalized fact contract for every wish writer.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishFactsTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Prepare the real taxonomy, meta registration and date settings.
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
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Restore visitor and theme state.
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
	 * Serialized wish card comment.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @return string Block comment.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Create a post and assign one kind after save hooks have run.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @param string               $kind Kind slug.
	 * @return int Post ID.
	 */
	private function post( array $args = [], string $kind = 'wish' ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => '',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Store raw meta rows without registered defaults masking absence.
	 *
	 * @since 1.9.0
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $meta    Full meta key to value map.
	 * @return void
	 */
	private function meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			delete_post_meta( $post_id, $key );
			add_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * A block-editor card populates the complete, ordered fact shape.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_block_editor_card_returns_every_documented_fact(): void {
		$id = $this->post(
			[
				'post_title'   => 'Lantern wish',
				'post_content' => $this->card(
					[
						'title'     => '  Fictional Lantern  ',
						'wishType'  => ' Media ',
						'url'       => 'https://shop.example/lantern',
						'image'     => 'https://images.example/lantern.jpg',
						'imageAlt'  => 'Paper lantern glowing at dusk',
						'price'     => '  $48  ',
						'reason'    => '  Reading light  ',
						'priority'  => 'high',
						'wishedAt' => '2026-07-04',
						'rel'       => '  product   external  ',
					]
				),
			]
		);
		$facts = Wish_Archive::facts( $id );

		$this->assertSame(
			[
				'has_card',
				'title',
				'name',
				'name_is_title',
				'name_kind',
				'type',
				'type_key',
				'type_label',
				'priority',
				'priority_label',
				'priority_text',
				'price',
				'reason',
				'url',
				'rel',
				'wished_iso',
				'wished_display',
				'picture',
			],
			array_keys( $facts )
		);
		$this->assertTrue( $facts['has_card'] );
		$this->assertSame( 'Fictional Lantern', $facts['title'] );
		$this->assertSame( 'Lantern wish', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( 'title', $facts['name_kind'] );
		$this->assertSame( 'media', $facts['type'] );
		$this->assertSame( 'movie', $facts['type_key'] );
		$this->assertSame( 'Movie/Show', $facts['type_label'] );
		$this->assertSame( 'high', $facts['priority'] );
		$this->assertSame( 'High', $facts['priority_label'] );
		$this->assertSame( 'High priority', $facts['priority_text'] );
		$this->assertSame( '$48', $facts['price'] );
		$this->assertSame( 'Reading light', $facts['reason'] );
		$this->assertSame( 'https://shop.example/lantern', $facts['url'] );
		$this->assertSame( 'product external', $facts['rel'] );
		$this->assertSame( '2026-07-04', $facts['wished_iso'] );
		$this->assertSame( 'July 4, 2026', $facts['wished_display'] );
		$this->assertSame(
			[
				'source'            => 'cover',
				'attachment_id'     => 0,
				'url'               => 'https://images.example/lantern.jpg',
				'alt'               => 'Paper lantern glowing at dusk',
				'remote'            => true,
				'suppress_featured' => false,
			],
			$facts['picture']
		);
		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/lat|lon|address|geo|location/i', $key );
		}
	}

	/**
	 * Sidebar meta supplies card-independent title, type and picture facts.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_sidebar_shaped_post_uses_meta_and_defaults_priority(): void {
		$id = $this->post( [ 'post_title' => 'Studio wish' ] );
		$this->meta(
			$id,
			[
				'_pkiw_wish_name'  => 'Standing desk',
				'_pkiw_wish_type'  => 'product',
				'_pkiw_wish_photo' => 'https://images.example/desk.jpg',
			]
		);

		$facts = Wish_Archive::facts( $id );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( 'Standing desk', $facts['title'] );
		$this->assertSame( 'Studio wish', $facts['name'] );
		$this->assertSame( 'title', $facts['name_kind'] );
		$this->assertSame( 'product', $facts['type'] );
		$this->assertSame( 'medium', $facts['priority'] );
		$this->assertSame( 'https://images.example/desk.jpg', $facts['picture']['url'] );
		$this->assertSame( '', $facts['picture']['alt'] );
	}

	/**
	 * A Quick Post wish with the type select left on "Select..." stores no type row.
	 *
	 * Quick Post skips empty fields and its priority select always sends a value
	 * (medium unless changed). WishGroupTest runs the real writer for every type.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_quick_post_with_no_type_picked_files_under_other(): void {
		$id = $this->post( [ 'post_title' => 'Quick wish', 'post_content' => '<!-- wp:paragraph --><p>Notes.</p><!-- /wp:paragraph -->' ] );
		$this->meta( $id, [ '_pkiw_wish_name' => 'Train ticket', '_pkiw_wish_priority' => 'medium' ] );

		$facts = Wish_Archive::facts( $id );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( 'Train ticket', $facts['title'] );
		$this->assertSame( '', $facts['type'] );
		$this->assertSame( '', $facts['type_key'] );
		$this->assertSame( 'Other', $facts['type_label'] );
		$this->assertSame( 'medium', $facts['priority'] );
	}

	/**
	 * A Micropub paragraph has no card facts and never causes a fatal.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_micropub_paragraph_shape_returns_safe_defaults(): void {
		$id = $this->post(
			[
				'post_title'   => 'Micropub wish',
				'post_content' => '<!-- wp:paragraph --><p>Wishing for <a class="u-wish-of" href="https://shop.example/kite">a kite</a>.</p><!-- /wp:paragraph -->',
			]
		);

		$facts = Wish_Archive::facts( $id );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( '', $facts['url'] );
		$this->assertSame( '', $facts['type'] );
		$this->assertSame( 'medium', $facts['priority'] );
		$this->assertSame( [ 'source' => '', 'attachment_id' => 0, 'url' => '', 'alt' => '', 'remote' => false, 'suppress_featured' => false ], $facts['picture'] );
	}

	/**
	 * Untitled wishes distinguish card names from generated names.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_untitled_wishes_report_card_and_synthetic_name_kinds(): void {
		$card_id = $this->post( [ 'post_content' => $this->card( [ 'title' => 'Fictional Lantern' ] ) ] );
		$card    = Wish_Archive::facts( $card_id );
		$this->assertSame( 'Fictional Lantern', $card['name'] );
		$this->assertSame( 'card', $card['name_kind'] );
		$this->assertFalse( $card['name_is_title'] );

		$empty_id = $this->post();
		$empty    = Wish_Archive::facts( $empty_id );
		$this->assertSame( \PKIW\untitled_name( get_post( $empty_id ), false ), $empty['name'] );
		$this->assertSame( 'untitled', $empty['name_kind'] );
		$this->assertFalse( $empty['name_is_title'] );
	}

	/**
	 * A stored visible post title is the public name.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_titled_wish_marks_its_name_as_a_title(): void {
		$facts = Wish_Archive::facts( $this->post( [ 'post_title' => 'A room of books' ] ) );

		$this->assertSame( 'A room of books', $facts['name'] );
		$this->assertSame( 'title', $facts['name_kind'] );
		$this->assertTrue( $facts['name_is_title'] );
	}

	/**
	 * Password-required facts stay hidden through both public readers.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_password_protected_wish_returns_no_facts(): void {
		$id = $this->post( [ 'post_title' => 'Hidden wish', 'post_password' => 'secret', 'post_content' => $this->card( [ 'title' => 'Private lamp' ] ) ] );
		wp_set_current_user( 0 );

		$this->assertSame( [], Wish_Archive::facts( $id ) );
		$this->assertSame( [], \PKIW\kind_facts( $id ) );
	}

	/**
	 * Private wishes are visible to an editor but not a logged-out visitor.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_private_wish_follows_read_post_capability(): void {
		$id = $this->post( [ 'post_status' => 'private', 'post_title' => 'Private telescope' ] );
		wp_set_current_user( 0 );
		$this->assertSame( [], Wish_Archive::facts( $id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertNotSame( [], Wish_Archive::facts( $id ) );
	}

	/**
	 * Draft wishes stay hidden from logged-out visitors.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_draft_wish_returns_no_facts_for_a_visitor(): void {
		$id = $this->post( [ 'post_status' => 'draft', 'post_title' => 'Draft wish' ] );
		wp_set_current_user( 0 );

		$this->assertSame( [], Wish_Archive::facts( $id ) );
	}

	/**
	 * A post assigned another kind cannot be read as a wish.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_another_kind_returns_no_wish_facts(): void {
		$id = $this->post( [ 'post_title' => 'A note', 'post_content' => $this->card( [ 'title' => 'Stray card' ] ) ], 'note' );

		$this->assertSame( [], Wish_Archive::facts( $id ) );
	}

	/**
	 * An invalid card URL falls back to a valid stored wish URL.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_invalid_card_url_is_hidden_and_can_fall_back_to_meta(): void {
		$without_meta = $this->post( [ 'post_content' => $this->card( [ 'title' => 'Unsafe', 'url' => 'javascript:alert(1)' ] ) ] );
		delete_post_meta( $without_meta, '_pkiw_wish_url' );
		$this->assertSame( '', Wish_Archive::facts( $without_meta )['url'] );

		$with_meta = $this->post( [ 'post_content' => $this->card( [ 'title' => 'Fallback', 'url' => 'javascript:alert(1)' ] ) ] );
		$this->meta( $with_meta, [ '_pkiw_wish_url' => 'https://shop.example/fallback' ] );
		$this->assertSame( 'https://shop.example/fallback', Wish_Archive::facts( $with_meta )['url'] );
	}

	/**
	 * Calendar dates reject impossible days and preserve valid local days.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_wished_date_validation_keeps_the_stored_calendar_day(): void {
		$invalid = Wish_Archive::facts( $this->post( [ 'post_content' => $this->card( [ 'title' => 'Invalid date', 'wishedAt' => '2026-02-30' ] ) ] ) );
		$this->assertSame( '', $invalid['wished_iso'] );
		$this->assertSame( '', $invalid['wished_display'] );

		$valid = Wish_Archive::facts( $this->post( [ 'post_content' => $this->card( [ 'title' => 'Valid date', 'wishedAt' => '2026-07-04' ] ) ] ) );
		$this->assertSame( '2026-07-04', $valid['wished_iso'] );
		$this->assertSame( 'July 4, 2026', $valid['wished_display'] );
	}

	/**
	 * Only the first wish card contributes facts.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_first_of_two_cards_wins(): void {
		$id = $this->post(
			[
				'post_content' => $this->card( [ 'title' => 'First lantern', 'wishType' => 'book', 'priority' => 'high' ] )
					. $this->card( [ 'title' => 'Second lantern', 'wishType' => 'tech', 'priority' => 'low' ] ),
			]
		);
		$facts = Wish_Archive::facts( $id );

		$this->assertSame( 'First lantern', $facts['title'] );
		$this->assertSame( 'book', $facts['type'] );
		$this->assertSame( 'high', $facts['priority'] );
	}

	/**
	 * Stored priority wins, normalizes, and unknowns become medium.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_priority_prefers_the_raw_row_then_the_card(): void {
		$urgent = $this->post( [ 'post_content' => $this->card( [ 'title' => 'Urgent row', 'priority' => 'low' ] ) ] );
		$this->meta( $urgent, [ '_pkiw_wish_priority' => 'urgent' ] );
		$this->assertSame( 'medium', Wish_Archive::facts( $urgent )['priority'] );

		$high = $this->post( [ 'post_content' => $this->card( [ 'title' => 'High row', 'priority' => 'low' ] ) ] );
		// Past the registered sanitizer, which would turn ' HIGH ' into 'medium'.
		global $wpdb;
		delete_post_meta( $high, '_pkiw_wish_priority' );
		$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $high, 'meta_key' => '_pkiw_wish_priority', 'meta_value' => ' HIGH ' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		wp_cache_delete( $high, 'post_meta' );
		$this->assertSame( 'high', Wish_Archive::facts( $high )['priority'] );

		$card = $this->post( [ 'post_content' => $this->card( [ 'title' => 'Card priority', 'priority' => 'high' ] ) ] );
		delete_post_meta( $card, '_pkiw_wish_priority' );
		$this->assertSame( 'high', Wish_Archive::facts( $card )['priority'] );
	}
}

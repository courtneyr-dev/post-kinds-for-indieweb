<?php
/**
 * The Recent Specials block on the eat and drink menus (issue 230).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;

/**
 * Recent Specials lists the newest posts of a menu kind above the menu:
 * name, note, venue under the location privacy rule, date, a text rating
 * and an optional photo. The menu lines below stay the page's entries, so
 * a special carries no microformats root of its own.
 *
 * @group integration
 */
final class MenuSpecialsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published eat or drink post.
	 *
	 * @param string               $kind  `eat` or `drink`.
	 * @param string               $date  Post date.
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function entry( string $kind, string $date, array $attrs ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => (string) ( $attrs['name'] ?? 'Untitled' ),
				'post_date'    => $date,
				'post_content' => '<!-- wp:post-kinds-indieweb/' . $kind . '-card ' . wp_json_encode( $attrs ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, $kind, 'kind' );

		return $id;
	}

	/**
	 * Render the block as a template would.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function render( array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => Kind_Archive_Layouts::MENU_SPECIALS,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Linked names of the specials, in document order.
	 *
	 * @return array<int, array{name: string, href: string}>
	 */
	private function names( string $html ): array {
		if ( '' === $html ) {
			return [];
		}
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$out = [];
		foreach ( ( new DOMXPath( $dom ) )->query( '//section/ul/li//a[contains(@class,"pkiw-menu-specials__link")]' ) as $a ) {
			$out[] = [
				'name' => trim( $a->textContent ),
				'href' => $a->getAttribute( 'href' ),
			];
		}

		return $out;
	}

	public function test_the_block_is_registered_with_an_editor_script(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Kind_Archive_Layouts::MENU_SPECIALS );

		$this->assertNotNull( $block );
		$this->assertContains( 'pkiw-menu-specials-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-menu-specials-editor', 'registered' ) );
	}

	public function test_it_lists_the_two_newest_posts_of_the_archives_kind_newest_first(): void {
		$this->entry( 'eat', '2026-01-01 10:00:00', [ 'name' => 'Oldest plate' ] );
		$middle = $this->entry( 'eat', '2026-02-01 10:00:00', [ 'name' => 'Middle plate' ] );
		$newest = $this->entry( 'eat', '2026-03-01 10:00:00', [ 'name' => 'Newest plate' ] );
		$this->entry( 'drink', '2026-04-01 10:00:00', [ 'name' => 'A drink' ] );
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$this->assertSame(
			[
				[
					'name' => 'Newest plate',
					'href' => get_permalink( $newest ),
				],
				[
					'name' => 'Middle plate',
					'href' => get_permalink( $middle ),
				],
			],
			$this->names( $this->render() )
		);
	}

	public function test_count_sets_how_many_within_one_to_six(): void {
		foreach ( range( 1, 8 ) as $day ) {
			$this->entry( 'eat', sprintf( '2026-01-%02d 10:00:00', $day ), [ 'name' => 'Plate ' . $day ] );
		}
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$this->assertCount( 3, $this->names( $this->render( [ 'count' => 3 ] ) ) );
		$this->assertCount( 1, $this->names( $this->render( [ 'count' => 0 ] ) ) );
		$this->assertCount( 6, $this->names( $this->render( [ 'count' => 40 ] ) ) );
	}

	public function test_a_special_prints_its_note_venue_date_and_rating_as_text(): void {
		$this->entry(
			'eat',
			'2026-03-01 10:00:00',
			[
				'name'       => 'Street Tacos al Pastor',
				'restaurant' => 'El Sol Taqueria',
				'rating'     => 4,
				'ateAt'      => '2026-02-27T18:30:00',
				'notes'      => 'Charred pork with pineapple.',
			]
		);
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Charred pork with pineapple.', $html );
		$this->assertStringContainsString( 'El Sol Taqueria', $html );
		$this->assertStringContainsString( 'Rated 4 of 5', $html );
		$this->assertMatchesRegularExpression( '/<time[^>]*datetime="2026-02-27T18:30:00\+00:00"[^>]*>February 27, 2026<\/time>/', $html );
	}

	public function test_a_venue_named_like_the_brand_prints_once(): void {
		$this->entry(
			'drink',
			'2026-03-01 10:00:00',
			[
				'name'         => 'Honey Lavender Latte',
				'brand'        => 'Commonplace Coffee',
				'locationName' => 'commonplace coffee',
			]
		);
		$this->go_to( get_term_link( 'drink', 'kind' ) );

		$this->assertSame( 1, substr_count( strtolower( $this->render() ), 'commonplace coffee' ) );
	}

	public function test_an_eat_special_names_the_restaurant_before_the_town(): void {
		$this->entry(
			'eat',
			'2026-03-01 10:00:00',
			[
				'name'             => 'Mushroom Tacos',
				'restaurant'       => 'Mercado',
				'locationLocality' => 'Reading',
			]
		);
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html = $this->render();

		$this->assertNotFalse( strpos( $html, 'Mercado' ) );
		$this->assertNotFalse( strpos( $html, 'Reading' ) );
		$this->assertLessThan( strpos( $html, 'Reading' ), strpos( $html, 'Mercado' ) );
	}

	public function test_it_is_a_section_named_by_its_heading(): void {
		$this->entry( 'eat', '2026-03-01 10:00:00', [ 'name' => 'A plate' ] );
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html = $this->render();

		$this->assertSame( 1, preg_match( '/<h2 id="([^"]+)" class="pkiw-menu-specials__heading">Recent Specials<\/h2>/', $html, $heading ) );
		$this->assertMatchesRegularExpression( '/^<section [^>]*aria-labelledby="' . preg_quote( $heading[1], '/' ) . '"/', $html );
		$this->assertStringContainsString( '<h3 class="pkiw-menu-specials__name">', $html );

		$deeper = $this->render( [ 'headingLevel' => 3 ] );
		$this->assertStringContainsString( 'class="pkiw-menu-specials__heading">Recent Specials</h3>', $deeper );
		$this->assertStringContainsString( '<h4 class="pkiw-menu-specials__name">', $deeper );
	}

	public function test_a_photo_prints_with_its_alt_text_and_can_be_switched_off(): void {
		$this->entry(
			'eat',
			'2026-03-01 10:00:00',
			[
				'name'     => 'Tonkotsu Ramen',
				'photo'    => 'https://example.com/ramen.jpg',
				'photoAlt' => 'A bowl of ramen with an egg',
			]
		);
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$with = $this->render();
		$this->assertMatchesRegularExpression( '/<img[^>]*class="pkiw-menu-specials__photo"[^>]*src="https:\/\/example\.com\/ramen\.jpg"[^>]*alt="A bowl of ramen with an egg"/', $with );

		$without = $this->render( [ 'showPhotos' => false ] );
		$this->assertStringNotContainsString( '<img', $without );
		$this->assertStringContainsString( 'Tonkotsu Ramen', $without );
	}

	public function test_the_featured_image_is_the_photo_when_a_post_has_one(): void {
		$id      = $this->entry( 'eat', '2026-03-01 10:00:00', [ 'name' => 'Plate', 'photo' => 'https://example.com/card.jpg' ] );
		$picture = self::factory()->attachment->create_object( 'featured.png', 0, [ 'post_mime_type' => 'image/png' ] );
		set_post_thumbnail( $id, $picture );
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'featured.png', $html );
		$this->assertStringNotContainsString( 'card.jpg', $html );
	}

	public function test_a_private_post_shows_no_venue_to_a_visitor_and_shows_it_to_an_editor(): void {
		$id = $this->entry( 'eat', '2026-03-01 10:00:00', [ 'name' => 'Quiet dinner', 'restaurant' => 'Hidden Bistro' ] );
		update_post_meta( $id, '_pkiw_geo_privacy', 'private' );
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$this->assertStringNotContainsString( 'Hidden Bistro', $this->render() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertStringContainsString( 'Hidden Bistro', $this->render() );
	}

	public function test_it_prints_nothing_past_the_first_page(): void {
		update_option( 'posts_per_page', 1 );
		$this->entry( 'eat', '2026-01-01 10:00:00', [ 'name' => 'First' ] );
		$this->entry( 'eat', '2026-02-01 10:00:00', [ 'name' => 'Second' ] );
		$this->go_to( add_query_arg( 'paged', 2, get_term_link( 'eat', 'kind' ) ) );
		$this->assertTrue( is_paged() );

		$this->assertSame( '', $this->render() );
	}

	public function test_the_kind_attribute_names_the_kind_away_from_its_archive(): void {
		$this->entry( 'eat', '2026-03-01 10:00:00', [ 'name' => 'A plate' ] );
		$this->entry( 'drink', '2026-02-01 10:00:00', [ 'name' => 'Old Fashioned', 'brand' => 'Maple and Rye' ] );
		$this->go_to( home_url( '/' ) );

		$this->assertSame( '', $this->render(), 'No kind and no kind archive: nothing to list.' );

		$html = $this->render( [ 'kind' => 'drink' ] );
		$this->assertSame( [ 'Old Fashioned' ], wp_list_pluck( $this->names( $html ), 'name' ) );
		$this->assertStringContainsString( 'Maple and Rye', $html );
	}

	public function test_a_kind_with_no_menu_and_a_kind_with_no_posts_print_nothing(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', 'kind' );

		$this->assertSame( '', $this->render( [ 'kind' => 'note' ] ) );
		$this->assertSame( '', $this->render( [ 'kind' => 'eat' ] ) );
	}

	public function test_a_special_is_not_a_second_entry_for_the_post(): void {
		$this->entry( 'eat', '2026-03-01 10:00:00', [ 'name' => 'A plate', 'rating' => 5 ] );
		$this->go_to( get_term_link( 'eat', 'kind' ) );

		$html   = $this->render();
		$parsed = \Mf2\parse( $html, home_url( '/' ) );

		$this->assertSame( [], $parsed['items'] );
		$this->assertStringNotContainsString( 'h-entry', $html );
	}

	/**
	 * @dataProvider menu_kinds
	 */
	public function test_the_menu_template_places_the_specials_above_the_menu_and_names_its_kind( string $kind ): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-' . $kind . '.html' );

		$specials = strpos( $content, '<!-- wp:post-kinds-indieweb/menu-specials {"kind":"' . $kind . '"} /-->' );
		$query    = strpos( $content, '<!-- wp:query ' );

		$this->assertNotFalse( $specials );
		$this->assertNotFalse( $query );
		$this->assertLessThan( $query, $specials );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function menu_kinds(): array {
		return [
			'eat'   => [ 'eat' ],
			'drink' => [ 'drink' ],
		];
	}

	public function test_the_editor_render_lists_the_kind_it_is_given(): void {
		$this->entry( 'drink', '2026-02-01 10:00:00', [ 'name' => 'Old Fashioned' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Kind_Archive_Layouts::MENU_SPECIALS );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'attributes', [ 'kind' => 'drink', 'count' => 2, 'showPhotos' => true ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'Old Fashioned' ], wp_list_pluck( $this->names( (string) $response->get_data()['rendered'] ), 'name' ) );
	}
}

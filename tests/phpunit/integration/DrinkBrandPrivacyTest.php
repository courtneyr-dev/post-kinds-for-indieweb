<?php
/**
 * A drink brand filled from venue data follows the location's privacy (#307).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Archive_Layouts;
use PKIW\Meta_Fields;

/**
 * A brand marked `_pkiw_drink_brand_source` = `location` names the venue, so
 * it follows the `name` tier of the location rule: hidden from a visitor
 * when `_pkiw_geo_privacy` is `private` or Simple Location `geo_public` is
 * `0`, shown otherwise. A brand with no marker was typed by the author and
 * prints whatever the privacy, even when it matches the venue name.
 *
 * Covers the card and its `h-food` author, the feed's `content:encoded`,
 * REST `content.rendered` and meta, the menu line and Recent Specials. An
 * author who edits a marked brand makes it theirs, so the edit clears the
 * marker.
 *
 * @group integration
 */
final class DrinkBrandPrivacyTest extends WP_UnitTestCase {

	private const VENUE     = 'Cedar and Salt Zq4';
	private const ELSEWHERE = 'Elsewhere Lounge Zq5';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published drink whose brand and venue share a name.
	 *
	 * @param string      $privacy        `_pkiw_geo_privacy` value.
	 * @param bool        $location_brand Whether the brand is marked as filled from venue data.
	 * @param string|null $geo_public     Simple Location `geo_public` value, if any.
	 * @return int Post ID.
	 */
	private function drink( string $privacy, bool $location_brand, ?string $geo_public = null ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Spicy Margarita',
				'post_content' => $this->card( self::VENUE ),
			]
		);
		wp_set_object_terms( $id, 'drink', 'kind' );
		update_post_meta( $id, '_pkiw_geo_privacy', $privacy );
		if ( null !== $geo_public ) {
			update_post_meta( $id, 'geo_public', $geo_public );
		}
		if ( $location_brand ) {
			update_post_meta( $id, '_pkiw_drink_brand_source', 'location' );
		}

		return $id;
	}

	/**
	 * Drink card markup.
	 *
	 * @param string $venue Venue name.
	 * @param string $brand Brand.
	 */
	private function card( string $venue, string $brand = self::VENUE ): string {
		return '<!-- wp:post-kinds-indieweb/drink-card ' . wp_json_encode(
			[
				'name'             => 'Spicy Margarita',
				'drinkType'        => 'cocktail',
				'brand'            => $brand,
				'locationName'     => $venue,
				'locationLocality' => 'Sentinelville',
			]
		) . ' /-->';
	}

	/**
	 * The card as its permalink renders it.
	 *
	 * @param int $id Post ID.
	 */
	private function render_card( int $id ): string {
		$this->go_to( get_permalink( $id ) );

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * The parsed `h-food` of a rendered card.
	 *
	 * @param string $html Card HTML.
	 * @return array<string, mixed>|null
	 */
	private function h_food( string $html ): ?array {
		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$stack  = $parsed['items'];
		while ( $stack ) {
			$item = array_shift( $stack );
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( in_array( 'h-food', $item['type'] ?? [], true ) ) {
				return $item;
			}
			foreach ( $item['properties'] ?? [] as $values ) {
				foreach ( $values as $value ) {
					if ( is_array( $value ) && isset( $value['type'] ) ) {
						$stack[] = $value;
					}
				}
			}
			foreach ( $item['children'] ?? [] as $child ) {
				$stack[] = $child;
			}
		}

		return null;
	}

	/**
	 * The REST meta a request for the post returns.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function rest_meta( int $id ): array {
		return (array) ( $this->rest_post( $id )['meta'] ?? [] );
	}

	/**
	 * The post as the REST posts route returns it.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function rest_post( int $id ): array {
		$GLOBALS['wp_rest_server'] = null;

		return (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
	}

	/**
	 * The post's feed item content (`content:encoded`), as the RSS2 template prints it.
	 *
	 * @param int $id Post ID.
	 */
	private function feed_content( int $id ): string {
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed(), 'Feed context did not take.' );

		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $id ) {
				return get_the_content_feed( 'rss2' );
			}
		}

		$this->fail( sprintf( 'Post %d never appeared in the feed loop.', $id ) );
	}

	/**
	 * The menu line for a post.
	 *
	 * @param int $id Post ID.
	 */
	private function menu_line( int $id ): string {
		$block = new WP_Block(
			[
				'blockName'    => Kind_Archive_Layouts::MENU_ENTRY,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[
				'postId'   => $id,
				'postType' => 'post',
			]
		);

		return $block->render();
	}

	/**
	 * Recent Specials on the drink menu.
	 */
	private function specials(): string {
		$this->go_to( get_term_link( 'drink', 'kind' ) );

		return render_block(
			[
				'blockName'    => Kind_Archive_Layouts::MENU_SPECIALS,
				'attrs'        => [ 'kind' => 'drink' ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Both ways a location is private.
	 *
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public function private_locations(): array {
		return [
			'geo_privacy private' => [ 'private', null ],
			'geo_public 0'        => [ 'public', '0' ],
		];
	}

	/**
	 * @dataProvider private_locations
	 */
	public function test_a_location_derived_brand_does_not_print_on_a_private_drink( string $privacy, ?string $geo_public ): void {
		$html = $this->render_card( $this->drink( $privacy, true, $geo_public ) );

		$this->assertStringContainsString( 'Spicy Margarita', $html, 'The card itself still renders.' );
		$this->assertStringNotContainsString( self::VENUE, $html );
		$this->assertStringNotContainsString( 'p-author', $html );

		$food = $this->h_food( $html );
		$this->assertNotNull( $food, 'Expected a parsed h-food.' );
		$this->assertArrayNotHasKey( 'author', $food['properties'] );
	}

	public function test_the_feed_content_omits_a_location_derived_brand_on_a_private_drink(): void {
		$content = $this->feed_content( $this->drink( 'private', true ) );

		$this->assertStringContainsString( 'Spicy Margarita', $content, 'The card itself is in the feed.' );
		$this->assertStringNotContainsString( self::VENUE, $content );
		$this->assertStringNotContainsString( 'p-author', $content );
	}

	/**
	 * @dataProvider private_locations
	 */
	public function test_rest_blanks_a_location_derived_brand_for_a_visitor( string $privacy, ?string $geo_public ): void {
		$id = $this->drink( $privacy, true, $geo_public );

		$post = $this->rest_post( $id );
		$this->assertStringContainsString( 'Spicy Margarita', $post['content']['rendered'], 'The card itself is in content.rendered.' );
		$this->assertStringNotContainsString( self::VENUE, $post['content']['rendered'] );
		$this->assertStringNotContainsString( 'p-author', $post['content']['rendered'] );

		$meta = (array) $post['meta'];
		$this->assertArrayHasKey( '_pkiw_drink_brewery', $meta );
		$this->assertSame( '', $meta['_pkiw_drink_brewery'] );
		$this->assertSame( self::VENUE, get_post_meta( $id, '_pkiw_drink_brewery', true ), 'Stored data stays intact.' );

		// The post-kinds/get-post-meta ability shares this walk with REST.
		$redacted = Meta_Fields::redact_location_array( [ '_pkiw_drink_brewery' => self::VENUE ], $id );
		$this->assertSame( '', $redacted['_pkiw_drink_brewery'] );
	}

	public function test_menu_line_and_recent_specials_omit_a_location_derived_brand(): void {
		$id = $this->drink( 'private', true );

		$line = $this->menu_line( $id );
		$this->assertStringContainsString( 'Spicy Margarita', $line, 'The menu line itself renders.' );
		$this->assertStringNotContainsString( self::VENUE, $line );

		$specials = $this->specials();
		$this->assertStringContainsString( 'Spicy Margarita', $specials, 'The special itself renders.' );
		$this->assertStringNotContainsString( self::VENUE, $specials );
	}

	public function test_a_typed_brand_still_prints_on_a_private_drink(): void {
		$id = $this->drink( 'private', false );

		$html = $this->render_card( $id );
		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">' . self::VENUE . '</span></span>', $html );
		$food = $this->h_food( $html );
		$this->assertNotNull( $food, 'Expected a parsed h-food.' );
		$this->assertSame( self::VENUE, $food['properties']['author'][0]['properties']['name'][0] );

		$post = $this->rest_post( $id );
		$this->assertSame( self::VENUE, $post['meta']['_pkiw_drink_brewery'] );
		$this->assertStringContainsString( self::VENUE, $post['content']['rendered'] );
		$this->assertStringContainsString( self::VENUE, $this->feed_content( $id ) );
		$this->assertStringContainsString( self::VENUE, $this->menu_line( $id ) );
		$this->assertStringContainsString( self::VENUE, $this->specials() );
	}

	public function test_renaming_the_venue_does_not_change_either_result(): void {
		$marked = $this->drink( 'private', true );
		$typed  = $this->drink( 'private', false );
		foreach ( [ $marked, $typed ] as $id ) {
			wp_update_post(
				[
					'ID'           => $id,
					'post_content' => $this->card( self::ELSEWHERE ),
				]
			);
			$this->assertSame( self::ELSEWHERE, get_post_meta( $id, '_pkiw_drink_location_name', true ), 'The venue was renamed.' );
		}
		$this->assertSame( 'location', get_post_meta( $marked, '_pkiw_drink_brand_source', true ), 'A resave that keeps the brand keeps the marker.' );

		$this->assertStringNotContainsString( self::VENUE, $this->render_card( $marked ), 'A marked brand stays hidden once it no longer matches the venue.' );
		$this->assertSame( '', $this->rest_meta( $marked )['_pkiw_drink_brewery'] );
		$this->assertStringNotContainsString( self::VENUE, $this->menu_line( $marked ) );

		$this->assertStringContainsString( self::VENUE, $this->render_card( $typed ), 'A typed brand stays printed.' );
		$this->assertSame( self::VENUE, $this->rest_meta( $typed )['_pkiw_drink_brewery'] );
		$this->assertStringContainsString( self::VENUE, $this->menu_line( $typed ) );
	}

	public function test_an_approximate_drink_keeps_a_location_derived_brand(): void {
		$id = $this->drink( 'approximate', true );

		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">' . self::VENUE . '</span></span>', $this->render_card( $id ) );
		$this->assertSame( self::VENUE, $this->rest_meta( $id )['_pkiw_drink_brewery'] );
		$this->assertStringContainsString( self::VENUE, $this->feed_content( $id ) );
		$this->assertStringContainsString( self::VENUE, $this->menu_line( $id ) );
		$this->assertStringContainsString( self::VENUE, $this->specials() );
	}

	public function test_a_public_drink_keeps_a_location_derived_brand(): void {
		$id = $this->drink( 'public', true );

		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">' . self::VENUE . '</span></span>', $this->render_card( $id ) );
		$this->assertSame( self::VENUE, $this->rest_meta( $id )['_pkiw_drink_brewery'] );
		$this->assertStringContainsString( self::VENUE, $this->menu_line( $id ) );
	}

	public function test_an_editor_sees_a_location_derived_brand(): void {
		$id = $this->drink( 'private', true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">' . self::VENUE . '</span></span>', $this->render_card( $id ) );
		$this->assertSame( self::VENUE, $this->rest_meta( $id )['_pkiw_drink_brewery'] );
		$this->assertStringContainsString( self::VENUE, $this->menu_line( $id ) );
	}

	public function test_retyping_the_brand_in_the_card_clears_the_marker(): void {
		$id    = $this->drink( 'private', true );
		$typed = 'Author Typed Brand Zq6';

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $this->card( self::VENUE, $typed ),
			]
		);

		$this->assertSame( '', get_post_meta( $id, '_pkiw_drink_brand_source', true ), 'An author-edited brand is the author\'s.' );
		$this->assertStringContainsString( '<span class="p-author h-card"><span class="p-name">' . $typed . '</span></span>', $this->render_card( $id ) );
		$this->assertSame( $typed, $this->rest_meta( $id )['_pkiw_drink_brewery'] );
		$this->assertStringContainsString( $typed, $this->menu_line( $id ) );
	}

	public function test_writing_the_brand_field_clears_the_marker(): void {
		$id    = $this->drink( 'private', true );
		$typed = 'Author Typed Brand Zq6';

		// The editor sidebar's Brand field writes the meta on its own.
		update_post_meta( $id, '_pkiw_drink_brewery', $typed );

		$this->assertSame( '', get_post_meta( $id, '_pkiw_drink_brand_source', true ), 'An author-edited brand is the author\'s.' );
		$this->assertSame( $typed, $this->rest_meta( $id )['_pkiw_drink_brewery'] );
		$this->assertStringContainsString( $typed, $this->menu_line( $id ) );
	}

	public function test_a_marker_recorded_before_the_first_card_sync_survives(): void {
		// meta_input lands before save_post, where Card_Meta_Sync first stores the brand.
		$id = wp_insert_post(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Spicy Margarita',
				'post_content' => $this->card( self::VENUE ),
				'meta_input'   => [
					'_pkiw_geo_privacy'        => 'private',
					'_pkiw_drink_brand_source' => 'location',
				],
			]
		);

		$this->assertSame( self::VENUE, get_post_meta( $id, '_pkiw_drink_brewery', true ), 'The card sync stored the brand.' );
		$this->assertSame( 'location', get_post_meta( $id, '_pkiw_drink_brand_source', true ), 'Storing the brand for the first time keeps the marker.' );
		$this->assertStringNotContainsString( self::VENUE, $this->render_card( $id ) );
		$this->assertSame( '', $this->rest_meta( $id )['_pkiw_drink_brewery'] );
	}

	public function test_mark_location_brand_records_the_location_source(): void {
		$id = $this->drink( 'private', false );

		Meta_Fields::mark_location_brand( $id );

		$this->assertSame( Meta_Fields::BRAND_SOURCE_LOCATION, get_post_meta( $id, Meta_Fields::BRAND_SOURCE_KEY, true ) );
		$this->assertSame( '_pkiw_drink_brand_source', Meta_Fields::BRAND_SOURCE_KEY );
		$this->assertSame( 'location', Meta_Fields::BRAND_SOURCE_LOCATION );
		$this->assertFalse( Meta_Fields::drink_brand_visible( $id ) );
	}

	public function test_the_marker_is_not_published_in_rest(): void {
		$id = $this->drink( 'public', true );

		$this->assertArrayNotHasKey( '_pkiw_drink_brand_source', $this->rest_meta( $id ) );
	}
}

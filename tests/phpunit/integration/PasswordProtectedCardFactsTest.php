<?php
/**
 * A locked post prints none of its card facts on any list, feed or block.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Four states per path: public, locked with no cookie, locked with a wrong
 * cookie, and unlocked. The locked states print the post's title and none of
 * its card facts. Each path's public state is its positive control: the same
 * fixture must print its facts there, or the absence checks prove nothing.
 *
 * @group integration
 */
final class PasswordProtectedCardFactsTest extends WP_UnitTestCase {

	private const PASSWORD = 'open-sesame-Zp1';

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		( new PKIW\Meta_Fields() )->register_meta_fields();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 20 );
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * The four states every path is tested in.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function states(): array {
		return [
			'public'               => [ 'public' ],
			'locked, no cookie'    => [ 'locked' ],
			'locked, wrong cookie' => [ 'wrong' ],
			'unlocked'             => [ 'unlocked' ],
		];
	}

	/**
	 * Whether the state lets a post-level renderer print the post's facts.
	 *
	 * @param string $state State name.
	 */
	private function facts_visible( string $state ): bool {
		return in_array( $state, [ 'public', 'unlocked' ], true );
	}

	/**
	 * Set the visitor's post-password cookie for a state.
	 *
	 * @param string $state State name.
	 */
	private function apply_cookie( string $state ): void {
		require_once ABSPATH . WPINC . '/class-phpass.php';
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		if ( 'wrong' === $state ) {
			$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'not-the-password' );
		} elseif ( 'unlocked' === $state ) {
			$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( self::PASSWORD );
		}
	}

	/**
	 * A published post of a kind in a state, with meta and its visitor cookie.
	 *
	 * @param string                $state State name.
	 * @param string                $kind  Kind slug.
	 * @param string                $title Title.
	 * @param array<string, string> $meta  Meta keyed by field suffix, written under the plugin prefix.
	 * @param array<string, mixed>  $extra Extra post fields.
	 */
	private function post_in_state( string $state, string $kind, string $title, array $meta = [], array $extra = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'   => 'publish',
					'post_title'    => $title,
					'post_password' => 'public' === $state ? '' : self::PASSWORD,
				],
				$extra
			)
		);
		wp_set_object_terms( $id, $kind, 'kind' );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, PKIW\Meta_Fields::PREFIX . $suffix, $value );
		}
		$this->apply_cookie( $state );

		return $id;
	}

	/**
	 * Assert markers print in a visible state and none print in a locked one.
	 *
	 * @param string   $state   State name.
	 * @param string   $html    Rendered output.
	 * @param string[] $markers Fact values that must follow the state.
	 * @param bool     $visible Whether this state prints the facts; defaults to the post-level rule.
	 */
	private function assert_facts( string $state, string $html, array $markers, ?bool $visible = null ): void {
		$visible = $visible ?? $this->facts_visible( $state );
		foreach ( $markers as $marker ) {
			if ( $visible ) {
				$this->assertStringContainsString( $marker, $html, "State {$state} prints {$marker}." );
			} else {
				$this->assertStringNotContainsString( $marker, $html, "State {$state} leaked {$marker}." );
			}
		}
	}

	/**
	 * The menu line block for a post.
	 *
	 * @param int $id Post ID.
	 */
	private function menu_line( int $id ): string {
		$block = new WP_Block(
			[
				'blockName'    => PKIW\Kind_Archive_Layouts::MENU_ENTRY,
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
	 * Recent Specials for a kind.
	 *
	 * @param string $kind Kind slug.
	 */
	private function specials( string $kind ): string {
		$this->go_to( get_term_link( $kind, 'kind' ) );

		return render_block(
			[
				'blockName'    => PKIW\Kind_Archive_Layouts::MENU_SPECIALS,
				'attrs'        => [ 'kind' => $kind ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Menu line, eat kind.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_eat_menu_line( string $state ): void {
		$id   = $this->post_in_state(
			$state,
			'eat',
			'Menu Eat Title',
			[
				'eat_name'       => 'Menu Eat Card Name Zq1',
				'eat_rating'     => '4',
				'eat_restaurant' => 'Menu Eat Restaurant Zq2',
				'eat_notes'      => 'Menu eat note Zq3',
			]
		);
		$html = $this->menu_line( $id );

		$this->assert_facts( $state, $html, [ 'Menu Eat Card Name Zq1', 'Rated 4 of 5', 'Menu Eat Restaurant Zq2', 'Menu eat note Zq3' ] );
		if ( ! $this->facts_visible( $state ) ) {
			$this->assertStringContainsString( 'Menu Eat Title', $html );
			$this->assertStringNotContainsString( 'h-food', $html );
		}
	}

	/**
	 * Menu line, drink kind.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_drink_menu_line( string $state ): void {
		$id   = $this->post_in_state(
			$state,
			'drink',
			'Menu Drink Title',
			[
				'drink_name'    => 'Menu Drink Card Name Zq4',
				'drink_rating'  => '5',
				'drink_brewery' => 'Menu Drink Brewery Zq5',
				'drink_notes'   => 'Menu drink note Zq6',
			]
		);
		$html = $this->menu_line( $id );

		$this->assert_facts( $state, $html, [ 'Menu Drink Card Name Zq4', 'Rated 5 of 5', 'Menu Drink Brewery Zq5', 'Menu drink note Zq6' ] );
		if ( ! $this->facts_visible( $state ) ) {
			$this->assertStringContainsString( 'Menu Drink Title', $html );
			$this->assertStringNotContainsString( 'h-food', $html );
		}
	}

	/**
	 * Recent Specials.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_recent_specials( string $state ): void {
		$id = $this->post_in_state(
			$state,
			'drink',
			'Special Drink Title',
			[
				'drink_name'    => 'Special Card Name Zq7',
				'drink_rating'  => '3',
				'drink_brewery' => 'Special Brewery Zq8',
				'drink_notes'   => 'Special note Zq9',
				'drink_photo'   => 'https://example.com/special-photo-zq10.jpg',
			]
		);
		$html = $this->specials( 'drink' );

		$this->assert_facts( $state, $html, [ 'Special Card Name Zq7', 'Rated 3 of 5', 'Special Brewery Zq8', 'Special note Zq9', 'special-photo-zq10.jpg' ] );
		$this->assertStringContainsString( (string) get_permalink( $id ), $html );
		if ( ! $this->facts_visible( $state ) ) {
			$this->assertStringContainsString( 'Special Drink Title', $html );
		}
	}

	/**
	 * Archive group sources whose heading names a card fact.
	 *
	 * @return array<string, array{0:string,1:string,2:string,3:string[]}>
	 */
	public static function group_cases(): array {
		return [
			'eat menu (legacy cuisine)'  => [ 'eat', 'legacy-eat', 'eat_cuisine', [ 'Aaa Cuisine', 'Mmm Cuisine', 'Zzz Cuisine' ] ],
			'drink menu (legacy type)'   => [ 'drink', 'legacy-drink', 'drink_type', [ 'aaa-type', 'mmm-type', 'zzz-type' ] ],
			'read status'                => [ 'read', 'read-status', 'read_status', [ 'reading', 'to-read', 'finished' ] ],
			'play type'                  => [ 'play', 'play_type', '', [ 'board', 'video', 'board' ] ],
		];
	}

	/**
	 * The group source a case names.
	 *
	 * @param string $source Source id used by the case.
	 */
	private function group_source( string $source ): PKIW\Grouping\Group_Source {
		if ( 'legacy-eat' === $source ) {
			return PKIW\Grouping\Meta_Source::legacy( '_pkiw_eat_cuisine', 'eat' );
		}
		if ( 'legacy-drink' === $source ) {
			return PKIW\Grouping\Meta_Source::legacy( '_pkiw_drink_type', 'drink' );
		}
		$registered = PKIW\Grouped_Archive::source( $source );
		$this->assertNotNull( $registered, "Source {$source} is registered." );

		return $registered;
	}

	/**
	 * Meta that puts a play post in a group.
	 *
	 * @param string $group Group key.
	 * @return array<string, string>
	 */
	private function play_meta( string $group ): array {
		return [ 'play_' . ( 'video' === $group ? 'rawg_id' : 'bgg_id' ) => '777' ];
	}

	/**
	 * A group heading never names a locked post's fact, and its place in the
	 * grouped order matches its group, so its position names nothing either.
	 * Group sources key on the stored password, not the cookie, so an
	 * unlocked visitor sees the same filing as a locked one.
	 *
	 * @dataProvider group_cases
	 * @param string   $kind   Kind slug.
	 * @param string   $source Source id.
	 * @param string   $key    Meta suffix, or '' for play.
	 * @param string[] $values Values of the earlier, tested and later posts.
	 */
	public function test_group_heading_and_position( string $kind, string $source, string $key, array $values ): void {
		foreach ( [ 'public', 'locked', 'wrong', 'unlocked' ] as $state ) {
			$meta  = static fn( string $value ): array => '' === $key ? [] : [ $key => $value ];
			$mk    = fn( string $s, string $value ): int => $this->post_in_state( $s, $kind, "{$kind} {$value} {$s}", '' === $key ? $this->play_meta( $value ) : $meta( $value ) );
			$first = $mk( 'public', $values[0] );
			$mid   = $mk( $state, $values[1] );
			$last  = $mk( 'public', $values[2] );

			$group = $this->group_source( $source )->group_of( get_post( $mid ) );
			if ( 'public' === $state ) {
				$this->assertNotSame( '', $group->key(), "Public {$kind} post files under its value." );
			} else {
				$this->assertSame( '', $group->key(), "State {$state} files in the empty group." );
				$this->assertStringNotContainsString( strtolower( $values[1] ), strtolower( PKIW\Grouped_Archive::group_label_of_post( $kind, $mid ) ), "State {$state} heading names the value." );
			}

			$legacy = str_starts_with( $source, 'legacy' );
			$query  = new WP_Query(
				[
					'post_type'      => 'post',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => [ [ 'taxonomy' => 'kind', 'field' => 'slug', 'terms' => $kind ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				] + ( $legacy ? [ 'pkiw_group_by' => '_pkiw_' . $key ] : [ 'pkiw_group_source' => $source ] )
			);
			$ids    = array_map( 'intval', $query->posts );
			$at     = array_search( $mid, $ids, true );
			$this->assertNotFalse( $at, 'The tested post is in the grouped list.' );
			if ( 'public' !== $state && ! in_array( $kind, [ 'play' ], true ) ) {
				$this->assertTrue( 0 === $at || count( $ids ) - 1 === $at, "State {$state} sits in the empty group at an end of the list, not between its neighbors." );
			}
			foreach ( [ $first, $mid, $last ] as $id ) {
				wp_delete_post( $id, true );
			}
		}
	}

	/**
	 * A published check-in in a state, with a venue, coordinates, an excerpt and a featured image.
	 *
	 * @param string $state State name.
	 * @param string $title Title.
	 */
	private function checkin_in_state( string $state, string $title ): int {
		$id = $this->post_in_state(
			$state,
			'checkin',
			$title,
			[
				'geo_privacy'      => 'public',
				'checkin_venue'    => 'Checkin Venue Zq11',
				'checkin_city'     => 'Checkin City Zq12',
				'checkin_latitude' => '41.123456',
				'checkin_longitude' => '-87.654321',
			],
			[ 'post_excerpt' => 'Checkin excerpt Zq13' ]
		);
		$thumb = self::factory()->attachment->create(
			[
				'post_parent'    => $id,
				'post_mime_type' => 'image/jpeg',
				'file'           => 'checkin-thumb-zq14.jpg',
			]
		);
		set_post_thumbnail( $id, $thumb );

		return $id;
	}

	/**
	 * The Check-ins Feed block in list mode.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function checkins_feed( array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => 'post-kinds-indieweb/checkins-feed',
				'attrs'        => $attrs + [
					'showMap'     => true,
					'showExcerpt' => true,
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Check-ins Feed, list mode: the entry stays, as a title link, and the
	 * venue, coordinates, map marker, picture and excerpt follow the state.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_checkins_feed_list_mode( string $state ): void {
		$id   = $this->checkin_in_state( $state, 'Feed Checkin Title Zq15' );
		$html = $this->checkins_feed();

		$this->assertStringContainsString( 'Feed Checkin Title Zq15', $html );
		$this->assertStringContainsString( (string) get_permalink( $id ), $html );
		$this->assert_facts( $state, $html, [ 'Checkin Venue Zq11', '41.123456', 'checkin-thumb-zq14.jpg' ] );
		$this->assert_facts( $state, $html, [ 'Checkin excerpt Zq13' ] );
	}

	/**
	 * A check-in's venue is named by the venue list it sits in, so a locked
	 * one is left out of every list at that venue, for locked and unlocked
	 * visitors alike (the list query reads the stored password).
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_checkins_at_venue_list( string $state ): void {
		$venue = self::factory()->term->create( [ 'taxonomy' => PKIW\Venue_Taxonomy::TAXONOMY, 'name' => 'Venue Zq16' ] );
		$id    = $this->checkin_in_state( $state, 'Venue List Checkin Zq17' );
		wp_set_object_terms( $id, [ $venue ], PKIW\Venue_Taxonomy::TAXONOMY );

		$listed = wp_list_pluck( PKIW\get_checkins_at_venue( $venue )->posts, 'ID' );
		$this->assertSame( 'public' === $state, in_array( $id, $listed, true ), "State {$state}." );
	}

	/**
	 * The venue a check-in names, as the template function reads it.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_checkin_location_function( string $state ): void {
		$id       = $this->checkin_in_state( $state, 'Location Checkin Zq18' );
		$location = PKIW\get_checkin_location( $id );

		if ( $this->facts_visible( $state ) ) {
			$this->assertSame( 'Checkin Venue Zq11', $location['name'] ?? '' );
		} else {
			$this->assertSame( [], $location );
		}
	}

	/**
	 * Recipe courses nav: a course only a locked recipe carries isn't listed.
	 * The recipe sits in a WP Recipe Maker course, which only its post's
	 * embed ties to the kind archive, so the course is a card fact.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_recipe_courses_nav( string $state ): void {
		pkiw_register_wprm_stand_in_types();
		( new PKIW\Taxonomy() )->create_default_terms();
		$recipe_id = self::factory()->post->create(
			[
				'post_type'   => 'wprm_recipe',
				'post_title'  => 'Recipe Zq20',
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $recipe_id, [ 'Course Zq19' ], 'wprm_course' );
		$id = $this->post_in_state(
			$state,
			'recipe',
			'Recipe Title Zq20',
			[],
			[ 'post_content' => '<!-- wp:wp-recipe-maker/recipe {"id":' . $recipe_id . '} -->[wprm-recipe id="' . $recipe_id . '"]<!-- /wp:wp-recipe-maker/recipe -->' ]
		);
		update_post_meta( $recipe_id, 'wprm_parent_post_id', $id );

		$names = wp_list_pluck( PKIW\recipe_archive_courses(), 'name' );
		$this->assertSame( 'public' === $state, in_array( 'Course Zq19', $names, true ), "State {$state}." );
	}

	/**
	 * Render one block with a post in context, as a Query Loop or template does.
	 *
	 * @param string               $name    Block name.
	 * @param array<string, mixed> $attrs   Block attributes.
	 * @param int                  $post_id Post in context.
	 * @param string               $html    Saved inner HTML.
	 */
	private function render_in_context( string $name, array $attrs, int $post_id, string $html = '' ): string {
		if ( $post_id > 0 ) {
			// A block that declares no postId context reads the loop's global post.
			$GLOBALS['post'] = get_post( $post_id );
			setup_postdata( $GLOBALS['post'] );
		}
		$block = new WP_Block(
			[
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			],
			[
				'postId'   => $post_id,
				'postType' => 'post',
			]
		);

		return $block->render();
	}

	/**
	 * Now Playing shows the newest public listen. A locked listen is skipped
	 * for every visitor: the block reads the list query's stored password.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_now_playing( string $state ): void {
		$this->post_in_state(
			$state,
			'listen',
			'Now Playing Title Zq23',
			[
				'listen_track'  => 'Now Playing Track Zq24',
				'listen_artist' => 'Now Playing Artist Zq25',
			]
		);
		$html = $this->render_in_context( 'post-kinds/now-playing', [ 'kind' => 'listen' ], 0 );

		$this->assert_facts( $state, $html, [ 'Now Playing Track Zq24', 'Now Playing Artist Zq25' ], 'public' === $state );
	}

	/**
	 * The Kindle preview embed derives its iframe from the read post's ASIN.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_kindle_embed_bridge( string $state ): void {
		new PKIW\Kindle_Embed_Bridge();
		$id   = $this->post_in_state( $state, 'read', 'Kindle Title Zq26', [ 'read_asin' => 'B0ZQ27ASIN' ] );
		$html = $this->render_in_context(
			'core/embed',
			[
				'url'       => 'https://example.com/saved-embed',
				'className' => PKIW\Kindle_Embed_Bridge::MARKER,
			],
			$id,
			'<figure class="wp-block-embed pkiw-kindle-preview"><div class="wp-block-embed__wrapper">saved</div></figure>'
		);

		$this->assert_facts( $state, $html, [ 'B0ZQ27ASIN' ] );
		if ( ! $this->facts_visible( $state ) ) {
			$this->assertStringContainsString( 'saved', $html, 'A locked post keeps the embed as saved.' );
		}
	}

	/**
	 * A Drink card in a template falls back to the post's drink type.
	 *
	 * @dataProvider states
	 * @param string $state State name.
	 */
	public function test_drink_card_meta_fallback( string $state ): void {
		$id   = $this->post_in_state( $state, 'drink', 'Drink Card Title Zq28', [ 'drink_type' => 'Type Zq29' ] );
		$html = $this->render_in_context( 'post-kinds-indieweb/drink-card', [], $id );

		$this->assert_facts( $state, $html, [ 'Type Zq29' ] );
	}
}

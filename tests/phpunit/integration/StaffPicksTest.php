<?php
/**
 * The Staff Picks block above the play archive (issue 232).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Staff Picks lists the top-rated board game plays: published plays with
 * a BGG ID and no RAWG or Steam ID, a rating above 0, no password, ordered
 * by rating (a stored rating above 5 counts as 5), then date, then ID, one
 * per game. Each item is a box, a title link and "Rated N of 5", with no
 * microformats root. Past the first page, or with no qualifying play, the
 * block prints nothing at all.
 *
 * @group integration
 */
final class StaffPicksTest extends WP_UnitTestCase {

	/**
	 * Block name. A string here, so a missing block fails the assertions
	 * instead of stopping the class on an unknown constant.
	 */
	private const BLOCK = 'post-kinds-indieweb/staff-picks';

	public function set_up(): void {
		parent::set_up();
		// No remote artwork fetches from fixture covers.
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A post of the play kind with play meta.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $meta  Play meta suffix => value (`_pkiw_play_<suffix>`).
	 * @param string               $date  Post date.
	 * @param array<string, mixed> $post  Extra post fields.
	 */
	private function play( string $title, array $meta, string $date = '2026-03-01 10:00:00', array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => $date,
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'play', 'kind' );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, '_pkiw_play_' . $suffix, $value );
		}

		return $id;
	}

	/**
	 * A board game play: a BGG ID and a rating.
	 *
	 * @param string           $title  Post title.
	 * @param string           $bgg    BGG ID.
	 * @param int|float|string $rating Stored rating.
	 * @param string           $date   Post date.
	 */
	private function board( string $title, string $bgg, $rating, string $date = '2026-03-01 10:00:00' ): int {
		return $this->play(
			$title,
			[
				'bgg_id' => $bgg,
				'rating' => $rating,
			],
			$date
		);
	}

	/**
	 * Render the block as a template would.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function render( array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * DOM of a render.
	 *
	 * @param string $html Markup.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );

		return new DOMXPath( $dom );
	}

	/**
	 * The picks, in document order: title link text and href.
	 *
	 * @param string $html Markup.
	 * @return array<int, array{title: string, href: string}>
	 */
	private function picks( string $html ): array {
		$out = [];
		foreach ( $this->xpath( $html )->query( '//section/ul/li//a' ) as $a ) {
			$out[] = [
				'title' => trim( $a->textContent ),
				'href'  => $a->getAttribute( 'href' ),
			];
		}

		return $out;
	}

	/**
	 * Titles of the picks, in order.
	 *
	 * @param string $html Markup.
	 * @return string[]
	 */
	private function titles( string $html ): array {
		return wp_list_pluck( $this->picks( $html ), 'title' );
	}

	public function test_the_block_is_registered_with_its_editor_script_and_stylesheet(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertNotNull( $block );
		$this->assertContains( 'pkiw-staff-picks-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-staff-picks-editor', 'registered' ) );
		$this->assertContains( 'pkiw-staff-picks', $block->style_handles );
		$this->assertTrue( wp_style_is( 'pkiw-staff-picks', 'registered' ) );
		$this->assertSame( 3, $block->attributes['count']['default'] );
		$this->assertSame( 2, $block->attributes['headingLevel']['default'] );
		$this->assertSame( 'content', $block->attributes['heading']['role'], 'A contentOnly pattern can still rename the heading.' );
	}

	public function test_it_lists_board_plays_by_rating_then_newest(): void {
		$this->board( 'Meadow Songs', '9990004', 3, '2026-03-01 10:00:00' );
		$forest = $this->board( 'Forest Paths', '9990001', 5, '2026-01-01 10:00:00' );
		$this->board( 'Harbor Lanterns', '900101', 4, '2026-02-01 10:00:00' );
		$this->board( 'Orbit Table', '9990002', 4, '2026-02-15 10:00:00' );
		$this->go_to( get_term_link( 'play', 'kind' ) );

		$html = $this->render( [ 'count' => 6 ] );

		$this->assertSame( [ 'Forest Paths', 'Orbit Table', 'Harbor Lanterns', 'Meadow Songs' ], $this->titles( $html ) );
		$this->assertSame( get_permalink( $forest ), $this->picks( $html )[0]['href'] );
	}

	public function test_a_tie_on_rating_and_date_puts_the_higher_id_first(): void {
		$first  = $this->board( 'First Table', '1001', 4, '2026-02-01 10:00:00' );
		$second = $this->board( 'Second Table', '1002', 4, '2026-02-01 10:00:00' );
		$this->assertGreaterThan( $first, $second );

		$this->assertSame( [ 'Second Table', 'First Table' ], $this->titles( $this->render() ) );
	}

	public function test_a_game_shows_once_at_its_best_ranked_play_and_the_next_game_fills_its_place(): void {
		$forest = $this->board( 'Forest Paths', '9990001', 5, '2026-01-01 10:00:00' );
		$this->board( 'Forest Paths repeat', '9990001', 4, '2026-03-01 10:00:00' );
		$this->board( 'Orbit Table', '9990002', 4, '2026-02-01 10:00:00' );
		$this->board( 'Meadow Songs', '9990004', 3, '2026-03-02 10:00:00' );
		$this->board( 'Harbor Lanterns', '900101', 3, '2026-01-02 10:00:00' );

		$html = $this->render();

		$this->assertSame( [ 'Forest Paths', 'Orbit Table', 'Meadow Songs' ], $this->titles( $html ) );
		$this->assertSame( get_permalink( $forest ), $this->picks( $html )[0]['href'] );
	}

	/**
	 * More repeats of one game than the query reads at once still leave
	 * room for the next game.
	 */
	public function test_many_repeats_of_one_game_still_leave_room_for_the_next(): void {
		foreach ( range( 1, 30 ) as $day ) {
			$this->board( 'Forest Paths ' . $day, '9990001', 5, sprintf( '2026-01-%02d 10:00:00', $day ) );
		}
		$this->board( 'Orbit Table', '9990002', 4, '2025-12-01 10:00:00' );

		$this->assertSame( [ 'Forest Paths 30', 'Orbit Table' ], $this->titles( $this->render( [ 'count' => 2 ] ) ) );
	}

	public function test_video_and_both_id_plays_are_left_out(): void {
		$this->play(
			'Starbound Courier',
			[
				'rawg_id' => '900001',
				'rating'  => 5,
			]
		);
		$this->play(
			'Garden Circuit',
			[
				'steam_id' => '900002',
				'rating'   => 5,
			]
		);
		$this->play(
			'Clockwork Harbor',
			[
				'rawg_id' => '900003',
				'bgg_id'  => '900103',
				'rating'  => 5,
			]
		);
		$this->play(
			'Steam Harbor',
			[
				'steam_id' => '900007',
				'bgg_id'   => '900107',
				'rating'   => 5,
			]
		);
		$this->play( 'Unrated Video', [ 'rating' => 5 ] );
		$this->board( 'Meadow Tiles', '900102', 1 );

		$this->assertSame( [ 'Meadow Tiles' ], $this->titles( $this->render() ) );
	}

	public function test_a_blank_or_whitespace_rawg_or_steam_row_keeps_a_play_on_the_board(): void {
		global $wpdb;

		$blank = $this->board( 'Blank Rawg', '2001', 4, '2026-02-01 10:00:00' );
		update_post_meta( $blank, '_pkiw_play_rawg_id', '' );
		$spaced = $this->board( 'Spaced Steam', '2002', 3, '2026-02-01 10:00:00' );
		// A raw row: the registered sanitizer would trim it on the way in.
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $spaced,
				'meta_key'   => '_pkiw_play_steam_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '   ', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $spaced, 'post_meta' );

		$this->assertSame( [ 'Blank Rawg', 'Spaced Steam' ], $this->titles( $this->render() ) );
	}

	/**
	 * Eighteen non-play posts on each environment carry stray play rows
	 * (the issue 232 inventory: watch, article, read, mood, checkin, photo,
	 * quote, comics, trip). Here each one also has a BGG ID and a top
	 * rating, so only the kind term keeps them out.
	 */
	public function test_non_play_posts_with_stray_play_rows_are_left_out(): void {
		// The play term exists, so only its scope keeps the strays out.
		$this->board( 'Harbor Lights', '9990003', 0 );
		$kinds = [ 'watch', 'watch', 'watch', 'article', 'article', 'article', 'article', 'read', 'mood', 'checkin', 'photo', 'photo', 'photo', 'photo', 'quote', 'quote', 'comics', 'trip' ];
		$this->assertCount( 18, $kinds );
		foreach ( $kinds as $i => $kind ) {
			$id = self::factory()->post->create(
				[
					'post_status' => 'publish',
					'post_title'  => 'Stray ' . $kind . ' ' . $i,
				]
			);
			wp_set_object_terms( $id, $kind, 'kind' );
			update_post_meta( $id, '_pkiw_play_status', 'playing' );
			update_post_meta( $id, '_pkiw_play_hours', '0' );
			update_post_meta( $id, '_pkiw_play_rating', 5 );
			update_post_meta( $id, '_pkiw_play_bgg_id', (string) ( 9000 + $i ) );
		}
		$this->go_to( get_term_link( 'play', 'kind' ) );

		$this->assertSame( '', $this->render(), 'Stray rows alone qualify nothing.' );

		$this->board( 'Meadow Tiles', '900102', 1 );

		$this->assertSame( [ 'Meadow Tiles' ], $this->titles( $this->render( [ 'count' => 6 ] ) ) );
	}

	public function test_password_protected_draft_and_private_plays_are_left_out(): void {
		$this->play(
			'Locked Box',
			[
				'bgg_id' => '9990018',
				'rating' => 5,
				'review' => 'A secret review.',
			],
			'2026-03-01 10:00:00',
			[ 'post_password' => 'secret' ]
		);
		$this->play(
			'Draft Table',
			[
				'bgg_id' => '9990021',
				'rating' => 5,
			],
			'2026-03-01 10:00:00',
			[ 'post_status' => 'draft' ]
		);
		$this->play(
			'Private Table',
			[
				'bgg_id' => '9990022',
				'rating' => 5,
			],
			'2026-03-01 10:00:00',
			[ 'post_status' => 'private' ]
		);
		$this->board( 'Meadow Tiles', '900102', 1, '2026-01-01 10:00:00' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = $this->render( [ 'count' => 6 ] );

		$this->assertSame( [ 'Meadow Tiles' ], $this->titles( $html ) );
		$this->assertStringNotContainsString( 'Locked Box', $html );
		$this->assertStringNotContainsString( 'secret', $html );
	}

	public function test_a_stored_rating_above_five_sorts_and_prints_as_five(): void {
		$this->board( 'Eight Stored', '3001', 8, '2026-01-01 10:00:00' );
		$this->board( 'Five Newer', '3002', 5, '2026-02-01 10:00:00' );
		$this->board( 'Four and a Half', '3003', 4.5, '2026-03-01 10:00:00' );

		$html  = $this->render();
		$xpath = $this->xpath( $html );

		$this->assertSame( [ 'Five Newer', 'Eight Stored', 'Four and a Half' ], $this->titles( $html ), 'A stored 8 ranks as 5, so the newer 5 leads.' );
		$ratings = [];
		foreach ( $xpath->query( '//li/p[contains(@class,"pkiw-staff-picks__rating")]' ) as $p ) {
			$ratings[] = trim( $p->textContent );
		}
		$this->assertSame( [ 'Rated 5 of 5', 'Rated 5 of 5', 'Rated 4.5 of 5' ], $ratings );
		$this->assertStringNotContainsString( 'Rated 8', $html );
	}

	public function test_count_is_one_to_six_and_defaults_to_three(): void {
		foreach ( range( 1, 8 ) as $day ) {
			$this->board( 'Table ' . $day, (string) ( 4000 + $day ), 4, sprintf( '2026-01-%02d 10:00:00', $day ) );
		}

		$this->assertCount( 3, $this->picks( $this->render() ) );
		$this->assertCount( 1, $this->picks( $this->render( [ 'count' => 0 ] ) ) );
		$this->assertCount( 6, $this->picks( $this->render( [ 'count' => 40 ] ) ) );
		$this->assertCount( 5, $this->picks( $this->render( [ 'count' => 5 ] ) ) );
	}

	public function test_it_prints_nothing_past_the_first_page(): void {
		update_option( 'posts_per_page', 1 );
		$this->board( 'Forest Paths', '9990001', 5, '2026-01-01 10:00:00' );
		$this->board( 'Orbit Table', '9990002', 4, '2026-02-01 10:00:00' );

		$this->go_to( get_term_link( 'play', 'kind' ) );
		$this->assertNotSame( '', $this->render(), 'Page 1 lists the picks.' );

		$this->go_to( add_query_arg( 'paged', 2, get_term_link( 'play', 'kind' ) ) );
		$this->assertTrue( is_paged() );
		$this->assertSame( '', $this->render() );
	}

	public function test_it_prints_nothing_heading_included_when_no_play_qualifies(): void {
		global $wpdb;

		$this->assertSame( '', $this->render(), 'No plays at all.' );

		$this->board( 'Harbor Lights', '9990003', 0 );
		$this->board( 'Blank Rating', '9990010', '' );
		$this->board( 'Negative Rating', '9990012', -3 );
		$word = $this->play( 'Word Rating', [ 'bgg_id' => '9990011' ] );
		// A raw row: the registered sanitizer would store 0.
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $word,
				'meta_key'   => '_pkiw_play_rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'great', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $word, 'post_meta' );
		$this->play( 'No BGG', [ 'rating' => 4 ] );
		$this->go_to( get_term_link( 'play', 'kind' ) );

		$this->assertSame( '', $this->render() );
		$this->assertSame( '', $this->render( [ 'heading' => 'Our favorites' ] ) );
	}

	public function test_an_item_holds_the_box_one_title_link_and_the_rating_and_nothing_else(): void {
		$card = [
			'title'       => 'Forest Paths',
			'bggId'       => '9990001',
			'platform'    => 'Board Game',
			'status'      => 'completed',
			'hoursPlayed' => 2.5,
			'rating'      => 5,
			'review'      => 'Great table presence.',
			'playedAt'    => '2026-09-20',
			'gameUrl'     => 'https://example.test/games/forest-paths',
			'officialUrl' => 'https://example.test/official',
			'purchaseUrl' => 'https://example.test/buy',
		];
		$id   = $this->play(
			'Forest Paths',
			[
				'bgg_id'       => '9990001',
				'rating'       => 5,
				'platform'     => 'Board Game',
				'status'       => 'completed',
				'hours'        => '2.5',
				'review'       => 'Great table presence.',
				'game_url'     => 'https://example.test/games/forest-paths',
				'official_url' => 'https://example.test/official',
				'purchase_url' => 'https://example.test/buy',
			],
			'2026-03-01 10:00:00',
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $card ) . ' /-->' ]
		);

		$html  = $this->render();
		$xpath = $this->xpath( $html );
		$items = $xpath->query( '//section/ul/li' );

		$this->assertSame( 1, $items->length );
		$li = $items->item( 0 );

		$links = $xpath->query( './/a', $li );
		$this->assertSame( 1, $links->length, 'One link per item.' );
		$this->assertSame( get_permalink( $id ), $links->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'Forest Paths', trim( $links->item( 0 )->textContent ) );
		$this->assertSame( 1, $xpath->query( './h3/a[contains(@class,"pkiw-staff-picks__link")]', $li )->length );
		$this->assertSame( 'Rated 5 of 5', trim( $xpath->query( './p[contains(@class,"pkiw-staff-picks__rating")]', $li )->item( 0 )->textContent ) );

		foreach ( [ 'Board Game', 'ompleted', '2.5', 'hour', 'Great table presence', 'example.test', 'September', '2026', '★', 'pk-star', '<svg', '<time', 'role="img"' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html, $absent . ' does not print in a pick.' );
		}
	}

	public function test_covers_are_decorative_and_only_the_first_item_loads_eagerly(): void {
		$forest  = $this->board( 'Forest Paths', '9990001', 5, '2026-01-01 10:00:00' );
		$picture = self::factory()->attachment->create_object( 'forest-paths-box.png', 0, [ 'post_mime_type' => 'image/png' ] );
		update_post_meta( $picture, '_wp_attachment_image_alt', 'Forest Paths box' );
		set_post_thumbnail( $forest, $picture );
		$orbit = $this->board( 'Orbit Table', '9990002', 4, '2026-01-01 10:00:00' );
		update_post_meta( $orbit, '_pkiw_play_cover', 'https://example.test/covers/orbit.jpg' );
		$this->board( 'Meadow Songs', '9990004', 3, '2026-01-01 10:00:00' );

		$html  = $this->render();
		$xpath = $this->xpath( $html );
		$items = $xpath->query( '//section/ul/li' );
		$this->assertSame( 3, $items->length );

		$first = $xpath->query( './/img', $items->item( 0 ) )->item( 0 );
		$this->assertNotNull( $first );
		$this->assertStringContainsString( 'forest-paths-box.png', $first->getAttribute( 'src' ) );
		$this->assertTrue( $first->hasAttribute( 'alt' ) );
		$this->assertSame( '', $first->getAttribute( 'alt' ), 'The title link names the item; the box is decoration.' );
		$this->assertSame( 'eager', $first->getAttribute( 'loading' ) );
		$this->assertSame( 'high', $first->getAttribute( 'fetchpriority' ) );

		$second = $xpath->query( './/img', $items->item( 1 ) )->item( 0 );
		$this->assertNotNull( $second );
		$this->assertSame( 'https://example.test/covers/orbit.jpg', $second->getAttribute( 'src' ) );
		$this->assertTrue( $second->hasAttribute( 'alt' ) );
		$this->assertSame( '', $second->getAttribute( 'alt' ) );
		$this->assertSame( 'lazy', $second->getAttribute( 'loading' ) );
		$this->assertFalse( $second->hasAttribute( 'fetchpriority' ) );

		$this->assertSame( 2, $xpath->query( '//img' )->length );
		$this->assertSame( 1, $xpath->query( '//img[@fetchpriority]' )->length );
		$this->assertStringNotContainsString( 'Forest Paths box', $html );

		$box = $xpath->query( './*[contains(concat(" ", @class, " "), " pkiw-staff-picks__box ")]', $items->item( 2 ) )->item( 0 );
		$this->assertNotNull( $box, 'A play with no picture draws a typographic box.' );
		$this->assertStringContainsString( 'pkiw-staff-picks__box--text', $box->getAttribute( 'class' ) );
		$this->assertSame( 'true', $box->getAttribute( 'aria-hidden' ) );
		$this->assertSame( 'Meadow Songs', trim( $box->textContent ) );
	}

	public function test_an_untitled_play_shows_its_untitled_name_without_a_p_name(): void {
		$id = $this->board( '', '9990017', 4 );

		$html = $this->render();
		$name = \PKIW\untitled_name( get_post( $id ) );

		$this->assertNotSame( '', $name );
		$this->assertSame( [ $name ], $this->titles( $html ) );
		$this->assertStringNotContainsString( 'p-name', $html );
	}

	public function test_a_pick_is_not_a_second_entry_for_the_post(): void {
		$this->board( 'Forest Paths', '9990001', 5 );

		$html = $this->render();
		$this->assertNotSame( '', $html );

		$parsed = \Mf2\parse( $html, home_url( '/' ) );
		$this->assertSame( [], $parsed['items'] );
		$this->assertSame( 0, preg_match( '/class="[^"]*\b(?:h|p|u|dt|e)-[a-z]/', $html ), 'No microformats class at all.' );
	}

	public function test_it_is_a_section_named_by_its_editable_heading(): void {
		$this->board( 'Forest Paths', '9990001', 5 );

		$html = $this->render();
		$this->assertSame( 1, preg_match( '/<h2 id="([^"]+)" class="pkiw-staff-picks__heading">Staff Picks<\/h2>/', $html, $heading ) );
		$this->assertMatchesRegularExpression( '/^<section [^>]*aria-labelledby="' . preg_quote( $heading[1], '/' ) . '"/', $html );
		$classes = explode( ' ', (string) $this->xpath( $html )->query( '//section' )->item( 0 )->getAttribute( 'class' ) );
		$this->assertContains( 'pkiw-staff-picks', $classes );
		$this->assertContains( 'wp-block-post-kinds-indieweb-staff-picks', $classes );
		$this->assertStringContainsString( '<h3 class="pkiw-staff-picks__title">', $html );

		$deeper = $this->render( [ 'headingLevel' => 3 ] );
		$this->assertStringContainsString( 'class="pkiw-staff-picks__heading">Staff Picks</h3>', $deeper );
		$this->assertStringContainsString( '<h4 class="pkiw-staff-picks__title">', $deeper );

		$this->assertStringContainsString( 'class="pkiw-staff-picks__heading">Game &amp; Table Favorites</h2>', $this->render( [ 'heading' => 'Game & Table Favorites' ] ) );
		$this->assertStringContainsString( 'class="pkiw-staff-picks__heading">&lt;b&gt;Bold&lt;/b&gt;</h2>', $this->render( [ 'heading' => '<b>Bold</b>' ] ) );
		$this->assertStringContainsString( 'class="pkiw-staff-picks__heading">Staff Picks</h2>', $this->render( [ 'heading' => '  ' ] ) );
	}

	public function test_the_editor_render_lists_the_picks(): void {
		$this->board( 'Forest Paths', '9990001', 5 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . self::BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'attributes', [ 'count' => 2 ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'Forest Paths' ], $this->titles( (string) $response->get_data()['rendered'] ) );
	}
}

<?php
/**
 * The board game tabletop on the play card (issue 232 PL7).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * A play card whose provider IDs file it as a board game prints the
 * tabletop: a box, a facts list, a links row and a score pad, in that DOM
 * order, behind the `pk-card--tabletop` marker.
 *
 * @group integration
 */
final class PlayCardTabletopTest extends WP_UnitTestCase {

	/**
	 * The new-tab hint's text, as a screen reader reads it.
	 */
	private const HINT = ' (opens in a new tab)';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		// The test framework drops registered meta between tests.
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		\PKIW\register_play_kind();
		add_filter( 'pre_http_request', '__return_empty_array' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		update_option( 'date_format', 'F j, Y' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Forest Paths, the B1 fixture, with every tabletop field stored.
	 *
	 * @return array<string, mixed>
	 */
	private static function forest_paths(): array {
		return [
			'title'       => 'Forest Paths',
			'platform'    => 'Board Game',
			'status'      => 'completed',
			'hoursPlayed' => 2.5,
			'rating'      => 4,
			'review'      => 'Tense to the last turn.',
			'gameUrl'     => 'https://example.test/games/forest-paths',
			'officialUrl' => 'https://forest.example/',
			'purchaseUrl' => 'https://shop.example/forest-paths',
			'bggId'       => '9990001',
			'playedAt'    => '2026-09-20',
			'cover'       => 'https://example.test/covers/forest-paths.jpg',
			'coverAlt'    => 'Forest Paths box',
		];
	}

	/**
	 * A published play post holding one play card.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post args.
	 * @return int Post ID.
	 */
	private function board( array $attrs, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Forest Paths',
					'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'play', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Render a post's content on its own single.
	 *
	 * @param int $id Post ID.
	 */
	private function on_single( int $id ): string {
		$this->go_to( get_permalink( $id ) );

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * Render a post's content in a loop away from its single, as the
	 * archive and the Stream do.
	 *
	 * @param int $id Post ID.
	 */
	private function in_a_loop( int $id ): string {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * XPath over a card's HTML.
	 *
	 * @param string $html Card HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * XPath test for a class token.
	 *
	 * @param string $token Class token.
	 */
	private static function has_class( string $token ): string {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")';
	}

	/**
	 * The card's root element.
	 *
	 * @param DOMXPath $xpath XPath.
	 */
	private function root( DOMXPath $xpath ): DOMElement {
		$root = $xpath->query( '/html/body/div[@id="root"]/article' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $root );

		return $root;
	}

	/**
	 * Whitespace-collapsed text of a node.
	 *
	 * @param DOMNode|null $node Node.
	 */
	private function text( ?DOMNode $node ): string {
		$this->assertNotNull( $node );

		return trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
	}

	/**
	 * What a screen reader can reach: text outside `hidden` and
	 * `aria-hidden="true"` subtrees, plus aria-label values.
	 *
	 * @param DOMNode $node Node.
	 */
	private function accessible_text( DOMNode $node ): string {
		if ( $node instanceof DOMText ) {
			return $node->data;
		}
		if ( $node instanceof DOMElement ) {
			if ( $node->hasAttribute( 'hidden' ) || 'true' === $node->getAttribute( 'aria-hidden' ) ) {
				return '';
			}
			if ( $node->hasAttribute( 'aria-label' ) ) {
				return ' ' . $node->getAttribute( 'aria-label' ) . ' ';
			}
		}

		$text = '';
		foreach ( $node->childNodes as $child ) {
			$text .= $this->accessible_text( $child );
		}

		return $text;
	}

	/**
	 * The h-cite the entry's play-of holds.
	 *
	 * @param string $html Card HTML.
	 * @return array<string, mixed>
	 */
	private function play_of( string $html ): array {
		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$this->assertSame( [ 'h-entry' ], $parsed['items'][0]['type'] ?? null );
		$values = $parsed['items'][0]['properties']['play-of'] ?? [];
		$this->assertCount( 1, $values );
		$this->assertSame( [ 'h-cite' ], $values[0]['type'] ?? null );

		return $values[0];
	}

	public function test_a_board_card_carries_the_tabletop_marker(): void {
		$root = $this->root( $this->xpath( $this->on_single( $this->board( self::forest_paths() ) ) ) );

		$classes = preg_split( '/\s+/', trim( $root->getAttribute( 'class' ) ) );
		$this->assertContains( 'pk-card--tabletop', $classes );
		$this->assertContains( 'h-cite', $classes );
		$this->assertContains( 'u-play-of', $classes );
		$this->assertSame( 'board', $root->getAttribute( 'data-pkiw-play-group' ) );
	}

	/**
	 * The gate is play_group_of_attrs(): a BGG ID beside a video ID files
	 * the card as a video game, which keeps the shared card.
	 */
	public function test_a_bgg_id_beside_a_video_id_is_not_a_tabletop(): void {
		$html = $this->on_single( $this->board( array_merge( self::forest_paths(), [ 'rawgId' => '900003' ] ) ) );

		$this->assertStringNotContainsString( 'pk-card--tabletop', $html );
		$this->assertStringNotContainsString( 'data-pkiw-play-group', $html );
	}

	public function test_the_tabletop_prints_box_facts_links_and_pad_in_dom_order(): void {
		$xpath = $this->xpath( $this->on_single( $this->board( self::forest_paths() ) ) );

		$order = [];
		foreach ( $this->root( $xpath )->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || $child->hasAttribute( 'hidden' ) ) {
				continue;
			}
			$order[] = $child->tagName . '.' . strtok( trim( $child->getAttribute( 'class' ) ), ' ' );
		}

		$this->assertSame( [ 'figure.pk-box', 'dl.pk-facts', 'div.pk-links', 'section.pk-scorepad' ], $order );

		foreach ( [ 'pk-kindlabel', 'pk-sub', 'pk-media', 'pk-meta', 'pk-caption' ] as $gone ) {
			$this->assertSame( 0, $xpath->query( '//*[' . self::has_class( $gone ) . ']' )->length, "{$gone} prints on a tabletop" );
		}
	}

	public function test_the_facts_list_prints_platform_status_played_and_hours(): void {
		$xpath = $this->xpath( $this->on_single( $this->board( self::forest_paths() ) ) );

		$facts = [];
		foreach ( $xpath->query( '//dl[' . self::has_class( 'pk-facts' ) . ']//dt' ) as $term ) {
			$facts[ $this->text( $term ) ] = $this->text( $xpath->query( 'following-sibling::dd[1]', $term )->item( 0 ) );
		}

		$this->assertSame(
			[
				'Platform' => 'Board Game',
				'Status'   => 'Completed',
				'Played'   => 'September 20, 2026',
				'Hours'    => '2.5 hours played',
			],
			$facts
		);
		$this->assertSame( 'Board Game', $this->text( $xpath->query( '//*[' . self::has_class( 'pk-play-platform' ) . ']/dd' )->item( 0 ) ) );
		$this->assertSame( 'Completed', $this->text( $xpath->query( '//*[' . self::has_class( 'pk-play-status' ) . ']/dd' )->item( 0 ) ) );
		$this->assertSame( '2.5 hours played', $this->text( $xpath->query( '//*[' . self::has_class( 'pk-play-hours' ) . ']/dd' )->item( 0 ) ) );
	}

	/**
	 * @return array<string, array{0: float, 1: string}>
	 */
	public function hours(): array {
		return [
			'one' => [ 1, '1 hour played' ],
			'two' => [ 2, '2 hours played' ],
		];
	}

	/**
	 * @dataProvider hours
	 */
	public function test_the_hours_fact_is_plural_aware( float $hours, string $label ): void {
		$xpath = $this->xpath( $this->on_single( $this->board( array_merge( self::forest_paths(), [ 'hoursPlayed' => $hours ] ) ) ) );

		$this->assertSame( $label, $this->text( $xpath->query( '//*[' . self::has_class( 'pk-play-hours' ) . ']/dd' )->item( 0 ) ) );
	}

	public function test_no_hours_and_no_platform_print_no_rows(): void {
		$attrs = self::forest_paths();
		unset( $attrs['platform'] );
		$attrs['hoursPlayed'] = 0;
		$html                 = $this->on_single( $this->board( $attrs ) );

		$this->assertStringNotContainsString( 'pk-play-hours', $html );
		$this->assertStringNotContainsString( 'pk-play-platform', $html );
		$this->assertStringNotContainsString( 'played', $html );
	}

	/**
	 * 'Rated N of 5' is visible text, the stars are hidden from assistive
	 * tech, and p-rating stays as hidden data.
	 */
	public function test_rated_n_of_5_appears_once_in_the_accessibility_tree(): void {
		$html  = $this->on_single( $this->board( self::forest_paths() ) );
		$xpath = $this->xpath( $html );
		$pad   = $xpath->query( '//section[' . self::has_class( 'pk-scorepad' ) . ']' )->item( 0 );

		$this->assertSame( 1, substr_count( $this->accessible_text( $this->root( $xpath ) ), 'Rated 4 of 5' ) );
		$this->assertStringContainsString( 'Rated 4 of 5', $this->text( $pad ) );
		$stars = $xpath->query( './/div[' . self::has_class( 'pk-stars' ) . ']', $pad )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $stars );
		$this->assertSame( 'true', $stars->getAttribute( 'aria-hidden' ) );
		$this->assertFalse( $stars->hasAttribute( 'role' ) );
		$this->assertSame( 1, $xpath->query( './/data[' . self::has_class( 'p-rating' ) . '][@value="4"][@hidden]', $pad )->length );
	}

	/**
	 * A rating above 5 prints as 5, as the stars show it.
	 */
	public function test_a_stored_8_prints_rated_5_of_5(): void {
		$html = $this->on_single( $this->board( array_merge( self::forest_paths(), [ 'rating' => 8 ] ) ) );

		$this->assertStringContainsString( 'Rated 5 of 5', $html );
		$this->assertStringNotContainsString( 'Rated 8', $html );
	}

	public function test_the_review_heading_prints_only_with_a_review(): void {
		$with    = $this->xpath( $this->on_single( $this->board( self::forest_paths() ) ) );
		$heading = $with->query( '//section[' . self::has_class( 'pk-scorepad' ) . ']/h2' );
		$this->assertSame( 1, $heading->length );
		$this->assertSame( 'Review', $this->text( $heading->item( 0 ) ) );
		$this->assertSame( 'Tense to the last turn.', $this->text( $with->query( '//section[' . self::has_class( 'pk-scorepad' ) . ']/*[' . self::has_class( 'p-content' ) . ']' )->item( 0 ) ) );

		$attrs = self::forest_paths();
		unset( $attrs['review'] );
		$without = $this->on_single( $this->board( $attrs ) );
		$this->assertStringContainsString( 'pk-scorepad', $without );
		$this->assertStringNotContainsString( '>Review<', $without );
		$this->assertStringNotContainsString( 'p-content', $without );
	}

	public function test_a_review_with_no_rating_prints_the_pad_without_a_rating(): void {
		$attrs           = self::forest_paths();
		$attrs['rating'] = 0;
		$xpath           = $this->xpath( $this->on_single( $this->board( $attrs ) ) );
		$pad             = $xpath->query( '//section[' . self::has_class( 'pk-scorepad' ) . ']' )->item( 0 );

		$this->assertNotNull( $pad );
		$this->assertStringNotContainsString( 'Rated', $this->text( $pad ) );
		$this->assertSame( 0, $xpath->query( '//*[' . self::has_class( 'pk-stars' ) . ' or ' . self::has_class( 'p-rating' ) . ']' )->length );
		$this->assertSame( 'Review', $this->text( $xpath->query( 'h2', $pad )->item( 0 ) ) );
	}

	/**
	 * The Harbor Lights shape (B6): no rating and no review, so no pad.
	 */
	public function test_no_rating_and_no_review_print_no_pad(): void {
		$attrs           = self::forest_paths();
		$attrs['rating'] = 0;
		unset( $attrs['review'] );
		$html = $this->on_single( $this->board( $attrs, [ 'post_title' => 'Harbor Lights' ] ) );

		$this->assertStringNotContainsString( 'pk-scorepad', $html );
		$this->assertStringNotContainsString( 'Rated', $html );
		$this->assertStringNotContainsString( 'Review', $html );
	}

	/**
	 * Paper Skies (B7, B8): only a real calendar day prints, as a date.
	 */
	public function test_the_played_row_needs_a_real_date_and_prints_no_time(): void {
		$impossible = $this->on_single( $this->board( array_merge( self::forest_paths(), [ 'playedAt' => '2026-02-30' ] ) ) );
		$this->assertStringNotContainsString( 'pk-play-played', $impossible );
		$this->assertStringNotContainsString( '<time', $impossible );
		$this->assertStringNotContainsString( '>Played<', $impossible );

		$late  = $this->xpath( $this->on_single( $this->board( array_merge( self::forest_paths(), [ 'playedAt' => '2026-09-20T23:30' ] ) ) ) );
		$times = $late->query( '//dl[' . self::has_class( 'pk-facts' ) . ']//time' );
		$this->assertSame( 1, $times->length );
		$this->assertSame( '2026-09-20', $times->item( 0 )->getAttribute( 'datetime' ) );
		$this->assertSame( 'September 20, 2026', $this->text( $times->item( 0 ) ) );
	}

	/**
	 * The title isn't a link, so the game URL has exactly one href, and an
	 * example.test URL reads as its host.
	 */
	public function test_the_tabletop_holds_one_game_url_href_labelled_by_host(): void {
		$html  = $this->on_single( $this->board( array_merge( self::forest_paths(), [ 'title' => 'Forest Paths Deluxe' ] ) ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, substr_count( $html, 'href="https://example.test/games/forest-paths"' ) );
		$link = $xpath->query( '//div[' . self::has_class( 'pk-links' ) . ']/a[@href="https://example.test/games/forest-paths"]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertSame( 'example.test' . self::HINT, $link->textContent );
		$this->assertContains( 'u-url', preg_split( '/\s+/', $link->getAttribute( 'class' ) ) );
		$this->assertSame( 0, $xpath->query( '//h2[' . self::has_class( 'pk-title' ) . ']//a' )->length );
		$this->assertStringNotContainsString( 'View on BGG', $html );
	}

	public function test_a_boardgamegeek_game_url_reads_view_on_bgg(): void {
		$html  = $this->on_single( $this->board( array_merge( self::forest_paths(), [ 'gameUrl' => 'https://boardgamegeek.com/boardgame/9990001/forest-paths' ] ) ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, substr_count( $html, 'href="https://boardgamegeek.com/' ) );
		$this->assertSame( 'View on BGG' . self::HINT, $xpath->query( '//div[' . self::has_class( 'pk-links' ) . ']/a[1]' )->item( 0 )->textContent );
	}

	public function test_official_and_buy_links_print_only_when_stored_with_the_hint(): void {
		$xpath = $this->xpath( $this->on_single( $this->board( self::forest_paths() ) ) );
		$links = $xpath->query( '//div[' . self::has_class( 'pk-links' ) . ']/a' );

		$labels = [];
		foreach ( $links as $link ) {
			$labels[ $link->getAttribute( 'href' ) ] = $link->textContent;
			$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
			$this->assertSame( 1, $xpath->query( './span[' . self::has_class( 'pk-sr-only' ) . ']', $link )->length );
		}
		$this->assertSame(
			[
				'https://example.test/games/forest-paths' => 'example.test' . self::HINT,
				'https://forest.example/'                 => 'Official Site' . self::HINT,
				'https://shop.example/forest-paths'       => 'Buy' . self::HINT,
			],
			$labels
		);

		$attrs = self::forest_paths();
		unset( $attrs['officialUrl'], $attrs['purchaseUrl'], $attrs['gameUrl'] );
		$bare = $this->on_single( $this->board( $attrs ) );
		$this->assertStringNotContainsString( 'pk-links', $bare );
		$this->assertStringNotContainsString( 'Official Site', $bare );
		$this->assertStringNotContainsString( 'Buy', $bare );
	}

	/**
	 * With a featured image, the box prints it, and the card's own cover
	 * <img> doesn't print.
	 */
	public function test_the_box_prints_the_featured_image_first(): void {
		$id    = $this->board( self::forest_paths() );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		set_post_thumbnail( $id, $image );

		$html  = $this->on_single( $id );
		$xpath = $this->xpath( $html );
		$imgs  = $xpath->query( '//img' );

		$this->assertSame( 1, $imgs->length );
		$this->assertSame( 1, $xpath->query( '//figure[' . self::has_class( 'pk-box' ) . ']/img' )->length );
		$this->assertSame( wp_basename( (string) wp_get_attachment_url( $image ) ), wp_basename( $imgs->item( 0 )->getAttribute( 'src' ) ) );
		$this->assertStringNotContainsString( 'forest-paths.jpg', $html );
		$this->assertSame( 'Forest Paths box', $imgs->item( 0 )->getAttribute( 'alt' ) );
	}

	/**
	 * Poster Problem (B15): Featured_Artwork's 'Poster for <title>' alt on
	 * the attachment gives way to the plugin's 'Box art for <title>'.
	 */
	public function test_the_box_alt_overrides_the_attachment_alt(): void {
		$attrs          = self::forest_paths();
		$attrs['title'] = 'Poster Problem';
		unset( $attrs['coverAlt'] );
		$id    = $this->board( $attrs, [ 'post_title' => 'Poster Problem' ] );
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Poster for Poster Problem' );
		set_post_thumbnail( $id, $image );

		$img = $this->xpath( $this->on_single( $id ) )->query( '//figure[' . self::has_class( 'pk-box' ) . ']/img' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $img );
		$this->assertSame( 'Box art for Poster Problem', $img->getAttribute( 'alt' ) );
	}

	/**
	 * With no featured image, the box prints the card's cover once.
	 */
	public function test_the_box_prints_the_cover_without_a_featured_image(): void {
		$attrs = self::forest_paths();
		unset( $attrs['coverAlt'] );
		$xpath = $this->xpath( $this->on_single( $this->board( $attrs ) ) );
		$imgs  = $xpath->query( '//img' );

		$this->assertSame( 1, $imgs->length );
		$this->assertSame( 'https://example.test/covers/forest-paths.jpg', $imgs->item( 0 )->getAttribute( 'src' ) );
		$this->assertSame( 'Box art for Forest Paths', $imgs->item( 0 )->getAttribute( 'alt' ) );
		$this->assertSame( 1, $xpath->query( '//figure[' . self::has_class( 'pk-box' ) . ']/img' )->length );
	}

	/**
	 * Orbit Table (B3): no picture, so no box.
	 */
	public function test_no_picture_prints_no_box(): void {
		$attrs = self::forest_paths();
		unset( $attrs['cover'], $attrs['coverAlt'] );
		$html = $this->on_single( $this->board( $attrs, [ 'post_title' => 'Orbit Table' ] ) );

		$this->assertStringNotContainsString( 'pk-box', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/**
	 * On the post's own single, a card title equal to the post title
	 * doesn't print a second heading; the name stays as hidden data.
	 */
	public function test_an_equal_title_is_hidden_data_on_the_single(): void {
		$html  = $this->on_single( $this->board( self::forest_paths() ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 0, $xpath->query( '//*[' . self::has_class( 'pk-title' ) . ']' )->length );
		$name = $xpath->query( '/html/body/div[@id="root"]/article/data[' . self::has_class( 'p-name' ) . ']' );
		$this->assertSame( 1, $name->length );
		$this->assertSame( 'Forest Paths', $name->item( 0 )->getAttribute( 'value' ) );
		$this->assertTrue( $name->item( 0 )->hasAttribute( 'hidden' ) );
		$this->assertSame( [ 'Forest Paths' ], $this->play_of( $html )['properties']['name'] ?? null );
	}

	/**
	 * Cartographers' Table (B9): a card title that differs from the post
	 * title prints as the card's heading, below the template's H1.
	 */
	public function test_a_different_title_prints_a_heading_on_the_single(): void {
		$attrs          = self::forest_paths();
		$attrs['title'] = 'Cartographer\'s Table: Deluxe';
		$xpath          = $this->xpath( $this->on_single( $this->board( $attrs, [ 'post_title' => 'Cartographers\' Table' ] ) ) );

		$heading = $xpath->query( '//h2[' . self::has_class( 'pk-title' ) . ' and ' . self::has_class( 'p-name' ) . ']' );
		$this->assertSame( 1, $heading->length );
		$this->assertSame( 'Cartographer\'s Table: Deluxe', $this->text( $heading->item( 0 ) ) );
		$this->assertSame( 0, $xpath->query( '//data[' . self::has_class( 'p-name' ) . ']' )->length );
	}

	/**
	 * Away from its single, as on the archive or the Stream, an equal title
	 * still prints as the heading the Stream links to the post.
	 */
	public function test_an_equal_title_prints_a_heading_away_from_the_single(): void {
		$xpath = $this->xpath( $this->in_a_loop( $this->board( self::forest_paths() ) ) );

		$heading = $xpath->query( '//h2[' . self::has_class( 'pk-title' ) . ' and ' . self::has_class( 'p-name' ) . ']' );
		$this->assertSame( 1, $heading->length );
		$this->assertSame( 'Forest Paths', $this->text( $heading->item( 0 ) ) );
		$this->assertSame( 0, $xpath->query( '//h2//a' )->length );
	}

	/**
	 * The editor previews the card through the block renderer with the
	 * post's ID, and that preview hides an equal title the way the single
	 * does.
	 */
	public function test_the_editor_preview_hides_an_equal_title(): void {
		$id = $this->board( self::forest_paths() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/play-card' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $id );
		$request->set_param( 'attributes', self::forest_paths() );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$html  = (string) ( $response->get_data()['rendered'] ?? '' );
		$xpath = $this->xpath( $html );
		$this->assertSame( 'board', $this->root( $xpath )->getAttribute( 'data-pkiw-play-group' ) );
		$this->assertSame( 0, $xpath->query( '//*[' . self::has_class( 'pk-title' ) . ']' )->length );
		$this->assertSame( 1, $xpath->query( '//data[' . self::has_class( 'p-name' ) . '][@value="Forest Paths"]' )->length );
	}

	/**
	 * php-mf2 reads the tabletop as the entry's play-of h-cite with its
	 * name, URL, BGG uid, rating and review.
	 */
	public function test_the_tabletop_parses_as_a_play_of_h_cite(): void {
		$attrs          = self::forest_paths();
		$attrs['title'] = 'Forest Paths Deluxe';
		$cite           = $this->play_of( $this->on_single( $this->board( $attrs ) ) );

		$this->assertSame( [ 'Forest Paths Deluxe' ], $cite['properties']['name'] ?? null );
		$this->assertSame( [ 'https://example.test/games/forest-paths' ], $cite['properties']['url'] ?? null );
		$this->assertSame( 'https://example.test/games/forest-paths', $cite['value'] ?? null );
		$this->assertSame( [ 'https://boardgamegeek.com/boardgame/9990001' ], $cite['properties']['uid'] ?? null );
		$this->assertSame( [ '4' ], $cite['properties']['rating'] ?? null );
		$this->assertSame( 'Tense to the last turn.', trim( (string) ( $cite['properties']['content'][0]['value'] ?? '' ) ) );
	}
}

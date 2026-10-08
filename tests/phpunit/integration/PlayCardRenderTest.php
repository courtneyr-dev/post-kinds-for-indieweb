<?php
/**
 * Markup every play card shares (issue 237 PL4).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * Hours read through play_hours_label(), the game link reads its host
 * through play_game_url_label(), the status, platform and hours carry
 * neutral class hooks, and u-play-of sits on the h-cite root.
 *
 * These cards name no BoardGameGeek ID, so they render the shared card,
 * not the board tabletop.
 *
 * @group integration
 */
final class PlayCardRenderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		// The test framework drops registered meta between tests.
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		\PKIW\register_play_kind();
		add_filter( 'pre_http_request', '__return_empty_array' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
	}

	/**
	 * Render a play card on its own published play post's single.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @return string Card HTML.
	 */
	private function render( array $attrs ): string {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Night Shift',
				'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->',
			]
		);
		wp_set_object_terms( $id, 'play', Taxonomy::TAXONOMY );
		$this->go_to( get_permalink( $id ) );

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
	 * Whitespace-collapsed text of the first node a query finds.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $query Query.
	 */
	private function text( DOMXPath $xpath, string $query ): string {
		$node = $xpath->query( $query )->item( 0 );
		$this->assertNotNull( $node, "Nothing matches {$query}" );

		return trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );
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
	 * @return array<string, array{0: float, 1: string}>
	 */
	public function hours(): array {
		return [
			'one'     => [ 1, '1 hour played' ],
			'two'     => [ 2, '2 hours played' ],
			'decimal' => [ 1.5, '1.5 hours played' ],
			'half'    => [ 3.5, '3.5 hours played' ],
		];
	}

	/**
	 * @dataProvider hours
	 */
	public function test_hours_print_the_play_hours_label( float $hours, string $label ): void {
		$xpath = $this->xpath(
			$this->render(
				[
					'title'       => 'Night Shift',
					'rawgId'      => '900005',
					'hoursPlayed' => $hours,
				]
			)
		);

		$this->assertSame( $label, $this->text( $xpath, '//span[' . self::has_class( 'pk-play-hours' ) . ']' ) );
		$this->assertSame( \PKIW\play_hours_label( $hours ), $label );
	}

	public function test_zero_hours_print_no_hours_text(): void {
		$html = $this->render(
			[
				'title'       => 'Night Shift',
				'rawgId'      => '900005',
				'status'      => 'wishlist',
				'hoursPlayed' => 0,
			]
		);

		$this->assertStringNotContainsString( 'pk-play-hours', $html );
		$this->assertStringNotContainsString( 'played', $html );
	}

	public function test_status_platform_and_hours_carry_neutral_hooks(): void {
		$xpath = $this->xpath(
			$this->render(
				[
					'title'       => 'Garden Circuit',
					'steamId'     => '900002',
					'status'      => 'completed',
					'platform'    => 'Nintendo Switch',
					'hoursPlayed' => 42,
				]
			)
		);

		$sub = '//p[' . self::has_class( 'pk-sub' ) . ']';
		$this->assertSame( 'Completed', $this->text( $xpath, $sub . '/span[' . self::has_class( 'pk-play-status' ) . ']' ) );
		$this->assertSame( 'Nintendo Switch', $this->text( $xpath, $sub . '/span[' . self::has_class( 'pk-play-platform' ) . ']' ) );
		$this->assertSame( '42 hours played', $this->text( $xpath, $sub . '/span[' . self::has_class( 'pk-play-hours' ) . ']' ) );
		$this->assertSame( \PKIW\play_status_labels()['completed'], 'Completed' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function game_urls(): array {
		return [
			'BoardGameGeek' => [ 'https://boardgamegeek.com/boardgame/13/catan', 'View on BGG' ],
			'RAWG'          => [ 'https://rawg.io/games/starbound-courier', 'View on RAWG' ],
			'Steam'         => [ 'https://store.steampowered.com/app/900002', 'View on Steam' ],
			'example.test'  => [ 'https://example.test/games/forest-paths', 'example.test' ],
			'www host'      => [ 'https://www.games.example/backyard-tag', 'games.example' ],
		];
	}

	/**
	 * The game link reads its host, the same text play facts give a theme.
	 *
	 * @dataProvider game_urls
	 */
	public function test_the_game_link_label_goes_by_host( string $url, string $label ): void {
		$xpath = $this->xpath(
			$this->render(
				[
					'title'   => 'Starbound Courier',
					'gameUrl' => $url,
				]
			)
		);

		$links = $xpath->query( '//div[' . self::has_class( 'pk-meta' ) . ']/a[@href="' . esc_url( $url ) . '"]' );
		$this->assertSame( 1, $links->length );
		$hint = $xpath->query( './/span[' . self::has_class( 'pk-sr-only' ) . ']', $links->item( 0 ) )->item( 0 );
		$this->assertNotNull( $hint );
		$this->assertSame( $label . $hint->textContent, $links->item( 0 )->textContent );
		$this->assertSame( \PKIW\play_game_url_label( $url ), $label );
	}

	/**
	 * Every link out of the card warns that it opens a new tab: the links
	 * row carries the hint span, and the title link, whose text is the
	 * p-name, carries the warning as its accessible name.
	 */
	public function test_every_external_link_warns_it_opens_a_new_tab(): void {
		$xpath = $this->xpath(
			$this->render(
				[
					'title'       => 'Starbound Courier',
					'rawgId'      => '900001',
					'gameUrl'     => 'https://rawg.example/games/starbound-courier',
					'officialUrl' => 'https://starbound.example/',
					'purchaseUrl' => 'https://shop.example/starbound',
				]
			)
		);

		$row = $xpath->query( '//div[' . self::has_class( 'pk-meta' ) . ']/a' );
		$this->assertSame( 3, $row->length );
		foreach ( $row as $link ) {
			$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
			$this->assertStringContainsString( wp_strip_all_tags( \PKIW\pkiw_new_tab_hint() ), $link->textContent );
			$this->assertSame( 1, $xpath->query( './/span[' . self::has_class( 'pk-sr-only' ) . ']', $link )->length );
		}

		$title_link = $xpath->query( '//h2[' . self::has_class( 'pk-title' ) . ']/a' )->item( 0 );
		$this->assertNotNull( $title_link );
		$this->assertSame( '_blank', $title_link->getAttribute( 'target' ) );
		$this->assertSame( 'Starbound Courier (opens in a new tab)', $title_link->getAttribute( 'aria-label' ) );
	}

	/**
	 * u-play-of sits on the h-cite root, and the separate data element that
	 * attached it to the citation instead of the entry is gone.
	 */
	public function test_u_play_of_sits_on_the_h_cite_root(): void {
		$html  = $this->render(
			[
				'title'   => 'Copper Kite',
				'rawgId'  => '900009',
				'gameUrl' => 'https://rawg.example/games/copper-kite',
			]
		);
		$xpath = $this->xpath( $html );

		$root = $xpath->query( '/html/body/div[@id="root"]/article' )->item( 0 );
		$this->assertNotNull( $root );
		$classes = preg_split( '/\s+/', trim( $root->getAttribute( 'class' ) ) );
		$this->assertContains( 'h-cite', $classes );
		$this->assertContains( 'u-play-of', $classes );
		$this->assertSame( 1, $xpath->query( '//*[' . self::has_class( 'u-play-of' ) . ']' )->length );
		$this->assertStringNotContainsString( '<data class="u-play-of"', $html );
	}

	/**
	 * A card with no game URL has no u-url anywhere, so the citation never
	 * resolves to the page's own URL.
	 */
	public function test_a_card_with_no_game_url_prints_no_url(): void {
		$html = $this->render(
			[
				'title'  => 'Copper Kite',
				'rawgId' => '900009',
			]
		);

		$this->assertStringNotContainsString( 'u-url', $html );
		$this->assertStringNotContainsString( 'value=""', $html );
		$this->assertStringNotContainsString( 'pk-link', $html );
	}

	/**
	 * A card that names no provider, or a video provider, is not a board
	 * tabletop, even with a BGG ID beside the video ID.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function non_board_ids(): array {
		return [
			'no provider'          => [ [] ],
			'rawg'                 => [ [ 'rawgId' => '900001' ] ],
			'steam'                => [ [ 'steamId' => '900002' ] ],
			'rawg beside bgg'      => [ [ 'rawgId' => '900003', 'bggId' => '900103' ] ],
			'whitespace bgg'       => [ [ 'bggId' => '   ' ] ],
		];
	}

	/**
	 * @dataProvider non_board_ids
	 *
	 * @param array<string, string> $ids Provider ID attributes.
	 */
	public function test_non_board_cards_hold_no_tabletop_structure( array $ids ): void {
		$html = $this->render(
			array_merge(
				[
					'title'       => 'Clockwork Harbor',
					'status'      => 'abandoned',
					'rating'      => 5,
					'review'      => 'Too many gears.',
					'gameUrl'     => 'https://rawg.example/games/clockwork-harbor',
					'officialUrl' => 'https://clockwork.example/',
					'cover'       => 'https://media.rawg.example/clockwork.jpg',
				],
				$ids
			)
		);

		foreach ( [ 'pk-card--tabletop', 'data-pkiw-play-group', 'pk-box', 'pk-facts', 'pk-links', 'pk-scorepad' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html );
		}
		$this->assertStringContainsString( 'class="pk-sub"', $html );
		$this->assertStringContainsString( 'class="pk-meta"', $html );
		$this->assertStringContainsString( 'role="img"', $html );
	}

	/**
	 * The shared card's markup, locked after W0 and this lane's all-card
	 * changes (issue 232 check gap 7). A byte comparison against the card before
	 * those changes can't hold, so this file is the baseline from here on,
	 * and a later change to the shared card shows up as a diff.
	 */
	public function test_a_video_card_matches_its_golden_file(): void {
		update_option( 'date_format', 'F j, Y' );
		$html = $this->render(
			[
				'title'       => 'Starbound Courier',
				'platform'    => 'PC',
				'status'      => 'completed',
				'hoursPlayed' => 42,
				'rating'      => 4,
				'review'      => 'Every route delivered.',
				'gameUrl'     => 'https://rawg.example/games/starbound-courier',
				'officialUrl' => 'https://starbound.example/',
				'purchaseUrl' => 'https://shop.example/starbound',
				'playedAt'    => '2026-09-22',
				'rawgId'      => '900001',
				'cover'       => 'https://media.rawg.example/starbound.jpg',
				'coverAlt'    => 'Starbound Courier cover',
			]
		);

		$normalize = static fn( string $markup ): string => trim( (string) preg_replace( [ '/>\s+</', '/\s+/' ], [ '><', ' ' ], $markup ) );

		$this->assertSame(
			$normalize( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/play-card-video.golden.html' ) ),
			$normalize( $html )
		);
	}
}

<?php
/**
 * Favorite card rendering (issue 259).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * The favorite card names a url-only favorite by its host, claims
 * u-favorite-of only when a web URL is stored, and ships the same
 * render.php from src and build. Names, people and hosts are invented.
 *
 * @group integration
 */
final class FavoriteCardRenderTest extends WP_UnitTestCase {

	/**
	 * Site options restored after each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_options = [];

	public function set_up(): void {
		parent::set_up();
		foreach ( [ 'timezone_string', 'date_format' ] as $option ) {
			$this->original_options[ $option ] = get_option( $option );
		}
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		( new Taxonomy() )->create_default_terms();
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', '__return_empty_array' );
		foreach ( $this->original_options as $option => $value ) {
			update_option( $option, $value );
		}
		parent::tear_down();
	}

	/**
	 * Render the favorite card block alone.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_card( array $attributes ): string {
		return do_blocks( '<!-- wp:post-kinds-indieweb/favorite-card ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->' );
	}

	/**
	 * XPath over an HTML fragment.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Nodes matching an XPath expression.
	 *
	 * @param string $html       HTML.
	 * @param string $expression XPath.
	 */
	private function query( string $html, string $expression ): DOMNodeList {
		$nodes = $this->xpath( $html )->query( $expression );
		$this->assertInstanceOf( DOMNodeList::class, $nodes );

		return $nodes;
	}

	private function has_class( string $html, string $class_token, string $scope = '//article' ): bool {
		return $this->query( $html, $scope . '[contains(concat(" ", normalize-space(@class), " "), " ' . $class_token . ' ")]' )->length > 0;
	}

	private function one_title( string $html ): DOMElement {
		$nodes = $this->query( $html, '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]' );
		$this->assertSame( 1, $nodes->length, 'One title heading.' );

		return $nodes->item( 0 );
	}

	private function one_link_in_title( string $html ): DOMElement {
		$nodes = $this->query( $html, '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]//a' );
		$this->assertSame( 1, $nodes->length, 'One link in the title.' );

		return $nodes->item( 0 );
	}

	private function node_text( DOMNode $node ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) );
	}

	/**
	 * Assert the url-only heading: the host as text, one new-tab link, no p-name.
	 *
	 * @param string $html Card HTML.
	 * @param string $url  Stored URL.
	 * @param string $host Expected link text.
	 */
	private function assert_host_title( string $html, string $url, string $host ): void {
		$title = $this->one_title( $html );
		$link  = $this->one_link_in_title( $html );

		$this->assertSame( 'pk-title', $title->getAttribute( 'class' ), 'The synthetic title carries no p-name.' );
		$this->assertSame( $host, $this->node_text( $link ) );
		$this->assertSame( esc_url( $url ), $link->getAttribute( 'href' ) );
		$this->assertSame( 'u-url', $link->getAttribute( 'class' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( 'noopener noreferrer', $link->getAttribute( 'rel' ) );
		$this->assertSame( $host . ' (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
		$this->assertSame( 0, $this->query( $html, '//*[contains(concat(" ", normalize-space(@class), " "), " p-name ")]' )->length, 'No p-name anywhere in a url-only card.' );
	}

	public function test_a_url_only_card_prints_its_host_as_a_new_tab_title_link_with_no_p_name(): void {
		$url  = 'https://zine.example.org/issue-7?token=abc123';
		$html = $this->render_card( [ 'url' => $url ] );

		$this->assert_host_title( $html, $url, 'zine.example.org' );
		$this->assertStringNotContainsString( 'abc123', wp_strip_all_tags( $html ), 'The query string never prints as text.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function synthetic_titles(): array {
		return [
			'the title equals the url'      => [ 'https://zine.example.org/issue-7' ],
			'the title is another url'      => [ 'https://zine.example.org/another' ],
			'the title is only whitespace'  => [ " \t\n " ],
		];
	}

	/**
	 * @dataProvider synthetic_titles
	 *
	 * @param string $title Stored card title.
	 */
	public function test_a_synthetic_title_prints_the_same_host_heading( string $title ): void {
		$url  = 'https://zine.example.org/issue-7';
		$html = $this->render_card( [ 'title' => $title, 'url' => $url ] );

		$this->assert_host_title( $html, $url, 'zine.example.org' );
		$this->assertStringNotContainsString( 'another', wp_strip_all_tags( $html ) );
	}

	public function test_the_host_link_keeps_the_stored_rel_tokens(): void {
		$html = $this->render_card( [ 'url' => 'https://zine.example.org/issue-7', 'rel' => 'nofollow ugc' ] );

		$this->assertSame( 'noopener noreferrer nofollow ugc', $this->one_link_in_title( $html )->getAttribute( 'rel' ) );
	}

	public function test_a_card_with_a_title_and_url_keeps_the_linked_title_markup(): void {
		$html  = $this->render_card( [ 'title' => 'Small Press Almanac', 'url' => 'https://zine.example.org/almanac-3', 'author' => 'Imogen Thatch' ] );
		$title = $this->one_title( $html );
		$link  = $this->one_link_in_title( $html );

		$this->assertSame( 'pk-title p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( 'Small Press Almanac', $this->node_text( $link ) );
		$this->assertSame( 'https://zine.example.org/almanac-3', $link->getAttribute( 'href' ) );
		$this->assertSame( 'u-url', $link->getAttribute( 'class' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( 'noopener noreferrer', $link->getAttribute( 'rel' ) );
		$this->assertSame( 'Small Press Almanac (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
		$this->assertSame( 1, $this->query( $html, '//p[contains(concat(" ", normalize-space(@class), " "), " pk-sub ")]/span[contains(@class, "p-author")]' )->length );
	}

	public function test_a_card_with_a_title_and_no_url_prints_the_plain_title(): void {
		$html  = $this->render_card( [ 'title' => 'Kitchen Radio Hour' ] );
		$title = $this->one_title( $html );

		$this->assertSame( 'pk-title p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( 'Kitchen Radio Hour', $this->node_text( $title ) );
		$this->assertSame( 0, $this->query( $html, '//a' )->length );
	}

	public function test_a_card_with_no_title_and_no_url_prints_no_heading(): void {
		$html = $this->render_card( [ 'author' => 'Pell Okafor' ] );

		$this->assertSame( 0, $this->query( $html, '//h2' )->length );
		$this->assertSame( 0, $this->query( $html, '//a' )->length );
	}

	public function test_the_wrapper_claims_favorite_of_only_when_a_web_url_is_stored(): void {
		$with_url = $this->render_card( [ 'title' => 'With a URL', 'url' => 'https://notes.example.com/a' ] );
		$no_url   = $this->render_card( [ 'title' => 'No URL' ] );
		$url_only = $this->render_card( [ 'url' => 'https://notes.example.com/b' ] );

		foreach ( [ $with_url, $no_url, $url_only ] as $html ) {
			$this->assertTrue( $this->has_class( $html, 'pk-card' ) );
			$this->assertTrue( $this->has_class( $html, 'k-favorite' ) );
			$this->assertTrue( $this->has_class( $html, 'h-cite' ), 'The h-cite stays so the card\'s own p-name and p-author stay scoped to it.' );
		}
		$this->assertTrue( $this->has_class( $with_url, 'u-favorite-of' ) );
		$this->assertFalse( $this->has_class( $no_url, 'u-favorite-of' ), 'A card with no URL claims no favorite-of.' );
		$this->assertTrue( $this->has_class( $url_only, 'u-favorite-of' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function unusable_urls(): array {
		return [
			'javascript' => [ 'javascript:alert(1)' ],
			'mailto'     => [ 'mailto:someone@example.com?subject=hi' ],
			'not a url'  => [ 'not a url' ],
		];
	}

	/**
	 * @dataProvider unusable_urls
	 *
	 * @param string $url Stored URL.
	 */
	public function test_an_unusable_url_with_no_title_prints_no_heading_link_or_favorite_of( string $url ): void {
		$html = $this->render_card( [ 'url' => $url ] );

		$this->assertSame( 0, $this->query( $html, '//h2' )->length );
		$this->assertSame( 0, $this->query( $html, '//a' )->length );
		$this->assertFalse( $this->has_class( $html, 'u-favorite-of' ) );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	/**
	 * @dataProvider unusable_urls
	 *
	 * @param string $url Stored URL.
	 */
	public function test_an_unusable_url_with_a_title_prints_the_plain_title_and_no_empty_link( string $url ): void {
		$html = $this->render_card( [ 'title' => 'A Real Title', 'url' => $url ] );

		$this->assertSame( 'A Real Title', $this->node_text( $this->one_title( $html ) ) );
		$this->assertSame( 0, $this->query( $html, '//a' )->length );
		$this->assertStringNotContainsString( 'href=""', $html );
		$this->assertFalse( $this->has_class( $html, 'u-favorite-of' ) );
	}

	public function test_the_favorited_row_and_image_alt_are_unchanged(): void {
		$html = $this->render_card(
			[
				'title'       => 'Dated',
				'url'         => 'https://notes.example.com/dated',
				'favoritedAt' => '2026-08-14T23:30:00',
				'image'       => 'https://images.example.com/dated.jpg',
				'imageAlt'    => 'A hand-drawn chart of three feed readers',
			]
		);

		$time = $this->query( $html, '//time[contains(@class, "dt-published")]' );
		$this->assertSame( 1, $time->length );
		$this->assertSame( '2026-08-14', $time->item( 0 )->getAttribute( 'datetime' ) );
		$this->assertSame( 'August 14, 2026', $this->node_text( $time->item( 0 ) ) );
		$this->assertSame( 'A hand-drawn chart of three feed readers', $this->query( $html, '//img' )->item( 0 )->getAttribute( 'alt' ) );
	}

	public function test_the_build_copy_is_the_source_byte_for_byte(): void {
		$source = PKIW_PATH . 'src/blocks/favorite-card/render.php';
		$build  = PKIW_PATH . 'build/blocks/favorite-card/render.php';

		$this->assertFileExists( $source );
		$this->assertFileExists( $build );
		$this->assertSame( file_get_contents( $source ), file_get_contents( $build ), 'cmp src and build exits 0.' );
	}

	/**
	 * A favorite post with a card as its only block.
	 *
	 * @param array<string, mixed> $card Card attributes.
	 * @param array<string, mixed> $args Extra post arguments.
	 * @return int Post ID.
	 */
	private function card_post( array $card, array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_date'    => '2026-10-11 09:00:00',
					'post_content' => '<!-- wp:post-kinds-indieweb/favorite-card ' . wp_json_encode( $card, JSON_UNESCAPED_SLASHES ) . ' /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'favorite', Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * The Stream card for a post, as a Query Loop item prints it.
	 *
	 * The stream-card block runs through its render filters (the entry's
	 * hidden properties), and the item is wrapped in the li that
	 * Microformats::add_post_classes() makes the h-entry when the card is not
	 * rooted as one itself.
	 *
	 * @param int $post_id Post ID.
	 */
	private function stream_card( int $post_id ): string {
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->' );
		if ( 1 === preg_match( '/<article\b[^>]*\bclass="[^"]*\bpk-card\b[^"]*\bh-entry\b/i', $html ) ) {
			return $html;
		}

		return '<ul><li class="wp-block-post h-entry">' . $html . '</li></ul>';
	}

	/**
	 * Anchors a visitor can reach: no [hidden] on the element or any ancestor (W3-G2).
	 *
	 * @param string $html HTML.
	 */
	private function rendered_anchors( string $html ): DOMNodeList {
		return $this->query( $html, '//a[@href][not(ancestor-or-self::*[@hidden])]' );
	}

	public function test_a_url_only_card_only_post_gets_a_permalink_link_hidden_cited_url_and_a_date_on_the_stream(): void {
		$cited   = 'https://zine.example.org/issue-9';
		$post_id = $this->card_post( [ 'url' => $cited ] );
		$html    = $this->stream_card( $post_id );

		$anchors = $this->rendered_anchors( $html );
		$this->assertSame( 1, $anchors->length, 'One tab stop per card.' );
		$this->assertSame( esc_url( (string) get_permalink( $post_id ) ), $anchors->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'zine.example.org', $this->node_text( $anchors->item( 0 ) ) );
		$this->assertSame( 0, $this->query( $html, '//a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]' )->length, 'W3-G2: every tabindex -1 anchor sits inside a hidden subtree.' );

		$data = $this->query( $html, '//data[@hidden][contains(concat(" ", normalize-space(@class), " "), " u-url ")][@value="' . esc_url( $cited ) . '"]' );
		$this->assertSame( 1, $data->length, 'The cited URL stays as hidden u-url data.' );

		$date = $this->query( $html, '//p[contains(concat(" ", normalize-space(@class), " "), " pk-stream-date ")]/time' );
		$this->assertSame( 1, $date->length, 'The date paragraph follows the heading.' );
		$this->assertSame( 0, $this->query( $html, '//*[contains(concat(" ", normalize-space(@class), " "), " p-name ")][ancestor::h2 or ancestor::h3]' )->length, 'No p-name on the synthetic heading.' );

		$entry = $this->parsed_entry( $html );
		$cite  = $entry['properties']['favorite-of'][0] ?? null;
		$this->assertIsArray( $cite );
		$this->assertSame( [ 'h-cite' ], $cite['type'] );
		$this->assertSame( [ $cited ], $cite['properties']['url'] );
	}

	public function test_a_card_with_a_url_and_no_title_has_a_u_favorite_of_cite_not_the_permalink(): void {
		$cited   = 'https://zine.example.org/issue-9';
		$post_id = $this->card_post( [ 'url' => $cited ] );
		$entry   = $this->parsed_entry( $this->stream_card( $post_id ) );
		$urls    = array_map( static fn( $item ) => is_array( $item ) ? ( $item['properties']['url'][0] ?? '' ) : (string) $item, $entry['properties']['favorite-of'] ?? [] );

		$this->assertSame( [ $cited ], $urls );
		$this->assertNotContains( (string) get_permalink( $post_id ), $urls );
	}

	/**
	 * The h-entry the Stream card parses to.
	 *
	 * @param string $html Card HTML.
	 * @return array<string, mixed>
	 */
	private function parsed_entry( string $html ): array {
		$items = \Mf2\parse( $html, 'https://example.org/' )['items'];
		$this->assertCount( 1, $items );
		$this->assertContains( 'h-entry', $items[0]['type'] );

		return $items[0];
	}

	/**
	 * G4: 'favorite' joins pkiw_stream_kinds at the release (the site repo's mu-plugin).
	 * Here the test adds it through the filter itself and proves a card-less
	 * favorite (the Quick Post shape) makes a correct Stream card.
	 */
	public function test_a_card_less_favorite_on_the_stream_renders_a_correct_card_when_favorite_is_a_stream_kind(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'favorite' ] );

		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Seed Recipe Index',
				'post_date'    => '2026-10-11 09:00:00',
				'post_content' => '<!-- wp:paragraph --><p>Saved for later.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $post_id, 'favorite', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, '_pkiw_favorite_name', 'Seed Recipe Index' );
		update_post_meta( $post_id, '_pkiw_favorite_url', 'https://recipes.example.com/index' );

		$this->assertSame( 'stream', \PKIW\Post_Surface::get( $post_id ), 'With favorite in pkiw_stream_kinds the card-less favorite is a Stream post.' );

		$html = $this->stream_card( $post_id );

		$this->assertSame( 1, $this->query( $html, '//article[contains(concat(" ", normalize-space(@class), " "), " pk-card ")][contains(concat(" ", normalize-space(@class), " "), " k-favorite ")]' )->length );
		$title = $this->query( $html, '//*[self::h2 or self::h3][contains(concat(" ", normalize-space(@class), " "), " pk-title ")]/a[@href]' );
		$this->assertSame( 1, $title->length, 'The title is the one link to the post; the theme adapter cuts the Read more link.' );
		$this->assertSame( esc_url( (string) get_permalink( $post_id ) ), $title->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'Seed Recipe Index', $this->node_text( $title->item( 0 ) ) );
		foreach ( $this->rendered_anchors( $html ) as $anchor ) {
			$this->assertSame( esc_url( (string) get_permalink( $post_id ) ), $anchor->getAttribute( 'href' ), 'Every reachable link goes to the post.' );
		}
		$this->assertSame( 0, $this->query( $html, '//a[contains(@href, "recipes.example.com")]' )->length, 'The cited URL is hidden data, not a link.' );
		$this->assertSame( 0, $this->query( $html, '//img' )->length, 'No image on a Stream favorite.' );
		$this->assertSame( 1, $this->query( $html, '//span[contains(concat(" ", normalize-space(@class), " "), " pk-kindlabel ")][normalize-space()="Favorite"]' )->length );

		$cite = $this->parsed_entry( $html )['properties']['favorite-of'][0] ?? null;
		$this->assertIsArray( $cite );
		$this->assertSame( [ 'https://recipes.example.com/index' ], $cite['properties']['url'] );
		$this->assertSame( [ 'Seed Recipe Index' ], $cite['properties']['name'] );
	}

	public function test_a_card_less_favorite_is_a_main_post_without_the_stream_kind(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Seed Recipe Index' ] );
		wp_set_object_terms( $post_id, 'favorite', Taxonomy::TAXONOMY );

		$this->assertSame( 'main', \PKIW\Post_Surface::get( $post_id ), 'Today favorite is not a stream kind.' );
	}
}

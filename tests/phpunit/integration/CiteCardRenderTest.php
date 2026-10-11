<?php
/**
 * Citation card rendering coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class CiteCardRenderTest extends WP_UnitTestCase {

	/**
	 * @var string|null Original site timezone.
	 */
	private ?string $original_timezone = null;

	public function set_up(): void {
		parent::set_up();

		$this->original_timezone = (string) get_option( 'timezone_string' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pkiw_like_card_title_is_heading' );

		if ( null !== $this->original_timezone ) {
			update_option( 'timezone_string', $this->original_timezone );
		}

		parent::tear_down();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function cite_card_kinds(): array {
		return [
			'reply'    => [ 'reply' ],
			'like'     => [ 'like' ],
			'bookmark' => [ 'bookmark' ],
		];
	}

	/**
	 * @dataProvider cite_card_kinds
	 */
	public function test_url_only_cards_print_a_host_link_without_p_name( string $kind ): void {
		$url  = 'https://notes.example.com/what%E2%80%99s-new?token=abc123';
		$html = $this->render_card( $kind, [ 'url' => $url ] );

		$this->assertSyntheticTitleLink( $html, $url, 'notes.example.com' );
		$this->assertStringNotContainsString( 'token', $this->plain_text( $html ) );
		$this->assertStringNotContainsString( 'abc123', $this->plain_text( $html ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function synthetic_title_cards(): array {
		$url = 'https://notes.example.com/what%E2%80%99s-new?token=abc123';

		return [
			'reply title equals url'       => [ 'reply', $url, $url ],
			'like title equals url'        => [ 'like', $url, $url ],
			'bookmark title equals url'    => [ 'bookmark', $url, $url ],
			'reply title is another url'   => [ 'reply', 'https://notes.example.com/x', $url ],
			'like title is another url'    => [ 'like', 'https://notes.example.com/x', $url ],
			'bookmark title is another url' => [ 'bookmark', 'https://notes.example.com/x', $url ],
		];
	}

	/**
	 * @dataProvider synthetic_title_cards
	 */
	public function test_url_like_titles_print_a_host_link_without_p_name( string $kind, string $title, string $url ): void {
		$html = $this->render_card(
			$kind,
			[
				'title' => $title,
				'url'   => $url,
			]
		);

		$this->assertSyntheticTitleLink( $html, $url, 'notes.example.com' );
		$this->assertStringNotContainsString( $title, $this->plain_text( $html ) );
	}

	/**
	 * @dataProvider cite_card_kinds
	 */
	public function test_synthetic_title_links_preserve_extra_rel_tokens( string $kind ): void {
		$url  = 'https://notes.example.com/what%E2%80%99s-new?token=abc123';
		$html = $this->render_card(
			$kind,
			[
				'url' => $url,
				'rel' => 'nofollow ugc',
			]
		);
		$link = $this->one_link_in_title( $html );

		$this->assertSame( 'noopener noreferrer nofollow ugc', $link->getAttribute( 'rel' ) );
	}

	/**
	 * @dataProvider cite_card_kinds
	 */
	public function test_real_titled_cards_keep_existing_linked_title_markup( string $kind ): void {
		$html = $this->render_card(
			$kind,
			[
				'title' => 'A Real Title',
				'url'   => 'https://notes.example.com/real-title',
			]
		);
		$title = $this->one_title( $html );
		$link  = $this->one_link_in_title( $html );

		$this->assertSame( 'pk-title p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( 'A Real Title', $this->node_text( $link ) );
		$this->assertSame( 'A Real Title (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
	}

	/**
	 * @dataProvider cite_card_kinds
	 */
	public function test_real_titled_cards_with_no_url_keep_plain_title_markup( string $kind ): void {
		$html  = $this->render_card( $kind, [ 'title' => 'A Real Title' ] );
		$title = $this->one_title( $html );

		$this->assertSame( 'pk-title p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( 'A Real Title', $this->node_text( $title ) );
		$this->assertSame( 0, $this->query( $html, '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]//a' )->length );
	}

	/**
	 * @dataProvider cite_card_kinds
	 */
	public function test_whitespace_only_title_counts_as_synthetic_title( string $kind ): void {
		$url  = 'https://notes.example.com/what%E2%80%99s-new?token=abc123';
		$html = $this->render_card(
			$kind,
			[
				'title' => " \t\n ",
				'url'   => $url,
			]
		);

		$this->assertSyntheticTitleLink( $html, $url, 'notes.example.com' );
	}

	/**
	 * @dataProvider cite_card_kinds
	 */
	public function test_synthetic_title_uses_url_text_when_url_has_no_host( string $kind ): void {
		$url  = 'mailto:someone@example.com';
		$html = $this->render_card( $kind, [ 'url' => $url ] );

		$this->assertSyntheticTitleLink( $html, $url, $url );
	}

	public function test_like_single_uses_hidden_name_and_original_link_when_card_title_matches_post_title(): void {
		$url   = 'https://notes.example.com/liked-page';
		$title = 'liked page title ';
		$html  = $this->render_card_on_single(
			'like',
			[
				'title' => $title,
				'url'   => $url,
			],
			[
				'post_title' => 'Liked Page Title',
			]
		);

		$this->assertSame( 0, $this->query( $html, '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]' )->length );
		$this->assertHiddenName( $html, $title );
		$link = $this->one_original_link( $html );
		$this->assertSame( $url, $link->getAttribute( 'href' ) );
		$this->assertSame( 'u-url', $link->getAttribute( 'class' ) );
		$this->assertSame( 'Read the original', $this->node_text( $link ) );
		$this->assertSame( 'Read the original (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( 'noopener noreferrer', $link->getAttribute( 'rel' ) );
		$this->assertStringNotContainsString( $title, $this->plain_text( $html ) );
	}

	public function test_like_single_keeps_linked_title_when_post_title_differs_from_card_title(): void {
		$html = $this->render_card_on_single(
			'like',
			[
				'title' => 'Liked Page Title',
				'url'   => 'https://notes.example.com/liked-page',
			],
			[
				'post_title' => 'Different post title',
			]
		);

		$this->assertRealLinkedTitle( $html, 'Liked Page Title' );
		$this->assertNoOriginalLinkOrHiddenName( $html );
	}

	public function test_empty_post_title_keeps_like_title_unless_filter_returns_true(): void {
		$attributes = [
			'title' => 'Liked Page Title',
			'url'   => 'https://notes.example.com/liked-page',
		];
		$post_args  = [ 'post_title' => '' ];

		$html = $this->render_card_on_single( 'like', $attributes, $post_args );
		$this->assertRealLinkedTitle( $html, 'Liked Page Title' );
		$this->assertNoOriginalLinkOrHiddenName( $html );

		add_filter( 'pkiw_like_card_title_is_heading', '__return_true' );
		$html = $this->render_card_on_single( 'like', $attributes, $post_args );
		$this->assertHiddenName( $html, 'Liked Page Title' );
		$this->one_original_link( $html );

		remove_all_filters( 'pkiw_like_card_title_is_heading' );
		add_filter( 'pkiw_like_card_title_is_heading', '__return_false' );
		$html = $this->render_card_on_single(
			'like',
			$attributes,
			[
				'post_title' => 'Liked Page Title',
			]
		);
		$this->assertRealLinkedTitle( $html, 'Liked Page Title' );
		$this->assertNoOriginalLinkOrHiddenName( $html );
	}

	public function test_like_title_filter_receives_default_and_post(): void {
		$seen = [];
		add_filter(
			'pkiw_like_card_title_is_heading',
			static function ( bool $default, WP_Post $post ) use ( &$seen ): bool {
				$seen[] = [
					'default' => $default,
					'post_id' => $post->ID,
				];

				return false;
			},
			10,
			2
		);

		$matching_id = $this->render_card_on_single(
			'like',
			[
				'title' => 'Liked Page Title',
				'url'   => 'https://notes.example.com/liked-page',
			],
			[
				'post_title' => 'Liked Page Title',
			],
			true
		);
		$different_id = $this->render_card_on_single(
			'like',
			[
				'title' => 'Liked Page Title',
				'url'   => 'https://notes.example.com/liked-page',
			],
			[
				'post_title' => 'Different post title',
			],
			true
		);

		$this->assertSame(
			[
				[
					'default' => true,
					'post_id' => $matching_id,
				],
				[
					'default' => false,
					'post_id' => $different_id,
				],
			],
			$seen
		);
	}

	public function test_like_title_filter_is_not_called_outside_real_titled_own_view_like_cards(): void {
		$calls = 0;
		add_filter(
			'pkiw_like_card_title_is_heading',
			static function () use ( &$calls ): bool {
				++$calls;
				return true;
			}
		);

		$this->render_card_on_single(
			'like',
			[
				'title' => 'https://notes.example.com/liked-page',
				'url'   => 'https://notes.example.com/liked-page',
			],
			[
				'post_title' => 'notes.example.com',
			]
		);
		$this->render_card_on_single( 'like', [ 'title' => 'Liked Page Title' ], [ 'post_title' => 'Liked Page Title' ] );
		$this->render_like_off_own_view(
			[
				'title' => 'Liked Page Title',
				'url'   => 'https://notes.example.com/liked-page',
			],
			'Liked Page Title'
		);
		$this->render_card_on_single(
			'reply',
			[
				'title' => 'Liked Page Title',
				'url'   => 'https://notes.example.com/reply',
			],
			[
				'post_title' => 'Liked Page Title',
			]
		);
		$this->render_card_on_single(
			'bookmark',
			[
				'title' => 'Liked Page Title',
				'url'   => 'https://notes.example.com/bookmark',
			],
			[
				'post_title' => 'Liked Page Title',
			]
		);

		$this->assertSame( 0, $calls );
	}

	public function test_like_title_rule_does_not_run_in_feed_or_other_single_view(): void {
		$attributes = [
			'title' => 'Liked Page Title',
			'url'   => 'https://notes.example.com/liked-page',
		];

		$feed_html = $this->render_like_on_feed( $attributes, 'Liked Page Title' );
		$this->assertRealLinkedTitle( $feed_html, 'Liked Page Title' );
		$this->assertNoOriginalLinkOrHiddenName( $feed_html );

		$other_single_html = $this->render_like_off_own_view( $attributes, 'Liked Page Title' );
		$this->assertRealLinkedTitle( $other_single_html, 'Liked Page Title' );
		$this->assertNoOriginalLinkOrHiddenName( $other_single_html );
	}

	public function test_synthetic_like_title_stays_visible_as_host_on_own_view_even_when_filter_returns_true(): void {
		add_filter( 'pkiw_like_card_title_is_heading', '__return_true' );

		$url  = 'https://notes.example.com/what%E2%80%99s-new?token=abc123';
		$html = $this->render_card_on_single(
			'like',
			[
				'title' => $url,
				'url'   => $url,
			],
			[
				'post_title' => $url,
			]
		);

		$this->assertSyntheticTitleLink( $html, $url, 'notes.example.com' );
		$this->assertNoOriginalLinkOrHiddenName( $html );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function reply_and_bookmark_kinds(): array {
		return [
			'reply'    => [ 'reply' ],
			'bookmark' => [ 'bookmark' ],
		];
	}

	/**
	 * @dataProvider reply_and_bookmark_kinds
	 */
	public function test_reply_and_bookmark_do_not_use_the_like_single_title_rule( string $kind ): void {
		$html = $this->render_card_on_single(
			$kind,
			[
				'title' => 'Cited Page Title',
				'url'   => 'https://notes.example.com/cited-page',
			],
			[
				'post_title' => 'Cited Page Title',
			]
		);

		$this->assertRealLinkedTitle( $html, 'Cited Page Title' );
		$this->assertNoOriginalLinkOrHiddenName( $html );
	}

	public function test_like_card_keeps_liked_at_as_calendar_date(): void {
		$html = $this->render_card(
			'like',
			[
				'title'   => 'Liked Page Title',
				'url'     => 'https://notes.example.com/liked-page',
				'likedAt' => '2026-07-04T23:30:00-07:00',
			]
		);
		$time = $this->one_time( $html );

		$this->assertSame( 'dt-published', $time->getAttribute( 'class' ) );
		$this->assertSame( '2026-07-04', $time->getAttribute( 'datetime' ) );
		$this->assertDoesNotMatchRegularExpression( '/\d{1,2}:\d{2}/', $this->node_text( $time ) );
	}

	public function test_invalid_liked_at_off_own_view_prints_no_time(): void {
		$html = $this->render_like_off_own_view(
			[
				'title'   => 'Liked Page Title',
				'url'     => 'https://notes.example.com/liked-page',
				'likedAt' => 'tomorrow',
			],
			'Liked Page Title'
		);

		$this->assertSame( 0, $this->query( $html, '//time' )->length );
	}

	public function test_like_single_falls_back_to_post_date_without_dt_published_when_liked_at_is_missing(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );

		$html = $this->render_card_on_single(
			'like',
			[
				'title' => 'Different liked title',
				'url'   => 'https://notes.example.com/liked-page',
			],
			[
				'post_title'    => 'Post title',
				'post_date'     => '2026-07-04 22:30:00',
				'post_date_gmt' => '2026-07-05 05:30:00',
			]
		);
		$time = $this->one_time( $html );

		$this->assertSame( '2026-07-04', $time->getAttribute( 'datetime' ) );
		$this->assertStringNotContainsString( 'dt-published', $time->getAttribute( 'class' ) );

		$entry  = $this->top_level_h_entry( \Mf2\parse( '<div class="h-entry">' . $html . '</div>' ) );
		$cite   = $entry['properties']['like-of'][0] ?? null;
		$this->assertIsArray( $cite );
		$this->assertArrayNotHasKey( 'published', $cite['properties'] ?? [] );
	}

	public function test_valid_liked_at_on_own_view_wins_over_post_date_and_keeps_dt_published(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );

		$html = $this->render_card_on_single(
			'like',
			[
				'title'   => 'Different liked title',
				'url'     => 'https://notes.example.com/liked-page',
				'likedAt' => '2026-07-05',
			],
			[
				'post_title'    => 'Post title',
				'post_date'     => '2026-07-04 22:30:00',
				'post_date_gmt' => '2026-07-05 05:30:00',
			]
		);
		$time = $this->one_time( $html );

		$this->assertSame( 'dt-published', $time->getAttribute( 'class' ) );
		$this->assertSame( '2026-07-05', $time->getAttribute( 'datetime' ) );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_card( string $kind, array $attributes ): string {
		return do_blocks( sprintf( '<!-- wp:post-kinds-indieweb/%s-card %s /-->', $kind, wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) ) );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<string, mixed> $post_args  Extra post factory arguments.
	 * @return string|int Rendered HTML, or the post ID when $return_post_id is true.
	 */
	private function render_card_on_single( string $kind, array $attributes, array $post_args = [], bool $return_post_id = false ) {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_content' => sprintf( '<!-- wp:post-kinds-indieweb/%s-card %s /-->', $kind, wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) ),
				],
				$post_args
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );

		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		return $return_post_id ? $post_id : $html;
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_like_on_feed( array $attributes, string $post_title ): string {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $post_title,
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->',
			]
		);

		$this->go_to( home_url( '/?feed=rss2' ) );
		$GLOBALS['post'] = get_post( $post_id );

		return do_blocks( (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_like_off_own_view( array $attributes, string $post_title ): string {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $post_title,
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->',
			]
		);
		$other_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Another single',
			]
		);

		$this->go_to( get_permalink( $other_id ) );
		$GLOBALS['post'] = get_post( $post_id );

		return do_blocks( (string) get_post_field( 'post_content', $post_id ) );
	}

	private function assertSyntheticTitleLink( string $html, string $url, string $text ): void {
		$title = $this->one_title( $html );
		$link  = $this->one_link_in_title( $html );

		$this->assertSame( 'pk-title', $title->getAttribute( 'class' ) );
		$this->assertSame( $text, $this->node_text( $link ) );
		$this->assertSame( esc_url( $url ), $link->getAttribute( 'href' ) );
		$this->assertSame( 'u-url', $link->getAttribute( 'class' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( 'noopener noreferrer', $link->getAttribute( 'rel' ) );
		$this->assertSame( $text . ' (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
		$this->assertStringNotContainsString( 'p-name', $title->getAttribute( 'class' ) );
	}

	private function assertRealLinkedTitle( string $html, string $title_text ): void {
		$title = $this->one_title( $html );
		$link  = $this->one_link_in_title( $html );

		$this->assertSame( 'pk-title p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( $title_text, $this->node_text( $link ) );
		$this->assertSame( $title_text . ' (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
	}

	private function assertHiddenName( string $html, string $value ): void {
		$nodes = $this->query( $html, '//data[contains(concat(" ", normalize-space(@class), " "), " p-name ")]' );
		$this->assertSame( 1, $nodes->length );
		$this->assertSame( $value, $nodes->item( 0 )->getAttribute( 'value' ) );
	}

	private function assertNoOriginalLinkOrHiddenName( string $html ): void {
		$this->assertStringNotContainsString( 'Read the original', $html );
		$this->assertSame( 0, $this->query( $html, '//data[contains(concat(" ", normalize-space(@class), " "), " p-name ")]' )->length );
	}

	private function one_title( string $html ): DOMElement {
		$nodes = $this->query( $html, '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]' );
		$this->assertSame( 1, $nodes->length );

		return $nodes->item( 0 );
	}

	private function one_link_in_title( string $html ): DOMElement {
		$nodes = $this->query( $html, '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]//a[contains(concat(" ", normalize-space(@class), " "), " u-url ")]' );
		$this->assertSame( 1, $nodes->length );

		return $nodes->item( 0 );
	}

	private function one_original_link( string $html ): DOMElement {
		$nodes = $this->query( $html, '//p[contains(concat(" ", normalize-space(@class), " "), " pk-original ")]/a[contains(concat(" ", normalize-space(@class), " "), " u-url ")]' );
		$this->assertSame( 1, $nodes->length );

		return $nodes->item( 0 );
	}

	private function one_time( string $html ): DOMElement {
		$nodes = $this->query( $html, '//time' );
		$this->assertSame( 1, $nodes->length );

		return $nodes->item( 0 );
	}

	private function query( string $html, string $expression ): DOMNodeList {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return ( new DOMXPath( $dom ) )->query( $expression );
	}

	private function node_text( DOMNode $node ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) );
	}

	private function plain_text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	/**
	 * @param array<string, mixed> $parsed Parsed microformats2 document.
	 * @return array<string, mixed>
	 */
	private function top_level_h_entry( array $parsed ): array {
		foreach ( $parsed['items'] ?? [] as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				return $item;
			}
		}

		$this->fail( 'No top-level h-entry item was parsed.' );
	}
}

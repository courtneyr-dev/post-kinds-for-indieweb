<?php
/**
 * Repost card rendering coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class RepostCardRenderTest extends WP_UnitTestCase {

	public function test_a_titled_card_links_the_title_with_rel_tokens(): void {
		$html = $this->render_card(
			[
				'title' => 'Title',
				'url'   => 'https://quiet.example.com/posts/14',
				'rel'   => 'nofollow',
			]
		);
		$title = $this->one_title( $html );
		$link  = $this->one_link_in_title( $html );

		$this->assertSame( 'pk-title p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( 'u-url', $link->getAttribute( 'class' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( 'noopener noreferrer nofollow', $link->getAttribute( 'rel' ) );
		$this->assertSame( 'Title (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
	}

	public function test_url_only_cards_print_a_host_link_without_p_name_or_query_text(): void {
		$url  = 'https://quiet.example.com/posts/14?token=abc123';
		$html = $this->render_card( [ 'url' => $url ] );

		$this->assertSyntheticTitleLink( $html, $url, 'quiet.example.com' );
		$this->assertStringNotContainsString( 'token', $this->plain_text( $html ) );
		$this->assertStringNotContainsString( 'abc123', $this->plain_text( $html ) );
		$this->assertSame( 0, $this->query( $html, '//*[contains(concat(" ", normalize-space(@class), " "), " p-name ")]' )->length );
	}

	public function test_url_like_and_whitespace_titles_are_synthetic(): void {
		$url = 'https://quiet.example.com/posts/14?token=abc123';

		foreach ( [ $url, " \t\n " ] as $title ) {
			$html = $this->render_card(
				[
					'title' => $title,
					'url'   => $url,
				]
			);

			$this->assertSyntheticTitleLink( $html, $url, 'quiet.example.com' );
			$this->assertStringNotContainsString( 'abc123', $this->plain_text( $html ) );
		}
	}

	public function test_a_synthetic_title_keeps_extra_rel_tokens(): void {
		$html = $this->render_card(
			[
				'url' => 'https://quiet.example.com/posts/14',
				'rel' => 'nofollow ugc',
			]
		);
		$link = $this->one_link_in_title( $html );

		$this->assertSame( 'noopener noreferrer nofollow ugc', $link->getAttribute( 'rel' ) );
	}

	public function test_hostless_urls_print_original_link_without_the_address(): void {
		$url        = 'mailto:someone@example.com?subject=x';
		$link_text  = __( 'Original link', 'post-kinds-for-indieweb-in-block-themes' );
		$html       = $this->render_card( [ 'url' => $url ] );
		$plain_text = $this->plain_text( $html );

		$this->assertSyntheticTitleLink( $html, $url, $link_text );
		$this->assertStringNotContainsString( 'someone@example.com', $plain_text );
	}

	public function test_a_title_with_no_url_prints_plain_text_heading(): void {
		$html  = $this->render_card( [ 'title' => 'A real title' ] );
		$title = $this->one_title( $html );

		$this->assertSame( 'pk-title p-name', $title->getAttribute( 'class' ) );
		$this->assertSame( 'A real title', $this->node_text( $title ) );
		$this->assertSame( 0, $this->query( $html, '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]//a' )->length );
	}

	public function test_php_mf2_reads_repost_of_for_url_only_and_titled_cards(): void {
		$url_only = $this->render_card( [ 'url' => 'https://quiet.example.com/posts/14' ] );
		$titled   = $this->render_card(
			[
				'title' => 'Quiet links',
				'url'   => 'https://quiet.example.com/posts/15',
			]
		);

		$this->assertSame( 'https://quiet.example.com/posts/14', $this->repost_of_url( $url_only ) );
		$this->assertSame( 'https://quiet.example.com/posts/15', $this->repost_of_url( $titled ) );
		// php-mf2 implies a name from text content; this assertion guards the rendered class.
		$this->assertSame( 0, $this->query( $url_only, '//*[contains(concat(" ", normalize-space(@class), " "), " p-name ")]' )->length );
	}

	public function test_reposted_at_meta_prints_only_when_present(): void {
		$this->assertSame( 0, $this->query( $this->render_card( [ 'title' => 'No date' ] ), '//*[contains(concat(" ", normalize-space(@class), " "), " pk-meta ")]' )->length );

		$html = $this->render_card(
			[
				'title'      => 'With date',
				'repostedAt' => '2026-07-01T09:30',
			]
		);

		$this->assertSame( 1, $this->query( $html, '//*[contains(concat(" ", normalize-space(@class), " "), " pk-meta ")]//time[contains(concat(" ", normalize-space(@class), " "), " dt-published ")]' )->length );
	}

	public function test_image_alt_is_the_stored_value_or_empty(): void {
		$with_alt = $this->render_card(
			[
				'title'    => 'With image',
				'image'    => 'https://quiet.example.com/card.jpg',
				'imageAlt' => 'A quiet card',
			]
		);
		$without_alt = $this->render_card(
			[
				'title' => 'With image',
				'image' => 'https://quiet.example.com/card.jpg',
			]
		);

		$this->assertSame( 'A quiet card', $this->one_image( $with_alt )->getAttribute( 'alt' ) );
		$this->assertSame( '', $this->one_image( $without_alt )->getAttribute( 'alt' ) );
	}

	public function test_the_built_render_copy_matches_src(): void {
		$this->assertFileEquals( PKIW_PATH . 'src/blocks/repost-card/render.php', PKIW_PATH . 'build/blocks/repost-card/render.php' );
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_card( array $attributes ): string {
		return do_blocks( '<!-- wp:post-kinds-indieweb/repost-card ' . wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES ) . ' /-->' );
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

	private function repost_of_url( string $card_html ): string {
		$entry = $this->top_level_h_entry( \Mf2\parse( '<div class="h-entry">' . $card_html . '</div>', 'https://courtneyr.example/' ) );
		$cite  = $entry['properties']['repost-of'][0] ?? null;

		$this->assertIsArray( $cite );

		return (string) ( $cite['properties']['url'][0] ?? '' );
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

	private function one_image( string $html ): DOMElement {
		$nodes = $this->query( $html, '//img[contains(concat(" ", normalize-space(@class), " "), " u-photo ")]' );
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

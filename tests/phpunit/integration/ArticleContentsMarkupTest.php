<?php
/**
 * Article contents archive markup (issue 263).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class ArticleContentsMarkupTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	/**
	 * A real author, so the hidden author h-card has a name and a URL to print.
	 */
	private int $author_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 10 );
		$this->author_id = self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'Ana Example' ] );
		// Pretty permalinks, as the site runs: /kind/article/page/2/.
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( \PKIW\Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		$this->register_article_archive();
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_dequeue_style( 'pkiw-article-contents' );
		wp_dequeue_style( 'pkiw-article-stream-card' );
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	public function test_rendered_items_parse_as_h_entries_with_no_content_property(): void {
		$ids  = $this->seed_articles();
		$html = $this->article_archive_html();
		$mf2  = \Mf2\parse( $html, home_url( '/' ) );
		$items = array_values( array_filter( $mf2['items'], static fn( array $item ): bool => in_array( 'h-entry', $item['type'], true ) ) );

		$this->assertCount( count( $ids ), $items );
		foreach ( $items as $item ) {
			$this->assertArrayHasKey( 'url', $item['properties'] );
			$this->assertArrayHasKey( 'name', $item['properties'] );
			$this->assertArrayHasKey( 'published', $item['properties'] );
			$this->assertArrayHasKey( 'author', $item['properties'] );
			$this->assertArrayNotHasKey( 'content', $item['properties'] );
		}
	}

	public function test_an_untitled_article_uses_the_fallback_link_without_p_name(): void {
		$post_id = $this->article( '', 200, '2026-08-19 10:00:00' );
		$html    = $this->contents_item_html( $post_id );

		$this->assertStringContainsString( 'Article, August 19, 2026', $html );
		$this->assertNoEntryNameOutsideTheHiddenAuthor( $html );
	}

	public function test_an_untitled_protected_article_uses_the_fallback_without_protected_text(): void {
		$post_id = $this->article( '', 200, '2026-08-19 10:00:00', [ 'post_password' => 'secret' ] );
		$html    = $this->contents_item_html( $post_id );

		$this->assertStringContainsString( 'Article, August 19, 2026', $html );
		$this->assertNoEntryNameOutsideTheHiddenAuthor( $html, 0 );
		$this->assertStringNotContainsString( 'p-author', $html, 'A protected article names no author.' );
		$this->assertStringNotContainsString( 'Protected', $html );
	}

	public function test_a_titled_protected_article_keeps_protected_title_but_no_media_or_reading_time(): void {
		$post_id = $this->article( 'Locked Field Report', 1000, '2026-08-18 10:00:00', [ 'post_password' => 'secret' ] );
		$this->attach_image( $post_id );
		$html = $this->contents_item_html( $post_id );

		$this->assertStringContainsString( 'p-name', $html );
		$this->assertStringContainsString( 'Protected: Locked Field Report', wp_strip_all_tags( $html ) );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'min read', $html );
	}

	public function test_an_unprotected_article_prints_reading_time_in_the_contents_item(): void {
		$post_id = $this->article( 'Open Field Report', 1000, '2026-08-18 10:00:00' );
		$html    = $this->contents_item_html( $post_id );

		$this->assertStringContainsString( 'wp-block-post-kinds-indieweb-reading-time', $html );
		$this->assertStringContainsString( 'min read', $html );
	}

	public function test_every_contents_image_has_empty_alt_text(): void {
		$first = $this->article( 'Illustrated One', 200, '2026-08-18 10:00:00' );
		$this->attach_image( $first, 'Stored alt' );
		$second = $this->article( 'Illustrated Two', 200, '2026-08-17 10:00:00' );
		$this->attach_image( $second, 'Another alt' );

		foreach ( $this->xpath( $this->article_archive_html() )->query( '//img' ) as $img ) {
			$this->assertSame( '', $img->getAttribute( 'alt' ) );
		}
	}

	public function test_the_first_page_lead_image_is_eager_and_high_priority(): void {
		$lead = $this->article( 'Lead Story', 200, '2026-08-31 10:00:00' );
		$this->attach_image( $lead );
		$second = $this->article( 'Second Story', 200, '2026-08-30 10:00:00' );
		$this->attach_image( $second );
		$images = $this->xpath( $this->article_archive_html() )->query( '//img' );

		$this->assertSame( 'high', $images->item( 0 )->getAttribute( 'fetchpriority' ) );
		$this->assertNotSame( 'lazy', $images->item( 0 )->getAttribute( 'loading' ) );
		$this->assertSame( 'lazy', $images->item( 1 )->getAttribute( 'loading' ) );
		$this->assertFalse( $images->item( 1 )->hasAttribute( 'fetchpriority' ) );
	}

	public function test_page_two_has_no_high_priority_image_and_no_first_page_class(): void {
		foreach ( range( 1, 12 ) as $n ) {
			$post_id = $this->article( 'Page Two ' . $n, 200, sprintf( '2026-08-%02d 10:00:00', 31 - $n ) );
			$this->attach_image( $post_id );
		}
		$html = $this->article_archive_html( 2 );

		$this->assertStringNotContainsString( 'fetchpriority="high"', $html );
		$this->assertStringNotContainsString( 'pkiw-contents--first-page', $html );
	}

	public function test_visible_text_of_an_item_is_the_title_and_reading_time_only(): void {
		$this->article( 'Plain Article', 1000, '2026-08-12 10:00:00', [ 'post_excerpt' => 'Do not print this deck.' ] );
		$html  = $this->article_archive_html();
		$xpath = $this->xpath( $html );

		$items = $xpath->query( '//li[contains(concat(" ", normalize-space(@class), " "), " wp-block-post ")]' );
		$this->assertSame( 1, $items->length );
		$item = $items->item( 0 );
		// The date is visually hidden by the stylesheet and the author h-card is
		// [hidden]; neither is visible text.
		foreach ( iterator_to_array( $xpath->query( './/*[@hidden] | .//*[contains(concat(" ", normalize-space(@class), " "), " pkiw-contents__date ")]', $item ) ) as $node ) {
			$node->parentNode->removeChild( $node );
		}

		$this->assertSame( 'Plain Article 5 min read', trim( preg_replace( '/\s+/', ' ', $item->textContent ) ) );
		$this->assertStringNotContainsString( 'Do not print this deck.', $html );
	}

	public function test_the_date_is_inside_the_contents_date_block(): void {
		$this->article( 'Date Test', 200, '2026-08-12 10:00:00' );

		$this->assertSame( 1, $this->xpath( $this->article_archive_html() )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pkiw-contents__date ")]//time[contains(concat(" ", normalize-space(@class), " "), " dt-published ")]' )->length );
	}

	public function test_geo_values_are_absent_from_contents_items(): void {
		$post_id = $this->article( 'Orchard Article', 200, '2026-08-12 10:00:00' );
		update_post_meta( $post_id, 'geo_latitude', '40.0' );
		update_post_meta( $post_id, 'geo_longitude', '-75.0' );
		update_post_meta( $post_id, 'geo_address', '1 Example Way, Exampleville' );
		update_post_meta( $post_id, 'geo_public', '1' );
		$html = $this->article_archive_html();

		$this->assertStringContainsString( 'Orchard Article', $html );
		foreach ( [ 'Example Way', 'Exampleville', 'p-location', 'h-geo', 'latitude', 'longitude' ] as $token ) {
			$this->assertStringNotContainsString( $token, $html );
		}
	}

	public function test_theme_shaped_post_template_is_byte_identical_and_does_not_enqueue_the_contents_style(): void {
		$post_id  = $this->article( 'Theme Shape', 200, '2026-08-12 10:00:00' );
		$template = '<!-- wp:query {"queryId":9,"query":{"inherit":false,"postType":"post","perPage":3}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- wp:post-date /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$this->go_to( get_permalink( $post_id ) );

		$with = do_blocks( $template );
		$this->assertFalse( wp_style_is( 'pkiw-article-contents', 'enqueued' ) );

		// The hooks stay registered for the process, so take them off here;
		// WP_UnitTestCase restores them after the test.
		remove_filter( 'render_block_core/post-title', [ \PKIW\Article_Archive::class, 'title' ], 10 );
		remove_filter( 'render_block_core/post-date', [ \PKIW\Article_Archive::class, 'date' ], 10 );
		remove_filter( 'render_block_core/post-featured-image', [ \PKIW\Article_Archive::class, 'picture' ], 10 );
		remove_filter( 'render_block_core/post-template', [ \PKIW\Article_Archive::class, 'contents_wrapper' ], 10 );
		$without = do_blocks( $template );

		$this->assertSame( $without, $with );
		$this->assertStringContainsString( 'Theme Shape', $with );
		$this->assertStringNotContainsString( 'p-name', $with );
	}

	public function test_contents_post_template_enqueues_the_contents_style(): void {
		$this->article( 'Style Shape', 200, '2026-08-12 10:00:00' );

		do_blocks( '<!-- wp:query {"queryId":7,"query":{"inherit":false,"postType":"post","perPage":1}} --><div class="wp-block-query"><!-- wp:post-template {"className":"is-style-pkiw-contents"} --><!-- wp:post-title {"className":"pkiw-contents__title","isLink":true} /--><!-- /wp:post-template --></div><!-- /wp:query -->' );

		$this->assertTrue( wp_style_is( 'pkiw-article-contents', 'enqueued' ) );
	}

	public function test_article_stream_card_filter_enqueues_the_stream_card_style_for_articles_only(): void {
		$article = $this->article( 'Stream Article', 200, '2026-08-12 10:00:00' );
		$this->assertSame( $this->stream_card_html( $article ), $this->filtered_stream_card_html( $article ) );
		$this->assertTrue( wp_style_is( 'pkiw-article-stream-card', 'enqueued' ) );

		wp_dequeue_style( 'pkiw-article-stream-card' );
		$note = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Stream Note',
				'post_content' => '<!-- wp:paragraph --><p>Note body.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $note, 'note', 'kind' );

		$this->assertSame( $this->stream_card_html( $note ), $this->filtered_stream_card_html( $note ) );
		$this->assertFalse( wp_style_is( 'pkiw-article-stream-card', 'enqueued' ) );
	}

	/**
	 * No element outside the hidden author h-card carries p-name.
	 *
	 * The hidden `p-author h-card` has its own p-name (the author's name), which
	 * is not the entry's name, so a bare substring check would be wrong. A
	 * password-protected article prints no author h-card, so it expects none.
	 *
	 * @param string $html  Item markup.
	 * @param int    $cards Hidden author h-cards expected.
	 */
	private function assertNoEntryNameOutsideTheHiddenAuthor( string $html, int $cards = 1 ): void {
		$xpath = $this->xpath( $html );
		$this->assertSame( 0, $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " p-name ")][not(ancestor::*[@hidden])]' )->length, 'An untitled item has no entry p-name.' );
		$this->assertSame( $cards, $xpath->query( '//*[@hidden]//*[contains(concat(" ", normalize-space(@class), " "), " h-card ")]' )->length, 'The hidden author h-card count.' );
	}

	private function register_article_archive(): void {
		$this->assertTrue( class_exists( 'PKIW\\Article_Archive' ), 'PKIW\\Article_Archive must exist for issue 263.' );
		$this->assertTrue( class_exists( 'PKIW\\Reading_Time' ), 'PKIW\\Reading_Time must exist for issue 263.' );
		// The plugin wires both classes at load. Re-running init here would
		// register every other init callback twice.
		$this->assertNotFalse( has_filter( 'render_block_core/post-title', [ \PKIW\Article_Archive::class, 'title' ] ), 'Article_Archive hooks are registered.' );
	}

	/**
	 * @return int[]
	 */
	private function seed_articles(): array {
		$ids = [];
		foreach ( range( 1, 3 ) as $n ) {
			$ids[] = $this->article( 'Seed Article ' . $n, 200, sprintf( '2026-08-%02d 10:00:00', 20 - $n ) );
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $args Extra post args.
	 */
	private function article( string $title, int $words, string $date, array $args = [] ): int {
		if ( ! term_exists( 'article', 'kind' ) ) {
			wp_insert_term( 'Article', 'kind', [ 'slug' => 'article' ] );
		}
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_author'  => $this->author_id,
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'word ', $words ) . '</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'article', 'kind' );

		return $post_id;
	}

	private function attach_image( int $post_id, string $alt = '' ): int {
		$attachment = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		update_post_meta( $attachment, '_wp_attachment_image_alt', $alt );
		set_post_thumbnail( $post_id, $attachment );

		return $attachment;
	}

	private function article_archive_html( int $page = 1 ): string {
		$url = 1 === $page ? get_term_link( 'article', 'kind' ) : home_url( '/kind/article/page/' . $page . '/' );
		$this->go_to( $url );

		return $this->render_current_template( 'article' );
	}

	private function contents_item_html( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );

		// Inside a Post Template, so the depth-guarded reading-time block can print.
		return do_blocks(
			'<!-- wp:query {"queryId":' . $post_id . ',"query":{"inherit":false,"postType":"post","perPage":1,"include":[' . $post_id . ']}} --><div class="wp-block-query">'
			. '<!-- wp:post-template -->'
			. '<!-- wp:post-title {"level":3,"isLink":true,"className":"pkiw-contents__title"} /-->'
			. '<!-- wp:post-kinds-indieweb/reading-time /-->'
			. '<!-- wp:post-featured-image {"className":"pkiw-contents__picture"} /-->'
			. '<!-- /wp:post-template -->'
			. '</div><!-- /wp:query -->'
		);
	}

	private function stream_card_html( int $post_id ): string {
		$GLOBALS['post'] = get_post( $post_id );

		return render_block(
			[
				'blockName'    => 'post-kinds-indieweb/stream-card',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	private function filtered_stream_card_html( int $post_id ): string {
		return \PKIW\Article_Archive::stream_card_style(
			$this->stream_card_html( $post_id ),
			[ 'blockName' => 'post-kinds-indieweb/stream-card' ],
			null
		);
	}

	private function render_current_template( string $kind ): string {
		$template = resolve_block_template( 'taxonomy', [ "taxonomy-kind-{$kind}.php", 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );

		return new DOMXPath( $dom );
	}
}

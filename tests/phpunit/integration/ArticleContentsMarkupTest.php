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

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 10 );
		$this->register_article_archive();
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_dequeue_style( 'pkiw-article-contents' );
		wp_dequeue_style( 'pkiw-article-stream-card' );
		wp_set_current_user( 0 );
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
		$this->assertStringNotContainsString( 'p-name', $html );
	}

	public function test_an_untitled_protected_article_uses_the_fallback_without_protected_text(): void {
		$post_id = $this->article( '', 200, '2026-08-19 10:00:00', [ 'post_password' => 'secret' ] );
		$html    = $this->contents_item_html( $post_id );

		$this->assertStringContainsString( 'Article, August 19, 2026', $html );
		$this->assertStringNotContainsString( 'p-name', $html );
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

	public function test_visible_text_is_title_reading_time_and_date_only(): void {
		$this->article( 'Plain Article', 1000, '2026-08-12 10:00:00', [ 'post_excerpt' => 'Do not print this deck.' ] );
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $this->article_archive_html() ) ) );

		$this->assertStringContainsString( 'Plain Article', $text );
		$this->assertStringContainsString( '5 min read', $text );
		$this->assertStringNotContainsString( 'Do not print this deck.', $text );
		$this->assertStringNotContainsString( 'Kind', $text );
		$this->assertStringNotContainsString( 'Badge', $text );
	}

	public function test_the_date_is_inside_the_contents_date_block(): void {
		$this->article( 'Date Test', 200, '2026-08-12 10:00:00' );

		$this->assertSame( 1, $this->xpath( $this->article_archive_html() )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pkiw-contents__date ")]//time[contains(concat(" ", normalize-space(@class), " "), " dt-published ")]' )->length );
	}

	public function test_geo_values_are_absent_from_contents_items(): void {
		$this->article( 'Geo Article', 200, '2026-08-12 10:00:00', [ 'post_content' => '<!-- wp:paragraph --><p>Body from a fictional orchard.</p><!-- /wp:paragraph -->' ] );
		$html = $this->article_archive_html();

		foreach ( [ 'p-location', 'h-geo', 'geo', 'latitude', 'longitude' ] as $token ) {
			$this->assertStringNotContainsString( $token, $html );
		}
	}

	public function test_theme_shaped_post_template_is_byte_identical_and_does_not_enqueue_the_contents_style(): void {
		$post_id = $this->article( 'Theme Shape', 200, '2026-08-12 10:00:00' );
		$template = '<!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- wp:post-date /--><!-- /wp:post-template -->';
		$this->go_to( get_permalink( $post_id ) );

		remove_filter( 'render_block_core/post-title', [ \PKIW\Article_Archive::class, 'title' ], 10 );
		remove_filter( 'render_block_core/post-date', [ \PKIW\Article_Archive::class, 'date' ], 10 );
		remove_filter( 'render_block_core/post-featured-image', [ \PKIW\Article_Archive::class, 'picture' ], 10 );
		remove_filter( 'render_block_core/post-template', [ \PKIW\Article_Archive::class, 'contents_wrapper' ], 10 );
		$without = do_blocks( $template );
		( new \PKIW\Article_Archive() )->register();
		$with = do_blocks( $template );

		$this->assertSame( $without, $with );
		$this->assertFalse( wp_style_is( 'pkiw-article-contents', 'enqueued' ) );
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

	private function register_article_archive(): void {
		$this->assertTrue( class_exists( 'PKIW\\Article_Archive' ), 'PKIW\\Article_Archive must exist for issue 263.' );
		$this->assertTrue( class_exists( 'PKIW\\Reading_Time' ), 'PKIW\\Reading_Time must exist for issue 263.' );
		( new \PKIW\Article_Archive() )->register();
		( new \PKIW\Reading_Time() )->register();
		do_action( 'init' );
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

		return do_blocks(
			'<!-- wp:post-title {"level":3,"isLink":true,"className":"pkiw-contents__title"} /-->'
			. '<!-- wp:post-kinds-indieweb/reading-time /-->'
			. '<!-- wp:post-featured-image {"className":"pkiw-contents__picture"} /-->'
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

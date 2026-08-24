<?php
/**
 * Coverage for the [pk_stream_card] Stream renderer.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * The Stream card renderer collapses two post shapes — Post Kinds
 * micro-posts and long-form watch articles — into one compact feed item.
 *
 * @group integration
 */
final class StreamCardTest extends WP_UnitTestCase {

	/**
	 * Block every live HTTP request so render_block never hits the network
	 * for an oEmbed lookup.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * A linked card title is repointed at the post permalink.
	 */
	public function test_link_title_to_post_repoints_anchor(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Movie' ] );
		$post    = get_post( $post_id );
		$html    = '<h3 class="pk-title p-name"><a class="u-url" href="https://youtu.be/x" target="_blank" rel="noopener">Movie</a></h3>';

		$out = \PKIW\link_title_to_post( $html, $post );

		$this->assertStringContainsString( '<a href="' . esc_url( (string) get_permalink( $post_id ) ) . '">Movie</a>', $out );
		$this->assertStringNotContainsString( 'youtu.be', $out );
	}

	/**
	 * A plain-text card title gets wrapped in a link to the post.
	 */
	public function test_link_title_to_post_wraps_plaintext(): void {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Enola' ] );
		$post    = get_post( $post_id );
		$html    = '<h3 class="pk-title p-name">Enola</h3>';

		$out = \PKIW\link_title_to_post( $html, $post );

		$this->assertStringContainsString( '<a href="' . esc_url( (string) get_permalink( $post_id ) ) . '">Enola</a>', $out );
	}

	/**
	 * A body of only card blocks is a micro-post.
	 */
	public function test_card_only_body_is_micro_post(): void {
		$content = '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola Holmes 3"} /-->';
		$this->assertTrue( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * A trailing empty paragraph doesn't stop a single-card post from
	 * reading as a micro-post.
	 */
	public function test_card_with_trailing_empty_paragraph_is_micro_post(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">' .
			'<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola"} /-->' .
			"</div><!-- /wp:group -->\n\n<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
		$this->assertTrue( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * A non-empty trailing paragraph makes it long-form again.
	 */
	public function test_card_with_real_paragraph_is_not_micro_post(): void {
		$content = '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"X"} /-->' .
			"\n\n<!-- wp:paragraph -->\n<p>Real words.</p>\n<!-- /wp:paragraph -->";
		$this->assertFalse( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * The post date is injected under the card title, before the media.
	 */
	public function test_inject_post_date_into_card(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title' => 'Movie',
				'post_date'  => '2026-07-05 09:00:00',
			]
		);
		$post = get_post( $post_id );
		$html = '<h3 class="pk-title p-name"><a href="#">Movie</a></h3><div class="pk-embed"></div>';

		$out = \PKIW\inject_post_date_into_card( $html, $post );

		$this->assertStringContainsString( 'pk-stream-date', $out );
		$this->assertStringContainsString( '<time class="dt-published"', $out );
		$this->assertLessThan( strpos( $out, 'pk-embed' ), strpos( $out, 'pk-stream-date' ) );
	}

	/**
	 * A card wrapped in a group is still a micro-post.
	 */
	public function test_group_wrapped_card_is_micro_post(): void {
		$content = "<!-- wp:group -->\n<div class=\"wp-block-group\">" .
			'<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola Holmes 3","rating":4} /-->' .
			"</div>\n<!-- /wp:group -->";
		$this->assertTrue( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * An article with paragraphs and headings is not a micro-post.
	 */
	public function test_article_body_is_not_micro_post(): void {
		$content = "<!-- wp:heading -->\n<h2>Hi</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Words.</p>\n<!-- /wp:paragraph -->";
		$this->assertFalse( \PKIW\content_is_kind_card_only( $content ) );
	}

	/**
	 * Empty content is never a micro-post.
	 */
	public function test_empty_body_is_not_micro_post(): void {
		$this->assertFalse( \PKIW\content_is_kind_card_only( '' ) );
	}

	/**
	 * The Able Player shortcode's youtube-id becomes a watch URL.
	 */
	public function test_extracts_video_from_ableplayer_shortcode(): void {
		$content = '<!-- wp:shortcode -->[ableplayer youtube-id="dQw4w9WgXcQ" youtube-nocookie="true"]<!-- /wp:shortcode -->';
		$this->assertSame(
			'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			\PKIW\extract_first_video_url( $content )
		);
	}

	/**
	 * A YouTube core/embed block is used when no shortcode is present.
	 */
	public function test_extracts_video_from_core_embed(): void {
		$content = '<!-- wp:embed {"url":"https://youtu.be/dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube"} -->' .
			'<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://youtu.be/dQw4w9WgXcQ</div></figure>' .
			'<!-- /wp:embed -->';
		$this->assertSame( 'https://youtu.be/dQw4w9WgXcQ', \PKIW\extract_first_video_url( $content ) );
	}

	/**
	 * No video in the body returns an empty string.
	 */
	public function test_no_video_returns_empty_string(): void {
		$content = "<!-- wp:paragraph -->\n<p>No video here.</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( '', \PKIW\extract_first_video_url( $content ) );
	}

	/**
	 * A micro-post renders its card, not the fallback link.
	 */
	public function test_micro_post_renders_card(): void {
		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Enola Holmes 3"} /-->' ]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card', $html );
		$this->assertStringContainsString( 'Enola Holmes 3', $html );
		$this->assertStringNotContainsString( 'pk-stream-fallback', $html );
	}

	/**
	 * A long-form watch post renders a watch card carrying the post title.
	 */
	public function test_long_form_watch_renders_watch_card(): void {
		$this->ensure_kind_term( 'watch' );

		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'This Week in WordPress 374',
				'post_content' => "<!-- wp:paragraph -->\n<p>A long recap.</p>\n<!-- /wp:paragraph -->\n\n" .
					'<!-- wp:shortcode -->[ableplayer youtube-id="dQw4w9WgXcQ"]<!-- /wp:shortcode -->',
			]
		);
		wp_set_object_terms( $post_id, 'watch', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'k-watch', $html );
		$this->assertStringContainsString( 'This Week in WordPress 374', $html );
		// The article prose must not reach the feed.
		$this->assertStringNotContainsString( 'A long recap.', $html );
	}

	/**
	 * A long-form post with no kind renders a compact card with its excerpt —
	 * never the full body, and never the old bare-link fallback.
	 */
	public function test_long_form_non_watch_renders_generic_card(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Just an essay',
				'post_content' => "<!-- wp:paragraph -->\n<p>The full body of the essay.</p>\n<!-- /wp:paragraph -->",
				'post_excerpt' => 'A short summary.',
			]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card', $html );
		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringNotContainsString( 'pk-stream-fallback', $html );
		$this->assertStringContainsString( 'Just an essay', $html );
		// The excerpt shows; the full body never reaches the feed.
		$this->assertStringContainsString( 'A short summary.', $html );
		$this->assertStringContainsString( 'p-summary', $html );
		$this->assertStringNotContainsString( 'The full body of the essay.', $html );
	}

	/**
	 * A kinded long-form post shows its kind label; an unkinded one falls back
	 * to "Note".
	 */
	public function test_generic_card_shows_kind_label(): void {
		$this->ensure_kind_term( 'article' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'A write-up',
				'post_content' => "<!-- wp:paragraph -->\n<p>Words and words.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'article', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-kindlabel', $html );
		$this->assertStringContainsString( 'k-article', $html );
	}

	/**
	 * A no-kind post's card labels as "Note" and carries the k-note class.
	 */
	public function test_generic_card_defaults_to_note_without_kind(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Untagged thought',
				'post_content' => "<!-- wp:paragraph -->\n<p>Just a thought.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'k-note', $html );
		$this->assertStringContainsString( 'Note', $html );
	}

	/**
	 * A featured image rides into the card as a `u-photo`.
	 */
	public function test_generic_card_includes_featured_image(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Illustrated post',
				'post_content' => "<!-- wp:paragraph -->\n<p>Has a picture.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$attachment_id = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg',
			$post_id
		);
		set_post_thumbnail( $post_id, $attachment_id );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-media--stream', $html );
		$this->assertStringContainsString( 'u-photo', $html );
	}

	/**
	 * A title-less post still renders a full card — the kind label stands in
	 * as the linked title, without claiming to be the entry's p-name.
	 */
	public function test_long_form_without_title_renders_card_with_label_title(): void {
		$this->ensure_kind_term( 'weather' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => "<!-- wp:paragraph -->\n<p>Sunny, 22°C.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'weather', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringContainsString( 'k-weather', $html );
		$this->assertStringContainsString( 'pk-title', $html );
		// The synthetic title is navigation, not the entry name.
		$this->assertStringNotContainsString( 'p-name', $html );
		$this->assertStringContainsString( '>Weather</a>', $html );
	}

	/**
	 * A kind term the plugin has never heard of — no card block, no icon, no
	 * default entry — still renders a complete generic card carrying the
	 * standard structure classes the theme styles against.
	 */
	public function test_unknown_kind_renders_generic_card(): void {
		$this->ensure_kind_term( 'zzz-future-kind' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => "<!-- wp:paragraph -->\n<p>From the future.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'zzz-future-kind', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertNotSame( '', $html );
		$this->assertStringContainsString( 'k-zzz-future-kind', $html );
		$this->assertStringContainsString( 'pk-badge', $html );
		$this->assertStringContainsString( 'pk-kindlabel', $html );
		$this->assertStringContainsString( 'pk-title', $html );
		$this->assertStringContainsString( 'pk-stream-date', $html );
	}

	/**
	 * A long-form mood post keeps its mood-card emoji on the generic card,
	 * ahead of the caption so the theme can paint it as the enamel pin.
	 */
	public function test_long_form_mood_renders_mood_card_emoji(): void {
		$this->ensure_kind_term( 'mood' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'A rainy-day reflection',
				'post_content' => '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Melancholy","emoji":"🌧️"} /-->' .
					"\n\n<!-- wp:paragraph -->\n<p>Long thoughts about the rain.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'mood', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'pk-card--stream', $html );
		$this->assertStringContainsString( '<span class="pk-mood__emoji" aria-hidden="true">🌧️</span>', $html );
		$this->assertLessThan( strpos( $html, 'pk-caption' ), strpos( $html, 'pk-mood__emoji' ) );
	}

	/**
	 * A mood-card block with no explicit emoji still pins the block's 😊
	 * default to the card.
	 */
	public function test_long_form_mood_without_explicit_emoji_uses_block_default(): void {
		$this->ensure_kind_term( 'mood' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Feeling fine',
				'post_content' => '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Content"} /-->' .
					"\n\n<!-- wp:paragraph -->\n<p>Nothing much to add.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'mood', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( '<span class="pk-mood__emoji" aria-hidden="true">😊</span>', $html );
	}

	/**
	 * A mood post with no mood-card block in the body gets no invented pin.
	 */
	public function test_long_form_mood_without_mood_card_block_has_no_emoji(): void {
		$this->ensure_kind_term( 'mood' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Mood, in prose only',
				'post_content' => "<!-- wp:paragraph -->\n<p>Words about a feeling.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'mood', 'kind' );
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( 'k-mood', $html );
		$this->assertStringNotContainsString( 'pk-mood__emoji', $html );
	}

	/**
	 * Make sure a `kind` term exists so it can be assigned to a post.
	 *
	 * @param string $slug Kind slug.
	 */
	private function ensure_kind_term( string $slug ): void {
		if ( ! term_exists( $slug, 'kind' ) ) {
			wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
		}
	}
}

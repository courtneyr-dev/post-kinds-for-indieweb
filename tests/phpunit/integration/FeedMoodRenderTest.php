<?php
/**
 * Integration tests for mood rendering in syndication feeds.
 *
 * A mood post's card block renders badge SVG, a "Mood" kind label, and the
 * emoji as block-level markup — a feed reader flattens that into stray
 * lines. In feeds the mood should collapse to its essence: the emoji
 * inline at the head of the post text.
 *
 * @package PKIW
 */

/**
 * @covers \PKIW\suppress_mood_card_in_feeds
 * @covers \PKIW\feed_mood_content
 * @covers \PKIW\feed_mood_excerpt
 */
class FeedMoodRenderTest extends WP_UnitTestCase {

	const MOOD_CARD = '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Excited","emoji":"🥳","note":"Excited","moodAt":"2026-08-23"} /-->';

	public function test_long_form_mood_feed_content_leads_with_inline_emoji(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Demo.RSS.Chat',
				'post_content' => self::MOOD_CARD . "\n\n" .
					'<!-- wp:paragraph --><p>Testing out connecting my site to Demo.RSS.Chat.</p><!-- /wp:paragraph -->',
			]
		);

		$content = $this->feed_content_for( $post_id );

		$this->assertMatchesRegularExpression( '/<p\b[^>]*>🥳 Testing out connecting my site to Demo\.RSS\.Chat\.<\/p>/', $content );
		$this->assertStringNotContainsString( 'Mood', $content );
		$this->assertStringNotContainsString( 'pk-card', $content );
		$this->assertStringNotContainsString( 'pk-kindlabel', $content );
	}

	public function test_micro_mood_feed_content_is_emoji_and_note(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Recharged',
				'post_content' => '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Recharged","emoji":"😌","note":"Recharged","moodAt":"2026-08-10"} /-->',
			]
		);

		$content = $this->feed_content_for( $post_id );

		$this->assertSame( '<p>😌 Recharged</p>', trim( $content ) );
	}

	public function test_mood_card_without_emoji_leaves_content_bare(): void {
		$post_id = self::factory()->post->create(
			[
				'post_content' => '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Quiet","note":"Quiet"} /-->' . "\n\n" .
					'<!-- wp:paragraph --><p>Just words today.</p><!-- /wp:paragraph -->',
			]
		);

		$content = $this->feed_content_for( $post_id );

		$this->assertMatchesRegularExpression( '/<p\b[^>]*>Just words today\.<\/p>/', $content );
		$this->assertStringNotContainsString( 'pk-card', $content );
	}

	public function test_non_mood_post_feed_content_is_untouched(): void {
		$post_id = self::factory()->post->create(
			[
				'post_content' => '<!-- wp:paragraph --><p>An ordinary post.</p><!-- /wp:paragraph -->',
			]
		);

		$content = $this->feed_content_for( $post_id );

		$this->assertMatchesRegularExpression( '/<p\b[^>]*>An ordinary post\.<\/p>/', $content );
		$this->assertStringNotContainsString( '🥳', $content );
	}

	public function test_feed_excerpt_leads_with_emoji(): void {
		$post_id = self::factory()->post->create(
			[
				'post_excerpt' => '',
				'post_content' => self::MOOD_CARD . "\n\n" .
					'<!-- wp:paragraph --><p>Testing out connecting my site to Demo.RSS.Chat.</p><!-- /wp:paragraph -->',
			]
		);

		$this->go_to( '/?feed=rss2' );

		$excerpt = null;
		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $post_id ) {
				$excerpt = apply_filters( 'the_excerpt_rss', get_the_excerpt() );
				break;
			}
		}

		$this->assertNotNull( $excerpt, 'Post never appeared in the feed loop.' );
		$this->assertStringStartsWith( '🥳 Testing out connecting my site', $excerpt );
	}

	public function test_web_rendering_still_shows_the_full_card(): void {
		$post_id = self::factory()->post->create(
			[
				'post_content' => self::MOOD_CARD . "\n\n" .
					'<!-- wp:paragraph --><p>Testing out connecting my site to Demo.RSS.Chat.</p><!-- /wp:paragraph -->',
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		$post = get_post( $post_id );

		$this->assertFalse( is_feed() );

		$content = do_blocks( $post->post_content );

		$this->assertStringContainsString( 'pk-card', $content );
		$this->assertStringContainsString( 'pk-mood__emoji', $content );
	}

	/**
	 * Render a post's feed content the way the RSS2 template does.
	 *
	 * @param int $post_id Post to render.
	 * @return string Feed item content.
	 */
	private function feed_content_for( int $post_id ): string {
		$this->go_to( '/?feed=rss2' );

		$this->assertTrue( is_feed(), 'Feed context did not take.' );

		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $post_id ) {
				return get_the_content_feed( 'rss2' );
			}
		}

		$this->fail( sprintf( 'Post %d never appeared in the feed loop.', $post_id ) );
	}
}

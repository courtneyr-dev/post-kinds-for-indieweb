<?php
/**
 * Kindle_Embed_Bridge integration coverage.
 *
 * @package PostKindsForIndieWeb
 */

declare(strict_types=1);

/**
 * Verifies the render-time bridge rewrites a marked core/embed's iframe src
 * from post meta, and leaves unrelated embeds untouched.
 *
 * @group integration
 */
final class KindleEmbedBridgeTest extends WP_UnitTestCase {

	/**
	 * Block all live HTTP requests.
	 *
	 * The render bridge must never depend on the network — it only reads
	 * post meta and builds a URL string. Same guard as BlockFieldRenderTest,
	 * so a future regression that reintroduces a live lookup fails loudly
	 * here instead of making these tests flaky in CI.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) {
				return new WP_Error( 'http_blocked', 'Live HTTP is blocked in render tests: ' . $url );
			},
			10,
			3
		);
	}

	public function test_marked_embed_gets_iframe_src_from_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' =>
				'<!-- wp:embed {"url":"https://read.amazon.com/kp/embed?asin=PLACEHOLDER0","type":"video","providerNameSlug":"amazon-kindle","className":"pkiw-kindle-preview"} -->' .
				'<figure class="wp-block-embed is-provider-amazon-kindle pkiw-kindle-preview"><div class="wp-block-embed__wrapper">' . "\n" .
				'https://read.amazon.com/kp/embed?asin=PLACEHOLDER0' . "\n" .
				'</div></figure><!-- /wp:embed -->',
		] );
		update_post_meta( $post_id, '_postkind_read_asin', '1649374046' );

		$this->go_to( get_permalink( $post_id ) );
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringContainsString( 'src="https://read.amazon.com/kp/embed?asin=1649374046&#038;preview=inline"', $html );
		$this->assertStringContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( 'PLACEHOLDER0', $html );
	}

	public function test_unmarked_embeds_untouched(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=x","providerNameSlug":"youtube"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">x</div></figure><!-- /wp:embed -->',
		] );
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );
		$this->assertStringNotContainsString( 'read.amazon.com', $html );
	}

	public function test_marker_substring_class_does_not_trigger_rewrite(): void {
		$post_id = self::factory()->post->create( [
			'post_content' =>
				'<!-- wp:embed {"url":"https://read.amazon.com/kp/embed?asin=PLACEHOLDER0","type":"video","providerNameSlug":"amazon-kindle","className":"not-pkiw-kindle-preview"} -->' .
				'<figure class="wp-block-embed is-provider-amazon-kindle not-pkiw-kindle-preview"><div class="wp-block-embed__wrapper">' . "\n" .
				'https://read.amazon.com/kp/embed?asin=PLACEHOLDER0' . "\n" .
				'</div></figure><!-- /wp:embed -->',
		] );
		update_post_meta( $post_id, '_postkind_read_asin', '1649374046' );

		$this->go_to( get_permalink( $post_id ) );
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		// The marker must match as a whole class token, not a substring.
		$this->assertStringContainsString( 'PLACEHOLDER0', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_marked_embed_with_no_derivable_asin_is_untouched(): void {
		$post_id = self::factory()->post->create( [
			'post_content' =>
				'<!-- wp:embed {"url":"https://read.amazon.com/kp/embed?asin=PLACEHOLDER0","type":"video","providerNameSlug":"amazon-kindle","className":"pkiw-kindle-preview"} -->' .
				'<figure class="wp-block-embed is-provider-amazon-kindle pkiw-kindle-preview"><div class="wp-block-embed__wrapper">' . "\n" .
				'https://read.amazon.com/kp/embed?asin=PLACEHOLDER0' . "\n" .
				'</div></figure><!-- /wp:embed -->',
		] );
		// No _postkind_read_asin and no _postkind_read_isbn meta set — nothing derivable.

		$this->go_to( get_permalink( $post_id ) );
		$html = apply_filters( 'the_content', get_post( $post_id )->post_content );

		$this->assertStringContainsString( 'PLACEHOLDER0', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}
}

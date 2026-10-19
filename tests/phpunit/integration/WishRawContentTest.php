<?php
/**
 * Raw wish card preservation for readers that do not render blocks.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Confirms wish price and reason remain public in stored editor markup.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishRawContentTest extends WP_UnitTestCase {

	/**
	 * Register the real wish taxonomy and meta filters.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	/**
	 * Realistic static save markup from the wish editor block.
	 *
	 * @since 1.9.0
	 *
	 * @return string Saved block pair.
	 */
	private function saved_card(): string {
		$attrs = [
			'title'     => 'Seed greenhouse',
			'wishType'  => 'tech',
			'url'       => 'https://shop.example/seed-greenhouse',
			'price'     => '$725',
			'reason'    => 'Grow greens in winter',
			'priority'  => 'high',
			'rel'       => 'product external',
		];

		return '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes( $attrs ) . ' -->'
			. '<div class="wp-block-post-kinds-indieweb-wish-card wish-card layout-horizontal priority-high"><div class="post-kinds-card h-cite"><div class="post-kinds-card__content">'
			. '<span class="wish-card__type">Tech</span><h3 class="post-kinds-card__title p-name"><a class="u-url u-wish-of" href="https://shop.example/seed-greenhouse" rel="product external">Seed greenhouse</a></h3>'
			. '<p class="wish-card__price">$725</p><p class="wish-card__reason p-content">Grow greens in winter</p><data class="wish-card__type-slug" value="food"></data>'
			. '</div></div></div><!-- /wp:post-kinds-indieweb/wish-card -->';
	}

	/**
	 * Create a public wish holding the saved editor HTML.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Stored content.
	 * @return int Post ID.
	 */
	private function wish( string $content ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Stored wish',
				'post_content' => wp_slash( $content ),
			]
		);
		wp_set_object_terms( $post_id, 'wish', Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * The privacy scrubber leaves every byte of public wish markup intact.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_private_card_text_scrubber_leaves_saved_wish_markup_byte_identical(): void {
		$content = $this->saved_card();
		$post_id = $this->wish( $content );

		$this->assertSame( $content, Meta_Fields::strip_private_card_text( $content, $post_id ) );
	}

	/**
	 * Display-context raw post content remains the content the editor stored.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_display_post_content_equals_the_stored_wish_content(): void {
		$content = $this->saved_card();
		$post_id = $this->wish( $content );

		$this->assertSame( $content, get_post_field( 'post_content', $post_id, 'display' ) );
	}

	/**
	 * ActivityPub-style content filtering outside a singular request renders safely.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_activitypub_proxy_render_outside_singular_keeps_title_and_price(): void {
		$post_id = $this->wish( $this->saved_card() );
		$this->go_to( home_url( '/' ) );
		$post            = get_post( $post_id );
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$html = apply_filters( 'the_content', $post->post_content );
		wp_reset_postdata();

		$this->assertStringContainsString( 'Seed greenhouse', $html );
		$this->assertStringContainsString( '$725', $html );
	}
}

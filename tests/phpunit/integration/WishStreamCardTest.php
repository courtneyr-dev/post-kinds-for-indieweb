<?php
/**
 * Wish routing and rendering through the real Stream card block.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Post_Surface;
use PKIW\Taxonomy;

/**
 * Covers card-only, Quick Post, Micropub and protected Stream shapes.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishStreamCardTest extends WP_UnitTestCase {

	/**
	 * Register the real taxonomy and metadata readers.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pre_http_request', '__return_empty_array' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Restore shared request state.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Create a public wish post.
	 *
	 * @since 1.9.0
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $args    Post overrides.
	 * @return int Post ID.
	 */
	private function wish( string $content, array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Stream wish',
					'post_date'    => '2026-07-05 10:00:00',
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'wish', Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * Wish card comment.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @return string Block comment.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Render a Stream block with the target post in loop scope.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Stream HTML.
	 */
	private function stream( int $post_id ): string {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->' );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Parse rendered HTML.
	 *
	 * @since 1.9.0
	 *
	 * @param string $html HTML.
	 * @return DOMXPath XPath object.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $dom );
	}

	/**
	 * A routed card-only wish keeps one permalink and a hidden cited item.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_card_only_wish_routes_to_stream_and_renders_the_compact_form(): void {
		$filter = static fn(): array => [ 'wish' ];
		add_filter( 'pkiw_stream_kinds', $filter );
		$item_url = 'https://shop.example/stream-greenhouse';
		$post_id  = $this->wish(
			$this->card(
				[
					'title'     => 'Stream Greenhouse',
					'wishType'  => 'product',
					'url'       => $item_url,
					'price'     => '$900',
					'reason'    => 'Grow food all winter',
					'priority'  => 'high',
					'wishedAt' => '2026-07-04',
				]
			)
		);

		$this->assertSame( Post_Surface::STREAM, Post_Surface::get( $post_id ) );
		$html    = $this->stream( $post_id );
		$xpath   = $this->xpath( $html );
		$anchors = $xpath->query( '//a[@href]' );
		$this->assertSame( 1, $anchors->length );
		$this->assertSame( esc_url( (string) get_permalink( $post_id ) ), $anchors->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 1, $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " pk-card ")]/descendant::h3[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//data[@hidden][contains(concat(" ", normalize-space(@class), " "), " u-wish-of ")][@value="' . $item_url . '"]' )->length );
		$this->assertLessThan( strpos( $html, '<h3 class="pk-title' ), strpos( $html, '<data class="u-url u-wish-of"' ) );
		$this->assertSame( 1, substr_count( $html, $item_url ) );
		$this->assertStringNotContainsString( 'Grow food all winter', $html );
		$this->assertStringNotContainsString( '2026-07-04', $html );
		$this->assertStringNotContainsString( 'View item', $html );
		$this->assertSame( 1, $xpath->query( '//p[contains(concat(" ", normalize-space(@class), " "), " pk-stream-date ")]' )->length );
		$this->assertMatchesRegularExpression( '/Product.*High priority.*\$900/s', $html );
		$this->assertStringContainsString( 'data-priority="high"', $html );
		remove_filter( 'pkiw_stream_kinds', $filter );
	}

	/**
	 * A card-less Quick Post uses the generic Stream card and hidden wish cite.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_quick_post_wish_uses_generic_card_with_hidden_name_and_url(): void {
		$filter = static fn(): array => [ 'wish' ];
		add_filter( 'pkiw_stream_kinds', $filter );
		$post_id = $this->wish( '<!-- wp:paragraph --><p>Notes for later.</p><!-- /wp:paragraph -->', [ 'post_title' => 'Train journey' ] );
		update_post_meta( $post_id, '_pkiw_wish_name', 'Night train ticket' );
		update_post_meta( $post_id, '_pkiw_wish_url', 'https://rail.example/night-train' );

		$html  = $this->stream( $post_id );
		$xpath = $this->xpath( $html );
		$this->assertSame( 1, $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " pk-card--stream ")][contains(concat(" ", normalize-space(@class), " "), " k-wish ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//span[contains(concat(" ", normalize-space(@class), " "), " u-wish-of ")][@hidden]/data[contains(concat(" ", normalize-space(@class), " "), " p-name ")][@value="Train journey"]' )->length );
		$this->assertSame( 1, $xpath->query( '//span[contains(concat(" ", normalize-space(@class), " "), " u-wish-of ")][@hidden]/data[contains(concat(" ", normalize-space(@class), " "), " u-url ")][@value="https://rail.example/night-train"]' )->length );
		remove_filter( 'pkiw_stream_kinds', $filter );
	}

	/**
	 * A Micropub paragraph remains a title-only generic listing.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_micropub_paragraph_lists_without_card_facts_or_a_fatal(): void {
		$filter = static fn(): array => [ 'wish' ];
		add_filter( 'pkiw_stream_kinds', $filter );
		$post_id = $this->wish( '<!-- wp:paragraph --><p>Wishing for <a class="u-wish-of" href="https://example.org/observatory">an observatory visit</a>.</p><!-- /wp:paragraph -->', [ 'post_title' => 'Observatory visit' ] );
		$html    = $this->stream( $post_id );

		$this->assertStringContainsString( 'Observatory visit', $html );
		$this->assertStringNotContainsString( 'High priority', $html );
		$this->assertStringNotContainsString( 'Medium priority', $html );
		$this->assertStringNotContainsString( 'pk-wish-price', $html );
		remove_filter( 'pkiw_stream_kinds', $filter );
	}

	/**
	 * Password protection cuts every card detail before the wish renderer runs.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_protected_routed_wish_is_a_title_only_protected_card(): void {
		$filter = static fn(): array => [ 'wish' ];
		add_filter( 'pkiw_stream_kinds', $filter );
		$post_id = $this->wish(
			$this->card( [ 'title' => 'Card secret', 'price' => '$777', 'reason' => 'Secret reason', 'image' => 'https://images.example/secret.jpg' ] ),
			[ 'post_title' => 'Protected wish', 'post_password' => 'secret' ]
		);
		$html = $this->stream( $post_id );

		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringContainsString( 'Protected: Protected wish', $html );
		foreach ( [ 'Card secret', '$777', 'Secret reason', 'images.example', 'pk-wish-chip' ] as $private ) {
			$this->assertStringNotContainsString( $private, $html );
		}
		remove_filter( 'pkiw_stream_kinds', $filter );
	}

	/**
	 * Wish remains a main-surface post until the release filter opts it in.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_wish_is_main_without_the_stream_kind_filter(): void {
		$post_id = $this->wish( $this->card( [ 'title' => 'Main wish' ] ) );

		$this->assertSame( Post_Surface::MAIN, Post_Surface::get( $post_id ) );
	}

	/**
	 * The golden wish fixture follows the new compact Stream shape.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_golden_fixture_shape_has_one_anchor_and_chips(): void {
		$post_id = $this->wish( $this->card( [ 'title' => 'Golden card wish', 'url' => 'https://example.com/golden' ] ) );
		$html    = $this->stream( $post_id );
		$xpath   = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//a[@href]' )->length );
		$this->assertSame( 2, $xpath->query( '//span[contains(concat(" ", normalize-space(@class), " "), " pk-wish-chip ")]' )->length );
		$this->assertStringContainsString( '>Item</span>', $html );
		$this->assertStringContainsString( '>Medium priority</span>', $html );
	}
}

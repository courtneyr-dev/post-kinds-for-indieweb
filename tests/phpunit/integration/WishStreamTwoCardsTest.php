<?php
/**
 * A wish post holding two wish cards prints one Stream article.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Wish_Archive;

/**
 * Covers the two-card wish Stream shape and its byte-identical guards.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishStreamTwoCardsTest extends WP_UnitTestCase {

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
	 * Create a public post of a kind.
	 *
	 * @since 1.9.0
	 *
	 * @param string               $kind    Kind slug.
	 * @param string               $content Post content.
	 * @param array<string, mixed> $args    Post overrides.
	 * @return int Post ID.
	 */
	private function post_of_kind( string $kind, string $content, array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Two card wish',
					'post_date'    => '2026-07-05 10:00:00',
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * Serialized wish card block.
	 *
	 * @since 1.9.0
	 *
	 * @param string $title Card title.
	 * @param string $type  Wish type.
	 * @return string Block comment.
	 */
	private function wish_card( string $title, string $type = 'tech' ): string {
		return '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes(
			[
				'title'    => $title,
				'wishType' => $type,
				'priority' => 'low',
			]
		) . ' /-->';
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
	 * Render a Stream card with the wish callback detached, then reattached.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Stream HTML without the wish callback.
	 */
	private function stream_without_callback( int $post_id ): string {
		$callback = [ Wish_Archive::class, 'stream_card' ];
		$priority = has_filter( 'pkiw_stream_card_kind_render', $callback );
		if ( false !== $priority ) {
			remove_filter( 'pkiw_stream_card_kind_render', $callback, $priority );
		}
		$html = $this->stream( $post_id );
		if ( false !== $priority ) {
			add_filter( 'pkiw_stream_card_kind_render', $callback, $priority, 4 );
		}

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
	 * Two wish cards print one article, one permalink link and the first title.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_two_wish_cards_print_only_the_first_card(): void {
		$post_id = $this->post_of_kind( 'wish', $this->wish_card( 'First Card Wins' ) . "\n\n" . $this->wish_card( 'Ignored Second Card' ) );
		$html    = $this->stream( $post_id );
		$xpath   = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//article' )->length, 'Exactly one article.' );
		$anchors = $xpath->query( '//a[@href]' );
		$this->assertSame( 1, $anchors->length, 'Exactly one link.' );
		$this->assertSame( esc_url( (string) get_permalink( $post_id ) ), $anchors->item( 0 )->getAttribute( 'href' ) );
		$this->assertStringContainsString( 'First Card Wins', $html );
		$this->assertStringNotContainsString( 'Ignored Second Card', $html );
	}

	/**
	 * The card the Stream renders is the card the facts reader names.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_rendered_card_is_the_card_the_facts_reader_uses(): void {
		$post_id = $this->post_of_kind( 'wish', $this->wish_card( 'First Card Wins' ) . "\n\n" . $this->wish_card( 'Ignored Second Card' ), [ 'post_title' => '' ] );

		$this->assertSame( 'First Card Wins', Wish_Archive::facts( $post_id )['name'] );
		$this->assertStringContainsString( 'First Card Wins', $this->stream( $post_id ) );
	}

	/**
	 * The article for two cards matches the article a one-card post prints.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_two_cards_render_the_same_article_as_the_first_card_alone(): void {
		$both = $this->post_of_kind( 'wish', $this->wish_card( 'First Card Wins' ) . "\n\n" . $this->wish_card( 'Ignored Second Card' ), [ 'post_name' => 'same-slug-a' ] );
		$one  = $this->post_of_kind( 'wish', $this->wish_card( 'First Card Wins' ), [ 'post_name' => 'same-slug-b' ] );

		$a = $this->stream( $both );
		$b = $this->stream( $one );
		$this->assertSame(
			str_replace( 'same-slug-a', 'x', $a ),
			str_replace( 'same-slug-b', 'x', $b )
		);
	}

	/**
	 * One wish card renders the same with and without the callback.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_single_wish_card_is_byte_identical_with_and_without_the_callback(): void {
		$post_id = $this->post_of_kind( 'wish', $this->wish_card( 'Only Card' ) );

		$with    = $this->stream( $post_id );
		$without = $this->stream_without_callback( $post_id );
		$this->assertStringContainsString( 'Only Card', $with );
		$this->assertSame( $without, $with );
		$this->assertSame( $with, $this->stream( $post_id ), 'Callback reattached.' );
	}

	/**
	 * One wish card inside a group wrapper renders unchanged.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_single_wish_card_in_a_group_is_byte_identical(): void {
		$content = '<!-- wp:group --><div class="wp-block-group">' . $this->wish_card( 'Grouped Card' ) . '</div><!-- /wp:group -->';
		$post_id = $this->post_of_kind( 'wish', $content );

		$with    = $this->stream( $post_id );
		$without = $this->stream_without_callback( $post_id );
		$this->assertStringContainsString( 'Grouped Card', $with );
		$this->assertSame( $without, $with );
	}

	/**
	 * Another kind with two of its own cards is untouched by the wish callback.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_non_wish_kind_with_two_cards_is_byte_identical(): void {
		$card = static fn( string $title ): string => '<!-- wp:post-kinds-indieweb/bookmark-card ' . serialize_block_attributes( [ 'bookmarkTitle' => $title, 'bookmarkUrl' => 'https://example.com/' . sanitize_title( $title ) ] ) . ' /-->';
		$post_id = $this->post_of_kind( 'bookmark', $card( 'First Bookmark' ) . "\n\n" . $card( 'Second Bookmark' ) );

		$this->assertTrue( PKIW\content_is_kind_card_only( (string) get_post( $post_id )->post_content ) );
		$with    = $this->stream( $post_id );
		$without = $this->stream_without_callback( $post_id );
		$this->assertSame( $without, $with );
		$this->assertSame( 2, $this->xpath( $with )->query( '//article' )->length, 'The built-in path still prints both cards.' );
	}

	/**
	 * Prose between two wish cards keeps the post out of the card-only path.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_two_wish_cards_with_prose_between_are_byte_identical(): void {
		$content = $this->wish_card( 'Prose First' ) . "\n\n<!-- wp:paragraph --><p>Prose between the cards.</p><!-- /wp:paragraph -->\n\n" . $this->wish_card( 'Prose Second' );
		$post_id = $this->post_of_kind( 'wish', $content );

		$this->assertFalse( PKIW\content_is_kind_card_only( $content ) );
		$with    = $this->stream( $post_id );
		$without = $this->stream_without_callback( $post_id );
		$this->assertSame( $without, $with );
		// The built-in generic card carries no body prose; the callback must not change that.
		$this->assertStringNotContainsString( 'Prose First', $with, 'Callback did not take over with the first card.' );
		$this->assertStringNotContainsString( 'Prose Second', $with );
	}

	/**
	 * A wish post with two wish cards and another kind's card prints only the first wish card.
	 *
	 * Intended: a wish post is one Stream item, the card its facts read.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_two_wish_cards_plus_another_kind_card_print_only_the_first_wish_card(): void {
		$bookmark = '<!-- wp:post-kinds-indieweb/bookmark-card ' . serialize_block_attributes( [ 'bookmarkTitle' => 'Mixed Bookmark' ] ) . ' /-->';
		$content  = $this->wish_card( 'Mixed First Wish' ) . "\n\n" . $bookmark . "\n\n" . $this->wish_card( 'Mixed Second Wish' );
		$post_id  = $this->post_of_kind( 'wish', $content );

		$this->assertTrue( PKIW\content_is_kind_card_only( $content ) );
		$html  = $this->stream( $post_id );
		$xpath = $this->xpath( $html );
		$this->assertSame( 1, $xpath->query( '//article' )->length );
		$this->assertStringContainsString( 'Mixed First Wish', $html );
		$this->assertStringNotContainsString( 'Mixed Second Wish', $html );
		$this->assertSame( 0, $xpath->query( '//*[contains(@class, "k-bookmark")]' )->length );
	}

	/**
	 * A protected two-card wish keeps the existing title-only path.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_protected_two_card_wish_is_unchanged(): void {
		$post_id = $this->post_of_kind(
			'wish',
			$this->wish_card( 'Secret First' ) . "\n\n" . $this->wish_card( 'Secret Second' ),
			[ 'post_title' => 'Protected two', 'post_password' => 'secret' ]
		);

		$with    = $this->stream( $post_id );
		$without = $this->stream_without_callback( $post_id );
		$this->assertStringContainsString( 'pk-card--protected', $with );
		$this->assertSame( $without, $with );
		$this->assertStringNotContainsString( 'Secret First', $with );
		$this->assertStringNotContainsString( 'Secret Second', $with );
	}

	/**
	 * The callback returns its first argument for a post that is not a wish.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_callback_passes_through_non_wish_and_bad_arguments(): void {
		$post_id = $this->post_of_kind( 'bookmark', $this->wish_card( 'A' ) . $this->wish_card( 'B' ) );

		$this->assertNull( Wish_Archive::stream_card( null, get_post( $post_id ), 2, [] ) );
		$this->assertSame( 'kept', Wish_Archive::stream_card( 'kept', null, 2, [] ) );
	}
}

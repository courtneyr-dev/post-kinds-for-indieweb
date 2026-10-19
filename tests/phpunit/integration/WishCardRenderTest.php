<?php
/**
 * Wish card rendering across single, listing and Stream surfaces.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Covers the public wish card HTML contract against the registered block.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishCardRenderTest extends WP_UnitTestCase {

	/**
	 * Prepare terms, meta fields and deterministic dates.
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
	 * Restore request state.
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
	 * Serialized wish card comment.
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
	 * Create a published post holding one wish card.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post overrides.
	 * @param string               $kind  Kind slug.
	 * @return int Post ID.
	 */
	private function wish( array $attrs, array $args = [], string $kind = 'wish' ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Wish post',
					'post_date'    => '2026-07-05 10:00:00',
					'post_content' => wp_slash( $this->card( $attrs ) ),
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * Render the card on its own single or on a non-own page.
	 *
	 * @since 1.9.0
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $own      Whether to use the post's singular request.
	 * @return string Rendered card HTML.
	 */
	private function render_card( int $post_id, bool $own = true ): string {
		$this->go_to( $own ? get_permalink( $post_id ) : home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Render the real Stream card block for a card-only wish.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Stream HTML.
	 */
	private function render_stream( int $post_id ): string {
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->' );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Parse rendered HTML for structural assertions.
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
	 * The single relies on its page heading and carries its card name as data.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_own_view_omits_heading_and_keeps_hidden_name_data(): void {
		$html  = $this->render_card( $this->wish( [ 'title' => 'Fictional Lantern' ] ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 0, $xpath->query( '//h2' )->length );
		$name = $xpath->query( '//data[contains(concat(" ", normalize-space(@class), " "), " p-name ")][@hidden]' );
		$this->assertSame( 1, $name->length );
		$this->assertSame( 'Fictional Lantern', $name->item( 0 )->getAttribute( 'value' ) );
	}

	/**
	 * Listings retain the visible wish heading.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_non_own_render_keeps_the_visible_name_heading(): void {
		$html = $this->render_card( $this->wish( [ 'title' => 'Fictional Lantern' ] ), false );

		$this->assertSame( 1, $this->xpath( $html )->query( '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")][contains(concat(" ", normalize-space(@class), " "), " p-name ")]' )->length );
	}

	/**
	 * Stored wish types and unknown values print only public labels.
	 *
	 * @since 1.9.0
	 *
	 * @dataProvider type_labels
	 *
	 * @param string $stored Stored type.
	 * @param string $label  Public label.
	 * @return void
	 */
	public function test_type_rows_use_public_labels( string $stored, string $label ): void {
		$html = $this->render_card( $this->wish( [ 'title' => 'Typed wish', 'wishType' => $stored ] ) );
		$row  = $this->xpath( $html )->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pk-wish-row ")][dt="Type"]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $row );
		$this->assertSame( $label, trim( $row->getElementsByTagName( 'dd' )->item( 0 )->textContent ) );
		if ( ! in_array( $stored, [ 'movie', 'media' ], true ) && strtolower( $stored ) !== strtolower( $label ) ) {
			$this->assertStringNotContainsString( $stored, $html );
		}
	}

	/**
	 * Wish type label cases.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, array{0:string,1:string}> Cases.
	 */
	public function type_labels(): array {
		return [
			'item'       => [ 'item', 'Item' ],
			'experience' => [ 'experience', 'Experience' ],
			'book'       => [ 'book', 'Book' ],
			'game'       => [ 'game', 'Game' ],
			'movie'      => [ 'movie', 'Movie/Show' ],
			'media'      => [ 'media', 'Movie/Show' ],
			'music'      => [ 'music', 'Music' ],
			'product'    => [ 'product', 'Product' ],
			'travel'     => [ 'travel', 'Travel' ],
			'tech'       => [ 'tech', 'Tech' ],
			'food'       => [ 'food', 'Food' ],
			'other'      => [ 'other', 'Other' ],
			'gizmo'      => [ 'gizmo', 'Other' ],
		];
	}

	/**
	 * Priority drives the wrapper attribute and normalized row label.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_priority_normalizes_high_unknown_and_absent_values(): void {
		$high    = $this->render_card( $this->wish( [ 'title' => 'High', 'priority' => 'high' ] ) );
		$unknown = $this->render_card( $this->wish( [ 'title' => 'Unknown', 'priority' => 'urgent' ] ) );
		$absent  = $this->render_card( $this->wish( [ 'title' => 'Absent' ] ) );

		$this->assertStringContainsString( 'data-priority="high"', $high );
		$this->assertStringContainsString( '>High</span>', $high );
		foreach ( [ $unknown, $absent ] as $html ) {
			$this->assertStringContainsString( 'data-priority="medium"', $html );
			$this->assertStringContainsString( '>Medium</span>', $html );
			$this->assertStringNotContainsString( 'urgent', $html );
		}
	}

	/**
	 * Fact rows omit empty optional values and keep their fixed order.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_rows_omit_empties_and_render_in_contract_order(): void {
		$full = $this->render_card(
			$this->wish(
				[
					'title'     => 'Ordered wish',
					'wishType'  => 'book',
					'price'     => ' $25 ',
					'priority'  => 'low',
					'wishedAt' => '2026-07-04',
				]
			)
		);
		$labels = [];
		foreach ( $this->xpath( $full )->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pk-wish-row ")]/dt' ) as $label ) {
			$labels[] = trim( $label->textContent );
		}
		$this->assertSame( [ 'Type', 'Price', 'Priority', 'Wished' ], $labels );
		$this->assertStringContainsString( '<span class="pk-wish-price">$25</span>', $full );
		$this->assertStringContainsString( 'datetime="2026-07-04"', $full );

		$minimal = $this->render_card( $this->wish( [ 'title' => 'Minimal wish' ] ) );
		$this->assertSame( [ 'Type', 'Priority' ], array_map( static fn( DOMNode $node ): string => trim( $node->textContent ), iterator_to_array( $this->xpath( $minimal )->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pk-wish-row ")]/dt' ) ) ) );
		$this->assertStringNotContainsString( '>Price</dt>', $minimal );
		$this->assertStringNotContainsString( '>Wished</dt>', $minimal );
	}

	/**
	 * Reason markup and its wrapper class appear as one decision.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_reason_section_and_modifier_are_absent_when_empty(): void {
		$empty = $this->render_card( $this->wish( [ 'title' => 'No reason', 'reason' => '   ' ] ) );
		$shown = $this->render_card( $this->wish( [ 'title' => 'Reasoned', 'reason' => 'For winter reading' ] ) );

		$this->assertStringNotContainsString( 'pk-wish--has-reason', $empty );
		$this->assertStringNotContainsString( 'pk-wish-reason', $empty );
		$this->assertStringContainsString( 'pk-wish--has-reason', $shown );
		$this->assertStringContainsString( '<span class="pk-wish-reason__label">Reason</span>', $shown );
		$this->assertStringContainsString( '<p class="p-content">For winter reading</p>', $shown );
	}

	/**
	 * The single's outbound action is valid, accessible and restricted to that surface.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_view_item_link_appears_only_on_own_view_for_a_valid_url(): void {
		$post_id = $this->wish( [ 'title' => 'Greenhouse', 'url' => 'https://shop.example/greenhouse', 'rel' => '  Sample   rel value  ' ] );
		$own     = $this->xpath( $this->render_card( $post_id ) );
		$link    = $own->query( '//p[contains(concat(" ", normalize-space(@class), " "), " pk-original ")]/a' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertSame( 'https://shop.example/greenhouse', $link->getAttribute( 'href' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( 'noopener noreferrer Sample rel value', $link->getAttribute( 'rel' ) );
		$this->assertSame( 'u-url u-wish-of', $link->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'opens in a new tab', $link->getAttribute( 'aria-label' ) );

		$this->assertSame( 0, $this->xpath( $this->render_card( $post_id, false ) )->query( '//p[contains(concat(" ", normalize-space(@class), " "), " pk-original ")]' )->length );
	}

	/**
	 * Invalid URLs never create links, and a title without a URL stays text.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_invalid_or_missing_urls_never_create_empty_or_unsafe_links(): void {
		$invalid = $this->wish( [ 'title' => 'Unsafe wish', 'url' => 'javascript:alert(1)' ] );
		foreach ( [ $this->render_card( $invalid ), $this->render_card( $invalid, false ) ] as $html ) {
			$this->assertSame( 0, $this->xpath( $html )->query( '//a' )->length );
			$this->assertStringNotContainsString( 'javascript:alert(1)', $html );
		}

		$plain = $this->render_card( $this->wish( [ 'title' => 'Plain wish' ] ), false );
		$this->assertSame( 0, $this->xpath( $plain )->query( '//h2/a' )->length );
		$this->assertStringContainsString( 'Plain wish', $plain );
	}

	/**
	 * Stream mode replaces detail rows with compact chips and one title link.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_stream_mode_prints_ordered_chips_and_drops_single_details(): void {
		$html = $this->render_stream(
			$this->wish(
				[
					'title'     => 'Golden greenhouse',
					'wishType'  => 'tech',
					'url'       => 'https://shop.example/golden-greenhouse',
					'price'     => '$900',
					'priority'  => 'high',
					'wishedAt' => '2026-07-04',
					'reason'    => 'Grow food all winter',
				]
			)
		);
		$xpath = $this->xpath( $html );
		$chips = [];
		foreach ( $xpath->query( '//span[contains(concat(" ", normalize-space(@class), " "), " pk-wish-chip ")]' ) as $chip ) {
			$chips[] = trim( $chip->textContent );
		}

		$this->assertSame( [ 'Tech', 'High priority', '$900' ], $chips );
		$this->assertSame( 1, $xpath->query( '//a[@href]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pk-wish-row ")]' )->length );
		$this->assertStringNotContainsString( 'Grow food all winter', $html );
		$this->assertStringNotContainsString( '2026-07-04', $html );
		$this->assertStringNotContainsString( 'View item', $html );
	}

	/**
	 * Featured media overrides the card image on a plain post.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_featured_image_overrides_the_card_image(): void {
		$post_id       = $this->wish( [ 'title' => 'Picture wish', 'image' => 'https://images.example/card.jpg', 'imageAlt' => 'Card image' ], [], 'note' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Featured flowers' );
		set_post_thumbnail( $post_id, $attachment_id );
		$html = $this->render_card( $post_id );

		$this->assertStringContainsString( esc_url( (string) wp_get_attachment_url( $attachment_id ) ), $html );
		$this->assertStringContainsString( 'alt="Featured flowers"', $html );
		$this->assertStringNotContainsString( 'https://images.example/card.jpg', $html );
	}

	/**
	 * Remote images receive a referrer policy while uploads and empty alt stay literal.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_picture_privacy_and_empty_alt_follow_the_resolved_source(): void {
		$remote = $this->render_card( $this->wish( [ 'title' => 'Remote', 'image' => 'https://images.example/remote.jpg', 'imageAlt' => '' ] ) );
		$this->assertStringContainsString( 'alt=""', $remote );
		$this->assertStringContainsString( 'referrerpolicy="no-referrer"', $remote );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local         = $this->render_card( $this->wish( [ 'title' => 'Local', 'image' => wp_get_attachment_url( $attachment_id ), 'imageAlt' => 'Canola' ] ) );
		$this->assertStringNotContainsString( 'referrerpolicy=', $local );
	}

	/**
	 * User text is escaped and wrapper modifiers match visible content.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_text_is_escaped_and_wrapper_classes_match_the_picture(): void {
		$html    = $this->render_card( $this->wish( [ 'title' => '<b>Greenhouse</b> & tools', 'image' => 'https://images.example/greenhouse.jpg' ] ), false );
		$article = $this->xpath( $html )->query( '/html/body/div[@id="root"]/article' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $article );
		$classes = preg_split( '/\s+/', trim( $article->getAttribute( 'class' ) ) );
		foreach ( [ 'pk-card', 'k-wish', 'h-cite', 'pk-wish', 'pk-wish--has-photo' ] as $class ) {
			$this->assertContains( $class, $classes );
		}
		$this->assertStringContainsString( '&lt;b&gt;Greenhouse&lt;/b&gt; &amp; tools', $html );
		$this->assertStringNotContainsString( '<b>Greenhouse</b>', $html );

		$without = $this->render_card( $this->wish( [ 'title' => 'No photo' ] ) );
		$this->assertStringNotContainsString( 'pk-wish--has-photo', $without );
	}

	/**
	 * An omitted type attribute uses the block's Item default.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_missing_type_attribute_prints_item(): void {
		$html = $this->render_card( $this->wish( [ 'title' => 'Default type' ] ) );

		$this->assertMatchesRegularExpression( '/<dt>Type<\/dt>\s*<dd>Item<\/dd>/', $html );
	}
}

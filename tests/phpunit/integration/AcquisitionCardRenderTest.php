<?php
/**
 * Acquisition card render coverage for issue 239.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * The acquisition card is detailed on single and feed surfaces, and lean on
 * Stream and archive surfaces.
 *
 * @group integration
 */
final class AcquisitionCardRenderTest extends WP_UnitTestCase {

	/**
	 * Register terms, meta and request defaults.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'blocked', 'No network in tests.' )
		);
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Restore permalinks.
	 */
	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A serialized acquisition-card comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/acquisition-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
	}

	/**
	 * A published acquisition.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post args.
	 */
	private function acquisition( array $attrs, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Field Recorder',
					'post_date'    => '2026-08-12 10:00:00',
					'post_content' => $this->card( $attrs ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'acquisition', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Render only the card on its single request.
	 *
	 * @param int $id Post ID.
	 */
	private function render_single_card( int $id ): string {
		$this->go_to( get_permalink( $id ) );

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * Render the Stream card for a post.
	 *
	 * @param int $id Post ID.
	 */
	private function render_stream( int $id ): string {
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return \PKIW\render_stream_card();
	}

	/**
	 * XPath over HTML.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Find whether a parsed mf2 tree has a property name.
	 *
	 * @param array<string, mixed> $item Parsed mf2 item.
	 * @param string               $key  Property key.
	 */
	private function mf2_has_property( array $item, string $key ): bool {
		if ( array_key_exists( $key, $item['properties'] ?? [] ) ) {
			return true;
		}
		foreach ( $item['children'] ?? [] as $child ) {
			if ( is_array( $child ) && $this->mf2_has_property( $child, $key ) ) {
				return true;
			}
		}
		foreach ( $item['properties'] ?? [] as $values ) {
			foreach ( (array) $values as $value ) {
				if ( is_array( $value ) && $this->mf2_has_property( $value, $key ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public function test_type_labels_render_inherited_and_the_default_purchase(): void {
		$inherited = $this->render_single_card( $this->acquisition( [ 'title' => 'Family Clock', 'acquisitionType' => 'inherited' ] ) );
		$default   = $this->render_single_card( $this->acquisition( [ 'title' => 'Default Clock' ] ) );

		$this->assertSame( 'Inherited', trim( $this->xpath( $inherited )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pk-kindlabel ")]' )->item( 0 )->textContent ) );
		$this->assertSame( 'Purchase', trim( $this->xpath( $default )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pk-kindlabel ")]' )->item( 0 )->textContent ) );
	}

	public function test_root_class_marks_the_card_as_the_acquisition_citation(): void {
		$html    = $this->render_single_card( $this->acquisition( [ 'title' => 'Field Recorder' ] ) );
		$article = $this->xpath( $html )->query( '/html/body/div[@id="root"]/article' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $article );
		$classes = preg_split( '/\s+/', trim( $article->getAttribute( 'class' ) ) );

		foreach ( [ 'pk-card', 'k-acquisition', 'h-cite', 'u-acquisition-of' ] as $class ) {
			$this->assertContains( $class, $classes );
		}
		$this->assertStringNotContainsString( 'u-acquired', $html );
	}

	public function test_single_microformats_contain_acquisition_of_and_no_acquired_property(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder' ] );
		$this->go_to( get_permalink( $id ) );
		$parsed = \Mf2\parse( apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ) );
		$entry  = null;
		foreach ( $parsed['items'] ?? [] as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				$entry = $item;
				break;
			}
		}

		$this->assertIsArray( $entry );
		$this->assertArrayHasKey( 'acquisition-of', $entry['properties'] );
		$this->assertContains( 'h-cite', $entry['properties']['acquisition-of'][0]['type'] );
		$this->assertSame( 'Field Recorder', $entry['properties']['acquisition-of'][0]['properties']['name'][0] );
		$this->assertFalse( $this->mf2_has_property( $entry, 'acquired' ) );
	}

	public function test_public_cost_prints_on_single_and_feed_content(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder', 'cost' => '$129.00', 'showCostPublicly' => true ] );

		$this->assertStringContainsString( '$129.00', $this->render_single_card( $id ) );
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed() );
		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $id ) {
				$this->assertStringContainsString( '$129.00', get_the_content_feed( 'rss2' ) );
				return;
			}
		}
		$this->fail( 'The acquisition never appeared in the feed loop.' );
	}

	public function test_stream_drops_cost_notes_and_where_url_but_keeps_type_and_where_text_on_page_requests(): void {
		$id = $this->acquisition(
			[
				'title'            => 'Field Recorder',
				'acquisitionType'  => 'purchase',
				'where'            => 'Corner Hardware',
				'whereUrl'         => 'https://shop.example.com/field-recorder-zq9',
				'notes'            => 'Records the morning birds Zq3',
				'cost'             => '$129.00',
				'showCostPublicly' => true,
			]
		);
		$this->go_to( home_url( '/' ) );
		$html = $this->render_stream( $id );

		foreach ( [ '$129.00', 'Records the morning birds Zq3', 'href="https://shop.example.com/field-recorder-zq9"', 'https://shop.example.com/field-recorder-zq9', 'u-url' ] as $missing ) {
			$this->assertStringNotContainsString( $missing, $html, $missing );
		}
		$this->assertStringContainsString( 'Purchase', $html );
		$this->assertStringContainsString( 'from Corner Hardware', $html );
	}

	public function test_stream_archive_and_search_drop_details_but_keep_title_and_badge(): void {
		$id = $this->acquisition(
			[
				'title'            => 'Field Recorder',
				'acquisitionType'  => 'purchase',
				'where'            => 'Family Attic Zq7',
				'whereUrl'         => 'https://shop.example.com/attic-zq9',
				'notes'            => 'Dusty note Zq8',
				'acquiredAt'       => '2026-08-02',
				'cost'             => '$129.00',
				'showCostPublicly' => true,
			]
		);

		foreach ( [ (string) get_term_link( 'acquisition', Taxonomy::TAXONOMY ), '/?s=Field+Recorder' ] as $url ) {
			$this->go_to( $url );
			$html = $this->render_stream( $id );
			foreach ( [ 'Purchase', 'Family Attic Zq7', 'August 2, 2026', '$129.00', 'Dusty note Zq8', 'attic-zq9' ] as $missing ) {
				$this->assertStringNotContainsString( $missing, $html, $url . ' ' . $missing );
			}
			$this->assertStringContainsString( 'Field Recorder', $html );
			$this->assertStringContainsString( 'pk-badge', $html );
		}
	}

	public function test_cost_is_suppressed_outside_stream_on_archive_and_search_but_not_feed_or_single(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder', 'cost' => '$129.00', 'showCostPublicly' => true ] );

		$this->go_to( (string) get_term_link( 'acquisition', Taxonomy::TAXONOMY ) );
		$this->assertStringNotContainsString( '$129.00', do_blocks( (string) get_post_field( 'post_content', $id ) ) );

		$this->go_to( '/?s=Field+Recorder' );
		$this->assertStringNotContainsString( '$129.00', do_blocks( (string) get_post_field( 'post_content', $id ) ) );

		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'acquisition', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_feed() );
		$this->assertStringContainsString( '$129.00', apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) ) );

		$this->assertStringContainsString( '$129.00', $this->render_single_card( $id ) );
	}

	public function test_rich_text_values_render_as_text_not_literal_markup(): void {
		$html = $this->render_single_card(
			$this->acquisition(
				[
					'title' => 'Brass <strong>Desk</strong> Lamp',
					'where' => '<em>Corner</em> Hardware',
				]
			)
		);

		$this->assertStringContainsString( 'Brass Desk Lamp', $html );
		$this->assertStringContainsString( 'from Corner Hardware', $html );
		$this->assertStringNotContainsString( '<strong>', $html );
		$this->assertStringNotContainsString( '&lt;strong', $html );
	}

	public function test_single_where_url_wraps_the_title_link(): void {
		$url  = 'https://shop.example.com/field-recorder';
		$html = $this->render_single_card( $this->acquisition( [ 'title' => 'Field Recorder', 'whereUrl' => $url ] ) );
		$link = $this->xpath( $html )->query( '//h2[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]/a' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertSame( 'u-url', $link->getAttribute( 'class' ) );
		$this->assertSame( $url, $link->getAttribute( 'href' ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
	}

	public function test_remote_photo_still_renders_with_stored_alt(): void {
		$html = $this->render_single_card(
			$this->acquisition(
				[
					'title'    => 'Missing Lantern',
					'photo'    => 'https://images.example.com/missing-lantern.jpg',
					'photoAlt' => 'A brass lantern on a shelf',
				]
			)
		);
		$img = $this->xpath( $html )->query( '//img[contains(concat(" ", normalize-space(@class), " "), " u-photo ")]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $img );
		$this->assertSame( 'https://images.example.com/missing-lantern.jpg', $img->getAttribute( 'src' ) );
		$this->assertSame( 'A brass lantern on a shelf', $img->getAttribute( 'alt' ) );
	}
}

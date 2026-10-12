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
		return '<!-- wp:post-kinds-indieweb/acquisition-card ' . serialize_block_attributes( $attrs ) . ' /-->';
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
					'post_content' => wp_slash( $this->card( $attrs ) ),
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

	public function test_cost_is_suppressed_on_the_blog_home_and_a_404(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder', 'cost' => '$129.00', 'showCostPublicly' => true ] );

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_home() );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$html            = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		$this->assertStringContainsString( 'Field Recorder', $html );
		$this->assertStringNotContainsString( '$129.00', $html );

		$this->go_to( home_url( '/no-such-page-zq9/' ) );
		$this->assertTrue( is_404() );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$html            = do_blocks( (string) get_post_field( 'post_content', $id ) );
		$this->assertStringContainsString( 'Field Recorder', $html );
		$this->assertStringNotContainsString( '$129.00', $html );
	}

	public function test_cost_is_suppressed_in_a_page_level_query_loop(): void {
		$id      = $this->acquisition( [ 'title' => 'Field Recorder', 'cost' => '$129.00', 'showCostPublicly' => true ] );
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Recent acquisitions',
				'post_content' => wp_slash(
					'<!-- wp:query {"queryId":0,"query":{"perPage":5,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","author":"","search":"","exclude":[],"sticky":"","inherit":false}} -->'
					. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-content /--><!-- /wp:post-template --></div>'
					. '<!-- /wp:query -->'
				),
			]
		);

		$this->go_to( get_permalink( $page_id ) );
		$this->assertTrue( is_page() );
		$this->assertNotSame( $id, get_queried_object_id() );
		$html = do_blocks( (string) get_post_field( 'post_content', $page_id ) );

		$this->assertStringContainsString( 'Field Recorder', $html, 'The query loop lists the acquisition card.' );
		$this->assertStringNotContainsString( '$129.00', $html );
	}

	public function test_rest_content_keeps_a_public_cost_after_a_listing_request(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder', 'cost' => '$129.00', 'showCostPublicly' => true ] );

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( is_home() );
		$GLOBALS['wp_rest_server'] = null;
		$data                      = (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();

		$this->assertStringContainsString( '$129.00', $data['content']['rendered'] );
	}

	public function test_rest_stream_card_render_hides_a_public_cost(): void {
		$id = $this->acquisition( [ 'title' => 'Field Recorder', 'cost' => '$129.00', 'showCostPublicly' => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/stream-card' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $id );
		$data = (array) rest_do_request( $request )->get_data();
		$html = (string) ( $data['rendered'] ?? wp_json_encode( $data ) );

		$this->assertStringContainsString( 'pk-card', $html, 'The Stream card rendered over REST.' );
		$this->assertStringNotContainsString( '$129.00', $html, 'A REST request opens the cost surface, so only the Stream guard hides it.' );
	}

	public function test_entity_encoded_markup_is_escaped_and_notes_are_plain_text(): void {
		$html = $this->render_single_card(
			$this->acquisition(
				[
					'title'    => '&lt;img src=x onerror=alert(1)&gt;',
					'where'    => '&lt;script&gt;alert(2)&lt;/script&gt;',
					'notes'    => 'One<br>Two <strong>bold</strong> &lt;i&gt;n&lt;/i&gt;',
					'cost'     => '<b>$5</b>',
					'showCostPublicly' => true,
					'photo'    => 'http://example.org/wp-content/uploads/a.jpg',
					'photoAlt' => 'a"><svg onload=alert(3)>',
				]
			)
		);

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<i>n', $html );
		$this->assertStringNotContainsString( '<b>$5', $html );
		$this->assertStringNotContainsString( '<svg onload', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html, 'Encoded markup in the title prints as escaped text.' );
		$this->assertStringContainsString( 'alt="a&quot;&gt;', $html, 'A double quote in photoAlt is escaped inside the attribute.' );
		$this->assertStringNotContainsString( '&lt;br', $html, 'A line break in notes prints as plain text, not escaped markup.' );
		$this->assertStringNotContainsString( '&lt;strong', $html, 'Tags in notes print as plain text, not escaped markup.' );
		$this->assertStringContainsString( 'One', $html );
		$this->assertStringContainsString( 'bold', $html );
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

	public function test_stream_cards_print_a_local_photo_and_never_a_remote_one(): void {
		$image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local    = $this->acquisition( [ 'title' => 'Local Lantern', 'photo' => (string) wp_get_attachment_url( $image_id ), 'photoAlt' => 'A brass lantern' ] );
		$remote   = $this->acquisition( [ 'title' => 'Remote Lantern', 'photo' => 'https://images.example.com/missing-lantern.jpg', 'photoAlt' => 'A brass lantern' ] );

		foreach ( [ home_url( '/' ), (string) get_term_link( 'acquisition', Taxonomy::TAXONOMY ) ] as $url ) {
			$this->go_to( $url );
			$local_html = $this->render_stream( $local );
			$this->assertStringContainsString( '<img', $local_html, $url );
			$this->assertStringContainsString( (string) wp_get_attachment_url( $image_id ), $local_html, $url );

			$remote_html = $this->render_stream( $remote );
			$this->assertStringNotContainsString( '<img', $remote_html, $url );
			$this->assertStringNotContainsString( 'images.example.com', $remote_html, $url );
			$this->assertStringContainsString( 'Remote Lantern', $remote_html, $url );
		}
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

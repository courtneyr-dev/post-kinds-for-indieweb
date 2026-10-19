<?php
/**
 * Jam Stream card coverage for issue 235.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Jam_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Jam Stream cards use a listing shape on the jam archive and a compact
 * Stream shape on every other request.
 *
 * @group integration
 */
final class JamStreamCardTest extends WP_UnitTestCase {

	/**
	 * Register terms, meta, URLs and no-network guards.
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
		wp_set_current_user( 0 );
	}

	/**
	 * Restore URL and user state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A serialized jam-card comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/jam-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A post assigned to one kind.
	 *
	 * @param array<string, mixed>|null $attrs Card attributes, or null for no card.
	 * @param array<string, mixed>      $args  Post arguments.
	 * @param string                    $kind  Kind slug.
	 */
	private function post( ?array $attrs = [], array $args = [], string $kind = 'jam' ): int {
		$content = null === $attrs ? (string) ( $args['post_content'] ?? '' ) : $this->card( $attrs );
		unset( $args['post_content'] );
		$id = wp_insert_post(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Hollow Light',
					'post_date'    => '2026-08-31 23:30:00',
					'post_content' => wp_slash( $content ),
				],
				$args
			),
			true
		);
		$this->assertIsInt( $id );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Render the current post through the Stream card callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_stream( int $id, array $attributes = [] ): string {
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return \PKIW\render_stream_card( $attributes );
	}

	/**
	 * XPath over an HTML fragment.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * The card's root article.
	 */
	private function article( string $html ): DOMElement {
		$article = $this->xpath( $html )->query( '/html/body/div[@id="root"]/article' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $article );

		return $article;
	}

	/**
	 * Count visible links. An ancestor with hidden makes a link non-rendered.
	 */
	private function rendered_link_count( string $html ): int {
		return $this->xpath( $html )->query( '//a[@href and not(ancestor::*[@hidden])]' )->length;
	}

	public function test_taxonomy_request_uses_the_listing_shape_and_only_listing_fields(): void {
		$id = $this->post(
			[
				'title'    => 'Hollow Light',
				'artist'   => 'Marlowe Kite',
				'album'    => 'Signal Bloom',
				'note'     => 'Distinctive midnight note',
				'cover'    => 'https://covers.example.net/hollow.png',
				'url'      => 'https://tracks.example.org/t/hollow',
				'jammedAt' => '2026-09-01T00:30',
			]
		);
		$this->go_to( (string) get_term_link( 'jam', Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_tax( 'kind', 'jam' ) );

		$html    = $this->render_stream( $id, [ 'headingLevel' => 3 ] );
		$xpath   = $this->xpath( $html );
		$article = $this->article( $html );
		$classes = preg_split( '/\s+/', trim( $article->getAttribute( 'class' ) ) );

		foreach ( [ 'pk-card', 'pk-card--stream', 'pk-card--jam', 'k-jam', 'h-entry', 'pk-card--jam-listing' ] as $class ) {
			$this->assertContains( $class, $classes );
		}
		$this->assertSame( 1, $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pk-media ")]/img[contains(concat(" ", normalize-space(@class), " "), " pk-cover ")]' )->length );
		$this->assertSame( '', $xpath->query( '//img[contains(concat(" ", normalize-space(@class), " "), " pk-cover ")]' )->item( 0 )->getAttribute( 'alt' ) );
		$this->assertSame( 1, $xpath->query( '//h3[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]' )->length );
		$link = $xpath->query( '//h3/a' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertSame( 'pkiw-jam-t-' . $id . ' pkiw-jam-a-' . $id, $link->getAttribute( 'aria-labelledby' ) );
		foreach ( preg_split( '/\s+/', $link->getAttribute( 'aria-labelledby' ) ) as $token ) {
			$this->assertSame( 1, $xpath->query( '//*[@id="' . $token . '"]' )->length, $token );
		}
		$this->assertSame( 1, $this->rendered_link_count( $html ) );
		foreach ( [ 'Signal Bloom', 'September 1, 2026', 'Distinctive midnight note', 'pk-kindlabel', '<iframe', 'pk-player' ] as $missing ) {
			$this->assertStringNotContainsString( $missing, $html, $missing );
		}
	}

	public function test_listing_without_artist_has_one_aria_token_and_untitled_has_no_p_name(): void {
		$artistless = $this->post( [ 'title' => 'The Quiet Orchards' ], [ 'post_title' => 'The Quiet Orchards' ] );
		$untitled   = $this->post( null, [ 'post_title' => '', 'post_content' => '<p>Jamming to <a class="u-jam-of" href="https://tracks.example.org/t/quiet">the track</a></p>' ] );
		$this->go_to( (string) get_term_link( 'jam', Taxonomy::TAXONOMY ) );

		$artistless_html = $this->render_stream( $artistless );
		$link            = $this->xpath( $artistless_html )->query( '//h2/a' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $link );
		$this->assertSame( 'pkiw-jam-t-' . $artistless, $link->getAttribute( 'aria-labelledby' ) );
		$this->assertSame( 0, $this->xpath( $artistless_html )->query( '//*[@id="pkiw-jam-a-' . $artistless . '"]' )->length );

		$untitled_link = $this->xpath( $this->render_stream( $untitled ) )->query( '//h2/a' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $untitled_link );
		$this->assertSame( 'u-url', $untitled_link->getAttribute( 'class' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function stream_requests(): array {
		return [
			'tag archive' => [ 'tag' ],
			'search'      => [ 'search' ],
			'single'      => [ 'single' ],
		];
	}

	/**
	 * @dataProvider stream_requests
	 */
	public function test_non_jam_archive_requests_use_the_stream_shape( string $request ): void {
		$id = $this->post(
			[
				'title'    => 'Hollow Light',
				'artist'   => 'Marlowe Kite',
				'album'    => 'Signal Bloom',
				'note'     => 'Distinctive stream note',
				'cover'    => 'https://covers.example.net/hollow.png',
				'url'      => 'https://tracks.example.org/t/hollow',
				'jammedAt' => '2026-09-01T00:30',
			]
		);
		if ( 'tag' === $request ) {
			wp_set_post_tags( $id, [ 'night-music' ] );
			$this->go_to( (string) get_tag_link( get_term_by( 'slug', 'night-music', 'post_tag' ) ) );
		} elseif ( 'search' === $request ) {
			$this->go_to( '/?s=Hollow+Light' );
		} else {
			$this->go_to( get_permalink( $id ) );
		}

		$html    = $this->render_stream( $id, [ 'headingLevel' => 3 ] );
		$xpath   = $this->xpath( $html );
		$article = $this->article( $html );

		$this->assertStringContainsString( 'pk-card--jam-stream', $article->getAttribute( 'class' ) );
		$this->assertSame( 1, $xpath->query( '//span[contains(concat(" ", normalize-space(@class), " "), " pk-kindlabel ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//h3[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]/a[contains(concat(" ", normalize-space(@class), " "), " p-name ")]' )->length );
		$time = $xpath->query( '//p[contains(concat(" ", normalize-space(@class), " "), " pk-stream-date ")]/time' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertSame( '2026-09-01', $time->getAttribute( 'datetime' ) );
		$this->assertStringNotContainsString( 'dt-published', $time->getAttribute( 'class' ) );
		$this->assertSame( 'September 1, 2026', trim( $time->textContent ) );
		$this->assertSame( 1, $this->rendered_link_count( $html ) );
		foreach ( [ 'Signal Bloom', 'Distinctive stream note', 'covers.example.net', '<iframe' ] as $missing ) {
			$this->assertStringNotContainsString( $missing, $html, $missing );
		}
	}

	public function test_block_renderer_preview_uses_the_listing_shape(): void {
		$id = $this->post( [ 'title' => 'Hollow Light', 'artist' => 'Marlowe Kite' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/stream-card' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $id );
		$request->set_param( 'pkiw_kind', 'jam' );
		$request->set_param( 'attributes', [ 'headingLevel' => 3 ] );
		$response                  = rest_get_server()->dispatch( $request );
		$GLOBALS['wp_rest_server'] = null;
		$html                      = (string) ( $response->get_data()['rendered'] ?? '' );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringContainsString( 'pk-card--jam-listing', $html );
		$this->assertStringNotContainsString( 'pk-card--jam-stream', $html );
	}

	public function test_hidden_citation_matches_card_micropub_and_quick_post_shapes(): void {
		$card = $this->post( [ 'title' => 'Hollow Light', 'artist' => 'Marlowe Kite', 'url' => 'https://tracks.example.org/t/hollow' ] );
		$this->go_to( get_permalink( $card ) );
		$card_html = $this->render_stream( $card );
		$card_cite = $this->xpath( $card_html )->query( '//span[contains(concat(" ", normalize-space(@class), " "), " pk-kind-cite ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $card_cite );
		$this->assertTrue( $card_cite->hasAttribute( 'hidden' ) );
		$this->assertStringContainsString( 'h-cite', $card_cite->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'u-jam-of', $card_cite->getAttribute( 'class' ) );
		$this->assertSame( 1, $this->xpath( $card_html )->query( '//span[contains(@class,"pk-kind-cite")]/data[contains(@class,"p-name") and @value="Hollow Light"]' )->length );
		$this->assertSame( 1, $this->xpath( $card_html )->query( '//span[contains(@class,"pk-kind-cite")]//span[contains(@class,"p-author") and contains(@class,"h-card")]/data[@value="Marlowe Kite"]' )->length );
		$this->assertSame( 1, $this->xpath( $card_html )->query( '//span[contains(@class,"pk-kind-cite")]/data[contains(@class,"u-url") and @value="https://tracks.example.org/t/hollow"]' )->length );
		$this->assertSame( 0, $this->xpath( $card_html )->query( '//span[contains(@class,"pk-kind-cite")]//a' )->length );

		$micropub = $this->post( null, [ 'post_title' => '', 'post_content' => '<p>Jamming to <a class="u-jam-of" href="https://tracks.example.org/t/1">track</a></p>' ] );
		$micro_html = $this->render_stream( $micropub );
		$this->assertSame( 1, $this->xpath( $micro_html )->query( '//span[contains(@class,"pk-kind-cite")]/data[contains(@class,"u-url")]' )->length );
		$this->assertSame( 0, $this->xpath( $micro_html )->query( '//span[contains(@class,"pk-kind-cite")]//*[@class="p-name"]' )->length );

		$quick = $this->post( null, [ 'post_title' => 'Track by Artist' ] );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_track', 'The Quiet Orchards' );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_artist', 'Marlowe Kite' );
		$quick_html = $this->render_stream( $quick );
		$this->assertSame( 1, $this->xpath( $quick_html )->query( '//span[contains(@class,"pk-kind-cite")]/data[contains(@class,"p-name") and @value="The Quiet Orchards"]' )->length );
		$this->assertSame( 1, $this->xpath( $quick_html )->query( '//span[contains(@class,"pk-kind-cite")]//data[@value="Marlowe Kite"]' )->length );
		$this->assertSame( 0, $this->xpath( $quick_html )->query( '//span[contains(@class,"pk-kind-cite")]/data[contains(@class,"u-url")]' )->length );
	}

	public function test_rich_text_is_plain_then_escaped(): void {
		$id = $this->post( [ 'title' => 'Tom & "Jerry" <b>x</b> O\'Neil', 'artist' => 'Marlowe Kite' ] );
		$this->go_to( get_permalink( $id ) );
		$html = $this->render_stream( $id );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( 'Tom & "Jerry"', $html );
		$this->assertSame( 'Tom & "Jerry" x O\'Neil', trim( $this->xpath( $html )->query( '//h2/a' )->item( 0 )->textContent ) );
	}

	public function test_protected_jam_never_exposes_card_facts_and_direct_callback_bails(): void {
		$id = $this->post(
			[
				'title'  => 'Locked Track Probe',
				'artist' => 'Locked Artist Probe',
				'album'  => 'Locked Album Probe',
				'note'   => 'Locked Note Probe',
				'cover'  => 'https://covers.example.net/locked-probe.png',
				'url'    => 'https://tracks.example.org/locked-probe',
			],
			[ 'post_title' => 'Protected Jam', 'post_password' => 'secret' ]
		);
		$this->go_to( get_permalink( $id ) );
		$html = $this->render_stream( $id );

		$this->assertStringContainsString( 'pk-card--protected', $html );
		foreach ( [ 'Locked Track Probe', 'Locked Artist Probe', 'Locked Album Probe', 'Locked Note Probe', 'locked-probe.png', 'tracks.example.org/locked-probe' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $html, $secret );
		}
		$post = get_post( $id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertNull( Jam_Archive::stream_card( null, $post, 3, [] ) );
	}

	public function test_callback_passes_through_posts_it_does_not_own_or_cannot_show(): void {
		$note = get_post( $this->post( null, [ 'post_content' => '<p>Earlier note.</p>' ], 'note' ) );
		$this->assertInstanceOf( WP_Post::class, $note );
		$this->assertNull( Jam_Archive::stream_card( null, $note, 3, [] ) );
		$this->assertSame( '<p>earlier</p>', Jam_Archive::stream_card( '<p>earlier</p>', $note, 3, [] ) );
		$this->assertSame( '<p>earlier</p>', Jam_Archive::stream_card( '<p>earlier</p>', 'not a post' ) );

		$draft = get_post( $this->post( [ 'title' => 'Draft Track' ], [ 'post_status' => 'draft' ] ) );
		$this->assertInstanceOf( WP_Post::class, $draft );
		$this->assertSame( '<p>earlier</p>', Jam_Archive::stream_card( '<p>earlier</p>', $draft, 3, [] ) );
	}

	public function test_heading_levels_clamp_to_two_through_four(): void {
		$id = $this->post( [ 'title' => 'Hollow Light' ] );
		$this->go_to( get_permalink( $id ) );

		$this->assertSame( 1, $this->xpath( $this->render_stream( $id, [ 'headingLevel' => 3 ] ) )->query( '//h3[contains(@class,"pk-title")]' )->length );
		$this->assertSame( 1, $this->xpath( $this->render_stream( $id, [ 'headingLevel' => 1 ] ) )->query( '//h2[contains(@class,"pk-title")]' )->length );
		$this->assertSame( 1, $this->xpath( $this->render_stream( $id, [ 'headingLevel' => 9 ] ) )->query( '//h4[contains(@class,"pk-title")]' )->length );
	}

	public function test_a_coverless_listing_card_has_no_media_region(): void {
		$id = $this->post( [ 'title' => 'No Cover Track', 'artist' => 'Marlowe Kite' ] );
		$this->go_to( (string) get_term_link( 'jam', Taxonomy::TAXONOMY ) );

		$html = $this->render_stream( $id );

		$this->assertStringContainsString( 'pk-card--jam-listing', $html );
		$this->assertSame( 0, $this->xpath( $html )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pk-media ")]' )->length );
		$this->assertSame( 0, $this->xpath( $html )->query( '//img' )->length );
	}
}

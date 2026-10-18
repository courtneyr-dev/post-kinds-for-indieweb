<?php
/**
 * Jam card dynamic renderer coverage for issue 235.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Jam_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * The detailed jam card keeps embeds on single and feed surfaces, suppresses
 * them during Stream rendering, and shares its cover-alt fallback with facts.
 *
 * @group integration
 */
final class JamCardRenderTest extends WP_UnitTestCase {

	/**
	 * Provider URL pattern registered for each test.
	 */
	private const PROVIDER_PATTERN = '#https://player\.example\.test/t/.*#i';

	/**
	 * Stub for pre_oembed_result.
	 *
	 * @var callable
	 */
	private $embed_filter;

	/**
	 * Register the real render environment with a fictional oEmbed provider.
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

		wp_oembed_add_provider( self::PROVIDER_PATTERN, 'https://player.example.test/oembed', true );
		$this->embed_filter = static function ( $result, string $url ) {
			if ( str_starts_with( $url, 'https://player.example.test/t/' ) ) {
				return '<iframe src="https://player.example.test/embed/1?autoplay=1"></iframe>';
			}

			return $result;
		};
		add_filter( 'pre_oembed_result', $this->embed_filter, 10, 2 );
	}

	/**
	 * Restore provider and permalink state.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_oembed_result', $this->embed_filter, 10 );
		wp_oembed_remove_provider( self::PROVIDER_PATTERN );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
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
	 * Insert a card post assigned to one kind.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post arguments.
	 * @param string               $kind  Kind slug.
	 */
	private function post( array $attrs, array $args = [], string $kind = 'jam' ): int {
		$id = wp_insert_post(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Hollow Light',
					'post_date'    => '2026-08-31 23:30:00',
					'post_content' => wp_slash( $this->card( $attrs ) ),
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
	 * Render only the card on its single request.
	 */
	private function render_single( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * Render a post through the Stream card callback.
	 */
	private function render_stream( int $id ): string {
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		return \PKIW\render_stream_card();
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
	 * Alt text of the first u-photo image.
	 */
	private function image_alt( string $html ): string {
		$image = $this->xpath( $html )->query( '//img[contains(concat(" ", normalize-space(@class), " "), " u-photo ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $image );

		return $image->getAttribute( 'alt' );
	}

	public function test_single_keeps_cached_embed_but_stream_render_skips_it_and_uses_cover(): void {
		$attrs = [
			'title'  => 'Hollow Light',
			'artist' => 'Marlowe Kite',
			'cover'  => 'https://covers.example.net/hollow.png',
			'url'    => 'https://player.example.test/t/1',
		];
		$jam   = $this->post( $attrs );
		$single = $this->render_single( $jam );

		$this->assertStringContainsString( '<iframe src="https://player.example.test/embed/1?autoplay=1"></iframe>', $single );
		$this->assertStringNotContainsString( '<img class="u-photo"', $single, 'An embed and cover never render together on a single.' );

		$note = $this->post( $attrs, [ 'post_title' => 'A note with a jam card' ], 'note' );
		$this->go_to( home_url( '/' ) );
		$stream = $this->render_stream( $note );

		$this->assertStringNotContainsString( '<iframe', $stream );
		$this->assertStringContainsString( '<img class="u-photo"', $stream );
		$this->assertStringContainsString( 'https://covers.example.net/hollow.png', $stream );
	}

	public function test_single_keeps_rel_microformats_and_calendar_date_contracts(): void {
		$id = $this->post(
			[
				'title'    => 'Hollow Light',
				'artist'   => 'Marlowe Kite',
				'album'    => 'Signal Bloom',
				'note'     => 'A fictional listening note.',
				'url'      => 'https://tracks.example.org/t/hollow',
				'rel'      => 'nofollow',
				'jammedAt' => '2026-09-01T00:30',
			]
		);
		$html  = $this->render_single( $id );
		$xpath = $this->xpath( $html );
		$root  = $xpath->query( '/html/body/div[@id="root"]/article' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $root );
		foreach ( [ 'pk-card', 'k-jam', 'h-cite' ] as $class ) {
			$this->assertContains( $class, preg_split( '/\s+/', trim( $root->getAttribute( 'class' ) ) ) );
		}
		$this->assertSame( 1, $xpath->query( '//h2[contains(@class,"pk-title") and contains(@class,"p-name")]' )->length );
		$this->assertSame( 1, $xpath->query( '//h2/a[contains(@class,"u-url") and contains(@class,"u-jam-of") and @rel="noopener noreferrer nofollow"]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[contains(@class,"pk-sub")]//span[contains(@class,"p-author") and contains(@class,"h-card")]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[contains(@class,"pk-note") and contains(@class,"p-content")]' )->length );
		$time = $xpath->query( '//time[contains(@class,"dt-published")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );
		$this->assertSame( '2026-09-01', $time->getAttribute( 'datetime' ) );
		$this->assertSame( 'September 1, 2026', trim( $time->textContent ) );

		$undated = $this->render_single( $this->post( [ 'title' => 'The Quiet Orchards', 'jammedAt' => '' ] ) );
		$this->assertSame( 0, $this->xpath( $undated )->query( '//time' )->length );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function alt_fallbacks(): array {
		return [
			'both'        => [ 'Hollow Light', 'Marlowe Kite', 'Hollow Light by Marlowe Kite' ],
			'title only'  => [ 'Hollow Light', '', 'Hollow Light' ],
			'artist only' => [ '', 'Marlowe Kite', 'Marlowe Kite' ],
			'neither'     => [ '', '', '' ],
		];
	}

	/**
	 * @dataProvider alt_fallbacks
	 */
	public function test_cover_alt_fallback_matches_the_facts_reader( string $title, string $artist, string $expected ): void {
		$id = $this->post(
			[
				'title'    => $title,
				'artist'   => $artist,
				'cover'    => 'https://covers.example.net/fallback.png',
				'coverAlt' => '',
			],
			[ 'post_title' => '' ]
		);
		$html  = $this->render_single( $id );
		$facts = Jam_Archive::facts( $id );

		$this->assertSame( $expected, $this->image_alt( $html ) );
		$this->assertSame( $facts['cover_alt_fallback'], $this->image_alt( $html ) );
	}

	public function test_stored_cover_alt_wins_over_the_fallback(): void {
		$id = $this->post(
			[
				'title'    => 'Hollow Light',
				'artist'   => 'Marlowe Kite',
				'cover'    => 'https://covers.example.net/stored.png',
				'coverAlt' => 'A pale fictional album sleeve',
			]
		);

		$this->assertSame( 'A pale fictional album sleeve', $this->image_alt( $this->render_single( $id ) ) );
		$this->assertSame( 'A pale fictional album sleeve', Jam_Archive::facts( $id )['cover_alt'] );
	}

	public function test_public_feed_keeps_embed_and_note_while_protected_content_keeps_no_card_facts(): void {
		$public = $this->post(
			[
				'title'  => 'Public Track Probe',
				'artist' => 'Public Artist Probe',
				'note'   => 'Public Note Probe',
				'url'    => 'https://player.example.test/t/public',
			],
			[ 'post_title' => 'Public Jam' ]
		);
		$protected = $this->post(
			[
				'title'  => 'Locked Track Probe',
				'artist' => 'Locked Artist Probe',
				'note'   => 'Locked Note Probe',
				'url'    => 'https://player.example.test/t/locked',
			],
			[ 'post_title' => 'Protected Jam', 'post_password' => 'secret' ]
		);

		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed() );
		$seen_public    = false;
		$seen_protected = false;
		while ( have_posts() ) {
			the_post();
			if ( $public === get_the_ID() ) {
				$feed = get_the_content_feed( 'rss2' );
				$this->assertStringContainsString( 'Public Note Probe', $feed );
				$this->assertStringContainsString( '<iframe src="https://player.example.test/embed/1?autoplay=1"></iframe>', $feed );
				$seen_public = true;
			}
			if ( $protected === get_the_ID() ) {
				$feed = get_the_content_feed( 'rss2' );
				foreach ( [ 'Locked Track Probe', 'Locked Artist Probe', 'Locked Note Probe' ] as $secret ) {
					$this->assertStringNotContainsString( $secret, $feed );
				}
				$seen_protected = true;
			}
		}
		$this->assertTrue( $seen_public, 'The public jam appeared in the feed query.' );
		$this->assertTrue( $seen_protected, 'The protected jam appeared in the feed query.' );

		$GLOBALS['post'] = get_post( $protected ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$filtered        = apply_filters( 'the_content', (string) get_post_field( 'post_content', $protected ) );
		foreach ( [ 'Locked Track Probe', 'Locked Artist Probe', 'Locked Note Probe' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $filtered );
		}
	}
	/**
	 * The single's markup is the develop renderer's, byte for byte: three public
	 * cards (all fields; a provider embed that beats the cover; a title-only
	 * card with a cover) hash to the value recorded from develop 8133c16.
	 */
	public function test_public_single_markup_is_byte_identical_to_the_shipped_renderer(): void {
		$sets = [
			[
				'title'    => 'Hollow Light',
				'artist'   => 'Marlowe Kite',
				'album'    => 'Signal Bloom',
				'cover'    => 'https://covers.example.net/a.png',
				'coverAlt' => 'Sleeve',
				'url'      => 'https://tracks.example.org/t/1',
				'note'     => 'A note',
				'jammedAt' => '2026-09-01T00:30',
				'rel'      => 'nofollow',
			],
			[
				'title'  => 'Hollow Light',
				'artist' => 'Marlowe Kite',
				'cover'  => 'https://covers.example.net/a.png',
				'url'    => 'https://player.example.test/t/1',
			],
			[
				'title' => 'Only Title',
				'cover' => 'https://covers.example.net/a.png',
			],
		];
		$html = '';
		foreach ( $sets as $attrs ) {
			$html .= $this->render_single( $this->post( $attrs ) );
		}

		$dump = getenv( 'PKIW_GOLDEN_DUMP' );
		if ( is_string( $dump ) && '' !== $dump ) {
			file_put_contents( $dump, sha1( $html ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$this->assertStringContainsString( '<iframe src="https://player.example.test/embed/1?autoplay=1"></iframe>', $html, 'The embed case rendered a provider player.' );
		$this->assertSame( '2f6b470b7f5b8c3af1ff667c278fb70268a91f69', sha1( $html ) );
	}
}

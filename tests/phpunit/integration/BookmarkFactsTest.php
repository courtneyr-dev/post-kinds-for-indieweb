<?php
/**
 * Bookmark facts reader (issue 258).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use const PKIW\CITE_HOST_META;

/**
 * Bookmark facts come from the first bookmark-card block, cite meta fallback,
 * kind pictures and the registered tag drawer. Names and URLs are invented.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class BookmarkFactsTest extends WP_UnitTestCase {

	/**
	 * Set up the facts reader.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		add_filter( 'pre_http_request', '__return_empty_array' );
		if ( null === Kind_Facts::reader( 'bookmark' ) ) {
			( new \PKIW\Bookmark_Archive() )->register();
		}
	}

	/**
	 * Restore current-user and HTTP globals.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_filter( 'pre_http_request', '__return_empty_array' );
		parent::tear_down();
	}

	/**
	 * A bookmark post, with either a bookmark-card block or a paragraph.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $card_attrs Bookmark-card attributes.
	 * @param array<string, mixed> $meta       Meta key => value, or values for several rows.
	 * @param array<string, mixed> $post       Extra post args.
	 * @return int
	 */
	private function bookmark( array $card_attrs = [], array $meta = [], array $post = [] ): int {
		$content = [] !== $card_attrs
			? '<!-- wp:post-kinds-indieweb/bookmark-card ' . wp_json_encode( $card_attrs, JSON_UNESCAPED_SLASHES ) . ' /-->'
			: '<!-- wp:paragraph --><p>Saved link.</p><!-- /wp:paragraph -->';
		$id      = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Saved link',
					'post_date'    => '2026-03-09 23:59:00',
					'post_content' => $content,
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'bookmark', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $values ) {
			foreach ( is_array( $values ) ? $values : [ $values ] as $value ) {
				add_post_meta( $id, $key, $value );
			}
		}

		return $id;
	}

	/**
	 * Bookmark facts through the public door.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function facts( int $post_id ): array {
		return \PKIW\kind_facts( $post_id );
	}

	/**
	 * The bookmark reader registers at runtime.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_the_reader_registers_through_kind_facts_register_not_readers(): void {
		$this->assertArrayNotHasKey( 'bookmark', Kind_Facts::READERS );
		$this->assertIsCallable( Kind_Facts::reader( 'bookmark' )['reader'] ?? null );
	}

	/**
	 * A complete editor card reports every bookmark fact.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_full_editor_card_reports_every_fact(): void {
		$id = $this->bookmark(
			[
				'title'        => 'A useful guide',
				'url'          => 'https://example.test/guide',
				'description'  => 'A note about the guide.',
				'image'        => 'https://example.org/shot.jpg',
				'imageAlt'     => 'A shot of the guide',
				'author'       => 'Casey Example',
				'bookmarkedAt' => '2026-07-04',
				'rel'          => 'nofollow',
			]
		);

		$facts = $this->facts( $id );

		$this->assertSame( [ 'url', 'host', 'title', 'cited_title', 'author', 'note', 'rel', 'date', 'picture', 'drawer' ], array_keys( $facts ) );
		$this->assertSame( 'https://example.test/guide', $facts['url'] );
		$this->assertSame( 'example.test', $facts['host'] );
		$this->assertSame( 'A useful guide', $facts['title'] );
		$this->assertSame( 'A useful guide', $facts['cited_title'] );
		$this->assertSame( 'Casey Example', $facts['author'] );
		$this->assertSame( 'A note about the guide.', $facts['note'] );
		$this->assertSame( 'nofollow', $facts['rel'] );
		$this->assertSame( \PKIW\card_calendar_date( '2026-07-04' ), $facts['date'] );
		$this->assertSame( 'cover', $facts['picture']['source'] );
		$this->assertSame( 'A shot of the guide', $facts['picture']['alt'] );
		$this->assertTrue( $facts['picture']['remote'] );
		$this->assertFalse( $facts['picture']['suppress_featured'] );
		$this->assertSame( [ 'key' => '', 'label' => 'Other' ], $facts['drawer'] );
	}

	/**
	 * A URL-only card uses the host as its title.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_card_with_only_a_url_has_the_host_for_a_title_and_no_cited_title(): void {
		$raw = 'https://WWW.Example.COM:8443/p?q=1';
		$id  = $this->bookmark( [ 'url' => $raw ], [], [ 'post_title' => '' ] );

		$facts = $this->facts( $id );

		$this->assertSame( \PKIW\web_url( $raw ), $facts['url'] );
		$this->assertSame( 'example.com', $facts['host'] );
		$this->assertSame( '', $facts['cited_title'] );
		$this->assertSame( 'example.com', $facts['title'] );
	}

	/**
	 * Quick Post cite meta supplies facts without a card.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_quick_post_meta_without_a_card(): void {
		$id = $this->bookmark(
			[],
			[
				Meta_Fields::PREFIX . 'bookmark_of'  => 'https://quick.example/path',
				Meta_Fields::PREFIX . 'cite_name'    => 'Quick cite',
				Meta_Fields::PREFIX . 'cite_author'  => 'Riley Example',
				Meta_Fields::PREFIX . 'cite_summary' => 'A quick saved summary.',
			]
		);
		update_post_meta( $id, CITE_HOST_META, 'stored.example' );

		$facts = $this->facts( $id );

		$this->assertSame( 'https://quick.example/path', $facts['url'] );
		$this->assertSame( 'stored.example', $facts['host'] );
		$this->assertSame( 'Quick cite', $facts['title'] );
		$this->assertSame( 'Quick cite', $facts['cited_title'] );
		$this->assertSame( 'Riley Example', $facts['author'] );
		$this->assertSame( 'A quick saved summary.', $facts['note'] );
	}

	/**
	 * Import cite meta supplies facts without a card.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_import_meta_without_a_card(): void {
		$id = $this->bookmark(
			[],
			[
				Meta_Fields::PREFIX . 'cite_name'   => 'Imported cite',
				Meta_Fields::PREFIX . 'cite_url'    => 'https://import.example/sample',
				Meta_Fields::PREFIX . 'cite_author' => 'Morgan Example',
				Meta_Fields::PREFIX . 'cite_photo'  => 'https://example.org/cover.jpg',
			],
			[ 'post_title' => 'Bookmarked: Sample' ]
		);

		$facts = $this->facts( $id );

		$this->assertSame( 'https://import.example/sample', $facts['url'] );
		$this->assertSame( 'Imported cite', $facts['title'] );
		$this->assertSame( 'Imported cite', $facts['cited_title'] );
		$this->assertSame( 'Morgan Example', $facts['author'] );
		$this->assertSame( 'cover', $facts['picture']['source'] );
		$this->assertTrue( $facts['picture']['remote'] );
		$this->assertSame( '', $facts['picture']['alt'] );
	}

	/**
	 * URL precedence is card, bookmark_of, then cite_url.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_url_precedence_is_card_then_bookmark_of_then_cite_url(): void {
		$card = $this->bookmark(
			[ 'url' => 'https://card.example/a' ],
			[
				Meta_Fields::PREFIX . 'bookmark_of' => 'https://bookmark.example/b',
				Meta_Fields::PREFIX . 'cite_url'    => 'https://cite.example/c',
			]
		);
		$bookmark_of = $this->bookmark(
			[ 'url' => 'javascript:alert(1)' ],
			[
				Meta_Fields::PREFIX . 'bookmark_of' => 'https://bookmark.example/b',
				Meta_Fields::PREFIX . 'cite_url'    => 'https://cite.example/c',
			]
		);
		$cite = $this->bookmark( [], [ Meta_Fields::PREFIX . 'cite_url' => 'https://cite.example/c' ] );

		$this->assertSame( 'https://card.example/a', $this->facts( $card )['url'] );
		$this->assertSame( 'https://bookmark.example/b', $this->facts( $bookmark_of )['url'] );
		$this->assertSame( 'https://cite.example/c', $this->facts( $cite )['url'] );
	}

	/**
	 * Card URLs compute their host from the card URL.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_card_url_uses_its_own_host_even_when_meta_holds_another(): void {
		$id = $this->bookmark(
			[ 'url' => 'https://card.example/a' ],
			[
				Meta_Fields::PREFIX . 'cite_url' => 'https://meta.example/b',
				CITE_HOST_META                   => 'meta.example',
			]
		);

		$facts = $this->facts( $id );
		$this->assertSame( 'https://card.example/a', $facts['url'] );
		$this->assertSame( 'card.example', $facts['host'] );
	}

	/**
	 * No URL gives empty URL and host.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_no_url_gives_empty_url_and_host(): void {
		$facts = $this->facts( $this->bookmark( [ 'title' => 'Title only' ] ) );

		$this->assertSame( '', $facts['url'] );
		$this->assertSame( '', $facts['host'] );
	}

	/**
	 * Legacy bookmark embed meta does not supply facts.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_post_with_only_embed_type_has_no_url(): void {
		$id = $this->bookmark(
			[],
			[
				Meta_Fields::PREFIX . 'bookmark_embed_type' => 'auto',
				Meta_Fields::PREFIX . 'bookmark_title'      => 'Ignored legacy title',
				Meta_Fields::PREFIX . 'bookmark_url'        => 'https://ignored.example/url',
				Meta_Fields::PREFIX . 'bookmark_author'     => 'Ignored legacy author',
				Meta_Fields::PREFIX . 'bookmark_image'      => 'https://ignored.example/image.jpg',
			]
		);

		$facts = $this->facts( $id );
		$json  = (string) wp_json_encode( $facts );

		$this->assertSame( '', $facts['url'] );
		$this->assertStringNotContainsString( 'Ignored legacy title', $json );
		$this->assertStringNotContainsString( 'https://ignored.example/url', $json );
		$this->assertStringNotContainsString( 'Ignored legacy author', $json );
		$this->assertStringNotContainsString( 'https://ignored.example/image.jpg', $json );
	}

	/**
	 * Titles fall through cite name, post title and untitled name.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_title_falls_back_through_cite_name_then_post_title_then_untitled(): void {
		$with_cite = $this->bookmark( [], [ Meta_Fields::PREFIX . 'cite_name' => 'Cite title', Meta_Fields::PREFIX . 'cite_url' => 'https://title.example/a' ] );
		$url       = 'https://title.example/b';
		$with_post = $this->bookmark( [ 'title' => $url, 'url' => $url ], [], [ 'post_title' => 'Post title fallback' ] );
		$untitled  = $this->bookmark( [ 'title' => $url, 'url' => $url ], [], [ 'post_title' => '' ] );

		$this->assertSame( 'Cite title', $this->facts( $with_cite )['title'] );
		$this->assertSame( '', $this->facts( $with_post )['cited_title'] );
		$this->assertSame( 'Post title fallback', $this->facts( $with_post )['title'] );
		$this->assertSame( 'title.example', $this->facts( $untitled )['title'] );
	}

	/**
	 * Card dates win, else post date is used.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_date_uses_the_card_date_else_the_post_date(): void {
		$date_only = $this->bookmark( [ 'bookmarkedAt' => '2026-07-04' ] );
		$offset    = $this->bookmark( [ 'bookmarkedAt' => '2026-07-04T23:30:00-08:00' ] );
		$empty     = $this->bookmark( [ 'bookmarkedAt' => '' ] );
		$absent    = $this->bookmark();
		$invalid   = $this->bookmark( [ 'bookmarkedAt' => '2026-02-30' ] );
		$fallback  = [ '2026-03-09', mysql2date( (string) get_option( 'date_format' ), '2026-03-09 23:59:00' ) ];

		$this->assertSame( \PKIW\card_calendar_date( '2026-07-04' ), $this->facts( $date_only )['date'] );
		$this->assertSame( '2026-07-04', $this->facts( $offset )['date'][0] );
		$this->assertSame( $fallback, $this->facts( $empty )['date'] );
		$this->assertSame( $fallback, $this->facts( $absent )['date'] );
		$this->assertSame( $fallback, $this->facts( $invalid )['date'] );
	}

	/**
	 * Picture order is featured image, card image, cite photo, none.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_picture_order_is_featured_then_card_image_then_cite_photo(): void {
		$featured = $this->bookmark( [ 'image' => 'https://example.org/card.jpg', 'imageAlt' => 'Card alt' ] );
		$image_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $image_id, '_wp_attachment_image_alt', 'Canola field' );
		set_post_thumbnail( $featured, $image_id );

		$card      = $this->bookmark( [ 'image' => 'https://example.org/card.jpg', 'imageAlt' => 'A  B' ] );
		$card_no_alt = $this->bookmark( [ 'image' => 'https://example.org/no-alt.jpg' ] );
		$cite      = $this->bookmark( [], [ Meta_Fields::PREFIX . 'cite_photo' => 'https://example.org/cite.jpg' ] );
		$none      = $this->bookmark();
		$fallback  = $this->bookmark( [ 'image' => 'javascript:alert(1)' ], [ Meta_Fields::PREFIX . 'cite_photo' => 'https://example.org/cite.jpg' ] );

		$featured_picture = $this->facts( $featured )['picture'];
		$this->assertSame( 'featured', $featured_picture['source'] );
		$this->assertTrue( $featured_picture['suppress_featured'] );
		$this->assertSame( $image_id, $featured_picture['attachment_id'] );

		$this->assertSame( 'cover', $this->facts( $card )['picture']['source'] );
		$this->assertSame( 'A  B', $this->facts( $card )['picture']['alt'] );
		$this->assertSame( '', $this->facts( $card_no_alt )['picture']['alt'] );
		$this->assertSame( 'cover', $this->facts( $cite )['picture']['source'] );
		$this->assertSame( '', $this->facts( $cite )['picture']['alt'] );
		$this->assertSame( '', $this->facts( $none )['picture']['source'] );
		$this->assertSame( 'cover', $this->facts( $fallback )['picture']['source'] );
		$this->assertSame( 'https://example.org/cite.jpg', $this->facts( $fallback )['picture']['url'] );
	}

	/**
	 * Rel tokens collapse whitespace runs.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_rel_tokens_are_collapsed(): void {
		$with_rel = $this->bookmark( [ 'rel' => '  nofollow   ugc ' ] );
		$none     = $this->bookmark();

		$this->assertSame( 'nofollow ugc', $this->facts( $with_rel )['rel'] );
		$this->assertSame( '', $this->facts( $none )['rel'] );
	}

	/**
	 * Drawer fact is the first tag A to Z and label.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_the_drawer_is_the_first_tag_a_to_z_with_its_label(): void {
		$tag = wp_insert_term( 'Alpha', 'post_tag', [ 'slug' => 'alpha' ] );
		$this->assertIsArray( $tag );
		$id = $this->bookmark();
		wp_set_object_terms( $id, [ 'zeta', 'Alpha' ], 'post_tag' );

		$this->assertSame( [ 'key' => (string) $tag['term_id'], 'label' => 'Alpha' ], $this->facts( $id )['drawer'] );
		$this->assertSame( [ 'key' => '', 'label' => 'Other' ], $this->facts( $this->bookmark() )['drawer'] );
	}

	/**
	 * Protected and unreadable posts return no facts.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_protected_post_has_no_facts(): void {
		$protected = $this->bookmark( [ 'url' => 'https://example.test/secret' ], [], [ 'post_password' => 'secret' ] );
		$draft     = $this->bookmark( [ 'url' => 'https://example.test/draft' ], [], [ 'post_status' => 'draft' ] );
		$private   = $this->bookmark( [ 'url' => 'https://example.test/private' ], [], [ 'post_status' => 'private' ] );

		wp_set_current_user( 0 );
		$this->assertSame( [], $this->facts( $protected ) );
		$this->assertSame( [], $this->facts( $draft ) );
		$this->assertSame( [], $this->facts( $private ) );
	}

	/**
	 * Bookmark facts never include location keys.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_facts_never_include_location_keys(): void {
		$facts = $this->facts( $this->bookmark( [ 'url' => 'https://example.test/place' ] ) );

		foreach ( [ 'latitude', 'longitude', 'location', 'coordinates', 'map', 'street' ] as $key ) {
			$this->assertNotContains( $key, array_keys( $facts ) );
		}
	}
}

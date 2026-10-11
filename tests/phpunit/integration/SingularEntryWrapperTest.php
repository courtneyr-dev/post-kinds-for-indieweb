<?php
/**
 * Entry-attachment coverage for singular permalinks.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies a kind post's canonical property attaches to an h-entry at the
 * permalink — the URL webmention receivers actually fetch.
 *
 * MicroformatsRenderTest wraps do_blocks() output in an h-entry it injects
 * itself, so it can only prove property/root co-location on the card. These
 * tests render the real block template for a singular request (Twenty
 * Twenty-Five's single template does not call post_class(), so no theme
 * wrapper exists) and parse what a consumer would see.
 *
 * @group integration
 */
final class SingularEntryWrapperTest extends WP_UnitTestCase {

	/**
	 * Create a published like post carrying only a like card.
	 */
	private function make_like_post(): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Entry wrapper probe',
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card {"title":"Target","url":"https://example.com/target"} /-->',
			]
		);

		$term_result = wp_set_object_terms( $post_id, 'like', 'kind' );
		$this->assertNotWPError( $term_result );

		return $post_id;
	}

	/**
	 * Recursively search parsed mf2 items for an h-entry carrying a property.
	 *
	 * @param array<int, array<string, mixed>> $items    Parsed mf2 items.
	 * @param string                           $property Canonical property name.
	 * @return array<string, mixed>|null The matching h-entry, or null.
	 */
	private function find_entry_with_property( array $items, string $property ): ?array {
		foreach ( $items as $item ) {
			$types = $item['type'] ?? [];
			if ( in_array( 'h-entry', $types, true ) && isset( $item['properties'][ $property ] ) ) {
				return $item;
			}
			if ( ! empty( $item['children'] ) ) {
				$found = $this->find_entry_with_property( $item['children'], $property );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Render the current request's block template exactly as template-canvas does.
	 */
	private function render_block_template(): string {
		$template = resolve_block_template(
			'single',
			[ 'single-post.php', 'single.php', 'singular.php', 'index.php' ],
			''
		);
		$this->assertNotNull( $template, 'No block template resolved for the singular request.' );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * The full Twenty Twenty-Five single template attaches like-of to an h-entry.
	 */
	public function test_singular_template_attaches_property_to_h_entry(): void {
		switch_theme( 'twentytwentyfive' );
		$post_id = $this->make_like_post();

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'Request did not resolve as singular.' );

		$html  = $this->render_block_template();
		$entry = $this->find_entry_with_property( \Mf2\parse( $html )['items'] ?? [], 'like-of' );

		$this->assertNotNull(
			$entry,
			'like-of did not attach to any h-entry in the rendered singular template.'
		);
		$this->assertPropertyContainsTarget(
			$entry['properties']['like-of'],
			'https://example.com/target'
		);
	}

	/**
	 * The content filter alone produces a complete h-entry when no theme wrapper exists.
	 */
	public function test_content_filter_wraps_orphan_singular_content(): void {
		$post_id = $this->make_like_post();

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$parsed  = \Mf2\parse( $content, get_permalink( $post_id ) );
		$entry   = $this->find_entry_with_property( $parsed['items'] ?? [], 'like-of' );

		$this->assertNotNull( $entry, 'Filtered content did not carry an h-entry with like-of.' );
		$this->assertContains(
			get_permalink( $post_id ),
			$entry['properties']['url'] ?? [],
			'The injected h-entry does not declare the permalink as u-url.'
		);
	}

	/**
	 * An h-entry gets no photo from the featured image: post type discovery
	 * reads a photo property on an h-entry as "this is a photo post".
	 */
	public function test_h_entry_kind_gets_no_photo_from_the_featured_image(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Watched something',
				'post_content' => '<!-- wp:paragraph --><p>Good.</p><!-- /wp:paragraph -->',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'watch', 'kind' ) );
		set_post_thumbnail( $post_id, self::factory()->attachment->create_object( 'poster.png', 0, [ 'post_mime_type' => 'image/png' ] ) );

		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$parsed  = \Mf2\parse( $content, get_permalink( $post_id ) );
		$entry   = $this->find_entry_with_property( $parsed['items'] ?? [], 'url' );

		$this->assertNotNull( $entry, 'Filtered content did not carry an h-entry.' );
		$this->assertArrayNotHasKey( 'photo', $entry['properties'] );
	}

	/**
	 * A theme that wraps the post via post_class() must not get a second h-entry.
	 */
	public function test_no_double_wrap_when_theme_applies_post_class(): void {
		$post_id = $this->make_like_post();

		$this->go_to( get_permalink( $post_id ) );

		// A post_class()-based wrapper renders before its inner content, so the
		// filter fires first — exactly what core/post-template does per item.
		get_post_class( '', $post_id );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString(
			'pkiw-singular-entry',
			$content,
			'Content was wrapped even though the theme already applied post_class().'
		);
	}

	/**
	 * Kinds without a kind_formats entry still get the default h-entry root —
	 * their cards emit properties too (the favorite card's u-favorite-of made
	 * this gap visible in the 1.5.0 pre-release gate).
	 */
	public function test_formatless_kind_content_is_wrapped(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Formatless kind probe',
				'post_content' => '<!-- wp:post-kinds-indieweb/favorite-card {"title":"Target","url":"https://example.com/fav"} /-->',
			]
		);
		wp_set_object_terms( $post_id, 'favorite', 'kind' );

		$this->go_to( get_permalink( $post_id ) );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$entry   = $this->find_entry_with_property( \Mf2\parse( $content, get_permalink( $post_id ) )['items'] ?? [], 'favorite-of' );

		$this->assertNotNull( $entry, 'favorite-of did not attach to an h-entry for a kind with no format entry.' );
	}

	/**
	 * Posts without a kind keep their content untouched.
	 */
	public function test_kindless_post_content_is_untouched(): void {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Plain post.</p><!-- /wp:paragraph -->',
			]
		);

		$this->go_to( get_permalink( $post_id ) );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'pkiw-singular-entry', $content );
	}

	/**
	 * Non-singular contexts are never wrapped.
	 */
	public function test_archive_context_is_never_wrapped(): void {
		$post_id = $this->make_like_post();

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( is_singular() );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'pkiw-singular-entry', $content );
	}

	/**
	 * Kinds whose readers a test registered, to unregister afterwards.
	 *
	 * @var string[]
	 */
	private array $registered_readers = [];

	public function tear_down(): void {
		foreach ( $this->registered_readers as $kind ) {
			$registered = new ReflectionProperty( \PKIW\Kind_Facts::class, 'registered' );
			$registered->setAccessible( true );
			$readers = $registered->getValue();
			unset( $readers[ $kind ] );
			$registered->setValue( null, $readers );
		}
		$this->registered_readers = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published favorite or repost post.
	 *
	 * @param string               $kind    Kind slug.
	 * @param string               $content Post content.
	 * @param array<string, mixed> $meta    Post meta.
	 * @param array<string, mixed> $args    Extra post arguments.
	 * @return int Post ID.
	 */
	private function response_post( string $kind, string $content, array $meta = [], array $args = [] ): int {
		$author_id = self::factory()->user->create( [ 'display_name' => 'Site Author' ] );
		$post_id   = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Response probe',
					'post_author'  => $author_id,
					'post_content' => $content,
				],
				$args
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, $kind, 'kind' ) );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return (int) $post_id;
	}

	/**
	 * The filtered singular content for a post, and the entry parsed from it.
	 *
	 * @param int $post_id Post ID.
	 * @return array{0: string, 1: array<string, mixed>|null} Content HTML and the h-entry.
	 */
	private function single_content_and_entry( int $post_id ): array {
		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );
		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$items   = \Mf2\parse( $content, get_permalink( $post_id ) )['items'] ?? [];
		$entry   = null;
		foreach ( $items as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				$entry = $item;
				break;
			}
		}

		return [ $content, $entry ];
	}

	/**
	 * Names of the h-cards in an item's author property.
	 *
	 * @param array<string, mixed> $item Parsed item.
	 * @return string[]
	 */
	private function author_names( array $item ): array {
		$names = [];
		foreach ( $item['properties']['author'] ?? [] as $author ) {
			$names[] = is_array( $author ) ? (string) ( $author['properties']['name'][0] ?? '' ) : (string) $author;
		}

		return $names;
	}

	/**
	 * Count elements with a class token inside a [hidden] ancestor-or-self.
	 *
	 * @param string $html  HTML fragment.
	 * @param string $token Class token.
	 * @return int
	 */
	private function hidden_token_count( string $html, string $token ): int {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $doc );

		return $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")][ancestor-or-self::*[@hidden]]' )->length;
	}

	/**
	 * The values of a parsed property as URLs, whether plain or embedded.
	 *
	 * @param array<string, mixed> $entry    h-entry.
	 * @param string               $property Property name.
	 * @return string[]
	 */
	private function property_urls( array $entry, string $property ): array {
		$urls = [];
		foreach ( $entry['properties'][ $property ] ?? [] as $value ) {
			if ( is_string( $value ) ) {
				$urls[] = $value;
				continue;
			}
			if ( is_array( $value ) ) {
				foreach ( $value['properties']['url'] ?? [] as $url ) {
					$urls[] = (string) $url;
				}
			}
		}

		return $urls;
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function cite_card_posts(): array {
		return [
			'favorite (F01 shape)' => [ 'favorite', 'favorite-of', 'https://example.com/fine-page' ],
			'repost (A01 shape)'   => [ 'repost', 'repost-of', 'https://example.com/shared-essay' ],
		];
	}

	/**
	 * A favorite or repost card prints the cited author inside its h-cite.
	 * That p-author belongs to the citation, so the entry still names the
	 * site author, and the h-cite keeps the cited one.
	 *
	 * @dataProvider cite_card_posts
	 *
	 * @param string $kind     Kind slug.
	 * @param string $property php-mf2 property.
	 * @param string $url      Cited URL.
	 */
	public function test_a_cite_card_post_is_authored_by_the_site_author_and_the_cite_keeps_its_own( string $kind, string $property, string $url ): void {
		$post_id = $this->response_post(
			$kind,
			'<!-- wp:post-kinds-indieweb/' . $kind . '-card ' . wp_json_encode( [ 'title' => 'A cited page', 'url' => $url, 'author' => 'Cited Author' ], JSON_UNESCAPED_SLASHES ) . ' /-->'
		);

		[ , $entry ] = $this->single_content_and_entry( $post_id );

		$this->assertIsArray( $entry );
		$this->assertSame( [ 'Site Author' ], $this->author_names( $entry ), 'The entry author is the site author, once.' );
		$this->assertCount( 1, $entry['properties'][ $property ] ?? [], 'One citation.' );
		$this->assertSame( [ $url ], $this->property_urls( $entry, $property ) );
		$this->assertSame( [ 'Cited Author' ], $this->author_names( $entry['properties'][ $property ][0] ), 'The cited author stays on the h-cite.' );
	}

	/**
	 * A card-less favorite (the F09 shape) keeps its target in meta. The
	 * permalink is what webmention receivers fetch, so it carries favorite-of.
	 */
	public function test_a_meta_only_favorite_carries_one_hidden_favorite_of(): void {
		$url     = 'https://example.com/fine-page?x=1';
		$post_id = $this->response_post(
			'favorite',
			'<!-- wp:paragraph --><p>Loved this one.</p><!-- /wp:paragraph -->',
			[ '_pkiw_favorite_url' => $url ]
		);

		[ $content, $entry ] = $this->single_content_and_entry( $post_id );

		$this->assertIsArray( $entry );
		$this->assertSame( [ $url ], $this->property_urls( $entry, 'favorite-of' ) );
		$this->assertSame( 1, $this->hidden_token_count( $content, 'u-favorite-of' ) );
	}

	public function test_a_registered_favorite_reader_supplies_the_hidden_favorite_of(): void {
		\PKIW\Kind_Facts::register(
			'favorite',
			static fn( int $post_id ): array => [
				'url'         => 'https://reader.example.org/fine-page',
				'host'        => 'reader.example.org',
				'cited_title' => 'From the reader',
			]
		);
		$this->registered_readers[] = 'favorite';
		$post_id                    = $this->response_post(
			'favorite',
			'<!-- wp:paragraph --><p>Loved this one.</p><!-- /wp:paragraph -->',
			[ '_pkiw_favorite_url' => 'https://stored.example.org/other' ]
		);

		[ $content, $entry ] = $this->single_content_and_entry( $post_id );

		$this->assertSame( [ 'https://reader.example.org/fine-page' ], $this->property_urls( $entry, 'favorite-of' ), 'The reader decides, not the raw meta.' );
		$this->assertStringNotContainsString( 'stored.example.org', $content );
	}

	public function test_a_favorite_card_with_a_url_gets_no_hidden_duplicate(): void {
		$url     = 'https://example.com/fine-page';
		$post_id = $this->response_post(
			'favorite',
			'<!-- wp:post-kinds-indieweb/favorite-card ' . wp_json_encode( [ 'title' => 'A fine page', 'url' => $url ], JSON_UNESCAPED_SLASHES ) . ' /-->',
			[ '_pkiw_favorite_url' => $url ]
		);

		[ $content, $entry ] = $this->single_content_and_entry( $post_id );

		$this->assertSame( [ $url ], $this->property_urls( $entry, 'favorite-of' ), 'Exactly one favorite-of.' );
		$this->assertSame( 0, $this->hidden_token_count( $content, 'u-favorite-of' ), 'The card already carries it.' );
	}

	public function test_a_favorite_never_names_its_own_permalink_as_the_target(): void {
		$post_id = $this->response_post( 'favorite', '<!-- wp:paragraph --><p>Self link.</p><!-- /wp:paragraph -->' );
		update_post_meta( $post_id, '_pkiw_favorite_url', (string) get_permalink( $post_id ) );

		[ $content, $entry ] = $this->single_content_and_entry( $post_id );

		$this->assertArrayNotHasKey( 'favorite-of', $entry['properties'] ?? [] );
		$this->assertStringNotContainsString( 'u-favorite-of', $content );
	}

	public function test_a_favorite_with_no_target_or_an_unsafe_one_has_no_favorite_of(): void {
		$none = $this->response_post( 'favorite', '<!-- wp:paragraph --><p>No target yet.</p><!-- /wp:paragraph -->' );
		[ $content ] = $this->single_content_and_entry( $none );
		$this->assertStringNotContainsString( 'u-favorite-of', $content );

		$unsafe = $this->response_post( 'favorite', '<!-- wp:paragraph --><p>Bad target.</p><!-- /wp:paragraph -->' );
		update_post_meta( $unsafe, '_pkiw_favorite_url', 'javascript:alert(1)' );
		[ $content ] = $this->single_content_and_entry( $unsafe );
		$this->assertStringNotContainsString( 'u-favorite-of', $content );
		$this->assertStringNotContainsString( 'javascript:', $content );
	}

	public function test_a_protected_favorite_prints_no_hidden_data(): void {
		$url     = 'https://example.com/private-page';
		$post_id = $this->response_post(
			'favorite',
			'<!-- wp:paragraph --><p>Protected favorite.</p><!-- /wp:paragraph -->',
			[ '_pkiw_favorite_url' => $url ],
			[ 'post_password' => 'secret' ]
		);

		[ $content, $entry ] = $this->single_content_and_entry( $post_id );

		$this->assertStringNotContainsString( $url, $content );
		$this->assertStringNotContainsString( 'u-favorite-of', $content );
		$this->assertArrayNotHasKey( 'favorite-of', $entry['properties'] ?? [] );
	}

	public function test_the_atproto_projection_of_a_favorite_has_no_hidden_data(): void {
		$post_id = $this->response_post(
			'favorite',
			'<!-- wp:paragraph --><p>Projection body.</p><!-- /wp:paragraph -->',
			[ '_pkiw_favorite_url' => 'https://example.org/atproto' ]
		);
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['wp_query']->set( 'atproto', '' );

		$content = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'u-favorite-of', $content );
		$this->assertStringNotContainsString( 'pkiw-singular-entry', $content );
	}

	/**
	 * Assert a parsed property contains a target URL, as a plain value or
	 * inside an embedded microformat (a u- property on an h-cite parses to
	 * an embedded object whose url property carries the target).
	 *
	 * @param array<int, mixed> $values     Parsed property values.
	 * @param string            $target_url Expected URL.
	 */
	private function assertPropertyContainsTarget( array $values, string $target_url ): void {
		foreach ( $values as $value ) {
			if ( $target_url === $value ) {
				$this->addToAssertionCount( 1 );
				return;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( $target_url === ( $value['value'] ?? null ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}

			foreach ( $value['properties']['url'] ?? [] as $url ) {
				if ( $target_url === $url ) {
					$this->addToAssertionCount( 1 );
					return;
				}
			}
		}

		$this->fail( 'No parsed value carries ' . $target_url );
	}
}

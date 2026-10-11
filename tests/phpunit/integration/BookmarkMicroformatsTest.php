<?php
/**
 * Bookmark single microformats (issue 258).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Bookmark_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Bookmark singles expose one bookmark-of target without leaking private facts.
 *
 * @group integration
 */
final class BookmarkMicroformatsTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private int $original_user_id = 0;

	private int $author_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_user_id    = get_current_user_id();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		if ( null === \PKIW\Kind_Facts::reader( 'bookmark' ) ) {
			( new Bookmark_Archive() )->register();
		}
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Site Author' ] );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( $this->original_user_id );
		if ( isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query']->set( 'atproto', null );
		}
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public function test_meta_only_bookmark_prints_one_hidden_bookmark_of(): void {
		$url     = 'https://example.com/saved';
		$post_id = $this->bookmark( $this->paragraph( 'Saved link.' ), [ Meta_Fields::PREFIX . 'bookmark_of' => $url ] );
		$html    = $this->render_single( $post_id );
		$entry   = $this->entry_for_post( $html, $post_id );

		$this->assertSame( [ $url ], $this->bookmark_urls( $entry ), 'A meta-only bookmark has one bookmark-of URL.' );
		$this->assertSame( 1, $this->hidden_class_token_count( $html, 'u-bookmark-of' ), 'The meta-only bookmark-of is hidden.' );
	}

	public function test_import_cite_url_meta_prints_one_hidden_bookmark_of(): void {
		$url     = 'https://example.org/imported';
		$post_id = $this->bookmark( $this->paragraph( 'Imported link.' ), [ Meta_Fields::PREFIX . 'cite_url' => $url ] );
		$entry   = $this->entry_for_post( $this->render_single( $post_id ), $post_id );

		$this->assertSame( [ $url ], $this->bookmark_urls( $entry ), 'Import cite_url meta maps to one bookmark-of URL.' );
	}

	public function test_card_only_bookmark_has_one_bookmark_of_without_hidden_duplicate(): void {
		$url     = 'https://example.com/card';
		$post_id = $this->bookmark( $this->bookmark_card( 'Saved page', $url, '' ) );
		$html    = $this->render_single( $post_id );
		$entry   = $this->entry_for_post( $html, $post_id );

		$this->assertSame( [ $url ], $this->bookmark_urls( $entry ), 'A card-only bookmark has one bookmark-of URL.' );
		$this->assertSame( 0, $this->hidden_class_token_count( $html, 'u-bookmark-of' ), 'A card-only bookmark does not get hidden duplicate bookmark-of data.' );
	}

	public function test_card_and_same_meta_url_still_have_one_bookmark_of(): void {
		$url     = 'https://example.org/same';
		$post_id = $this->bookmark( $this->bookmark_card( 'Same saved page', $url, '' ), [ Meta_Fields::PREFIX . 'bookmark_of' => $url ] );
		$entry   = $this->entry_for_post( $this->render_single( $post_id ), $post_id );

		$this->assertSame( [ $url ], $this->bookmark_urls( $entry ), 'A bookmark-card and same meta URL produce one bookmark-of URL.' );
	}

	public function test_bookmark_without_url_has_no_bookmark_of_marker(): void {
		$post_id = $this->bookmark( $this->paragraph( 'No target yet.' ) );
		$html    = $this->render_single( $post_id );
		$entry   = $this->entry_for_post( $html, $post_id );

		$this->assertArrayNotHasKey( 'bookmark-of', $entry['properties'] ?? [], 'No URL means no bookmark-of property.' );
		$this->assertStringNotContainsString( 'u-bookmark-of', $html, 'No URL means no bookmark-of class in the output.' );
	}

	public function test_unsafe_meta_url_has_no_bookmark_of_marker(): void {
		$post_id = $this->bookmark( $this->paragraph( 'Bad target.' ) );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'bookmark_of', 'javascript:alert(1)' );
		$html  = $this->render_single( $post_id );
		$entry = $this->entry_for_post( $html, $post_id );

		$this->assertArrayNotHasKey( 'bookmark-of', $entry['properties'] ?? [], 'Unsafe bookmark meta does not produce a bookmark-of property.' );
		$this->assertStringNotContainsString( 'u-bookmark-of', $html, 'Unsafe bookmark meta does not print a bookmark-of marker.' );
	}

	public function test_bookmark_of_never_points_to_the_post_permalink(): void {
		$post_id   = $this->bookmark( $this->paragraph( 'Self link.' ) );
		$permalink = (string) get_permalink( $post_id );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'bookmark_of', $permalink );
		$html  = $this->render_single( $post_id );
		$entry = $this->entry_for_post( $html, $post_id );

		$this->assertArrayNotHasKey( 'bookmark-of', $entry['properties'] ?? [], 'The post permalink does not become bookmark-of.' );
		$this->assertStringNotContainsString( 'u-bookmark-of', $html, 'The post permalink does not print a bookmark-of marker.' );
	}

	public function test_bookmark_of_ignores_a_trailing_slash_difference_from_the_permalink(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$post_id   = $this->bookmark( $this->paragraph( 'Self link.' ) );
		$permalink = (string) get_permalink( $post_id );

		foreach ( [ untrailingslashit( $permalink ), trailingslashit( $permalink ) ] as $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . 'bookmark_of', $value );
			$html  = $this->render_single( $post_id );
			$entry = $this->entry_for_post( $html, $post_id );

			$this->assertStringNotContainsString( 'u-bookmark-of', $html, 'The post permalink variant ' . $value . ' does not print a bookmark-of marker.' );
			$this->assertArrayNotHasKey( 'bookmark-of', $entry['properties'] ?? [], 'The post permalink variant ' . $value . ' does not become bookmark-of.' );
		}
	}

	public function test_protected_bookmark_does_not_leak_meta_url(): void {
		$url     = 'https://example.com/private';
		$post_id = $this->bookmark(
			$this->paragraph( 'Protected bookmark.' ),
			[ Meta_Fields::PREFIX . 'bookmark_of' => $url ],
			[ 'post_password' => 'secret' ]
		);
		$html    = $this->render_single( $post_id );
		$entry   = $this->entry_for_post( $html, $post_id );

		$this->assertArrayNotHasKey( 'bookmark-of', $entry['properties'] ?? [], 'Protected bookmarks do not produce bookmark-of.' );
		$this->assertStringNotContainsString( $url, $html, 'Protected bookmarks do not leak the bookmark URL.' );
		$this->assertStringNotContainsString( 'u-bookmark-of', $html, 'Protected bookmarks do not print bookmark-of markers.' );
	}

	public function test_atproto_bookmark_request_has_no_hidden_data_or_singular_entry(): void {
		$url     = 'https://example.org/atproto';
		$post_id = $this->bookmark( $this->paragraph( 'Projection body.' ), [ Meta_Fields::PREFIX . 'bookmark_of' => $url ] );

		$this->go_to_post( $post_id );
		$GLOBALS['wp_query']->set( 'atproto', '' );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertStringNotContainsString( 'u-bookmark-of', $html, 'The atproto projection has no hidden bookmark-of.' );
		$this->assertStringNotContainsString( 'pkiw-singular-entry', $html, 'The atproto projection has no singular entry wrapper.' );
	}

	public function test_bookmark_card_author_does_not_suppress_entry_author(): void {
		$url     = 'https://example.com/author';
		$post_id = $this->bookmark( $this->bookmark_card( 'Saved page', $url, 'Cited Author' ) );
		$entry   = $this->entry_for_post( $this->render_single( $post_id ), $post_id );

		$this->assertSame( [ 'Site Author' ], $this->author_names( $entry ), 'The entry author is Site Author exactly once.' );
		$this->assertCount( 1, $entry['properties']['bookmark-of'] ?? [], 'The card keeps one bookmark-of citation.' );
		$cite = $entry['properties']['bookmark-of'][0];
		$this->assertSame( [ 'Cited Author' ], $this->author_names( $cite ), 'The cited author stays on the h-cite.' );
	}

	private function bookmark( string $content, array $meta = [], array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Saved page',
					'post_author'  => $this->author_id,
					'post_content' => $content,
				],
				$post
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $id, 'bookmark', Taxonomy::TAXONOMY ), 'The bookmark kind term is assigned.' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	private function bookmark_card( string $title, string $url, string $author ): string {
		return '<!-- wp:post-kinds-indieweb/bookmark-card '
			. wp_json_encode( [ 'title' => $title, 'url' => $url, 'author' => $author ], JSON_UNESCAPED_SLASHES )
			. ' /-->';
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	private function render_single( int $post_id ): string {
		$this->go_to_post( $post_id );
		$template = resolve_block_template(
			'single',
			[ 'single-post.php', 'single.php', 'singular.php', 'index.php' ],
			''
		);
		$this->assertNotNull( $template, 'No block template resolved for the bookmark single.' );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	private function go_to_post( int $post_id ): void {
		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'The request resolves as singular.' );
		setup_postdata( get_post( $post_id ) );
	}

	private function entry_for_post( string $html, int $post_id ): array {
		$items = \Mf2\parse( $html, get_permalink( $post_id ) )['items'] ?? [];
		$entry = $this->find_item_with_url( $items, 'h-entry', (string) get_permalink( $post_id ) );
		$this->assertIsArray( $entry, 'The rendered bookmark single has an h-entry with the post permalink.' );

		return $entry;
	}

	private function find_item_with_url( array $items, string $type, string $url ): ?array {
		foreach ( $items as $item ) {
			if ( in_array( $type, $item['type'] ?? [], true ) && in_array( $url, $item['properties']['url'] ?? [], true ) ) {
				return $item;
			}
			$found = $this->find_item_with_url( $item['children'] ?? [], $type, $url );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	private function bookmark_urls( array $entry ): array {
		$urls = [];
		foreach ( $entry['properties']['bookmark-of'] ?? [] as $value ) {
			if ( is_string( $value ) ) {
				$urls[] = $value;
				continue;
			}
			if ( is_array( $value ) && is_string( $value['value'] ?? null ) && str_starts_with( $value['value'], 'http' ) ) {
				$urls[] = $value['value'];
				continue;
			}
			foreach ( is_array( $value ) ? ( $value['properties']['url'] ?? [] ) : [] as $url ) {
				$urls[] = (string) $url;
			}
		}

		return $urls;
	}

	private function author_names( array $item ): array {
		$names = [];
		foreach ( $item['properties']['author'] ?? [] as $author ) {
			if ( is_array( $author ) ) {
				$names[] = (string) ( $author['properties']['name'][0] ?? '' );
			} else {
				$names[] = (string) $author;
			}
		}

		return $names;
	}

	private function hidden_class_token_count( string $html, string $token ): int {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $doc );
		$count = 0;
		foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")]' ) as $node ) {
			if ( $node instanceof DOMElement && $this->has_hidden_ancestor_or_self( $node ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function has_hidden_ancestor_or_self( DOMElement $node ): bool {
		for ( $current = $node; $current instanceof DOMElement; $current = $current->parentNode ) {
			if ( $current->hasAttribute( 'hidden' ) ) {
				return true;
			}
		}

		return false;
	}
}

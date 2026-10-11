<?php
/**
 * Reply single microformats (issue 253).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Micropub_Content_Builder;
use PKIW\Reply_Archive;
use PKIW\Taxonomy;

/**
 * Reply singles keep the citation outside the entry body and preserve authorship.
 *
 * @group integration
 */
final class ReplyMicroformatsTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private int $original_user_id = 0;

	private int $author_id = 0;

	/**
	 * @var callable|null
	 */
	private $post_content_filter = null;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_user_id    = get_current_user_id();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		if ( null === \PKIW\Kind_Facts::reader( 'reply' ) ) {
			( new Reply_Archive() )->register();
		}
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Site Author' ] );
	}

	public function tear_down(): void {
		if ( null !== $this->post_content_filter ) {
			remove_filter( 'render_block_core/post-content', $this->post_content_filter, 11 );
			$this->post_content_filter = null;
		}
		wp_reset_postdata();
		wp_set_current_user( $this->original_user_id );
		if ( isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query']->set( 'atproto', null );
		}
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * @dataProvider editor_reply_variants
	 */
	public function test_editor_reply_card_is_first_and_body_is_the_only_e_content( string $variant ): void {
		if ( 'with dev IndieBlocks mirror' === $variant ) {
			$this->add_indieblocks_post_content_mirror();
		}
		$post_id = $this->reply(
			$this->paragraph( 'Body authored before the card.' )
			. $this->reply_card( 'A cited note', 'https://example.org/notes/1', 'Cited Author' )
			. $this->paragraph( 'Body authored after the card.' )
		);

		$html  = $this->render_single( $post_id );
		$entry = $this->entry_for_post( $html, $post_id );

		$this->assertLessThan(
			$this->position_of( $html, 'class="e-content"' ),
			$this->position_of( $html, 'k-reply h-cite' ),
			"{$variant}: the reply card renders before the e-content wrapper."
		);
		$this->assertSame( [ 'Site Author' ], $this->author_names( $entry ), "{$variant}: the entry author is the site author exactly once." );
		$this->assertCount( 1, $entry['properties']['in-reply-to'] ?? [], "{$variant}: the entry has one in-reply-to." );
		$cite = $entry['properties']['in-reply-to'][0];
		$this->assertSame( [ 'h-cite' ], $cite['type'] ?? [], "{$variant}: in-reply-to is an h-cite." );
		$this->assertSame( [ 'https://example.org/notes/1' ], $cite['properties']['url'] ?? [], "{$variant}: the h-cite has the cited URL." );
		$this->assertSame( [ 'A cited note' ], $cite['properties']['name'] ?? [], "{$variant}: the h-cite has the cited name." );
		$this->assertSame( [ 'Cited Author' ], $this->author_names( $cite ), "{$variant}: the h-cite has the cited author." );
		$this->assertCount( 1, $entry['properties']['content'] ?? [], "{$variant}: the entry has one content property." );
		$content = $entry['properties']['content'][0];
		$this->assertIsArray( $content, "{$variant}: the content property preserves html." );
		$this->assertStringContainsString( 'Body authored before the card.', $content['html'] ?? '', "{$variant}: e-content includes body authored before the card." );
		$this->assertStringContainsString( 'Body authored after the card.', $content['html'] ?? '', "{$variant}: e-content includes body authored after the card." );
		$this->assertStringNotContainsString( 'h-cite', $content['html'] ?? '', "{$variant}: e-content excludes the h-cite." );
		$this->assertStringNotContainsString( 'pk-card', $content['html'] ?? '', "{$variant}: e-content excludes the reply card." );
		$this->assertStringNotContainsString( 'A cited note', $content['html'] ?? '', "{$variant}: e-content excludes the cited title." );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function editor_reply_variants(): array {
		return [
			'plain'                       => [ 'plain' ],
			'with dev IndieBlocks mirror' => [ 'with dev IndieBlocks mirror' ],
		];
	}

	public function test_card_only_reply_is_left_without_e_content_but_keeps_author_and_citation(): void {
		$post_id = $this->reply( $this->reply_card( 'A cited note', 'https://example.org/notes/2', 'Cited Author' ) );

		$html  = $this->render_single( $post_id );
		$entry = $this->entry_for_post( $html, $post_id );

		$this->assertSame( 0, $this->class_token_count( $html, 'e-content' ), 'Card-only replies do not get an empty e-content wrapper.' );
		$this->assertCount( 1, $entry['properties']['in-reply-to'] ?? [], 'Card-only replies keep one in-reply-to.' );
		$this->assertSame( [ 'Site Author' ], $this->author_names( $entry ), 'Card-only replies keep the hidden entry author.' );
	}

	public function test_micropub_reply_content_keeps_existing_e_content_shape(): void {
		$raw     = $this->micropub_reply_content( 'https://example.org/notes/3', 'A cited Micropub note', 'Cited Author', 'Micropub body text.' );
		$post_id = $this->reply( $raw );

		$html  = $this->render_single( $post_id );
		$entry = $this->entry_for_post( $html, $post_id );

		$this->assertSame( substr_count( do_blocks( $raw ), 'e-content' ), substr_count( $html, 'e-content' ), 'The singular render does not add another e-content string.' );
		$this->assertSame( 1, $this->class_token_count( $html, 'e-content' ), 'The rendered Micropub reply has exactly one e-content element.' );
		$this->assertLessThan(
			$this->position_of( $html, 'e-content' ),
			$this->position_of( $html, 'k-reply h-cite' ),
			'The h-cite renders before the existing e-content group.'
		);
		$this->assertCount( 1, $entry['properties']['in-reply-to'] ?? [], 'Micropub replies keep one in-reply-to.' );
		$content = $entry['properties']['content'][0] ?? null;
		$this->assertIsArray( $content, 'The Micropub content property preserves html.' );
		$this->assertSame( 'Micropub body text.', $content['value'] ?? '', 'The parsed content value equals the Micropub body.' );
		$this->assertStringNotContainsString( 'h-cite', $content['html'] ?? '', 'The Micropub e-content excludes the h-cite.' );
	}

	public function test_atproto_reply_keeps_authored_order_and_no_new_markup(): void {
		$post_id = $this->reply(
			$this->paragraph( 'Body before card.' )
			. $this->reply_card( 'A cited note', 'https://example.org/notes/4', 'Cited Author' )
		);

		$this->go_to_post( $post_id );
		$GLOBALS['wp_query']->set( 'atproto', '' );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

		$this->assertLessThan( $this->position_of( $html, '<article' ), $this->position_of( $html, 'Body before card.' ), 'The atproto projection keeps the authored paragraph before the card.' );
		$this->assertStringNotContainsString( 'e-content', $html, 'The atproto projection has no reply e-content wrapper.' );
		$this->assertStringNotContainsString( 'pkiw-singular-entry', $html, 'The atproto projection has no singular entry wrapper.' );
	}

	public function test_hidden_author_check_ignores_cited_author_inside_h_cite(): void {
		$post_id = $this->reply( $this->reply_card( 'A cited note', 'https://example.org/notes/5', 'Cited Author' ) );

		$entry = $this->entry_for_post( $this->render_single( $post_id ), $post_id );

		$this->assertSame( [ 'Site Author' ], $this->author_names( $entry ), 'The cited p-author does not suppress the entry author.' );
	}

	public function test_hidden_author_check_honors_author_outside_h_cite(): void {
		$post_id = $this->reply(
			$this->reply_card( 'A cited note', 'https://example.org/notes/6', 'Cited Author' )
			. '<!-- wp:html --><span class="p-author h-card"><span class="p-name">Body Author</span></span><!-- /wp:html -->'
		);

		$entry = $this->entry_for_post( $this->render_single( $post_id ), $post_id );

		$this->assertSame( [ 'Body Author' ], $this->author_names( $entry ), 'An authored p-author outside the h-cite is the only entry author.' );
	}

	public function test_reply_restructuring_does_not_apply_to_bookmark_singles(): void {
		$post_id = $this->kind_post(
			'bookmark',
			'<!-- wp:post-kinds-indieweb/bookmark-card {"title":"Saved page","url":"https://example.com/saved"} /-->'
			. $this->paragraph( 'Saved this for later.' )
		);

		$html = $this->render_single( $post_id );

		$this->assertSame( 0, $this->class_token_count( $html, 'e-content' ), 'Bookmark singles do not get the reply e-content restructuring.' );
	}

	private function reply( string $content ): int {
		return $this->kind_post( 'reply', $content );
	}

	private function kind_post( string $kind, string $content ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => ucfirst( $kind ) . ' probe',
				'post_author'  => $this->author_id,
				'post_content' => $content,
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY ), 'The kind term is assigned.' );

		return $id;
	}

	private function reply_card( string $title, string $url, string $author ): string {
		return '<!-- wp:post-kinds-indieweb/reply-card '
			. wp_json_encode( [ 'title' => $title, 'url' => $url, 'author' => $author ], JSON_UNESCAPED_SLASHES )
			. ' /-->';
	}

	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	private function micropub_reply_content( string $url, string $name, string $author, string $body ): string {
		$build = new ReflectionMethod( Micropub_Content_Builder::class, 'build_block_content' );

		return (string) $build->invoke(
			null,
			[
				'in-reply-to' => [ $url ],
				'name'        => [ $name ],
				'author'      => [ $author ],
				'content'     => [ $body ],
			]
		);
	}

	private function add_indieblocks_post_content_mirror(): void {
		// Mirrors IndieBlocks 0.13.3 on the dev site: if post-content has no
		// e-content class token, add it to the first div.
		$this->post_content_filter = static function ( string $html ): string {
			if ( preg_match( '~(^|\\s)e-content(\\s|$)~', $html ) ) {
				return $html;
			}
			$tags = new WP_HTML_Tag_Processor( $html );
			if ( $tags->next_tag( [ 'tag_name' => 'div' ] ) ) {
				$tags->add_class( 'e-content' );
				return $tags->get_updated_html();
			}

			return $html;
		};
		add_filter( 'render_block_core/post-content', $this->post_content_filter, 11 );
	}

	private function render_single( int $post_id ): string {
		$this->go_to_post( $post_id );
		$template = resolve_block_template(
			'single',
			[ 'single-post.php', 'single.php', 'singular.php', 'index.php' ],
			''
		);
		$this->assertNotNull( $template, 'No block template resolved for the reply single.' );

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
		$this->assertIsArray( $entry, 'The rendered reply single has an h-entry with the post permalink.' );

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

	private function class_token_count( string $html, string $token ): int {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $doc );

		return $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")]' )->length;
	}

	private function position_of( string $html, string $needle ): int {
		$position = strpos( $html, $needle );
		$this->assertIsInt( $position, "The rendered HTML contains {$needle}." );

		return $position;
	}
}

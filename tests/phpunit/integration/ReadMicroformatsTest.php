<?php
/**
 * Microformats of the read archive's items (issue 234).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Micropub_Content_Builder;
use PKIW\Read_Archive;
use PKIW\Taxonomy;

/**
 * Every item on /kind/read/ parses as an h-entry with a `read-of` h-cite
 * naming the book and its author, whatever shape the post has:
 *
 * - a post holding only a read card, which prints the h-cite itself
 * - a Micropub post, whose group holds the card and a content paragraph, so
 *   the Stream card prints its generic card with the hidden citation
 * - a post with read meta and no card (an import), the same generic card
 *
 * The archive is the plugin's taxonomy-kind-read template, in both the
 * shelf view and the A to Z view. Titles and authors are invented.
 *
 * @group integration
 */
final class ReadMicroformatsTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Stub book-completion service, so saving a card makes no remote lookup.
	 *
	 * @var callable
	 */
	private $no_lookup;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		$this->no_lookup = static fn() => new class() {
			/**
			 * Complete nothing.
			 *
			 * @param array<string, mixed> $query Book query.
			 * @return array<string, mixed>
			 */
			public function complete( array $query ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return [];
			}
		};
		add_filter( 'pkiw_book_completion_service', $this->no_lookup );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Site Author' ] ) );
	}

	public function tear_down(): void {
		remove_filter( 'pkiw_book_completion_service', $this->no_lookup );
		remove_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * Insert a published read the way the site does: wp_insert_post, so
	 * Card_Meta_Sync copies the card's facts to meta on save.
	 *
	 * @param string $title   Post title.
	 * @param string $date    Post date.
	 * @param string $content Post content.
	 */
	private function insert( string $title, string $date, string $content ): int {
		$id = wp_insert_post(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => $content,
				'post_author'  => get_current_user_id(),
				'tax_input'    => [ Taxonomy::TAXONOMY => [ 'read' ] ],
			],
			true
		);
		$this->assertIsInt( $id );
		$this->assertSame( [ 'read' ], wp_get_object_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ) );

		return $id;
	}

	/**
	 * The three shapes.
	 *
	 * @return array<string, array{id: int, name: string, author: string}>
	 */
	private function shapes(): array {
		$card = $this->insert(
			'Salt and Paper',
			'2026-08-28 10:00:00',
			'<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Salt and Paper","authorName":"Ines Okafor","bookUrl":"https://example.org/books/salt-and-paper","readStatus":"finished"} /-->'
		);

		$build    = new ReflectionMethod( Micropub_Content_Builder::class, 'build_block_content' );
		$micropub = (string) $build->invoke(
			null,
			[
				'read-of'     => [ 'https://example.org/books/lantern-index' ],
				'name'        => [ 'The Lantern Index' ],
				'author'      => [ 'Theo Marsh' ],
				'read-status' => [ 'reading' ],
				'content'     => [ 'Halfway, and the index is the plot.' ],
			]
		);
		$this->assertStringContainsString( 'pkiw-entry', $micropub, 'The Micropub group shape.' );
		$this->assertFalse( \PKIW\content_is_kind_card_only( $micropub ), 'The paragraph sends the Stream card down the generic path.' );
		$group = $this->insert( 'The Lantern Index', '2026-08-27 10:00:00', $micropub );

		$meta_only = $this->insert( 'reading', '2026-08-26 10:00:00', '<!-- wp:paragraph --><p>Picked it up at the library.</p><!-- /wp:paragraph -->' );
		add_post_meta( $meta_only, '_pkiw_read_title', 'A Day of Fallen Rain' );
		add_post_meta( $meta_only, '_pkiw_read_author', 'Samira Holt' );
		add_post_meta( $meta_only, '_pkiw_read_status', 'reading' );

		return [
			'card'      => [
				'id'     => $card,
				'name'   => 'Salt and Paper',
				'author' => 'Ines Okafor',
			],
			'micropub'  => [
				'id'     => $group,
				'name'   => 'The Lantern Index',
				'author' => 'Theo Marsh',
			],
			'meta only' => [
				'id'     => $meta_only,
				'name'   => 'A Day of Fallen Rain',
				'author' => 'Samira Holt',
			],
		];
	}

	/**
	 * Visit a URL and render the block template it resolves to.
	 *
	 * @param string $url URL.
	 */
	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-read.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-read', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Every h-entry on the page, keyed by its url.
	 *
	 * @param string $html Rendered page.
	 * @return array<string, array<string, mixed>>
	 */
	private function entries( string $html ): array {
		$entries = [];
		$walk    = static function ( array $items ) use ( &$walk, &$entries ): void {
			foreach ( $items as $item ) {
				if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
					foreach ( $item['properties']['url'] ?? [] as $url ) {
						$entries[ (string) $url ] = $item;
					}
				}
				$walk( $item['children'] ?? [] );
			}
		};
		$walk( \Mf2\parse( $html, 'http://example.org/' )['items'] ?? [] );

		return $entries;
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function views(): array {
		return [
			'shelves' => [ '' ],
			'A to Z'  => [ 'author' ],
		];
	}

	/**
	 * @dataProvider views
	 *
	 * @param string $order pkiw_read_order value, '' for the shelves.
	 */
	public function test_every_shape_parses_as_an_entry_reading_the_book( string $order ): void {
		$shapes = $this->shapes();
		$url    = (string) get_term_link( 'read', Taxonomy::TAXONOMY );
		if ( '' !== $order ) {
			$url = add_query_arg( Read_Archive::QUERY_VAR, $order, $url );
		}

		$entries = $this->entries( $this->serve( $url ) );

		foreach ( $shapes as $shape => $expected ) {
			$permalink = (string) get_permalink( $expected['id'] );
			$this->assertArrayHasKey( $permalink, $entries, "{$shape}: one h-entry whose url is the permalink." );
			$entry = $entries[ $permalink ];

			$this->assertNotEmpty( $entry['properties']['published'] ?? [], "{$shape}: the entry is dated." );
			$this->assertSame( 'Site Author', $entry['properties']['author'][0]['properties']['name'][0] ?? null, "{$shape}: the entry's author is the site author." );

			$this->assertCount( 1, $entry['properties']['read-of'] ?? [], "{$shape}: one read-of." );
			$cite = $entry['properties']['read-of'][0];
			$this->assertContains( 'h-cite', $cite['type'] ?? [], "{$shape}: read-of is an h-cite." );
			$this->assertSame( [ $expected['name'] ], $cite['properties']['name'] ?? null, "{$shape}: the citation names the book." );
			$this->assertSame( [ $expected['author'] ], $cite['properties']['author'][0]['properties']['name'] ?? null, "{$shape}: the citation names its author." );
		}
		$this->assertCount( 3, $entries, 'No item parses as a second entry.' );
	}
}

<?php
/**
 * The favorite archive end to end on the plugin template (issue 259).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * Month sections, 12 per page, newest first, and the microformats each item
 * carries, rendered from the plugin's own templates/taxonomy-kind-favorite.html
 * with no theme. Titles, people and hosts are invented.
 *
 * @group integration
 */
final class FavoriteArchiveTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Original month names, restored after a localized test.
	 *
	 * @var array<string, string>
	 */
	private array $original_months = [];

	/**
	 * Fixture post IDs by key.
	 *
	 * @var array<string, int>
	 */
	private array $posts = [];

	/**
	 * Published fixture keys in the order the archive lists them.
	 *
	 * @var string[]
	 */
	private array $order = [];

	/**
	 * Fixture author.
	 *
	 * @var int
	 */
	private int $author = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		add_filter( 'pre_http_request', '__return_empty_array' );
		update_option( 'posts_per_page', 10 );
		update_option( 'posts_per_rss', 5 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		$this->author = self::factory()->user->create( [ 'role' => 'editor', 'display_name' => 'Site Author' ] );
		$this->fixtures();
	}

	public function tear_down(): void {
		if ( [] !== $this->original_months ) {
			foreach ( $this->original_months as $number => $name ) {
				$GLOBALS['wp_locale']->month[ $number ] = $name;
			}
			$this->original_months = [];
		}
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A favorite.
	 *
	 * @param string               $key     Fixture key (also the slug).
	 * @param string               $date    Post date.
	 * @param array<string, mixed> $args    Post arguments, including post_content.
	 * @param array<string, mixed> $meta    Post meta.
	 */
	private function favorite( string $key, string $date, array $args, array $meta = [] ): void {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_name'   => $key,
					'post_date'   => $date,
					'post_author' => $this->author,
					'post_title'  => ucfirst( $key ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'favorite', Taxonomy::TAXONOMY );
		foreach ( $meta as $name => $value ) {
			update_post_meta( $id, $name, $value );
		}
		$this->posts[ $key ] = $id;
	}

	/**
	 * The card block for attributes.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/favorite-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
	}

	/**
	 * Eighteen published favorites in three months, plus a private one and a draft.
	 *
	 * August 5, July 5, June 8: page 1 is August, July and the first two of
	 * June, and page 2 repeats the June heading over the last six.
	 */
	private function fixtures(): void {
		$paragraph = '<!-- wp:paragraph --><p>My own words about it.</p><!-- /wp:paragraph -->';

		// August: card-only posts, with two on the same timestamp.
		$this->favorite( 'aug-a1', '2026-08-28 10:00:00', [ 'post_content' => $this->card( [ 'title' => 'Aug A1', 'url' => 'https://notes.example.com/a1', 'author' => 'Rowan Vale', 'favoritedAt' => '2026-08-14' ] ) ] );
		$this->favorite( 'aug-a2', '2026-08-24 10:00:00', [ 'post_title' => 'Why this zine stuck with me', 'post_content' => $this->card( [ 'title' => 'Small Press Almanac, Issue 3', 'url' => 'https://zine.example.org/almanac-3', 'author' => 'Imogen Thatch' ] ) ] );
		$this->favorite( 'aug-a3', '2026-08-20 10:00:00', [ 'post_content' => $this->card( [ 'title' => 'Aug A3', 'url' => 'https://notes.example.com/a3' ] ) ] );
		$this->favorite( 'aug-a4', '2026-08-20 10:00:00', [ 'post_content' => $this->card( [ 'title' => 'Aug A4', 'url' => 'https://notes.example.com/a4' ] ) ] );
		$this->favorite( 'aug-a5', '2026-08-12 10:00:00', [ 'post_content' => $this->card( [ 'title' => 'Kitchen Radio Hour', 'author' => 'Pell Okafor' ] ) ] );

		// July: a generic card, an untitled url-only card, a card-less Quick Post, a protected card, and nothing stored.
		$this->favorite( 'jul-j1', '2026-07-30 10:00:00', [ 'post_content' => $this->card( [ 'title' => 'Jul J1', 'url' => 'https://notes.example.com/j1' ] ) . $paragraph ] );
		$this->favorite( 'jul-j2', '2026-07-25 10:00:00', [ 'post_title' => '', 'post_content' => $this->card( [ 'url' => 'https://zine.example.org/issue-9' ] ) ] );
		$this->favorite(
			'jul-j3',
			'2026-07-20 10:00:00',
			[ 'post_title' => 'Seed Recipe Index', 'post_content' => $paragraph ],
			[
				'_pkiw_favorite_name' => 'Seed Recipe Index',
				'_pkiw_favorite_url'  => 'https://recipes.example.com/index',
			]
		);
		$this->favorite( 'jul-j4', '2026-07-10 10:00:00', [ 'post_title' => 'Locked Favorite Sample', 'post_password' => 'seed259', 'post_content' => $this->card( [ 'title' => 'Locked', 'url' => 'https://secret.example.org/locked' ] ) ] );
		$this->favorite( 'jul-j5', '2026-07-02 10:00:00', [ 'post_title' => '', 'post_content' => $paragraph ] );

		// June: eight card-only posts.
		foreach ( [ 30, 28, 26, 22, 18, 14, 10, 5 ] as $i => $day ) {
			$this->favorite( 'jun-u' . ( $i + 1 ), sprintf( '2026-06-%02d 10:00:00', $day ), [ 'post_content' => $this->card( [ 'title' => 'Jun U' . ( $i + 1 ), 'url' => 'https://notes.example.com/u' . ( $i + 1 ) ] ) ] );
		}

		// Never listed to a logged-out visitor.
		$this->favorite( 'private-p1', '2026-08-29 10:00:00', [ 'post_status' => 'private', 'post_content' => $this->card( [ 'title' => 'Private P1', 'url' => 'https://secret.example.org/p1' ] ) ] );
		$this->favorite( 'draft-d1', '2026-08-30 10:00:00', [ 'post_status' => 'draft', 'post_content' => $this->card( [ 'title' => 'Draft D1', 'url' => 'https://secret.example.org/d1' ] ) ] );

		$this->order = array_merge(
			[ 'aug-a1', 'aug-a2', 'aug-a4', 'aug-a3', 'aug-a5', 'jul-j1', 'jul-j2', 'jul-j3', 'jul-j4', 'jul-j5' ],
			array_map( static fn( int $n ): string => 'jun-u' . $n, range( 1, 8 ) )
		);
	}

	/**
	 * The archive page's URL.
	 *
	 * @param int $page Page number.
	 */
	private function archive_url( int $page = 1 ): string {
		$link = get_term_link( 'favorite', Taxonomy::TAXONOMY );
		$this->assertIsString( $link );

		return $page > 1 ? trailingslashit( $link ) . 'page/' . $page . '/' : $link;
	}

	/**
	 * Request an archive page and render the template it resolves to.
	 *
	 * @param int $page Page number.
	 */
	private function render_archive( int $page = 1 ): string {
		$this->go_to( $this->archive_url( $page ) );
		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, 'favorite' ), 'The request is the favorite archive.' );

		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-favorite.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-favorite', $template->id, 'No theme template stands in the way.' );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * XPath over an HTML fragment.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * The month headings on a rendered page: id => text.
	 *
	 * @param string $html Archive HTML.
	 * @return array<int, array{0: string, 1: string, 2: string}> Tag, id and text per heading.
	 */
	private function month_headings( string $html ): array {
		$out = [];
		foreach ( $this->xpath( $html )->query( '//*[starts-with(@id, "pkiw-group-")]' ) as $node ) {
			$out[] = [ $node->nodeName, $node->getAttribute( 'id' ), trim( (string) preg_replace( '/\s+/', ' ', $node->textContent ) ) ];
		}

		return $out;
	}

	/**
	 * The permalinks of fixture keys.
	 *
	 * @param string[] $keys Fixture keys.
	 * @return string[]
	 */
	private function permalinks( array $keys ): array {
		return array_map( fn( string $key ): string => (string) get_permalink( $this->posts[ $key ] ), $keys );
	}

	public function test_page_one_lists_twelve_in_date_order_with_the_higher_id_first_on_a_tie(): void {
		$this->assertGreaterThan( $this->posts['aug-a3'], $this->posts['aug-a4'] );

		$html = $this->render_archive();
		$ids  = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$this->assertSame( array_map( fn( string $key ): int => $this->posts[ $key ], array_slice( $this->order, 0, 12 ) ), $ids );
		$this->assertSame( 12, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ), 'The marker sizes the page, not posts_per_page.' );

		$last = -1;
		foreach ( $this->permalinks( array_slice( $this->order, 0, 12 ) ) as $permalink ) {
			$at = strpos( $html, 'href="' . $permalink . '"' );
			$this->assertNotFalse( $at, "Rendered: {$permalink}" );
			$this->assertGreaterThan( $last, $at, 'DOM order is archive order.' );
			$last = $at;
		}
	}

	public function test_page_two_resolves_and_holds_the_other_six(): void {
		$html = $this->render_archive( 2 );
		$ids  = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$this->assertSame( array_map( fn( string $key ): int => $this->posts[ $key ], array_slice( $this->order, 12 ) ), $ids );
		$this->assertSame( 18, (int) $GLOBALS['wp_query']->found_posts, 'Private and draft favorites are not counted for a visitor.' );
		$this->assertSame( 2, (int) $GLOBALS['wp_query']->max_num_pages );
		$this->assertStringNotContainsString( 'Nothing here yet.', $html );
	}

	public function test_sections_read_month_and_year_and_page_two_repeats_the_june_heading(): void {
		$one = $this->month_headings( $this->render_archive() );
		$two = $this->month_headings( $this->render_archive( 2 ) );

		$this->assertSame(
			[
				[ 'h2', 'pkiw-group-2026-08', 'August 2026' ],
				[ 'h2', 'pkiw-group-2026-07', 'July 2026' ],
				[ 'h2', 'pkiw-group-2026-06', 'June 2026' ],
			],
			$one
		);
		$this->assertSame( [ [ 'h2', 'pkiw-group-2026-06', 'June 2026' ] ], $two, 'A month that continues repeats its H2 at the top of the next page.' );
		foreach ( [ $one, $two ] as $headings ) {
			$ids = array_column( $headings, 1 );
			$this->assertSame( $ids, array_values( array_unique( $ids ) ), 'Heading ids are unique on a page.' );
		}
	}

	public function test_month_labels_follow_the_wp_locale_month_names(): void {
		foreach ( [ '06', '08' ] as $number ) {
			$this->original_months[ $number ] = $GLOBALS['wp_locale']->month[ $number ];
		}
		$GLOBALS['wp_locale']->month['08'] = 'août';
		$GLOBALS['wp_locale']->month['06'] = 'juin';

		$one = $this->month_headings( $this->render_archive() );
		$two = $this->month_headings( $this->render_archive( 2 ) );

		$this->assertSame( [ 'août 2026', 'July 2026', 'juin 2026' ], array_column( $one, 2 ) );
		$this->assertSame( [ 'juin 2026' ], array_column( $two, 2 ) );
		$this->assertSame( [ 'pkiw-group-2026-08', 'pkiw-group-2026-07', 'pkiw-group-2026-06' ], array_column( $one, 1 ), 'Ids stay on the numeric month.' );
	}

	public function test_the_main_query_orders_by_post_date_and_never_reads_favorite_meta(): void {
		$this->go_to( $this->archive_url() );
		global $wpdb;

		$request = (string) $GLOBALS['wp_query']->request;
		$this->assertStringNotContainsString( '_pkiw_favorite', $request );
		$this->assertStringNotContainsString( 'favoritedAt', $request );
		$this->assertSame( 18, (int) $GLOBALS['wp_query']->found_posts, 'Grouping changes order, never the count.' );

		$ungrouped = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'favorite' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);
		$this->assertSame( 18, $ungrouped->found_posts );
		$this->assertStringContainsString( "{$wpdb->posts}.post_date DESC", $request );
	}

	public function test_the_kind_feed_ignores_grouping_and_lists_newest_first(): void {
		$this->go_to( add_query_arg( 'feed', 'rss2', $this->archive_url() ) );
		global $wp_query;

		$this->assertTrue( $wp_query->is_feed() && $wp_query->is_tax( Taxonomy::TAXONOMY, 'favorite' ), 'The request is the kind feed.' );
		$grouping = array_filter(
			(array) $wp_query->query_vars,
			static fn( $value, $name ): bool => str_starts_with( (string) $name, 'pkiw_group' ) && '' !== $value && null !== $value,
			ARRAY_FILTER_USE_BOTH
		);
		$this->assertSame( [], $grouping, 'No pkiw_group_* var reaches the feed query.' );
		$this->assertSame( 5, (int) $wp_query->get( 'posts_per_page' ), 'posts_per_rss, not the archive page size.' );
		$this->assertSame( 5, $wp_query->post_count );
		$this->assertSame(
			array_map( fn( string $key ): int => $this->posts[ $key ], [ 'aug-a1', 'aug-a2', 'aug-a4', 'aug-a3', 'aug-a5' ] ),
			array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) )
		);
	}

	/**
	 * The top-level microformats on a rendered archive page.
	 *
	 * @param string $html Archive HTML.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( string $html ): array {
		return \Mf2\parse( $html, home_url( '/' ) )['items'];
	}

	/**
	 * The cited URLs a parsed h-entry names as favorite-of.
	 *
	 * @param array<string, mixed> $entry Parsed h-entry.
	 * @return string[]
	 */
	private function favorite_of_urls( array $entry ): array {
		$urls = [];
		foreach ( $entry['properties']['favorite-of'] ?? [] as $value ) {
			$urls[] = is_array( $value ) ? (string) ( $value['properties']['url'][0] ?? $value['value'] ?? '' ) : (string) $value;
		}

		return $urls;
	}

	/**
	 * Each page-one item is one h-entry root with the permalink, a publish date and an author, never an h-cite root.
	 */
	public function test_every_item_is_exactly_one_h_entry_root_and_never_an_h_cite(): void {
		$items = $this->items( $this->render_archive() );

		$this->assertCount( 12, $items, 'One root per item: no card adds a second one.' );
		$by_url = [];
		foreach ( $items as $item ) {
			$this->assertSame( [ 'h-entry' ], $item['type'], 'No item\'s root is an h-cite.' );
			$this->assertNotEmpty( $item['properties']['url'] ?? [], 'u-url' );
			$this->assertNotEmpty( $item['properties']['published'] ?? [], 'dt-published' );
			$this->assertNotEmpty( $item['properties']['author'] ?? [], 'p-author' );
			foreach ( $item['properties']['url'] as $url ) {
				$by_url[ is_array( $url ) ? (string) ( $url['value'] ?? '' ) : (string) $url ] = $item;
			}
		}
		foreach ( $this->permalinks( array_slice( $this->order, 0, 12 ) ) as $permalink ) {
			$this->assertArrayHasKey( $permalink, $by_url, "An h-entry has u-url {$permalink}" );
		}
	}

	/**
	 * favorite-of appears only where a URL is stored, and never names the permalink.
	 *
	 * @return array<string, array{0: string, 1: string}> Fixture key, cited URL or ''.
	 */
	public function favorite_of_cases(): array {
		return [
			'a card-only post with a URL'            => [ 'aug-a1', 'https://notes.example.com/a1' ],
			'a card-only post, title differs'        => [ 'aug-a2', 'https://zine.example.org/almanac-3' ],
			'a card with no URL (the F05 shape)'     => [ 'aug-a5', '' ],
			'a generic card, card plus body'         => [ 'jul-j1', 'https://notes.example.com/j1' ],
			'an untitled url-only card'              => [ 'jul-j2', 'https://zine.example.org/issue-9' ],
			'a card-less Quick Post (meta)'          => [ 'jul-j3', 'https://recipes.example.com/index' ],
			'a protected post'                       => [ 'jul-j4', '' ],
			'a post with nothing stored (F08)'       => [ 'jul-j5', '' ],
		];
	}

	/**
	 * @dataProvider favorite_of_cases
	 *
	 * @param string $key   Fixture key.
	 * @param string $cited Cited URL, or '' for none.
	 */
	public function test_favorite_of_appears_only_with_a_stored_url( string $key, string $cited ): void {
		$permalink = (string) get_permalink( $this->posts[ $key ] );
		$entry     = null;
		foreach ( $this->items( $this->render_archive() ) as $item ) {
			foreach ( $item['properties']['url'] ?? [] as $url ) {
				if ( ( is_array( $url ) ? (string) ( $url['value'] ?? '' ) : (string) $url ) === $permalink ) {
					$entry = $item;
				}
			}
		}

		$this->assertNotNull( $entry, "{$key} parses to an h-entry." );
		$urls = $this->favorite_of_urls( $entry );
		$this->assertSame( '' === $cited ? [] : [ $cited ], $urls );
		$this->assertNotContains( $permalink, $urls, 'favorite-of never names the post itself.' );
	}

	public function test_a_protected_favorite_leaks_neither_its_cited_url_nor_its_card_title(): void {
		$html = $this->render_archive();

		$this->assertStringContainsString( 'Locked Favorite Sample', $html );
		$this->assertStringNotContainsString( 'secret.example.org/locked', $html );
		$this->assertStringNotContainsString( '>Locked<', $html );
	}

	public function test_private_and_draft_favorites_are_not_listed_to_a_visitor(): void {
		$html = $this->render_archive() . $this->render_archive( 2 );

		$this->assertStringNotContainsString( 'secret.example.org/p1', $html );
		$this->assertStringNotContainsString( 'secret.example.org/d1', $html );
		$this->assertStringNotContainsString( 'Private P1', $html );
		$this->assertStringNotContainsString( 'Draft D1', $html );
	}

	public function test_the_archive_titles_item_headings_as_h3_under_the_month_h2s_and_one_h1(): void {
		$html = $this->render_archive();
		$xp   = $this->xpath( $html );

		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertSame( 3, $xp->query( '//h2[starts-with(@id, "pkiw-group-")]' )->length );
		$this->assertSame( 12, $xp->query( '//li//h3' )->length, 'Item titles are H3.' );
	}

	public function test_an_empty_archive_prints_the_empty_state_and_no_sections_or_pager(): void {
		foreach ( $this->posts as $id ) {
			wp_trash_post( $id );
		}
		$html = $this->render_archive();

		$this->assertStringContainsString( 'Nothing here yet.', $html );
		$this->assertSame( [], $this->month_headings( $html ) );
		$this->assertStringNotContainsString( 'wp-block-query-pagination', $html );
	}
}

<?php
/**
 * The read archive: status shelves and the A to Z view (issue 234).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Read_Archive;
use PKIW\Taxonomy;

/**
 * /kind/read/ is served by the plugin's taxonomy-kind-read template. Its loop
 * groups reads by `_pkiw_read_status` into shelves: Currently Reading, To
 * Read, Finished, Abandoned, then the posts with no status. Twelve reads
 * fill a page. `?pkiw_read_order=author` turns the shelves off and lists the
 * reads by author, then title. The feed keeps date order in both views.
 * Titles and authors are invented.
 *
 * @group integration
 */
final class ReadArchiveTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		update_option( 'posts_per_page', 20 );
		// Pretty permalinks, as the site runs: /kind/read/page/2/. The kind
		// taxonomy registered before the structure was set, so add its permastruct.
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A published read with no card: its facts are meta.
	 *
	 * @param string      $title  Post title, also the book title.
	 * @param string      $date   Post date.
	 * @param string|null $status `_pkiw_read_status`, or null for no row.
	 * @param string|null $author `_pkiw_read_author`, or null for no row.
	 */
	private function read( string $title, string $date, ?string $status, ?string $author = null ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => $date,
			]
		);
		wp_set_object_terms( $id, 'read', Taxonomy::TAXONOMY );
		add_post_meta( $id, '_pkiw_read_title', $title );
		if ( null !== $status ) {
			add_post_meta( $id, '_pkiw_read_status', $status );
		}
		if ( null !== $author ) {
			add_post_meta( $id, '_pkiw_read_author', $author );
		}

		return $id;
	}

	private function archive_url(): string {
		$url = get_term_link( 'read', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/read/', $url );

		return $url;
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

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * Parse rendered HTML.
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
	 * Each shelf: its group key, heading tag and text, heading id and item titles.
	 *
	 * @param string $html Rendered page.
	 * @return array<int, array{group:string, heading:string, id:string, titles:string[]}>
	 */
	private function shelves( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$out[]   = [
				'group'   => $section->getAttribute( 'data-pkiw-group' ),
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'id'      => $heading instanceof DOMElement ? $heading->getAttribute( 'id' ) : '',
				'titles'  => $this->titles( $xpath, $section ),
			];
		}

		return $out;
	}

	/**
	 * Card titles under a node, in document order, as tag:text.
	 *
	 * @param DOMXPath $xpath Parsed page.
	 * @param DOMNode  $node  Context node.
	 * @return string[]
	 */
	private function titles( DOMXPath $xpath, DOMNode $node ): array {
		$titles = [];
		foreach ( $xpath->query( './/li//*[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]', $node ) as $title ) {
			$titles[] = trim( $title->textContent );
		}

		return $titles;
	}

	/**
	 * Fourteen reads: three reading, two to read, six finished, two
	 * abandoned and one with no status row, newest first within each.
	 *
	 * @return array<string, int>
	 */
	private function fourteen(): array {
		$ids = [];
		foreach (
			[
				[ 'Salt and Paper', '2026-08-28 10:00:00', 'reading' ],
				[ 'The Lantern Index', '2026-08-27 10:00:00', 'reading' ],
				[ 'Harbor Weather', '2026-08-26 10:00:00', 'reading' ],
				[ 'The Quiet Orchard', '2026-08-25 10:00:00', 'to-read' ],
				[ 'Glass Meridian', '2026-08-24 10:00:00', 'to-read' ],
				[ 'Copper Atlas', '2026-08-23 10:00:00', 'finished' ],
				[ 'Winter Ledger', '2026-08-22 10:00:00', 'finished' ],
				[ 'Field Notes on Moss', '2026-08-21 10:00:00', 'finished' ],
				[ 'The Paper Garden', '2026-08-20 10:00:00', 'finished' ],
				[ 'North of the Mill', '2026-08-19 10:00:00', 'finished' ],
				[ 'Small Hours', '2026-08-18 10:00:00', 'finished' ],
				[ 'The Long Detour', '2026-08-17 10:00:00', 'abandoned' ],
				[ 'Rain Almanac', '2026-08-16 10:00:00', 'abandoned' ],
				[ 'Untitled Margins', '2026-08-15 10:00:00', null ],
			] as [ $title, $date, $status ]
		) {
			$ids[ $title ] = $this->read( $title, $date, $status );
		}

		return $ids;
	}

	/**
	 * The REST posts route's read posts in grouped order, keyed by ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rest_reads_by_group(): array {
		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ get_term_by( 'slug', 'read', Taxonomy::TAXONOMY )->term_id ] );
		$request->set_param( 'orderby', 'pkiw_group' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return array_column( (array) $response->get_data(), null, 'id' );
	}

	/**
	 * The read archive's RSS2 feed, as core's feed template prints it.
	 */
	private function read_feed_document(): string {
		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'read', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_feed() );

		ob_start();
		try {
			// The template sends headers after PHPUnit's output, as core's feed tests do.
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$out = (string) ob_get_clean();
		}

		return $out;
	}

	// The status source.

	public function test_read_groups_by_status_through_a_registered_meta_source(): void {
		$this->assertContains( 'read', Grouped_Archive::grouped_kinds(), 'The Site Editor preview groups read posts.' );
		$id = $this->read( 'Salt and Paper', '2026-08-28 10:00:00', 'to-read' );

		$group = Grouped_Archive::group_of_post( 'read', $id );
		$this->assertNotNull( $group );
		$this->assertSame( 'to-read', $group->key() );
		$this->assertSame( '', Grouped_Archive::group_of_post( 'read', $this->read( 'Untitled Margins', '2026-08-15 10:00:00', null ) )->key(), 'A read with no status row files under the empty group, whatever default the key registers.' );
	}

	public function test_the_archive_sections_editor_offers_a_heading_field_per_status(): void {
		$this->assertSame(
			\PKIW\read_status_labels(),
			\PKIW\Kind_Archive_Layouts::archive_sections_groups()['read'] ?? null
		);
	}

	// Shelves.

	public function test_shelves_run_reading_to_read_finished_abandoned_then_other(): void {
		$finished_first  = $this->read( 'Field Notes on Moss', '2026-08-10 10:00:00', 'finished' );
		$finished_second = $this->read( 'Winter Ledger', '2026-08-10 10:00:00', 'finished' );
		$this->read( 'Copper Atlas', '2026-08-30 10:00:00', 'finished' );
		$this->read( 'Untitled Margins', '2026-08-29 10:00:00', null );
		$this->read( 'The Long Detour', '2026-08-21 10:00:00', 'abandoned' );
		$this->read( 'The Quiet Orchard', '2026-08-19 10:00:00', 'to-read' );
		$this->read( 'The Lantern Index', '2026-08-18 10:00:00', 'reading' );
		$this->read( 'Salt and Paper', '2026-08-20 10:00:00', 'reading' );
		$this->assertGreaterThan( $finished_first, $finished_second );

		$shelves = $this->shelves( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ 'reading', 'to-read', 'finished', 'abandoned', '' ], array_column( $shelves, 'group' ) );
		$this->assertSame( [ 'h2:Currently Reading', 'h2:To Read', 'h2:Finished', 'h2:Abandoned', 'h2:Other' ], array_column( $shelves, 'heading' ), 'The plugin labels, and "Other" for the template\'s empty emptyLabel.' );
		$this->assertSame( [ 'pkiw-group-reading', 'pkiw-group-to-read', 'pkiw-group-finished', 'pkiw-group-abandoned', 'pkiw-group-empty' ], array_column( $shelves, 'id' ) );
		$this->assertSame(
			[
				[ 'Salt and Paper', 'The Lantern Index' ],
				[ 'The Quiet Orchard' ],
				[ 'Copper Atlas', 'Winter Ledger', 'Field Notes on Moss' ],
				[ 'The Long Detour' ],
				[ 'Untitled Margins' ],
			],
			array_column( $shelves, 'titles' ),
			'Newest first within a shelf, and the later ID first on the same date.'
		);
	}

	public function test_a_status_the_map_lacks_shelves_after_abandoned_under_its_stored_value(): void {
		$this->read( 'The Long Detour', '2026-08-21 10:00:00', 'abandoned' );
		$paused = $this->read( 'Glass Meridian', '2026-08-28 10:00:00', 'reading' );
		$this->read( 'Untitled Margins', '2026-08-15 10:00:00', null );
		// The meta sanitizer stores only the four statuses; an import writing the row directly can store another.
		global $wpdb;
		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => 'paused' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			[
				'post_id'  => $paused,
				'meta_key' => '_pkiw_read_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		);
		wp_cache_delete( $paused, 'post_meta' );

		$shelves = $this->shelves( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ 'h2:Abandoned', 'h2:paused', 'h2:Other' ], array_column( $shelves, 'heading' ) );
	}

	public function test_twelve_reads_fill_a_page_and_a_shelf_crossing_the_break_repeats_its_label(): void {
		$this->fourteen();

		$first = $this->shelves( $this->serve( $this->archive_url() ) );
		global $wp_query;
		$this->assertSame( 12, (int) $wp_query->get( 'posts_per_page' ), 'The marker\'s linesPerPage sizes the page.' );
		$this->assertSame( 14, $wp_query->found_posts );
		$this->assertSame( 2, $wp_query->max_num_pages );
		$this->assertSame( [ 'h2:Currently Reading', 'h2:To Read', 'h2:Finished', 'h2:Abandoned' ], array_column( $first, 'heading' ) );
		$this->assertSame( [ 'The Long Detour' ], $first[3]['titles'] );

		$second = $this->shelves( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame( 14, $GLOBALS['wp_query']->found_posts, 'Grouping leaves the count alone.' );
		$this->assertSame( 2, $GLOBALS['wp_query']->post_count );
		$this->assertSame( [ 'h2:Abandoned', 'h2:Other' ], array_column( $second, 'heading' ), 'No heading for a shelf with no reads on the page; Abandoned continues, so it opens page 2 again.' );
		$this->assertSame( [ [ 'Rain Almanac' ], [ 'Untitled Margins' ] ], array_column( $second, 'titles' ) );
	}

	// The A to Z view.

	public function test_the_order_var_is_public_and_takes_only_author(): void {
		$this->assertContains( Read_Archive::QUERY_VAR, apply_filters( 'query_vars', [] ) );
		$this->fourteen();

		foreach ( [ 'title', 'AUTHOR', 'author ' ] as $value ) {
			$shelves = $this->shelves( $this->serve( add_query_arg( Read_Archive::QUERY_VAR, rawurlencode( $value ), $this->archive_url() ) ) );
			$this->assertSame( [ 'h2:Currently Reading', 'h2:To Read', 'h2:Finished', 'h2:Abandoned' ], array_column( $shelves, 'heading' ), "'{$value}' leaves the shelves on." );
		}
	}

	public function test_a_to_z_lists_reads_by_author_then_title_with_empty_authors_last_and_no_shelves(): void {
		$this->read( 'Untitled Margins', '2026-08-30 10:00:00', 'reading' );
		$this->read( 'Kindred Lines', '2026-08-29 10:00:00', 'finished', 'Octavia Brandt' );
		$this->read( 'Record of a Spaceborn Week', '2026-08-28 10:00:00', 'to-read', 'becky chalmers' );
		$this->read( 'Anonymous Pamphlet', '2026-08-27 10:00:00', 'finished', '' );
		$this->read( 'The Dispossessed Harbor', '2026-08-26 10:00:00', 'abandoned', 'ursula Lane' );
		$this->read( 'A Closed and Common Orbit Road', '2026-08-25 10:00:00', 'reading', 'Becky Chalmers' );
		$this->read( 'Second Copy', '2026-08-24 10:00:00', 'reading', '  ' );

		$html  = $this->serve( add_query_arg( Read_Archive::QUERY_VAR, 'author', $this->archive_url() ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 0, $xpath->query( '//section[@data-pkiw-sections]' )->length, 'The A to Z view has no shelves.' );
		$this->assertSame( 0, $xpath->query( '//*[contains(@class, "pkiw-group__heading")]' )->length );
		$items = $xpath->query( '//ul[contains(@class, "wp-block-post-template")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $items, 'Core\'s Post Template prints the list.' );
		$this->assertSame(
			[
				'A Closed and Common Orbit Road',
				'Record of a Spaceborn Week',
				'Kindred Lines',
				'The Dispossessed Harbor',
				'Anonymous Pamphlet',
				'Second Copy',
				'Untitled Margins',
			],
			$this->titles( $xpath, $items ),
			'Case-insensitive author, then title; a blank, spaces-only or missing author sorts last, by title.'
		);
		$this->assertSame( 7, $xpath->query( '//li//h2[contains(@class, "pk-title")]' )->length, 'Item titles stay h2 with no shelf heading above them.' );

		global $wp_query;
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ), 'pkiw_archive_group_source returns null for read in this view.' );
	}

	public function test_a_to_z_sorts_by_the_book_title_falling_back_to_the_post_title(): void {
		$book = $this->read( 'Aardvark draft', '2026-08-30 10:00:00', 'reading', 'Ines Okafor' );
		update_post_meta( $book, '_pkiw_read_title', 'Zebra Days' );
		$card_less = $this->read( 'Moss and Ink', '2026-08-29 10:00:00', 'reading', 'Ines Okafor' );
		delete_post_meta( $card_less, '_pkiw_read_title' );
		$lantern = $this->read( 'Lantern Hours', '2026-08-28 10:00:00', 'reading', 'Ines Okafor' );

		$this->serve( add_query_arg( Read_Archive::QUERY_VAR, 'author', $this->archive_url() ) );
		global $wp_query;

		$this->assertSame(
			[ $lantern, $card_less, $book ],
			array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ),
			"Lantern Hours, Moss and Ink (no book title, so its post title), then Zebra Days (post titled 'Aardvark draft')."
		);
	}

	/**
	 * Post passwords. Two spaces is a password to core, since
	 * post_password_required() tests empty(), but under the PAD SPACE
	 * collation utf8mb4_unicode_520_ci it compares equal to ''.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function protected_passwords(): array {
		return [
			'a password'           => [ 'hunter2' ],
			'a two-space password' => [ '  ' ],
		];
	}

	/**
	 * @dataProvider protected_passwords
	 *
	 * @param string $password Post password.
	 */
	public function test_a_protected_read_sorts_by_its_post_title_with_no_author_whatever_it_hides( string $password ): void {
		wp_set_current_user( 0 );
		$alice   = $this->read( 'Copper Atlas', '2026-08-30 10:00:00', 'finished', 'Alice Ames' );
		$carol   = $this->read( 'Harbor Weather', '2026-08-29 10:00:00', 'reading', 'Carol Cole' );
		$erin    = $this->read( 'Small Hours', '2026-08-28 10:00:00', 'to-read', 'Erin Eck' );
		$moss    = $this->read( 'Moss and Ink', '2026-08-27 10:00:00', 'finished' );
		$margins = $this->read( 'Untitled Margins', '2026-08-26 10:00:00', 'reading' );
		$locked  = $this->read( 'Locked Post', '2026-08-25 10:00:00', 'finished', 'Bob Baker' );
		wp_update_post(
			[
				'ID'            => $locked,
				'post_password' => $password,
			]
		);
		$this->assertSame( $password, get_post( $locked )->post_password, 'wp_update_post() stores the password as given.' );
		$this->assertTrue( post_password_required( $locked ), 'An anonymous visitor has no password cookie.' );

		$url = add_query_arg( Read_Archive::QUERY_VAR, 'author', $this->archive_url() );
		foreach (
			[
				[ 'Bob Baker', 'Aardvark Secrets' ],
				[ 'Dan Dorn', 'Zephyr Notes' ],
				[ 'Zoe Zane', 'Middle Book' ],
				[ null, 'Zephyr Notes' ],
				[ null, 'Aardvark Secrets' ],
			] as [ $author, $book ]
		) {
			delete_post_meta( $locked, '_pkiw_read_author' );
			if ( null !== $author ) {
				add_post_meta( $locked, '_pkiw_read_author', $author );
			}
			update_post_meta( $locked, '_pkiw_read_title', $book );

			$this->serve( $url );
			$this->assertSame(
				[ $alice, $carol, $erin, $locked, $moss, $margins ],
				array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ),
				sprintf( "Author '%s' and book '%s' stay hidden: 'Locked Post' sorts with the no-author reads, by post title.", (string) $author, $book )
			);
		}
	}

	/**
	 * @dataProvider protected_passwords
	 *
	 * @param string $password Post password.
	 */
	public function test_a_protected_read_shows_only_its_title_on_the_shelves_in_rest_and_in_the_feed( string $password ): void {
		wp_set_current_user( 0 );
		$open   = $this->read( 'Harbor Weather', '2026-08-29 10:00:00', 'finished', 'Carol Cole' );
		$locked = $this->read( 'Locked Post', '2026-08-25 10:00:00', 'finished', 'Bob Baker' );
		wp_update_post(
			[
				'ID'           => $open,
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Harbor Weather","authorName":"Carol Cole","readStatus":"finished"} /-->',
			]
		);
		wp_update_post(
			[
				'ID'            => $locked,
				'post_content'  => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Aardvark Secrets","authorName":"Bob Baker","readStatus":"finished"} /-->',
				'post_password' => $password,
			]
		);
		update_post_meta( $locked, '_pkiw_read_title', 'Aardvark Secrets' );
		$this->assertSame( $password, get_post( $locked )->post_password );

		// The shelves: the protected read keeps its status shelf, as fixture
		// P1 does, and prints its title link alone.
		$html    = $this->serve( $this->archive_url() );
		$shelves = $this->shelves( $html );
		$this->assertSame( [ 'finished' ], array_column( $shelves, 'group' ) );
		$this->assertSame( 'h2:Finished', $shelves[0]['heading'] );
		$this->assertSame( [ 'Harbor Weather', 'Protected: Locked Post' ], $shelves[0]['titles'] );
		$this->assertSame( 1, $this->xpath( $html )->query( '//article[contains(concat(" ", normalize-space(@class), " "), " pk-card--protected ")]' )->length );
		$this->assertStringContainsString( 'Carol Cole', $html, 'The open read prints its author, so the next two checks can fail.' );
		$this->assertStringNotContainsString( 'Bob Baker', $html );
		$this->assertStringNotContainsString( 'Aardvark Secrets', $html );

		// REST in grouped order: both on the finished shelf, newest first,
		// and the protected read's content and excerpt withheld.
		$items = $this->rest_reads_by_group();
		$this->assertSame( [ $open, $locked ], array_keys( $items ) );
		$this->assertStringContainsString( 'Carol Cole', $items[ $open ]['content']['rendered'] );
		$this->assertTrue( $items[ $locked ]['content']['protected'] );
		$this->assertSame( '', $items[ $locked ]['content']['rendered'] );
		$this->assertTrue( $items[ $locked ]['excerpt']['protected'] );
		$this->assertSame( '', $items[ $locked ]['excerpt']['rendered'] );

		// The feed.
		$feed = $this->read_feed_document();
		$this->assertStringContainsString( 'Carol Cole', $feed, 'The open read\'s card is in its feed item.' );
		$this->assertStringContainsString( 'Locked Post', $feed );
		$this->assertStringNotContainsString( 'Bob Baker', $feed );
		$this->assertStringNotContainsString( 'Aardvark Secrets', $feed );
	}

	public function test_the_a_to_z_view_keeps_twelve_per_page_the_count_and_the_var_in_the_pager(): void {
		$this->fourteen();
		$url = add_query_arg( Read_Archive::QUERY_VAR, 'author', $this->archive_url() );

		$html = $this->serve( $url );
		global $wp_query;
		$this->assertSame( 12, (int) $wp_query->get( 'posts_per_page' ), 'linesPerPage still sizes the page with grouping off.' );
		$this->assertSame( 12, $wp_query->post_count );
		$this->assertSame( 14, $wp_query->found_posts );

		$links = (string) paginate_links( [ 'total' => (int) $wp_query->max_num_pages ] );
		$this->assertStringContainsString( 'page/2/?' . Read_Archive::QUERY_VAR . '=author', $links, 'paginate_links keeps the var.' );
		$next = $this->xpath( $html )->query( '//a[contains(@class, "wp-block-query-pagination-next")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $next );
		$this->assertStringContainsString( 'page/2/?' . Read_Archive::QUERY_VAR . '=author', $next->getAttribute( 'href' ), 'The template\'s pager keeps the var.' );

		$this->serve( add_query_arg( Read_Archive::QUERY_VAR, 'author', $this->archive_url() . 'page/2/' ) );
		$this->assertSame( 2, $GLOBALS['wp_query']->post_count );
		$this->assertSame( 14, $GLOBALS['wp_query']->found_posts );
	}

	public function test_an_explicit_orderby_or_another_kind_leaves_the_a_to_z_clause_out(): void {
		$this->read( 'Kindred Lines', '2026-08-29 10:00:00', 'finished', 'Octavia Brandt' );

		$this->serve( add_query_arg( [ Read_Archive::QUERY_VAR => 'author', 'orderby' => 'title' ], $this->archive_url() ) );
		global $wp_query;
		$this->assertStringNotContainsString( '_pkiw_read_author', $wp_query->request );

		$eat = get_term_link( 'eat', Taxonomy::TAXONOMY );
		$this->assertIsString( $eat );
		$this->go_to( add_query_arg( Read_Archive::QUERY_VAR, 'author', $eat ) );
		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, 'eat' ) );
		$this->assertStringNotContainsString( '_pkiw_read_author', $GLOBALS['wp_query']->request );
	}

	// The feed.

	public function test_the_feed_stays_newest_first_in_both_views(): void {
		$ids  = $this->fourteen();
		$feed = get_term_feed_link( get_term_by( 'slug', 'read', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/read/feed/', $feed );

		foreach ( [ $feed, add_query_arg( Read_Archive::QUERY_VAR, 'author', $feed ) ] as $url ) {
			$this->go_to( $url );
			$wp_query = $GLOBALS['wp_query'];
			$this->assertTrue( is_feed(), $url );
			$this->assertSame( array_slice( array_values( $ids ), 0, count( $wp_query->posts ) ), wp_list_pluck( $wp_query->posts, 'ID' ), "{$url} lists the newest read first." );
			$this->assertStringNotContainsString( '_pkiw_read_author', $wp_query->request, $url );
			$this->assertStringNotContainsString( '_pkiw_read_status', $wp_query->request, $url );
		}
	}
}

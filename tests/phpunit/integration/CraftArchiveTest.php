<?php
/**
 * The craft archive: sections by craft type, verbatim (issue 249).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Craft_Archive;
use PKIW\Grouped_Archive;
use PKIW\Kind_Archive_Layouts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * /kind/craft/ is served by the plugin's taxonomy-kind-craft template. Its
 * loop groups craft posts by `_pkiw_craft_type` into sections, one H2 per
 * type, in the type's stored case. Twelve posts fill a page, and a section
 * that crosses the break repeats its H2 on the next page. Posts with no type
 * and password-protected posts file under the template's empty label. The
 * feed keeps date order. Names, craft types and times are invented.
 *
 * @group integration
 */
final class CraftArchiveTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Fixture key => post ID.
	 *
	 * @var array<string, int>
	 */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'posts_per_page', 20 );
		update_option( 'posts_per_rss', 30 );
		wp_set_current_user( 0 );
		// Pretty permalinks, as the site runs: /kind/craft/page/2/. The kind
		// taxonomy registered before the structure was set, so add its permastruct.
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A craft post.
	 *
	 * @param string      $key    Fixture key.
	 * @param string      $title  Post title; '' for none.
	 * @param string      $date   Post date.
	 * @param string|null $type   `_pkiw_craft_type`, or null for no row.
	 * @param int|null    $mins   `_pkiw_craft_minutes`, or null for no row.
	 * @param string      $status Post status.
	 */
	private function craft( string $key, string $title, string $date, ?string $type, ?int $mins = null, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => $status,
				'post_title'  => $title,
				'post_date'   => $date,
			]
		);
		wp_set_object_terms( $id, 'craft', Taxonomy::TAXONOMY );
		if ( null !== $type ) {
			add_post_meta( $id, '_pkiw_craft_type', $type );
		}
		if ( null !== $mins ) {
			add_post_meta( $id, '_pkiw_craft_minutes', (string) $mins );
		}
		$this->ids[ $key ] = $id;

		return $id;
	}

	/**
	 * Write a stored type the plugin's own writes can't: sanitize_text_field()
	 * trims on every update_post_meta() and sync, so padding takes a raw row.
	 *
	 * @param string $key   Fixture key.
	 * @param string $title Post title.
	 * @param string $date  Post date.
	 * @param string $raw   Stored value, padding included.
	 * @param int    $mins  Minutes.
	 */
	private function padded( string $key, string $title, string $date, string $raw, int $mins ): int {
		$id = $this->craft( $key, $title, $date, null, $mins );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->postmeta,
			[
				'post_id'    => $id,
				'meta_key'   => '_pkiw_craft_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $raw, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $id, 'post_meta' );
		$this->assertSame( $raw, get_metadata_raw( 'post', $id, '_pkiw_craft_type', true ), 'The padding is stored.' );

		return $id;
	}

	/**
	 * The seventeen published craft posts, an X01 private and an X02 draft.
	 *
	 * Page 1 (12): [3D printing] D01 | [Bookbinding] B02, B01 | [Knitting]
	 * K01, K02, K04, K05, K03 | [Pottery] P01 | [woodworking] W01, W02, W03.
	 * Page 2 (5): [woodworking] W04, L01 | [Craft] E02, E01, E03.
	 */
	private function seed(): void {
		$this->craft( 'D01', 'Spool Holder', '2026-08-22 10:00:00', '3D printing', 360 );
		$this->craft( 'B01', 'Hand-Sewn Notebook', '2026-08-15 10:00:00', 'Bookbinding' );
		$this->craft( 'B02', 'Zine Binding Jig', '2026-08-30 09:55:00', 'Bookbinding', 90 );
		$this->craft( 'K01', 'Wool Scarf', '2026-08-30 10:00:00', 'Knitting', 1080 );
		$this->craft( 'K02', 'Ribbed Hat', '2026-08-20 10:00:00', 'Knitting', 150 );
		$this->craft( 'K03', 'Fingerless Mitts', '2026-07-28 10:00:00', 'knitting' );
		$this->padded( 'K04', 'Cabled Cowl', '2026-08-12 10:00:00', ' Knitting ', 0 );
		$this->craft( 'K05', 'A Seed-Stitch Baby Blanket That Took Most Of The Winter And Two Extra Skeins To Finish Properly', '2026-08-05 10:00:00', 'Knitting' );
		$this->padded( 'P01', 'Thrown Mug', '2026-07-20 10:00:00', '  Pottery  ', 240 );
		$this->craft( 'W01', 'Plant Stand', '2026-08-18 10:00:00', 'woodworking', 600 );
		$this->craft( 'W02', 'Cutting Board', '2026-08-10 10:00:00', 'woodworking' );
		$this->craft( 'W03', 'Spoon Carving', '2026-07-30 10:00:00', 'woodworking', 45 );
		$this->craft( 'W04', 'Step Stool', '2026-07-15 10:00:00', 'woodworking' );
		$this->craft( 'L01', 'Workbench Build', '2026-07-10 10:00:00', 'woodworking', 1200 );
		$this->craft( 'E01', 'Practice Swatches', '2026-08-01 10:00:00', null, 30 );
		$this->craft( 'E02', '', '2026-08-30 09:54:00', null );
		$e03 = $this->craft( 'E03', 'Locked Quilt Square', '2026-07-25 10:00:00', 'Knitting', 120 );
		wp_update_post(
			[
				'ID'            => $e03,
				'post_password' => 'seed-249',
			]
		);
		$this->craft( 'X01', 'Private Embroidery Hoop', '2026-08-08 10:00:00', 'Embroidery', 45, 'private' );
		$this->craft( 'X02', 'Unfinished Draft', '2026-08-09 10:00:00', 'Weaving', 45, 'draft' );
	}

	private function archive_url(): string {
		$url = get_term_link( 'craft', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/craft/', $url );

		return $url;
	}

	/**
	 * Visit a URL and render the block template it resolves to.
	 *
	 * @param string $url           URL.
	 * @param string $empty_replace Replacement for the template's emptyLabel attribute; null keeps the shipped one.
	 */
	private function serve( string $url, ?string $empty_replace = null ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-craft.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-craft', $template->id, 'The plugin\'s craft template serves.' );

		$content = $template->content;
		if ( null !== $empty_replace ) {
			$content = str_replace( '"emptyLabel":"Craft"', '"emptyLabel":"' . $empty_replace . '"', $content );
			$this->assertNotSame( $template->content, $content, 'The template carries the emptyLabel the test replaces.' );
		}

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $content;

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
	 * Each section: group key, heading tag and text, heading id and item titles.
	 *
	 * @param string $html Rendered page.
	 * @return array<int, array{group:string, heading:string, id:string, titles:string[]}>
	 */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$titles  = [];
			foreach ( $xpath->query( './/li//*[contains(concat(" ", normalize-space(@class), " "), " pk-title ")]', $section ) as $title ) {
				$titles[] = trim( $title->textContent );
			}
			$out[] = [
				'group'   => $section->getAttribute( 'data-pkiw-group' ),
				'heading' => $heading instanceof DOMElement ? $heading->nodeName . ':' . trim( $heading->textContent ) : '',
				'id'      => $heading instanceof DOMElement ? $heading->getAttribute( 'id' ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	/**
	 * Fixture keys for a list of IDs.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return string[]
	 */
	private function keys_of( array $post_ids ): array {
		$by_id = array_flip( $this->ids );

		return array_map( static fn( int $id ): string => $by_id[ $id ] ?? ( '#' . $id ), array_map( 'intval', $post_ids ) );
	}

	/**
	 * The REST posts route's craft posts, in the order the route returns them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rest_craft(): array {
		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ get_term_by( 'slug', 'craft', Taxonomy::TAXONOMY )->term_id ] );
		$request->set_param( 'orderby', 'pkiw_group' );
		$request->set_param( 'per_page', 100 );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (array) $response->get_data();
	}

	// The source.

	public function test_craft_groups_through_a_registered_verbatim_meta_source_with_no_legacy_row(): void {
		$this->assertContains( 'craft', Grouped_Archive::grouped_kinds(), 'The Site Editor preview groups craft posts.' );
		$source = Grouped_Archive::source( Craft_Archive::SOURCE );
		$this->assertNotNull( $source );
		$this->assertSame( 'craft-type', $source->id() );
		$this->assertTrue( $source->files_protected_as_empty(), 'The G10 flag is on.' );
		$this->assertSame( '', $source->legacy_key(), 'A registered source, not the legacy menu path that capitalizes labels.' );
		$this->assertArrayNotHasKey( 'craft', Kind_Archive_Layouts::group_fields(), 'No group-fields row: the legacy path would print "Woodworking" and turn on Recent Specials.' );
	}

	public function test_the_source_files_each_post_under_its_stored_type_case_kept(): void {
		$this->seed();

		$this->assertSame( 'woodworking', Grouped_Archive::group_of_post( 'craft', $this->ids['W01'] )->raw() );
		$this->assertSame( 'Knitting', Grouped_Archive::group_of_post( 'craft', $this->ids['K01'] )->raw() );
		$this->assertSame( 'knitting', Grouped_Archive::group_of_post( 'craft', $this->ids['K03'] )->raw(), 'The post keeps its own case; the section prints its first post\'s.' );
		$this->assertSame( Grouped_Archive::group_of_post( 'craft', $this->ids['K01'] )->key(), Grouped_Archive::group_of_post( 'craft', $this->ids['K03'] )->key(), 'Case variants share a group.' );
		$this->assertSame( '', Grouped_Archive::group_of_post( 'craft', $this->ids['E01'] )->key(), 'No type row files under the empty group.' );
		$this->assertSame( '', Grouped_Archive::group_of_post( 'craft', $this->ids['E03'] )->key(), 'G10: a protected post files under the empty group, whatever it stores.' );
	}

	// The page map.

	public function test_page_one_holds_twelve_posts_in_five_sections_with_verbatim_case_kept_headings(): void {
		$this->seed();

		$html     = $this->serve( $this->archive_url() );
		$sections = $this->sections( $html );

		global $wp_query;
		$this->assertSame( 12, (int) $wp_query->get( 'posts_per_page' ), 'The marker\'s linesPerPage sizes the page.' );
		$this->assertSame( 17, $wp_query->found_posts, 'Private, draft and nothing else are left out.' );
		$this->assertSame( 2, $wp_query->max_num_pages );
		$this->assertSame(
			[ 'h2:3D printing', 'h2:Bookbinding', 'h2:Knitting', 'h2:Pottery', 'h2:woodworking' ],
			array_column( $sections, 'heading' ),
			'A to Z by stored value; a digit sorts first; "woodworking" keeps its lowercase.'
		);
		$this->assertSame(
			[ 'pkiw-group-3d-printing', 'pkiw-group-bookbinding', 'pkiw-group-knitting', 'pkiw-group-pottery', 'pkiw-group-woodworking' ],
			array_column( $sections, 'id' )
		);
		$this->assertSame(
			[
				[ 'Spool Holder' ],
				[ 'Zine Binding Jig', 'Hand-Sewn Notebook' ],
				[ 'Wool Scarf', 'Ribbed Hat', 'Cabled Cowl', 'A Seed-Stitch Baby Blanket That Took Most Of The Winter And Two Extra Skeins To Finish Properly', 'Fingerless Mitts' ],
				[ 'Thrown Mug' ],
				[ 'Plant Stand', 'Cutting Board', 'Spoon Carving' ],
			],
			array_column( $sections, 'titles' ),
			'Newest first within a section. "knitting" (K03) files with "Knitting"; the padded rows file with their trimmed type.'
		);
		$this->assertSame( 12, array_sum( array_map( 'count', array_column( $sections, 'titles' ) ) ) );
		$this->assertSame( 1, substr_count( $html, '<h1' ), 'One h1.' );
	}

	public function test_page_two_repeats_the_woodworking_heading_and_ends_with_the_craft_section(): void {
		$this->seed();

		$sections = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );

		global $wp_query;
		$this->assertSame( 17, $wp_query->found_posts );
		$this->assertSame( 5, $wp_query->post_count );
		$this->assertSame( [ 'h2:woodworking', 'h2:Craft' ], array_column( $sections, 'heading' ), 'A section crossing the break repeats its H2; the empty group sorts last under the template\'s emptyLabel.' );
		$this->assertSame( [ 'woodworking', '' ], array_column( $sections, 'group' ) );
		$this->assertSame(
			[
				[ 'Step Stool', 'Workbench Build' ],
				[ 'Craft, August 30, 2026', 'Practice Swatches', 'Protected: Locked Quilt Square' ],
			],
			array_column( $sections, 'titles' ),
			'E02 is untitled and takes the kind and date; E03 is protected and keeps only its title link; E03 is the oldest, so it sorts last.'
		);
	}

	public function test_a_third_page_is_a_404(): void {
		$this->seed();

		$this->serve( $this->archive_url() . 'page/3/' );

		$this->assertTrue( is_404() );
	}

	public function test_page_two_is_200(): void {
		$this->seed();

		$this->serve( $this->archive_url() . 'page/2/' );

		$this->assertFalse( is_404() );
		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, 'craft' ) );
		$this->assertTrue( is_paged() );
	}

	public function test_each_card_prints_its_type_and_time_from_the_registered_reader(): void {
		$this->seed();

		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );
		$line  = static function ( DOMXPath $xpath, string $title ): ?DOMElement {
			$node = $xpath->query( '//article[.//*[contains(concat(" ", normalize-space(@class), " "), " pk-title ")][normalize-space(.)="' . $title . '"]]//p[contains(concat(" ", normalize-space(@class), " "), " pk-craft-facts ")]' )->item( 0 );

			return $node instanceof DOMElement ? $node : null;
		};

		$scarf = $line( $xpath, 'Wool Scarf' );
		$this->assertInstanceOf( DOMElement::class, $scarf, 'K01 prints the craft line the Stream card builds from the facts.' );
		$this->assertSame( 'Knitting', trim( $xpath->query( './/span[@class="pk-craft-type"]', $scarf )->item( 0 )->textContent ) );
		$this->assertSame( 'PT18H', $xpath->query( './/time', $scarf )->item( 0 )->getAttribute( 'datetime' ) );

		$notebook = $line( $xpath, 'Hand-Sewn Notebook' );
		$this->assertInstanceOf( DOMElement::class, $notebook );
		$this->assertSame( 'Bookbinding', trim( $xpath->query( './/span[@class="pk-craft-type"]', $notebook )->item( 0 )->textContent ) );
		$this->assertSame( 0, $xpath->query( './/time', $notebook )->length, 'No time stored, no time printed.' );
	}

	// Padded stored values.

	public function test_rows_written_with_wpdb_insert_with_padding_file_under_the_trimmed_type(): void {
		$this->seed();
		global $wpdb;
		$raw = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (%d, %d) ORDER BY post_id", '_pkiw_craft_type', $this->ids['K04'], $this->ids['P01'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( [ ' Knitting ', '  Pottery  ' ], $raw, 'The rows hold their padding.' );

		$sections = $this->sections( $this->serve( $this->archive_url() ) );

		$this->assertContains( 'h2:Knitting', array_column( $sections, 'heading' ) );
		$this->assertContains( 'h2:Pottery', array_column( $sections, 'heading' ) );
		$this->assertNotContains( 'h2:', array_column( $sections, 'heading' ) );
		$knitting = array_values( array_filter( $sections, static fn( array $s ): bool => 'knitting' === $s['group'] ) );
		$this->assertCount( 1, $knitting, 'The padded and lowercase variants add no section.' );
		$this->assertContains( 'Cabled Cowl', $knitting[0]['titles'] );
		$this->assertContains( 'Fingerless Mitts', $knitting[0]['titles'] );
		$this->assertSame( 'h2:Knitting', $knitting[0]['heading'], 'The H2 prints the newest post\'s stored value, trimmed.' );
	}

	public function test_a_type_of_spaces_alone_files_under_the_empty_group(): void {
		$this->seed();
		$this->padded( 'S01', 'Blank Type', '2026-08-29 10:00:00', '   ', 20 );

		$this->serve( $this->archive_url() . 'page/2/' );
		global $wp_query;
		$keys = $this->keys_of( wp_list_pluck( $wp_query->posts, 'ID' ) );

		$this->assertContains( 'S01', $keys, 'A type of spaces is no type.' );
		$last = array_slice( $keys, -4 );
		$this->assertContains( 'E01', $last );
	}

	// The empty group.

	public function test_the_empty_group_reads_other_when_the_template_sets_no_empty_label(): void {
		$this->seed();

		$sections = $this->sections( $this->serve( $this->archive_url() . 'page/2/', '' ) );

		$this->assertSame( [ 'h2:woodworking', 'h2:Other' ], array_column( $sections, 'heading' ) );
	}

	public function test_a_protected_post_files_under_the_empty_group_and_not_under_its_stored_type(): void {
		$this->seed();

		$page_one = $this->sections( $this->serve( $this->archive_url() ) );
		$knitting = array_values( array_filter( $page_one, static fn( array $s ): bool => 'knitting' === $s['group'] ) );
		$this->assertNotContains( 'Protected: Locked Quilt Square', $knitting[0]['titles'], 'E03 stores Knitting and does not file there.' );

		$page_two = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );
		$craft    = array_values( array_filter( $page_two, static fn( array $s ): bool => '' === $s['group'] ) );
		$this->assertSame( 'h2:Craft', $craft[0]['heading'] );
		$this->assertContains( 'Protected: Locked Quilt Square', $craft[0]['titles'] );
	}

	public function test_a_protected_post_prints_only_its_title_link_and_none_of_its_facts(): void {
		$this->seed();
		$e03 = $this->ids['E03'];
		wp_update_post(
			[
				'ID'           => $e03,
				'post_content' => '<!-- wp:post-kinds-indieweb/craft-card {"craftType":"Knitting","timeMinutes":120} /--><!-- wp:paragraph --><p>Hidden quilt notes.</p><!-- /wp:paragraph -->',
			]
		);

		$html = $this->serve( $this->archive_url() . 'page/2/' );

		$this->assertSame( 1, $this->xpath( $html )->query( '//article[contains(concat(" ", normalize-space(@class), " "), " pk-card--protected ")]' )->length );
		$this->assertStringContainsString( 'Protected: Locked Quilt Square', $html );
		$this->assertStringNotContainsString( 'Hidden quilt notes', $html );
		$this->assertStringNotContainsString( 'pk-craft-facts', substr( $html, (int) strpos( $html, 'Locked Quilt Square' ) - 400, 800 ), 'No craft type or time beside the protected title.' );
	}

	public function test_logged_out_the_private_and_draft_posts_and_their_types_never_appear(): void {
		$this->seed();

		$page_one = $this->serve( $this->archive_url() );
		$page_two = $this->serve( $this->archive_url() . 'page/2/' );

		foreach ( [ $page_one, $page_two ] as $html ) {
			$this->assertStringNotContainsString( 'Private Embroidery Hoop', $html );
			$this->assertStringNotContainsString( 'Embroidery', $html );
			$this->assertStringNotContainsString( 'Unfinished Draft', $html );
			$this->assertStringNotContainsString( 'Weaving', $html );
		}
		$this->go_to( $this->archive_url() );
		$this->assertNotContains( $this->ids['X01'], array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
	}

	// The feed.

	public function test_the_feed_runs_newest_first_with_every_published_post_and_none_of_the_hidden_ones(): void {
		$this->seed();
		$feed = get_term_feed_link( get_term_by( 'slug', 'craft', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/craft/feed/', $feed );

		$this->go_to( $feed );
		$this->assertTrue( is_feed() );
		global $wp_query;

		$this->assertSame(
			[ 'K01', 'B02', 'E02', 'D01', 'K02', 'W01', 'B01', 'K04', 'W02', 'K05', 'E01', 'W03', 'K03', 'E03', 'P01', 'W04', 'L01' ],
			$this->keys_of( wp_list_pluck( $wp_query->posts, 'ID' ) ),
			'Date descending, with no grouping and no page size of 12.'
		);
		$this->assertStringNotContainsString( 'pkiw_craft_type', $wp_query->request, 'The feed query carries no grouping clause.' );
	}

	// REST.

	public function test_rest_orderby_pkiw_group_returns_the_sections_in_order(): void {
		$this->seed();

		$data = $this->rest_craft();

		$this->assertSame(
			[ 'D01', 'B02', 'B01', 'K01', 'K02', 'K04', 'K05', 'K03', 'P01', 'W01', 'W02', 'W03', 'W04', 'L01', 'E02', 'E01', 'E03' ],
			$this->keys_of( wp_list_pluck( $data, 'id' ) ),
			'Grouped order: type A to Z, newest first, the empty group last (E03 protected among it).'
		);
	}

	public function test_rest_withholds_a_protected_posts_content_excerpt_and_craft_meta(): void {
		$this->seed();
		wp_update_post(
			[
				'ID'           => $this->ids['E03'],
				'post_content' => '<!-- wp:paragraph --><p>Hidden quilt notes.</p><!-- /wp:paragraph -->',
			]
		);

		$items = array_column( $this->rest_craft(), null, 'id' );
		$open  = $items[ $this->ids['K01'] ];
		$item  = $items[ $this->ids['E03'] ];

		// The open post's meta comes back, so the absence below means something.
		$this->assertSame( 'Knitting', $open['meta']['_pkiw_craft_type'] ?? null );
		$this->assertSame( 1080, $open['meta']['_pkiw_craft_minutes'] ?? null );

		$this->assertTrue( $item['content']['protected'] );
		$this->assertSame( '', $item['content']['rendered'] );
		$this->assertTrue( $item['excerpt']['protected'] );
		$this->assertSame( '', $item['excerpt']['rendered'] );
		$this->assertArrayNotHasKey( '_pkiw_craft_type', (array) ( $item['meta'] ?? [] ), 'REST redacts a protected post\'s kind meta for someone who can\'t edit it.' );
		$this->assertArrayNotHasKey( '_pkiw_craft_minutes', (array) ( $item['meta'] ?? [] ) );
		$this->assertStringNotContainsString( 'Knitting', (string) wp_json_encode( $item ), 'Its stored type is nowhere in the response.' );
	}

	public function test_both_craft_keys_are_registered_for_rest(): void {
		$keys = get_registered_meta_keys( 'post', 'post' );

		$this->assertSame( 'string', $keys['_pkiw_craft_type']['type'] ?? null );
		$this->assertSame( 'integer', $keys['_pkiw_craft_minutes']['type'] ?? null );
		$this->assertNotEmpty( $keys['_pkiw_craft_type']['show_in_rest'] ?? false );
		$this->assertNotEmpty( $keys['_pkiw_craft_minutes']['show_in_rest'] ?? false );
	}

	// The editor preview.

	public function test_the_block_renderer_preview_opens_each_section_on_the_same_post_the_page_does(): void {
		$this->seed();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->go_to( $this->archive_url() );

		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-craft.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$blocks   = parse_blocks( $template->content );
		$marker   = null;
		$walk     = static function ( array $list ) use ( &$walk, &$marker ): void {
			foreach ( $list as $block ) {
				if ( 'post-kinds-indieweb/archive-sections' === $block['blockName'] ) {
					$marker = $block;
					return;
				}
				$walk( $block['innerBlocks'] ?? [] );
			}
		};
		$walk( $blocks );
		$this->assertNotNull( $marker );

		$opened = [];
		foreach ( [ 'D01', 'B02', 'B01', 'K01', 'K02', 'K04', 'K05', 'K03', 'P01', 'W01', 'W02', 'W03' ] as $key ) {
			$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/archive-sections' );
			$request->set_param( 'context', 'edit' );
			$request->set_param( 'attributes', $marker['attrs'] );
			$request->set_param( 'post_id', $this->ids[ $key ] );
			$request->set_param( 'pkiw_kind', 'craft' );
			$response                  = rest_get_server()->dispatch( $request );
			$GLOBALS['wp_rest_server'] = null;
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$html = (string) ( $response->get_data()['rendered'] ?? '' );
			if ( 1 === preg_match( '#<h2[^>]*>([^<]*)</h2>#', $html, $m ) ) {
				$opened[ $key ] = trim( $m[1] );
			}
		}

		$this->assertSame(
			[
				'D01' => '3D printing',
				'B02' => 'Bookbinding',
				'K01' => 'Knitting',
				'P01' => 'Pottery',
				'W01' => 'woodworking',
			],
			$opened
		);
	}

	// The code carries no craft names.

	public function test_no_craft_type_name_appears_in_the_code_beyond_the_shipped_term_description(): void {
		$pattern = '/knitting|woodworking|bookbinding|pottery/i';
		$found   = [];
		foreach ( [ 'includes', 'src', 'templates', 'patterns' ] as $dir ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PKIW_PATH . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$lines = file( $file->getPathname() );
				foreach ( (array) $lines as $number => $line ) {
					if ( 1 === preg_match( $pattern, (string) $line ) ) {
						$found[] = substr( $file->getPathname(), strlen( PKIW_PATH ) ) . ':' . ( $number + 1 );
					}
				}
			}
		}
		sort( $found );

		// The plan's `git grep` over includes/ src/ templates/ patterns/ matches
		// class-taxonomy.php's shipped description only. Two lines in files the
		// health-rule lane owns match too: an example in a meta description and a
		// docblock example. They aren't craft logic and this lane doesn't own
		// them; list them here so a third match fails, and remove them with the fix.
		$known = [
			'includes/class-meta-fields.php',
			'includes/class-taxonomy.php',
			'includes/functions-stream-card.php',
		];
		$files = array_values( array_unique( array_map( static fn( string $hit ): string => explode( ':', $hit )[0], $found ) ) );

		$this->assertSame( $known, $files, 'A craft type name in code: ' . implode( ', ', $found ) );
		$this->assertCount( 1, array_filter( $found, static fn( string $hit ): bool => str_starts_with( $hit, 'includes/class-taxonomy.php:' ) ), 'The term description is the one match in class-taxonomy.php.' );
	}

	public function test_the_craft_archive_class_names_no_craft_type(): void {
		$source = (string) file_get_contents( PKIW_PATH . 'includes/class-craft-archive.php' );

		$this->assertDoesNotMatchRegularExpression( '/knitting|woodworking|bookbinding|pottery|embroider|weaving|3d print/i', $source );
	}
}

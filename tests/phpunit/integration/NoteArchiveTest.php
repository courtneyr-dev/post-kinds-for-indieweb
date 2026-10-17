<?php
/**
 * The note archive: week sections on 12-note pages (issue 265).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Note_Archive;
use PKIW\Taxonomy;

/**
 * /kind/note/ is served by the plugin's taxonomy-kind-note template: the
 * archive-sections marker sets 12 notes a page and groups them by the week of
 * the post date, and each note prints through the note-entry block. The page
 * keeps count paging; nothing about the week source turns on calendar paging.
 *
 * @group integration
 */
final class NoteArchiveTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Timezone active before each test.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	/**
	 * Start of week active before each test.
	 *
	 * @var mixed
	 */
	private $original_start_of_week = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet    = get_stylesheet();
		$this->original_timezone      = (string) get_option( 'timezone_string' );
		$this->original_start_of_week = get_option( 'start_of_week' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'start_of_week', 0 );
		update_option( 'posts_per_page', 5 );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'start_of_week', $this->original_start_of_week );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_the_main_query_takes_twelve_posts_in_date_order_grouped_by_the_week_source(): void {
		$this->seed_pages();

		$this->serve( $this->archive_url() );
		global $wp_query;

		$this->assertSame( 12, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( 'post_week', (string) $wp_query->get( 'pkiw_group_source' ) );
		$this->assertSame( [ 'date' => 'DESC', 'ID' => 'DESC' ], $wp_query->get( 'orderby' ) );
		$this->assertEmpty( $wp_query->get( 'pkiw_calendar' ), 'The week source never turns on calendar paging.' );
		$this->assertCount( 12, $wp_query->posts );
		$this->assertSame( 3, (int) $wp_query->max_num_pages );
		$this->assertSame( 28, (int) $wp_query->found_posts );
	}

	public function test_pages_two_and_three_resolve_and_a_page_past_the_end_is_a_404(): void {
		$this->seed_pages();

		$page_two = $this->serve( $this->archive_url() . 'page/2/' );
		global $wp_query;
		$this->assertFalse( $wp_query->is_404() );
		$this->assertCount( 12, $wp_query->posts );
		$this->assertNotSame( '', $page_two );

		$this->serve( $this->archive_url() . 'page/3/' );
		$this->assertFalse( $GLOBALS['wp_query']->is_404() );
		$this->assertCount( 4, $GLOBALS['wp_query']->posts );

		$this->go_to( $this->archive_url() . 'page/4/' );
		$this->assertTrue( $GLOBALS['wp_query']->is_404() );
	}

	public function test_a_week_that_runs_across_a_page_opens_the_next_page_under_the_same_plain_heading(): void {
		$this->seed_pages();

		$one = $this->headings( $this->serve( $this->archive_url() ) );
		$two = $this->headings( $this->serve( $this->archive_url() . 'page/2/' ) );

		$this->assertSame( 'August 23–29, 2026', $one[0] );
		$this->assertSame( 'August 16–22, 2026', $one[ array_key_last( $one ) ] );
		$this->assertSame( $one[ array_key_last( $one ) ], $two[0], 'The week continues on page 2 under its plain label.' );
		foreach ( array_merge( $one, $two ) as $label ) {
			$this->assertStringNotContainsString( 'continued', strtolower( $label ) );
		}
	}

	/**
	 * @return array<string, array{int, string, string, string}>
	 */
	public function week_label_cases(): array {
		return [
			'sunday start, one month'  => [ 0, '2026-08-26 10:00:00', 'August 23–29, 2026', 'sunday' ],
			'monday start, one month'  => [ 1, '2026-08-26 10:00:00', 'August 24–30, 2026', 'monday' ],
			'sunday start, two months' => [ 0, '2026-07-29 10:00:00', 'July 26 – August 1, 2026', 'sunday' ],
			'monday start, two months' => [ 1, '2026-07-29 10:00:00', 'July 27 – August 2, 2026', 'monday' ],
			'sunday start, two years'  => [ 0, '2025-12-31 10:00:00', 'December 28, 2025 – January 3, 2026', 'sunday' ],
			'monday start, two years'  => [ 1, '2025-12-31 10:00:00', 'December 29, 2025 – January 4, 2026', 'monday' ],
		];
	}

	/**
	 * @dataProvider week_label_cases
	 */
	public function test_week_headings_read_the_same_month_cross_month_and_cross_year_forms( int $start_of_week, string $date, string $label ): void {
		update_option( 'start_of_week', $start_of_week );
		$this->note( 'Lone note', $date, 'Body.' );

		$this->assertSame( [ $label ], $this->headings( $this->serve( $this->archive_url() ) ) );
	}

	public function test_a_page_runs_exactly_one_posts_query(): void {
		$this->seed_pages();
		$queries = [];
		$count   = static function ( WP_Query $query ) use ( &$queries ): void {
			$type = $query->get( 'post_type' );
			if ( in_array( $type, [ 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles' ], true ) ) {
				return;
			}
			$queries[] = $query;
		};
		add_action( 'pre_get_posts', $count, 99 );

		$html = $this->serve( $this->archive_url() );

		remove_action( 'pre_get_posts', $count, 99 );
		$this->assertStringContainsString( 'pkiw-note-entry', $html );
		$this->assertCount( 1, $queries, 'Only the main query reads posts; the render adds none.' );
		$this->assertTrue( $queries[0]->is_main_query() );
	}

	public function test_private_and_draft_notes_are_absent_logged_out(): void {
		$this->note( 'Public note', '2026-08-26 10:00:00', 'Body.' );
		$private = $this->note( 'Private note', '2026-08-25 10:00:00', 'Body.', [ 'post_status' => 'private' ] );
		$draft   = $this->note( 'Draft note', '2026-08-24 10:00:00', 'Body.', [ 'post_status' => 'draft' ] );

		$html = $this->serve( $this->archive_url() );

		$this->assertStringContainsString( 'Public note', $html );
		$this->assertStringNotContainsString( 'Private note', $html );
		$this->assertStringNotContainsString( 'Draft note', $html );
		$this->assertStringNotContainsString( 'post-' . $private, $html );
		$this->assertStringNotContainsString( 'post-' . $draft, $html );
	}

	public function test_an_empty_archive_prints_no_section_and_the_no_results_paragraph(): void {
		$html = $this->serve( $this->archive_url() );

		$this->assertStringNotContainsString( 'pkiw-group', $html );
		$this->assertStringNotContainsString( 'pkiw-note-entry', $html );
		$this->assertStringNotContainsString( 'wp-block-query-pagination', $html );
		$this->assertStringContainsString( 'Nothing here yet.', $html );
	}

	public function test_the_feed_takes_the_feed_page_size_and_no_grouping_vars(): void {
		update_option( 'posts_per_rss', 7 );
		$this->seed_pages();

		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'note', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		global $wp_query;

		$this->assertTrue( is_feed() );
		$this->assertSame( 7, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_by' ) );
		$this->assertLessThanOrEqual( 7, count( $wp_query->posts ) );
		$ids    = array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) );
		$sorted = $ids;
		usort(
			$sorted,
			static function ( int $a, int $b ): int {
				$date = strcmp( get_post_field( 'post_date', $b ), get_post_field( 'post_date', $a ) );
				return 0 !== $date ? $date : $b <=> $a;
			}
		);
		$this->assertSame( $sorted, $ids, 'Newest first.' );
	}

	public function test_an_untitled_unprotected_note_only_post_is_named_as_untitled_name_names_it(): void {
		$ids = [
			$this->note( '', '2026-08-26 10:00:00', 'A thought that is short.' ),
			$this->note( '', '2026-08-25 10:00:00', '<!-- wp:paragraph --><p>A block <strong>thought</strong> with markup &amp; an entity.</p><!-- /wp:paragraph -->' ),
			$this->note( '', '2026-08-24 10:00:00', '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->' ),
			$this->note( '', '2026-08-23 10:00:00', '<!-- wp:image --><figure><img src="https://example.test/a.jpg" alt=""/></figure><!-- /wp:image -->' ),
			$this->note( '', '2026-08-22 10:00:00', str_repeat( 'long ', 60 ) ),
			$this->note( '', '2026-08-21 10:00:00', 'Body ignored.', [ 'post_excerpt' => 'The stored excerpt wins.' ] ),
		];

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$this->assertSame( \PKIW\untitled_name( $post ), Note_Archive::parts( $post )['name'], "post {$id}" );
		}
	}

	public function test_the_served_archive_prints_each_note_once_in_its_week_section(): void {
		$a = $this->note( 'Garden gate', '2026-08-26 10:00:00', 'Body.' );
		$b = $this->note( '', '2026-08-18 10:00:00', 'Seed order arrived.' );

		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$this->assertSame( 2, $xpath->query( '//section[contains(concat(" ",@class," ")," pkiw-group ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//section[h2]//li[contains(@class,"post-' . $a . '")]//div[contains(concat(" ",@class," ")," pkiw-note-entry ")]' )->length );
		$this->assertSame( 1, $xpath->query( '//section[h2]//li[contains(@class,"post-' . $b . '")]//div[contains(concat(" ",@class," ")," pkiw-note-entry ")]' )->length );
		$this->assertSame( 2, $xpath->query( '//li[contains(concat(" ",@class," ")," h-entry ")]' )->length, 'The list item is the h-entry.' );
		$this->assertSame( 2, $xpath->query( '//h3[contains(concat(" ",@class," ")," pkiw-note-entry__title ")]' )->length );
		$this->assertTrue( Grouped_Archive::is_entry_block( 'post-kinds-indieweb/archive-sections' ) );
	}

	/**
	 * Twenty-eight notes: a newest week of 4, a week of 12, a week of 8 and 4 more.
	 *
	 * Page 1 holds the first week and 8 of the second; page 2 opens on the
	 * rest of the second week, then the third week and a part of the fourth;
	 * page 3 holds the last 4.
	 */
	private function seed_pages(): void {
		$weeks = [
			'2026-08-26' => 4,
			'2026-08-19' => 12,
			'2026-08-12' => 8,
			'2026-08-05' => 4,
		];
		foreach ( $weeks as $day => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$this->note( "Note {$day} #{$i}", sprintf( '%s %02d:00:00', $day, 20 - $i ), "Body {$day} {$i}." );
			}
		}
	}

	/**
	 * @param array<string, mixed> $extra Extra post args.
	 */
	private function note( string $title, string $date, string $body, array $extra = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $body,
					'post_excerpt' => '',
					'post_date'    => $date,
				],
				$extra
			)
		);
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function archive_url(): string {
		$url = get_term_link( 'note', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/note/', $url );

		return $url;
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-note.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Section heading texts, in page order.
	 *
	 * @return string[]
	 */
	private function headings( string $html ): array {
		$out = [];
		foreach ( $this->xpath( $html )->query( '//section[contains(concat(" ",@class," ")," pkiw-group ")]/h2' ) as $heading ) {
			$out[] = trim( $heading->textContent );
		}

		return $out;
	}
}

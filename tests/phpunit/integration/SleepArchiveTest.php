<?php
/**
 * The sleep archive: one sheet per month, 31 nights a page (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Sleep_Archive;
use PKIW\Taxonomy;

/**
 * /kind/sleep/ is served by the plugin sleep template and the month source:
 * months newest first, nights oldest first inside a month, one cell per post.
 * A private or draft post never reaches a logged-out reader's sheet, count
 * or feed. A protected night is listed as a date, a moon and a phase only,
 * and the kind feed leaves it out.
 *
 * @group integration
 */
final class SleepArchiveTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private int $original_posts_per_page = 0;

	private int $author = 0;

	/**
	 * Fixture IDs by name.
	 *
	 * @var array<string, int>
	 */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet     = get_stylesheet();
		$this->original_timezone       = (string) get_option( 'timezone_string' );
		$this->original_posts_per_page = (int) get_option( 'posts_per_page' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 5 );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		$this->author = (int) self::factory()->user->create( [ 'role' => 'author', 'display_name' => 'Example Author' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'posts_per_page', $this->original_posts_per_page );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $attrs Card attributes, or null for a card-less post.
	 */
	private function card( ?array $attrs ): string {
		if ( null === $attrs ) {
			return 'Fictional note: quiet night.';
		}

		return [] === $attrs
			? '<!-- wp:post-kinds-indieweb/sleep-card /-->'
			: '<!-- wp:post-kinds-indieweb/sleep-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * @param array<string, mixed>|null $attrs Card attributes.
	 * @param array<string, mixed>      $post  Post overrides.
	 */
	private function sleep( string $key, string $night, ?array $attrs, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_author'  => $this->author,
					'post_title'   => '',
					'post_name'    => 'sleep-' . strtolower( $key ),
					'post_date'    => $night . ' 12:00:00',
					'post_content' => $this->card( $attrs ),
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'sleep', Taxonomy::TAXONOMY );
		$this->ids[ $key ] = (int) $id;

		return (int) $id;
	}

	/**
	 * August's 16 nights (one protected), July's 24 and June's 2: 42 published
	 * posts, all 42 on a logged-out sheet.
	 */
	private function seed(): void {
		$this->sleep( 'Z01', '2026-08-03', [] );
		$this->sleep( 'C01', '2026-08-04', null );
		$this->sleep( 'L01', '2026-08-05', [ 'durationMinutes' => 355 ], [ 'post_title' => 'A fictional night of thunder over Example Lake while the cat refused to settle down at all' ] );
		$this->sleep(
			'X01',
			'2026-08-08',
			[
				'durationMinutes'   => 430,
				'bedtime'           => '2026-08-08T23:40',
				'wakeTime'          => '2026-08-09T06:30',
				'showTimesPublicly' => true,
			],
			[
				'post_password' => 'example-pass',
				'post_title'    => 'Secret night',
				'post_excerpt'  => 'Secret excerpt text',
			]
		);
		$this->sleep(
			'H01',
			'2026-08-09',
			[
				'durationMinutes' => 420,
				'bedtime'         => '2026-08-09T23:10',
				'wakeTime'        => '2026-08-10T06:10',
			]
		);
		$this->sleep( 'N01', '2026-08-10', [ 'durationMinutes' => 420 ] );
		$this->sleep(
			'T01',
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			],
			[ 'post_title' => 'Early night' ]
		);
		$this->sleep( 'M01', '2026-08-12', [ 'durationMinutes' => 425, 'origin' => 'micropub' ] );
		foreach ( [ 16 => 432, 17 => 408, 18 => 455, 19 => 482, 20 => 440, 21 => 494, 22 => 462 ] as $day => $minutes ) {
			$this->sleep( 'W' . ( $day - 15 ), sprintf( '2026-08-%02d', $day ), [ 'durationMinutes' => $minutes ] );
		}
		$this->sleep( 'NAP', '2026-08-22', [ 'durationMinutes' => 65 ], [ 'post_title' => 'Afternoon nap' ] );
		foreach ( range( 1, 24 ) as $n ) {
			$this->sleep( sprintf( 'J%02d', $n ), sprintf( '2026-07-%02d', $n ), [ 'durationMinutes' => 360 + ( ( $n * 37 ) % 180 ) ] );
		}
		$this->sleep( 'U01', '2026-06-06', [ 'durationMinutes' => 401 ] );
		$this->sleep( 'U02', '2026-06-21', [ 'durationMinutes' => 468 ] );

		$this->sleep( 'D01', '2026-08-06', [ 'durationMinutes' => 300 ], [ 'post_status' => 'draft' ] );
		$this->sleep( 'P01', '2026-08-07', [ 'durationMinutes' => 300 ], [ 'post_status' => 'private' ] );
		$note = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Plain note', 'post_date' => '2026-10-11 10:00:00' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
	}

	private function archive_url(): string {
		$url = get_term_link( 'sleep', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/sleep/', $url );

		return $url;
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-sleep.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-sleep', $template->id );

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
	 * @return array<int, array{0: string, 1: int}> Heading text and cell count per section, in page order.
	 */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[contains(concat(" ",@class," ")," pkiw-sleep-archive__month ")]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$out[]   = [
				trim( (string) $heading?->textContent ),
				$xpath->query( './ol[contains(concat(" ",@class," ")," pkiw-sleep-sheet ")]/li', $section )->length,
			];
		}

		return $out;
	}

	/**
	 * The post IDs of a page's cells in DOM order.
	 *
	 * @return int[]
	 */
	private function cell_ids( string $html ): array {
		$out = [];
		foreach ( $this->xpath( $html )->query( '//ol[contains(@class,"pkiw-sleep-sheet")]/li' ) as $li ) {
			$classes = explode( ' ', (string) $li->getAttribute( 'class' ) );
			foreach ( $classes as $class ) {
				if ( preg_match( '/^post-(\d+)$/', $class, $m ) ) {
					$out[] = (int) $m[1];
				}
			}
		}

		return $out;
	}

	public function test_the_sheet_pages_by_the_month_with_31_nights_a_page(): void {
		$this->seed();
		$one = $this->serve( $this->archive_url() );
		global $wp_query;

		$this->assertSame( 31, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( 42, (int) $wp_query->found_posts, '42 published, the protected night included.' );
		$this->assertSame( 2, (int) $wp_query->max_num_pages );
		$this->assertSame( [ [ 'August 2026', 16 ], [ 'July 2026', 15 ] ], $this->sections( $one ) );

		$two = $this->serve( $this->archive_url() . 'page/2/' );
		$this->assertSame( [ [ 'July 2026', 9 ], [ 'June 2026', 2 ] ], $this->sections( $two ), 'July repeats its heading on page 2: 31, then 11.' );

		$this->serve( $this->archive_url() . 'page/3/' );
		$this->assertTrue( is_404() );
	}

	public function test_months_run_newest_first_and_nights_oldest_first_with_an_id_tiebreak(): void {
		$this->seed();
		$page = $this->cell_ids( $this->serve( $this->archive_url() ) );

		$expected_august = [ 'Z01', 'C01', 'L01', 'X01', 'H01', 'N01', 'T01', 'M01', 'W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'NAP' ];
		$this->assertSame( array_map( fn( string $k ): int => $this->ids[ $k ], $expected_august ), array_slice( $page, 0, 16 ), 'W7 then NAP: the same night, ID order.' );
		$this->assertSame( $this->ids['J01'], $page[16] );
		$this->assertSame( $this->ids['J15'], $page[30] );

		$later = $this->cell_ids( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame( array_map( fn( int $n ): int => $this->ids[ sprintf( 'J%02d', $n ) ], range( 16, 24 ) ), array_slice( $later, 0, 9 ) );
		$this->assertSame( [ $this->ids['U01'], $this->ids['U02'] ], array_slice( $later, 9 ) );
	}

	public function test_a_private_or_draft_night_never_reaches_a_logged_out_sheet(): void {
		$this->seed();
		$all = array_merge( $this->cell_ids( $this->serve( $this->archive_url() ) ), $this->cell_ids( $this->serve( $this->archive_url() . 'page/2/' ) ) );

		foreach ( [ 'D01', 'P01' ] as $key ) {
			$this->assertNotContains( $this->ids[ $key ], $all, $key );
		}
		$this->assertCount( 42, $all );
	}

	/**
	 * Every string a protected night must keep off the page.
	 *
	 * @return string[]
	 */
	private function secret_strings(): array {
		return [ 'Secret night', 'Secret excerpt', 'Protected', 'example-pass', '7 hr 10 min', 'PT7H10M', '430 min', '>430<', '"430"', '23:40', '06:30', '11:40 pm', '6:30 am', 'T23:40', 'T06:30' ];
	}

	public function test_a_protected_night_prints_its_date_moon_and_phase_only_logged_out(): void {
		$this->seed();
		$html = $this->serve( $this->archive_url() );

		$xpath = $this->xpath( $html );
		$cell  = $xpath->query( '//ol[contains(@class,"pkiw-sleep-sheet")]/li[contains(concat(" ",@class," ")," post-' . $this->ids['X01'] . ' ")]' )->item( 0 );
		$this->assertNotNull( $cell, 'The protected night is on the sheet.' );
		$markup = (string) $cell->ownerDocument->saveHTML( $cell );

		$this->assertStringContainsString( 'datetime="2026-08-08"', $markup );
		$this->assertStringContainsString( 'Aug 8', $markup );
		$this->assertStringContainsString( 'Waning crescent', $markup );
		foreach ( [ 'p-sleep', 'pkiw-sleep-night__bar', 'pkiw-sleep-night__duration', 'pkiw-sleep-night__title', '<h' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $markup, $needle );
		}
		// None of its secret strings is anywhere on the page.
		foreach ( $this->secret_strings() as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
		// The cell's one link goes to the post, which asks for the password.
		$this->assertSame( 1, $xpath->query( './/a[@href]', $cell )->length );
		$this->assertStringContainsString( '/sleep-x01/', (string) $xpath->query( './/a[@href]', $cell )->item( 0 )->getAttribute( 'href' ) );
	}

	public function test_a_wrong_password_cookie_changes_nothing_on_the_protected_cell(): void {
		$this->seed();
		$logged_out = $this->serve( $this->archive_url() );

		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = wp_hash_password( 'wrong-password' );
		try {
			$html = $this->serve( $this->archive_url() );
			$this->assertTrue( post_password_required( get_post( $this->ids['X01'] ) ), 'The wrong cookie does not open the post.' );
			$this->assertSame( [], \PKIW\Sleep_Archive::facts( $this->ids['X01'] ) );
		} finally {
			unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		}

		foreach ( $this->secret_strings() as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
		$this->assertSame( $this->cell_ids( $logged_out ), $this->cell_ids( $html ) );
	}

	public function test_a_protected_nights_cell_matches_a_night_with_no_card_apart_from_ids_url_and_date(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );
		$cell  = function ( string $key ) use ( $xpath ): string {
			$li  = $xpath->query( '//ol[contains(@class,"pkiw-sleep-sheet")]/li[contains(concat(" ",@class," ")," post-' . $this->ids[ $key ] . ' ")]' )->item( 0 );
			$out = (string) $li->ownerDocument->saveHTML( $li );
			$out = (string) preg_replace( '/post-\d+|pkiw-sleep-([tdhp])-\d+/', 'N', $out );
			$out = (string) preg_replace( '#href="[^"]*"|datetime="[^"]*"|value="[^"]*"#', 'X', $out );
			$out = (string) preg_replace( '/>(?:Jul|Aug|Jun) \d+</', '>D<', $out );
			$out = (string) preg_replace( '/>(?:Jul|Aug|Jun) \d+<span/', '>D<span', $out );
			$out = (string) preg_replace( '/(?:post-password-required|kind-sleep|category-uncategorized|status-publish|format-standard|hentry|type-post|h-entry|post)\s*/', '', $out );
			$out = (string) preg_replace( '/(?:Waning (?:crescent|gibbous)|Last quarter)/', 'P', $out );

			return (string) preg_replace( '/\s+/', ' ', $out );
		};

		$this->assertSame( $cell( 'Z01' ), $cell( 'X01' ) );
	}

	public function test_the_kind_feed_orders_newest_first_and_drops_the_protected_night(): void {
		$this->seed();
		update_option( 'posts_per_rss', 100 );
		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'sleep', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		global $wp_query;

		$this->assertTrue( is_feed() && $wp_query->is_tax( 'kind', 'sleep' ) );
		$ids = array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) );

		$this->assertNotContains( $this->ids['X01'], $ids );
		$this->assertNotContains( $this->ids['D01'], $ids );
		$this->assertNotContains( $this->ids['P01'], $ids );
		$this->assertCount( 41, $ids, 'The kind feed leaves the protected night out.' );
		$this->assertSame( $this->ids['NAP'], $ids[0], 'Newest first: NAP, created after W7 on the same night, has the higher ID.' );
		$this->assertSame( $this->ids['W7'], $ids[1] );
		$this->assertStringContainsString( "ORDER BY {$GLOBALS['wpdb']->posts}.post_date DESC, {$GLOBALS['wpdb']->posts}.ID DESC", $wp_query->request );
	}

	public function test_the_site_feed_treats_a_protected_sleep_night_as_core_does_for_every_kind(): void {
		$this->seed();
		$protected_note = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Locked note', 'post_password' => 'pw', 'post_date' => '2026-10-12 10:00:00' ] );
		wp_set_object_terms( $protected_note, 'note', Taxonomy::TAXONOMY );
		update_option( 'posts_per_rss', 100 );

		$this->go_to( get_feed_link() );
		global $wp_query;
		$this->assertTrue( is_feed() && ! $wp_query->is_tax() );
		$ids = array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) );

		$this->assertContains( $this->ids['X01'], $ids, 'Core lists a protected item in a site feed.' );
		$this->assertContains( $protected_note, $ids );

		// Its feed content is core's password notice, never the card.
		$GLOBALS['post'] = get_post( $this->ids['X01'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		$content = (string) get_the_content_feed( 'rss2' ) . (string) get_the_excerpt( $this->ids['X01'] );
		wp_reset_postdata();
		foreach ( $this->secret_strings() as $needle ) {
			if ( 'Protected' === $needle ) {
				continue;
			}
			$this->assertStringNotContainsString( $needle, $content, $needle );
		}
	}

	public function test_the_html_archive_for_an_editor_shows_the_protected_night_as_a_frame_and_the_private_one(): void {
		$this->seed();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->serve( $this->archive_url() );
		$ids  = $this->cell_ids( $html );

		$this->assertContains( $this->ids['X01'], $ids );
		foreach ( [ 'Secret night', 'Secret excerpt', '7 hr 10 min', '23:40' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, 'An editor gets the same frame.' );
		}
		$this->assertContains( $this->ids['P01'], $ids, 'An editor reads the private night.' );
	}

	public function test_zero_sleep_posts_prints_no_sheet_and_no_heading(): void {
		$html = $this->serve( $this->archive_url() );

		$this->assertStringNotContainsString( 'pkiw-sleep-sheet', $html );
		$this->assertStringNotContainsString( 'pkiw-sleep-archive__month', $html );
		$this->assertStringContainsString( 'Nothing here yet.', $html );
		$this->assertSame( 0, $this->xpath( $html )->query( '//h2[contains(@class,"pkiw-group__heading")]' )->length );
	}

	public function test_the_text_greps_find_nothing_private_on_either_page(): void {
		$this->seed();
		$pages = $this->serve( $this->archive_url() ) . "\n" . $this->serve( $this->archive_url() . 'page/2/' );

		foreach ( [ 'Private', 'Public duration', 'bedtime', 'wake', 'WC ', 'FQ ', 'WG ', 'Fictional note', '23:10', '06:10', '21:45', '06:15' ] as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $pages, $needle );
		}
		$xpath = $this->xpath( $pages );
		foreach ( $xpath->query( '//ol[contains(@class,"pkiw-sleep-sheet")]' ) as $list ) {
			$text = $list->textContent;
			$this->assertStringNotContainsString( 'Sleep', $text, 'No kind label inside the sheet.' );
			$this->assertStringNotContainsString( 'Protected', $text );
			$this->assertStringNotContainsString( 'Duration', $text );
			$this->assertStringNotContainsString( 'Bedtime', $text );
		}
		$this->assertStringContainsString( 'Early night', $pages, 'T01 is opted in, and the sheet still prints no time.' );
	}

	public function test_page_one_parses_as_31_flat_h_entries_with_url_published_and_author(): void {
		$this->seed();
		$html   = $this->serve( $this->archive_url() );
		$parsed = \Mf2\parse( $html, 'http://example.org/kind/sleep/' );
		$items  = array_values( array_filter( $parsed['items'], static fn( array $item ): bool => in_array( 'h-entry', $item['type'], true ) ) );

		$this->assertCount( 31, $items );
		foreach ( $items as $item ) {
			$this->assertArrayNotHasKey( 'children', $item, 'No h-entry nests another.' );
			$this->assertNotEmpty( $item['properties']['url'] ?? [] );
			$this->assertNotEmpty( $item['properties']['published'] ?? [] );
			$this->assertNotEmpty( $item['properties']['author'] ?? [] );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) $item['properties']['published'][0], 'Date-only published.' );
		}
		$with_sleep = array_filter( $items, static fn( array $item ): bool => ! empty( $item['properties']['sleep'] ) );
		$this->assertCount( 28, $with_sleep, 'Every night but the three with no public duration carries one.' );
	}

	public function test_each_cell_holds_one_visible_link_and_every_accessible_name_is_unique(): void {
		$this->seed();
		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );
		$names = [];

		foreach ( $xpath->query( '//ol[contains(@class,"pkiw-sleep-sheet")]/li' ) as $li ) {
			$links = $xpath->query( './/a[@href][not(ancestor-or-self::*[@hidden])]', $li );
			$this->assertSame( 1, $links->length );
			$this->assertSame( 0, $xpath->query( './/a[@tabindex="-1"][not(ancestor::*[@hidden])]', $li )->length );
			$link = $links->item( 0 );
			$name = [];
			foreach ( explode( ' ', (string) $link->getAttribute( 'aria-labelledby' ) ) as $ref ) {
				$node   = $xpath->query( '//*[@id="' . $ref . '"]' )->item( 0 );
				$name[] = $node ? trim( (string) $node->textContent ) : '';
			}
			$names[] = trim( (string) preg_replace( '/\s+/', ' ', implode( ' ', $name ) ) );
		}

		$this->assertCount( 31, $names );
		$this->assertContains( 'Aug 8, Waning crescent', $names, 'X01 names itself by its date and phase.' );
		$this->assertSame( count( $names ), count( array_unique( $names ) ), 'Names: ' . implode( ' | ', $names ) );
		$this->assertContains( 'Aug 22, 7 hr 42 min, Waxing gibbous', $names, 'W7.' );
		$this->assertContains( 'Afternoon nap Aug 22, 1 hr 5 min, Waxing gibbous', $names, 'NAP names itself by its title.' );
	}

	public function test_a_night_with_no_duration_matches_one_that_stored_none_and_hidden_times_match_none(): void {
		$this->seed();
		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );
		$cell  = function ( string $key ) use ( $xpath, $html ): string {
			$li = $xpath->query( '//ol[contains(@class,"pkiw-sleep-sheet")]/li[contains(concat(" ",@class," ")," post-' . $this->ids[ $key ] . ' ")]' )->item( 0 );
			$this->assertNotNull( $li, $key );
			$out = $li->ownerDocument->saveHTML( $li );
			$out = (string) preg_replace( '/post-\d+/', 'post-N', $out );
			$out = (string) preg_replace( '/pkiw-sleep-([tdhp])-\d+/', 'pkiw-sleep-$1-N', $out );
			$out = (string) preg_replace( '#href="[^"]*"#', 'href="U"', $out );
			$out = (string) preg_replace( '/datetime="\d{4}-\d{2}-\d{2}"/', 'datetime="D"', $out );
			$out = (string) preg_replace( '/>Aug \d+</', '>Aug N<', $out );
			$out = (string) preg_replace( '/>Aug \d+<span/', '>Aug N<span', $out );
			$out = (string) preg_replace( '/value="[^"]*"/', 'value="V"', $out );
			$out = (string) preg_replace( '/(?:post-password-required|kind-sleep|category-uncategorized|status-publish|format-standard|hentry|type-post|has-post-thumbnail|post-N|h-entry)\s*/', '', $out );

			return (string) preg_replace( '/\s+/', ' ', $out );
		};

		$this->assertSame( $cell( 'Z01' ), $cell( 'C01' ), 'Z01 (a card with nothing) and C01 (no card) print the same cell.' );
		$this->assertSame( $cell( 'N01' ), $cell( 'H01' ), 'Hidden times print like a card that never stored them.' );
		$this->assertStringNotContainsString( 'pkiw-sleep-night__bar', $cell( 'Z01' ) );
		$this->assertStringNotContainsString( 'p-sleep', $cell( 'Z01' ) );
	}

	public function test_the_editor_preview_prints_the_month_sections_and_hides_a_protected_night_behind_a_frame(): void {
		$this->seed();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Sleep_Archive::ENTRY );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $this->ids['Z01'] );
		$request->set_param( 'attributes', [ 'linesPerPage' => 31 ] );
		$request->set_param( 'pkiw_kind', 'sleep' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$html = (string) $response->get_data()['rendered'];

		$this->assertStringContainsString( 'August 2026', $html );
		$this->assertStringContainsString( 'pkiw-sleep-sheet', $html );
		$this->assertStringNotContainsString( '23:10', $html );
		$this->assertStringNotContainsString( 'Secret night', $html );

		$xpath = $this->xpath( $html );
		$cell  = $xpath->query( '//ol[contains(@class,"pkiw-sleep-sheet")]/li[.//a[contains(@href,"sleep-x01")]]' )->item( 0 );
		$this->assertNotNull( $cell, 'The editor sees the protected night as a frame.' );
		$text = (string) $cell->textContent;
		$this->assertStringContainsString( 'Waning crescent', $text );
		$this->assertStringNotContainsString( 'Secret', $text );
		$markup = (string) $cell->ownerDocument->saveHTML( $cell );
		foreach ( [ 'p-sleep', 'pkiw-sleep-night__bar', 'pkiw-sleep-night__duration', '7 hr' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $markup, 'No duration for a protected night.' );
		}
	}

	public function test_the_month_source_is_the_kinds_default_and_the_entry_block_is_registered(): void {
		$this->assertContains( 'sleep', Grouped_Archive::grouped_kinds() );
		$this->assertTrue( Grouped_Archive::is_entry_block( 'post-kinds-indieweb/sleep-night' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'post-kinds-indieweb/sleep-night' ) );
	}
}

<?php
/**
 * The mood year grid block (issue 248).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Mood_Vocabulary;
use PKIW\Mood_Year_Grid;

/**
 * One calendar year of moods on a grid. Every post lands on its local day
 * (not the GMT day), a day with one mood is one link to it, a day with
 * several links to a card below the grid, and only date, code, label and
 * intensity print. Private, draft and password posts never appear.
 *
 * @group integration
 */
final class MoodYearGridTest extends WP_UnitTestCase {

	private const BLOCK = '<!-- wp:post-kinds-indieweb/mood-year-grid /-->';

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
		delete_option( 'pkiw_settings' );
		( new ReflectionProperty( Mood_Vocabulary::class, 'cache' ) )->setValue( null, [] );
		( new Meta_Fields() )->register_meta_fields();
		Mood_Vocabulary::register();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'start_of_week', 0 );
		update_option( 'time_format', 'g:i a' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A mood post.
	 *
	 * @param string               $date  Local post date.
	 * @param array<string, mixed> $attrs Mood card attributes.
	 * @param array<string, mixed> $post  Extra post fields.
	 */
	private function mood( string $date, array $attrs = [], array $post = [] ): int {
		$attrs   = array_merge( [ 'mood' => 'Happy', 'moodKey' => 'happy' ], $attrs );
		$content = '<!-- wp:post-kinds-indieweb/mood-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) . ' /-->';
		$id      = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'A mood',
					'post_content' => $content,
					'post_date'    => $date,
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'mood', 'kind' );

		return $id;
	}

	/**
	 * Visit a page of the mood archive and render the grid as the template would.
	 *
	 * @param int                  $page       Archive page.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render( int $page = 1, array $attributes = [] ): string {
		$this->visit( $page );

		return do_blocks( '<!-- wp:post-kinds-indieweb/mood-year-grid ' . ( [] === $attributes ? '' : wp_json_encode( $attributes ) . ' ' ) . '/-->' );
	}

	/**
	 * Request /kind/mood/ at a page with the plugin template serving.
	 */
	private function visit( int $page = 1 ): void {
		if ( ! term_exists( 'mood', 'kind' ) ) {
			wp_insert_term( 'Mood', 'kind', [ 'slug' => 'mood' ] );
		}
		$url = get_term_link( 'mood', 'kind' );
		$this->assertIsString( $url );
		$this->go_to( $page > 1 ? add_query_arg( 'paged', $page, $url ) : $url );
	}

	/**
	 * The HTML as a DOM.
	 */
	private function dom( string $html ): DOMXPath {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $doc );
	}

	/**
	 * An XPath for an element with a class.
	 */
	private static function cls( string $class, string $tag = '*' ): string {
		return '//' . $tag . '[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
	}

	/**
	 * The day cell for a date.
	 */
	private function cell( DOMXPath $xp, string $day ): ?DOMElement {
		$found = $xp->query( self::cls( 'pkiw-mood-day', 'div' ) . '[@data-day="' . $day . '"]' );
		$cell  = $found ? $found->item( 0 ) : null;

		return $cell instanceof DOMElement ? $cell : null;
	}

	// Placement.

	public function test_a_post_files_on_its_new_york_day_across_new_years_eve(): void {
		$this->mood( '2024-12-31 23:30:00' );
		$this->mood( '2025-01-01 00:30:00' );

		$xp = $this->dom( $this->render( 2 ) );
		$this->assertNotNull( $this->cell( $xp, '2024-12-31' ), 'Page 2 is 2024.' );
		$this->assertSame( 'joy', $this->cell( $xp, '2024-12-31' )->getAttribute( 'data-family' ) );
		$this->assertNull( $this->cell( $xp, '2025-01-01' ), 'January 1, 2025 is not on the 2024 page.' );
		$this->assertSame( '2024', trim( $xp->query( self::cls( 'pkiw-mood-year__year' ) )->item( 0 )->textContent ) );

		$xp = $this->dom( $this->render( 1 ) );
		$this->assertSame( 'joy', $this->cell( $xp, '2025-01-01' )->getAttribute( 'data-family' ) );
		$this->assertNull( $this->cell( $xp, '2024-12-31' ) );
	}

	public function test_a_post_files_on_its_tokyo_day_across_new_years_eve(): void {
		update_option( 'timezone_string', 'Asia/Tokyo' );
		// 00:30 local on January 1 is 15:30 GMT on December 31; 23:30 local on December 31 is 14:30 GMT.
		$this->mood( '2025-01-01 00:30:00', [], [ 'post_date_gmt' => '2024-12-31 15:30:00' ] );
		$this->mood( '2024-12-31 23:30:00', [], [ 'post_date_gmt' => '2024-12-31 14:30:00' ] );

		$xp = $this->dom( $this->render( 1 ) );
		$this->assertSame( '2025', trim( $xp->query( self::cls( 'pkiw-mood-year__year' ) )->item( 0 )->textContent ) );
		$this->assertNotNull( $this->cell( $xp, '2025-01-01' ), 'Local January 1 sits on 2025 though its GMT day is December 31.' );

		$xp = $this->dom( $this->render( 2 ) );
		$this->assertNotNull( $this->cell( $xp, '2024-12-31' ) );
		$this->assertNull( $this->cell( $xp, '2025-01-01' ) );
	}

	public function test_every_month_prints_its_real_length(): void {
		$this->mood( '2024-03-05 10:00:00' );
		$this->mood( '2025-03-05 10:00:00' );

		$xp   = $this->dom( $this->render( 2 ) );
		$days = $xp->query( self::cls( 'pkiw-mood-month__days', 'div' ) );
		$this->assertSame( 12, $days->length );
		$counts = [];
		foreach ( $days as $month ) {
			$counts[] = $xp->query( './/div[contains(@class, "pkiw-mood-day")]', $month )->length;
		}
		$this->assertSame( [ 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ], $counts, '2024 is a leap year.' );

		$xp     = $this->dom( $this->render( 1 ) );
		$counts = [];
		foreach ( $xp->query( self::cls( 'pkiw-mood-month__days', 'div' ) ) as $month ) {
			$counts[] = $xp->query( './/div[contains(@class, "pkiw-mood-day")]', $month )->length;
		}
		$this->assertSame( 28, $counts[1], 'February 2025 has 28 days.' );
		$this->assertSame( 30, $counts[3] );
	}

	public function test_an_empty_day_has_no_text_link_or_tab_stop(): void {
		$this->mood( '2026-03-05 10:00:00' );

		$xp    = $this->dom( $this->render() );
		$empty = $this->cell( $xp, '2026-03-04' );

		$this->assertNotNull( $empty );
		$this->assertSame( '', trim( $empty->textContent ) );
		$this->assertSame( 0, $xp->query( './/a|.//*[@tabindex]|.//button', $empty )->length );
		$this->assertFalse( $empty->hasAttribute( 'data-family' ) );
	}

	// One mood a day.

	public function test_a_one_mood_day_is_one_link_to_the_mood_named_with_its_visible_code(): void {
		$id = $this->mood( '2026-09-03 10:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy', 'intensity' => 4, 'note' => 'private-ish note', 'emoji' => '😊' ], [ 'post_title' => 'Anything' ] );
		update_post_meta( $id, '_pkiw_mood_rating', 4 );

		$html = $this->render();
		$xp   = $this->dom( $html );
		$cell = $this->cell( $xp, '2026-09-03' );

		$this->assertSame( 'joy', $cell->getAttribute( 'data-family' ) );
		$this->assertStringContainsString( 'pkiw-cal__day', $cell->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'h-entry', $cell->getAttribute( 'class' ) );
		$links = $xp->query( './/a', $cell );
		$this->assertSame( 1, $links->length );
		$link = $links->item( 0 );
		$this->assertSame( get_permalink( $id ), $link->getAttribute( 'href' ) );
		$this->assertStringContainsString( 'u-url', $link->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'pkiw-cal__link', $link->getAttribute( 'class' ) );
		$this->assertSame( 'September 3, 2026: Happy, intensity 4 of 5 (J4)', $link->getAttribute( 'aria-label' ) );
		$code = $xp->query( './/span[contains(@class, "pkiw-mood-day__code")]', $link )->item( 0 );
		$this->assertSame( 'J4', $code->textContent );
		$this->assertSame( 'true', $code->getAttribute( 'aria-hidden' ) );
		$this->assertStringContainsString( $code->textContent, $link->getAttribute( 'aria-label' ), 'The accessible name contains the visible text (label in name).' );
	}

	public function test_the_name_drops_the_digit_and_the_intensity_clause_without_an_intensity(): void {
		$this->mood( '2026-09-03 10:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ] );

		$xp   = $this->dom( $this->render() );
		$link = $xp->query( self::cls( 'pkiw-cal__link', 'a' ) )->item( 0 );

		$this->assertSame( 'September 3, 2026: Calm (C)', $link->getAttribute( 'aria-label' ) );
		$this->assertSame( 'C', $xp->query( self::cls( 'pkiw-mood-day__code' ) )->item( 0 )->textContent );
	}

	public function test_a_label_less_mood_is_named_mood_and_is_other(): void {
		$this->mood( '2026-09-03 10:00:00', [ 'mood' => '', 'moodKey' => '', 'emoji' => '😎' ] );

		$xp   = $this->dom( $this->render() );
		$cell = $this->cell( $xp, '2026-09-03' );

		$this->assertSame( 'other', $cell->getAttribute( 'data-family' ) );
		$this->assertSame( 'September 3, 2026: Mood (O)', $xp->query( './/a', $cell )->item( 0 )->getAttribute( 'aria-label' ) );
		$this->assertSame( 0, $xp->query( './/*[contains(@class, "p-name")]', $cell )->length, 'A synthetic name never gets p-name.' );
	}

	public function test_a_custom_typed_mood_takes_the_other_fill_and_a_filter_can_file_it(): void {
		$this->mood( '2026-09-03 10:00:00', [ 'mood' => 'Curious', 'moodKey' => '' ] );

		$this->assertSame( 'other', $this->cell( $this->dom( $this->render() ), '2026-09-03' )->getAttribute( 'data-family' ) );

		add_filter( 'pkiw_mood_family', static fn( $family, $key, $label ) => 'Curious' === $label ? 'thoughtful' : $family, 10, 3 );
		$this->assertSame( 'thoughtful', $this->cell( $this->dom( $this->render() ), '2026-09-03' )->getAttribute( 'data-family' ) );
	}

	public function test_the_cell_carries_only_date_code_label_and_intensity(): void {
		$this->mood( '2026-09-03 10:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy', 'intensity' => 4, 'note' => 'SECRET NOTE TEXT', 'emoji' => '🌟', 'moodAt' => '2026-09-03T09:15' ] );

		$html = $this->render();

		foreach ( [ 'SECRET NOTE TEXT', '🌟', '9:15', 'pk-kindlabel', 'p-content', 'pk-mood__note', 'pk-card', 'excerpt' ] as $cut ) {
			$this->assertStringNotContainsString( $cut, $html, $cut );
		}
		$xp = $this->dom( $html );
		$this->assertSame( 0, $xp->query( self::cls( 'p-content' ) )->length );
	}

	public function test_the_entry_carries_hidden_mf2_properties_beside_the_link(): void {
		$author = self::factory()->user->create( [ 'display_name' => 'Casey Author' ] );
		$id     = $this->mood( '2026-09-03 10:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy' ], [ 'post_author' => $author ] );

		$xp    = $this->dom( $this->render() );
		$cell  = $this->cell( $xp, '2026-09-03' );
		$props = $xp->query( './span[contains(@class, "pk-entry-props")]', $cell )->item( 0 );

		$this->assertNotNull( $props, 'Props sit beside the link, not inside it.' );
		$this->assertTrue( $props->hasAttribute( 'hidden' ) );
		$this->assertSame( 'Happy', $xp->query( './data[@class="p-name"]', $props )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( get_post_time( 'c', true, $id ), $xp->query( './data[@class="dt-published"]', $props )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'Casey Author', $xp->query( './/*[contains(@class, "p-author")]/*[contains(@class, "p-name")]', $props )->item( 0 )->textContent );
		$this->assertSame( 0, $xp->query( './/a', $props )->length, 'Nothing in the hidden props is a link.' );
		$this->assertSame( 0, $xp->query( './/a[contains(@class, "pkiw-cal__link")]//*[contains(@class, "pk-entry-props")]' )->length );
	}

	public function test_the_mf2_parser_reads_one_h_entry_per_mood_with_url_published_and_author(): void {
		$author = self::factory()->user->create( [ 'display_name' => 'Casey Author' ] );
		$one    = $this->mood( '2026-03-01 10:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy' ], [ 'post_author' => $author ] );
		$bare   = $this->mood( '2026-03-02 10:00:00', [ 'mood' => '', 'moodKey' => '' ], [ 'post_author' => $author ] );
		$busy_a = $this->mood( '2026-03-03 09:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ], [ 'post_author' => $author ] );
		$busy_b = $this->mood( '2026-03-03 15:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ], [ 'post_author' => $author ] );

		$parsed = \Mf2\parse( '<html><body>' . $this->render() . '</body></html>', home_url( '/' ) );
		$items  = $parsed['items'];

		$this->assertCount( 4, $items );
		$urls = [];
		foreach ( $items as $item ) {
			$this->assertSame( [ 'h-entry' ], $item['type'] );
			$this->assertCount( 1, $item['properties']['url'] );
			$this->assertNotEmpty( $item['properties']['published'][0] );
			$this->assertSame( 'Casey Author', $item['properties']['author'][0]['properties']['name'][0] );
			$this->assertArrayNotHasKey( 'content', $item['properties'], 'Nothing invented: no p-content.' );
			$urls[ $item['properties']['url'][0] ] = $item;
		}
		$this->assertEqualsCanonicalizing( array_map( 'get_permalink', [ $one, $bare, $busy_a, $busy_b ] ), array_keys( $urls ) );
		$this->assertSame( 'Happy', $urls[ get_permalink( $one ) ]['properties']['name'][0] );
		$this->assertSame( 'Tired', $urls[ get_permalink( $busy_a ) ]['properties']['name'][0] );
	}

	public function test_the_h_entry_count_equals_the_moods_in_the_year(): void {
		$this->mood( '2026-03-01 10:00:00' );
		$this->mood( '2026-03-02 10:00:00' );
		$this->mood( '2026-03-02 11:00:00' );
		$this->mood( '2026-03-02 12:00:00' );
		$this->mood( '2025-03-02 12:00:00' );

		$xp = $this->dom( $this->render( 1 ) );

		$this->assertSame( 4, $xp->query( self::cls( 'h-entry' ) )->length, 'One cell and three card entries.' );
		$this->assertSame( 1, $this->dom( $this->render( 2 ) )->query( self::cls( 'h-entry' ) )->length );
	}

	public function test_exactly_one_anchor_sits_in_a_one_mood_cell_and_no_tabindex_link_is_visible(): void {
		$this->mood( '2026-03-01 10:00:00' );
		$this->mood( '2026-03-02 10:00:00' );
		$this->mood( '2026-03-02 11:00:00' );

		$xp = $this->dom( $this->render() );

		$this->assertSame( 1, $xp->query( './/a[not(ancestor-or-self::*[@hidden])]', $this->cell( $xp, '2026-03-01' ) )->length );
		$this->assertSame( 1, $xp->query( './/a[not(ancestor-or-self::*[@hidden])]', $this->cell( $xp, '2026-03-02' ) )->length, 'A busy cell is one link too.' );
		foreach ( $xp->query( '//a[@tabindex="-1"]' ) as $link ) {
			$this->assertSame( 1, $xp->query( 'ancestor-or-self::*[@hidden]', $link )->length );
		}
	}

	// Several moods a day.

	public function test_two_moods_make_a_split_cell_linking_to_the_day_card(): void {
		$a = $this->mood( '2026-09-08 09:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy', 'intensity' => 4 ], [ 'post_title' => 'A' ] );
		$b = $this->mood( '2026-09-08 15:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired', 'intensity' => 2 ], [ 'post_title' => 'B' ] );
		update_post_meta( $a, '_pkiw_mood_rating', 4 );
		update_post_meta( $b, '_pkiw_mood_rating', 2 );

		$html = $this->render();
		$xp   = $this->dom( $html );
		$cell = $this->cell( $xp, '2026-09-08' );

		$this->assertStringContainsString( 'pkiw-mood-day--split', $cell->getAttribute( 'class' ) );
		$this->assertStringNotContainsString( 'h-entry', $cell->getAttribute( 'class' ) );
		$this->assertSame( '2', $cell->getAttribute( 'data-count' ) );
		$link = $xp->query( './a', $cell )->item( 0 );
		$this->assertSame( '#mood-day-2026-09-08', $link->getAttribute( 'href' ) );
		$this->assertSame( '', $link->getAttribute( 'id' ), 'The id belongs to the card.' );
		$this->assertSame(
			'September 8, 2026: Happy, intensity 4 of 5; Tired, intensity 2 of 5 (J4, P2)',
			$link->getAttribute( 'aria-label' )
		);
		$segments = $xp->query( './/span[contains(@class, "pkiw-mood-day__seg")]', $link );
		$this->assertSame( 2, $segments->length );
		$this->assertSame( 'joy', $segments->item( 0 )->getAttribute( 'data-family' ) );
		$this->assertSame( 'physical', $segments->item( 1 )->getAttribute( 'data-family' ) );
		$this->assertSame( 'J4', trim( $segments->item( 0 )->textContent ) );
		$this->assertSame( 'P2', trim( $segments->item( 1 )->textContent ) );
	}

	public function test_three_or_more_moods_make_stripes_with_no_text(): void {
		$this->mood( '2026-09-08 09:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy' ] );
		$this->mood( '2026-09-08 10:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ] );
		$this->mood( '2026-09-08 11:00:00', [ 'mood' => 'Sad', 'moodKey' => 'sad' ] );
		$this->mood( '2026-09-08 12:00:00', [ 'mood' => 'Angry', 'moodKey' => 'angry' ] );

		$xp   = $this->dom( $this->render() );
		$cell = $this->cell( $xp, '2026-09-08' );

		$this->assertStringContainsString( 'pkiw-mood-day--stripes', $cell->getAttribute( 'class' ) );
		$this->assertSame( '4', $cell->getAttribute( 'data-count' ) );
		$segments = $xp->query( './/span[contains(@class, "pkiw-mood-day__seg")]', $cell );
		$this->assertSame( 4, $segments->length );
		$this->assertSame( '', trim( $cell->textContent ), 'Stripes carry no text.' );
		$this->assertSame(
			[ 'joy', 'physical', 'sad', 'angry' ],
			array_map( static fn( DOMElement $seg ): string => $seg->getAttribute( 'data-family' ), iterator_to_array( $segments ) )
		);
		$this->assertSame(
			'September 8, 2026: Happy; Tired; Sad; Angry',
			$xp->query( './a', $cell )->item( 0 )->getAttribute( 'aria-label' ),
			'No visible text, so no code suffix.'
		);
	}

	public function test_a_fifteen_mood_day_lists_fifteen_entries_in_post_date_then_id_order(): void {
		$ids = [];
		for ( $i = 0; $i < 15; $i++ ) {
			// A shared minute keeps the post date equal, so ID breaks the tie.
			$ids[] = $this->mood( '2026-09-11 16:0' . ( $i < 10 ? '5' : '6' ) . ':00', [ 'mood' => 'Happy', 'moodKey' => 'happy' ] );
		}

		$xp      = $this->dom( $this->render() );
		$entries = $xp->query( '//li[@id="mood-day-2026-09-11"]//li[contains(@class, "h-entry")]' );

		$this->assertSame( 15, $entries->length );
		$hrefs = [];
		foreach ( $entries as $entry ) {
			$hrefs[] = $xp->query( './a', $entry )->item( 0 )->getAttribute( 'href' );
		}
		$this->assertSame( array_map( 'get_permalink', $ids ), $hrefs );
		$this->assertStringContainsString( 'pkiw-mood-day--stripes', $this->cell( $xp, '2026-09-11' )->getAttribute( 'class' ) );
	}

	public function test_the_day_card_has_a_date_heading_that_is_not_a_link_and_a_link_per_entry(): void {
		$a = $this->mood( '2026-09-08 09:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy', 'intensity' => 4 ] );
		$b = $this->mood( '2026-09-08 15:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ] );
		update_post_meta( $a, '_pkiw_mood_rating', 4 );

		$xp   = $this->dom( $this->render() );
		$card = $xp->query( '//li[@id="mood-day-2026-09-08"]' )->item( 0 );

		$this->assertNotNull( $card );
		$this->assertStringContainsString( 'pkiw-mood-daycard', $card->getAttribute( 'class' ) );
		$heading = $xp->query( './h3', $card )->item( 0 );
		$this->assertSame( 'September 8, 2026', trim( $heading->textContent ) );
		$this->assertSame( 'mood-day-2026-09-08-h', $heading->getAttribute( 'id' ) );
		$this->assertSame( 0, $xp->query( './/a', $heading )->length );
		$links = $xp->query( './/li[contains(@class, "h-entry")]/a', $card );
		$this->assertSame( 2, $links->length );
		$this->assertSame( get_permalink( $a ), $links->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( get_permalink( $b ), $links->item( 1 )->getAttribute( 'href' ) );
		$this->assertSame( 'mood-day-2026-09-08-h mood-entry-' . $a, $links->item( 0 )->getAttribute( 'aria-labelledby' ) );
		$this->assertSame( 'Happy, intensity 4 of 5', trim( $xp->query( '//*[@id="mood-entry-' . $a . '"]' )->item( 0 )->textContent ) );
		$this->assertSame( 'Tired', trim( $xp->query( '//*[@id="mood-entry-' . $b . '"]' )->item( 0 )->textContent ) );
	}

	public function test_the_cards_follow_the_grid_in_date_order(): void {
		$this->mood( '2026-09-08 09:00:00' );
		$this->mood( '2026-09-08 10:00:00' );
		$this->mood( '2026-02-02 09:00:00' );
		$this->mood( '2026-02-02 10:00:00' );

		$html = $this->render();
		$xp   = $this->dom( $html );

		$this->assertLessThan( strpos( $html, 'pkiw-mood-daycards' ), strpos( $html, 'pkiw-mood-year__months' ), 'Cards after the grid.' );
		$ids = [];
		foreach ( $xp->query( self::cls( 'pkiw-mood-daycards', 'ol' ) . '/li' ) as $card ) {
			$ids[] = $card->getAttribute( 'id' );
		}
		$this->assertSame( [ 'mood-day-2026-02-02', 'mood-day-2026-09-08' ], $ids );
	}

	public function test_link_names_are_unique_across_days_that_log_the_same_mood_and_time_only_tells_twins_apart(): void {
		$one = $this->mood( '2026-04-01 09:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ] );
		$two = $this->mood( '2026-04-01 14:30:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ] );
		$thr = $this->mood( '2026-04-01 18:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ] );
		$fou = $this->mood( '2026-04-02 09:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ] );
		$fiv = $this->mood( '2026-04-02 18:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ] );

		$xp = $this->dom( $this->render() );
		$text = static function ( int $id ) use ( $xp ): string {
			return trim( $xp->query( '//*[@id="mood-entry-' . $id . '"]' )->item( 0 )->textContent );
		};

		$this->assertSame( 'Tired, 9:00 am', $text( $one ), 'Two Tired entries that day need the time.' );
		$this->assertSame( 'Tired, 2:30 pm', $text( $two ) );
		$this->assertSame( 'Calm', $text( $thr ), 'A lone Calm needs none.' );
		$this->assertSame( 'Tired', $text( $fou ) );
		$this->assertSame( 'Calm', $text( $fiv ) );

		$names = [];
		foreach ( $xp->query( '//li[contains(@class, "pkiw-mood-daycard")]//li/a' ) as $link ) {
			$heading = $xp->query( '//*[@id="' . explode( ' ', $link->getAttribute( 'aria-labelledby' ) )[0] . '"]' )->item( 0 );
			$entry   = $xp->query( '//*[@id="' . explode( ' ', $link->getAttribute( 'aria-labelledby' ) )[1] . '"]' )->item( 0 );
			$names[] = trim( $heading->textContent ) . ' ' . trim( $entry->textContent );
		}
		$this->assertCount( 5, $names );
		$this->assertSame( $names, array_values( array_unique( $names ) ), 'Every card link has its own accessible name.' );
	}

	public function test_no_id_repeats_on_the_page(): void {
		$this->mood( '2026-04-01 09:00:00' );
		$this->mood( '2026-04-01 14:30:00' );
		$this->mood( '2026-04-02 09:00:00' );
		$this->mood( '2026-05-02 09:00:00' );
		$this->mood( '2026-05-02 10:00:00' );

		$xp  = $this->dom( $this->render() );
		$ids = [];
		foreach ( $xp->query( '//*[@id]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}

		$this->assertNotEmpty( $ids );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	// Visibility.

	public function test_private_draft_future_password_and_whitespace_password_moods_are_absent(): void {
		global $wpdb;
		$this->mood( '2026-03-01 10:00:00', [ 'mood' => 'Happy' ], [ 'post_title' => 'Public' ] );
		$private = $this->mood( '2026-03-02 10:00:00', [ 'mood' => 'Sad', 'moodKey' => 'sad' ], [ 'post_status' => 'private' ] );
		$draft   = $this->mood( '2026-03-03 10:00:00', [ 'mood' => 'Angry', 'moodKey' => 'angry' ], [ 'post_status' => 'draft' ] );
		$future  = $this->mood( '2099-03-04 10:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ], [ 'post_status' => 'future' ] );
		$locked  = $this->mood( '2026-03-05 10:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ], [ 'post_password' => 'sesame' ] );
		$spaces  = $this->mood( '2026-03-06 10:00:00', [ 'mood' => 'Excited', 'moodKey' => 'excited' ] );
		$wpdb->update( $wpdb->posts, [ 'post_password' => '   ' ], [ 'ID' => $spaces ] );
		clean_post_cache( $spaces );

		foreach ( [ 'visitor' => 0, 'admin' => self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $who => $user ) {
			wp_set_current_user( $user );
			$html = $this->render();
			$xp   = $this->dom( $html );

			$this->assertNotNull( $this->cell( $xp, '2026-03-01' ), "{$who}: the public mood shows." );
			foreach ( [ $private, $draft, $future, $locked, $spaces ] as $id ) {
				$this->assertStringNotContainsString( get_permalink( $id ), $html, "{$who}: post {$id}" );
			}
			foreach ( [ '2026-03-02', '2026-03-03', '2026-03-05', '2026-03-06' ] as $day ) {
				$this->assertFalse( $this->cell( $xp, $day )->hasAttribute( 'data-family' ), "{$who}: {$day} is an empty cell." );
			}
			$this->assertSame( 1, $xp->query( self::cls( 'h-entry' ) )->length, $who );
			$this->assertNotContains( $spaces, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), "{$who}: the whitespace password is out of the query, not only the markup." );
		}
	}

	public function test_the_block_drops_unsafe_posts_even_if_the_query_let_them_through(): void {
		$locked  = $this->mood( '2026-03-05 10:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ], [ 'post_password' => 'sesame' ] );
		$private = $this->mood( '2026-03-04 10:00:00', [ 'mood' => 'Sad', 'moodKey' => 'sad' ], [ 'post_status' => 'private' ] );
		$draft   = $this->mood( '2026-03-03 10:00:00', [ 'mood' => 'Angry', 'moodKey' => 'angry' ], [ 'post_status' => 'draft' ] );
		$open    = $this->mood( '2026-03-06 10:00:00' );
		$this->visit();
		$GLOBALS['wp_query']->posts = [ get_post( $locked ), get_post( $private ), get_post( $draft ), get_post( $open ) ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$html = do_blocks( self::BLOCK );

		foreach ( [ $locked, $private, $draft ] as $id ) {
			$this->assertStringNotContainsString( get_permalink( $id ), $html, "Post {$id}" );
		}
		$this->assertStringContainsString( get_permalink( $open ), $html );
	}

	public function test_a_protected_mood_stays_out_for_a_visitor_who_has_entered_the_password(): void {
		$locked = $this->mood( '2026-03-05 10:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ], [ 'post_password' => 'sesame' ] );
		$open   = $this->mood( '2026-03-06 10:00:00' );
		// The visitor unlocked it elsewhere, so core no longer treats the password as required.
		add_filter( 'post_password_required', '__return_false' );

		$this->visit();
		$this->assertNotContains( $locked, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'The SQL guard keeps it out of the query.' );
		$GLOBALS['wp_query']->posts = [ get_post( $locked ), get_post( $open ) ];
		$html                       = do_blocks( self::BLOCK );

		$this->assertNotEmpty( \PKIW\kind_facts( $locked ), 'Facts alone would let it through.' );
		$this->assertStringNotContainsString( get_permalink( $locked ), $html, 'The block drops it itself.' );
		$this->assertStringContainsString( get_permalink( $open ), $html );
	}

	public function test_a_post_of_another_kind_is_dropped_even_if_that_kinds_facts_look_like_a_mood(): void {
		$this->mood( '2026-03-06 10:00:00' );
		$note = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-03-07 10:00:00',
			]
		);
		wp_set_object_terms( $note, 'note', 'kind' );
		\PKIW\Kind_Facts::register(
			'note',
			static fn(): array => [
				'name'         => 'Pretend',
				'has_label'    => true,
				'label'        => 'Pretend',
				'family'       => 'joy',
				'family_code'  => 'J',
				'intensity'    => null,
				'day'          => '2026-03-07',
			]
		);
		try {
			$this->visit();
			$GLOBALS['wp_query']->posts[] = get_post( $note );
			$this->assertNotEmpty( \PKIW\kind_facts( $note ), 'The stand-in reader answers.' );

			$html = do_blocks( self::BLOCK );
		} finally {
			$registered = new ReflectionProperty( \PKIW\Kind_Facts::class, 'registered' );
			$map        = $registered->getValue();
			unset( $map['note'] );
			$registered->setValue( null, $map );
		}

		$this->assertStringNotContainsString( get_permalink( $note ), $html );
	}

	public function test_a_day_lists_its_moods_oldest_first_whatever_order_the_query_gave(): void {
		$late  = $this->mood( '2026-03-05 18:00:00', [ 'mood' => 'Calm', 'moodKey' => 'calm' ] );
		$early = $this->mood( '2026-03-05 08:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ] );
		$tie_a = $this->mood( '2026-03-05 12:00:00', [ 'mood' => 'Sad', 'moodKey' => 'sad' ] );
		$tie_b = $this->mood( '2026-03-05 12:00:00', [ 'mood' => 'Sad', 'moodKey' => 'sad' ] );
		$this->visit();
		// Newest first, the way a plain archive would hand them over.
		$GLOBALS['wp_query']->posts = [ get_post( $late ), get_post( $tie_b ), get_post( $tie_a ), get_post( $early ) ];

		$xp    = $this->dom( do_blocks( self::BLOCK ) );
		$hrefs = [];
		foreach ( $xp->query( '//li[@id="mood-day-2026-03-05"]//li[contains(@class, "h-entry")]/a' ) as $link ) {
			$hrefs[] = $link->getAttribute( 'href' );
		}

		$this->assertSame( array_map( 'get_permalink', [ $early, $tie_a, $tie_b, $late ] ), $hrefs );
		$this->assertSame(
			'March 5, 2026: Tired; Sad; Sad; Calm',
			$this->cell( $xp, '2026-03-05' )->getElementsByTagName( 'a' )->item( 0 )->getAttribute( 'aria-label' ),
			'The cell name runs in the same order.'
		);
	}

	public function test_a_post_of_another_kind_is_not_drawn(): void {
		$this->mood( '2026-03-06 10:00:00' );
		$note = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_date'   => '2026-03-07 10:00:00',
			]
		);
		wp_set_object_terms( $note, 'note', 'kind' );
		$this->visit();
		$GLOBALS['wp_query']->posts[] = get_post( $note );

		$html = do_blocks( self::BLOCK );

		$this->assertStringNotContainsString( get_permalink( $note ), $html );
	}

	public function test_no_posts_prints_nothing(): void {
		$this->visit();

		$this->assertSame( '', trim( do_blocks( self::BLOCK ) ) );
	}

	// Legend.

	public function test_the_legend_lists_only_present_families_in_order_and_can_be_hidden(): void {
		$this->mood( '2026-03-01 10:00:00', [ 'mood' => 'Tired', 'moodKey' => 'tired' ] );
		$this->mood( '2026-03-02 10:00:00', [ 'mood' => 'Happy', 'moodKey' => 'happy' ] );
		$this->mood( '2026-03-03 10:00:00', [ 'mood' => 'Curious', 'moodKey' => '' ] );

		$xp     = $this->dom( $this->render() );
		$legend = $xp->query( self::cls( 'pkiw-mood-legend', 'div' ) )->item( 0 );

		$this->assertNotNull( $legend );
		$this->assertSame( 'group', $legend->getAttribute( 'role' ) );
		$this->assertSame( 'Legend', $legend->getAttribute( 'aria-label' ) );
		$items = $xp->query( './/li[@data-family]', $legend );
		$this->assertSame(
			[ 'joy', 'physical', 'other' ],
			array_map( static fn( DOMElement $li ): string => $li->getAttribute( 'data-family' ), iterator_to_array( $items ) ),
			'Vocabulary order, only families on the page.'
		);
		$this->assertSame( 'JJoy', preg_replace( '/\s+/', '', $items->item( 0 )->textContent ) );
		$this->assertStringContainsString( 'Intensity: 1 (low) to 5 (high)', $legend->textContent );
		$this->assertStringContainsString( 'Diagonal: two moods that day', $legend->textContent );
		$this->assertStringContainsString( 'Stripes: three or more moods that day', $legend->textContent );
		$swatch = $xp->query( './/*[contains(@class, "pkiw-mood-legend__swatch")]', $items->item( 0 ) )->item( 0 );
		$this->assertSame( 'true', $swatch->getAttribute( 'aria-hidden' ) );

		$html = $this->render( 1, [ 'showLegend' => false ] );
		$this->assertStringNotContainsString( 'pkiw-mood-legend', $html );
		$this->assertStringContainsString( 'pkiw-mood-year__months', $html );
	}

	public function test_the_legend_comes_before_the_first_month_heading(): void {
		$this->mood( '2026-03-01 10:00:00' );

		$html = $this->render();

		$this->assertLessThan( strpos( $html, 'pkiw-mood-month' ), strpos( $html, 'pkiw-mood-legend' ) );
	}

	// Headings.

	public function test_headings_default_to_h2_year_and_h3_months_and_shift_with_the_level(): void {
		$this->mood( '2026-03-01 10:00:00' );
		$this->mood( '2026-03-01 11:00:00' );

		$xp = $this->dom( $this->render() );
		$this->assertSame( 1, $xp->query( '//h2[contains(@class, "pkiw-mood-year__year")]' )->length );
		$this->assertSame( 12, $xp->query( '//h3[contains(@class, "pkiw-mood-month__name")]' )->length );
		$this->assertSame( 1, $xp->query( '//li[contains(@class, "pkiw-mood-daycard")]/h3' )->length );
		$this->assertSame( 0, $xp->query( '//h1' )->length );

		$xp = $this->dom( $this->render( 1, [ 'headingLevel' => 3 ] ) );
		$this->assertSame( 1, $xp->query( '//h3[contains(@class, "pkiw-mood-year__year")]' )->length );
		$this->assertSame( 12, $xp->query( '//h4[contains(@class, "pkiw-mood-month__name")]' )->length );
		$this->assertSame( 1, $xp->query( '//li[contains(@class, "pkiw-mood-daycard")]/h4' )->length );
	}

	public function test_a_month_heading_is_named_by_the_full_month(): void {
		$this->mood( '2026-03-01 10:00:00' );

		$xp      = $this->dom( $this->render() );
		$heading = $xp->query( '//h3[contains(@class, "pkiw-mood-month__name")]' )->item( 2 );

		$this->assertSame( 'Mar', $xp->query( './span[@aria-hidden="true"]', $heading )->item( 0 )->textContent );
		$this->assertSame( 'March', $xp->query( './span[contains(@class, "pkiw-mood-year__sr")]', $heading )->item( 0 )->textContent );
	}

	public function test_the_day_gutter_is_hidden_from_assistive_tech_and_runs_one_to_thirty_one(): void {
		$this->mood( '2026-03-01 10:00:00' );

		$xp     = $this->dom( $this->render() );
		$gutter = $xp->query( self::cls( 'pkiw-mood-year__gutter', 'ol' ) )->item( 0 );

		$this->assertSame( 'true', $gutter->getAttribute( 'aria-hidden' ) );
		$this->assertSame( 31, $xp->query( './li', $gutter )->length );
		$this->assertSame( '31', $xp->query( './li', $gutter )->item( 30 )->textContent );
	}

	public function test_the_root_carries_the_shared_calendar_classes(): void {
		$this->mood( '2026-03-01 10:00:00' );

		$xp   = $this->dom( $this->render() );
		$root = $xp->query( self::cls( 'pkiw-mood-year', 'div' ) )->item( 0 );

		$this->assertNotNull( $root );
		foreach ( [ 'pkiw-cal', 'pkiw-cal--year' ] as $class ) {
			$this->assertStringContainsString( $class, $root->getAttribute( 'class' ) );
		}
	}

	// Paging and the template.

	public function test_template_has_grid_sees_the_plugin_template_and_a_pattern_expanded_one(): void {
		$plugin = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-mood.html' );

		$this->assertTrue( Mood_Year_Grid::template_has_grid( $plugin ) );
		$this->assertTrue( Mood_Year_Grid::template_has_grid( '<!-- wp:pattern {"slug":"x/y"} /--><!-- wp:post-kinds-indieweb/mood-year-grid {"headingLevel":3} /-->' ) );
		$this->assertFalse( Mood_Year_Grid::template_has_grid( '<!-- wp:query {"query":{"inherit":true}} --><!-- wp:post-template /--><!-- /wp:query -->' ) );
		$this->assertFalse( Mood_Year_Grid::template_has_grid( '<!-- wp:post-kinds-indieweb/mood-year-grid-other /-->' ) );

		register_block_pattern(
			'pkiw-test/mood-loop',
			[
				'title'   => 'Mood loop',
				'content' => '<!-- wp:post-kinds-indieweb/mood-year-grid /-->',
			]
		);
		$term = get_term_by( 'slug', 'mood', 'kind' );
		$this->mood( '2026-03-01 10:00:00' );
		$this->mood( '2025-03-01 10:00:00' );
		$swap = static function ( $templates ) {
			foreach ( $templates as $found ) {
				if ( str_starts_with( $found->slug, 'taxonomy-kind' ) ) {
					$found->content = '<!-- wp:query {"queryId":0,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:pattern {"slug":"pkiw-test/mood-loop"} /--></div><!-- /wp:query -->';
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $swap );
		$this->visit();
		remove_filter( 'get_block_templates', $swap );
		unregister_block_pattern( 'pkiw-test/mood-loop' );

		$this->assertNotNull( $term );
		$this->assertSame( 'year', $GLOBALS['wp_query']->get( 'pkiw_calendar' )['unit'] ?? '', 'A grid inside a pattern turns the paging on.' );
	}

	public function test_the_mapping_is_for_mood_only_and_needs_the_grid(): void {
		$grid = '<!-- wp:post-kinds-indieweb/mood-year-grid /-->';

		$this->assertSame( [ 'unit' => 'year' ], Mood_Year_Grid::calendar_paging( null, get_term_by( 'slug', 'mood', 'kind' ) ?: $this->kind( 'mood' ), $grid ) );
		$this->assertNull( Mood_Year_Grid::calendar_paging( null, $this->kind( 'weather' ), $grid ), 'Another kind is left alone.' );
		$this->assertNull( Mood_Year_Grid::calendar_paging( null, $this->kind( 'mood' ), '<!-- wp:query /-->' ) );
		$other = [ 'unit' => 'month' ];
		$this->assertSame( $other, Mood_Year_Grid::calendar_paging( $other, $this->kind( 'weather' ), $grid ), 'An earlier answer passes through.' );
	}

	/**
	 * A kind term.
	 */
	private function kind( string $slug ): WP_Term {
		if ( ! term_exists( $slug, 'kind' ) ) {
			wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
		}
		$term = get_term_by( 'slug', $slug, 'kind' );
		$this->assertInstanceOf( WP_Term::class, $term );

		return $term;
	}

	public function test_the_archive_pages_by_year_through_the_plugin_template(): void {
		$this->mood( '2026-03-01 10:00:00' );
		$this->mood( '2025-03-01 10:00:00' );
		$this->mood( '2024-03-01 10:00:00' );

		$this->visit( 1 );
		$this->assertSame( 3, (int) $GLOBALS['wp_query']->max_num_pages );
		$this->assertSame( '2026', $GLOBALS['wp_query']->get( 'pkiw_calendar' )['key'] );
		$this->visit( 3 );
		$this->assertSame( '2024', $GLOBALS['wp_query']->get( 'pkiw_calendar' )['key'] );
		$this->visit( 4 );
		$this->assertTrue( $GLOBALS['wp_query']->is_404() );
	}

	// The block-renderer preview.

	public function test_the_block_renderer_preview_draws_the_newest_year(): void {
		$this->mood( '2026-03-01 10:00:00' );
		$this->mood( '2025-03-01 10:00:00' );
		$locked = $this->mood( '2027-03-01 10:00:00', [], [ 'post_password' => 'sesame' ] );
		$this->mood( '2028-03-01 10:00:00', [], [ 'post_status' => 'draft' ] );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/mood-year-grid' );
		apply_filters( 'rest_request_before_callbacks', null, [], $request );
		$this->assertTrue( Grouped_Archive::is_block_preview() );
		$this->go_to( home_url( '/' ) );
		$html = do_blocks( self::BLOCK );
		apply_filters( 'rest_request_after_callbacks', null, [], $request );

		$xp = $this->dom( $html );
		$this->assertSame( '2026', trim( $xp->query( self::cls( 'pkiw-mood-year__year' ) )->item( 0 )->textContent ), 'Newest visible year; the 2027 password post and 2028 draft add none.' );
		$this->assertNotNull( $this->cell( $xp, '2026-03-01' ) );
		$this->assertStringNotContainsString( get_permalink( $locked ), $html );
	}

	public function test_the_preview_with_no_moods_prints_nothing(): void {
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/mood-year-grid' );
		apply_filters( 'rest_request_before_callbacks', null, [], $request );
		$this->go_to( home_url( '/' ) );
		$html = do_blocks( self::BLOCK );
		apply_filters( 'rest_request_after_callbacks', null, [], $request );

		$this->assertSame( '', trim( $html ) );
	}

	// Registration.

	public function test_the_block_is_registered_with_its_attributes_and_style(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( Mood_Year_Grid::BLOCK );

		$this->assertSame( 'post-kinds-indieweb/mood-year-grid', Mood_Year_Grid::BLOCK );
		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 2, $type->attributes['headingLevel']['default'] );
		$this->assertSame( 'integer', $type->attributes['headingLevel']['type'] );
		$this->assertTrue( $type->attributes['showLegend']['default'] );
		$this->assertSame( 'boolean', $type->attributes['showLegend']['type'] );
		$this->assertSame( [ 'pkiw-mood-year-grid' ], $type->style_handles );
		$this->assertSame( [ 'pkiw-mood-year-grid-editor' ], $type->editor_script_handles );
		$this->assertSame( 'post-kinds-indieweb', $type->category );
	}

	public function test_the_editor_script_carries_the_plugin_text_domain(): void {
		$script = wp_scripts()->registered['pkiw-mood-year-grid-editor'] ?? null;

		$this->assertNotNull( $script );
		$this->assertSame( 'post-kinds-for-indieweb-in-block-themes', $script->textdomain );
		$this->assertStringEndsWith( 'assets/js/mood-year-grid-editor.js', (string) $script->src );
		$this->assertFileExists( PKIW_PATH . 'assets/js/mood-year-grid-editor.js' );
		$this->assertFileExists( PKIW_PATH . 'styles/mood-year-grid.css' );
	}
}

<?php
/**
 * The almanac month block on the weather archive (issue 246).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Almanac_Month;
use PKIW\Taxonomy;

/**
 * One calendar month of weather per page, drawn from the inherited main
 * query: a link per entry, one reading each, and nothing the archive may not
 * print. Posts are invented; the fixtures follow the plan's A-numbering.
 *
 * @group integration
 */
final class AlmanacMonthBlockTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/almanac-month';

	/**
	 * Stylesheet active before each test.
	 */
	private string $original_stylesheet = '';

	/**
	 * Author of every fixture.
	 */
	private int $author_id = 0;

	/**
	 * Fixture IDs by name.
	 *
	 * @var array<string, int>
	 */
	private array $ids = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/weather-stub.php';
		require_once dirname( __DIR__ ) . '/fixtures/simple-location/location-stub.php';
	}

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'start_of_week', 0 );
		update_option( 'sloc_measurements', 'metric' );
		update_option( 'geo_public', '0' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Almanac Author' ] );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A weather post.
	 *
	 * @param string               $date       Local post date.
	 * @param array<string, mixed> $args       Post arguments.
	 * @param array<string, mixed> $weather    Weather rows, metric.
	 * @param string               $geo_public Simple Location visibility, '' for no row.
	 * @param string               $kind       Kind slug.
	 */
	private function weather( string $date, array $args = [], array $weather = [], string $geo_public = '1', string $kind = 'weather' ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_author'  => $this->author_id,
					'post_date'    => $date,
					'post_content' => '<!-- wp:paragraph --><p>Out for a walk.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY ) );
		foreach ( $weather as $prop => $value ) {
			add_post_meta( $id, 'weather_' . $prop, $value );
		}
		if ( '' !== $geo_public ) {
			add_post_meta( $id, 'geo_public', $geo_public );
		}
		// A street and a point that must never reach the archive.
		add_post_meta( $id, 'geo_address', '100 Example Avenue, Sampletown' );
		add_post_meta( $id, 'geo_latitude', '10.1' );
		add_post_meta( $id, 'geo_longitude', '20.2' );
		add_post_meta( $id, 'geo_locality', 'Sampletown' );

		return $id;
	}

	/**
	 * The run of months used by most tests: August 2026 (eight entries), June
	 * 2026 and December 2025, plus controls that must never show.
	 */
	private function seed(): void {
		$clear = [
			'code'        => 800,
			'temperature' => 26.8,
		];

		$this->ids['A01'] = $this->weather( '2026-08-03 09:15:00', [], $clear );
		$this->ids['A02'] = $this->weather(
			'2026-08-03 14:40:00',
			[ 'post_title' => 'Afternoon storm' ],
			[
				'code'        => 500,
				'temperature' => 18,
			]
		);
		$this->ids['A03'] = $this->weather( '2026-08-10 08:00:00' );
		$this->ids['A04'] = $this->weather( '2026-08-10 08:00:00' );
		$this->ids['A05'] = $this->weather(
			'2026-08-16 07:05:00',
			[ 'post_content' => '<!-- wp:paragraph --><p><span class="p-weather">Breezy</span></p><!-- /wp:paragraph -->' ],
			[ 'temperature' => 15 ],
			'0'
		);
		$this->ids['A06'] = $this->weather(
			'2026-08-17 12:00:00',
			[],
			[
				'icon'        => 'wi-day-haze',
				'temperature' => 20,
			]
		);
		$this->ids['A07'] = $this->weather( '2026-08-18 10:00:00', [], [ 'temperature' => 22 ], '0' );
		$this->ids['A08'] = $this->weather( '2026-08-18 18:30:00', [], [], '' );
		$this->ids['J01'] = $this->weather( '2026-06-20 11:00:00', [], $clear );
		$this->ids['D01'] = $this->weather( '2025-12-31 23:30:00', [], $clear );

		// Controls: none of these may ever print.
		$this->ids['X_protected'] = $this->weather( '2026-08-04 10:00:00', [ 'post_password' => 'secret' ], $clear );
		$this->ids['X_private']   = $this->weather( '2026-08-05 10:00:00', [ 'post_status' => 'private' ], $clear );
		$this->ids['X_draft']     = $this->weather( '2026-08-06 10:00:00', [ 'post_status' => 'draft' ], $clear );
		$this->ids['X_note']      = $this->weather( '2026-08-07 10:00:00', [ 'post_title' => 'A plain note' ], $clear, '1', 'note' );
	}

	/**
	 * Visit a page of the weather archive.
	 *
	 * @param int $page Page number.
	 */
	private function visit( int $page = 1 ): void {
		$link = get_term_link( 'weather', Taxonomy::TAXONOMY );
		$this->assertIsString( $link );
		$this->go_to( $page > 1 ? trailingslashit( $link ) . 'page/' . $page . '/' : $link );
	}

	/**
	 * Render the block against the current main query.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function block( array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Visit a page and render the block.
	 *
	 * @param int                  $page  Page number.
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function page_html( int $page = 1, array $attrs = [] ): string {
		$this->visit( $page );

		return $this->block( $attrs );
	}

	/**
	 * Parse HTML for XPath.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * XPath for an element holding a class token.
	 *
	 * @param string $class Class token.
	 * @param string $tag   Tag name or *.
	 */
	private function cls( string $class, string $tag = '*' ): string {
		return './/' . $tag . '[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
	}

	/**
	 * Entry nodes in document order.
	 *
	 * @param DOMXPath $xp XPath.
	 * @return DOMElement[]
	 */
	private function entries( DOMXPath $xp ): array {
		$out = [];
		foreach ( $xp->query( $this->cls( 'pkiw-almanac__entry', 'li' ) ) as $node ) {
			$out[] = $node;
		}

		return $out;
	}

	/**
	 * The shape of a node: tags, classes and attribute names, no values and no text.
	 *
	 * @param DOMNode $node Node.
	 */
	private function skeleton( DOMNode $node ): string {
		if ( ! $node instanceof DOMElement ) {
			return '';
		}
		$names = [];
		foreach ( $node->attributes as $attribute ) {
			$names[] = 'class' === $attribute->name ? 'class=' . $attribute->value : $attribute->name;
		}
		sort( $names );
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= $this->skeleton( $child );
		}

		return '<' . $node->tagName . ' ' . implode( ' ', $names ) . '>' . $inner . '</' . $node->tagName . '>';
	}

	/**
	 * Whether a node, or an ancestor, is hidden.
	 *
	 * @param DOMNode $node Node.
	 */
	private function is_hidden( DOMNode $node ): bool {
		for ( $current = $node; $current instanceof DOMElement; $current = $current->parentNode ) {
			if ( $current->hasAttribute( 'hidden' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Entry nodes for the given fixture permalinks, by name.
	 *
	 * @param DOMXPath $xp   XPath.
	 * @param string   $name Fixture name.
	 */
	private function entry_of( DOMXPath $xp, string $name ): DOMElement {
		$href = (string) get_permalink( $this->ids[ $name ] );
		$node = $xp->query( $this->cls( 'pkiw-almanac__entry', 'li' ) . '[.//a[@href="' . $href . '"]]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $node, $name . ' has an entry.' );

		return $node;
	}

	/**
	 * Visible text of a node: text outside hidden subtrees, whitespace collapsed.
	 *
	 * @param DOMXPath $xp   XPath.
	 * @param DOMNode  $node Context node.
	 */
	private function visible_text( DOMXPath $xp, DOMNode $node ): string {
		$text = '';
		foreach ( $xp->query( './/text()', $node ) as $piece ) {
			if ( ! $this->is_hidden( $piece->parentNode ) ) {
				$text .= $piece->nodeValue . ' ';
			}
		}

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	// Registration.

	public function test_the_block_registers_from_php_with_both_attributes_and_no_block_json(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertNotNull( $type );
		$this->assertSame( 3, $type->api_version );
		$this->assertSame( 'integer', $type->attributes['headingLevel']['type'] );
		$this->assertSame( 2, $type->attributes['headingLevel']['default'] );
		$this->assertSame( 'boolean', $type->attributes['showYearStrip']['type'] );
		$this->assertTrue( $type->attributes['showYearStrip']['default'] );
		$this->assertSame( [ Almanac_Month::class, 'render' ], $type->render_callback );
		$this->assertContains( 'templateSlug', $type->uses_context );
		$this->assertContains( 'pkiw-almanac-month-editor', $type->editor_script_handles );
		$this->assertFileDoesNotExist( PKIW_PATH . 'src/blocks/almanac-month/block.json' );
		$this->assertFileDoesNotExist( PKIW_PATH . 'build/blocks/almanac-month/block.json' );
		$this->assertFileExists( PKIW_PATH . 'assets/js/almanac-month-editor.js' );
		$this->assertTrue( wp_script_is( 'pkiw-almanac-month-editor', 'registered' ) );
	}

	public function test_the_class_answers_the_calendar_paging_filter_for_weather_only(): void {
		$template = '<!-- wp:post-kinds-indieweb/almanac-month {"headingLevel":2} /-->';
		$weather  = get_term_by( 'slug', 'weather', Taxonomy::TAXONOMY );
		$note     = get_term_by( 'slug', 'note', Taxonomy::TAXONOMY );

		$this->assertSame(
			[
				'unit'  => 'month',
				'order' => 'ASC',
			],
			apply_filters( 'pkiw_calendar_paging', null, $weather, $template )
		);
		$this->assertSame(
			[
				'unit'  => 'month',
				'order' => 'ASC',
			],
			apply_filters( 'pkiw_calendar_paging', null, $weather, '<!-- wp:post-kinds-indieweb/almanac-month /-->' )
		);
		$this->assertNull( apply_filters( 'pkiw_calendar_paging', null, $weather, '<!-- wp:post-kinds-indieweb/almanac-month-extra /--><!-- wp:query /-->' ), 'A longer block name is not the block.' );
		$this->assertNull( apply_filters( 'pkiw_calendar_paging', null, $weather, '<!-- wp:query --><!-- /wp:query -->' ), 'No almanac block, no plan.' );
		$this->assertNull( apply_filters( 'pkiw_calendar_paging', null, $note, $template ), 'Another kind keeps count paging.' );
		$this->assertSame( [ 'unit' => 'year' ], apply_filters( 'pkiw_calendar_paging', [ 'unit' => 'year' ], $note, $template ), 'An earlier answer passes through.' );
	}

	// The page.

	public function test_a_page_holds_one_section_with_the_month_heading(): void {
		$this->seed();
		$xp = $this->xpath( $this->page_html() );

		$sections = $xp->query( $this->cls( 'pkiw-almanac', 'section' ) );
		$this->assertSame( 1, $sections->length );
		$section = $sections->item( 0 );
		foreach ( [ 'pkiw-cal', 'pkiw-cal--month', 'wp-block-post-kinds-indieweb-almanac-month' ] as $class ) {
			$this->assertStringContainsString( $class, $section->getAttribute( 'class' ) );
		}
		$this->assertSame( '2026-08', $section->getAttribute( 'data-pkiw-month' ) );

		$headings = $xp->query( './/h2', $section );
		$this->assertSame( 1, $headings->length );
		$this->assertSame( 'August 2026', trim( $headings->item( 0 )->textContent ) );
		$this->assertSame( $headings->item( 0 )->getAttribute( 'id' ), $section->getAttribute( 'aria-labelledby' ) );
		$this->assertNotSame( '', $section->getAttribute( 'aria-labelledby' ) );
	}

	public function test_only_days_with_entries_get_a_cell_and_the_days_ascend(): void {
		$this->seed();
		$xp   = $this->xpath( $this->page_html() );
		$days = [];
		foreach ( $xp->query( $this->cls( 'pkiw-almanac__day', 'li' ) ) as $day ) {
			$days[] = $day->getAttribute( 'data-pkiw-date' );
			$this->assertStringContainsString( 'pkiw-cal__day', $day->getAttribute( 'class' ) );
		}

		$this->assertSame( [ '2026-08-03', '2026-08-10', '2026-08-16', '2026-08-17', '2026-08-18' ], $days );
		$number = $xp->query( $this->cls( 'pkiw-almanac__daynum', 'span' ) )->item( 0 );
		$this->assertSame( 'true', $number->getAttribute( 'aria-hidden' ) );
		$this->assertSame( '3', trim( $number->textContent ) );
	}

	public function test_entries_ascend_with_time_then_id_for_identical_timestamps(): void {
		$this->seed();
		$xp    = $this->xpath( $this->page_html() );
		$hrefs = [];
		foreach ( $this->entries( $xp ) as $entry ) {
			$hrefs[] = $xp->query( './/a[@href]', $entry )->item( 0 )->getAttribute( 'href' );
		}

		$expected = array_map(
			fn( string $name ): string => (string) get_permalink( $this->ids[ $name ] ),
			[ 'A01', 'A02', 'A03', 'A04', 'A05', 'A06', 'A07', 'A08' ]
		);
		$this->assertSame( $expected, $hrefs );
		$this->assertLessThan( $this->ids['A04'], $this->ids['A03'] );
	}

	public function test_the_page_holds_no_protected_private_draft_or_other_kind_post(): void {
		$this->seed();
		$html = $this->page_html();

		$this->assertCount( 8, $this->entries( $this->xpath( $html ) ) );
		foreach ( [ 'X_protected', 'X_private', 'X_draft', 'X_note' ] as $name ) {
			$this->assertStringNotContainsString( (string) get_permalink( $this->ids[ $name ] ), $html, $name );
		}
		$this->assertStringNotContainsString( 'A plain note', $html );
	}

	public function test_a_post_that_slips_into_the_query_is_still_left_out(): void {
		$this->seed();
		$this->visit();
		foreach ( [ 'X_protected', 'X_private', 'X_draft', 'X_note', 'J01' ] as $name ) {
			$GLOBALS['wp_query']->posts[] = get_post( $this->ids[ $name ] );
		}

		$html = $this->block();

		$this->assertCount( 8, $this->entries( $this->xpath( $html ) ) );
		foreach ( [ 'X_protected', 'X_private', 'X_draft', 'X_note', 'J01' ] as $name ) {
			$this->assertStringNotContainsString( (string) get_permalink( $this->ids[ $name ] ), $html, $name );
		}
	}

	public function test_each_entry_has_exactly_one_rendered_link_and_no_hidden_link_is_visible(): void {
		$this->seed();
		$xp = $this->xpath( $this->page_html() );

		foreach ( $this->entries( $xp ) as $entry ) {
			$this->assertSame( 1, $xp->query( './/a[@href][not(ancestor-or-self::*[@hidden])]', $entry )->length );
			$this->assertSame( 0, $xp->query( './/a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]', $entry )->length );
		}
		$this->assertSame( 0, $xp->query( '//a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]' )->length );
	}

	public function test_accessible_names_are_unique_except_for_identical_timestamps(): void {
		$this->seed();
		$xp    = $this->xpath( $this->page_html() );
		$names = [];
		foreach ( $this->entries( $xp ) as $entry ) {
			$link = $xp->query( './/a[@href]', $entry )->item( 0 );
			$names[ $link->getAttribute( 'href' ) ] = trim( (string) preg_replace( '/\s+/', ' ', $link->textContent ) );
		}

		$repeated = array_keys( array_filter( array_count_values( $names ), static fn( int $count ): bool => $count > 1 ) );
		$this->assertSame( [ 'Weather, August 10, 2026 at 8:00 am' ], $repeated );
		$shared = array_keys( array_filter( $names, static fn( string $name ): bool => 'Weather, August 10, 2026 at 8:00 am' === $name ) );
		sort( $shared );
		$expected = [ (string) get_permalink( $this->ids['A03'] ), (string) get_permalink( $this->ids['A04'] ) ];
		sort( $expected );
		$this->assertSame( $expected, $shared, 'Only the identical-timestamp pair shares a name.' );
	}

	public function test_an_untitled_entry_with_an_observation_prints_its_reading_once_inside_the_link(): void {
		$this->seed();
		$xp    = $this->xpath( $this->page_html() );
		$entry = $this->entry_of( $xp, 'A01' );

		$weather = $xp->query( $this->cls( 'p-weather' ), $entry );
		$this->assertSame( 1, $weather->length );
		$this->assertSame( 'Clear Sky, 27 °C', $weather->item( 0 )->textContent );
		$this->assertSame( 1, $xp->query( './/a[@href]//*[contains(concat(" ", normalize-space(@class), " "), " p-weather ")]', $entry )->length );
		$this->assertSame( 1, substr_count( $this->visible_text( $xp, $entry ), 'Clear Sky' ) );
		$this->assertSame( 'h3', $xp->query( './/a[@href]', $entry )->item( 0 )->parentNode->nodeName );
	}

	public function test_an_untitled_entry_names_its_link_with_a_hidden_date_and_time(): void {
		$this->seed();
		$xp    = $this->xpath( $this->page_html() );
		$entry = $this->entry_of( $xp, 'A01' );

		$sr = $xp->query( $this->cls( 'screen-reader-text', 'span' ), $entry );
		$this->assertSame( 1, $sr->length );
		$this->assertSame( ', August 3, 2026 at 9:15 am', $sr->item( 0 )->textContent );
	}

	public function test_a_titled_entry_links_its_title_and_prints_the_reading_beside_it(): void {
		$this->seed();
		$xp    = $this->xpath( $this->page_html() );
		$entry = $this->entry_of( $xp, 'A02' );

		$name = $xp->query( './/a[@href]' . '//' . 'span[contains(concat(" ", normalize-space(@class), " "), " p-name ")]', $entry );
		$this->assertSame( 1, $name->length );
		$this->assertSame( 'Afternoon storm', $name->item( 0 )->textContent );
		$weather = $xp->query( $this->cls( 'p-weather' ), $entry );
		$this->assertSame( 1, $weather->length );
		$this->assertSame( 'p', $weather->item( 0 )->nodeName );
		$this->assertSame( 0, $xp->query( './/a[@href]//*[contains(concat(" ", normalize-space(@class), " "), " p-weather ")]', $entry )->length );
		$this->assertSame( 'light rain, 18 °C', $weather->item( 0 )->textContent );
	}

	public function test_an_untitled_entry_without_a_reading_says_weather_with_no_class(): void {
		$this->seed();
		$xp    = $this->xpath( $this->page_html() );
		$entry = $this->entry_of( $xp, 'A08' );
		$link  = $xp->query( './/a[@href]', $entry )->item( 0 );

		$this->assertSame( 0, $xp->query( $this->cls( 'p-weather' ), $entry )->length );
		$this->assertSame( 0, $xp->query( $this->cls( 'p-name' ) . '[not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " p-author ")])]', $entry )->length, 'A synthetic name is never a p-name.' );
		$this->assertStringStartsWith( 'Weather', trim( $link->textContent ) );
	}

	public function test_authored_text_is_the_reading_when_the_observation_is_hidden(): void {
		$this->seed();
		$xp    = $this->xpath( $this->page_html() );
		$entry = $this->entry_of( $xp, 'A05' );

		$this->assertSame( 'Breezy', $xp->query( $this->cls( 'p-weather' ), $entry )->item( 0 )->textContent );
		$this->assertSame( 1, $xp->query( $this->cls( 'p-weather' ), $entry )->length );
		$this->assertSame( 0, $xp->query( $this->cls( 'pkiw-almanac__mark' ), $entry )->length );
	}

	public function test_a_hidden_observation_draws_the_same_skeleton_as_no_observation(): void {
		$this->seed();
		$xp = $this->xpath( $this->page_html() );

		$this->assertSame(
			$this->skeleton( $this->entry_of( $xp, 'A08' ) ),
			$this->skeleton( $this->entry_of( $xp, 'A07' ) )
		);
		$this->assertSame( 'Weather', trim( explode( ',', $this->visible_text( $xp, $this->entry_of( $xp, 'A07' ) ) )[0] ) );
	}

	public function test_an_editor_sees_the_hidden_observation(): void {
		$this->seed();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$xp = $this->xpath( $this->page_html() );

		$this->assertSame( '22 °C', $xp->query( $this->cls( 'p-weather' ), $this->entry_of( $xp, 'A07' ) )->item( 0 )->textContent );
	}

	public function test_a_mark_needs_a_stored_code(): void {
		$this->seed();
		$xp = $this->xpath( $this->page_html() );

		$mark = $xp->query( $this->cls( 'pkiw-almanac__mark', 'span' ), $this->entry_of( $xp, 'A01' ) );
		$this->assertSame( 1, $mark->length );
		$this->assertSame( 'wi-day-sunny', $mark->item( 0 )->getAttribute( 'data-pkiw-weather-icon' ) );
		$this->assertSame( 'true', $mark->item( 0 )->getAttribute( 'aria-hidden' ) );
		$this->assertSame( '', trim( $mark->item( 0 )->textContent ) );

		$this->assertSame( 0, $xp->query( $this->cls( 'pkiw-almanac__mark' ), $this->entry_of( $xp, 'A06' ) )->length, 'An icon row without a code draws no mark.' );
	}

	public function test_the_visible_text_never_holds_the_forbidden_words_or_place_or_author(): void {
		$this->seed();
		$html = $this->page_html();
		$xp   = $this->xpath( $html );
		$text = '';
		foreach ( $xp->query( '//text()' ) as $piece ) {
			$text .= ' ' . $piece->nodeValue;
		}

		foreach ( [ 'Observed', 'High', 'Low', 'Public', 'Private', 'hidden', 'observation', 'Sample content' ] as $word ) {
			$this->assertStringNotContainsString( $word, $text, $word );
		}
		foreach ( [ 'Sampletown', '100 Example Avenue', '10.1', '20.2', 'wind', 'Wind' ] as $word ) {
			$this->assertStringNotContainsString( $word, $text, $word );
		}
		$this->assertStringNotContainsString( 'Out for a walk', $text, 'No excerpt.' );
		foreach ( $xp->query( '//text()[contains(., "Almanac Author")]' ) as $piece ) {
			$this->assertTrue( $this->is_hidden( $piece->parentNode ), 'The author name sits under a hidden node.' );
		}
		$this->assertGreaterThan( 0, $xp->query( '//text()[contains(., "Almanac Author")]' )->length, 'The hidden h-card carries the author name.' );
		$this->assertStringNotContainsString( '→', $text );
		$this->assertStringNotContainsString( '←', $text );
	}

	public function test_the_block_runs_no_content_pass(): void {
		$this->seed();
		$this->visit();
		$passes = 0;
		$count  = static function ( $content ) use ( &$passes ) {
			++$passes;

			return $content;
		};
		add_filter( 'the_content', $count, 1 );
		add_filter( 'get_the_excerpt', $count, 1 );

		$this->block();

		$this->assertSame( 0, $passes );
	}

	// The year strip.

	public function test_the_year_strip_lists_twelve_months_and_links_only_the_months_with_posts(): void {
		$this->seed();
		$xp  = $this->xpath( $this->page_html() );
		$nav = $xp->query( $this->cls( 'pkiw-year-strip', 'nav' ) );

		$this->assertSame( 1, $nav->length );
		$this->assertSame( 'Months of 2026', $nav->item( 0 )->getAttribute( 'aria-label' ) );
		$items = $xp->query( './/li', $nav->item( 0 ) );
		$this->assertSame( 12, $items->length );

		$linked = [];
		foreach ( $xp->query( './/a', $nav->item( 0 ) ) as $link ) {
			$linked[] = trim( $xp->query( './/span[not(contains(@class,"screen-reader-text"))]', $link )->item( 0 )->textContent );
		}
		$this->assertSame( [ 'Jun', 'Aug' ], $linked, 'December 2025 belongs to another year.' );
	}

	public function test_year_strip_links_are_the_page_urls_and_the_displayed_month_is_current(): void {
		$this->seed();
		$xp   = $this->xpath( $this->page_html() );
		$term = trailingslashit( (string) get_term_link( 'weather', Taxonomy::TAXONOMY ) );
		$nav  = $xp->query( $this->cls( 'pkiw-year-strip', 'nav' ) )->item( 0 );

		$links = [];
		foreach ( $xp->query( './/a', $nav ) as $link ) {
			$links[ trim( $xp->query( './/span[contains(@class,"screen-reader-text")]', $link )->item( 0 )->textContent ) ] = $link;
		}

		$this->assertSame( [ 'June 2026', 'August 2026' ], array_keys( $links ) );
		$this->assertSame( $term . 'page/2/', $links['June 2026']->getAttribute( 'href' ) );
		$this->assertSame( $term, $links['August 2026']->getAttribute( 'href' ) );
		$this->assertSame( 'page', $links['August 2026']->getAttribute( 'aria-current' ) );
		$this->assertFalse( $links['June 2026']->hasAttribute( 'aria-current' ) );
		$this->assertSame( 1, $xp->query( './/*[@aria-current]', $nav )->length );
	}

	public function test_the_second_page_shows_june_and_its_strip_marks_june(): void {
		$this->seed();
		$xp = $this->xpath( $this->page_html( 2 ) );

		$this->assertSame( 'June 2026', trim( $xp->query( '//h2' )->item( 0 )->textContent ) );
		$this->assertSame( 'June 2026', trim( $xp->query( '//a[@aria-current="page"]//span[contains(@class,"screen-reader-text")]' )->item( 0 )->textContent ) );
		$this->assertCount( 1, $this->entries( $xp ) );
	}

	public function test_the_year_strip_can_be_switched_off(): void {
		$this->seed();
		$xp = $this->xpath( $this->page_html( 1, [ 'showYearStrip' => false ] ) );

		$this->assertSame( 0, $xp->query( '//nav' )->length );
		$this->assertCount( 8, $this->entries( $xp ) );
	}

	public function test_the_heading_level_sets_the_section_and_entry_headings_and_clamps_to_four(): void {
		$this->seed();

		$xp = $this->xpath( $this->page_html( 1, [ 'headingLevel' => 3 ] ) );
		$this->assertSame( 1, $xp->query( '//h3[contains(@class,"pkiw-almanac__heading")]' )->length );
		$this->assertSame( 8, $xp->query( '//li//h4' )->length );
		$this->assertSame( 0, $xp->query( '//h2' )->length );

		$xp = $this->xpath( $this->page_html( 1, [ 'headingLevel' => 7 ] ) );
		$this->assertSame( 1, $xp->query( '//h4[contains(@class,"pkiw-almanac__heading")]' )->length );
		$this->assertSame( 8, $xp->query( '//li//h5' )->length );
	}

	// Paging through the real engine.

	public function test_the_pages_step_back_through_the_months_that_have_posts(): void {
		$this->seed();

		$this->visit( 1 );
		$this->assertSame( 3, (int) $GLOBALS['wp_query']->max_num_pages );
		$this->assertSame( '2026-08', $GLOBALS['wp_query']->get( 'pkiw_calendar' )['key'] );

		$this->visit( 2 );
		$this->assertSame( '2026-06', $GLOBALS['wp_query']->get( 'pkiw_calendar' )['key'] );

		$xp = $this->xpath( $this->page_html( 3 ) );
		$this->assertSame( 'December 2025', trim( $xp->query( '//h2' )->item( 0 )->textContent ) );
		$day = $xp->query( $this->cls( 'pkiw-almanac__day', 'li' ) )->item( 0 );
		$this->assertSame( '2025-12-31', $day->getAttribute( 'data-pkiw-date' ), 'A late-evening post stays on its local day.' );

		$this->visit( 4 );
		$this->assertTrue( $GLOBALS['wp_query']->is_404(), 'A page past the last month is a 404.' );
	}

	public function test_the_feed_keeps_core_ordering_and_the_page_size(): void {
		$this->seed();
		update_option( 'posts_per_rss', 3 );
		$this->go_to( trailingslashit( (string) get_term_link( 'weather', Taxonomy::TAXONOMY ) ) . 'feed/' );

		$this->assertTrue( is_feed() );
		$this->assertSame( '', $GLOBALS['wp_query']->get( 'pkiw_calendar' ), 'Feeds take no calendar plan.' );
		$this->assertCount( 3, $GLOBALS['wp_query']->posts );
		$this->assertSame( $this->ids['A08'], $GLOBALS['wp_query']->posts[0]->ID, 'Newest first.' );
	}

	// Empty and out-of-place contexts.

	public function test_the_block_prints_nothing_on_an_empty_archive_the_home_page_or_another_kind(): void {
		$this->visit();
		$this->assertSame( '', $this->block(), 'No weather posts yet.' );

		$this->seed();
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', $this->block(), 'The home page.' );

		$this->go_to( (string) get_term_link( 'note', Taxonomy::TAXONOMY ) );
		$this->assertSame( '', $this->block(), 'Another kind\'s archive.' );

		$this->visit( 4 );
		$this->assertSame( '', $this->block(), 'A page past the end.' );
	}

	public function test_a_weather_post_on_another_kinds_month_page_is_not_drawn(): void {
		$both = $this->weather( '2026-08-03 09:15:00', [], [ 'temperature' => 20 ] );
		wp_set_object_terms( $both, [ 'weather', 'note' ], Taxonomy::TAXONOMY );
		$plan = static fn( $plan, $term ) => 'note' === $term->slug ? [ 'unit' => 'month' ] : $plan;
		add_filter( 'pkiw_calendar_paging', $plan, 20, 2 );

		$this->go_to( (string) get_term_link( 'note', Taxonomy::TAXONOMY ) );

		$this->assertSame( 'month', $GLOBALS['wp_query']->get( 'pkiw_calendar' )['unit'] ?? null, 'The note archive pages by month here.' );
		$this->assertContains( $both, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( '', $this->block(), 'The almanac belongs to the weather archive.' );
		remove_filter( 'pkiw_calendar_paging', $plan, 20 );
	}

	// Microformats.

	public function test_a_parse_finds_one_entry_per_post_each_with_url_published_and_author(): void {
		$this->seed();
		$html  = $this->page_html();
		$items = \Mf2\parse( $html, home_url( '/' ) )['items'] ?? [];
		$found = [];
		$walk  = static function ( array $items ) use ( &$walk, &$found ): void {
			foreach ( $items as $item ) {
				if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
					$found[] = $item;
				}
				$walk( $item['children'] ?? [] );
			}
		};
		$walk( $items );

		$this->assertCount( 8, $found );
		foreach ( $found as $entry ) {
			$this->assertNotEmpty( $entry['properties']['url'] ?? [] );
			$this->assertNotEmpty( $entry['properties']['published'] ?? [] );
			$author = $entry['properties']['author'][0] ?? null;
			$this->assertIsArray( $author );
			$this->assertSame( 'Almanac Author', $author['properties']['name'][0] ?? null );
			$this->assertLessThanOrEqual( 1, count( $entry['properties']['weather'] ?? [] ) );
			if ( isset( $entry['properties']['name'] ) ) {
				$this->assertSame( [ 'Afternoon storm' ], $entry['properties']['name'], 'Only a real title is a p-name.' );
			}
			$this->assertArrayNotHasKey( 'location', $entry['properties'] ?? [], 'No place on the archive.' );
		}
	}

	public function test_a_titled_entry_names_itself_with_its_title(): void {
		$this->seed();
		$html  = $this->page_html();
		$items = \Mf2\parse( $html, home_url( '/' ) )['items'] ?? [];
		$names = [];
		$walk  = static function ( array $items ) use ( &$walk, &$names ): void {
			foreach ( $items as $item ) {
				if ( in_array( 'h-entry', $item['type'] ?? [], true ) && isset( $item['properties']['name'] ) ) {
					$names[] = $item['properties']['name'][0];
				}
				$walk( $item['children'] ?? [] );
			}
		};
		$walk( $items );

		$this->assertSame( [ 'Afternoon storm' ], $names );
	}

	// The editor preview.

	public function test_the_editor_preview_shows_the_newest_month(): void {
		$this->seed();
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . self::BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'weather' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$xp = $this->xpath( (string) $response->get_data()['rendered'] );
		$this->assertSame( 'August 2026', trim( $xp->query( '//h2' )->item( 0 )->textContent ) );
		$this->assertCount( 8, $this->entries( $xp ), 'The preview holds the same eight entries and none of the controls.' );
		$this->assertSame( 0, $xp->query( '//a[@href="' . get_permalink( $this->ids['X_protected'] ) . '"]' )->length );
	}

	public function test_the_editor_preview_of_an_empty_archive_is_empty(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . self::BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', trim( (string) $response->get_data()['rendered'] ) );
	}
}

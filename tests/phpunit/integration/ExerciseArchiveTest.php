<?php
/**
 * The exercise archive: whole weeks of workouts on each page (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * /kind/exercise/ is served by the plugin template, pages by whole weeks
 * (weeksPerPage 4) newest week first, and prints each week as seven day cells
 * with a one-line summary read from facts only.
 *
 * The fixtures follow the lane's plan: seven non-empty weeks, controls that
 * must never appear (a protected, a private and a draft post, a craft post)
 * and a pair of posts (A02, A03) that must print identically.
 *
 * @group integration
 */
final class ExerciseArchiveTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private string $original_date_format = '';

	/**
	 * Fixture IDs by plan key.
	 *
	 * @var array<string, int>
	 */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		$this->original_stylesheet  = get_stylesheet();
		$this->original_timezone    = (string) get_option( 'timezone_string' );
		$this->original_date_format = (string) get_option( 'date_format' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'start_of_week', 0 );
		delete_option( 'sloc_measurements' );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'date_format', $this->original_date_format );
		delete_option( 'sloc_measurements' );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * A card comment.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/exercise-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * One exercise fixture.
	 *
	 * @param string               $key   Plan key.
	 * @param string               $title Title.
	 * @param string               $date  Local date.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Extra post args.
	 */
	private function add( string $key, string $title, string $date, array $attrs, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_name'    => 'seed-245-' . strtolower( $key ),
					'post_content' => $this->card( $attrs ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'exercise', Taxonomy::TAXONOMY );
		$this->ids[ $key ] = (int) $id;

		return (int) $id;
	}

	/**
	 * The plan's fixture weeks.
	 */
	private function seed(): void {
		// Week August 23-29.
		$this->add(
			'A01',
			'Lakeside Loop',
			'2026-08-23 07:10:00',
			[
				'activity'            => 'Run',
				'distance'            => 5.0,
				'duration'            => 'PT29M12S',
				'splits'              => [
					[
						'i' => 1,
						't' => 'PT5M58S',
					],
					[
						'i' => 2,
						't' => 'PT5M51S',
					],
				],
				'personalBest'        => true,
				'pbCategory'          => '5k',
				'showDetailsPublicly' => true,
			]
		);
		$this->add(
			'A02',
			'Evening Walk',
			'2026-08-24 19:40:00',
			[
				'activity' => 'Walk',
				'distance' => 7.77,
				'duration' => 'PT1H32M',
			]
		);
		$this->add( 'A03', 'Evening Walk', '2026-08-25 19:40:00', [ 'activity' => 'Walk' ] );
		$this->add(
			'A04',
			'Sunrise Flow',
			'2026-08-26 06:30:00',
			[
				'activity'            => 'Yoga',
				'duration'            => 'PT45M',
				'showDetailsPublicly' => true,
			]
		);
		$this->add( 'A05', '', '2026-08-27 12:15:00', [ 'activity' => 'Bike ride' ] );
		$this->add(
			'A06',
			'Hill Repeats',
			'2026-08-29 23:30:00',
			[
				'activity'            => 'Run',
				'distance'            => 3.1,
				'distanceUnit'        => 'mi',
				'duration'            => 'PT27M40S',
				'showDetailsPublicly' => true,
			]
		);

		// Week August 16-22.
		$this->add(
			'B01',
			'Morning Swim',
			'2026-08-19 06:00:00',
			[
				'activity'            => 'Swim',
				'duration'            => 'PT40M',
				'showDetailsPublicly' => true,
			]
		);
		$this->add(
			'B02',
			'Lunch Walk',
			'2026-08-19 12:00:00',
			[
				'activity' => 'Walk',
				'distance' => 2.0,
			]
		);
		$this->add(
			'B03',
			'The Long Way Around the Reservoir and Back Past the Old Mill Bridge',
			'2026-08-19 18:00:00',
			[
				'activity'            => 'Hike',
				'distance'            => 12.4,
				'duration'            => 'PT2H58M',
				'showDetailsPublicly' => true,
			]
		);
		$this->add(
			'B04',
			'Tempo Run',
			'2026-08-22 08:00:00',
			[
				'activity'            => 'Run',
				'distance'            => 10.0,
				'duration'            => 'PT52M03S',
				'personalBest'        => true,
				'pbCategory'          => '10k',
				'showDetailsPublicly' => true,
			]
		);
		$this->add(
			'B05',
			'Track Night',
			'2026-08-21 19:00:00',
			[
				'activity'     => 'Run',
				'distance'     => 5.0,
				'duration'     => 'PT23M58S',
				'personalBest' => true,
				'pbCategory'   => '5k',
			]
		);
		$this->add(
			'B06',
			'Members Only Ride',
			'2026-08-20 18:00:00',
			[
				'activity'            => 'Cycle',
				'distance'            => 20.0,
				'duration'            => 'PT55M',
				'personalBest'        => true,
				'pbCategory'          => '10k',
				'showDetailsPublicly' => true,
			],
			[ 'post_password' => 'secret' ]
		);
		$this->add( 'B07', 'Quiet Recovery Walk', '2026-08-18 09:00:00', [ 'activity' => 'Walk' ], [ 'post_status' => 'private' ] );
		$this->add( 'B08', 'Draft Intervals', '2026-08-17 07:00:00', [ 'activity' => 'Run' ], [ 'post_status' => 'draft' ] );

		// Week August 9-15: no public value.
		$this->add(
			'C01',
			'Rest Day Stretch',
			'2026-08-09 08:00:00',
			[
				'activity' => 'Yoga',
				'duration' => 'PT20M',
			]
		);
		$legacy = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Ran Before Work',
				'post_date'    => '2026-08-11 06:45:00',
				'post_content' => '<!-- wp:paragraph --><p><span class="p-exercise">Ran 5 km in 29:12</span></p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $legacy, 'exercise', Taxonomy::TAXONOMY );
		$this->ids['C02'] = (int) $legacy;

		// Week August 2-8.
		$this->add(
			'D01',
			'Track Session',
			'2026-08-06 18:00:00',
			[
				'activity'            => 'Run',
				'distance'            => 6.0,
				'showDetailsPublicly' => true,
			],
			[
				'meta_input' => [
					'_pkiw_checkin_venue'   => 'Maplewood Track',
					'_pkiw_checkin_city'    => 'Fernvale',
					'_pkiw_checkin_address' => '12 Example Lane',
					'_pkiw_geo_privacy'     => 'approximate',
				],
			]
		);
		$this->add( 'D02', 'Park Laps', '2026-08-07 18:00:00', [ 'activity' => 'Run' ] );
		$this->add( 'D03', 'Map Walk', '2026-08-08 10:00:00', [ 'activity' => 'Walk' ] );

		// Edge weeks.
		$this->add( 'E01', 'Month-edge Walk', '2026-07-31 18:00:00', [ 'activity' => 'Walk' ] );
		$this->add(
			'F01',
			'New Year Plunge',
			'2026-01-01 11:00:00',
			[
				'activity'            => 'Swim',
				'duration'            => 'PT5M',
				'showDetailsPublicly' => true,
			]
		);

		// The newest week, October 11-17.
		$this->add(
			'S01',
			'Harbor Run',
			'2026-10-11 09:00:00',
			[
				'activity'            => 'Run',
				'distance'            => 8.0,
				'duration'            => 'PT41M10S',
				'showDetailsPublicly' => true,
			]
		);
		$this->add(
			'S02',
			'Harbor Walk',
			'2026-10-11 10:00:00',
			[
				'activity' => 'Walk',
				'distance' => 3.0,
			]
		);
		$this->add( 'S03', '', '2026-10-12 08:00:00', [ 'activity' => 'Row' ] );

		// Control: a craft post is not an exercise.
		$craft = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Scarf Swatch',
				'post_date'   => '2026-08-26 12:00:00',
			]
		);
		wp_set_object_terms( $craft, 'craft', Taxonomy::TAXONOMY );
		$this->ids['K01'] = (int) $craft;
	}

	private function archive_url(): string {
		$url = get_term_link( 'exercise', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/exercise/', $url );

		return $url;
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-exercise.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-exercise', $template->id );

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
	 * The week sections of a page: heading text => section element.
	 *
	 * @return array<string, DOMElement>
	 */
	private function weeks( DOMXPath $xpath ): array {
		$weeks = [];
		foreach ( $xpath->query( '//section[contains(concat(" ",normalize-space(@class)," ")," pkiw-exercise-week ")]' ) as $section ) {
			$heading = $xpath->query( './h2', $section )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $heading );
			$weeks[ trim( $heading->textContent ) ] = $section;
		}

		return $weeks;
	}

	private function summary( DOMXPath $xpath, DOMElement $week ): string {
		$node = $xpath->query( './p[contains(concat(" ",@class," ")," pkiw-exercise-week__summary ")]', $week )->item( 0 );

		return $node instanceof DOMNode ? preg_replace( '/\s+/', ' ', trim( $node->textContent ) ) : '';
	}

	public function test_page_one_holds_the_four_newest_weeks_and_page_two_the_rest(): void {
		$this->seed();

		$one = $this->xpath( $this->serve( $this->archive_url() ) );
		global $wp_query;
		$this->assertSame( 2, (int) $wp_query->max_num_pages );
		$this->assertSame(
			[ 'October 11–17, 2026', 'August 23–29, 2026', 'August 16–22, 2026', 'August 9–15, 2026' ],
			array_keys( $this->weeks( $one ) )
		);

		$two = $this->xpath( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame(
			[ 'August 2–8, 2026', 'July 26 – August 1, 2026', 'December 28, 2025 – January 3, 2026' ],
			array_keys( $this->weeks( $two ) )
		);

		$this->serve( $this->archive_url() . 'page/3/' );
		$this->assertTrue( is_404(), 'A page past the last week is a 404.' );
	}

	public function test_the_main_query_runs_newest_first_and_is_not_a_paged_limit_query(): void {
		$this->seed();
		$this->serve( $this->archive_url() );
		global $wp_query;

		$calendar = $wp_query->get( 'pkiw_calendar' );
		$this->assertSame( 'week', $calendar['unit'] );
		$this->assertSame( [ '2026-10-11', '2026-08-23', '2026-08-16', '2026-08-09' ], $calendar['keys'] );
		$this->assertSame( 2, $calendar['pages'] );
		$this->assertSame( -1, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( 'post_week', $wp_query->get( 'pkiw_group_source' ) );

		$ids    = array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) );
		$sorted = $ids;
		usort(
			$sorted,
			static function ( int $a, int $b ): int {
				$date = strcmp( (string) get_post_field( 'post_date', $b ), (string) get_post_field( 'post_date', $a ) );
				return 0 !== $date ? $date : $b <=> $a;
			}
		);
		$this->assertSame( $sorted, $ids, 'The engine orders post_date DESC, ID DESC, whatever the plan or section order says.' );
	}

	public function test_days_run_oldest_first_and_a_week_has_seven_cells(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );
		$weeks = $this->weeks( $xpath );

		foreach ( $weeks as $name => $week ) {
			$cells = $xpath->query( './ol[contains(concat(" ",@class," ")," pkiw-exercise-week__days ")]/li', $week );
			$this->assertSame( 7, $cells->length, $name );
			$this->assertSame( 1, $xpath->query( './ol[contains(@class,"pkiw-cal")][contains(@class,"pkiw-cal--week")]', $week )->length, $name );
			foreach ( $cells as $position => $cell ) {
				$this->assertStringContainsString( 'pkiw-cal__day', $cell->getAttribute( 'class' ), $name );
				$this->assertSame( (string) $position, $cell->getAttribute( 'data-pkiw-dow' ), 'Sunday start: cell ' . $position . ' of ' . $name );
				if ( str_contains( $cell->getAttribute( 'class' ), 'pkiw-exercise-day--empty' ) ) {
					$this->assertSame( 'true', $cell->getAttribute( 'aria-hidden' ), $name );
					$this->assertSame( '', $cell->textContent, 'An empty cell holds no text.' );
					$this->assertSame( 0, $xpath->query( './/a | .//*[@tabindex]', $cell )->length );
				}
			}
		}

		$aug_23 = $xpath->query( './ol/li', $weeks['August 23–29, 2026'] );
		$this->assertStringContainsString( 'post-' . $this->ids['A01'], $aug_23->item( 0 )->ownerDocument->saveHTML( $aug_23->item( 0 ) ) );
		$this->assertStringContainsString( 'post-' . $this->ids['A06'], $aug_23->item( 6 )->ownerDocument->saveHTML( $aug_23->item( 6 ) ), 'Saturday 23:30 local (03:30 UTC Sunday) files on Saturday.' );
		$this->assertSame( 'Sun, Aug 23', trim( $xpath->query( './time', $aug_23->item( 0 ) )->item( 0 )->textContent ) );
		$this->assertSame( '2026-08-29', $xpath->query( './time', $aug_23->item( 6 ) )->item( 0 )->getAttribute( 'datetime' ) );
	}

	public function test_a_day_with_three_workouts_lists_them_in_time_order_under_one_time(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );
		$weeks = $this->weeks( $xpath );

		$times = $xpath->query( './/time[@datetime="2026-08-19"]', $weeks['August 16–22, 2026'] );
		$this->assertSame( 1, $times->length );
		$cell = $times->item( 0 )->parentNode;
		$ids  = [];
		foreach ( $xpath->query( './ul/li', $cell ) as $item ) {
			preg_match( '/\bpost-(\d+)\b/', $item->getAttribute( 'class' ), $found );
			$ids[] = (int) $found[1];
		}
		$this->assertSame( [ $this->ids['B01'], $this->ids['B02'], $this->ids['B03'] ], $ids );
	}

	public function test_protected_private_and_draft_posts_and_other_kinds_never_appear_for_a_visitor(): void {
		$this->seed();
		$html = $this->serve( $this->archive_url() ) . $this->serve( $this->archive_url() . 'page/2/' );

		foreach ( [ 'B06', 'B07', 'B08', 'K01' ] as $key ) {
			$this->assertStringNotContainsString( 'post-' . $this->ids[ $key ] . ' ', $html . ' ', $key );
			$this->assertStringNotContainsString( (string) get_permalink( $this->ids[ $key ] ), $html, $key );
		}
		foreach ( [ 'Members Only Ride', 'Quiet Recovery Walk', 'Draft Intervals', 'Scarf Swatch', 'Protected:', 'Private:' ] as $text ) {
			$this->assertStringNotContainsString( $text, $html, $text );
		}
	}

	public function test_an_editor_still_gets_no_protected_post_on_a_calendar_page(): void {
		$this->seed();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = $this->serve( $this->archive_url() );

		$this->assertStringNotContainsString( 'Members Only Ride', $html );
		$this->assertStringNotContainsString( 'post-' . $this->ids['B06'] . ' ', $html . ' ' );
	}

	public function test_each_bib_has_one_rendered_link_and_prints_no_metric_or_image(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$items = $xpath->query( '//ul[contains(@class,"pkiw-exercise-day__bibs")]/li' );
		$this->assertGreaterThan( 10, $items->length );
		foreach ( $items as $item ) {
			$this->assertSame( 1, $xpath->query( './/a[@href][not(ancestor-or-self::*[@hidden])]', $item )->length, $item->getAttribute( 'class' ) );
			$this->assertSame( 0, $xpath->query( './/a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]', $item )->length );
			$this->assertSame( 0, $xpath->query( './/img', $item )->length );
			$text = $item->textContent;
			$this->assertDoesNotMatchRegularExpression( '/\b\d+(?:\.\d+)?\s?(?:km|mi)\b/', $text, 'No distance.' );
			$this->assertDoesNotMatchRegularExpression( '/\b\d{1,2}:\d{2}\b/', $text, 'No duration or clock time.' );
			$this->assertDoesNotMatchRegularExpression( '/\b(?:Splits|Personal best)\b/', $text );
		}
	}

	public function test_a_bib_carries_a_hidden_local_day_and_no_clock_time_anywhere(): void {
		$this->seed();
		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );

		$item = $xpath->query( '//li[contains(concat(" ",@class," ")," post-' . $this->ids['A06'] . ' ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $item );
		$data = $xpath->query( './/data[contains(concat(" ",@class," ")," dt-published ")]', $item );
		$this->assertSame( 1, $data->length, 'One published property.' );
		$this->assertSame( '2026-08-29', $data->item( 0 )->getAttribute( 'value' ), 'The local day, not the UTC day or a time.' );
		$this->assertTrue( $data->item( 0 )->hasAttribute( 'hidden' ), 'The published day is a hidden property.' );

		$wrapper = $xpath->query( '//div[contains(concat(" ",@class," ")," pkiw-exercise-log ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $wrapper );
		$markup = $wrapper->ownerDocument->saveHTML( $wrapper );
		$this->assertDoesNotMatchRegularExpression( '/T\d{2}:\d{2}/', $markup, 'No datetime with a time.' );
		$this->assertDoesNotMatchRegularExpression( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $markup );
	}

	public function test_titled_and_untitled_names(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$titled = $xpath->query( '//li[contains(concat(" ",@class," ")," post-' . $this->ids['A01'] . ' ")]//a[contains(@class,"u-url")]' )->item( 0 );
		$this->assertSame( 'Lakeside Loop', trim( $titled->textContent ) );
		$this->assertStringContainsString( 'p-name', $titled->getAttribute( 'class' ) );

		$untitled = $xpath->query( '//li[contains(concat(" ",@class," ")," post-' . $this->ids['A05'] . ' ")]//a[contains(@class,"u-url")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $untitled );
		$this->assertStringNotContainsString( 'p-name', $untitled->getAttribute( 'class' ) );
		$visible = '';
		foreach ( $xpath->query( './text()', $untitled ) as $node ) {
			$visible .= $node->nodeValue;
		}
		$this->assertSame( 'Exercise', trim( $visible ), 'The kind shows; the date sits in the screen-reader span.' );
		$this->assertSame( 'Exercise, August 27, 2026', trim( preg_replace( '/\s+/', ' ', $untitled->textContent ) ) );
	}

	public function test_a_long_title_prints_in_full(): void {
		$this->seed();
		$html = $this->serve( $this->archive_url() );

		$this->assertStringContainsString( 'The Long Way Around the Reservoir and Back Past the Old Mill Bridge', $html );
	}

	public function test_activity_prints_once_as_p_exercise_and_not_for_a_legacy_body(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$cell = $xpath->query( '//li[contains(concat(" ",@class," ")," post-' . $this->ids['A01'] . ' ")]' )->item( 0 );
		$this->assertSame( 1, $xpath->query( './/*[contains(concat(" ",@class," ")," p-exercise ")]', $cell )->length );
		$this->assertSame( 'Run', trim( $xpath->query( './/*[contains(@class,"p-exercise")]', $cell )->item( 0 )->textContent ) );

		$legacy = $xpath->query( '//li[contains(concat(" ",@class," ")," post-' . $this->ids['C02'] . ' ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $legacy );
		$this->assertSame( 0, $xpath->query( './/*[contains(@class,"p-exercise")]', $legacy )->length, 'No card, no activity row.' );
	}

	public function test_a02_prints_exactly_what_a03_prints(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$html_of = function ( string $key ) use ( $xpath ): string {
			$li = $xpath->query( '//li[contains(concat(" ",@class," ")," post-' . $this->ids[ $key ] . ' ")]' )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $li, $key );
			$html = $li->ownerDocument->saveHTML( $li );
			$html = (string) preg_replace( '/post-' . $this->ids[ $key ] . '\b/', 'post-N', $html );
			$html = (string) preg_replace( '#https?://[^"\']+#', 'URL', $html );
			$html = (string) preg_replace( '/\d{4}-\d{2}-\d{2}/', 'DAY', $html );
			$html = (string) preg_replace( '/\bseed-245-a0[23]\b/', 'seed', $html );
			$html = (string) preg_replace( '/\b(?:category|tag)-[\w-]+/', '', $html );

			return trim( (string) preg_replace( '/\s+/', ' ', $html ) );
		};

		$this->assertSame( $html_of( 'A03' ), $html_of( 'A02' ), 'A private workout prints exactly like one that stored nothing.' );
		$this->assertStringNotContainsString( '7.77', $html_of( 'A02' ) );
	}

	public function test_summaries_read_visible_values_only_in_kilometers_with_no_measurement_option(): void {
		$this->seed();
		$one = $this->xpath( $this->serve( $this->archive_url() ) );
		$wk  = $this->weeks( $one );

		$this->assertSame( '3 workouts · 8.0 km · 41 min', $this->summary( $one, $wk['October 11–17, 2026'] ) );
		$this->assertSame( '6 workouts · 10.0 km · 1 hr 42 min', $this->summary( $one, $wk['August 23–29, 2026'] ) );
		$this->assertSame( '5 workouts · 22.4 km · 4 hr 30 min', $this->summary( $one, $wk['August 16–22, 2026'] ), 'The protected ride is not in the week at all.' );
		$this->assertSame( '2 workouts', $this->summary( $one, $wk['August 9–15, 2026'] ), 'Counts only: no public value.' );

		$two = $this->xpath( $this->serve( $this->archive_url() . 'page/2/' ) );
		$wk2 = $this->weeks( $two );
		$this->assertSame( '3 workouts · 6.0 km', $this->summary( $two, $wk2['August 2–8, 2026'] ) );
		$this->assertSame( '1 workout', $this->summary( $two, $wk2['July 26 – August 1, 2026'] ) );
		$this->assertSame( '1 workout · 5 min', $this->summary( $two, $wk2['December 28, 2025 – January 3, 2026'] ) );
	}

	public function test_summaries_print_miles_on_an_imperial_site(): void {
		$this->seed();
		update_option( 'sloc_measurements', 'imperial' );
		$one = $this->xpath( $this->serve( $this->archive_url() ) );
		$wk  = $this->weeks( $one );

		$this->assertSame( '3 workouts · 5.0 mi · 41 min', $this->summary( $one, $wk['October 11–17, 2026'] ) );
		$this->assertSame( '6 workouts · 6.2 mi · 1 hr 42 min', $this->summary( $one, $wk['August 23–29, 2026'] ) );
		$this->assertSame( '5 workouts · 13.9 mi · 4 hr 30 min', $this->summary( $one, $wk['August 16–22, 2026'] ) );

		$two = $this->xpath( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame( '3 workouts · 3.7 mi', $this->summary( $two, $this->weeks( $two )['August 2–8, 2026'] ) );
	}

	public function test_a_stale_post_row_cannot_put_a_hidden_value_into_a_total(): void {
		$this->seed();
		// A02's row says visible while its card says hidden: the total must still skip it.
		update_post_meta( $this->ids['A02'], '_pkiw_exercise_details_public', '1' );
		$one = $this->xpath( $this->serve( $this->archive_url() ) );

		$this->assertSame( '6 workouts · 10.0 km · 1 hr 42 min', $this->summary( $one, $this->weeks( $one )['August 23–29, 2026'] ) );
	}

	public function test_a_card_toggle_that_is_on_cannot_put_a_value_into_a_total_without_its_row(): void {
		$this->seed();
		delete_post_meta( $this->ids['A01'], '_pkiw_exercise_details_public' );
		$one = $this->xpath( $this->serve( $this->archive_url() ) );

		// A01 (5.00 km, 29:12) drops out; A06 (3.10 mi, 27:40) and A04 (45:00) stay.
		$this->assertSame( '6 workouts · 5.0 km · 1 hr 13 min', $this->summary( $one, $this->weeks( $one )['August 23–29, 2026'] ) );
	}

	public function test_no_text_names_visibility_a_route_or_a_marker(): void {
		$this->seed();
		$html  = $this->serve( $this->archive_url() );
		$xpath = $this->xpath( $html );
		$log   = $xpath->query( '//div[contains(concat(" ",@class," ")," pkiw-exercise-log ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $log );

		$visible = '';
		foreach ( $xpath->query( './/text()[not(ancestor-or-self::*[@hidden])]', $log ) as $node ) {
			$visible .= ' ' . $node->nodeValue;
		}
		foreach ( [ 'Route', 'Public', 'Private', 'private', 'Show details', 'Protected' ] as $word ) {
			$this->assertStringNotContainsString( $word, $visible, $word );
		}
		$this->assertDoesNotMatchRegularExpression( '/\block(?:ed)?\b|\bglobe\b/i', $visible );
		$this->assertDoesNotMatchRegularExpression( '/[\x{1F512}\x{1F513}\x{1F510}\x{1F30D}\x{1F30E}\x{1F30F}\x{1F310}]/u', $visible );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:heart|calorie|bpm)/i', $visible );
	}

	public function test_no_page_holds_a_hidden_metric_anywhere_in_its_markup(): void {
		$this->seed();
		$html = $this->serve( $this->archive_url() ) . $this->serve( $this->archive_url() . 'page/2/' );

		foreach ( [ '7.77', 'PT1H32M', 'PT23M58S', '23:58', '1:32:00', '2.00' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
		foreach ( [ '/\bP(?:T\d+[HMS])+\b/' ] as $pattern ) {
			$this->assertDoesNotMatchRegularExpression( $pattern, $html, 'No ISO duration reaches an archive item.' );
		}
	}

	public function test_the_kind_feed_keeps_core_order_and_the_site_feed_size(): void {
		$this->seed();
		update_option( 'posts_per_rss', 5 );
		$this->go_to( get_term_feed_link( (int) get_term_by( 'slug', 'exercise', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		global $wp_query;

		$this->assertTrue( is_feed() );
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );
		$this->assertFalse( is_array( $wp_query->get( 'pkiw_calendar' ) ), 'A feed is never paged by week.' );
		$this->assertCount( 5, $wp_query->posts );
		$dates  = wp_list_pluck( $wp_query->posts, 'post_date' );
		$sorted = $dates;
		rsort( $sorted );
		$this->assertSame( $sorted, $dates, 'Newest first.' );
	}

	public function test_the_kind_feed_lists_no_protected_private_or_draft_post_for_a_visitor(): void {
		$this->seed();
		update_option( 'posts_per_rss', 100 );
		$this->go_to( get_term_feed_link( (int) get_term_by( 'slug', 'exercise', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$this->assertCount( 21, $ids, 'The 22 published fixtures less the protected ride.' );
		foreach ( [ 'B06', 'B07', 'B08', 'K01' ] as $key ) {
			$this->assertNotContains( $this->ids[ $key ], $ids, $key );
		}
		foreach ( [ 'A01', 'A02', 'C02', 'S03' ] as $key ) {
			$this->assertContains( $this->ids[ $key ], $ids, $key );
		}
	}

	public function test_a_protected_workout_stays_in_other_feeds_but_with_no_card_content(): void {
		$this->seed();
		$GLOBALS['post'] = get_post( $this->ids['B06'] );
		setup_postdata( $GLOBALS['post'] );
		$this->go_to( home_url( '/feed/' ) );
		$GLOBALS['post'] = get_post( $this->ids['B06'] );
		setup_postdata( $GLOBALS['post'] );

		$content = (string) apply_filters( 'the_content_feed', (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $this->ids['B06'] ) ), 'rss2' );
		foreach ( [ '20.00', 'Cycle', 'Personal best', '55:00' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $content, $needle );
		}
	}

	public function test_an_empty_archive_prints_the_no_results_block_and_no_week(): void {
		( new Taxonomy() )->create_default_terms();
		$xpath = $this->xpath( $this->serve( $this->archive_url() ) );

		$this->assertSame( 0, $xpath->query( '//section[contains(@class,"pkiw-exercise-week")]' )->length );
		$this->assertStringContainsString( 'No workouts yet.', $xpath->document->textContent );
	}
}

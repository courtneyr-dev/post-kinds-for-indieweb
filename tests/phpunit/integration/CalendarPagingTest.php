<?php
/**
 * Calendar paging for kind archives: one year, month or run of whole weeks per page (W4-P0E).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Date_Source;

/**
 * A kind's own class answers the `pkiw_calendar_paging` filter when its
 * calendar block is in the resolved template; the engine then pages by
 * calendar unit instead of by post count. The kind classes arrive in other
 * lanes, so each test hooks a stand-in that answers the way they will: mood
 * (year, ascending), weather (month, ascending) and exercise (whole weeks,
 * newest first, the count read from the entry block). Titles are invented.
 *
 * @group integration
 */
final class CalendarPagingTest extends WP_UnitTestCase {

	private const YEAR_BLOCK  = 'pkiw-test/cal-year';
	private const MONTH_BLOCK = 'pkiw-test/cal-month';
	private const WEEK_ENTRY  = 'pkiw-test/cal-week-entry';
	private const NOTE_ENTRY  = 'post-kinds-indieweb/archive-sections';

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Template content swapped in for every kind archive.
	 *
	 * @var string
	 */
	private string $template = '';

	/**
	 * Swaps the template content in.
	 *
	 * @var callable
	 */
	private $swap;

	/**
	 * Answers pkiw_calendar_paging the way the kind classes will.
	 *
	 * @var callable
	 */
	private $paging;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 10 );
		update_option( 'posts_per_rss', 3 );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'start_of_week', 0 );

		Grouped_Archive::register_entry_block( self::WEEK_ENTRY, [ 'source' => 'post_week' ] );
		register_block_type(
			self::WEEK_ENTRY,
			[
				'api_version' => 3,
				'attributes'  => Grouped_Archive::entry_attributes(
					[
						'weeksPerPage' => [
							'type'    => 'integer',
							'default' => 4,
						],
					]
				),
			]
		);

		$this->swap = function ( $templates ) {
			foreach ( $templates as $found ) {
				if ( str_starts_with( $found->slug, 'taxonomy-kind' ) ) {
					$found->content = $this->template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $this->swap );

		$this->paging = static function ( $plan, $term, $content ) {
			if ( 'mood' === $term->slug && str_contains( $content, 'wp:' . self::YEAR_BLOCK ) ) {
				return [
					'unit'  => 'year',
					'order' => 'ASC',
				];
			}
			if ( 'weather' === $term->slug && str_contains( $content, 'wp:' . self::MONTH_BLOCK ) ) {
				return [
					'unit'  => 'month',
					'order' => 'ASC',
				];
			}
			if ( 'exercise' === $term->slug && 1 === preg_match( '/wp:' . preg_quote( self::WEEK_ENTRY, '/' ) . '(?:\s+(\{[^}]*\}))?/', $content, $found ) ) {
				$attrs = json_decode( $found[1] ?? '{}', true );
				return [
					'unit'  => 'week',
					'count' => (int) ( is_array( $attrs ) ? ( $attrs['weeksPerPage'] ?? 4 ) : 4 ),
					'order' => 'DESC',
				];
			}
			return $plan;
		};
		add_filter( 'pkiw_calendar_paging', $this->paging, 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'get_block_templates', $this->swap );
		remove_filter( 'pkiw_calendar_paging', $this->paging, 10 );
		Grouped_Archive::unregister_entry_block( self::WEEK_ENTRY );
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::WEEK_ENTRY ) ) {
			unregister_block_type( self::WEEK_ENTRY );
		}
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A published post of a kind.
	 *
	 * @param string $kind   Kind slug.
	 * @param string $title  Title.
	 * @param string $date   Post date (site time).
	 * @param string $status Post status.
	 */
	private function make( string $kind, string $title, string $date, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			[
				'post_status' => $status,
				'post_title'  => $title,
				'post_date'   => $date,
			]
		);
		wp_set_object_terms( $id, $kind, 'kind' );

		return $id;
	}

	/**
	 * An inheriting Query Loop, then any extra blocks.
	 *
	 * @param string $extra Blocks after the loop, such as a calendar block.
	 * @param string $entry An entry block for the Post Template.
	 */
	private function loop( string $extra = '', string $entry = '' ): string {
		return '<!-- wp:query {"queryId":3,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /-->' . $entry . '<!-- /wp:post-template --></div><!-- /wp:query -->' . $extra;
	}

	/**
	 * Request a kind archive page.
	 *
	 * @param string               $kind Kind slug.
	 * @param int                  $page Page number.
	 * @param array<string, mixed> $args Extra query args.
	 */
	private function visit( string $kind, int $page = 1, array $args = [] ): void {
		$url = get_term_link( $kind, 'kind' );
		$this->assertIsString( $url );
		if ( $page > 1 ) {
			$args['paged'] = $page;
		}
		$this->go_to( $args ? add_query_arg( $args, $url ) : $url );
	}

	/**
	 * The current main query. go_to() builds a new one, so this is read afresh each time.
	 */
	private function query(): WP_Query {
		return $GLOBALS['wp_query'];
	}

	/**
	 * Titles the main query holds, in order.
	 *
	 * @return string[]
	 */
	private function titles(): array {

		return array_map( static fn( WP_Post $post ): string => $post->post_title, $this->query()->posts );
	}

	/**
	 * The pkiw_calendar query var.
	 *
	 * @return array<string, mixed>
	 */
	private function calendar(): array {
		$var = $this->query()->get( 'pkiw_calendar' );

		return is_array( $var ) ? $var : [];
	}

	/**
	 * Mood posts across three years, one late on New Year's Eve.
	 *
	 * @return array<string, int>
	 */
	private function moods(): array {
		return [
			'sep26' => $this->make( 'mood', 'M Sep 2026', '2026-09-03 10:00:00' ),
			'jan26' => $this->make( 'mood', 'M Jan 2026', '2026-01-15 10:00:00' ),
			'jun25' => $this->make( 'mood', 'M Jun 2025', '2025-06-01 10:00:00' ),
			'eve24' => $this->make( 'mood', 'M Eve 2024', '2024-12-31 23:30:00' ),
			'feb24' => $this->make( 'mood', 'M Feb 2024', '2024-02-02 10:00:00' ),
		];
	}

	/**
	 * Weather posts across four months.
	 *
	 * @return array<string, int>
	 */
	private function weathers(): array {
		return [
			'aug_a' => $this->make( 'weather', 'W Aug 3', '2026-08-03 10:00:00' ),
			'aug_b' => $this->make( 'weather', 'W Aug 22', '2026-08-22 10:00:00' ),
			'jun'   => $this->make( 'weather', 'W Jun 6', '2026-06-06 10:00:00' ),
			'dec'   => $this->make( 'weather', 'W Dec 9', '2025-12-09 10:00:00' ),
			'nov'   => $this->make( 'weather', 'W Nov 2', '2025-11-02 10:00:00' ),
		];
	}

	/**
	 * Exercise posts in seven non-empty weeks (Sunday start), one week across a year edge.
	 *
	 * @return array<string, int>
	 */
	private function workouts(): array {
		return [
			'w1a' => $this->make( 'exercise', 'E w1 Sep 2', '2026-09-02 07:00:00' ),
			'w1b' => $this->make( 'exercise', 'E w1 Aug 30', '2026-08-30 07:00:00' ),
			'w2a' => $this->make( 'exercise', 'E w2 Aug 29 late', '2026-08-29 23:30:00' ),
			'w2b' => $this->make( 'exercise', 'E w2 Aug 25', '2026-08-25 07:00:00' ),
			'w3'  => $this->make( 'exercise', 'E w3 Aug 17', '2026-08-17 07:00:00' ),
			'w4'  => $this->make( 'exercise', 'E w4 Aug 12', '2026-08-12 07:00:00' ),
			'w5'  => $this->make( 'exercise', 'E w5 Aug 4', '2026-08-04 07:00:00' ),
			'w6'  => $this->make( 'exercise', 'E w6 Jul 29', '2026-07-29 07:00:00' ),
			'w7a' => $this->make( 'exercise', 'E w7 Jan 2', '2026-01-02 07:00:00' ),
			'w7b' => $this->make( 'exercise', 'E w7 Dec 30', '2025-12-30 07:00:00' ),
		];
	}

	// Year.

	public function test_a_mood_archive_pages_by_year_oldest_first_within_a_page(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->moods();

		$this->visit( 'mood', 1 );
		$this->assertSame( [ 'M Jan 2026', 'M Sep 2026' ], $this->titles(), '2026 is page 1, its posts oldest first.' );
		$this->assertSame( 3, (int) $this->query()->max_num_pages );
		$this->assertSame( -1, (int) $this->query()->get( 'posts_per_page' ) );
		$this->assertFalse( $this->query()->is_404() );
		$this->assertSame(
			[
				'unit'  => 'year',
				'key'   => '2026',
				'keys'  => [ '2026' ],
				'page'  => 1,
				'pages' => 3,
			],
			$this->calendar()
		);

		$this->visit( 'mood', 2 );
		$this->assertSame( [ 'M Jun 2025' ], $this->titles() );
		$this->assertSame( '2025', $this->calendar()['key'] );

		$this->visit( 'mood', 3 );
		$this->assertSame( [ 'M Feb 2024', 'M Eve 2024' ], $this->titles(), '11:30 pm on New Year\'s Eve files in 2024.' );
		$this->assertSame( 3, (int) $this->query()->max_num_pages );

		$this->visit( 'mood', 4 );
		$this->assertTrue( $this->query()->is_404(), 'Page 4 is past the last year.' );
		$this->assertSame( [], $this->titles() );
	}

	public function test_an_empty_calendar_archive_shows_no_results_on_page_one_and_404s_after(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->make( 'mood', 'Draft only', '2026-01-01 10:00:00', 'draft' );

		$this->visit( 'mood', 1 );
		$this->assertFalse( $this->query()->is_404(), 'The archive exists; it has nothing yet.' );
		$this->assertSame( [], $this->titles() );
		$this->assertSame( 0, $this->calendar()['pages'] );
		$this->assertSame( 0, (int) $this->query()->max_num_pages );

		$this->visit( 'mood', 2 );
		$this->assertTrue( $this->query()->is_404() );
	}

	// Month.

	public function test_a_weather_archive_pages_by_month(): void {
		$this->template = $this->loop( '<!-- wp:' . self::MONTH_BLOCK . ' /-->' );
		$this->weathers();

		$this->visit( 'weather', 1 );
		$this->assertSame( [ 'W Aug 3', 'W Aug 22' ], $this->titles() );
		$this->assertSame( 4, (int) $this->query()->max_num_pages );
		$this->assertSame( '2026-08', $this->calendar()['key'] );

		$this->visit( 'weather', 2 );
		$this->assertSame( [ 'W Jun 6' ], $this->titles() );
		$this->visit( 'weather', 3 );
		$this->assertSame( [ 'W Dec 9' ], $this->titles() );
		$this->visit( 'weather', 4 );
		$this->assertSame( [ 'W Nov 2' ], $this->titles() );

		$this->visit( 'weather', 5 );
		$this->assertTrue( $this->query()->is_404(), '/page/5/ is past the fourth month.' );
	}

	// Week.

	public function test_an_exercise_archive_pages_by_whole_weeks_newest_first(): void {
		$this->template = $this->loop( '', '<!-- wp:' . self::WEEK_ENTRY . ' {"weeksPerPage":4} /-->' );
		$posts          = $this->workouts();

		$this->visit( 'exercise', 1 );
		$this->assertSame(
			[ 'E w1 Sep 2', 'E w1 Aug 30', 'E w2 Aug 29 late', 'E w2 Aug 25', 'E w3 Aug 17', 'E w4 Aug 12' ],
			$this->titles(),
			'Four whole weeks, newest first. 11:30 pm on August 29 in New York is in the week of August 23.'
		);
		$this->assertSame( 2, (int) $this->query()->max_num_pages );
		$this->assertSame( 'week', $this->calendar()['unit'] );
		$this->assertSame( '2026-08-30', $this->calendar()['key'], 'The page\'s newest week.' );
		$this->assertSame( [ '2026-08-30', '2026-08-23', '2026-08-16', '2026-08-09' ], $this->calendar()['keys'] );
		$this->assertSame( 'post_week', $this->query()->get( 'pkiw_group_source' ), 'The sections need the week source on the main query.' );
		$this->assertSame( -1, (int) $this->query()->get( 'posts_per_page' ) );

		$this->visit( 'exercise', 2 );
		$this->assertSame( [ 'E w5 Aug 4', 'E w6 Jul 29', 'E w7 Jan 2', 'E w7 Dec 30' ], $this->titles(), 'Three weeks; the last spans the year edge.' );
		$this->assertSame( 2, $this->calendar()['page'] );

		$this->visit( 'exercise', 3 );
		$this->assertTrue( $this->query()->is_404() );
		$this->assertCount( 10, $posts );
	}

	public function test_lines_per_page_beside_weeks_per_page_changes_nothing(): void {
		$this->template = $this->loop( '', '<!-- wp:' . self::WEEK_ENTRY . ' {"weeksPerPage":4,"linesPerPage":3} /-->' );
		$this->workouts();

		$this->visit( 'exercise', 1 );
		$this->assertCount( 6, $this->query()->posts, 'Four whole weeks, not three posts.' );
		$this->assertSame( -1, (int) $this->query()->get( 'posts_per_page' ) );
		$this->assertSame( 2, (int) $this->query()->max_num_pages );
	}

	public function test_the_week_count_follows_the_entry_block_and_clamps_to_twelve(): void {
		$this->workouts();

		$this->template = $this->loop( '', '<!-- wp:' . self::WEEK_ENTRY . ' {"weeksPerPage":2} /-->' );
		$this->visit( 'exercise', 1 );
		$this->assertSame( 4, (int) $this->query()->max_num_pages, 'Seven weeks, two to a page.' );

		$this->template = $this->loop( '', '<!-- wp:' . self::WEEK_ENTRY . ' {"weeksPerPage":99} /-->' );
		$this->visit( 'exercise', 1 );
		$this->assertSame( 1, (int) $this->query()->max_num_pages, 'Twelve is the most; seven weeks fit.' );
		$this->assertCount( 7, $this->calendar()['keys'] );
	}

	public function test_a_plan_count_below_one_reads_as_one_and_an_order_that_is_not_desc_reads_as_asc(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->moods();
		$plan = static fn( $found ) => is_array( $found ) ? [
			'unit'  => 'year',
			'count' => 0,
			'order' => 'sideways',
		] : $found;
		add_filter( 'pkiw_calendar_paging', $plan, 20 );

		$this->visit( 'mood', 1 );
		$this->assertSame( 3, (int) $this->query()->max_num_pages, 'A count of 0 becomes 1: three years, three pages.' );
		$this->assertSame( [ 'M Jan 2026', 'M Sep 2026' ], $this->titles(), 'Oldest first.' );

		remove_filter( 'pkiw_calendar_paging', $plan, 20 );
		add_filter(
			'pkiw_calendar_paging',
			static fn( $found ) => is_array( $found ) ? [
				'unit'  => 'year',
				'count' => 2,
				'order' => 'desc',
			] : $found,
			20
		);
		$this->visit( 'mood', 1 );
		$this->assertSame( 2, (int) $this->query()->max_num_pages, 'Three years, two to a page.' );
		$this->assertSame( [ 'M Sep 2026', 'M Jan 2026', 'M Jun 2025' ], $this->titles(), 'Two years, newest post first.' );
		$this->assertSame( [ '2026', '2025' ], $this->calendar()['keys'] );
	}

	public function test_a_plan_with_an_unknown_unit_is_ignored(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->moods();
		add_filter( 'pkiw_calendar_paging', static fn() => [ 'unit' => 'decade' ], 20 );

		$this->visit( 'mood', 1 );
		$this->assertSame( [], $this->calendar() );
		$this->assertSame( 10, (int) $this->query()->get( 'posts_per_page' ) );
	}

	// Where it doesn't apply.

	public function test_nothing_maps_without_the_kinds_block_in_the_template(): void {
		$this->template = $this->loop();
		$this->moods();

		$this->visit( 'mood', 1 );
		$this->assertSame( [], $this->calendar() );
		$this->assertSame( 10, (int) $this->query()->get( 'posts_per_page' ) );
		$this->assertCount( 5, $this->query()->posts );
	}

	public function test_a_date_source_alone_keeps_count_paging(): void {
		$this->template = $this->loop( '', '<!-- wp:' . self::NOTE_ENTRY . ' {"linesPerPage":12} /-->' );
		for ( $i = 1; $i <= 14; $i++ ) {
			$this->make( 'note', "N{$i}", sprintf( '2026-08-%02d 09:00:00', $i ) );
		}

		$this->visit( 'note', 1 );
		$this->assertSame( [], $this->calendar(), 'No calendar block, so the filter returns null.' );
		$this->assertSame( 12, (int) $this->query()->get( 'posts_per_page' ) );
		$this->assertSame( 'post_week', $this->query()->get( 'pkiw_group_source' ), 'The note archive sections by week through the shared source.' );
		$this->assertSame( 2, (int) $this->query()->max_num_pages );
		$this->assertCount( 12, $this->query()->posts );
	}

	public function test_search_and_feeds_keep_core_queries(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->moods();

		$this->visit( 'mood', 1, [ 's' => 'M' ] );
		$this->assertTrue( $this->query()->is_search() );
		$this->assertSame( [], $this->calendar(), 'A search takes no calendar plan.' );
		$this->assertNotSame( -1, (int) $this->query()->get( 'posts_per_page' ) );

		$link = get_term_link( 'mood', 'kind' );
		$this->assertIsString( $link );
		update_option( 'posts_per_rss', 2 );
		$this->go_to( add_query_arg( 'feed', 'rss2', $link ) );
		$this->assertTrue( $this->query()->is_feed() && $this->query()->is_tax( 'kind', 'mood' ) );
		$this->assertSame( [], $this->calendar(), 'The feed carries no calendar var.' );
		$this->assertSame( 2, (int) $this->query()->get( 'posts_per_page' ), 'The feed keeps posts_per_rss.' );
		$this->assertSame( [ 'M Sep 2026', 'M Jan 2026' ], $this->titles(), 'Newest first.' );
	}

	public function test_an_explicit_orderby_leaves_the_query_alone(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->moods();

		$this->visit( 'mood', 1, [ 'orderby' => 'title' ] );
		$this->assertSame( [], $this->calendar() );
		$this->assertSame( 10, (int) $this->query()->get( 'posts_per_page' ) );
	}

	public function test_the_block_renderer_preview_keeps_core_queries(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->moods();

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/pkiw-test/anything' );
		apply_filters( 'rest_request_before_callbacks', null, [], $request );
		$this->assertTrue( Grouped_Archive::is_block_preview() );
		$this->visit( 'mood', 1 );
		apply_filters( 'rest_request_after_callbacks', null, [], $request );

		$this->assertSame( [], $this->calendar(), 'The editor previews through the block renderer; no calendar plan.' );
		$this->assertFalse( Grouped_Archive::is_block_preview() );
		$this->assertNotSame( -1, (int) $this->query()->get( 'posts_per_page' ) );
	}

	// Who sees what.

	public function test_only_public_published_posts_make_a_unit_or_appear(): void {
		global $wpdb;
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->make( 'mood', 'Public 2026', '2026-03-01 10:00:00' );
		$this->make( 'mood', 'Private 2023', '2023-03-01 10:00:00', 'private' );
		$this->make( 'mood', 'Draft 2022', '2022-03-01 10:00:00', 'draft' );
		$this->make( 'mood', 'Future 2099', '2099-03-01 10:00:00', 'future' );
		$locked = $this->make( 'mood', 'Locked 2021', '2021-03-01 10:00:00' );
		$spaces = $this->make( 'mood', 'Spaces 2020', '2020-03-01 10:00:00' );
		$mixed  = $this->make( 'mood', 'Locked 2026', '2026-03-02 10:00:00' );
		wp_update_post(
			[
				'ID'            => $locked,
				'post_password' => 'sesame',
			]
		);
		wp_update_post(
			[
				'ID'            => $mixed,
				'post_password' => 'sesame',
			]
		);
		$wpdb->update( $wpdb->posts, [ 'post_password' => '   ' ], [ 'ID' => $spaces ] );
		clean_post_cache( $spaces );

		$seen = [];
		foreach ( [ 'visitor' => 0, 'admin' => self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $who => $user ) {
			wp_set_current_user( $user );
			$this->visit( 'mood', 1 );
			$seen[ $who ] = [ $this->titles(), (int) $this->query()->max_num_pages ];
			$this->visit( 'mood', 2 );
			$this->assertTrue( $this->query()->is_404(), "{$who}: only 2026 is a page; the password, space-password, private, draft and future posts add none." );
		}

		$this->assertSame( [ [ 'Public 2026' ], 1 ], $seen['visitor'], 'The password post in 2026 is absent from the query, not only from the markup.' );
		$this->assertSame( $seen['visitor'], $seen['admin'], 'An administrator sees what a visitor sees.' );
	}

	public function test_a_post_moved_into_the_kind_adds_its_unit_on_the_next_request(): void {
		$this->template = $this->loop( '<!-- wp:' . self::YEAR_BLOCK . ' /-->' );
		$this->make( 'mood', 'Mood 2026', '2026-03-01 10:00:00' );
		$other = $this->make( 'note', 'Was a note', '2023-03-01 10:00:00' );

		$this->visit( 'mood', 1 );
		$this->assertSame( 1, (int) $this->query()->max_num_pages );

		wp_set_object_terms( $other, 'mood', 'kind' );

		$this->visit( 'mood', 1 );
		$this->assertSame( 2, (int) $this->query()->max_num_pages, 'No post update, only a term change.' );
		$this->visit( 'mood', 2 );
		$this->assertSame( [ 'Was a note' ], $this->titles() );
	}

	public function test_units_come_back_newest_first_for_each_unit_and_week_start(): void {
		$this->workouts();
		$term = get_term_by( 'slug', 'exercise', 'kind' );
		$this->assertInstanceOf( WP_Term::class, $term );

		$this->assertSame(
			[ '2026-08-30', '2026-08-23', '2026-08-16', '2026-08-09', '2026-08-02', '2026-07-26', '2025-12-28' ],
			Date_Source::units( $term, 'week' )
		);
		$this->assertSame( [ '2026-09', '2026-08', '2026-07', '2026-01', '2025-12' ], Date_Source::units( $term, 'month' ) );
		$this->assertSame( [ '2026', '2025' ], Date_Source::units( $term, 'year' ) );

		update_option( 'start_of_week', 1 );
		$this->assertSame(
			[ '2026-08-31', '2026-08-24', '2026-08-17', '2026-08-10', '2026-08-03', '2026-07-27', '2025-12-29' ],
			Date_Source::units( $term, 'week' ),
			'The week start is part of the cache key: a Monday start gives Mondays.'
		);
	}
}

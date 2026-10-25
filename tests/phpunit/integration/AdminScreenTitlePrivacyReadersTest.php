<?php
/**
 * Hidden location titles in the readers left after W4 (issue 434).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Acquisition_Archive;
use PKIW\Checkin_Map;
use PKIW\Chicken_Archive;
use PKIW\Kind_Archive_Layouts;
use PKIW\Meta_Fields;
use PKIW\Repost_Archive;
use PKIW\Review_Archive;
use PKIW\Staff_Picks;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;
use PKIW\Venue_Taxonomy;

/**
 * Title_Privacy::filter_the_title() returns the stored title on an admin
 * screen. Each test renders a reader for a post whose title was generated
 * from a hidden location under the edit-post screen, checks the stored title
 * never prints, and checks the admin screen prints what a visitor gets.
 *
 * @group integration
 */
final class AdminScreenTitlePrivacyReadersTest extends WP_UnitTestCase {

	private const TITLE = 'Checked in at Zephyr Tea Room';

	private const SAFE = 'Check-in, August 26, 2026';

	private const COVER = 'https://example.com/cover.jpg';

	/**
	 * What the hidden title must never leave behind, lowercase.
	 *
	 * @var string[]
	 */
	private const NEEDLES = [ 'zephyr', 'tea room', 'tea-room', 'checked in at' ];

	/**
	 * Timezone active before each test.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		delete_option( 'pkiw_settings' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'], $GLOBALS['post'] );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		update_option( 'timezone_string', $this->original_timezone );
		parent::tear_down();
	}

	/**
	 * A post whose stored title was generated from a location that is hidden.
	 *
	 * @param string               $kind    Kind slug.
	 * @param array<string, mixed> $meta    Extra post meta, key => value.
	 * @param string               $content Post content.
	 */
	private function hidden_title_post( string $kind, array $meta = [], string $content = '' ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => self::TITLE,
				'post_date'    => '2026-08-26 09:15:00',
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		update_post_meta( $id, Meta_Fields::PREFIX . 'checkin_name', 'Zephyr Tea Room' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		Title_Privacy::mark_location_title( $id );
		clean_post_cache( $id );

		return $id;
	}

	private function admin_screen(): void {
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin(), 'The request looks like an admin screen.' );
	}

	private function assertNoHiddenTitle( string $haystack, string $where ): void {
		$lower = strtolower( $haystack );
		foreach ( self::NEEDLES as $needle ) {
			$this->assertStringNotContainsString( $needle, $lower, $where . ': ' . $needle );
		}
	}

	/**
	 * Render a block as a template would.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function block( string $name, array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Call a private static reader method.
	 *
	 * @param string $class_name Class.
	 * @param string $method     Method.
	 * @param mixed  ...$args    Arguments.
	 * @return mixed
	 */
	private function call( string $class_name, string $method, ...$args ) {
		return ( new ReflectionMethod( $class_name, $method ) )->invoke( null, ...$args );
	}

	/**
	 * Render on the front end, then on an admin screen.
	 *
	 * @param callable $render Returns the reader's output.
	 * @return array{string, string} Front-end output, admin-screen output.
	 */
	private function front_and_admin( callable $render ): array {
		$front = (string) $render();
		$this->admin_screen();
		$admin = (string) $render();

		return [ $front, $admin ];
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Elements carrying p-name in some markup.
	 *
	 * @param string $html Markup.
	 */
	private function p_names( string $html ): DOMNodeList {
		return $this->xpath( $html )->query( '//*[contains(concat(" ",normalize-space(@class)," ")," p-name ")]' );
	}

	public function test_recent_kinds_never_prints_a_hidden_location_title(): void {
		$this->hidden_title_post( 'checkin' );

		[ $front, $admin ] = $this->front_and_admin(
			fn() => $this->block(
				'post-kinds/recent-kinds',
				[
					'kind'      => 'checkin',
					'count'     => 5,
					'showCover' => false,
				]
			)
		);

		$this->assertNoHiddenTitle( $admin, 'recent kinds' );
		$this->assertStringContainsString( self::SAFE, $admin );
		$this->assertSame( $front, $admin );
	}

	public function test_checkin_map_entries_name_a_hidden_location_title_by_its_safe_name(): void {
		$id = $this->hidden_title_post( 'checkin' );

		[ $front, $admin ] = $this->front_and_admin( fn() => wp_json_encode( Checkin_Map::entries( [ get_post( $id ) ] ) ) );

		$this->assertNoHiddenTitle( $admin, 'checkin map' );
		$entry = Checkin_Map::entries( [ get_post( $id ) ] )[0];
		$this->assertSame( self::SAFE, $entry['title'] );
		$this->assertTrue( $entry['synthetic'], 'A hidden title is a synthetic name, printed without p-name (X8).' );
		$this->assertSame( $front, $admin );
	}

	public function test_checkin_map_keeps_a_visible_title_as_a_title(): void {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Garden gate',
			]
		);
		wp_set_object_terms( $id, 'checkin', Taxonomy::TAXONOMY );
		$this->admin_screen();

		$entry = Checkin_Map::entries( [ get_post( $id ) ] )[0];

		$this->assertSame( 'Garden gate', $entry['title'] );
		$this->assertFalse( $entry['synthetic'] );
	}

	public function test_checkins_feed_never_prints_a_hidden_location_title(): void {
		$this->hidden_title_post( 'checkin' );

		[ $front, $admin ] = $this->front_and_admin( fn() => $this->block( 'post-kinds-indieweb/checkins-feed', [ 'count' => 5 ] ) );

		$this->assertNoHiddenTitle( $admin, 'checkins feed' );
		$this->assertStringContainsString( self::SAFE, $admin );
		$this->assertSame( $front, $admin );
		$titles = $this->xpath( $admin )->query( '//*[contains(@class,"checkins-feed__title")]' );
		$this->assertSame( 1, $titles->length );
		$this->assertStringNotContainsString( 'p-name', (string) $titles->item( 0 )->getAttribute( 'class' ), 'A hidden title is a synthetic name (X8).' );
	}

	public function test_checkins_feed_keeps_the_p_name_on_a_visible_title(): void {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Garden gate',
			]
		);
		wp_set_object_terms( $id, 'checkin', Taxonomy::TAXONOMY );

		$titles = $this->xpath( $this->block( 'post-kinds-indieweb/checkins-feed', [ 'count' => 5 ] ) )->query( '//*[contains(@class,"checkins-feed__title")]' );

		$this->assertSame( 1, $titles->length );
		$this->assertStringContainsString( 'p-name', (string) $titles->item( 0 )->getAttribute( 'class' ) );
		$this->assertStringContainsString( 'Garden gate', (string) $titles->item( 0 )->textContent );
	}

	public function test_venue_detail_never_prints_a_hidden_location_title(): void {
		$id    = $this->hidden_title_post( 'checkin' );
		$venue = wp_insert_term( 'Garden Venue', Venue_Taxonomy::TAXONOMY );
		$this->assertIsArray( $venue );
		wp_set_object_terms( $id, (int) $venue['term_id'], Venue_Taxonomy::TAXONOMY );

		[ $front, $admin ] = $this->front_and_admin( fn() => $this->block( 'post-kinds-indieweb/venue-detail', [ 'venueId' => (int) $venue['term_id'] ] ) );

		$this->assertNoHiddenTitle( $admin, 'venue detail' );
		$this->assertStringContainsString( self::SAFE, $admin );
		$this->assertSame( $front, $admin );
		$titles = $this->xpath( $admin )->query( '//*[contains(@class,"venue-detail__checkin-title")]' );
		$this->assertSame( 1, $titles->length );
		$this->assertStringNotContainsString( 'p-name', (string) $titles->item( 0 )->getAttribute( 'class' ), 'A hidden title is a synthetic name (X8).' );
	}

	public function test_chicken_facts_and_entry_never_carry_a_hidden_location_title(): void {
		$id = $this->hidden_title_post( 'chicken' );

		[ $front, $admin ] = $this->front_and_admin(
			fn() => wp_json_encode( Chicken_Archive::facts( $id ) ) . $this->call( Chicken_Archive::class, 'entry_html', get_post( $id ), [], false )
		);

		$this->assertNoHiddenTitle( $admin, 'chicken' );
		$this->assertSame( self::SAFE, Chicken_Archive::facts( $id )['name'] );
		$this->assertSame( $front, $admin );
		$this->assertSame( 0, $this->p_names( $this->call( Chicken_Archive::class, 'entry_html', get_post( $id ), [], false ) )->length, 'A hidden title is a synthetic name (X8).' );
	}

	public function test_review_entry_and_four_stars_never_print_a_hidden_location_title(): void {
		$id = $this->hidden_title_post(
			'review',
			[
				Meta_Fields::PREFIX . 'review_rating' => '4',
				Meta_Fields::PREFIX . 'review_best'   => '5',
			]
		);

		[ $front, $admin ] = $this->front_and_admin(
			fn() => (string) preg_replace( '/pkiw-four-stars-\d+/', 'pkiw-four-stars-n', $this->block( Review_Archive::FOUR_STARS_BLOCK ) ) . $this->call( Review_Archive::class, 'entry_html', get_post( $id ), [], false )
		);

		$this->assertNoHiddenTitle( $admin, 'review' );
		$this->assertStringContainsString( self::SAFE, $this->block( Review_Archive::FOUR_STARS_BLOCK ) );
		$this->assertSame( $front, $admin );
		$entry  = $this->call( Review_Archive::class, 'entry_html', get_post( $id ), [], false );
		$titles = $this->xpath( $entry )->query( '//*[contains(@class,"pkiw-review-entry__title")]' );
		$this->assertSame( 1, $titles->length );
		$this->assertStringContainsString( self::SAFE, (string) $titles->item( 0 )->textContent );
		$this->assertStringNotContainsString( 'p-name', (string) $titles->item( 0 )->getAttribute( 'class' ), 'A hidden title is a synthetic name (X8).' );
	}

	public function test_staff_picks_never_print_a_hidden_location_title(): void {
		$id = $this->hidden_title_post( 'play' );

		[ $front, $admin ] = $this->front_and_admin( fn() => $this->call( Staff_Picks::class, 'item', get_post( $id ), 4.0, 2, true ) );

		$this->assertNoHiddenTitle( $admin, 'staff picks' );
		$this->assertStringContainsString( self::SAFE, $admin );
		$this->assertSame( $front, $admin );
	}

	public function test_acquisition_facts_name_a_hidden_location_title_by_its_safe_name(): void {
		$id = $this->hidden_title_post( 'acquisition' );

		[ $front, $admin ] = $this->front_and_admin( fn() => wp_json_encode( Acquisition_Archive::facts( $id ) ) );

		$this->assertNoHiddenTitle( $admin, 'acquisition facts' );
		$facts = Acquisition_Archive::facts( $id );
		$this->assertSame( self::SAFE, $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'A hidden title is a synthetic name (X8).' );
		$this->assertSame( $front, $admin );
	}

	public function test_repost_facts_name_a_hidden_location_title_by_its_safe_name(): void {
		$id = $this->hidden_title_post( 'repost' );

		[ $front, $admin ] = $this->front_and_admin( fn() => wp_json_encode( Repost_Archive::facts( $id ) ) );

		$this->assertNoHiddenTitle( $admin, 'repost facts' );
		$facts = Repost_Archive::facts( $id );
		$this->assertSame( self::SAFE, $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'A hidden title is a synthetic name (X8).' );
		$this->assertSame( $front, $admin );
	}

	public function test_visible_titles_stay_titles_in_acquisition_and_repost_facts(): void {
		$acquisition = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Wool Scarf',
			]
		);
		wp_set_object_terms( $acquisition, 'acquisition', Taxonomy::TAXONOMY );
		$repost = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Garden gate',
			]
		);
		wp_set_object_terms( $repost, 'repost', Taxonomy::TAXONOMY );
		$this->admin_screen();

		$this->assertSame( 'Wool Scarf', Acquisition_Archive::facts( $acquisition )['name'] );
		$this->assertTrue( Acquisition_Archive::facts( $acquisition )['name_is_title'] );
		$this->assertSame( 'Garden gate', Repost_Archive::facts( $repost )['name'] );
		$this->assertTrue( Repost_Archive::facts( $repost )['name_is_title'] );
	}

	public function test_menu_lines_and_recent_specials_never_print_a_hidden_location_title(): void {
		$id = $this->hidden_title_post( 'eat', [ Meta_Fields::PREFIX . 'eat_rating' => '4' ] );

		[ $front, $admin ] = $this->front_and_admin(
			fn() => $this->call( Kind_Archive_Layouts::class, 'menu_entry_html', get_post( $id ), 'eat', [], false )
				. $this->call( Kind_Archive_Layouts::class, 'menu_special', get_post( $id ), 'eat', 3, false )
		);

		$this->assertNoHiddenTitle( $admin, 'menu' );
		$this->assertStringContainsString( '>' . self::SAFE . '</a>', $admin );
		$this->assertSame( $front, $admin );
		$line = $this->call( Kind_Archive_Layouts::class, 'menu_entry_html', get_post( $id ), 'eat', [], false );
		$this->assertSame( 0, $this->p_names( $line )->length, 'A hidden title is a synthetic name (X8).' );
	}

	public function test_a_menu_line_named_by_its_dish_keeps_its_p_name(): void {
		$id = $this->hidden_title_post( 'eat', [ Meta_Fields::PREFIX . 'eat_name' => 'Ramen' ] );
		$this->admin_screen();

		$line  = $this->call( Kind_Archive_Layouts::class, 'menu_entry_html', get_post( $id ), 'eat', [], false );
		$names = $this->p_names( $line );

		$this->assertNoHiddenTitle( $line, 'menu line' );
		$this->assertSame( 1, $names->length );
		$this->assertSame( 'Ramen', trim( (string) $names->item( 0 )->textContent ) );
	}

	/**
	 * Card blocks whose image alt falls back to the post title.
	 *
	 * @return array<string, array{string, string, array<string, mixed>, string}>
	 */
	public function card_alt_cases(): array {
		return [
			'watch poster' => [ 'watch', 'post-kinds-indieweb/watch-card', [ 'posterImage' => self::COVER ], 'Poster for ' . self::SAFE ],
			'read cover'   => [ 'read', 'post-kinds-indieweb/read-card', [ 'coverImage' => self::COVER ], 'Cover of ' . self::SAFE ],
			'comic cover'  => [ 'comic', 'post-kinds-indieweb/comic-card', [ 'coverImage' => self::COVER ], 'Cover of ' . self::SAFE ],
			'game cover'   => [ 'play', 'post-kinds-indieweb/play-card', [ 'cover' => self::COVER ], 'Box art for ' . self::SAFE ],
			'board box'    => [
				'play',
				'post-kinds-indieweb/play-card',
				[
					'cover' => self::COVER,
					'bggId' => '13',
				],
				'Box art for ' . self::SAFE,
			],
		];
	}

	/**
	 * @dataProvider card_alt_cases
	 *
	 * @param string               $kind     Kind slug.
	 * @param string               $name     Block name.
	 * @param array<string, mixed> $attrs    Block attributes, with no title.
	 * @param string               $expected Alt text the card prints.
	 */
	public function test_a_card_image_alt_never_falls_back_to_a_hidden_location_title( string $kind, string $name, array $attrs, string $expected ): void {
		$content = '<!-- wp:' . $name . ' ' . wp_json_encode( $attrs ) . ' /-->';
		$id      = $this->hidden_title_post( $kind, [], $content );

		$GLOBALS['post'] = get_post( $id );
		[ $front, $admin ] = $this->front_and_admin( fn() => $this->block( $name, $attrs ) );

		$this->assertNoHiddenTitle( $admin, $name );
		$this->assertStringContainsString( 'alt="' . esc_attr( $expected ) . '"', $admin );
		$this->assertSame( $front, $admin );
	}

	public function test_a_long_form_watch_stream_card_gives_a_hidden_location_title_no_p_name(): void {
		$id = $this->hidden_title_post( 'watch' );

		$GLOBALS['post'] = get_post( $id );
		[ $front, $admin ] = $this->front_and_admin( fn() => \PKIW\render_stream_card() );

		foreach ( [ 'front' => $front, 'admin screen' => $admin ] as $where => $html ) {
			$this->assertNoHiddenTitle( $html, $where );
			$titles = $this->xpath( $html )->query( '//*[contains(concat(" ",normalize-space(@class)," ")," pk-title ")]' );
			$this->assertSame( 1, $titles->length, $where );
			$this->assertStringContainsString( self::SAFE, (string) $titles->item( 0 )->textContent, $where );
			$this->assertStringNotContainsString( 'p-name', (string) $titles->item( 0 )->getAttribute( 'class' ), $where . ': a synthetic name carries no p-name.' );
		}
		$this->assertSame( $front, $admin );
	}

	public function test_a_long_form_watch_stream_card_keeps_the_p_name_on_a_visible_title(): void {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Movie night notes',
			]
		);
		wp_set_object_terms( $id, 'watch', Taxonomy::TAXONOMY );

		$GLOBALS['post'] = get_post( $id );
		$titles          = $this->xpath( \PKIW\render_stream_card() )->query( '//*[contains(concat(" ",normalize-space(@class)," ")," pk-title ")]' );

		$this->assertSame( 1, $titles->length );
		$this->assertStringContainsString( 'p-name', (string) $titles->item( 0 )->getAttribute( 'class' ) );
	}

	public function test_an_authored_watch_card_keeps_the_p_name_on_its_media_title(): void {
		$id = $this->hidden_title_post( 'watch' );

		$GLOBALS['post'] = get_post( $id );
		$this->admin_screen();
		$names = $this->p_names( $this->block( 'post-kinds-indieweb/watch-card', [ 'mediaTitle' => 'Arrival' ] ) );

		$this->assertSame( 1, $names->length );
		$this->assertSame( 'Arrival', trim( (string) $names->item( 0 )->textContent ) );
	}

	public function test_an_in_memory_title_rewrite_is_not_a_hidden_title(): void {
		$id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Garden gate',
			]
		);
		$preview             = clone get_post( $id );
		$preview->post_title = 'Garden gate, revised';

		$this->assertFalse( \PKIW\post_title_is_hidden( $preview ), 'A title rewritten in memory is still the post\'s own title.' );
		$this->assertSame( 'Garden gate, revised', \PKIW\visitor_post_title( $preview ) );
		$this->assertFalse( Title_Privacy::hides_title( $preview ) );
	}

	public function test_a_hidden_title_stays_hidden_when_rewritten_in_memory(): void {
		$id                  = $this->hidden_title_post( 'checkin' );
		$preview             = clone get_post( $id );
		$preview->post_title = self::TITLE . ' ';

		$this->assertTrue( \PKIW\post_title_is_hidden( get_post( $id ) ) );
		$this->assertTrue( \PKIW\post_title_is_hidden( $preview ) );
		$this->assertTrue( Title_Privacy::hides_title( $preview ) );
		$this->assertSame( self::SAFE, \PKIW\visitor_post_title( $preview ) );
	}

	/**
	 * Hooks a post save runs, and the priority ActivityPub 9.3.1 builds its
	 * activity at on wp_after_insert_post (includes/scheduler/class-post.php:31).
	 *
	 * @return array<string, array{string, int}>
	 */
	public function save_hook_cases(): array {
		return [
			'transition_post_status' => [ 'transition_post_status', 10 ],
			'save_post'              => [ 'save_post', 10 ],
			'wp_after_insert_post'   => [ 'wp_after_insert_post', 33 ],
		];
	}

	/**
	 * A record built while a classic-editor save runs reads the title a
	 * visitor reads, as ActivityPub's transformer does with get_the_title().
	 *
	 * @dataProvider save_hook_cases
	 *
	 * @param string $hook     Save hook.
	 * @param int    $priority Priority the record is built at.
	 */
	public function test_a_record_built_during_an_admin_save_reads_the_safe_title( string $hook, int $priority ): void {
		$id   = $this->hidden_title_post( 'checkin' );
		$seen = [];
		$read = static function () use ( $id, &$seen ): void {
			$seen[] = get_the_title( $id );
		};
		add_action( $hook, $read, $priority, 0 );
		set_current_screen( 'post' );
		$this->assertTrue( is_admin() );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => 'Tea and a notebook.',
			]
		);
		remove_action( $hook, $read, $priority );

		$this->assertNotEmpty( $seen );
		foreach ( $seen as $title ) {
			$this->assertSame( self::SAFE, $title );
		}
		$this->assertSame( self::TITLE, get_the_title( $id ), 'The admin screen still lists the stored title once the save is done.' );
		$this->assertSame( 'location', get_post_meta( $id, Title_Privacy::META_KEY, true ), 'A content edit keeps the marker.' );
	}

	/**
	 * The ATmosphere records for a hidden title: built by ATmosphere's cron,
	 * or while an admin save runs. Needs PKIW_TESTS_ATMOSPHERE_FILE.
	 *
	 * @group atmosphere
	 */
	public function test_atmosphere_records_never_carry_a_hidden_location_title(): void {
		if ( ! defined( 'ATMOSPHERE_VERSION' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to build ATmosphere records.' );
		}

		$id      = $this->hidden_title_post( 'checkin', [], '<!-- wp:paragraph --><p>Tea and a notebook.</p><!-- /wp:paragraph -->' );
		$records = static fn(): string => (string) wp_json_encode(
			[
				( new \Atmosphere\Transformer\Document( get_post( $id ) ) )->transform(),
				( new \Atmosphere\Transformer\Post( get_post( $id ) ) )->transform(),
			]
		);
		$this->admin_screen();

		add_filter( 'wp_doing_cron', '__return_true' );
		$cron = $records();
		remove_filter( 'wp_doing_cron', '__return_true' );

		$during_save = '';
		$build       = static function () use ( &$during_save, $records ): void {
			$during_save = $records();
		};
		add_action( 'wp_after_insert_post', $build, 33, 0 );
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '<!-- wp:paragraph --><p>Tea and two notebooks.</p><!-- /wp:paragraph -->',
			]
		);
		remove_action( 'wp_after_insert_post', $build, 33 );

		$this->assertNoHiddenTitle( $cron, 'ATmosphere cron records' );
		$this->assertStringContainsString( self::SAFE, $cron );
		$this->assertNotSame( '', $during_save );
		$this->assertNoHiddenTitle( $during_save, 'ATmosphere records built during an admin save' );
	}
}

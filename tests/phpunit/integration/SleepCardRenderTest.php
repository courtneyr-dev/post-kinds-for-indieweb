<?php
/**
 * The sleep card's render (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * The card prints one record on every surface: duration, moon phase and the
 * night, plus bedtime and wake time only when the card's own toggle AND the
 * post's row both say so. The Stream shape never prints a time. The block
 * registers in set_up() the way W4-PBUNDLE's block.json will, so this class
 * runs before and after that lane lands.
 *
 * @group integration
 */
final class SleepCardRenderTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/sleep-card';

	private string $original_timezone = '';

	private bool $registered_here = false;

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'sleep', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );

		$this->registered_here = ! WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK );
		if ( $this->registered_here ) {
			register_block_type(
				self::BLOCK,
				[
					'api_version'     => 3,
					'attributes'      => [
						'durationMinutes'   => [ 'type' => 'integer' ],
						'bedtime'           => [ 'type' => 'string' ],
						'wakeTime'          => [ 'type' => 'string' ],
						'showTimesPublicly' => [
							'type'    => 'boolean',
							'default' => false,
						],
						'origin'            => [ 'type' => 'string' ],
					],
					'uses_context'    => [ 'postId' ],
					'render_callback' => static function ( array $attributes, string $content, WP_Block $block ): string {
						ob_start();
						include PKIW_PATH . 'src/blocks/sleep-card/render.php';
						return (string) ob_get_clean();
					},
				]
			);
		}
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		if ( $this->registered_here ) {
			unregister_block_type( self::BLOCK );
		}
		remove_all_filters( 'pkiw_sleep_moon_html' );
		update_option( 'timezone_string', $this->original_timezone );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return [] === $attrs
			? '<!-- wp:post-kinds-indieweb/sleep-card /-->'
			: '<!-- wp:post-kinds-indieweb/sleep-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * @param string               $night Night, Y-m-d.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $post  Post overrides.
	 */
	private function sleep( string $night, array $attrs, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_date'    => $night . ' 12:00:00',
					'post_content' => $this->card( $attrs ),
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'sleep', 'kind' );

		return (int) $id;
	}

	/**
	 * A single's content, as the loop would print it.
	 */
	private function single( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$this->assertTrue( is_singular() );
		$this->assertSame( $id, get_queried_object_id() );

		return (string) apply_filters( 'the_content', get_post( $id )->post_content );
	}

	/**
	 * The record's row labels in order.
	 *
	 * @return string[]
	 */
	private function rows( string $html ): array {
		preg_match_all( '#<dt>(.*?)</dt>#s', $html, $matches );

		return $matches[1];
	}

	private function h01( bool $public = false ): int {
		return $this->sleep(
			'2026-08-09',
			[
				'durationMinutes'   => 420,
				'bedtime'           => '2026-08-09T23:10',
				'wakeTime'          => '2026-08-10T06:10',
				'showTimesPublicly' => $public,
			]
		);
	}

	public function test_a_hidden_times_card_prints_three_rows_in_order(): void {
		$html = $this->single( $this->h01() );

		$this->assertSame( [ 'Duration', 'Moon phase', 'Date' ], $this->rows( $html ) );
		$this->assertStringContainsString( '<time datetime="PT7H">7 hr</time>', $html );
		$this->assertStringContainsString( 'Waning crescent', $html );
		$this->assertStringContainsString( '<time datetime="2026-08-09">August 9, 2026</time>', $html );
		$this->assertStringNotContainsString( '23:10', $html );
		$this->assertStringNotContainsString( '06:10', $html );
		$this->assertStringNotContainsString( '11:10 pm', $html );
	}

	public function test_an_opted_in_card_prints_five_rows_with_plain_time_elements(): void {
		$id   = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			],
			[ 'post_title' => 'Early night' ]
		);
		$html = $this->single( $id );

		$this->assertSame( [ 'Duration', 'Moon phase', 'Date', 'Bedtime', 'Wake time' ], $this->rows( $html ) );
		$this->assertStringContainsString( '<dd><time datetime="2026-08-11T21:45">9:45 pm</time></dd>', $html );
		$this->assertStringContainsString( '<dd><time datetime="2026-08-12T06:15">6:15 am</time></dd>', $html );
	}

	public function test_class_names_ids_and_data_attributes_never_name_a_time(): void {
		$id   = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			]
		);
		$html = $this->single( $id );

		$this->assertSame( 0, preg_match( '/\b(?:class|id|data-[a-z-]+|aria-[a-z]+|style)="[^"]*(?:bedtime|wake)/i', $html ), 'No attribute value names a time.' );
	}

	public function test_p_sleep_holds_the_duration_only_and_no_time_carries_an_mf2_class(): void {
		$id      = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 462,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			]
		);
		$content = $this->single( $id );
		$parsed  = \Mf2\parse( $content, 'https://example.org/' );
		$entry   = $parsed['items'][0] ?? [];

		$this->assertContains( 'h-entry', $entry['type'] ?? [] );
		$this->assertSame( [ '7 hr 42 min' ], $entry['properties']['sleep'] ?? [] );
		$this->assertSame( 1, preg_match_all( '/\bp-sleep\b/', $content ) );
		$this->assertSame( 0, preg_match( '/<time[^>]*class="[^"]*(?:dt-|p-)[^"]*"[^>]*datetime="[^"]*T21:45/', $content ) );
		$this->assertStringNotContainsString( 'dt-start', $content );
		$this->assertStringNotContainsString( 'dt-end', $content );
	}

	public function test_a_card_with_no_duration_has_two_rows_and_no_chart(): void {
		$html = $this->single( $this->sleep( '2026-08-03', [] ) );

		$this->assertSame( [ 'Moon phase', 'Date' ], $this->rows( $html ) );
		$this->assertStringNotContainsString( 'pkiw-sleep__chart', $html );
		$this->assertStringNotContainsString( 'p-sleep', $html );
		$this->assertDoesNotMatchRegularExpression( '/\b0 min\b|PT0/', $html );
	}

	public function test_the_chart_is_one_aria_hidden_bar_on_the_twelve_hour_scale(): void {
		$html = $this->single( $this->sleep( '2026-08-19', [ 'durationMinutes' => 462 ] ) );

		$this->assertSame( 1, substr_count( $html, 'class="pkiw-sleep__chart"' ) );
		$this->assertStringContainsString( '<div class="pkiw-sleep__chart" aria-hidden="true"><span class="pkiw-sleep__bar" style="--pkiw-sleep-ratio:0.6417"></span></div>', $html );
		$this->assertGreaterThan( strpos( $html, '</dl>' ), strpos( $html, 'pkiw-sleep__chart' ), 'The chart follows the record.' );

		$long = $this->single( $this->sleep( '2026-07-10', [ 'durationMinutes' => 750 ] ) );
		$this->assertStringContainsString( '--pkiw-sleep-ratio:1"', $long );
	}

	public function test_one_moon_slot_and_it_is_empty_until_a_theme_fills_it(): void {
		$id   = $this->sleep( '2026-08-19', [ 'durationMinutes' => 462 ] );
		$html = $this->single( $id );

		$this->assertSame( 1, substr_count( $html, 'class="pkiw-sleep__moon"' ) );
		$this->assertStringContainsString( '<span class="pkiw-sleep__moon" aria-hidden="true"></span>', $html );

		$seen = [];
		add_filter(
			'pkiw_sleep_moon_html',
			static function ( $drawing, $phase, $post_id ) use ( &$seen ) {
				$seen[] = [ $phase, $post_id ];
				return '<svg class="theme-moon" data-phase="' . $phase . '"></svg>';
			},
			10,
			3
		);
		$drawn = $this->single( $id );

		$this->assertStringContainsString( '<span class="pkiw-sleep__moon" aria-hidden="true"><svg class="theme-moon" data-phase="first-quarter"></svg></span>', $drawn );
		$this->assertSame( [ [ 'first-quarter', $id ] ], $seen );
		$this->assertSame( 1, substr_count( $drawn, 'class="pkiw-sleep__moon"' ) );
	}

	public function test_an_administrator_sees_what_a_visitor_sees(): void {
		$id      = $this->h01();
		$visitor = $this->single( $id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $visitor, $this->single( $id ) );
	}

	public function test_a_visible_post_row_with_the_card_toggle_off_prints_no_time(): void {
		$id = $this->h01();
		update_post_meta( $id, Meta_Fields::SLEEP_PUBLIC_KEY, '1' );
		$this->assertTrue( Meta_Fields::health_details_visible( $id ) );

		$html = $this->single( $id );

		$this->assertSame( [ 'Duration', 'Moon phase', 'Date' ], $this->rows( $html ) );
		$this->assertStringNotContainsString( '23:10', $html );
		$this->assertStringNotContainsString( '06:10', $html );
	}

	public function test_a_card_toggle_on_with_no_post_row_prints_no_time(): void {
		$id = $this->h01( true );
		$this->assertSame( 5, count( $this->rows( $this->single( $id ) ) ) );
		delete_post_meta( $id, Meta_Fields::SLEEP_PUBLIC_KEY );

		$html = $this->single( $id );

		$this->assertSame( [ 'Duration', 'Moon phase', 'Date' ], $this->rows( $html ) );
		$this->assertStringNotContainsString( '23:10', $html );
	}

	public function test_a_second_card_with_its_toggle_on_cannot_print_times(): void {
		$first  = $this->card( [ 'durationMinutes' => 420, 'bedtime' => '2026-08-09T23:10', 'wakeTime' => '2026-08-10T06:10' ] );
		$second = $this->card( [ 'durationMinutes' => 420, 'bedtime' => '2026-08-09T23:10', 'wakeTime' => '2026-08-10T06:10', 'showTimesPublicly' => true ] );
		$id     = $this->sleep( '2026-08-09', [], [ 'post_content' => $first . "\n\n" . $second ] );
		update_post_meta( $id, Meta_Fields::SLEEP_PUBLIC_KEY, '1' );

		$this->go_to( get_permalink( $id ) );
		$html = (string) apply_filters( 'the_content', get_post( $id )->post_content );

		$this->assertStringNotContainsString( '23:10', $html );
		$this->assertStringNotContainsString( '06:10', $html );
	}

	public function test_a_card_whose_own_toggle_is_off_prints_no_time_even_when_the_first_card_is_public(): void {
		$public = [ 'durationMinutes' => 420, 'bedtime' => '2026-08-09T23:10', 'wakeTime' => '2026-08-10T06:10', 'showTimesPublicly' => true ];
		$closed = [ 'durationMinutes' => 420, 'bedtime' => '2026-08-09T23:10', 'wakeTime' => '2026-08-10T06:10' ];
		$id     = $this->sleep( '2026-08-09', [], [ 'post_content' => $this->card( $public ) . "\n\n" . $this->card( $closed ) ] );
		$this->assertStringContainsString( '23:10', wp_json_encode( \PKIW\Sleep_Archive::facts( $id ) ), 'The first card opened the times.' );

		$this->go_to( get_permalink( $id ) );
		$second = render_block( [ 'blockName' => self::BLOCK, 'attrs' => $closed, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );
		$first  = render_block( [ 'blockName' => self::BLOCK, 'attrs' => $public, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );

		$this->assertStringNotContainsString( '23:10', $second );
		$this->assertStringNotContainsString( '06:10', $second );
		$this->assertStringContainsString( '2026-08-09T23:10', $first );
	}

	public function test_the_build_copy_is_byte_for_byte_the_source(): void {
		$this->assertFileEquals( PKIW_PATH . 'src/blocks/sleep-card/render.php', PKIW_PATH . 'build/blocks/sleep-card/render.php' );
	}

	public function test_a_protected_post_renders_no_record(): void {
		$id = $this->sleep( '2026-08-08', [ 'durationMinutes' => 430 ], [ 'post_password' => 'example-pass' ] );

		$this->go_to( get_permalink( $id ) );
		$html = render_block( [ 'blockName' => self::BLOCK, 'attrs' => [ 'durationMinutes' => 430 ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );

		$this->assertSame( '', trim( $html ) );
	}

	// Stream shape.

	/**
	 * The Stream card for a post.
	 */
	private function stream( int $id ): string {
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		$html = \PKIW\render_stream_card();
		wp_reset_postdata();

		return $html;
	}

	/**
	 * A Stream card carries no clock time: not the labels the card would print
	 * in the site's time format, not the ISO values, and no time pressed
	 * against other text.
	 */
	private function assert_stream_holds_no_time( int $id, string $html ): void {
		$facts = \PKIW\Sleep_Archive::facts( $id );
		$this->assertNotSame( '', $facts['bedtime_label'], 'Precondition: the post is opted in, so the facts carry the labels.' );
		$this->assertNotSame( '', $facts['wake_label'] );

		foreach ( [ $facts['bedtime_label'], $facts['wake_label'], $facts['bedtime_iso'], $facts['wake_iso'] ] as $needle ) {
			$this->assertStringNotContainsString( (string) $needle, $html, (string) $needle );
		}
		$this->assertDoesNotMatchRegularExpression( '/\d{1,2}:\d{2}/', wp_strip_all_tags( $html ), 'No visible clock time, spaced or not.' );
		$this->assertDoesNotMatchRegularExpression( '/T\d{2}:\d{2}/', $html, 'No ISO time in any attribute.' );
	}

	public function test_the_stream_shape_is_one_link_a_date_only_published_and_the_duration(): void {
		$id   = $this->sleep(
			'2026-08-19',
			[
				'durationMinutes'   => 462,
				'bedtime'           => '2026-08-19T23:10',
				'wakeTime'          => '2026-08-20T06:52',
				'showTimesPublicly' => true,
			]
		);
		$html = $this->stream( $id );

		$this->assertSame( 1, substr_count( $html, '<h2 class="pk-title' ), 'One title.' );
		$this->assertSame( 1, preg_match_all( '/<a\b[^>]*\bhref=/', $html ), 'Exactly one link.' );
		$this->assertSame( 1, preg_match_all( '/<time\b[^>]*class="dt-published"/', $html ), 'One published time.' );
		$this->assertMatchesRegularExpression( '/<time class="dt-published" datetime="2026-08-19">/', $html );
		$this->assertDoesNotMatchRegularExpression( '/(?:datetime|value)="[^"]*T\d\d:/', $html, 'No hidden full timestamp.' );
		$this->assert_stream_holds_no_time( $id, $html );
		$this->assertStringContainsString( '<p class="pkiw-sleep__duration p-sleep"><time datetime="PT7H42M">7 hr 42 min</time></p>', $html );
		$this->assertStringContainsString( '<p class="pkiw-sleep__phase">First quarter</p>', $html );
		$this->assertStringNotContainsString( '23:10', $html );
		$this->assertStringNotContainsString( '06:52', $html );
		$this->assertStringContainsString( 'k-sleep', $html );
		$this->assertStringContainsString( 'Sleep, August 19, 2026', $html );
		$this->assertStringNotContainsString( 'p-name', $html, 'A synthetic name never carries p-name.' );
	}

	public function test_a_titled_stream_card_names_the_post_with_p_name_and_prints_no_time(): void {
		$id   = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 375,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T04:00',
				'showTimesPublicly' => true,
			],
			[ 'post_title' => 'Fictional title: storm night' ]
		);
		$html = $this->stream( $id );

		$this->assertMatchesRegularExpression( '#<h2 class="pk-title p-name"><a class="u-url" href="[^"]+">Fictional title: storm night</a></h2>#', $html );
		$this->assert_stream_holds_no_time( $id, $html );
	}

	public function test_a_stream_card_with_no_duration_has_no_p_sleep(): void {
		$html = $this->stream( $this->sleep( '2026-08-19', [] ) );

		$this->assertStringNotContainsString( 'p-sleep', $html );
		$this->assertStringNotContainsString( 'pkiw-sleep__duration', $html );
		$this->assertStringContainsString( 'pkiw-sleep__phase', $html );
	}

	public function test_the_stream_shape_holds_the_moon_slot_once(): void {
		add_filter( 'pkiw_sleep_moon_html', static fn() => '<svg class="theme-moon"></svg>' );
		$html = $this->stream( $this->sleep( '2026-08-19', [ 'durationMinutes' => 462 ] ) );

		$this->assertSame( 1, substr_count( $html, 'theme-moon' ) );
		$this->assertSame( 1, substr_count( $html, 'pkiw-sleep__moon' ) );
	}

	public function test_a_hidden_location_title_never_heads_a_stream_card_even_on_an_admin_screen(): void {
		$id = $this->sleep( '2026-08-23', [ 'durationMinutes' => 420 ], [ 'post_title' => 'Maplewood Home' ] );
		update_post_meta( $id, '_pkiw_title_source', 'location' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'private' );
		$visitor = $this->stream( $id );
		$this->assertStringContainsString( 'Check-in, August 23, 2026', $visitor, 'Precondition: the front end prints the safe title.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );
		$admin = $this->stream( $id );

		$this->assertStringNotContainsString( 'Maplewood', $admin );
		$this->assertStringContainsString( 'Check-in, August 23, 2026', $admin );
	}

	public function test_simple_locations_timezone_cannot_move_the_published_date_of_the_single_or_the_stream(): void {
		$tokyo = static function ( $value, $format = '', $post = null ) {
			$post = get_post( $post );
			$zone = $post instanceof WP_Post ? (string) get_post_meta( $post->ID, 'geo_timezone', true ) : '';
			if ( '' === $zone ) {
				return $value;
			}
			$format = '' !== (string) $format ? (string) $format : (string) get_option( 'date_format' );

			return wp_date( $format, get_post_datetime( $post )->getTimestamp(), new DateTimeZone( $zone ) );
		};
		add_filter( 'get_the_date', $tokyo, 9, 3 );
		$id = $this->sleep( '2026-08-23', [ 'durationMinutes' => 420 ] );
		update_post_meta( $id, 'geo_timezone', 'Asia/Tokyo' );
		$note = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2026-08-23 12:00:00' ] );
		update_post_meta( $note, 'geo_timezone', 'Asia/Tokyo' );
		$this->assertSame( 'August 24, 2026', get_the_date( '', $note ), 'Precondition: the stand-in is live.' );

		$single = $this->single( $id );
		$stream = $this->stream( $id );
		remove_filter( 'get_the_date', $tokyo, 9 );

		$this->assertStringContainsString( 'datetime="2026-08-23T12:00:00-04:00"', $single );
		$this->assertStringNotContainsString( '+09:00', $single );
		$this->assertStringContainsString( 'August 23, 2026', $stream );
		$this->assertStringNotContainsString( 'August 24', $stream );
		$this->assertMatchesRegularExpression( '/<time class="dt-published" datetime="2026-08-23">/', $stream );
	}

	// Card-less singles.

	public function test_a_card_less_single_gets_the_record_ahead_of_its_text_and_not_in_its_feed(): void {
		$id = $this->sleep( '2026-08-04', [], [ 'post_excerpt' => '', 'post_content' => '<!-- wp:paragraph --><p>Fictional note: quiet night.</p><!-- /wp:paragraph -->' ] );

		$html = $this->single( $id );

		$this->assertSame( [ 'Moon phase', 'Date' ], $this->rows( $html ) );
		$this->assertStringContainsString( 'Waning gibbous', $html );
		$this->assertLessThan( strpos( $html, 'Fictional note: quiet night.' ), strpos( $html, 'pkiw-sleep__record' ), 'The record comes first.' );
		$this->assertStringNotContainsString( 'Duration', $html );

		// A feed, REST and an excerpt keep the stored content.
		$this->go_to( get_permalink( $id ) );
		$stored = get_post( $id )->post_content;
		$GLOBALS['wp_query']->is_feed = true;
		$feed = (string) apply_filters( 'the_content', $stored );
		$GLOBALS['wp_query']->is_feed = false;
		$this->assertStringNotContainsString( 'pkiw-sleep__record', $feed );
		$this->assertStringContainsString( 'Fictional note: quiet night.', $feed );

		$this->assertSame( '', get_post( $id )->post_excerpt, 'The excerpt is built from the content.' );
		$excerpt = get_the_excerpt( $id );
		$this->assertStringContainsString( 'quiet night', $excerpt );
		$this->assertStringNotContainsString( 'Moon phase', $excerpt );
		$this->assertStringNotContainsString( 'Waning gibbous', $excerpt );
	}

	public function test_a_post_that_already_holds_a_card_gets_no_second_record(): void {
		$html = $this->single( $this->sleep( '2026-08-19', [ 'durationMinutes' => 462 ] ) );

		$this->assertSame( 1, substr_count( $html, 'pkiw-sleep__record' ) );
	}

	public function test_a_note_post_gets_no_record(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => 'Plain text' ] );
		wp_set_object_terms( $id, 'note', 'kind' );

		$this->assertStringNotContainsString( 'pkiw-sleep', $this->single( (int) $id ) );
	}
}

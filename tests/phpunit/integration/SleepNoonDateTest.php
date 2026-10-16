<?php
/**
 * Sleep posts publish at a neutral noon (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Sleep_Night;

/**
 * Courtney's answer A on the health data question: a sleep post's publish
 * time must not say when she went to bed or woke up, so every sleep post
 * publishes at noon of its night. The window runs noon to noon: a bedtime
 * of 23:30 on Aug 19 and one of 00:40 on Aug 20 both file under the night of
 * Aug 19, and a post with no bedtime files under the night its own time
 * falls in. The noon is never later than the time it reads, so a post
 * can't publish ahead of itself.
 *
 * P0E wires Sleep_Night::register() in the plugin; set_up() calls it so this
 * lane's tests run without that wiring, and the integration run repeats the
 * class against the real one.
 *
 * @group integration
 */
final class SleepNoonDateTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Sleep_Night::reset();
		Sleep_Night::register();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'gmt_offset', '' );
		foreach ( [ 'sleep', 'note', 'read' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Sleep_Night::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A sleep card block comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs = [] ): string {
		return '<!-- wp:post-kinds-indieweb/sleep-card ' . serialize_block_attributes( array_merge( [ 'durationMinutes' => 462 ], $attrs ) ) . ' /-->';
	}

	/**
	 * Insert a post and read its dates back from the database.
	 *
	 * @param array<string, mixed> $args wp_insert_post() arguments.
	 * @return array{id: int, post: WP_Post}
	 */
	private function insert( array $args ): array {
		$id = wp_insert_post(
			array_merge(
				[
					'post_title'   => 'Night',
					'post_status'  => 'publish',
					'post_content' => $this->card(),
				],
				$args
			),
			true
		);
		$this->assertIsInt( $id );
		clean_post_cache( $id );

		return [
			'id'   => $id,
			'post' => get_post( $id ),
		];
	}

	/**
	 * Run the wp_insert_post_data filter on a post's data, with no insert.
	 *
	 * @param string               $content Post content.
	 * @param string               $date    Incoming local post_date.
	 * @param string               $status  Incoming status.
	 * @param array<string, mixed> $postarr Extra post array entries.
	 * @return array<string, mixed> The filtered data.
	 */
	private function filtered( string $content, string $date, string $status = 'publish', array $postarr = [] ): array {
		$gmt  = get_gmt_from_date( $date );
		$data = [
			'post_type'         => 'post',
			'post_status'       => $status,
			'post_content'      => wp_slash( $content ),
			'post_title'        => 'Night',
			'post_excerpt'      => '',
			'post_password'     => '',
			'post_date'         => $date,
			'post_date_gmt'     => $gmt,
			'post_modified'     => $date,
			'post_modified_gmt' => $gmt,
		];

		return Sleep_Night::neutral_post_date( $data, array_merge( [ 'ID' => 0 ], $postarr ) );
	}

	/**
	 * Set the site to a fixed UTC offset whose local hour is $hour right now,
	 * so a test of today's noon doesn't depend on when it runs.
	 *
	 * @param int $hour Local hour, 0-23.
	 */
	private function local_hour_is( int $hour ): void {
		$offset = ( $hour - (int) gmdate( 'G' ) + 24 ) % 24;
		if ( $offset > 14 ) {
			$offset -= 24;
		}
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', (string) $offset );
	}

	// --- Which night a time belongs to. ---

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function night_cases(): array {
		return [
			'bedtime before midnight'       => [ '2026-08-19 23:30:00', '2026-08-19' ],
			'bedtime after midnight'        => [ '2026-08-20 00:40:00', '2026-08-19' ],
			'a minute before noon'          => [ '2026-08-20 11:59:00', '2026-08-19' ],
			'noon itself starts the night'  => [ '2026-08-20 12:00:00', '2026-08-20' ],
			'afternoon'                     => [ '2026-08-20 15:00:00', '2026-08-20' ],
			'a morning wake time'           => [ '2026-08-21 07:31:00', '2026-08-20' ],
			'fall back, second 1:30'        => [ '2026-11-01 01:30:00', '2026-10-31' ],
			'spring forward, skipped 2:30'  => [ '2027-03-14 02:30:00', '2027-03-13' ],
		];
	}

	/**
	 * @dataProvider night_cases
	 *
	 * @param string $local Local wall time.
	 * @param string $night The night it files under.
	 */
	public function test_night_of_runs_noon_to_noon( string $local, string $night ): void {
		$this->assertSame( $night, Sleep_Night::night_of( new DateTimeImmutable( $local, wp_timezone() ) ) );
	}

	public function test_night_of_reads_the_time_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );
		$utc = new DateTimeImmutable( '2026-08-19 20:00:00', new DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-08-19', Sleep_Night::night_of( $utc ), '01:30 on Aug 20 in Kolkata is still the night of Aug 19.' );
	}

	public function test_night_date_prefers_a_valid_bedtime_row_even_when_it_is_hidden(): void {
		$made = $this->insert(
			[
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => $this->card( [ 'bedtime' => '2026-08-19T23:30' ] ),
			]
		);
		$this->assertSame( '2026-08-19T23:30', get_metadata_raw( 'post', $made['id'], '_pkiw_sleep_bedtime', true ) );
		$this->assertSame( '2026-08-19', Sleep_Night::night_date( $made['post'] ) );

		update_post_meta( $made['id'], '_pkiw_sleep_bedtime', '2026-08-25T00:15' );
		$this->assertSame( '2026-08-24', Sleep_Night::night_date( get_post( $made['id'] ) ), 'The row wins over the post date.' );

		update_post_meta( $made['id'], '_pkiw_sleep_bedtime', 'late' );
		$this->assertSame( '2026-08-19', Sleep_Night::night_date( get_post( $made['id'] ) ), 'An invalid row falls back to the post date, noon of the night.' );
	}

	public function test_night_date_of_a_post_at_noon_is_its_own_local_date(): void {
		$made = $this->insert( [ 'post_date' => '2026-08-20 12:00:00' ] );

		$this->assertSame( '2026-08-20', Sleep_Night::night_date( $made['post'] ) );
	}

	// --- The date write, through wp_insert_post(). ---

	public function test_a_bedtime_files_the_post_under_its_night_at_noon(): void {
		$made = $this->insert(
			[
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => $this->card( [ 'bedtime' => '2026-08-19T23:30' ] ),
			]
		);

		$this->assertSame( '2026-08-19 12:00:00', $made['post']->post_date );
		$this->assertSame( '2026-08-19 16:00:00', $made['post']->post_date_gmt );
		$this->assertSame( '2026-08-19 12:00:00', $made['post']->post_modified, 'Modified is noon too.' );
		$this->assertSame( '2026-08-19 16:00:00', $made['post']->post_modified_gmt );
		$this->assertSame( 'publish', $made['post']->post_status );
	}

	public function test_an_after_midnight_bedtime_files_under_the_night_before(): void {
		$made = $this->insert(
			[
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => $this->card( [ 'bedtime' => '2026-08-20T00:40' ] ),
			]
		);

		$this->assertSame( '2026-08-19 12:00:00', $made['post']->post_date );
	}

	public function test_a_post_with_no_bedtime_files_under_the_night_its_own_time_falls_in(): void {
		$morning   = $this->insert( [ 'post_date' => '2026-08-21 07:31:00' ] );
		$afternoon = $this->insert( [ 'post_date' => '2026-08-21 15:00:00' ] );

		$this->assertSame( '2026-08-20 12:00:00', $morning['post']->post_date );
		$this->assertSame( '2026-08-21 12:00:00', $afternoon['post']->post_date );
	}

	public function test_a_bedtime_later_than_the_post_clamps_to_the_post_s_own_night(): void {
		// Noon of Aug 21 would read later than the 09:00 it publishes at.
		$made = $this->insert(
			[
				'post_date'    => '2026-08-21 09:00:00',
				'post_content' => $this->card( [ 'bedtime' => '2026-08-21T23:00' ] ),
			]
		);

		$this->assertSame( '2026-08-20 12:00:00', $made['post']->post_date );
	}

	public function test_a_post_saved_again_keeps_its_noon(): void {
		$made = $this->insert( [ 'post_date' => '2026-08-21 07:31:00' ] );
		Sleep_Night::reset();

		wp_update_post(
			[
				'ID'         => $made['id'],
				'post_title' => 'Renamed',
			]
		);
		clean_post_cache( $made['id'] );

		$this->assertSame( '2026-08-20 12:00:00', get_post( $made['id'] )->post_date );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public function dst_cases(): array {
		return [
			'fall back'       => [ '2026-11-01T01:30', '2026-11-01 07:30:00', '2026-10-31 12:00:00', '2026-10-31 16:00:00' ],
			'spring forward'  => [ '2027-03-14T02:30', '2027-03-14 07:30:00', '2027-03-13 12:00:00', '2027-03-13 17:00:00' ],
		];
	}

	/**
	 * @dataProvider dst_cases
	 *
	 * @param string $bedtime Card bedtime.
	 * @param string $date    Incoming local post_date.
	 * @param string $local   Expected local noon.
	 * @param string $gmt     Expected GMT.
	 */
	public function test_daylight_saving_changes_the_noon_offset_not_the_night( string $bedtime, string $date, string $local, string $gmt ): void {
		$data = $this->filtered( $this->card( [ 'bedtime' => $bedtime ] ), $date );

		$this->assertSame( $local, $data['post_date'] );
		$this->assertSame( $gmt, $data['post_date_gmt'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function zone_cases(): array {
		return [
			'Asia/Kolkata'     => [ 'Asia/Kolkata', '2026-08-19 06:30:00' ],
			'Pacific/Auckland' => [ 'Pacific/Auckland', '2026-08-19 00:00:00' ],
			'America/New_York' => [ 'America/New_York', '2026-08-19 16:00:00' ],
		];
	}

	/**
	 * @dataProvider zone_cases
	 *
	 * @param string $zone Site timezone.
	 * @param string $gmt  Expected GMT of local noon on Aug 19.
	 */
	public function test_the_noon_is_the_sites_noon_in_any_timezone( string $zone, string $gmt ): void {
		update_option( 'timezone_string', $zone );

		$data = $this->filtered( $this->card( [ 'bedtime' => '2026-08-19T23:30' ] ), '2026-08-20 07:31:00' );

		$this->assertSame( '2026-08-19 12:00:00', $data['post_date'] );
		$this->assertSame( $gmt, $data['post_date_gmt'] );
	}

	// --- Which posts count as sleep. ---

	public function test_a_card_less_post_with_the_sleep_term_in_tax_input_gets_noon(): void {
		$made = $this->insert(
			[
				'post_date'    => '2026-08-21 07:31:00',
				'post_content' => '<!-- wp:paragraph --><p>Slept badly.</p><!-- /wp:paragraph -->',
				'tax_input'    => [ 'kind' => [ 'sleep' ] ],
			]
		);

		$this->assertSame( '2026-08-20 12:00:00', $made['post']->post_date );
	}

	public function test_a_card_less_post_created_over_rest_with_the_sleep_term_gets_noon(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$term    = get_term_by( 'slug', 'sleep', 'kind' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body_params(
			[
				'status'  => 'publish',
				'title'   => 'REST night',
				'content' => '<!-- wp:paragraph --><p>Slept badly.</p><!-- /wp:paragraph -->',
				'date'    => '2026-08-21T07:31:00',
				'kind'    => [ $term->term_id ],
			]
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$id = (int) $response->get_data()['id'];
		clean_post_cache( $id );
		$this->assertSame( '2026-08-20 12:00:00', get_post( $id )->post_date );
		$this->assertTrue( has_term( 'sleep', 'kind', $id ) );
	}

	public function test_a_rest_create_for_another_kind_is_left_alone(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$term    = get_term_by( 'slug', 'note', 'kind' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body_params(
			[
				'status'  => 'publish',
				'title'   => 'REST note',
				'content' => '<!-- wp:paragraph --><p>Hello.</p><!-- /wp:paragraph -->',
				'date'    => '2026-08-21T07:31:00',
				'kind'    => [ $term->term_id ],
			]
		);
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '2026-08-21 07:31:00', get_post( (int) $response->get_data()['id'] )->post_date );
	}

	public function test_a_rest_update_to_a_stored_sleep_post_gets_noon_without_a_kind_in_the_request(): void {
		$made = $this->insert( [ 'post_date' => '2026-08-21 07:31:00' ] );
		wp_set_object_terms( $made['id'], 'sleep', 'kind' );
		Sleep_Night::reset();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $made['id'] );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body_params( [ 'date' => '2026-08-23T08:15:00' ] );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );

		clean_post_cache( $made['id'] );
		$this->assertSame( '2026-08-22 12:00:00', get_post( $made['id'] )->post_date );
	}

	public function test_a_read_card_first_and_a_sleep_card_second_is_a_read_post_and_keeps_its_time(): void {
		$content = '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing"} /-->' . "\n\n" . $this->card( [ 'bedtime' => '2026-08-19T23:30' ] );
		$made    = $this->insert(
			[
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => $content,
			]
		);

		$this->assertSame( '2026-08-20 07:31:00', $made['post']->post_date );
	}

	public function test_a_note_keeps_its_time(): void {
		$made = $this->insert(
			[
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => '<!-- wp:paragraph --><p>Slept badly.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertSame( '2026-08-20 07:31:00', $made['post']->post_date );
	}

	public function test_a_page_with_a_sleep_card_keeps_its_time(): void {
		$made = $this->insert(
			[
				'post_type' => 'page',
				'post_date' => '2026-08-20 07:31:00',
			]
		);

		$this->assertSame( '2026-08-20 07:31:00', $made['post']->post_date );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function unpublished_statuses(): array {
		return [
			'draft'   => [ 'draft' ],
			'pending' => [ 'pending' ],
		];
	}

	/**
	 * @dataProvider unpublished_statuses
	 *
	 * @param string $status Post status.
	 */
	public function test_a_draft_or_pending_sleep_post_keeps_its_time( string $status ): void {
		$made = $this->insert(
			[
				'post_status' => $status,
				'post_date'   => '2026-08-20 07:31:00',
			]
		);

		$this->assertSame( '2026-08-20 07:31:00', $made['post']->post_date );
	}

	public function test_a_private_sleep_post_gets_noon_too(): void {
		$made = $this->insert(
			[
				'post_status' => 'private',
				'post_date'   => '2026-08-21 07:31:00',
			]
		);

		$this->assertSame( '2026-08-20 12:00:00', $made['post']->post_date );
		$this->assertSame( 'private', $made['post']->post_status );
	}

	// --- Scheduling. ---

	public function test_a_post_scheduled_for_tomorrow_morning_files_under_tonight_s_noon_and_publishes_when_noon_has_passed(): void {
		$this->local_hour_is( 15 );
		$today    = wp_date( 'Y-m-d' );
		$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );

		$made = $this->insert(
			[
				'post_status' => 'future',
				'post_date'   => $tomorrow . ' 07:00:00',
			]
		);

		$this->assertSame( $today . ' 12:00:00', $made['post']->post_date );
		$this->assertSame( 'publish', $made['post']->post_status, 'Noon today is behind us at 15:00, so core\'s own 60 second test says publish.' );
	}

	public function test_a_post_scheduled_for_tomorrow_morning_stays_scheduled_when_noon_is_still_ahead(): void {
		$this->local_hour_is( 8 );
		$today    = wp_date( 'Y-m-d' );
		$tomorrow = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );

		$made = $this->insert(
			[
				'post_status' => 'future',
				'post_date'   => $tomorrow . ' 07:00:00',
			]
		);

		$noon_gmt = strtotime( get_gmt_from_date( $today . ' 12:00:00' ) . ' UTC' );
		$this->assertSame( $today . ' 12:00:00', $made['post']->post_date );
		$this->assertSame( 'future', $made['post']->post_status );
		$this->assertSame( $noon_gmt, wp_next_scheduled( 'publish_future_post', [ $made['id'] ] ), 'Cron publishes at that noon.' );
	}

	public function test_a_post_scheduled_three_days_out_stays_scheduled_at_its_night_s_noon(): void {
		$this->local_hour_is( 15 );
		$day  = wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS );
		$made = $this->insert(
			[
				'post_status' => 'publish',
				'post_date'   => $day . ' 07:00:00',
			]
		);

		$night = gmdate( 'Y-m-d', strtotime( $day . ' 07:00:00 UTC' ) - DAY_IN_SECONDS );
		$this->assertSame( $night . ' 12:00:00', $made['post']->post_date );
		$this->assertSame( 'future', $made['post']->post_status );
		$this->assertSame( strtotime( get_gmt_from_date( $night . ' 12:00:00' ) . ' UTC' ), wp_next_scheduled( 'publish_future_post', [ $made['id'] ] ) );
	}

	// --- Paths that skip wp_insert_post_data. ---

	public function test_wp_publish_post_repairs_a_draft_s_time(): void {
		$made = $this->insert(
			[
				'post_status' => 'draft',
				'post_date'   => '2026-08-20 07:31:00',
			]
		);
		$this->assertSame( '2026-08-20 07:31:00', $made['post']->post_date );

		wp_publish_post( $made['id'] );
		clean_post_cache( $made['id'] );
		$post = get_post( $made['id'] );

		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_date );
		$this->assertSame( '2026-08-19 16:00:00', $post->post_date_gmt );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_modified );
		$this->assertSame( '2026-08-19 16:00:00', $post->post_modified_gmt );
	}

	public function test_the_repair_runs_before_the_publish_hooks_read_the_post(): void {
		$made = $this->insert(
			[
				'post_status' => 'draft',
				'post_date'   => '2026-08-20 07:31:00',
			]
		);

		$seen = [];
		add_action(
			'transition_post_status',
			static function ( $new, $old, $post ) use ( &$seen ): void {
				if ( 'draft' === $old && 'publish' === $new ) {
					$seen[] = $post->post_date;
				}
			},
			5,
			3
		);
		wp_publish_post( $made['id'] );

		$this->assertSame( [ '2026-08-19 12:00:00' ], $seen, 'A priority 5 hook sees the noon date on the post object.' );
	}

	public function test_the_scheduled_publish_path_repairs_the_time_too(): void {
		$made = $this->insert(
			[
				'post_status' => 'future',
				'post_date'   => wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) . ' 07:00:00',
			]
		);
		$this->assertSame( 'future', $made['post']->post_status );

		// Cron fires later: the stored date is moved back as if the clock had run on.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date'     => '2026-08-20 07:31:00',
				'post_date_gmt' => '2026-08-20 11:31:00',
			],
			[ 'ID' => $made['id'] ]
		);
		clean_post_cache( $made['id'] );
		check_and_publish_future_post( $made['id'] );

		clean_post_cache( $made['id'] );
		$post = get_post( $made['id'] );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_date );
	}

	public function test_wp_publish_post_leaves_a_note_alone(): void {
		$made = $this->insert(
			[
				'post_status'  => 'draft',
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => '<!-- wp:paragraph --><p>Hi.</p><!-- /wp:paragraph -->',
			]
		);

		wp_publish_post( $made['id'] );
		clean_post_cache( $made['id'] );

		$this->assertSame( '2026-08-20 07:31:00', get_post( $made['id'] )->post_date );
	}

	public function test_a_published_sleep_post_already_at_noon_is_not_rewritten_by_the_repair(): void {
		$made = $this->insert( [ 'post_date' => '2026-08-20 12:00:00' ] );
		$post = $made['post'];
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $made['id'] ] );
		clean_post_cache( $made['id'] );

		wp_publish_post( $made['id'] );
		clean_post_cache( $made['id'] );

		$after = get_post( $made['id'] );
		$this->assertSame( 'publish', $after->post_status );
		$this->assertSame( $post->post_date, $after->post_date );
		$this->assertSame( $post->post_modified, $after->post_modified, 'Nothing to repair, so nothing is written.' );
	}

	// --- The Micropub night hint. ---

	public function test_a_pending_night_hint_files_the_next_insert_and_is_used_once(): void {
		Sleep_Night::set_pending_night( '2026-08-13' );
		$first = $this->insert(
			[
				'post_date'    => '2026-08-14 07:00:00',
				'post_content' => 'Slept okay.',
			]
		);
		$second = $this->insert(
			[
				'post_date'    => '2026-08-14 07:00:00',
				'post_content' => 'Plain note.',
			]
		);

		$this->assertSame( '2026-08-13 12:00:00', $first['post']->post_date );
		$this->assertSame( '2026-08-14 07:00:00', $second['post']->post_date, 'The hint is spent.' );
	}

	public function test_a_pending_hint_that_is_not_a_date_is_ignored(): void {
		Sleep_Night::set_pending_night( 'last night' );
		$made = $this->insert(
			[
				'post_date'    => '2026-08-14 07:00:00',
				'post_content' => 'Plain note.',
			]
		);

		$this->assertSame( '2026-08-14 07:00:00', $made['post']->post_date );
	}

	// --- Modified time. ---

	public function test_every_save_in_the_publishing_request_keeps_modified_at_noon(): void {
		$made = $this->insert(
			[
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => $this->card( [ 'bedtime' => '2026-08-19T23:30' ] ),
			]
		);
		$this->assertSame( '2026-08-19 12:00:00', $made['post']->post_modified );

		// Micropub's second pass rewrites the content in the same request.
		wp_update_post(
			[
				'ID'           => $made['id'],
				'post_content' => wp_slash( $this->card( [ 'bedtime' => '2026-08-19T23:30' ] ) . '<!-- wp:paragraph --><p>Added.</p><!-- /wp:paragraph -->' ),
			]
		);
		clean_post_cache( $made['id'] );

		$post = get_post( $made['id'] );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_modified, 'Same request, still noon.' );
		$this->assertSame( '2026-08-19 16:00:00', $post->post_modified_gmt );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_date );
	}

	public function test_a_later_save_that_changes_nothing_keeps_the_stored_modified_time(): void {
		$made = $this->insert( [ 'post_date' => '2026-08-20 07:31:00' ] );
		Sleep_Night::reset();

		wp_update_post( [ 'ID' => $made['id'] ] );
		clean_post_cache( $made['id'] );

		$post = get_post( $made['id'] );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_modified, 'The meta box save changes no field Gutenberg sends.' );
		$this->assertSame( '2026-08-19 16:00:00', $post->post_modified_gmt );
	}

	public function test_a_later_save_that_edits_the_content_writes_the_real_time(): void {
		$made = $this->insert( [ 'post_date' => '2026-08-20 07:31:00' ] );
		Sleep_Night::reset();
		$before = time();

		wp_update_post(
			[
				'ID'           => $made['id'],
				'post_content' => wp_slash( $this->card( [ 'durationMinutes' => 400 ] ) ),
			]
		);
		clean_post_cache( $made['id'] );

		$modified = strtotime( get_post( $made['id'] )->post_modified_gmt . ' UTC' );
		$this->assertGreaterThanOrEqual( $before - 1, $modified, 'A real edit writes the real time.' );
		$this->assertSame( '2026-08-19 12:00:00', get_post( $made['id'] )->post_date, 'The date stays at noon.' );
	}

	public function test_a_note_s_modified_time_is_core_s(): void {
		$made = $this->insert(
			[
				'post_date'    => '2026-08-20 07:31:00',
				'post_content' => '<!-- wp:paragraph --><p>Hi.</p><!-- /wp:paragraph -->',
			]
		);
		Sleep_Night::reset();
		$before = time();

		wp_update_post( [ 'ID' => $made['id'] ] );
		clean_post_cache( $made['id'] );

		$this->assertGreaterThanOrEqual( $before - 1, strtotime( get_post( $made['id'] )->post_modified_gmt . ' UTC' ) );
	}

	public function test_publishing_a_draft_in_a_later_request_sets_modified_to_noon(): void {
		$made = $this->insert(
			[
				'post_status' => 'draft',
				'post_date'   => '2026-08-20 07:31:00',
			]
		);
		Sleep_Night::reset();

		wp_update_post(
			[
				'ID'          => $made['id'],
				'post_status' => 'publish',
				'post_date'   => '2026-08-20 07:31:00',
				'edit_date'   => true,
			]
		);
		clean_post_cache( $made['id'] );

		$post = get_post( $made['id'] );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_date );
		$this->assertSame( '2026-08-19 12:00:00', $post->post_modified );
		$this->assertSame( '2026-08-19 16:00:00', $post->post_modified_gmt );
	}
}

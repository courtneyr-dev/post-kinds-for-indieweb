<?php
/**
 * The sleep facts reader and the public date frame (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Sleep_Archive;

/**
 * Sleep_Archive::facts() is the one door a theme and the card render read.
 * Duration, the night and the moon are public. Bedtime and wake time come
 * back only when the post's own toggle row says so AND the first sleep
 * card's own showTimesPublicly attribute is on. Either half alone prints
 * nothing, because health_details_visible() reads only the post row.
 *
 * @group integration
 */
final class SleepFactsTest extends WP_UnitTestCase {

	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'sleep', 'note', 'read' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Sleep card markup, a bare block comment as save.js's null return stores.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return [] === $attrs
			? '<!-- wp:post-kinds-indieweb/sleep-card /-->'
			: '<!-- wp:post-kinds-indieweb/sleep-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published sleep post at noon of its night.
	 *
	 * @param string               $night Night, Y-m-d.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $post  Post overrides.
	 * @return int Post ID.
	 */
	private function sleep( string $night, array $attrs, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '',
					'post_date'    => $night . ' 12:00:00',
					'post_content' => [] === $attrs ? '' : $this->card( $attrs ),
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'sleep', 'kind' );

		return (int) $id;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function facts( int $id ): array {
		return Sleep_Archive::facts( $id );
	}

	public function test_facts_hold_the_documented_keys_and_nothing_else(): void {
		$id    = $this->sleep( '2026-08-19', [ 'durationMinutes' => 462 ] );
		$facts = $this->facts( $id );

		$this->assertSame(
			[
				'name',
				'name_is_title',
				'name_kind',
				'has_card',
				'night',
				'night_display',
				'night_short',
				'duration_minutes',
				'duration_iso',
				'duration_label',
				'duration_ratio',
				'phase',
				'phase_label',
				'phase_principal',
				'bedtime_iso',
				'bedtime_label',
				'wake_iso',
				'wake_label',
			],
			array_keys( $facts )
		);
		$this->assertSame( 462, $facts['duration_minutes'] );
		$this->assertSame( 'PT7H42M', $facts['duration_iso'] );
		$this->assertSame( '7 hr 42 min', $facts['duration_label'] );
		$this->assertSame( 0.6417, $facts['duration_ratio'] );
		$this->assertTrue( $facts['has_card'] );
		$this->assertSame( '2026-08-19', $facts['night'] );
		$this->assertSame( 'August 19, 2026', $facts['night_display'] );
		$this->assertSame( 'Aug 19', $facts['night_short'] );
		$this->assertSame( 'first-quarter', $facts['phase'] );
		$this->assertSame( 'First quarter', $facts['phase_label'] );
		$this->assertTrue( $facts['phase_principal'] );
	}

	public function test_a_post_that_is_not_a_sleep_post_has_no_facts(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $this->card( [ 'durationMinutes' => 400 ] ) ] );
		wp_set_object_terms( $id, 'note', 'kind' );

		$this->assertSame( [], $this->facts( (int) $id ) );
		$this->assertSame( [], $this->facts( 0 ) );
		$this->assertSame( [], $this->facts( 99999999 ) );
	}

	public function test_kind_facts_routes_a_sleep_post_to_this_reader(): void {
		$id = $this->sleep( '2026-08-19', [ 'durationMinutes' => 462 ] );

		$this->assertSame( $this->facts( $id ), Kind_Facts::get( $id ) );
		$this->assertNotNull( Kind_Facts::reader( 'sleep' ) );
	}

	public function test_hidden_times_make_facts_equal_to_a_card_that_never_stored_them(): void {
		$hidden = $this->sleep(
			'2026-08-19',
			[
				'durationMinutes' => 420,
				'bedtime'         => '2026-08-19T23:10',
				'wakeTime'        => '2026-08-20T06:10',
			]
		);
		$never  = $this->sleep( '2026-08-19', [ 'durationMinutes' => 420 ] );

		$this->assertSame( $this->facts( $never ), $this->facts( $hidden ) );
		$this->assertStringNotContainsString( '23:10', (string) wp_json_encode( $this->facts( $hidden ) ) );
		$this->assertStringNotContainsString( '06:10', (string) wp_json_encode( $this->facts( $hidden ) ) );
	}

	public function test_opted_in_times_come_back_as_iso_and_labels(): void {
		$id    = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			],
			[ 'post_title' => 'Early night' ]
		);
		$facts = $this->facts( $id );

		$this->assertSame( '2026-08-11T21:45', $facts['bedtime_iso'] );
		$this->assertSame( '2026-08-12T06:15', $facts['wake_iso'] );
		$this->assertSame( '9:45 pm', $facts['bedtime_label'] );
		$this->assertSame( '6:15 am', $facts['wake_label'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( 'Early night', $facts['name'] );
	}

	public function test_a_visible_post_row_with_the_card_toggle_off_prints_no_time(): void {
		$id = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes' => 510,
				'bedtime'         => '2026-08-11T21:45',
				'wakeTime'        => '2026-08-12T06:15',
			]
		);
		update_post_meta( $id, Meta_Fields::SLEEP_PUBLIC_KEY, '1' );

		$this->assertTrue( Meta_Fields::health_details_visible( $id ), 'The post row alone says visible.' );
		$facts = $this->facts( $id );

		$this->assertSame( '', $facts['bedtime_iso'] );
		$this->assertSame( '', $facts['bedtime_label'] );
		$this->assertSame( '', $facts['wake_iso'] );
		$this->assertSame( '', $facts['wake_label'] );
		$this->assertSame( 510, $facts['duration_minutes'], 'The duration stays public.' );
	}

	public function test_a_card_toggle_on_with_no_visible_post_row_prints_no_time(): void {
		$id = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 510,
				'bedtime'           => '2026-08-11T21:45',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			]
		);
		$this->assertTrue( Meta_Fields::health_details_visible( $id ), 'The sync wrote the row.' );
		delete_post_meta( $id, Meta_Fields::SLEEP_PUBLIC_KEY );

		$this->assertFalse( Meta_Fields::health_details_visible( $id ) );
		$facts = $this->facts( $id );

		foreach ( [ 'bedtime_iso', 'bedtime_label', 'wake_iso', 'wake_label' ] as $key ) {
			$this->assertSame( '', $facts[ $key ], $key );
		}
		$this->assertSame( 510, $facts['duration_minutes'] );

		foreach ( [ '0', 'yes', '' ] as $row ) {
			update_post_meta( $id, Meta_Fields::SLEEP_PUBLIC_KEY, $row );
			$this->assertSame( '', $this->facts( $id )['bedtime_iso'], "Row '$row' is not the exact one." );
		}
	}

	public function test_the_first_sleep_card_decides_the_toggle(): void {
		$content = $this->card( [ 'durationMinutes' => 400, 'bedtime' => '2026-08-11T22:00', 'wakeTime' => '2026-08-12T05:00' ] )
			. "\n\n" . $this->card( [ 'durationMinutes' => 400, 'bedtime' => '2026-08-11T22:00', 'wakeTime' => '2026-08-12T05:00', 'showTimesPublicly' => true ] );
		$id      = $this->sleep( '2026-08-11', [], [ 'post_content' => $content ] );
		update_post_meta( $id, Meta_Fields::SLEEP_PUBLIC_KEY, '1' );

		$this->assertSame( '', $this->facts( $id )['bedtime_iso'], 'A second card cannot open the first card\'s times.' );

		$reverse = $this->card( [ 'durationMinutes' => 400, 'bedtime' => '2026-08-11T22:00', 'wakeTime' => '2026-08-12T05:00', 'showTimesPublicly' => true ] )
			. "\n\n" . $this->card( [ 'durationMinutes' => 400 ] );
		$id2     = $this->sleep( '2026-08-11', [], [ 'post_content' => $reverse ] );
		$this->assertSame( '2026-08-11T22:00', $this->facts( $id2 )['bedtime_iso'] );
	}

	public function test_a_card_nested_in_a_group_is_found(): void {
		$inner   = $this->card( [ 'durationMinutes' => 300, 'bedtime' => '2026-08-11T23:00', 'wakeTime' => '2026-08-12T04:00', 'showTimesPublicly' => true ] );
		$content = '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
		$id      = $this->sleep( '2026-08-11', [], [ 'post_content' => $content ] );

		$this->assertSame( 300, $this->facts( $id )['duration_minutes'] );
		$this->assertSame( '2026-08-11T23:00', $this->facts( $id )['bedtime_iso'] );
	}

	public function test_a_duration_comes_from_the_times_when_the_card_has_none(): void {
		$id = $this->sleep(
			'2026-08-11',
			[
				'bedtime'           => '2026-08-11T22:30',
				'wakeTime'          => '2026-08-12T06:00',
				'showTimesPublicly' => true,
			]
		);

		$this->assertSame( 450, $this->facts( $id )['duration_minutes'] );
		$this->assertSame( 'PT7H30M', $this->facts( $id )['duration_iso'] );

		$hidden = $this->sleep( '2026-08-11', [ 'bedtime' => '2026-08-11T22:30', 'wakeTime' => '2026-08-12T06:00' ] );
		$facts  = $this->facts( $hidden );
		$this->assertSame( 450, $facts['duration_minutes'], 'The span is the public duration, the clock times are not.' );
		$this->assertSame( '', $facts['bedtime_iso'] );
	}

	public function test_the_ratio_clamps_at_twelve_hours_and_a_missing_duration_is_empty(): void {
		$long = $this->facts( $this->sleep( '2026-07-10', [ 'durationMinutes' => 750 ] ) );
		$this->assertSame( 1.0, $long['duration_ratio'] );
		$this->assertSame( 750, $long['duration_minutes'] );

		$none = $this->facts( $this->sleep( '2026-08-03', [] ) );
		$this->assertSame( 0, $none['duration_minutes'] );
		$this->assertSame( 0.0, $none['duration_ratio'] );
		$this->assertSame( '', $none['duration_iso'] );
		$this->assertSame( '', $none['duration_label'] );
		$this->assertFalse( $none['has_card'] );

		$bare = $this->facts( $this->sleep( '2026-08-03', [ 'origin' => 'micropub' ] ) );
		$this->assertTrue( $bare['has_card'] );
		$this->assertSame( 0, $bare['duration_minutes'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function invalid_durations(): array {
		return [
			'zero'      => [ 0 ],
			'negative'  => [ -5 ],
			'too long'  => [ 1441 ],
			'text'      => [ 'a long night' ],
			'fractions' => [ 7.5 ],
			'array'     => [ [ 400 ] ],
		];
	}

	/**
	 * @dataProvider invalid_durations
	 *
	 * @param mixed $value Stored duration.
	 */
	public function test_an_invalid_duration_reads_as_no_duration( $value ): void {
		$id = $this->sleep( '2026-08-03', [ 'durationMinutes' => $value ] );

		$this->assertSame( 0, $this->facts( $id )['duration_minutes'] );
		$this->assertSame( '', $this->facts( $id )['duration_label'] );
	}

	public function test_a_malformed_time_is_dropped_and_a_valid_one_beside_it_survives(): void {
		$id    = $this->sleep(
			'2026-08-11',
			[
				'durationMinutes'   => 400,
				'bedtime'           => '9:45 pm',
				'wakeTime'          => '2026-08-12T06:15',
				'showTimesPublicly' => true,
			]
		);
		$facts = $this->facts( $id );

		$this->assertSame( '', $facts['bedtime_iso'] );
		$this->assertSame( '2026-08-12T06:15', $facts['wake_iso'] );
	}

	public function test_protected_private_and_draft_posts_have_no_facts_logged_out(): void {
		$protected = $this->sleep( '2026-08-08', [ 'durationMinutes' => 430 ], [ 'post_password' => 'example-pass' ] );
		$private   = $this->sleep( '2026-08-07', [ 'durationMinutes' => 430 ], [ 'post_status' => 'private' ] );
		$draft     = $this->sleep( '2026-08-06', [ 'durationMinutes' => 430 ], [ 'post_status' => 'draft' ] );

		foreach ( [ $protected, $private, $draft ] as $id ) {
			$this->assertSame( [], $this->facts( $id ), 'Post ' . $id );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertNotSame( [], $this->facts( $private ), 'An editor reads the private post.' );
		$this->assertSame( [], $this->facts( $protected ), 'A password hides it from the editor too.' );
	}

	public function test_the_frame_of_a_protected_post_is_the_date_and_the_moon_only(): void {
		$id = $this->sleep(
			'2026-08-08',
			[
				'durationMinutes'   => 430,
				'bedtime'           => '2026-08-08T23:40',
				'wakeTime'          => '2026-08-09T06:30',
				'showTimesPublicly' => true,
			],
			[ 'post_password' => 'example-pass', 'post_title' => 'Secret night' ]
		);
		$frame = Sleep_Archive::frame( get_post( $id ) );

		$this->assertSame(
			[ 'night', 'night_short', 'night_display', 'phase', 'phase_label', 'phase_principal' ],
			array_keys( $frame )
		);
		$this->assertSame( '2026-08-08', $frame['night'] );
		$this->assertSame( 'waning-crescent', $frame['phase'] );
		$this->assertFalse( $frame['phase_principal'] );
		$json = (string) wp_json_encode( $frame );
		foreach ( [ '23:40', '06:30', '430', 'Secret', 'duration' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_the_frame_reads_the_post_date_never_a_meta_row(): void {
		$id = $this->sleep( '2026-08-08', [ 'durationMinutes' => 430 ] );
		update_post_meta( $id, '_pkiw_sleep_bedtime', '2026-01-01T23:00' );

		$this->assertSame( '2026-08-08', Sleep_Archive::frame( get_post( $id ) )['night'] );
	}

	public function test_an_untitled_post_is_named_by_its_kind_and_night_without_a_title_flag(): void {
		$untitled = $this->facts( $this->sleep( '2026-08-20', [ 'durationMinutes' => 462 ] ) );
		$titled   = $this->facts( $this->sleep( '2026-08-21', [ 'durationMinutes' => 462 ], [ 'post_title' => 'Storm night' ] ) );

		$this->assertSame( 'Sleep, August 20, 2026', $untitled['name'] );
		$this->assertFalse( $untitled['name_is_title'] );
		$this->assertSame( 'Sleep', $untitled['name_kind'] );
		$this->assertSame( 'Storm night', $titled['name'] );
		$this->assertTrue( $titled['name_is_title'] );
	}

	public function test_the_short_night_prints_the_stored_day_in_a_timezone_west_of_utc(): void {
		update_option( 'timezone_string', 'Pacific/Honolulu' );
		$id    = $this->sleep( '2026-08-20', [ 'durationMinutes' => 462 ] );
		$facts = $this->facts( $id );

		$this->assertSame( 'Aug 20', $facts['night_short'] );
		$this->assertSame( 'August 20, 2026', $facts['night_display'] );
		$this->assertSame( '2026-08-20', $facts['night'] );
	}

	public function test_the_micropub_plugin_rows_never_reach_the_facts(): void {
		$id = $this->sleep( '2026-08-12', [ 'durationMinutes' => 425, 'origin' => 'micropub' ] );
		update_post_meta( $id, 'mf2_sleep', [ '7h 05m, restless after 3am' ] );
		update_post_meta( $id, 'mf2_start', [ '2026-08-12T23:40:00-04:00' ] );
		update_post_meta( $id, 'mf2_end', [ '2026-08-13T06:45:00-04:00' ] );

		$json = (string) wp_json_encode( $this->facts( $id ) );
		foreach ( [ '23:40', '06:45', 'restless', '3am', 'micropub' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
		$this->assertSame( 425, $this->facts( $id )['duration_minutes'] );
	}

	public function test_a_card_less_sleep_post_has_the_date_and_the_moon_and_no_duration(): void {
		$id    = $this->sleep( '2026-08-04', [], [ 'post_content' => 'Fictional note: quiet night.' ] );
		$facts = $this->facts( $id );

		$this->assertFalse( $facts['has_card'] );
		$this->assertSame( 'waning-gibbous', $facts['phase'] );
		$this->assertSame( 0, $facts['duration_minutes'] );
		$this->assertSame( '', $facts['bedtime_iso'] );
	}

	public function test_the_night_follows_a_bedtime_after_midnight(): void {
		$id = $this->sleep(
			'2026-08-19',
			[
				'durationMinutes' => 400,
				'bedtime'         => '2026-08-20T00:40',
			]
		);

		$this->assertSame( '2026-08-19', $this->facts( $id )['night'] );
	}
}

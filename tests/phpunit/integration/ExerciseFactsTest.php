<?php
/**
 * The exercise facts reader (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Exercise_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Exercise_Archive::facts() is the only door a theme reads an exercise post
 * through. Activity and the date are public. Distance, duration, splits and
 * the personal best print only while BOTH the card's own showDetailsPublicly
 * attribute and the post's `_pkiw_exercise_details_public` row say so, since
 * Meta_Fields::health_details_visible() reads the row alone (review finding
 * N3 on the health rule). No key names heart rate, calories, a start or end,
 * a clock time or the card's origin, because Post Kinds stores none of them.
 *
 * Fixture names and values are invented. Dates are the lane's fixture week.
 *
 * @group integration
 */
final class ExerciseFactsTest extends WP_UnitTestCase {

	private const PUBLIC_KEY = '_pkiw_exercise_details_public';

	private string $original_timezone = '';

	private string $original_date_format = '';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		$this->original_timezone    = (string) get_option( 'timezone_string' );
		$this->original_date_format = (string) get_option( 'date_format' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'start_of_week', 0 );
		delete_option( 'sloc_measurements' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'date_format', $this->original_date_format );
		delete_option( 'sloc_measurements' );
		remove_all_filters( 'pkiw_exercise_total_unit' );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A card with every opt-in field set, the way A01 is.
	 *
	 * @param bool $public Whether the card's own toggle is on.
	 * @return array<string, mixed>
	 */
	private function full_attrs( bool $public ): array {
		$attrs = [
			'activity'     => 'Run',
			'distance'     => 5.0,
			'duration'     => 'PT29M12S',
			'splits'       => [
				[
					'i' => 1,
					't' => 'PT5M58S',
				],
				[
					'i' => 2,
					't' => 'PT5M51S',
				],
				[
					'i' => 3,
					't' => 'PT5M49S',
				],
				[
					'i' => 4,
					't' => 'PT5M47S',
				],
				[
					'i' => 5,
					't' => 'PT5M47S',
				],
			],
			'personalBest' => true,
			'pbCategory'   => '5k',
		];
		if ( $public ) {
			$attrs['showDetailsPublicly'] = true;
		}

		return $attrs;
	}

	/**
	 * A published exercise post holding one card.
	 *
	 * @param string               $title Post title.
	 * @param string               $date  Local post date.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post args.
	 * @return int Post ID.
	 */
	private function exercise( string $title, string $date, array $attrs, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => '<!-- wp:post-kinds-indieweb/exercise-card ' . serialize_block_attributes( $attrs ) . ' /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'exercise', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	public function test_an_opted_in_post_reports_every_value_in_stored_order(): void {
		$id    = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', $this->full_attrs( true ) );
		$facts = Kind_Facts::get( $id );

		$this->assertSame( 'Lakeside Loop', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( 'Run', $facts['activity'] );
		$this->assertSame( '2026-08-23', $facts['date_iso'] );
		$this->assertSame( 'August 23, 2026', $facts['date_display'] );
		$this->assertSame( 5.0, $facts['distance'] );
		$this->assertSame( 'km', $facts['distance_unit'] );
		$this->assertSame( '5.00 km', $facts['distance_label'] );
		$this->assertSame( 1752, $facts['duration_seconds'] );
		$this->assertSame( 'PT29M12S', $facts['duration_iso'] );
		$this->assertSame( '29:12', $facts['duration_label'] );
		$this->assertTrue( $facts['personal_best'] );
		$this->assertSame( '5k', $facts['pb_category'] );
		$this->assertSame( '5K', $facts['pb_label'] );

		$this->assertSame( [ 'PT5M58S', 'PT5M51S', 'PT5M49S', 'PT5M47S', 'PT5M47S' ], array_column( $facts['splits'], 'iso' ) );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_column( $facts['splits'], 'index' ) );
		$this->assertSame( [ '5:58', '5:51', '5:49', '5:47', '5:47' ], array_column( $facts['splits'], 'label' ) );
		$this->assertSame( [ 'km', 'km', 'km', 'km', 'km' ], array_column( $facts['splits'], 'unit' ) );
	}

	public function test_a_hidden_post_returns_what_a_never_stored_twin_returns(): void {
		$private = $this->exercise( 'Evening Walk', '2026-08-24 19:40:00', array_merge( $this->full_attrs( false ), [ 'activity' => 'Walk' ] ) );
		$never   = $this->exercise( 'Evening Walk', '2026-08-25 19:40:00', [ 'activity' => 'Walk' ] );
		// The opt-in rows really are stored on the private one: the card decides rows, not visibility.
		$this->assertSame( '5', (string) get_metadata_raw( 'post', $private, '_pkiw_exercise_distance', true ) );

		$hidden = Kind_Facts::get( $private );
		$twin   = Kind_Facts::get( $never );
		foreach ( [ 'date_iso', 'date_display' ] as $key ) {
			unset( $hidden[ $key ], $twin[ $key ] );
		}

		$this->assertNotSame( [], $twin );
		$this->assertSame( $twin, $hidden, 'Hidden details match a card that never stored them, key for key.' );
		$this->assertSame( 'Walk', $hidden['activity'] );
		$this->assertNull( $hidden['distance'] );
		$this->assertSame( [], $hidden['splits'] );
		$this->assertFalse( $hidden['personal_best'] );
	}

	public function test_a_post_row_of_one_does_not_open_a_card_whose_own_toggle_is_off(): void {
		$id = $this->exercise( 'Stale row', '2026-08-24 19:40:00', $this->full_attrs( false ) );
		update_post_meta( $id, self::PUBLIC_KEY, '1' );

		$this->assertTrue( Meta_Fields::health_details_visible( $id ), 'The post row alone says visible: the gap the card toggle closes.' );
		$facts = Kind_Facts::get( $id );
		$this->assertNull( $facts['distance'] );
		$this->assertSame( '', $facts['distance_label'] );
		$this->assertSame( 0, $facts['duration_seconds'] );
		$this->assertSame( '', $facts['duration_label'] );
		$this->assertSame( [], $facts['splits'] );
		$this->assertFalse( $facts['personal_best'] );
		$this->assertSame( '', $facts['pb_label'] );
		$this->assertSame( 'Run', $facts['activity'], 'Activity is public either way.' );
	}

	public function test_a_card_toggle_that_is_on_does_not_open_a_post_whose_row_is_gone(): void {
		$id = $this->exercise( 'Missing row', '2026-08-24 19:40:00', $this->full_attrs( true ) );
		$this->assertNotNull( Kind_Facts::get( $id )['distance'], 'Both say visible.' );

		delete_post_meta( $id, self::PUBLIC_KEY );
		$this->assertFalse( Meta_Fields::health_details_visible( $id ) );
		$facts = Kind_Facts::get( $id );
		$this->assertNull( $facts['distance'] );
		$this->assertSame( 0, $facts['duration_seconds'] );
		$this->assertSame( [], $facts['splits'] );
		$this->assertFalse( $facts['personal_best'] );
	}

	public function test_an_administrator_gets_the_same_facts_as_a_visitor(): void {
		$id      = $this->exercise( 'Same for all', '2026-08-24 19:40:00', $this->full_attrs( false ) );
		$visitor = Kind_Facts::get( $id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $visitor, Kind_Facts::get( $id ) );
	}

	public function test_an_untitled_post_is_named_by_kind_and_date_with_no_time(): void {
		$id    = $this->exercise( '', '2026-08-27 12:15:00', [ 'activity' => 'Bike ride' ] );
		$facts = Kind_Facts::get( $id );

		$this->assertSame( 'Exercise, August 27, 2026', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
		$this->assertSame( 'Exercise', $facts['name_kind'] );
		$this->assertSame( 'August 27, 2026', $facts['name_date'] );
		$this->assertStringNotContainsString( ':', $facts['name'] );
		$this->assertStringNotContainsString( '12', $facts['name'], 'No clock time in the name.' );
	}

	public function test_a_titled_post_leaves_the_untitled_name_parts_empty(): void {
		$facts = Kind_Facts::get( $this->exercise( 'Hill Repeats', '2026-08-29 23:30:00', [ 'activity' => 'Run' ] ) );

		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( '', $facts['name_kind'] );
		$this->assertSame( '', $facts['name_date'] );
		$this->assertSame( '2026-08-29', $facts['date_iso'], 'The local day, not the UTC day (03:30 UTC on the 30th).' );
	}

	public function test_protected_private_and_draft_posts_have_no_facts_for_a_visitor(): void {
		$protected = $this->exercise( 'Members only', '2026-08-20 18:00:00', $this->full_attrs( true ), [ 'post_password' => 'secret' ] );
		$private   = $this->exercise( 'Quiet walk', '2026-08-18 09:00:00', $this->full_attrs( true ), [ 'post_status' => 'private' ] );
		$draft     = $this->exercise( 'Draft intervals', '2026-08-17 07:00:00', $this->full_attrs( true ), [ 'post_status' => 'draft' ] );

		foreach ( [ 'protected' => $protected, 'private' => $private, 'draft' => $draft ] as $name => $id ) {
			$this->assertSame( [], Kind_Facts::get( $id ), $name );
			$this->assertSame( [], Exercise_Archive::facts( $id ), $name . ' through the reader itself' );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( [], Kind_Facts::get( $protected ), 'A password hides the facts from an administrator too, as the page does.' );
		$this->assertNotSame( [], Kind_Facts::get( $private ), 'An editor reads a private post.' );
	}

	public function test_a_private_post_read_by_an_editor_is_named_without_core_s_private_prefix(): void {
		$private = $this->exercise( 'Quiet walk', '2026-08-18 09:00:00', [ 'activity' => 'Walk' ], [ 'post_status' => 'private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$facts = Kind_Facts::get( $private );
		$this->assertSame( 'Quiet walk', $facts['name'], 'get_the_title() would say "Private: Quiet walk".' );
		$this->assertStringNotContainsString( 'Private', (string) wp_json_encode( $facts ) );
	}

	/**
	 * An exercise post titled from a location that is hidden (a title the check-in importers generate).
	 *
	 * @param array<string, mixed> $args Post args.
	 */
	private function location_titled( array $args = [] ): int {
		return $this->exercise(
			'Maplewood Track',
			'2026-08-23 07:10:00',
			[ 'activity' => 'Run' ],
			array_merge(
				[
					'meta_input' => [
						'_pkiw_title_source' => 'location',
						'_pkiw_geo_privacy'  => 'private',
					],
				],
				$args
			)
		);
	}

	public function test_a_hidden_location_title_never_prints_on_an_admin_screen_and_facts_match_a_visitor(): void {
		$id      = $this->location_titled();
		$visitor = Kind_Facts::get( $id );
		$this->assertSame( 'Check-in, August 23, 2026', $visitor['name'], 'A visitor gets the safe title.' );
		$this->assertTrue( $visitor['name_is_title'] );

		// Title_Privacy hands the stored title back on an admin screen (the_title), so facts read the safe title.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );

		$facts = Kind_Facts::get( $id );
		$this->assertSame( $visitor, $facts, 'An administrator on an admin screen gets a visitor\'s facts.' );
		$this->assertStringNotContainsStringIgnoringCase( 'maplewood', (string) wp_json_encode( $facts ) );
	}

	public function test_a_visible_title_is_unchanged_on_an_admin_screen(): void {
		$id = $this->exercise( 'Lakeside Loop', '2026-08-23 07:10:00', [ 'activity' => 'Run' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'edit-post' );

		$this->assertSame( 'Lakeside Loop', Kind_Facts::get( $id )['name'] );
	}

	public function test_a_post_of_another_kind_has_no_exercise_facts(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );

		$this->assertSame( [], Exercise_Archive::facts( $note ) );
		$this->assertSame( [], Exercise_Archive::facts( 0 ) );
	}

	public function test_place_keys_follow_the_public_location_tier(): void {
		$venue = [
			'_pkiw_checkin_venue'     => 'Maplewood Track',
			'_pkiw_checkin_city'      => 'Fernvale',
			'_pkiw_checkin_region'    => 'North Province',
			'_pkiw_checkin_country'   => 'Freedonia',
			'_pkiw_checkin_address'   => '12 Example Lane',
			'_pkiw_checkin_latitude'  => '45.1000',
			'_pkiw_checkin_longitude' => '-75.2000',
		];
		$track = $this->exercise( 'Track Session', '2026-08-06 18:00:00', [ 'activity' => 'Run' ], [ 'meta_input' => $venue + [ '_pkiw_geo_privacy' => 'approximate' ] ] );
		$laps  = $this->exercise( 'Park Laps', '2026-08-07 18:00:00', [ 'activity' => 'Run' ], [ 'meta_input' => $venue + [ '_pkiw_geo_privacy' => 'private' ] ] );
		$bare  = $this->exercise( 'Map Walk', '2026-08-08 10:00:00', [ 'activity' => 'Walk' ] );

		$facts = Kind_Facts::get( $track );
		$this->assertSame( 'Maplewood Track', $facts['place_name'] );
		$this->assertSame( 'Fernvale', $facts['place_locality'] );
		$this->assertSame( 'North Province', $facts['place_region'] );
		$this->assertSame( 'Freedonia', $facts['place_country'] );
		$this->assertSame( '', $facts['place_street'], 'The approximate tier hides the street.' );

		foreach ( [ 'place_name', 'place_locality', 'place_region', 'place_country', 'place_street' ] as $key ) {
			$this->assertSame( '', Kind_Facts::get( $laps )[ $key ], "Private place: {$key}" );
			$this->assertSame( '', Kind_Facts::get( $bare )[ $key ], "No place: {$key}" );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( '', Kind_Facts::get( $laps )['place_name'], 'Editors see the public tier too.' );
		$this->assertSame( '', Kind_Facts::get( $track )['place_street'] );
	}

	public function test_no_key_names_a_biometric_a_clock_time_a_coordinate_or_the_origin(): void {
		$id   = $this->exercise( 'All keys', '2026-08-23 07:10:00', array_merge( $this->full_attrs( true ), [ 'origin' => 'micropub' ] ) );
		$keys = array_keys( Kind_Facts::get( $id ) );

		$this->assertSame(
			[
				'name',
				'name_is_title',
				'name_kind',
				'name_date',
				'activity',
				'activity_in_content',
				'date_iso',
				'date_display',
				'distance',
				'distance_unit',
				'distance_label',
				'duration_seconds',
				'duration_iso',
				'duration_label',
				'splits',
				'personal_best',
				'pb_category',
				'pb_label',
				'place_name',
				'place_locality',
				'place_region',
				'place_country',
				'place_street',
			],
			$keys
		);
		foreach ( $keys as $key ) {
			foreach ( [ 'heart', 'calorie', 'start', 'end', 'clock', 'origin', 'latitude', 'longitude', 'public', 'visible', 'private' ] as $banned ) {
				$this->assertStringNotContainsString( $banned, $key, "Key {$key}." );
			}
		}
		$this->assertStringNotContainsString( 'micropub', (string) wp_json_encode( Kind_Facts::get( $id ) ), 'The origin value never leaves.' );
	}

	public function test_micropub_plugin_rows_never_reach_the_facts(): void {
		$id = $this->exercise(
			'Rows outside Post Kinds',
			'2026-08-23 07:10:00',
			$this->full_attrs( true ),
			[
				'meta_input' => [
					'mf2_heart-rate' => [ '150' ],
					'mf2_calories'   => [ '480' ],
					'mf2_start'      => [ '2026-08-23T07:10:00-04:00' ],
					'mf2_end'        => [ '2026-08-23T07:40:00-04:00' ],
				],
			]
		);

		$json = (string) wp_json_encode( Kind_Facts::get( $id ) );
		foreach ( [ '150', '480', '07:10:00', '07:40:00', 'heart', 'calorie' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $needle );
		}
	}

	public function test_activity_in_content_is_true_for_a_legacy_body_and_false_for_a_card(): void {
		$legacy = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Ran Before Work',
				'post_content' => '<!-- wp:paragraph --><p><span class="p-exercise">Ran 5 km in 29:12</span></p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $legacy, 'exercise', Taxonomy::TAXONOMY );
		$card = $this->exercise( 'Carded', '2026-08-23 07:10:00', [ 'activity' => 'Run' ] );

		$facts = Kind_Facts::get( $legacy );
		$this->assertTrue( $facts['activity_in_content'] );
		$this->assertSame( '', $facts['activity'], 'No card, so no activity row.' );
		$this->assertNull( $facts['distance'] );
		$this->assertFalse( Kind_Facts::get( $card )['activity_in_content'] );
	}

	public function test_miles_convert_and_a_unit_without_a_distance_prints_nothing(): void {
		$miles = $this->exercise(
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
		$yoga  = $this->exercise(
			'Sunrise Flow',
			'2026-08-26 06:30:00',
			[
				'activity'            => 'Yoga',
				'duration'            => 'PT45M',
				'showDetailsPublicly' => true,
			]
		);

		$facts = Kind_Facts::get( $miles );
		$this->assertSame( 3.1, $facts['distance'] );
		$this->assertSame( 'mi', $facts['distance_unit'] );
		$this->assertSame( '3.10 mi', $facts['distance_label'] );

		$flow = Kind_Facts::get( $yoga );
		$this->assertNull( $flow['distance'] );
		$this->assertSame( '', $flow['distance_unit'], 'The default unit row means nothing without a distance.' );
		$this->assertSame( '', $flow['distance_label'] );
		$this->assertSame( '45:00', $flow['duration_label'] );
	}

	public function test_a_category_not_in_the_list_prints_as_typed_and_none_prints_no_label(): void {
		$typed = $this->exercise( 'Typed', '2026-08-23 07:10:00', [ 'activity' => 'Run', 'duration' => 'PT20M', 'personalBest' => true, 'pbCategory' => 'Parkrun', 'showDetailsPublicly' => true ] );
		$bare  = $this->exercise( 'Bare', '2026-08-24 07:10:00', [ 'activity' => 'Run', 'duration' => 'PT20M', 'personalBest' => true, 'showDetailsPublicly' => true ] );

		$this->assertSame( 'Parkrun', Kind_Facts::get( $typed )['pb_label'] );
		$this->assertTrue( Kind_Facts::get( $bare )['personal_best'] );
		$this->assertSame( '', Kind_Facts::get( $bare )['pb_label'] );
	}

	public function test_total_unit_follows_the_measurement_option_and_the_filter(): void {
		$this->assertSame( 'km', Exercise_Archive::total_unit(), 'No option and no Simple Location.' );

		update_option( 'sloc_measurements', 'imperial' );
		$this->assertSame( 'mi', Exercise_Archive::total_unit() );

		update_option( 'sloc_measurements', 'si' );
		$this->assertSame( 'km', Exercise_Archive::total_unit() );

		add_filter( 'pkiw_exercise_total_unit', static fn(): string => 'mi' );
		$this->assertSame( 'mi', Exercise_Archive::total_unit(), 'The filter wins over the option.' );

		remove_all_filters( 'pkiw_exercise_total_unit' );
		update_option( 'sloc_measurements', 'imperial' );
		add_filter( 'pkiw_exercise_total_unit', static fn(): string => 'km' );
		$this->assertSame( 'km', Exercise_Archive::total_unit(), 'The filter wins over an imperial site.' );

		remove_all_filters( 'pkiw_exercise_total_unit' );
		add_filter( 'pkiw_exercise_total_unit', static fn(): string => 'furlongs' );
		$this->assertSame( 'mi', Exercise_Archive::total_unit(), 'A value that is not km or mi falls back to the option.' );
	}

	public function test_the_editor_gets_the_activity_and_category_lists_on_the_blocks_handle(): void {
		if ( ! wp_script_is( 'post-kinds-indieweb-blocks', 'registered' ) ) {
			wp_register_script( 'post-kinds-indieweb-blocks', 'https://example.org/blocks.js', [], '1', true );
		}

		( new Exercise_Archive() )->register();
		do_action( 'enqueue_block_editor_assets' );

		$all = implode( "\n", (array) wp_scripts()->get_data( 'post-kinds-indieweb-blocks', 'before' ) );
		$this->assertStringContainsString( 'pkiwExercise', $all );
		$this->assertStringContainsString( '"Walk"', $all );
		$this->assertStringContainsString( '"5K"', $all );
		$this->assertStringContainsString( '"half"', $all );
		$this->assertStringNotContainsString( 'heart', strtolower( $all ) );
	}

	public function test_the_activity_and_category_lists_hold_the_documented_values(): void {
		$this->assertSame( [ 'Walk', 'Run', 'Cycle', 'Hike', 'Yoga', 'Swim' ], Exercise_Archive::activity_labels() );
		$this->assertSame(
			[ '1mi', '5k', '10k', 'half', 'marathon' ],
			array_keys( Exercise_Archive::pb_categories() )
		);
		$this->assertSame( '5K', Exercise_Archive::pb_categories()['5k'] );
	}
}

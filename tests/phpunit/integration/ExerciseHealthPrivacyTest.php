<?php
/**
 * Exercise details are private unless the post opts in (issue 245).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Meta_Fields;

/**
 * Courtney's answer A on the health data question: an exercise post's title,
 * activity type and date are public. Distance, duration, splits, personal
 * bests and the PB category print only when the card's "Show details
 * publicly" toggle is on. Heart rate, calories, other biometrics and start
 * and end clock times never print, because Post Kinds stores none of them.
 *
 * The toggle is the card attribute `showDetailsPublicly`, which
 * Card_Meta_Sync keeps in `_pkiw_exercise_details_public`. No exercise key is
 * registered, so REST, the abilities and core/post-meta bindings never see
 * one, and the card in post_content is the only control. These tests need no
 * block registration: the card's render.php and block.json arrive in later
 * lanes, and the render-dependent cases (feeds, microformats) live in
 * ExerciseCardRenderTest.
 *
 * @group integration
 */
final class ExerciseHealthPrivacyTest extends WP_UnitTestCase {

	private const PUBLIC_KEY = '_pkiw_exercise_details_public';

	/**
	 * Distinct values, one per hidden field, so a search for each one tells
	 * which field leaked.
	 */
	private const DISTANCE = '7.77';
	private const DURATION = 'PT41M37S';
	private const SPLIT    = 'PT6M11S';
	private const CATEGORY = 'Zq10K';
	private const ACTIVITY = 'Zq7Walk';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'exercise', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
			}
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Card attributes with every opt-in field set.
	 *
	 * @param bool $public Whether the card's toggle is on.
	 * @return array<string, mixed>
	 */
	private function attrs( bool $public ): array {
		$attrs = [
			'activity'     => self::ACTIVITY,
			'distance'     => (float) self::DISTANCE,
			'distanceUnit' => 'mi',
			'duration'     => self::DURATION,
			'splits'       => [
				[
					'i' => 1,
					't' => self::SPLIT,
				],
			],
			'personalBest' => true,
			'pbCategory'   => self::CATEGORY,
		];
		if ( $public ) {
			$attrs['showDetailsPublicly'] = true;
		}

		return $attrs;
	}

	/**
	 * Exercise card markup, a bare block comment as save.js's null return stores.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/exercise-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published exercise post.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @return int Post ID.
	 */
	private function exercise( string $title, array $attrs ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => 'Same excerpt',
				'post_content' => $this->card( $attrs ),
			]
		);
		wp_set_object_terms( $id, 'exercise', 'kind' );

		return $id;
	}

	/**
	 * IDs a logged-out search returns, limited to the given posts.
	 *
	 * @param string $terms Search string.
	 * @param int[]  $ids   Fixture IDs.
	 * @return int[]
	 */
	private function found( string $terms, array $ids ): array {
		$query = new WP_Query(
			[
				's'              => $terms,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			]
		);

		return array_values( array_intersect( array_map( 'intval', $query->posts ), $ids ) );
	}

	/**
	 * @return array<string, array{0: string|null, 1: bool}>
	 */
	public function public_row_values(): array {
		return [
			'no row'      => [ null, false ],
			'zero'        => [ '0', false ],
			'yes'         => [ 'yes', false ],
			'true'        => [ 'true', false ],
			'empty'       => [ '', false ],
			'one'         => [ '1', true ],
		];
	}

	/**
	 * @dataProvider public_row_values
	 *
	 * @param string|null $row      Stored value, null for no row.
	 * @param bool        $expected Whether details are visible.
	 */
	public function test_health_details_visible_is_true_only_for_the_exact_one( ?string $row, bool $expected ): void {
		$id = $this->exercise( 'Row test', $this->attrs( false ) );
		delete_post_meta( $id, self::PUBLIC_KEY );
		if ( null !== $row ) {
			update_post_meta( $id, self::PUBLIC_KEY, $row );
		}

		$this->assertSame( $expected, Meta_Fields::health_details_visible( $id ), 'A visitor.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $expected, Meta_Fields::health_details_visible( $id ), 'An administrator gets the same answer, so one render serves the page and every feed.' );
	}

	public function test_a_post_of_a_kind_with_no_health_entry_never_shows_health_details(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'note', 'kind' );
		update_post_meta( $id, self::PUBLIC_KEY, '1' );

		$this->assertFalse( Meta_Fields::health_details_visible( $id ) );
		$this->assertSame( [], Meta_Fields::get_visible_health_fields( $id ) );
		$this->assertSame( [], pkiw_get_visible_health_fields( $id ) );
	}

	public function test_visible_health_fields_keep_the_activity_public_and_the_opt_in_fields_private(): void {
		$hidden = $this->exercise( 'Hidden', $this->attrs( false ) );
		$opted  = $this->exercise( 'Opted in', $this->attrs( true ) );

		$expected_private = [
			'exercise_activity'      => true,
			'exercise_distance'      => false,
			'exercise_distance_unit' => false,
			'exercise_duration'      => false,
			'exercise_splits'        => false,
			'exercise_pb'            => false,
			'exercise_pb_category'   => false,
		];
		$this->assertSame( $expected_private, Meta_Fields::get_visible_health_fields( $hidden ) );
		$this->assertSame( $expected_private, pkiw_get_visible_health_fields( $hidden ) );
		$this->assertSame( array_fill_keys( array_keys( $expected_private ), true ), Meta_Fields::get_visible_health_fields( $opted ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $expected_private, Meta_Fields::get_visible_health_fields( $hidden ), 'No editor override.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function exercise_keys(): array {
		$keys = [];
		foreach ( Meta_Fields::EXERCISE_KEYS as $key ) {
			$keys[ $key ] = [ $key ];
		}

		return $keys;
	}

	/**
	 * @dataProvider exercise_keys
	 *
	 * @param string $key Field key without the prefix.
	 */
	public function test_no_exercise_key_is_registered( string $key ): void {
		$this->assertFalse( registered_meta_key_exists( 'post', Meta_Fields::PREFIX . $key ), "{$key} is not registered post meta." );
		$this->assertFalse( ( new Meta_Fields() )->is_valid_field( $key ), "{$key} is not a valid field, so the abilities skip it." );
	}

	public function test_no_registered_post_meta_names_an_exercise_key_or_a_biometric(): void {
		$keys = array_keys( get_registered_meta_keys( 'post', 'post' ) );
		$this->assertContains( '_pkiw_acquisition_price', $keys, 'The check sees this plugin\'s registered keys.' );

		foreach ( $keys as $key ) {
			$this->assertStringStartsNotWith( '_pkiw_exercise_', (string) $key );
			$this->assertStringNotContainsString( 'heart', (string) $key );
			$this->assertStringNotContainsString( 'calorie', (string) $key );
		}
	}

	/**
	 * Courtney's never-output tier: PKIW names no key for any of it.
	 */
	public function test_the_never_output_tier_has_no_key(): void {
		$every_key = array_merge( Meta_Fields::EXERCISE_KEYS, Meta_Fields::SLEEP_KEYS );
		foreach ( Meta_Fields::HEALTH_FIELDS as $entry ) {
			$every_key = array_merge( $every_key, $entry['public'], $entry['opt_in'] );
		}
		$every_key[] = Meta_Fields::EXERCISE_PUBLIC_KEY;
		$every_key[] = Meta_Fields::SLEEP_PUBLIC_KEY;

		foreach ( $every_key as $key ) {
			foreach ( [ 'heart', 'calorie', 'bpm', 'biometric', 'start', 'end', 'clock' ] as $never ) {
				$this->assertStringNotContainsString( $never, strtolower( $key ), "{$key} names {$never}, which never prints." );
			}
		}
	}

	/**
	 * Logged-out REST: no exercise key for any of the three fixtures, and a
	 * post with its details hidden answers like one that never stored them.
	 */
	public function test_rest_exposes_no_exercise_meta_and_hidden_details_match_a_card_that_never_stored_them(): void {
		$hidden = $this->exercise( 'Same title', $this->attrs( false ) );
		$never  = $this->exercise( 'Same title', [ 'activity' => self::ACTIVITY ] );
		$opted  = $this->exercise( 'Opted title', $this->attrs( true ) );

		$bodies = [];
		foreach ( [ 'hidden' => $hidden, 'never' => $never, 'opted' => $opted ] as $name => $id ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
			$this->assertSame( 200, $response->get_status(), $name );
			$data = $response->get_data();
			foreach ( array_keys( (array) ( $data['meta'] ?? [] ) ) as $key ) {
				$this->assertStringNotContainsStringIgnoringCase( 'exercise', (string) $key, "{$name}: meta key {$key}." );
			}
			$bodies[ $name ] = $data;
		}

		$normalize = static function ( array $data ): array {
			foreach ( [ 'id', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'link', 'guid', 'class_list', '_links' ] as $key ) {
				unset( $data[ $key ] );
			}

			return $data;
		};
		$this->assertSame( $normalize( $bodies['never'] ), $normalize( $bodies['hidden'] ), 'Hidden details match a card that never stored them.' );
	}

	public function test_a_password_protected_post_hands_a_visitor_no_exercise_meta_or_content(): void {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => $this->card( $this->attrs( true ) ),
			]
		);
		wp_set_object_terms( $id, 'exercise', 'kind' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
		$this->assertTrue( $data['content']['protected'] );
		$this->assertSame( '', $data['content']['rendered'] );
		foreach ( array_keys( (array) ( $data['meta'] ?? [] ) ) as $key ) {
			$this->assertStringNotContainsStringIgnoringCase( 'exercise', (string) $key );
		}

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] );
		$this->assertIsArray( $result );
		foreach ( array_keys( (array) $result['meta'] ) as $key ) {
			$this->assertStringNotContainsStringIgnoringCase( 'exercise', (string) $key, 'The get-post-meta ability.' );
		}
	}

	/**
	 * The block editor sends back the meta it loaded. No exercise key is
	 * registered, so a stale value in that request writes nothing, and the
	 * card decides at save_post.
	 */
	public function test_stale_editor_meta_leaves_no_row(): void {
		$id = $this->exercise( 'Editable', $this->attrs( true ) );
		$this->assertSame( '7.77', get_metadata_raw( 'post', $id, '_pkiw_exercise_distance', true ), 'The card wrote its distance.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $id );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body_params(
			[
				'content' => $this->card( [ 'activity' => self::ACTIVITY ] ),
				'meta'    => [ 'exercise_distance' => 5 ],
			]
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), 'REST ignores the unregistered key.' );
		$this->assertNull( get_metadata_raw( 'post', $id, '_pkiw_exercise_distance', true ), 'The card has no distance, so the row goes and the stale 5 is not written.' );

		$this->assertNull( get_metadata_raw( 'post', $id, '_pkiw_exercise_pb', true ), 'Dropping personalBest drops its row.' );
		$this->assertNull( get_metadata_raw( 'post', $id, self::PUBLIC_KEY, true ), 'Dropping the toggle drops its row.' );
	}

	public function test_meta_input_and_the_create_post_ability_write_no_exercise_row(): void {
		$id = wp_insert_post(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Meta input',
				'post_content' => $this->card( [ 'activity' => self::ACTIVITY ] ),
				'meta_input'   => [
					'_pkiw_exercise_distance' => 9,
					'_pkiw_exercise_pb'       => '1',
				],
			]
		);
		$this->assertNull( get_metadata_raw( 'post', $id, '_pkiw_exercise_distance', true ), 'The card decides at save_post, after meta_input.' );
		$this->assertNull( get_metadata_raw( 'post', $id, '_pkiw_exercise_pb', true ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$result = Core_Abilities::instance()->execute_create_post(
			[
				'kind'              => 'note',
				'title'             => 'Ability',
				'content'           => $this->card( [ 'activity' => self::ACTIVITY ] ),
				'exercise_distance' => 9,
				'exercise_pb'       => '1',
			]
		);
		$this->assertIsArray( $result );
		$created = (int) $result['post_id'];
		$this->assertNull( get_metadata_raw( 'post', $created, '_pkiw_exercise_distance', true ) );
		$this->assertNull( get_metadata_raw( 'post', $created, '_pkiw_exercise_pb', true ) );
	}

	/**
	 * What a search can't tell about a hidden fixture: each value and each
	 * key name that sits in the card's block comment.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function hidden_search_terms(): array {
		return [
			'distance value'      => [ self::DISTANCE ],
			'distance key'        => [ 'distance' ],
			'unit key'            => [ 'distanceUnit' ],
			'duration value'      => [ self::DURATION ],
			'duration key'        => [ 'duration' ],
			'split value'         => [ self::SPLIT ],
			'splits key'          => [ 'splits' ],
			'personal best key'   => [ 'personalBest' ],
			'pb category value'   => [ self::CATEGORY ],
			'pb category key'     => [ 'pbCategory' ],
			'toggle key'          => [ 'showDetailsPublicly' ],
		];
	}

	/**
	 * @dataProvider hidden_search_terms
	 *
	 * @param string $term Search term.
	 */
	public function test_a_logged_out_search_cannot_find_a_hidden_value_or_key( string $term ): void {
		$hidden = $this->exercise( 'Hidden fixture', $this->attrs( false ) );
		// The toggle's card attribute is on but its row is gone: still hidden.
		$stale = $this->exercise( 'Stale fixture', $this->attrs( true ) );
		delete_post_meta( $stale, self::PUBLIC_KEY );

		$this->assertSame( [], $this->found( $term, [ $hidden, $stale ] ), "Searching '{$term}'." );
	}

	/**
	 * The same terms find the post once it opts in, and an editor finds a
	 * hidden one: the search hides only what the page hides.
	 *
	 * @dataProvider hidden_search_terms
	 *
	 * @param string $term Search term.
	 */
	public function test_the_same_search_finds_an_opted_in_post_and_an_editor_finds_a_hidden_one( string $term ): void {
		$opted  = $this->exercise( 'Opted fixture', $this->attrs( true ) );
		// The card's toggle is on but its row is gone, so the post is hidden
		// and its comment still holds every key the search could match.
		$hidden = $this->exercise( 'Hidden fixture', $this->attrs( true ) );
		delete_post_meta( $hidden, self::PUBLIC_KEY );

		$this->assertSame( [ $opted ], $this->found( $term, [ $opted, $hidden ] ), "An opted-in post matches '{$term}', a hidden one doesn't." );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertContains( $hidden, $this->found( $term, [ $opted, $hidden ] ), "An editor's search finds the hidden post by '{$term}'." );
	}

	public function test_turning_the_toggle_on_lets_the_search_find_the_post(): void {
		$id = $this->exercise( 'Toggle fixture', $this->attrs( false ) );
		$this->assertSame( [], $this->found( self::DISTANCE, [ $id ] ) );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( $this->attrs( true ) ) ),
			]
		);
		$this->assertSame( '1', get_post_meta( $id, self::PUBLIC_KEY, true ) );
		$this->assertSame( [ $id ], $this->found( self::DISTANCE, [ $id ] ) );
	}

	public function test_an_excluded_hidden_value_does_not_drop_the_post_and_public_text_still_matches(): void {
		$id = $this->exercise( 'Exclusion fixture', $this->attrs( false ) );

		$this->assertSame( [ $id ], $this->found( self::ACTIVITY . ' -' . self::DISTANCE, [ $id ] ), 'The hidden value can\'t exclude the post.' );
		$this->assertSame( [ $id ], $this->found( self::ACTIVITY, [ $id ] ), 'The activity is public.' );
	}

	public function test_the_core_post_meta_binding_is_null_for_an_exercise_key_logged_out(): void {
		$id = $this->exercise( 'Binding fixture', $this->attrs( true ) );
		$this->assertNotNull( get_metadata_raw( 'post', $id, '_pkiw_exercise_distance', true ), 'The card stored its distance.' );

		$source = get_block_bindings_source( 'core/post-meta' );
		$this->assertNotNull( $source );
		$block = new stdClass();
		$block->context = [ 'postId' => $id ];
		$this->assertNull( $source->get_value( [ 'key' => '_pkiw_exercise_distance' ], $block, 'content' ) );
	}

	public function test_display_post_content_with_tags_stripped_holds_no_hidden_value(): void {
		$id = $this->exercise( 'Display fixture', $this->attrs( false ) );

		$text = wp_strip_all_tags( (string) get_post_field( 'post_content', $id, 'display' ) );
		$this->assertStringNotContainsString( self::DISTANCE, $text );
		$this->assertStringNotContainsString( self::DURATION, $text );
	}

	/**
	 * Switching the toggle queues ATmosphere's update for a shared post, so
	 * the record is rebuilt without the metrics. Needs ATmosphere loaded
	 * (PKIW_TESTS_ATMOSPHERE_FILE).
	 *
	 * @group atmosphere
	 */
	public function test_toggling_the_public_row_queues_one_atmosphere_update(): void {
		if ( ! defined( 'ATMOSPHERE_VERSION' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to run ATmosphere\'s scheduling.' );
		}

		update_option( 'atmosphere_identity', [ 'did' => 'did:plc:pkiwtest245' ] );
		update_option( 'atmosphere_did', 'did:plc:pkiwtest245' );
		update_option( 'atmosphere_connection', [ 'access_token' => 'test-token' ] );
		add_filter( 'atmosphere_should_auto_publish', '__return_true' );
		$this->assertTrue( \Atmosphere\is_connected(), 'ATmosphere is connected.' );

		$id = $this->exercise( 'Shared fixture', $this->attrs( true ) );
		update_post_meta( $id, \Atmosphere\Transformer\Post::META_TID, '3pkiwtest245' );
		wp_clear_scheduled_hook( 'atmosphere_update_post', [ $id ] );
		$this->assertFalse( wp_next_scheduled( 'atmosphere_update_post', [ $id ] ) );

		// The row alone changes, as it does when a write has no save: the
		// queue must come from the privacy key, not from ATmosphere's own
		// reaction to a content update.
		delete_post_meta( $id, self::PUBLIC_KEY );
		$this->assertNotFalse( wp_next_scheduled( 'atmosphere_update_post', [ $id ] ), 'Switching the toggle off queues one update.' );
		wp_clear_scheduled_hook( 'atmosphere_update_post', [ $id ] );
	}
}

<?php
/**
 * Sleep bed and wake times are private unless the post opts in (issue 247).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Meta_Fields;

/**
 * Courtney's answer A on the health data question: a sleep post's title,
 * date and duration are public. Exact bed and wake times print only when the
 * card's "Show times publicly" toggle is on. Heart rate, other biometrics and
 * start and end clock times beyond those two never print, because Post Kinds
 * stores none of them.
 *
 * The toggle is the card attribute `showTimesPublicly`, which Card_Meta_Sync
 * keeps in `_pkiw_sleep_times_public`. No sleep key is registered, so REST,
 * the abilities and core/post-meta bindings never see one. These tests need
 * no block registration: render.php and block.json arrive in later lanes,
 * and the render-dependent cases live in SleepCardRenderTest.
 *
 * @group integration
 */
final class SleepHealthPrivacyTest extends WP_UnitTestCase {

	private const PUBLIC_KEY = '_pkiw_sleep_times_public';

	private const BEDTIME  = '2026-08-19T23:10';
	private const WAKETIME = '2026-08-20T06:52';
	private const MINUTES  = 462;

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'sleep', 'note' ] as $slug ) {
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
	 * Card attributes with both times set.
	 *
	 * @param bool $public Whether the card's toggle is on.
	 * @return array<string, mixed>
	 */
	private function attrs( bool $public ): array {
		$attrs = [
			'durationMinutes' => self::MINUTES,
			'bedtime'         => self::BEDTIME,
			'wakeTime'        => self::WAKETIME,
		];
		if ( $public ) {
			$attrs['showTimesPublicly'] = true;
		}

		return $attrs;
	}

	/**
	 * Sleep card markup, a bare block comment as save.js's null return stores.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/sleep-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published sleep post.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $attrs Card attributes.
	 * @return int Post ID.
	 */
	private function sleep( string $title, array $attrs ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => 'Same excerpt',
				'post_content' => $this->card( $attrs ),
			]
		);
		wp_set_object_terms( $id, 'sleep', 'kind' );

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
			'no row' => [ null, false ],
			'zero'   => [ '0', false ],
			'yes'    => [ 'yes', false ],
			'empty'  => [ '', false ],
			'one'    => [ '1', true ],
		];
	}

	/**
	 * @dataProvider public_row_values
	 *
	 * @param string|null $row      Stored value, null for no row.
	 * @param bool        $expected Whether the times are visible.
	 */
	public function test_health_details_visible_is_true_only_for_the_exact_one( ?string $row, bool $expected ): void {
		$id = $this->sleep( 'Row test', $this->attrs( false ) );
		delete_post_meta( $id, self::PUBLIC_KEY );
		if ( null !== $row ) {
			update_post_meta( $id, self::PUBLIC_KEY, $row );
		}

		$this->assertSame( $expected, Meta_Fields::health_details_visible( $id ), 'A visitor.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $expected, Meta_Fields::health_details_visible( $id ), 'An administrator gets the same answer.' );
	}

	public function test_the_sleep_row_does_not_open_an_exercise_post_and_the_exercise_row_does_not_open_a_sleep_post(): void {
		$sleep = $this->sleep( 'Sleep', $this->attrs( false ) );
		update_post_meta( $sleep, Meta_Fields::EXERCISE_PUBLIC_KEY, '1' );
		$this->assertFalse( Meta_Fields::health_details_visible( $sleep ), 'Only the sleep row opens a sleep post.' );

		$exercise = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $exercise, 'exercise', 'kind' );
		update_post_meta( $exercise, Meta_Fields::SLEEP_PUBLIC_KEY, '1' );
		$this->assertFalse( Meta_Fields::health_details_visible( $exercise ), 'Only the exercise row opens an exercise post.' );
	}

	public function test_visible_health_fields_keep_the_duration_public_and_both_times_private(): void {
		$hidden = $this->sleep( 'Hidden', $this->attrs( false ) );
		$opted  = $this->sleep( 'Opted in', $this->attrs( true ) );

		$expected_private = [
			'sleep_duration' => true,
			'sleep_bedtime'  => false,
			'sleep_wake'     => false,
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
	public function sleep_keys(): array {
		$keys = [];
		foreach ( Meta_Fields::SLEEP_KEYS as $key ) {
			$keys[ $key ] = [ $key ];
		}

		return $keys;
	}

	/**
	 * @dataProvider sleep_keys
	 *
	 * @param string $key Field key without the prefix.
	 */
	public function test_no_sleep_key_is_registered( string $key ): void {
		$this->assertFalse( registered_meta_key_exists( 'post', Meta_Fields::PREFIX . $key ), "{$key} is not registered post meta." );
		$this->assertFalse( ( new Meta_Fields() )->is_valid_field( $key ), "{$key} is not a valid field, so the abilities skip it." );
	}

	public function test_no_registered_post_meta_names_a_sleep_key(): void {
		$keys = array_keys( get_registered_meta_keys( 'post', 'post' ) );
		$this->assertContains( '_pkiw_acquisition_price', $keys, 'The check sees this plugin\'s registered keys.' );

		foreach ( $keys as $key ) {
			$this->assertStringStartsNotWith( '_pkiw_sleep_', (string) $key );
		}
	}

	/**
	 * Logged-out REST: no sleep key, and a post with its times hidden answers
	 * like one that never stored them.
	 */
	public function test_rest_exposes_no_sleep_meta_and_hidden_times_match_a_card_that_never_stored_them(): void {
		$hidden = $this->sleep( 'Same title', $this->attrs( false ) );
		$never  = $this->sleep( 'Same title', [ 'durationMinutes' => self::MINUTES ] );
		$opted  = $this->sleep( 'Opted title', $this->attrs( true ) );

		$bodies = [];
		foreach ( [ 'hidden' => $hidden, 'never' => $never, 'opted' => $opted ] as $name => $id ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
			$this->assertSame( 200, $response->get_status(), $name );
			$data = $response->get_data();
			foreach ( array_keys( (array) ( $data['meta'] ?? [] ) ) as $key ) {
				$this->assertStringNotContainsStringIgnoringCase( 'sleep', (string) $key, "{$name}: meta key {$key}." );
			}
			$bodies[ $name ] = $data;
		}

		$normalize = static function ( array $data ): array {
			foreach ( [ 'id', 'date', 'date_gmt', 'modified', 'modified_gmt', 'slug', 'link', 'guid', 'class_list', '_links' ] as $key ) {
				unset( $data[ $key ] );
			}

			return $data;
		};
		$this->assertSame( $normalize( $bodies['never'] ), $normalize( $bodies['hidden'] ), 'Hidden times match a card that never stored them.' );
	}

	public function test_a_password_protected_sleep_post_hands_a_visitor_no_sleep_meta_or_content(): void {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => $this->card( $this->attrs( true ) ),
			]
		);
		wp_set_object_terms( $id, 'sleep', 'kind' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
		$this->assertTrue( $data['content']['protected'] );
		$this->assertSame( '', $data['content']['rendered'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$result = Core_Abilities::instance()->execute_get_post_meta( [ 'post_id' => $id ] );
		$this->assertIsArray( $result );
		foreach ( array_keys( (array) $result['meta'] ) as $key ) {
			$this->assertStringNotContainsStringIgnoringCase( 'sleep', (string) $key, 'The get-post-meta ability.' );
		}
	}

	public function test_meta_input_and_the_create_post_ability_write_no_sleep_row(): void {
		$id = wp_insert_post(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Meta input',
				'post_content' => $this->card( [ 'durationMinutes' => self::MINUTES ] ),
				'meta_input'   => [
					'_pkiw_sleep_bedtime'     => self::BEDTIME,
					'_pkiw_sleep_times_public' => '1',
				],
			]
		);
		$this->assertNull( get_metadata_raw( 'post', $id, '_pkiw_sleep_bedtime', true ), 'The card decides at save_post, after meta_input.' );
		$this->assertNull( get_metadata_raw( 'post', $id, self::PUBLIC_KEY, true ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$result = Core_Abilities::instance()->execute_create_post(
			[
				'kind'          => 'note',
				'title'         => 'Ability',
				'content'       => $this->card( [ 'durationMinutes' => self::MINUTES ] ),
				'sleep_bedtime' => self::BEDTIME,
			]
		);
		$this->assertIsArray( $result );
		$this->assertNull( get_metadata_raw( 'post', (int) $result['post_id'], '_pkiw_sleep_bedtime', true ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function hidden_search_terms(): array {
		return [
			'bedtime value'  => [ '23:10' ],
			'wake value'     => [ '06:52' ],
			'bedtime date'   => [ '2026-08-19T23' ],
			'bedtime key'    => [ 'bedtime' ],
			'wake key'       => [ 'wakeTime' ],
			'toggle key'     => [ 'showTimesPublicly' ],
		];
	}

	/**
	 * @dataProvider hidden_search_terms
	 *
	 * @param string $term Search term.
	 */
	public function test_a_logged_out_search_cannot_find_a_hidden_time_or_key( string $term ): void {
		$hidden = $this->sleep( 'Hidden fixture', $this->attrs( false ) );
		// The toggle's card attribute is on but its row is gone: still hidden.
		$stale = $this->sleep( 'Stale fixture', $this->attrs( true ) );
		delete_post_meta( $stale, self::PUBLIC_KEY );

		$this->assertSame( [], $this->found( $term, [ $hidden, $stale ] ), "Searching '{$term}'." );
	}

	/**
	 * @dataProvider hidden_search_terms
	 *
	 * @param string $term Search term.
	 */
	public function test_the_same_search_finds_an_opted_in_post_and_an_editor_finds_a_hidden_one( string $term ): void {
		$opted  = $this->sleep( 'Opted fixture', $this->attrs( true ) );
		// The card's toggle is on but its row is gone, so the post is hidden
		// and its comment still holds every key the search could match.
		$hidden = $this->sleep( 'Hidden fixture', $this->attrs( true ) );
		delete_post_meta( $hidden, self::PUBLIC_KEY );

		$this->assertSame( [ $opted ], $this->found( $term, [ $opted, $hidden ] ), "An opted-in post matches '{$term}', a hidden one doesn't." );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertContains( $hidden, $this->found( $term, [ $opted, $hidden ] ), "An editor's search finds the hidden post by '{$term}'." );
	}

	public function test_a_search_for_the_public_duration_and_the_notes_still_finds_a_hidden_post(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Night notes',
				'post_content' => $this->card( $this->attrs( false ) ) . "\n\n<!-- wp:paragraph --><p>Zq3 woke twice</p><!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $id, 'sleep', 'kind' );

		$this->assertSame( [ $id ], $this->found( (string) self::MINUTES, [ $id ] ), 'The duration is public.' );
		$this->assertSame( [ $id ], $this->found( 'Zq3', [ $id ] ), 'Body text prints as written.' );
	}

	public function test_turning_the_toggle_on_lets_the_search_find_the_post(): void {
		$id = $this->sleep( 'Toggle fixture', $this->attrs( false ) );
		$this->assertSame( [], $this->found( '23:10', [ $id ] ) );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( $this->attrs( true ) ) ),
			]
		);
		$this->assertSame( '1', get_post_meta( $id, self::PUBLIC_KEY, true ) );
		$this->assertSame( [ $id ], $this->found( '23:10', [ $id ] ) );
	}

	public function test_display_post_content_with_tags_stripped_holds_no_hidden_time(): void {
		$id = $this->sleep( 'Display fixture', $this->attrs( false ) );

		$text = wp_strip_all_tags( (string) get_post_field( 'post_content', $id, 'display' ) );
		$this->assertStringNotContainsString( '23:10', $text );
		$this->assertStringNotContainsString( '06:52', $text );
	}

	/**
	 * Switching the toggle queues ATmosphere's update for a shared post.
	 * Needs ATmosphere loaded (PKIW_TESTS_ATMOSPHERE_FILE).
	 *
	 * @group atmosphere
	 */
	public function test_toggling_the_public_row_queues_one_atmosphere_update(): void {
		if ( ! defined( 'ATMOSPHERE_VERSION' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to run ATmosphere\'s scheduling.' );
		}

		update_option( 'atmosphere_identity', [ 'did' => 'did:plc:pkiwtest247' ] );
		update_option( 'atmosphere_did', 'did:plc:pkiwtest247' );
		update_option( 'atmosphere_connection', [ 'access_token' => 'test-token' ] );
		add_filter( 'atmosphere_should_auto_publish', '__return_true' );
		$this->assertTrue( \Atmosphere\is_connected(), 'ATmosphere is connected.' );

		$id = $this->sleep( 'Shared fixture', $this->attrs( true ) );
		update_post_meta( $id, \Atmosphere\Transformer\Post::META_TID, '3pkiwtest247' );
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

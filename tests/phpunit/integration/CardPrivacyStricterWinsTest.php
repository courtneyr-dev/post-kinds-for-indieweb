<?php
/**
 * A card's privacy setting and the post's stored setting: stricter wins (#358).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Card_Meta_Sync;
use PKIW\Meta_Fields;

/**
 * Courtney's decision on #358. A check-in card's `locationPrivacy` and an
 * RSVP card's `locationVisibility` share one stored row each with a second
 * control: REST meta, the update-post-meta ability, wp_insert_post()
 * meta_input, WP-CLI or another plugin's update_post_meta(). The card sync
 * on save, the card meta backfill and every write or delete of the row keep
 * the stricter of the card's value and the stored row, so a card can't
 * loosen a stricter stored value and loosening takes both controls. A post
 * with no card keeps whatever was stored.
 *
 * Every value under test is a sentinel string, so a leak is unambiguous.
 *
 * @group integration
 */
final class CardPrivacyStricterWinsTest extends WP_UnitTestCase {

	private const VENUE     = 'Sentinel Venue Sw358';
	private const STREET    = '358 Sentinel Row Sw';
	private const LATITUDE  = 12.358358;
	private const LONGITUDE = -76.358358;
	private const EVENT     = 'Quillfeather Meetup Sw358';
	private const LOCATION  = 'Back room, 358 Quill Lane Sw';
	private const EVENT_URL = 'https://events.example/quillfeather-sw358';

	/**
	 * Stored row per setting.
	 */
	private const KEYS = [
		'checkin' => '_pkiw_geo_privacy',
		'rsvp'    => '_pkiw_rsvp_location_privacy',
	];

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Both card privacy settings that have a second control.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function settings(): array {
		return [
			'check-in card' => [ 'checkin' ],
			'RSVP card'     => [ 'rsvp' ],
		];
	}

	/**
	 * Card markup with sentinel location data.
	 *
	 * @param string      $setting `checkin` or `rsvp`.
	 * @param string|null $privacy The card's privacy attribute, or null to leave it out.
	 */
	private function card( string $setting, ?string $privacy ): string {
		if ( 'checkin' === $setting ) {
			$attrs = [
				'venueName' => self::VENUE,
				'address'   => self::STREET,
				'locality'  => 'Sentinelville Sw',
				'latitude'  => self::LATITUDE,
				'longitude' => self::LONGITUDE,
			];
			if ( null !== $privacy ) {
				$attrs['locationPrivacy'] = $privacy;
			}

			return '<!-- wp:post-kinds-indieweb/checkin-card ' . wp_json_encode( $attrs ) . ' /-->';
		}

		$attrs = [
			'eventName'     => self::EVENT,
			'eventUrl'      => self::EVENT_URL,
			'eventStart'    => gmdate( 'Y-m-d\TH:i', time() + 30 * DAY_IN_SECONDS ),
			'rsvpStatus'    => 'yes',
			'eventLocation' => self::LOCATION,
		];
		if ( null !== $privacy ) {
			$attrs['locationVisibility'] = $privacy;
		}

		return '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * A published post holding a card, with its kind and, for an RSVP, the
	 * event location row REST meta returns.
	 *
	 * @param string      $setting `checkin` or `rsvp`.
	 * @param string|null $privacy The card's privacy attribute, or null to leave it out.
	 * @return int Post ID.
	 */
	private function create( string $setting, ?string $privacy ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Sentinel ' . $setting,
				'post_content' => $this->card( $setting, $privacy ),
			]
		);
		wp_set_object_terms( $id, $setting, 'kind' );
		if ( 'rsvp' === $setting ) {
			update_post_meta( $id, '_pkiw_event_location', self::LOCATION );
		}

		return $id;
	}

	/**
	 * Write the stored setting with no save, as the update-post-meta ability does.
	 *
	 * @param int    $id      Post ID.
	 * @param string $setting `checkin` or `rsvp`.
	 * @param string $value   Value to store.
	 */
	private function store( int $id, string $setting, string $value ): void {
		update_post_meta( $id, self::KEYS[ $setting ], $value );
	}

	/**
	 * The stored row, read raw so a registered default can't stand in for it.
	 *
	 * @param int    $id      Post ID.
	 * @param string $setting `checkin` or `rsvp`.
	 * @return mixed
	 */
	private function stored( int $id, string $setting ) {
		return get_metadata_raw( 'post', $id, self::KEYS[ $setting ], true );
	}

	/**
	 * Update a post through the REST posts route as an editor.
	 *
	 * @param int                  $id   Post ID.
	 * @param array<string, mixed> $body Body params.
	 */
	private function rest_save( int $id, array $body ): void {
		$response = $this->rest_post( $id, $body );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Send an update to the REST posts route as an editor, whatever it returns.
	 *
	 * @param int                  $id   Post ID.
	 * @param array<string, mixed> $body Body params.
	 */
	private function rest_post( int $id, array $body ): WP_REST_Response {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$GLOBALS['wp_rest_server'] = null;

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $id );
		$request->set_body_params( $body );
		$response = rest_do_request( $request );

		wp_set_current_user( 0 );

		return $response;
	}

	/**
	 * The post as the REST posts route returns it to a visitor.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>
	 */
	private function visitor_rest( int $id ): array {
		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;

		return (array) rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
	}

	/**
	 * The post's blocks as a visitor's permalink renders them.
	 *
	 * @param int $id Post ID.
	 */
	private function render( int $id ): string {
		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $id ) );

		return do_blocks( (string) get_post_field( 'post_content', $id ) );
	}

	/**
	 * Sentinels a private location must keep from visitors.
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 * @return string[]
	 */
	private function sentinels( string $setting ): array {
		return 'checkin' === $setting
			? [ self::VENUE, self::STREET, (string) self::LATITUDE ]
			: [ self::LOCATION ];
	}

	/**
	 * Assert a visitor's REST meta and rendered content carry no location.
	 *
	 * @param int    $id      Post ID.
	 * @param string $setting `checkin` or `rsvp`.
	 */
	private function assert_hidden_from_visitors( int $id, string $setting ): void {
		$data = $this->visitor_rest( $id );
		$this->assertArrayHasKey( 'meta', $data );
		if ( 'checkin' === $setting ) {
			$this->assertSame( '', $data['meta']['_pkiw_checkin_name'] );
			$this->assertSame( '', $data['meta']['_pkiw_checkin_address'] );
		} else {
			$this->assertSame( '', $data['meta']['_pkiw_event_location'] );
		}

		$json = (string) wp_json_encode( $data );
		$html = $this->render( $id );
		foreach ( $this->sentinels( $setting ) as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $json, 'REST meta and content.rendered.' );
			$this->assertStringNotContainsString( $sentinel, $html, 'The rendered card.' );
		}
	}

	/**
	 * Assert a visitor sees the location, so assert_hidden_from_visitors()
	 * can't pass on a post that never shows one.
	 *
	 * @param int    $id      Post ID.
	 * @param string $setting `checkin` or `rsvp`.
	 */
	private function assert_shown_to_visitors( int $id, string $setting ): void {
		$data = $this->visitor_rest( $id );
		if ( 'checkin' === $setting ) {
			$this->assertSame( self::STREET, $data['meta']['_pkiw_checkin_address'] );
		} else {
			$this->assertSame( self::LOCATION, $data['meta']['_pkiw_event_location'] );
		}
		$this->assertStringContainsString( $this->sentinels( $setting )[0], $this->render( $id ) );
	}

	/**
	 * (a) A save that sends no meta keeps a stricter stored setting.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_wp_update_post_keeps_a_stricter_stored_setting( string $setting ): void {
		$id = $this->create( $setting, 'public' );
		$this->assertSame( 'public', $this->stored( $id, $setting ), 'The card synced public.' );
		$this->store( $id, $setting, 'private' );

		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Touched',
			]
		);

		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * (a) A REST save that sends no meta keeps a stricter stored setting.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_a_rest_save_without_meta_keeps_a_stricter_stored_setting( string $setting ): void {
		$id = $this->create( $setting, 'public' );
		$this->store( $id, $setting, 'private' );

		$this->rest_save( $id, [ 'title' => 'Touched' ] );

		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * (b) REST meta looser than the card stores the card's setting.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_rest_meta_looser_than_the_card_stores_the_card_setting( string $setting ): void {
		$id = $this->create( $setting, 'private' );

		$this->rest_save( $id, [ 'meta' => [ self::KEYS[ $setting ] => 'public' ] ] );

		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * (b) The block editor sends the meta it loaded with every save once any
	 * kind meta changed, so a card tightened in the editor arrives with the
	 * old public row in the same request.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_stale_rest_meta_cannot_undo_a_card_tightened_in_the_same_save( string $setting ): void {
		$id = $this->create( $setting, 'public' );
		$this->assertSame( 'public', $this->stored( $id, $setting ) );

		$this->rest_save(
			$id,
			[
				'content' => $this->card( $setting, 'private' ),
				'meta'    => [ self::KEYS[ $setting ] => 'public' ],
			]
		);

		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * (b) wp_insert_post() meta_input looser than the card stores the card's setting.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_meta_input_looser_than_the_card_stores_the_card_setting( string $setting ): void {
		$id = wp_insert_post(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Sentinel insert',
				'post_content' => $this->card( $setting, 'private' ),
				'meta_input'   => [ self::KEYS[ $setting ] => 'public' ],
			]
		);

		$this->assertIsInt( $id );
		$this->assertSame( 'private', $this->stored( $id, $setting ) );
	}

	/**
	 * (b) The update-post-meta ability can't loosen past the card either.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_the_update_post_meta_ability_cannot_loosen_past_the_card( string $setting ): void {
		$id = $this->create( $setting, 'private' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = Core_Abilities::instance()->execute_update_post_meta(
			[
				'post_id'    => $id,
				'meta_key'   => substr( self::KEYS[ $setting ], strlen( Meta_Fields::PREFIX ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'public', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * (b) The create-post ability writes its meta after the insert's hooks
	 * have run, and still can't loosen past the card.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_the_create_post_ability_cannot_loosen_past_the_card( string $setting ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = Core_Abilities::instance()->execute_create_post(
			[
				'kind'    => $setting,
				'title'   => 'Sentinel ability post',
				'content' => $this->card( $setting, 'private' ),
				'status'  => 'publish',
				substr( self::KEYS[ $setting ], strlen( Meta_Fields::PREFIX ) ) => 'public',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'private', $this->stored( $result['post_id'], $setting ) );
	}

	/**
	 * (c) Both controls public store public.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_both_controls_public_store_public( string $setting ): void {
		$id = $this->create( $setting, 'public' );

		$this->rest_save( $id, [ 'meta' => [ self::KEYS[ $setting ] => 'public' ] ] );

		$this->assertSame( 'public', $this->stored( $id, $setting ) );
		$this->assert_shown_to_visitors( $id, $setting );
	}

	/**
	 * (d) Loosening both controls in one REST save stores the looser setting.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_loosening_both_controls_in_one_rest_save_stores_public( string $setting ): void {
		$id = $this->create( $setting, 'private' );
		$this->assertSame( 'private', $this->stored( $id, $setting ) );

		$this->rest_save(
			$id,
			[
				'content' => $this->card( $setting, 'public' ),
				'meta'    => [ self::KEYS[ $setting ] => 'public' ],
			]
		);

		$this->assertSame( 'public', $this->stored( $id, $setting ) );
		$this->assert_shown_to_visitors( $id, $setting );
	}

	/**
	 * (d) Loosening both controls in one wp_update_post() call stores the looser setting.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_loosening_both_controls_with_meta_input_stores_public( string $setting ): void {
		$id = $this->create( $setting, 'private' );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( $setting, 'public' ) ),
				'meta_input'   => [ self::KEYS[ $setting ] => 'public' ],
			]
		);

		$this->assertSame( 'public', $this->stored( $id, $setting ) );
	}

	/**
	 * Loosening the card alone leaves a stricter stored setting in place,
	 * even one the card stored itself.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_loosening_only_the_card_keeps_the_stored_setting( string $setting ): void {
		$id = $this->create( $setting, 'private' );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( $setting, 'public' ) ),
			]
		);

		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * (e) The card meta backfill keeps a stricter stored setting.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_the_backfill_keeps_a_stricter_stored_setting( string $setting ): void {
		$id = $this->create( $setting, 'public' );
		$this->store( $id, $setting, 'private' );

		$result = Card_Meta_Sync::backfill_batch( 0, 1000 );

		$this->assertGreaterThanOrEqual( 1, $result['processed'] );
		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * (f) A post with no card keeps what was stored, through a save, a REST
	 * meta write and the backfill.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_a_post_without_a_card_keeps_what_was_stored( string $setting ): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>No card here.</p><!-- /wp:paragraph -->',
			]
		);
		$this->store( $id, $setting, 'private' );

		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Touched',
			]
		);
		$this->assertSame( 'private', $this->stored( $id, $setting ), 'A save leaves it.' );

		$this->rest_save( $id, [ 'meta' => [ self::KEYS[ $setting ] => 'public' ] ] );
		$this->assertSame( 'public', $this->stored( $id, $setting ), 'With no card, the request decides.' );

		Card_Meta_Sync::backfill_batch( 0, 1000 );
		$this->assertSame( 'public', $this->stored( $id, $setting ), 'The backfill leaves it.' );
	}

	/**
	 * A check-in card left at its block.json default, Approximate, saves
	 * with no `locationPrivacy` in the block comment. That default is the
	 * card's setting, so it tightens a public row.
	 */
	public function test_a_checkin_card_left_at_approximate_tightens_a_public_row(): void {
		$id = $this->create( 'checkin', 'public' );
		$this->assertSame( 'public', $this->stored( $id, 'checkin' ) );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( 'checkin', null ) ),
			]
		);

		$this->assertSame( 'approximate', $this->stored( $id, 'checkin' ) );
		$data = $this->visitor_rest( $id );
		$this->assertSame( '', $data['meta']['_pkiw_checkin_address'], 'Approximate hides the street.' );
		$this->assertSame( self::VENUE, $data['meta']['_pkiw_checkin_name'], 'Approximate keeps the venue name.' );
	}

	/**
	 * Approximate sits between public and private.
	 */
	public function test_a_public_checkin_card_keeps_a_stored_approximate_row(): void {
		$id = $this->create( 'checkin', 'public' );
		$this->store( $id, 'checkin', 'approximate' );

		wp_update_post(
			[
				'ID'         => $id,
				'post_title' => 'Touched',
			]
		);
		$this->assertSame( 'approximate', $this->stored( $id, 'checkin' ) );

		$this->rest_save( $id, [ 'meta' => [ '_pkiw_geo_privacy' => 'private' ] ] );
		$this->assertSame( 'private', $this->stored( $id, 'checkin' ), 'A stricter request still wins over a looser card.' );
	}

	/**
	 * A card with no stored row yet sets the row, so a new post's card still
	 * decides on its own.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_a_card_with_no_stored_row_sets_it( string $setting ): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Card to come.</p><!-- /wp:paragraph -->',
			]
		);
		$this->assertNull( $this->stored( $id, $setting ) );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( $setting, 'public' ) ),
			]
		);

		$this->assertSame( 'public', $this->stored( $id, $setting ) );
	}

	/**
	 * REST meta null deletes the row, and a missing check-in row reads as
	 * Approximate. A Private card puts its setting back.
	 */
	public function test_a_rest_meta_delete_under_a_private_checkin_card_stores_private(): void {
		$id = $this->create( 'checkin', 'private' );
		$this->assertSame( 'private', $this->stored( $id, 'checkin' ) );

		$this->rest_save( $id, [ 'meta' => [ '_pkiw_geo_privacy' => null ] ] );

		$this->assertSame( 'private', $this->stored( $id, 'checkin' ) );
		$this->assert_hidden_from_visitors( $id, 'checkin' );
	}

	/**
	 * A deleted row stays deleted when the missing row already reads as
	 * strict as the card: a check-in reads as Approximate, an RSVP as
	 * private. Under a looser card, writing the card's value back would let
	 * a delete loosen the location.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_a_rest_meta_delete_under_a_looser_card_leaves_the_row_missing( string $setting ): void {
		$id = $this->create( $setting, 'public' );
		$this->store( $id, $setting, 'private' );

		$this->rest_save( $id, [ 'meta' => [ self::KEYS[ $setting ] => null ] ] );

		$this->assertNull( $this->stored( $id, $setting ) );
		$data = $this->visitor_rest( $id );
		if ( 'checkin' === $setting ) {
			$this->assertSame( '', $data['meta']['_pkiw_checkin_address'], 'Approximate hides the street.' );
			$this->assertSame( self::VENUE, $data['meta']['_pkiw_checkin_name'], 'Approximate keeps the venue name.' );
		} else {
			$this->assert_hidden_from_visitors( $id, $setting );
		}
	}

	/**
	 * A REST request that writes the row and then fails on a later meta key
	 * returns before wp_after_insert_post. The write is held as it lands.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_a_rest_request_that_fails_after_writing_the_setting_keeps_the_card_setting( string $setting ): void {
		// Registered after Meta_Fields' keys, so REST handles it after the privacy row.
		register_post_meta(
			'post',
			'_pkiw_test_denied_358',
			[
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => '__return_false',
			]
		);
		$id = $this->create( $setting, 'private' );

		$response = $this->rest_post(
			$id,
			[
				'meta' => [
					self::KEYS[ $setting ]  => 'public',
					'_pkiw_test_denied_358' => 'x',
				],
			]
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * `wp post meta update` and another plugin's update_post_meta() write the
	 * row with no save. The write is held to the card at once.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_a_direct_meta_write_cannot_loosen_past_the_card( string $setting ): void {
		$id = $this->create( $setting, 'private' );

		update_post_meta( $id, self::KEYS[ $setting ], 'public' );

		$this->assertSame( 'private', $this->stored( $id, $setting ) );
		$this->assert_hidden_from_visitors( $id, $setting );
	}

	/**
	 * Loosening in two steps works card first: the card save keeps the
	 * stricter row, then a write to the row loosens it to the card.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_loosening_the_card_and_then_the_setting_stores_public( string $setting ): void {
		$id = $this->create( $setting, 'private' );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->card( $setting, 'public' ) ),
			]
		);
		$this->assertSame( 'private', $this->stored( $id, $setting ), 'The card alone keeps the row.' );

		update_post_meta( $id, self::KEYS[ $setting ], 'public' );

		$this->assertSame( 'public', $this->stored( $id, $setting ) );
		$this->assert_shown_to_visitors( $id, $setting );
	}

	/**
	 * Deleting the post deletes its rows. The card's setting isn't written
	 * back for a post that's going away.
	 *
	 * @dataProvider settings
	 *
	 * @param string $setting `checkin` or `rsvp`.
	 */
	public function test_deleting_the_post_leaves_no_setting_behind( string $setting ): void {
		global $wpdb;

		$id = $this->create( $setting, 'private' );

		wp_delete_post( $id, true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads past the meta cache on purpose.
		$rows = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $id ) );
		$this->assertSame( '0', $rows );
	}
}

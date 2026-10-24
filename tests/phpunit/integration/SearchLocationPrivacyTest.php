<?php
/**
 * Search can't confirm a hidden card location (issue 350).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * WordPress search matches post_content, and the RSVP, check-in, eat and
 * drink cards keep their location in the block comment. A visitor who
 * guesses a hidden street must get the same results as for a wrong guess.
 * Editors keep full search.
 *
 * @group integration
 */
final class SearchLocationPrivacyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'rsvp', 'checkin', 'eat', 'drink' ] as $slug ) {
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
	 * A card's stored block comment.
	 *
	 * @param string               $name  Block name without the namespace.
	 * @param array<string, mixed> $attrs Attributes.
	 */
	private function card( string $name, array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/' . $name . ' ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * A published post with a card, a kind and some meta.
	 *
	 * @param string               $content Post content.
	 * @param string               $kind    Kind slug.
	 * @param array<string, mixed> $meta    Meta to store after the save.
	 * @param string               $title   Post title.
	 * @return int Post ID.
	 */
	private function post( string $content, string $kind, array $meta = [], string $title = 'Location fixture' ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $id, $kind, 'kind' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * IDs the site's main query returns for a search.
	 *
	 * @param string $search Search string.
	 * @param string $flags  Extra query string.
	 * @return int[]
	 */
	private function main_query_ids( string $search, string $flags = '' ): array {
		$this->go_to( home_url( '/?s=' . rawurlencode( $search ) . $flags ) );

		return array_map( static fn( $post ): int => (int) $post->ID, $GLOBALS['wp_query']->posts );
	}

	/**
	 * IDs /wp/v2/search returns.
	 *
	 * @param string $search Search string.
	 * @return int[]
	 */
	private function rest_search_ids( string $search ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', $search );
		$response = rest_do_request( $request );

		return array_map( static fn( array $item ): int => (int) $item['id'], (array) $response->get_data() );
	}

	private function private_rsvp(): int {
		return $this->post(
			$this->card(
				'rsvp-card',
				[
					'eventName'     => 'Quill Meetup',
					'eventLocation' => '9 Quill Lane',
					'rsvpStatus'    => 'yes',
				]
			),
			'rsvp'
		);
	}

	public function test_a_logged_out_search_for_a_private_rsvp_street_finds_nothing(): void {
		$id = $this->private_rsvp();
		$this->assertFalse( Meta_Fields::rsvp_location_visible( $id ), 'The fixture hides its location.' );

		$this->assertNotContains( $id, $this->main_query_ids( '9 Quill Lane' ), 'A right guess is no hit.' );
		$this->assertNotContains( $id, $this->main_query_ids( '8 Quill Lane' ), 'A wrong guess is no hit.' );
		$this->assertNotContains( $id, $this->rest_search_ids( '9 Quill Lane' ), 'REST search follows.' );
	}

	public function test_an_exact_search_for_the_private_rsvp_card_finds_nothing(): void {
		$id      = $this->private_rsvp();
		$comment = (string) get_post_field( 'post_content', $id, 'raw' );

		$this->assertNotContains( $id, $this->main_query_ids( $comment, '&exact=1&sentence=1' ) );
	}

	public function test_a_private_rsvp_is_still_found_by_its_visible_event_name(): void {
		$id = $this->private_rsvp();

		$this->assertContains( $id, $this->main_query_ids( 'Quill Meetup' ) );
	}

	public function test_an_editor_still_finds_a_private_rsvp_by_its_street(): void {
		$id = $this->private_rsvp();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertContains( $id, $this->main_query_ids( '9 Quill Lane' ) );
	}

	public function test_a_public_rsvp_is_found_by_its_street(): void {
		$id = $this->post(
			$this->card(
				'rsvp-card',
				[
					'eventName'          => 'Quill Meetup',
					'eventLocation'      => '9 Quill Lane',
					'locationVisibility' => 'public',
				]
			),
			'rsvp',
			[ '_pkiw_rsvp_location_privacy' => 'public' ]
		);
		$this->assertTrue( Meta_Fields::rsvp_location_visible( $id ) );

		$this->assertContains( $id, $this->main_query_ids( '9 Quill Lane' ) );
	}

	/**
	 * A check-in card with a full location.
	 *
	 * @param string $privacy The card's locationPrivacy, which the save syncs to the stored row.
	 */
	private function checkin_card( string $privacy = 'private' ): string {
		return $this->card(
			'checkin-card',
			[
				'venueName'       => 'Zephyr Tea Room',
				'address'         => '41 Ostrander Way',
				'locality'        => 'Brindleford',
				'latitude'        => 41.123456,
				'longitude'       => -73.654321,
				'locationPrivacy' => $privacy,
			]
		);
	}

	public function test_a_logged_out_search_for_a_private_check_in_venue_or_street_finds_nothing(): void {
		$id = $this->post( $this->checkin_card(), 'checkin', [ '_pkiw_geo_privacy' => 'private' ] );

		$this->assertNotContains( $id, $this->main_query_ids( '41 Ostrander Way' ), 'Street.' );
		$this->assertNotContains( $id, $this->main_query_ids( 'Zephyr Tea Room' ), 'Venue name.' );
		$this->assertNotContains( $id, $this->main_query_ids( 'Brindleford' ), 'Locality.' );
		$this->assertNotContains( $id, $this->main_query_ids( '41.123456' ), 'Latitude.' );
		$this->assertContains( $id, $this->main_query_ids( 'Location fixture' ), 'The public title still matches.' );
	}

	public function test_an_approximate_check_in_is_found_by_its_locality_but_not_its_street(): void {
		$id = $this->post( $this->checkin_card( 'approximate' ), 'checkin', [ '_pkiw_geo_privacy' => 'approximate' ] );

		$this->assertContains( $id, $this->main_query_ids( 'Brindleford' ), 'Locality prints on an approximate check-in.' );
		$this->assertNotContains( $id, $this->main_query_ids( '41 Ostrander Way' ), 'Street does not.' );
		$this->assertNotContains( $id, $this->main_query_ids( '73.654321' ), 'Nor do coordinates.' );
	}

	public function test_a_public_check_in_is_found_by_its_street(): void {
		$id = $this->post( $this->checkin_card( 'public' ), 'checkin', [ '_pkiw_geo_privacy' => 'public' ] );
		$this->assertTrue( Meta_Fields::get_public_location_fields( $id )['street'], 'The fixture shows its street.' );

		$this->assertContains( $id, $this->main_query_ids( '41 Ostrander Way' ) );
	}

	public function test_an_editor_finds_a_private_check_in_by_its_street(): void {
		$id = $this->post( $this->checkin_card(), 'checkin', [ '_pkiw_geo_privacy' => 'private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertContains( $id, $this->main_query_ids( '41 Ostrander Way' ) );
	}

	public function test_an_approximate_eat_card_street_finds_nothing_logged_out(): void {
		$id = $this->post(
			$this->card(
				'eat-card',
				[
					'name'             => 'Ramen',
					'restaurant'       => 'Noodle Hut',
					'locationAddress'  => '12 Vellum Court',
					'locationLocality' => 'Brindleford',
				]
			),
			'eat',
			[ '_pkiw_geo_privacy' => 'approximate' ]
		);

		$this->assertNotContains( $id, $this->main_query_ids( '12 Vellum Court' ) );
		$this->assertContains( $id, $this->main_query_ids( 'Noodle Hut' ), 'The restaurant name prints on an approximate post.' );
	}

	public function test_a_private_drink_card_venue_finds_nothing_logged_out(): void {
		$id = $this->post(
			$this->card(
				'drink-card',
				[
					'name'            => 'Flat white',
					'locationName'    => 'Gristmill Cafe',
					'locationAddress' => '3 Saddler Row',
				]
			),
			'drink',
			[ '_pkiw_geo_privacy' => 'private' ]
		);

		$this->assertNotContains( $id, $this->main_query_ids( 'Gristmill Cafe' ) );
		$this->assertNotContains( $id, $this->main_query_ids( '3 Saddler Row' ) );
		$this->assertContains( $id, $this->main_query_ids( 'Flat white' ), 'The drink name is not location.' );
	}

	public function test_a_hidden_location_title_is_not_searchable_logged_out(): void {
		$id = $this->post(
			$this->checkin_card(),
			'checkin',
			[
				'_pkiw_geo_privacy'  => 'private',
				'_pkiw_title_source' => 'location',
			],
			'Checked in at Zephyr Tea Room'
		);
		$this->assertNotSame( 'Checked in at Zephyr Tea Room', \PKIW\Title_Privacy::safe_title( $id ), 'The fixture hides its title.' );

		$this->assertNotContains( $id, $this->main_query_ids( 'Zephyr' ) );
	}

	public function test_an_excluded_street_term_does_not_drop_a_private_check_in(): void {
		$id = $this->post( $this->checkin_card(), 'checkin', [ '_pkiw_geo_privacy' => 'private' ] );

		$this->assertContains( $id, $this->main_query_ids( 'fixture -Ostrander' ), 'Excluding the hidden street keeps the post, as for a post without it.' );
		$this->assertContains( $id, $this->main_query_ids( 'fixture -Quimby' ), 'Excluding a wrong street gives the same result.' );
	}

	public function test_an_editor_finds_a_hidden_location_title(): void {
		$id = $this->post(
			$this->checkin_card(),
			'checkin',
			[
				'_pkiw_geo_privacy'  => 'private',
				'_pkiw_title_source' => 'location',
			],
			'Checked in at Zephyr Tea Room'
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertContains( $id, $this->main_query_ids( 'Zephyr' ) );
	}

	public function test_a_visible_location_title_is_still_searchable_logged_out(): void {
		$id = $this->post(
			$this->checkin_card( 'public' ),
			'checkin',
			[
				'_pkiw_geo_privacy'  => 'public',
				'_pkiw_title_source' => 'location',
			],
			'Checked in at Zephyr Tea Room'
		);

		$this->assertContains( $id, $this->main_query_ids( 'Zephyr' ) );
	}
}

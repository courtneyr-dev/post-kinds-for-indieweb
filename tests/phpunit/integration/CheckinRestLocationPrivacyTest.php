<?php
/**
 * Location-privacy coverage for the check-in dashboard REST routes.
 *
 * GET /checkins and GET /checkins/stats only require edit_posts, so any
 * Contributor reaches them. Each check-in's venue name, street address,
 * locality/region/country and coordinates must follow
 * Meta_Fields::get_visible_location_fields(), whose per-post edit_post
 * check separates "sees everything" from "sees the privacy tier".
 *
 * Every value under test is a sentinel string that does not resemble a
 * real place, so a leak is unambiguous in the assertion output.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\Meta_Fields;
use PKIW\REST_API;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class CheckinRestLocationPrivacyTest extends WP_UnitTestCase {

	private const VENUE_NAME = 'Sentinel Venue Zyx9';
	private const STREET     = '742 Sentinel Ave Qwrt';
	private const LOCALITY   = 'Sentinelville';
	private const REGION     = 'Sentinel Region Jhg';
	private const COUNTRY    = 'Sentinia';
	private const LATITUDE   = '12.345678';
	private const LONGITUDE  = '-76.543219';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
	}

	/**
	 * Create a published check-in with sentinel location meta, authored by
	 * nobody the test later logs in as.
	 *
	 * @param string $privacy  geo_privacy value.
	 * @param string $name     Venue name.
	 * @param string $locality Locality.
	 * @return int Post ID.
	 */
	private function create_checkin( string $privacy, string $name = self::VENUE_NAME, string $locality = self::LOCALITY ): int {
		wp_set_current_user( 0 );
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Sentinel check-in',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'checkin', 'kind' ) );

		$meta = [
			'checkin_name'     => $name,
			'checkin_address'  => self::STREET,
			'checkin_locality' => $locality,
			'checkin_region'   => self::REGION,
			'checkin_country'  => self::COUNTRY,
			'geo_latitude'     => self::LATITUDE,
			'geo_longitude'    => self::LONGITUDE,
			'geo_privacy'      => $privacy,
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}

		return $post_id;
	}

	private function become( string $role ): void {
		wp_set_current_user( 'anonymous' === $role ? 0 : self::factory()->user->create( [ 'role' => $role ] ) );
	}

	/**
	 * Dispatch a GET against one of the plugin's REST routes.
	 *
	 * @param string               $route  Route under the plugin namespace.
	 * @param array<string, mixed> $params Query parameters.
	 */
	private function get( string $route, array $params = [] ): WP_REST_Response {
		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		$server = rest_get_server();
		remove_action( 'rest_api_init', [ $rest, 'register_routes' ] );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE . $route );
		$request->set_query_params( $params );

		return $server->dispatch( $request );
	}

	public function test_anonymous_visitors_cannot_list_checkins(): void {
		$this->create_checkin( 'public' );
		$this->become( 'anonymous' );

		$this->assertSame( 401, $this->get( '/checkins' )->get_status() );
		$this->assertSame( 401, $this->get( '/checkins/stats' )->get_status() );
	}

	/**
	 * Privacy × role, with whether name+place / street / coordinates show.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: bool}>
	 */
	public function visibility_matrix(): array {
		return [
			// A check-in is a venue post (Meta_Fields::has_venue()); an
			// unset/'approximate' _pkiw_geo_privacy is a default, not an
			// author choice, and is ignored for venue posts, so it shows
			// the same as 'public'.
			'public, contributor'      => [ 'public', 'contributor', true, true, true ],
			'approximate, contributor' => [ 'approximate', 'contributor', true, true, true ],
			'private, contributor'     => [ 'private', 'contributor', false, false, false ],
			'public, editor'           => [ 'public', 'editor', true, true, true ],
			'approximate, editor'      => [ 'approximate', 'editor', true, true, true ],
			'private, editor'          => [ 'private', 'editor', true, true, true ],
		];
	}

	/**
	 * @dataProvider visibility_matrix
	 */
	public function test_checkins_route_follows_location_privacy_tiers( string $privacy, string $role, bool $place, bool $street, bool $coordinates ): void {
		$post_id = $this->create_checkin( $privacy );
		$this->become( $role );

		$response = $this->get( '/checkins' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['checkins'] );
		$checkin = $data['checkins'][0];
		$this->assertSame( $post_id, $checkin['id'] );

		$this->assertSame( $place ? self::VENUE_NAME : '', $checkin['venue_name'] );
		$this->assertSame( $place ? self::LOCALITY : '', $checkin['locality'] );
		$this->assertSame( $place ? self::REGION : '', $checkin['region'] );
		$this->assertSame( $place ? self::COUNTRY : '', $checkin['country'] );
		$this->assertSame( $street ? self::STREET : '', $checkin['address'] );
		$this->assertArrayHasKey( 'latitude', $checkin );
		$this->assertArrayHasKey( 'longitude', $checkin );
		$this->assertSame( $coordinates ? (float) self::LATITUDE : null, $checkin['latitude'] );
		$this->assertSame( $coordinates ? (float) self::LONGITUDE : null, $checkin['longitude'] );

		// Nothing hidden may ride along under another key.
		$json   = (string) wp_json_encode( $data );
		$hidden = array_merge(
			$place ? [] : [ self::VENUE_NAME, self::LOCALITY, self::REGION, self::COUNTRY ],
			$street ? [] : [ self::STREET ],
			$coordinates ? [] : [ self::LATITUDE ]
		);
		foreach ( $hidden as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}

		$this->assertSame( self::VENUE_NAME, get_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', true ), 'stored data must stay intact' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public function search_matrix(): array {
		return [
			'private, contributor'     => [ 'private', 'contributor', 0 ],
			'approximate, contributor' => [ 'approximate', 'contributor', 1 ],
			'private, editor'          => [ 'private', 'editor', 1 ],
		];
	}

	/**
	 * Search matches venue names, so a hit on a name the viewer can't see
	 * would confirm the hidden name even with the field blanked.
	 *
	 * @dataProvider search_matrix
	 */
	public function test_search_never_matches_a_hidden_venue_name( string $privacy, string $role, int $expected ): void {
		$this->create_checkin( $privacy );
		$this->become( $role );

		$data = $this->get( '/checkins', [ 'search' => 'Zyx9' ] )->get_data();

		$this->assertCount( $expected, $data['checkins'] );
		$this->assertSame( $expected, $data['total'] );
		$this->assertSame( $expected, $data['total_pages'] );
	}

	public function test_stats_only_count_visible_names_and_places(): void {
		$this->create_checkin( 'approximate', 'Sentinel Approx Venue', 'Sentinel Approx Town' );
		$this->create_checkin( 'private', 'Sentinel Private Venue Kqp4', 'Sentinel Private Town' );
		$this->become( 'contributor' );

		$data = $this->get( '/checkins/stats' )->get_data();

		$this->assertSame( 2, $data['total_checkins'] );
		$this->assertSame( 1, $data['unique_venues'] );
		$this->assertSame( 1, $data['cities'] );
		$this->assertSame( 1, $data['countries'] );
		$this->assertSame( [ 'Sentinel Approx Town' ], $data['cities_list'] );
		$this->assertSame( [ 'Sentinel Approx Venue' ], wp_list_pluck( $data['most_visited'], 'name' ) );

		$json = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( 'Sentinel Private Venue Kqp4', $json );
		$this->assertStringNotContainsString( 'Sentinel Private Town', $json );
	}

	public function test_editor_stats_count_every_checkin(): void {
		$this->create_checkin( 'approximate', 'Sentinel Approx Venue', 'Sentinel Approx Town' );
		$this->create_checkin( 'private', 'Sentinel Private Venue Kqp4', 'Sentinel Private Town' );
		$this->become( 'editor' );

		$data = $this->get( '/checkins/stats' )->get_data();

		$this->assertSame( 2, $data['unique_venues'] );
		$this->assertSame( 2, $data['cities'] );
	}
}

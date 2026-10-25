<?php
/**
 * Venue term meta must not expose a private check-in's location.
 *
 * REST: anyone who can't manage venue terms gets the venue without its
 * street, postal code, coordinates, phone, website or external ids, on both
 * the single-term and collection routes. The edit context and managers keep
 * everything.
 *
 * The venue-detail block: each field prints only at the loosest tier any of
 * the venue's published, non-password-protected check-ins allows, as
 * Meta_Fields::get_public_location_fields() reports it. No published
 * check-in means nothing location-like.
 *
 * Every value is a sentinel that doesn't resemble a real place, so a leak is
 * unambiguous in the assertion output.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Venue_Taxonomy;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class VenueLocationPrivacyTest extends WP_UnitTestCase {

	private const NAME     = 'Sentinel Venue Qzx';
	private const STREET   = '742 Sentinel Ave Qwrt';
	private const CITY     = 'Sentinelville';
	private const REGION   = 'Sentinel Region Jhg';
	private const COUNTRY  = 'Sentinia';
	private const POSTAL   = 'SN-90210-Q';
	private const LAT      = 12.345678;
	private const LNG      = -76.543219;
	private const URL      = 'https://sentinel-venue-qzx.example';
	private const PHONE    = '+1 555 0100 777';
	private const FSQ      = 'fsq-sentinel-4c1d';
	private const OSM      = 'node/987654321';
	private const STRIPPED = [ 'address', 'postal_code', 'latitude', 'longitude', 'phone', 'url', 'foursquare_id', 'osm_id' ];
	private const KEPT     = [ 'city', 'region', 'country' ];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		// The test framework wipes registered meta between tests.
		( new Venue_Taxonomy() )->register_term_meta();
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	private function venue(): int {
		$term = wp_insert_term( self::NAME, Venue_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );
		$id   = (int) $term['term_id'];
		$meta = [
			'address'       => self::STREET,
			'city'          => self::CITY,
			'region'        => self::REGION,
			'country'       => self::COUNTRY,
			'postal_code'   => self::POSTAL,
			'latitude'      => self::LAT,
			'longitude'     => self::LNG,
			'url'           => self::URL,
			'phone'         => self::PHONE,
			'foursquare_id' => self::FSQ,
			'osm_id'        => self::OSM,
		];
		foreach ( $meta as $key => $value ) {
			update_term_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * A check-in at the venue.
	 *
	 * @param int    $venue   Venue term ID.
	 * @param string $privacy `_pkiw_geo_privacy`.
	 * @param array  $post    Extra post fields.
	 */
	private function checkin( int $venue, string $privacy, array $post = [] ): int {
		$id = self::factory()->post->create(
			$post + [
				'post_status' => 'publish',
				'post_title'  => 'Sentinel check-in',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $id, 'checkin', Taxonomy::TAXONOMY ) );
		$this->assertNotWPError( wp_set_object_terms( $id, [ $venue ], Venue_Taxonomy::TAXONOMY ) );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );

		return $id;
	}

	private function become( string $role ): void {
		wp_set_current_user( 'anonymous' === $role ? 0 : self::factory()->user->create( [ 'role' => $role ] ) );
	}

	/**
	 * Dispatch a GET on the venue routes.
	 *
	 * @param string               $route  Route under /wp/v2.
	 * @param array<string, mixed> $params Query parameters.
	 * @return array{0:int,1:mixed}
	 */
	private function rest( string $route, array $params = [] ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . $route );
		$request->set_query_params( $params );
		$response = rest_get_server()->dispatch( $request );

		return [ $response->get_status(), $response->get_data() ];
	}

	/**
	 * The meta of one venue in the collection response.
	 *
	 * @param array $items Collection data.
	 * @param int   $id    Venue term ID.
	 * @return array<string, mixed>
	 */
	private function meta_in_list( array $items, int $id ): array {
		foreach ( $items as $item ) {
			if ( (int) $item['id'] === $id ) {
				return (array) ( $item['meta'] ?? [] );
			}
		}
		$this->fail( 'Venue missing from the collection.' );
	}

	private function block( int $venue ): string {
		return render_block(
			[
				'blockName'    => 'post-kinds-indieweb/venue-detail',
				'attrs'        => [
					'venueId' => $venue,
					'showMap' => true,
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	private function assertNoValues( array $meta, array $keys, string $who ): void {
		foreach ( $keys as $key ) {
			$this->assertTrue( ! isset( $meta[ $key ] ) || '' === $meta[ $key ] || null === $meta[ $key ], "$who sees venue meta '$key'." );
		}
	}

	/**
	 * Roles without manage_terms on the venue taxonomy.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function non_managers(): array {
		return [
			'anonymous'  => [ 'anonymous' ],
			'subscriber' => [ 'subscriber' ],
			'author'     => [ 'author' ],
		];
	}

	/**
	 * @dataProvider non_managers
	 *
	 * @param string $role Role.
	 */
	public function test_rest_single_venue_hides_location_from_non_managers( string $role ): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'private' );
		$this->become( $role );

		[ $status, $data ] = $this->rest( Venue_Taxonomy::TAXONOMY . '/' . $venue );

		$this->assertSame( 200, $status );
		$this->assertSame( self::NAME, $data['name'] );
		$this->assertNoValues( (array) $data['meta'], self::STRIPPED, $role );
		$json = (string) wp_json_encode( $data );
		foreach ( [ self::STREET, self::POSTAL, (string) self::LAT, self::PHONE, self::FSQ, self::OSM, 'sentinel-venue-qzx.example' ] as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $json, "$role sees $sentinel." );
		}
		foreach ( self::KEPT as $key ) {
			$this->assertArrayHasKey( $key, $data['meta'] );
		}
	}

	/**
	 * @dataProvider non_managers
	 *
	 * @param string $role Role.
	 */
	public function test_rest_venue_list_hides_location_from_non_managers( string $role ): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'private' );
		$this->become( $role );

		[ $status, $data ] = $this->rest( Venue_Taxonomy::TAXONOMY, [ 'hide_empty' => false ] );

		$this->assertSame( 200, $status );
		$this->assertNoValues( $this->meta_in_list( (array) $data, $venue ), self::STRIPPED, $role );
		$this->assertStringNotContainsString( self::STREET, (string) wp_json_encode( $data ) );
	}

	public function test_rest_editor_with_manage_terms_keeps_every_field(): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'private' );
		$this->become( 'editor' );
		$this->assertTrue( current_user_can( get_taxonomy( Venue_Taxonomy::TAXONOMY )->cap->manage_terms ) );

		[ , $single ] = $this->rest( Venue_Taxonomy::TAXONOMY . '/' . $venue );
		[ , $list ]   = $this->rest( Venue_Taxonomy::TAXONOMY, [ 'hide_empty' => false ] );

		foreach ( [ $single['meta'], $this->meta_in_list( (array) $list, $venue ) ] as $meta ) {
			$this->assertSame( self::STREET, $meta['address'] );
			$this->assertSame( self::POSTAL, $meta['postal_code'] );
			$this->assertEqualsWithDelta( self::LAT, (float) $meta['latitude'], 0.000001 );
			$this->assertEqualsWithDelta( self::LNG, (float) $meta['longitude'], 0.000001 );
			$this->assertSame( self::PHONE, $meta['phone'] );
			$this->assertSame( self::URL, $meta['url'] );
			$this->assertSame( self::FSQ, $meta['foursquare_id'] );
			$this->assertSame( self::OSM, $meta['osm_id'] );
		}
	}

	public function test_rest_edit_context_keeps_every_field(): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'private' );
		$this->become( 'administrator' );

		[ $status, $single ] = $this->rest( Venue_Taxonomy::TAXONOMY . '/' . $venue, [ 'context' => 'edit' ] );
		[ , $list ]          = $this->rest(
			Venue_Taxonomy::TAXONOMY,
			[
				'context'    => 'edit',
				'hide_empty' => false,
			]
		);

		$this->assertSame( 200, $status );
		$this->assertSame( self::STREET, $single['meta']['address'] );
		$this->assertSame( self::FSQ, $this->meta_in_list( (array) $list, $venue )['foursquare_id'] );
	}

	public function test_rest_edit_context_is_refused_to_a_subscriber(): void {
		$venue = $this->venue();
		$this->become( 'subscriber' );

		[ $status ] = $this->rest( Venue_Taxonomy::TAXONOMY . '/' . $venue, [ 'context' => 'edit' ] );

		$this->assertSame( 403, $status );
	}

	public function test_block_prints_no_street_or_coordinates_when_the_only_checkin_is_private(): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'private' );

		$html = $this->block( $venue );

		$this->assertStringContainsString( self::NAME, $html );
		foreach ( [ self::STREET, self::CITY, self::REGION, self::COUNTRY, (string) self::LAT, (string) self::LNG, self::PHONE, 'sentinel-venue-qzx.example', 'data-markers', 'h-geo' ] as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $html );
		}
	}

	public function test_block_shows_only_the_approximate_fields_for_an_approximate_checkin(): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'approximate' );

		$html = $this->block( $venue );

		foreach ( [ self::CITY, self::REGION, self::COUNTRY ] as $shown ) {
			$this->assertStringContainsString( $shown, $html );
		}
		foreach ( [ self::STREET, (string) self::LAT, (string) self::LNG, self::PHONE, 'sentinel-venue-qzx.example', 'data-markers', 'h-geo' ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html );
		}
	}

	public function test_block_takes_the_loosest_tier_across_checkins(): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'private' );
		$this->checkin( $venue, 'public' );

		$html = $this->block( $venue );

		foreach ( [ self::STREET, self::CITY, (string) self::LAT, (string) self::LNG, self::PHONE, 'sentinel-venue-qzx.example', 'data-markers', 'h-geo' ] as $shown ) {
			$this->assertStringContainsString( $shown, $html );
		}
	}

	/**
	 * Public check-ins that a visitor can't read don't loosen the venue.
	 *
	 * @return array<string, array{0:array<string,string>}>
	 */
	public function unreadable_public_checkins(): array {
		return [
			'draft'              => [ [ 'post_status' => 'draft' ] ],
			'private status'     => [ [ 'post_status' => 'private' ] ],
			'password protected' => [ [ 'post_password' => 'sentinel-pass' ] ],
		];
	}

	/**
	 * @dataProvider unreadable_public_checkins
	 *
	 * @param array<string,string> $post Post fields.
	 */
	public function test_block_ignores_public_checkins_a_visitor_cannot_read( array $post ): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'approximate' );
		$this->checkin( $venue, 'public', $post );

		$html = $this->block( $venue );

		$this->assertStringContainsString( self::CITY, $html );
		foreach ( [ self::STREET, (string) self::LAT, self::PHONE, 'data-markers', 'h-geo' ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html );
		}
	}

	public function test_block_shows_nothing_location_like_without_checkins(): void {
		$venue = $this->venue();

		$html = $this->block( $venue );

		$this->assertStringContainsString( self::NAME, $html );
		foreach ( [ self::STREET, self::CITY, self::REGION, self::COUNTRY, (string) self::LAT, self::PHONE, 'sentinel-venue-qzx.example', 'data-markers', 'h-geo', 'p-adr' ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html );
		}
	}

	public function test_block_gives_an_editor_the_visitor_view(): void {
		$venue = $this->venue();
		$this->checkin( $venue, 'private' );
		$visitor = $this->block( $venue );

		$this->become( 'administrator' );
		$editor = $this->block( $venue );

		$this->assertSame( preg_replace( '/venue-map-\d+/', 'venue-map-n', $visitor ), preg_replace( '/venue-map-\d+/', 'venue-map-n', $editor ) );
	}
}

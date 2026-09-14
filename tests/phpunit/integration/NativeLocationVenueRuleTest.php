<?php
/**
 * Location-privacy coverage for native Simple Location / IndieBlocks keys,
 * combined with Meta_Fields::has_venue() (audit follow-up, 2026-09-14).
 *
 * Meta_Fields::redact_location_meta() previously gated Post Kinds' own
 * `_pkiw_*` location keys by get_visible_location_fields()'s tiers, but
 * gated Simple Location's/IndieBlocks' native `geo_*` keys only by Simple
 * Location's own `geo_public`. A check-in with `_pkiw_geo_privacy`
 * unset/'approximate' (the default) and `geo_public` '1' therefore returned
 * exact coordinates and a street address to an anonymous
 * `GET /wp/v2/posts/<id>`.
 *
 * The confirmed rule: venue posts (a check-in by kind, or any post with
 * venue-identity data from Post Kinds' own fields, the venue taxonomy, or
 * Simple Location) show full location unless explicitly marked private
 * (`_pkiw_geo_privacy` 'private' or `geo_public` '0'), or `geo_public` '2'
 * (Protected) asks for text only. An unset/'approximate' `_pkiw_geo_privacy`
 * is a default and is ignored for venue posts. Non-venue posts (geotagged
 * notes, photos, articles with no venue) keep the original combined rule:
 * the Post Kinds tier and Simple Location's geo_public, the stricter of the
 * two wins per field.
 *
 * Every value under test is a sentinel string that does not resemble a real
 * place, so a leak is unambiguous in the assertion output.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class NativeLocationVenueRuleTest extends WP_UnitTestCase {

	private const VENUE      = 'Sentinel Native Venue Q7';
	private const STREET     = 'Sentinel Native Street 88Q';
	private const LOCALITY   = 'Sentinelburg';
	private const REGION     = 'SN';
	private const COUNTRY    = 'Sentinovia';
	private const POSTAL     = 'S3NT88Q';
	private const LATITUDE   = '61.778899';
	private const LONGITUDE  = '-149.334455';
	private const ALTITUDE   = '42';
	private const RESTAURANT = 'Sentinel Eatery Native Q9';
	private const DRINK_SPOT = 'Sentinel Taproom Native R1';

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		foreach ( [ 'geo_latitude', 'geo_longitude', 'geo_altitude', 'geo_address', 'geo_venue', 'geo_locality', 'geo_region', 'geo_country_name', 'geo_street_address', 'geo_postal_code', 'geo_public' ] as $key ) {
			register_post_meta( 'post', $key, [ 'show_in_rest' => true, 'single' => true, 'type' => 'string' ] );
		}
		register_rest_field(
			'post',
			'indieblocks_location',
			[
				'get_callback' => static function ( $p ) {
					return [
						'geo_latitude'  => get_post_meta( $p['id'], 'geo_latitude', true ),
						'geo_longitude' => get_post_meta( $p['id'], 'geo_longitude', true ),
						'geo_address'   => get_post_meta( $p['id'], 'geo_address', true ),
					];
				},
			]
		);
		wp_set_current_user( 0 );
	}

	/**
	 * @param array<string, string> $native_meta Native geo_* meta key => value.
	 * @param array<string, string> $pkiw_meta   _pkiw_-prefixed meta key (without prefix) => value.
	 * @param string|null           $kind        Kind taxonomy term to assign, if any.
	 * @return int Post ID.
	 */
	private function create_post( array $native_meta = [], array $pkiw_meta = [], ?string $kind = null ): int {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		foreach ( $native_meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		foreach ( $pkiw_meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}
		if ( null !== $kind ) {
			wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
		}
		return $post_id;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function rest_get( int $post_id ): array {
		$GLOBALS['wp_rest_server'] = null;
		$request                  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	private function full_native_fields(): array {
		return [
			'geo_latitude'       => self::LATITUDE,
			'geo_longitude'      => self::LONGITUDE,
			'geo_altitude'       => self::ALTITUDE,
			'geo_address'        => self::STREET,
			'geo_venue'          => self::VENUE,
			'geo_locality'       => self::LOCALITY,
			'geo_region'         => self::REGION,
			'geo_country_name'   => self::COUNTRY,
			'geo_street_address' => self::STREET,
			'geo_postal_code'    => self::POSTAL,
		];
	}

	// ─── 1. Check-in, pkiw approximate/unset, geo_public 1/unset: full ───

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function checkin_full_combos(): array {
		return [
			'approximate + geo_public 1'     => [ 'approximate', '1' ],
			'approximate + geo_public unset' => [ 'approximate', '' ],
			'unset + geo_public 1'           => [ '', '1' ],
			'unset + geo_public unset'       => [ '', '' ],
		];
	}

	/**
	 * @dataProvider checkin_full_combos
	 */
	public function test_checkin_shows_full_location( string $pkiw_privacy, string $geo_public ): void {
		$native = $this->full_native_fields();
		$native['geo_public'] = $geo_public;
		$pkiw_meta = [];
		if ( '' !== $pkiw_privacy ) {
			$pkiw_meta['geo_privacy'] = $pkiw_privacy;
		}
		$post_id = $this->create_post( $native, $pkiw_meta, 'checkin' );

		$data = $this->rest_get( $post_id );

		$this->assertSame( self::LATITUDE, $data['meta']['geo_latitude'] );
		$this->assertSame( self::LONGITUDE, $data['meta']['geo_longitude'] );
		$this->assertSame( self::STREET, $data['meta']['geo_address'] );
		$this->assertSame( self::VENUE, $data['meta']['geo_venue'] );
		$this->assertSame( self::LOCALITY, $data['meta']['geo_locality'] );
		$this->assertSame( self::POSTAL, $data['meta']['geo_postal_code'] );
		$this->assertSame( self::LATITUDE, $data['indieblocks_location']['geo_latitude'] );
		$this->assertSame( self::STREET, $data['indieblocks_location']['geo_address'] );
	}

	// ─── 2. Check-in, pkiw private: nothing ───

	public function test_checkin_pkiw_private_hides_everything(): void {
		$native                = $this->full_native_fields();
		$native['geo_public'] = '1';
		$post_id                = $this->create_post( $native, [ 'geo_privacy' => 'private' ], 'checkin' );

		$data = $this->rest_get( $post_id );

		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( '', $data['meta']['geo_address'] );
		$this->assertSame( '', $data['meta']['geo_venue'] );
		$this->assertSame( '', $data['indieblocks_location']['geo_address'] );
		$this->assertSame( self::LATITUDE, get_post_meta( $post_id, 'geo_latitude', true ), 'stored data must stay intact' );
	}

	// ─── 3. Check-in, geo_public 0: nothing (overrides even a public pkiw tier) ───

	public function test_checkin_geo_public_hidden_hides_everything(): void {
		$native                = $this->full_native_fields();
		$native['geo_public'] = '0';
		$post_id                = $this->create_post( $native, [ 'geo_privacy' => 'public' ], 'checkin' );

		$data = $this->rest_get( $post_id );

		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( '', $data['meta']['geo_address'] );
		$this->assertSame( '', $data['meta']['geo_venue'] );
		$this->assertSame( '', $data['indieblocks_location']['geo_latitude'] );
	}

	// ─── 4. Check-in, geo_public 2: text without coordinates ───

	public function test_checkin_geo_public_protected_keeps_text_hides_coordinates(): void {
		$native                = $this->full_native_fields();
		$native['geo_public'] = '2';
		$post_id                = $this->create_post( $native, [ 'geo_privacy' => 'approximate' ], 'checkin' );

		$data = $this->rest_get( $post_id );

		$this->assertSame( self::VENUE, $data['meta']['geo_venue'] );
		$this->assertSame( self::STREET, $data['meta']['geo_address'] );
		$this->assertSame( self::LOCALITY, $data['meta']['geo_locality'] );
		$this->assertSame( self::POSTAL, $data['meta']['geo_postal_code'] );
		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( '', $data['meta']['geo_longitude'] );
		$this->assertSame( '', $data['meta']['geo_altitude'] );
		$this->assertSame( '', $data['indieblocks_location']['geo_latitude'] );
		$this->assertSame( self::STREET, $data['indieblocks_location']['geo_address'] );
	}

	// ─── 5. Eat post with a restaurant, pkiw approximate: full ───

	public function test_eat_with_restaurant_approximate_shows_full_location(): void {
		$native  = $this->full_native_fields();
		$post_id = $this->create_post(
			$native,
			[
				'geo_privacy'   => 'approximate',
				'eat_restaurant' => self::RESTAURANT,
			]
		);

		$data = $this->rest_get( $post_id );

		$this->assertSame( self::LATITUDE, $data['meta']['geo_latitude'] );
		$this->assertSame( self::STREET, $data['meta']['geo_address'] );
		$this->assertSame( self::RESTAURANT, $data['meta']['_pkiw_eat_restaurant'] );
	}

	// ─── 6. Drink post with a venue, geo_public 0: nothing ───

	public function test_drink_with_venue_geo_public_hidden_hides_everything(): void {
		$native                = $this->full_native_fields();
		$native['geo_public'] = '0';
		$post_id                = $this->create_post(
			$native,
			[
				'geo_privacy'         => 'public',
				'drink_location_name' => self::DRINK_SPOT,
			]
		);

		$data = $this->rest_get( $post_id );

		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( '', $data['meta']['geo_address'] );
		$this->assertSame( '', $data['meta']['_pkiw_drink_location_name'] );
	}

	// ─── 7. Note with a venue name (native geo_venue only), pkiw approximate: full ───

	public function test_note_with_native_venue_name_approximate_shows_full_location(): void {
		$native  = $this->full_native_fields();
		$post_id = $this->create_post( $native, [ 'geo_privacy' => 'approximate' ] );

		$data = $this->rest_get( $post_id );

		$this->assertSame( self::VENUE, $data['meta']['geo_venue'] );
		$this->assertSame( self::STREET, $data['meta']['geo_address'] );
		$this->assertSame( self::LATITUDE, $data['meta']['geo_latitude'] );
	}

	// ─── 8. Note with only coordinates (+ place text, no venue name), pkiw
	// approximate, geo_public 1: name/locality/region/country tier only,
	// no coordinates/street/postal. ───

	public function test_note_coordinates_only_approximate_geo_public_public_hides_coordinates(): void {
		$post_id = $this->create_post(
			[
				'geo_latitude'     => self::LATITUDE,
				'geo_longitude'    => self::LONGITUDE,
				'geo_address'      => self::STREET,
				'geo_locality'     => self::LOCALITY,
				'geo_region'       => self::REGION,
				'geo_country_name' => self::COUNTRY,
				'geo_postal_code'  => self::POSTAL,
				'geo_public'       => '1',
			],
			[ 'geo_privacy' => 'approximate' ]
		);

		$data = $this->rest_get( $post_id );

		$this->assertSame( self::LOCALITY, $data['meta']['geo_locality'] );
		$this->assertSame( self::REGION, $data['meta']['geo_region'] );
		$this->assertSame( self::COUNTRY, $data['meta']['geo_country_name'] );
		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( '', $data['meta']['geo_longitude'] );
		$this->assertSame( '', $data['meta']['geo_address'] );
		$this->assertSame( '', $data['meta']['geo_postal_code'] );
		$this->assertSame( '', $data['indieblocks_location']['geo_latitude'], 'coordinates stay hidden in indieblocks_location too' );
	}

	// ─── 9. Photo with only coordinates, pkiw public, geo_public 0: nothing ───

	public function test_photo_coordinates_only_public_geo_public_hidden_hides_everything(): void {
		$post_id = $this->create_post(
			[
				'geo_latitude'  => self::LATITUDE,
				'geo_longitude' => self::LONGITUDE,
				'geo_public'    => '0',
			],
			[ 'geo_privacy' => 'public' ]
		);

		$data = $this->rest_get( $post_id );

		$this->assertSame( '', $data['meta']['geo_latitude'] );
		$this->assertSame( '', $data['meta']['geo_longitude'] );
		$this->assertSame( self::LATITUDE, get_post_meta( $post_id, 'geo_latitude', true ), 'stored data must stay intact' );
	}

	// ─── 10. Editors: full in every case ───

	/**
	 * @return array<string, array{0: array<string,string>, 1: array<string,string>, 2: string|null}>
	 */
	public function editor_scenarios(): array {
		return [
			'checkin private'                 => [ [ 'geo_public' => '1' ], [ 'geo_privacy' => 'private' ], 'checkin' ],
			'checkin geo_public hidden'        => [ [ 'geo_public' => '0' ], [ 'geo_privacy' => 'public' ], 'checkin' ],
			'checkin geo_public protected'     => [ [ 'geo_public' => '2' ], [ 'geo_privacy' => 'approximate' ], 'checkin' ],
			'note coordinates only, protected' => [ [ 'geo_public' => '0' ], [ 'geo_privacy' => 'approximate' ], null ],
		];
	}

	/**
	 * @dataProvider editor_scenarios
	 */
	public function test_editor_sees_full_location_regardless_of_privacy( array $extra_native, array $pkiw_meta, ?string $kind ): void {
		$native = array_merge( $this->full_native_fields(), $extra_native );
		$post_id = $this->create_post( $native, $pkiw_meta, $kind );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$data = $this->rest_get( $post_id );

		$this->assertSame( self::LATITUDE, $data['meta']['geo_latitude'] );
		$this->assertSame( self::STREET, $data['meta']['geo_address'] );
		$this->assertSame( self::VENUE, $data['meta']['geo_venue'] );
	}
}

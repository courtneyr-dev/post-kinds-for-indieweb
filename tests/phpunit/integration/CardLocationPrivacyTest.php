<?php
/**
 * Location-privacy leak coverage for the checkin, eat and drink cards.
 *
 * Covers the R-03 gap where "approximate" was treated inconsistently
 * (sometimes like public, sometimes like private) and "private" did not
 * fully hide venue name/locality/region/country anywhere. Exercises the
 * rendered card HTML (do_blocks), its parsed microformats2, and the REST
 * response for public/approximate/private posts as both an anonymous
 * visitor and an editor.
 *
 * Every value under test is a sentinel string that does not resemble a
 * real address, so a leak is unambiguous in the assertion output.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * @group integration
 */
final class CardLocationPrivacyTest extends WP_UnitTestCase {

	private const VENUE_NAME     = 'Sentinel Venue Zyx9';
	private const STREET         = '742 Sentinel Ave Qwrt';
	private const LOCALITY       = 'Sentinelville';
	private const REGION         = 'ST';
	private const COUNTRY        = 'Sentinia';
	private const POSTAL_CODE    = 'S3NT1N3L';
	private const LATITUDE       = 12.345678;
	private const LONGITUDE      = -76.543219;
	private const VENUE_URL      = 'https://sentinel.example/venue-zyx9';
	private const OSM_ID         = 'node/9998887';
	private const FOURSQUARE_ID  = '4sq-sentinel-777';
	private const RESTAURANT     = 'Sentinel Eatery Qvx4';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
	}

	/**
	 * Create a published post from a block comment and force its
	 * _pkiw_geo_privacy meta to the given value (after any card-attrs
	 * mirror save_post triggers), and render it through do_blocks().
	 *
	 * @param string $block_comment Full block HTML comment + markup.
	 * @param string $privacy       geo_privacy value to force.
	 * @return array{0: int, 1: string} Post ID and rendered HTML.
	 */
	private function render_card( string $block_comment, string $privacy ): array {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $block_comment,
			]
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );

		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		return [ $post_id, $html ];
	}

	private function checkin_block(): string {
		return sprintf(
			'<!-- wp:post-kinds-indieweb/checkin-card %s /-->',
			wp_json_encode(
				[
					'venueName'    => self::VENUE_NAME,
					'address'      => self::STREET,
					'locality'     => self::LOCALITY,
					'region'       => self::REGION,
					'country'      => self::COUNTRY,
					'postalCode'   => self::POSTAL_CODE,
					'latitude'     => self::LATITUDE,
					'longitude'    => self::LONGITUDE,
					'venueUrl'     => self::VENUE_URL,
					'osmId'        => self::OSM_ID,
					'foursquareId' => self::FOURSQUARE_ID,
				]
			)
		);
	}

	private function eat_block(): string {
		return sprintf(
			'<!-- wp:post-kinds-indieweb/eat-card %s /-->',
			wp_json_encode(
				[
					'name'             => 'Sentinel Dish',
					'restaurant'       => self::RESTAURANT,
					'restaurantUrl'    => self::VENUE_URL,
					'locationName'     => self::VENUE_NAME,
					'locationAddress'  => self::STREET,
					'locationLocality' => self::LOCALITY,
					'locationRegion'   => self::REGION,
					'locationCountry'  => self::COUNTRY,
					'geoLatitude'      => self::LATITUDE,
					'geoLongitude'     => self::LONGITUDE,
				]
			)
		);
	}

	private function drink_block(): string {
		return sprintf(
			'<!-- wp:post-kinds-indieweb/drink-card %s /-->',
			wp_json_encode(
				[
					'name'             => 'Sentinel Beverage',
					'venueUrl'         => self::VENUE_URL,
					'locationName'     => self::VENUE_NAME,
					'locationAddress'  => self::STREET,
					'locationLocality' => self::LOCALITY,
					'locationRegion'   => self::REGION,
					'locationCountry'  => self::COUNTRY,
					'geoLatitude'      => self::LATITUDE,
					'geoLongitude'     => self::LONGITUDE,
				]
			)
		);
	}

	/**
	 * Card render fixtures: block builder method name and whether the
	 * card exposes a map / OSM+Foursquare ids (checkin only).
	 *
	 * @return array<string, array{0: string}>
	 */
	public function card_builders(): array {
		return [
			'checkin' => [ 'checkin_block' ],
			'eat'     => [ 'eat_block' ],
			'drink'   => [ 'drink_block' ],
		];
	}

	// ─── Public: everything visible ───

	/**
	 * @dataProvider card_builders
	 */
	public function test_public_card_shows_full_location( string $builder ): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->{$builder}(), 'public' );

		$this->assertStringContainsString( self::VENUE_NAME, $html );
		$this->assertStringContainsString( self::STREET, $html );
		$this->assertStringContainsString( self::LOCALITY, $html );
		$this->assertStringContainsString( self::VENUE_URL, $html );
		$this->assertStringContainsString( (string) self::LATITUDE, $html );
	}

	// ─── Approximate: name/locality/region/country only ───

	/**
	 * @dataProvider card_builders
	 */
	public function test_approximate_card_keeps_name_and_place( string $builder ): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->{$builder}(), 'approximate' );

		$this->assertStringContainsString( self::VENUE_NAME, $html );
		$this->assertStringContainsString( self::LOCALITY, $html );
		$this->assertStringContainsString( self::REGION, $html );
		$this->assertStringContainsString( self::COUNTRY, $html );
	}

	/**
	 * @dataProvider card_builders
	 */
	public function test_approximate_card_hides_street_address( string $builder ): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->{$builder}(), 'approximate' );

		$this->assertStringNotContainsString( self::STREET, $html );
	}

	/**
	 * @dataProvider card_builders
	 */
	public function test_approximate_card_hides_coordinates( string $builder ): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->{$builder}(), 'approximate' );

		$this->assertStringNotContainsString( (string) self::LATITUDE, $html );
		$this->assertStringNotContainsString( (string) self::LONGITUDE, $html );
	}

	/**
	 * @dataProvider card_builders
	 */
	public function test_approximate_card_hides_venue_url( string $builder ): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->{$builder}(), 'approximate' );

		$this->assertStringNotContainsString( self::VENUE_URL, $html );
	}

	public function test_approximate_checkin_card_omits_map_entirely(): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->checkin_block(), 'approximate' );

		$this->assertStringNotContainsString( 'pk-embed--map', $html );
		$this->assertStringNotContainsString( 'openstreetmap.org/export/embed.html', $html );
	}

	public function test_approximate_checkin_card_hides_osm_and_foursquare_ids(): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->checkin_block(), 'approximate' );

		$this->assertStringNotContainsString( self::OSM_ID, $html );
		$this->assertStringNotContainsString( self::FOURSQUARE_ID, $html );
	}

	// ─── Private: nothing ───

	/**
	 * @dataProvider card_builders
	 */
	public function test_private_card_hides_everything( string $builder ): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->{$builder}(), 'private' );

		$this->assertStringNotContainsString( self::VENUE_NAME, $html );
		$this->assertStringNotContainsString( self::STREET, $html );
		$this->assertStringNotContainsString( self::LOCALITY, $html );
		$this->assertStringNotContainsString( self::REGION, $html );
		$this->assertStringNotContainsString( self::COUNTRY, $html );
		$this->assertStringNotContainsString( self::VENUE_URL, $html );
		$this->assertStringNotContainsString( (string) self::LATITUDE, $html );
	}

	public function test_private_checkin_card_hides_map_and_ids(): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->checkin_block(), 'private' );

		$this->assertStringNotContainsString( 'pk-embed--map', $html );
		$this->assertStringNotContainsString( self::OSM_ID, $html );
		$this->assertStringNotContainsString( self::FOURSQUARE_ID, $html );
	}

	// ─── Editor: full values regardless of privacy ───

	/**
	 * @dataProvider card_builders
	 */
	public function test_editor_sees_full_location_on_private_post( string $builder ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		[ , $html ] = $this->render_card( $this->{$builder}(), 'private' );

		$this->assertStringContainsString( self::VENUE_NAME, $html );
		$this->assertStringContainsString( self::STREET, $html );
		$this->assertStringContainsString( self::VENUE_URL, $html );
	}

	// ─── microformats2: approximate keeps a slimmed p-location h-card ───

	public function test_approximate_checkin_mf2_location_drops_precise_fields(): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->checkin_block(), 'approximate' );

		$parsed   = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$location = $this->find_h_card( $parsed );

		$this->assertNotNull( $location, 'Expected a parsed p-location h-card.' );
		$properties = $location['properties'];
		$this->assertArrayHasKey( 'locality', $properties );
		$this->assertSame( self::LOCALITY, $properties['locality'][0] );
		$this->assertArrayNotHasKey( 'street-address', $properties );
		$this->assertArrayNotHasKey( 'geo', $properties );
	}

	public function test_public_checkin_mf2_location_keeps_precise_fields(): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->checkin_block(), 'public' );

		$parsed   = \Mf2\parse( '<div class="h-entry">' . $html . '</div>' );
		$location = $this->find_h_card( $parsed );

		$this->assertNotNull( $location );
		$properties = $location['properties'];
		$this->assertArrayHasKey( 'street-address', $properties );
		$this->assertSame( self::STREET, $properties['street-address'][0] );
	}

	/**
	 * Find the first parsed h-card item (the card's p-location h-card).
	 *
	 * @param array<string, mixed> $parsed Parsed microformats2 document.
	 * @return array<string, mixed>|null
	 */
	private function find_h_card( array $parsed ): ?array {
		foreach ( $parsed['items'] ?? [] as $item ) {
			$found = $this->search_h_card( $item );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $item Parsed microformats2 item.
	 * @return array<string, mixed>|null
	 */
	private function search_h_card( array $item ): ?array {
		if ( in_array( 'h-card', $item['type'] ?? [], true ) ) {
			return $item;
		}
		foreach ( $item['properties'] ?? [] as $values ) {
			foreach ( (array) $values as $value ) {
				if ( is_array( $value ) && isset( $value['type'] ) ) {
					$found = $this->search_h_card( $value );
					if ( null !== $found ) {
						return $found;
					}
				}
			}
		}
		// A wrapping element that shares its child's h-* type (our test
		// harness's outer h-entry div around the card's own h-entry
		// article) parses with empty own properties and the real item
		// nested under 'children' — look there too.
		foreach ( $item['children'] ?? [] as $child ) {
			if ( is_array( $child ) ) {
				$found = $this->search_h_card( $child );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	// ─── REST: private strips name/locality/region/country too ───

	/**
	 * Dispatch a REST GET for a post, as the given user.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function rest_get( int $post_id ): array {
		$GLOBALS['wp_rest_server'] = null;
		$request                  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	public function test_rest_private_checkin_strips_name_and_place_meta(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE_NAME );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_locality', self::LOCALITY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_region', self::REGION );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_country', self::COUNTRY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		wp_set_current_user( 0 );
		$data = $this->rest_get( $post_id );

		$this->assertSame( '', $data['meta']['_pkiw_checkin_name'] );
		$this->assertSame( '', $data['meta']['_pkiw_checkin_locality'] );
		$this->assertSame( '', $data['meta']['_pkiw_checkin_region'] );
		$this->assertSame( '', $data['meta']['_pkiw_checkin_country'] );
		$this->assertSame( self::VENUE_NAME, get_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', true ), 'stored data must stay intact' );
	}

	public function test_rest_approximate_checkin_keeps_name_and_place_meta(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE_NAME );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_locality', self::LOCALITY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'approximate' );

		wp_set_current_user( 0 );
		$data = $this->rest_get( $post_id );

		$this->assertSame( self::VENUE_NAME, $data['meta']['_pkiw_checkin_name'] );
		$this->assertSame( self::LOCALITY, $data['meta']['_pkiw_checkin_locality'] );
	}

	public function test_rest_private_eat_strips_location_name_and_place_meta(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'eat_location_name', self::VENUE_NAME );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'eat_location_locality', self::LOCALITY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		wp_set_current_user( 0 );
		$data = $this->rest_get( $post_id );

		$this->assertSame( '', $data['meta']['_pkiw_eat_location_name'] );
		$this->assertSame( '', $data['meta']['_pkiw_eat_location_locality'] );
	}

	// ─── Eat card `restaurant` is venue identity: the name tier ───

	public function test_private_eat_card_hides_restaurant(): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->eat_block(), 'private' );

		$this->assertStringNotContainsString( self::RESTAURANT, $html );
	}

	public function test_approximate_eat_card_keeps_restaurant(): void {
		wp_set_current_user( 0 );
		[ , $html ] = $this->render_card( $this->eat_block(), 'approximate' );

		$this->assertStringContainsString( self::RESTAURANT, $html );
	}

	public function test_editor_sees_restaurant_on_private_eat_card(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		[ , $html ] = $this->render_card( $this->eat_block(), 'private' );

		$this->assertStringContainsString( self::RESTAURANT, $html );
	}

	public function test_rest_private_eat_strips_restaurant_meta(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'eat_restaurant', self::RESTAURANT );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		wp_set_current_user( 0 );
		$data = $this->rest_get( $post_id );

		$this->assertSame( '', $data['meta']['_pkiw_eat_restaurant'] );
		$this->assertSame( self::RESTAURANT, get_post_meta( $post_id, Meta_Fields::PREFIX . 'eat_restaurant', true ), 'stored data must stay intact' );
	}

	public function test_rest_approximate_eat_keeps_restaurant_meta(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'eat_restaurant', self::RESTAURANT );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'approximate' );

		wp_set_current_user( 0 );
		$data = $this->rest_get( $post_id );

		$this->assertSame( self::RESTAURANT, $data['meta']['_pkiw_eat_restaurant'] );
	}

	public function test_rest_editor_sees_full_meta_on_private_post(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE_NAME );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$data = $this->rest_get( $post_id );

		$this->assertSame( self::VENUE_NAME, $data['meta']['_pkiw_checkin_name'] );
	}

	// ─── Block bindings source (post-kinds/kind-meta) stays safe ───

	public function test_legacy_binding_source_never_exposes_location(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			$this->markTestSkipped( 'Block Bindings API requires WordPress 6.5+.' );
		}

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE_NAME );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'public' );
		wp_set_object_terms( $post_id, 'checkin', 'kind' );

		$source = new \PKIW\Block_Bindings_Source();
		$block  = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => $post_id, 'postType' => 'post' ];

		$reflection = new \ReflectionClass( \PKIW\Block_Bindings_Source::class );
		$key_map    = $reflection->getConstant( 'KEY_MAP' );

		foreach ( array_keys( $key_map ) as $key ) {
			$value = $source->get_value( [ 'key' => $key ], $block, 'content' );
			$this->assertNotSame( self::VENUE_NAME, $value, "post-kinds/kind-meta key '{$key}' must never resolve to location data." );
		}
	}
}

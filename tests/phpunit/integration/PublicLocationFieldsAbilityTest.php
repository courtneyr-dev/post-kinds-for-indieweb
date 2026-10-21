<?php
/**
 * Public location fields read ability integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class PublicLocationFieldsAbilityTest extends WP_UnitTestCase {

	private const SENTINELS = [ '40.20192', '-77.19256', '123 Sentinel St' ];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->register_taxonomy();
		( new Meta_Fields() )->register_meta_fields();
		if ( ! term_exists( 'checkin', Taxonomy::TAXONOMY ) ) {
			wp_insert_term( 'Checkin', Taxonomy::TAXONOMY, [ 'slug' => 'checkin' ] );
		}
	}

	public function test_public_location_is_identical_for_every_reader(): void {
		[ $post_id, $author_id ] = $this->location_post( 'public' );
		$expected_location       = [
			'checkin_name'      => 'Sentinel Place',
			'checkin_locality'  => 'Carlisle',
			'checkin_region'    => 'PA',
			'checkin_country'   => 'US',
			'checkin_address'   => '123 Sentinel St',
			'checkin_url'       => 'https://example.com/sentinel',
			'checkin_osm_id'    => 'node/123',
			'geo_latitude'      => '40.20192',
			'geo_longitude'     => '-77.19256',
		];

		foreach ( $this->reader_ids( $author_id ) as $user_id ) {
			wp_set_current_user( $user_id );
			$result = $this->execute( $post_id );
			$this->assertSame( $this->visible_all( true ), $result['visible'] );
			$this->assertSame( $expected_location, $result['location'] );
		}
	}

	/**
	 * @dataProvider approximate_privacy_values
	 */
	public function test_approximate_and_unset_location_expose_only_place_text( ?string $privacy ): void {
		[ $post_id, $author_id ] = $this->location_post( $privacy );
		$expected_visible        = $this->visible_approximate();
		$expected_location       = [
			'checkin_name'     => 'Sentinel Place',
			'checkin_locality' => 'Carlisle',
			'checkin_region'   => 'PA',
			'checkin_country'  => 'US',
		];

		foreach ( $this->reader_ids( $author_id ) as $user_id ) {
			wp_set_current_user( $user_id );
			$result = $this->execute( $post_id );
			$this->assertSame( $expected_visible, $result['visible'] );
			$this->assertSame( $expected_location, $result['location'] );
			$this->assertNoSentinels( $result );
		}
	}

	/**
	 * Approximate and unset Post Kinds privacy values.
	 *
	 * @return array<string, array{0:?string}>
	 */
	public static function approximate_privacy_values(): array {
		return [
			'approximate' => [ 'approximate' ],
			'unset'       => [ null ],
		];
	}

	public function test_private_location_is_empty_for_every_reader(): void {
		[ $post_id, $author_id ] = $this->location_post( 'private' );

		foreach ( $this->reader_ids( $author_id ) as $user_id ) {
			wp_set_current_user( $user_id );
			$result = $this->execute( $post_id );
			$this->assertSame( $this->visible_all( false ), $result['visible'] );
			$this->assertSame( [], $result['location'] );
			$this->assertNoSentinels( $result );
		}
	}

	public function test_simple_location_private_overrides_public_post_kinds_tier(): void {
		[ $post_id ] = $this->location_post( 'public' );
		update_post_meta( $post_id, 'geo_public', '0' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->execute( $post_id );

		$this->assertSame( $this->visible_all( false ), $result['visible'] );
		$this->assertSame( [], $result['location'] );
	}

	public function test_simple_location_protected_removes_coordinates_map_and_ids(): void {
		[ $post_id ] = $this->location_post( 'public' );
		update_post_meta( $post_id, 'geo_public', '2' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->execute( $post_id );

		$this->assertSame(
			[
				'name'        => true,
				'locality'    => true,
				'region'      => true,
				'country'     => true,
				'street'      => true,
				'postal_code' => true,
				'coordinates' => false,
				'map'         => false,
				'url'         => true,
				'osm_id'      => false,
				'venue_id'    => false,
			],
			$result['visible']
		);
		$this->assertSame(
			[
				'checkin_name'     => 'Sentinel Place',
				'checkin_locality' => 'Carlisle',
				'checkin_region'   => 'PA',
				'checkin_country'  => 'US',
				'checkin_address'  => '123 Sentinel St',
				'checkin_url'      => 'https://example.com/sentinel',
			],
			$result['location']
		);
		$this->assertArrayNotHasKey( 'geo_latitude', $result['location'] );
		$this->assertArrayNotHasKey( 'geo_longitude', $result['location'] );
	}

	public function test_simple_location_public_leaves_post_kinds_tier_unchanged(): void {
		[ $post_id ] = $this->location_post( 'approximate' );
		update_post_meta( $post_id, 'geo_public', '1' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->execute( $post_id );

		$this->assertSame( $this->visible_approximate(), $result['visible'] );
		$this->assertSame(
			[
				'checkin_name'     => 'Sentinel Place',
				'checkin_locality' => 'Carlisle',
				'checkin_region'   => 'PA',
				'checkin_country'  => 'US',
			],
			$result['location']
		);
	}

	public function test_password_protected_post_returns_no_location_to_any_reader(): void {
		[ $post_id, $author_id ] = $this->location_post( 'public', [ 'post_password' => 'secret' ] );

		foreach ( $this->reader_ids( $author_id ) as $user_id ) {
			wp_set_current_user( $user_id );
			$result = $this->execute( $post_id );
			$this->assertSame( $this->visible_all( false ), $result['visible'] );
			$this->assertSame( [], $result['location'] );
		}
	}

	/**
	 * @dataProvider non_public_statuses
	 */
	public function test_non_public_status_returns_no_location_to_administrator( string $status ): void {
		$args = [ 'post_status' => $status ];
		if ( 'future' === $status ) {
			// WordPress turns a future post with a past date into a published one.
			$args['post_date'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}
		[ $post_id ] = $this->location_post( 'public', $args );
		$this->assertSame( $status, get_post_status( $post_id ), 'The fixture must keep the status under test.' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->execute( $post_id );

		$this->assertSame( $this->visible_all( false ), $result['visible'] );
		$this->assertSame( [], $result['location'] );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function non_public_statuses(): array {
		return [
			'private' => [ 'private' ],
			'draft'   => [ 'draft' ],
			'pending' => [ 'pending' ],
			'future'  => [ 'future' ],
		];
	}

	/**
	 * @dataProvider privacy_tiers
	 */
	public function test_unrelated_meta_never_appears_in_location( string $privacy ): void {
		[ $post_id ] = $this->location_post( $privacy );
		update_post_meta( $post_id, '_pkiw_drink_brewery', 'Sentinel Brewery' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->execute( $post_id );

		foreach ( [ 'checkin_venue_id', 'listen_track', 'secret_note', 'drink_brewery' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $result['location'] );
		}
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function privacy_tiers(): array {
		return [
			'public'      => [ 'public' ],
			'approximate' => [ 'approximate' ],
			'private'     => [ 'private' ],
		];
	}

	public function test_approximate_eat_location_keeps_name_and_hides_coordinates(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_pkiw_geo_privacy', 'approximate' );
		update_post_meta( $post_id, '_pkiw_eat_restaurant', 'Sentinel Diner' );
		update_post_meta( $post_id, '_pkiw_eat_geo_latitude', '12.34567' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->execute( $post_id );

		$this->assertSame( 'Sentinel Diner', $result['location']['eat_restaurant'] );
		$this->assertArrayNotHasKey( 'eat_geo_latitude', $result['location'] );
	}

	/**
	 * @dataProvider privacy_tiers
	 */
	public function test_visible_matches_public_location_fields_for_published_posts( string $privacy ): void {
		[ $post_id ] = $this->location_post( $privacy );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->execute( $post_id );

		$this->assertSame( Meta_Fields::get_public_location_fields( $post_id ), $result['visible'] );
	}

	public function test_subscriber_cannot_read_draft(): void {
		[ $post_id ] = $this->location_post( 'public', [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability()->execute( [ 'post_id' => $post_id ] ) );
	}

	public function test_logged_out_request_is_denied(): void {
		[ $post_id ] = $this->location_post( 'public' );
		wp_set_current_user( 0 );

		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability()->execute( [ 'post_id' => $post_id ] ) );
	}

	public function test_unknown_post_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability()->execute( [ 'post_id' => 999999 ] ) );
	}

	public function test_public_post_passes_output_schema_validation(): void {
		[ $post_id ] = $this->location_post( 'public' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	public function test_direct_public_location_view_matches_ability_and_handles_unknown_post(): void {
		[ $post_id ] = $this->location_post( 'approximate' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$ability = $this->execute( $post_id );
		$direct  = Meta_Fields::get_public_location_view( $post_id );
		$missing = Meta_Fields::get_public_location_view( 999999 );

		$this->assertSame( $ability['visible'], $direct['visible'] );
		$this->assertSame( $ability['location'], $direct['meta'] );
		$this->assertSame( [], $missing['meta'] );
		$this->assertSame( $this->visible_all( false ), $missing['visible'] );
	}

	/**
	 * Create a location-bearing check-in post.
	 *
	 * @param string|null          $privacy Privacy tier, or null for unset.
	 * @param array<string, mixed> $args    Post overrides.
	 * @return array{0:int,1:int}
	 */
	private function location_post( ?string $privacy, array $args = [] ): array {
		$author_id = isset( $args['post_author'] )
			? (int) $args['post_author']
			: self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id   = self::factory()->post->create(
			array_merge(
				[
					'post_author' => $author_id,
					'post_status' => 'publish',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'checkin', Taxonomy::TAXONOMY );

		$meta = [
			'checkin_name'       => 'Sentinel Place',
			'checkin_locality'   => 'Carlisle',
			'checkin_region'     => 'PA',
			'checkin_country'    => 'US',
			'checkin_address'    => '123 Sentinel St',
			'checkin_url'        => 'https://example.com/sentinel',
			'checkin_osm_id'     => 'node/123',
			'geo_latitude'       => '40.20192',
			'geo_longitude'      => '-77.19256',
			'checkin_venue_id'   => 'venue-secret',
			'listen_track'       => 'Hidden Track',
			'secret_note'        => 'Hidden Note',
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}
		if ( null !== $privacy ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );
		}

		return [ $post_id, $author_id ];
	}

	/**
	 * Subscriber, author, editor and administrator IDs.
	 *
	 * @return int[]
	 */
	private function reader_ids( int $author_id ): array {
		return [
			self::factory()->user->create( [ 'role' => 'subscriber' ] ),
			$author_id,
			self::factory()->user->create( [ 'role' => 'editor' ] ),
			self::factory()->user->create( [ 'role' => 'administrator' ] ),
		];
	}

	/**
	 * Execute the public location ability and require an array response.
	 *
	 * @return array<string, mixed>
	 */
	private function execute( int $post_id ): array {
		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );
		$this->assertIsArray( $result );
		return $result;
	}

	/**
	 * Fetch the registered public-location ability.
	 *
	 * @return WP_Ability
	 */
	private function ability() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version (needs 6.9+).' );
		}

		$ability = wp_get_ability( 'post-kinds/get-public-location-fields' );
		$this->assertNotNull( $ability, 'The public location fields ability must be registered.' );
		return $ability;
	}

	/**
	 * All eleven visibility keys set to the same value.
	 *
	 * @return array<string, bool>
	 */
	private function visible_all( bool $value ): array {
		return array_fill_keys(
			[ 'name', 'locality', 'region', 'country', 'street', 'postal_code', 'coordinates', 'map', 'url', 'osm_id', 'venue_id' ],
			$value
		);
	}

	/**
	 * Visitor-visible fields for approximate or unset privacy.
	 *
	 * @return array<string, bool>
	 */
	private function visible_approximate(): array {
		return [
			'name'        => true,
			'locality'    => true,
			'region'      => true,
			'country'     => true,
			'street'      => false,
			'postal_code' => false,
			'coordinates' => false,
			'map'         => false,
			'url'         => false,
			'osm_id'      => false,
			'venue_id'    => false,
		];
	}

	/**
	 * Assert serialized output contains no precise-location sentinel.
	 *
	 * @param array<string, mixed> $result Ability result.
	 */
	private function assertNoSentinels( array $result ): void {
		$json = wp_json_encode( $result );
		foreach ( self::SENTINELS as $sentinel ) {
			$this->assertStringNotContainsString( $sentinel, $json );
		}
	}

	/**
	 * Assert a WP_Error has one exact code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Ability result.
	 */
	private function assertErrorCode( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}

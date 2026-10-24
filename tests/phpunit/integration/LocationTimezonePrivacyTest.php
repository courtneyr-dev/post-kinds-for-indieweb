<?php
/**
 * A hidden location's timezone stays out of its post's dates (issue 412).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * Simple Location rewrites a post's REST `date` and `modified`, and the
 * get_the_date() family, into the post's stored `geo_timezone`, offset
 * included. On a post whose location is hidden that offset narrows the
 * location down to a UTC offset. Runs against the real Simple Location when
 * PKIW_TESTS_SIMPLE_LOCATION_FILE loads it, and otherwise against a stand-in
 * that hooks what Loc_Timezone hooks (5.0.25, class-loc-timezone.php:7-18
 * and :79-89) the way it does.
 *
 * @group integration
 */
final class LocationTimezonePrivacyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		if ( ! class_exists( 'Loc_Timezone' ) ) {
			$this->stand_in_for_loc_timezone();
		} elseif ( ! has_filter( 'rest_prepare_post', [ 'Loc_Timezone', 'rest_prepare_post' ] ) ) {
			Loc_Timezone::init();
		}
		update_option( 'timezone_string', 'America/New_York' );
		wp_set_current_user( 0 );
	}

	/**
	 * Loc_Timezone's date rewrites: the getters at 9, REST at 10, both in the
	 * post's `geo_timezone`. The framework restores hooks after each test.
	 */
	private function stand_in_for_loc_timezone(): void {
		$zone = static function ( $post ): ?DateTimeZone {
			$post = get_post( $post );
			$name = $post ? (string) get_post_meta( $post->ID, 'geo_timezone', true ) : '';

			return '' === $name ? null : new DateTimeZone( $name );
		};
		foreach ( [ 'get_the_date', 'get_the_modified_date' ] as $hook ) {
			add_filter(
				$hook,
				static function ( $value, $format = '', $post = null ) use ( $zone, $hook ) {
					$tz = $zone( $post );
					if ( ! $tz ) {
						return $value;
					}
					$field = 'get_the_date' === $hook ? 'date' : 'modified';

					return wp_date( '' === $format ? (string) get_option( 'date_format' ) : $format, (int) get_post_timestamp( $post, $field ), $tz );
				},
				9,
				3
			);
		}
		add_filter(
			'rest_prepare_post',
			static function ( $response, $post ) use ( $zone ) {
				$tz = $zone( $post );
				if ( $tz ) {
					$data             = $response->get_data();
					$data['date']     = wp_date( DATE_W3C, (int) get_post_timestamp( $post ), $tz );
					$data['modified'] = wp_date( DATE_W3C, (int) get_post_timestamp( $post, 'modified' ), $tz );
					$response->set_data( $data );
				}

				return $response;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published post set in Tokyo time with the given privacy meta.
	 *
	 * @param array<string, string> $meta Privacy meta.
	 * @return int Post ID.
	 */
	private function tokyo_post( array $meta ): int {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_date_gmt' => '2026-10-10 14:00:00',
				'post_date'     => '2026-10-10 10:00:00',
			]
		);
		update_post_meta( $id, 'geo_timezone', 'Asia/Tokyo' );
		update_post_meta( $id, 'geo_latitude', '35.6' );
		update_post_meta( $id, 'geo_longitude', '139.7' );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		wp_cache_delete( $id, 'post_timezone' );

		return $id;
	}

	/**
	 * The REST view of a post.
	 *
	 * @param int    $id      Post ID.
	 * @param string $context Context.
	 * @return array<string, mixed>
	 */
	private function rest( int $id, string $context = 'view' ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$request->set_param( 'context', $context );

		return (array) rest_do_request( $request )->get_data();
	}

	/**
	 * Hidden-location shapes.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public function hidden(): array {
		return [
			'pkiw private'        => [ [ '_pkiw_geo_privacy' => 'private' ] ],
			'simple location off' => [ [ 'geo_public' => '0' ] ],
		];
	}

	/**
	 * @dataProvider hidden
	 *
	 * @param array<string, string> $meta Privacy meta.
	 */
	public function test_rest_dates_of_a_hidden_location_post_carry_no_geo_offset( array $meta ): void {
		$id   = $this->tokyo_post( $meta );
		$data = $this->rest( $id );

		$this->assertStringNotContainsString( '+09:00', (string) $data['date'] );
		$this->assertStringNotContainsString( '+09:00', (string) $data['modified'] );
		$this->assertSame( '2026-10-10T10:00:00', $data['date'], 'Core\'s site-time value.' );
	}

	/**
	 * @dataProvider hidden
	 *
	 * @param array<string, string> $meta Privacy meta.
	 */
	public function test_get_the_date_of_a_hidden_location_post_is_in_site_time( array $meta ): void {
		$id = $this->tokyo_post( $meta );

		$this->assertSame( '2026-10-10T10:00:00-04:00', get_the_date( 'c', $id ) );
		$this->assertStringEndsWith( wp_date( 'P', (int) get_post_timestamp( $id, 'modified' ) ), (string) get_the_modified_date( 'c', $id ), 'Modified date in the site offset.' );
	}

	public function test_a_public_location_post_keeps_its_local_time(): void {
		$id = $this->tokyo_post( [ '_pkiw_geo_privacy' => 'public' ] );

		$this->assertStringEndsWith( '+09:00', (string) $this->rest( $id )['date'] );
		$this->assertSame( '2026-10-10T23:00:00+09:00', get_the_date( 'c', $id ) );
	}

	public function test_an_editor_s_edit_context_keeps_simple_location_s_value(): void {
		$id = $this->tokyo_post( [ '_pkiw_geo_privacy' => 'private' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertStringEndsWith( '+09:00', (string) $this->rest( $id, 'edit' )['date'] );
	}

	public function test_an_approximate_location_post_keeps_its_local_time(): void {
		$id = $this->tokyo_post( [ '_pkiw_geo_privacy' => 'approximate' ] );
		$this->assertTrue( Meta_Fields::get_public_location_fields( $id )['locality'], 'An approximate location prints its locality.' );

		$this->assertSame( '2026-10-10T23:00:00+09:00', get_the_date( 'c', $id ) );
	}

	public function test_a_hidden_location_post_without_a_stored_timezone_is_left_alone(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $id, '_pkiw_geo_privacy', 'private' );

		$this->assertFalse( \PKIW\Location_Timezone::hides_timezone( get_post( $id ) ) );
	}
}

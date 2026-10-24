<?php
/**
 * A Micropub check-in's card privacy follows the request (issue 370).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Micropub_Content_Builder;

/**
 * Micropub_Content_Builder::checkin_card() never set the card's
 * locationPrivacy, so every Micropub check-in fell back to the card default,
 * approximate, and the stricter-wins hold (issue 358) kept a later 'public'
 * row from loosening it. The builder now reads the request's
 * `mp-location-privacy` (public, approximate or private), else
 * `location-visibility` (Simple Location's public, protected or private),
 * and leaves the default when neither is sent.
 *
 * @group integration
 */
final class MicropubCheckinVisibilityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		if ( ! term_exists( 'checkin', 'kind' ) ) {
			wp_insert_term( 'Checkin', 'kind', [ 'slug' => 'checkin' ] );
		}
		wp_set_current_user( 0 );
	}

	/**
	 * The check-in card the builder writes for some properties.
	 *
	 * @param array<string, mixed> $properties h-entry properties.
	 */
	private function card( array $properties ): string {
		$method = new ReflectionMethod( Micropub_Content_Builder::class, 'checkin_card' );
		$method->setAccessible( true );

		return (string) $method->invoke(
			null,
			array_merge(
				[
					'mp-place-name' => [ 'Brass Lantern Cafe' ],
					'location'      => [ 'geo:39.37,-77.37' ],
				],
				$properties
			)
		);
	}

	/**
	 * A published check-in saved with the builder's card.
	 *
	 * @param array<string, mixed> $properties h-entry properties.
	 * @return int Post ID.
	 */
	private function checkin( array $properties ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $this->card( $properties ),
			]
		);
		wp_set_object_terms( $id, 'checkin', 'kind' );

		return $id;
	}

	/**
	 * Visibility sent, and the privacy the card and row get.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function visibilities(): array {
		return [
			'public'                 => [ [ 'public' ], 'public' ],
			'protected'              => [ [ 'protected' ], 'approximate' ],
			'approximate'            => [ [ 'approximate' ], 'approximate' ],
			'private'                => [ [ 'private' ], 'private' ],
			'scalar public'          => [ 'public', 'public' ],
			'upper-case private'     => [ [ 'PRIVATE' ], 'private' ],
			'unknown value: default' => [ [ 'friends' ], 'approximate' ],
		];
	}

	/**
	 * @dataProvider visibilities
	 *
	 * @param mixed  $sent     The request's location-visibility.
	 * @param string $expected The card's locationPrivacy and the stored row.
	 */
	public function test_the_card_and_row_follow_the_request( $sent, string $expected ): void {
		$id = $this->checkin( [ 'location-visibility' => $sent ] );

		$this->assertSame( $expected, get_post_meta( $id, '_pkiw_geo_privacy', true ) );
	}

	public function test_a_public_check_in_shows_its_coordinates(): void {
		$id = $this->checkin( [ 'location-visibility' => [ 'public' ] ] );

		$this->assertTrue( Meta_Fields::get_public_location_fields( $id )['coordinates'] );
	}

	public function test_a_private_check_in_hides_its_venue(): void {
		$id = $this->checkin( [ 'location-visibility' => [ 'private' ] ] );

		$this->assertFalse( Meta_Fields::get_public_location_fields( $id )['name'] );
	}

	public function test_no_visibility_leaves_the_card_default(): void {
		$this->assertStringNotContainsString( 'locationPrivacy', $this->card( [] ) );
		$id = $this->checkin( [] );

		$this->assertFalse( Meta_Fields::get_public_location_fields( $id )['coordinates'], 'The default, approximate, hides coordinates.' );
		$this->assertTrue( Meta_Fields::get_public_location_fields( $id )['name'] );
	}

	public function test_mp_location_privacy_wins_over_location_visibility(): void {
		$id = $this->checkin(
			[
				'mp-location-privacy' => [ 'private' ],
				'location-visibility' => [ 'public' ],
			]
		);

		$this->assertSame( 'private', get_post_meta( $id, '_pkiw_geo_privacy', true ) );
	}

	public function test_an_invalid_mp_location_privacy_falls_back_to_location_visibility(): void {
		$id = $this->checkin(
			[
				'mp-location-privacy' => [ 'protected' ],
				'location-visibility' => [ 'public' ],
			]
		);

		$this->assertSame( 'public', get_post_meta( $id, '_pkiw_geo_privacy', true ) );
	}

	public function test_mp_location_privacy_alone_sets_approximate(): void {
		$id = $this->checkin( [ 'mp-location-privacy' => 'approximate' ] );

		$this->assertStringContainsString( '"locationPrivacy":"approximate"', (string) get_post_field( 'post_content', $id, 'raw' ) );
	}
}

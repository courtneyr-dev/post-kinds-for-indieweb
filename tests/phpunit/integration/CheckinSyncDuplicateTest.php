<?php
/**
 * Check-in syncs import each provider check-in once, whatever its status.
 *
 * Untappd_Checkin_Sync::import_checkin() called a find_existing_post()
 * method that neither it nor Checkin_Sync_Base defined, so the first
 * Untappd check-in a sync reached threw an Error (#216). The base class
 * duplicate check also keyed on `$checkin['id']`, which Untappd check-ins
 * don't have, and used get_posts() without post_status, so it matched
 * published posts only. These tests run as user 0 outside wp-admin.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\Sync\Foursquare_Checkin_Sync;
use PKIW\Sync\Untappd_Checkin_Sync;
use PKIW\Tests\ApiTestCase;

/**
 * Duplicate detection for Checkin_Sync_Base subclasses.
 */
final class CheckinSyncDuplicateTest extends ApiTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );

		update_option(
			'pkiw_api_credentials',
			[
				'untappd'    => [
					'access_token' => 'fake-untappd-token',
					'username'     => 'testuser',
				],
				'foursquare' => [
					'access_token' => 'fake-foursquare-token',
				],
			]
		);

		$this->mock_http_response(
			'api.untappd.com/v4/user/checkins/testuser',
			[
				'response' => [
					'checkins' => [
						'items' => [
							$this->untappd_checkin( 1001, 'Pliny the Elder' ),
							$this->untappd_checkin( 1002, 'Heady Topper' ),
						],
					],
				],
			]
		);

		$this->mock_http_response(
			'api.foursquare.com/v2/users/self/checkins',
			[
				'response' => [
					'checkins' => [
						'items' => [
							$this->foursquare_checkin( 'fsq-checkin-1', 'Blue Bottle Coffee' ),
						],
					],
				],
			]
		);
	}

	public function test_untappd_sync_twice_imports_each_checkin_once(): void {
		$sync = new Untappd_Checkin_Sync();

		$first = $sync->import_checkins( 50 );

		$this->assertSame( 2, $first['imported'] );
		$this->assertSame( 0, $first['errors'] );
		$this->assertSame( 1, $this->count_posts_with( '_pkiw_untappd_checkin_id', '1001' ) );
		$this->assertSame( 1, $this->count_posts_with( '_pkiw_untappd_checkin_id', '1002' ) );

		$second = ( new Untappd_Checkin_Sync() )->import_checkins( 50 );

		$this->assertSame( 0, $second['imported'], 'The second Untappd sync imported check-ins again.' );
		$this->assertSame( 2, $second['skipped'] );
		$this->assertSame( 1, $this->count_posts_with( '_pkiw_untappd_checkin_id', '1001' ) );
		$this->assertSame( 1, $this->count_posts_with( '_pkiw_untappd_checkin_id', '1002' ) );
	}

	/**
	 * A draft or trashed earlier import still blocks re-import.
	 *
	 * @dataProvider blocking_status_provider
	 *
	 * @param string $status Status of the earlier import.
	 */
	public function test_untappd_sync_skips_an_earlier_import_in_any_status( string $status ): void {
		$post_id = self::factory()->post->create( [ 'post_status' => $status ] );
		update_post_meta( $post_id, '_pkiw_untappd_checkin_id', '1001' );

		$result = ( new Untappd_Checkin_Sync() )->import_checkins( 50 );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 1, $this->count_posts_with( '_pkiw_untappd_checkin_id', '1001' ) );
	}

	public function test_untappd_import_records_the_checkin_id_under_the_base_key(): void {
		( new Untappd_Checkin_Sync() )->import_checkins( 50 );

		$this->assertSame( 1, $this->count_posts_with( '_pkiw_checkin_untappd_id', '1001' ) );
		$this->assertSame( 1, $this->count_posts_with( '_pkiw_checkin_untappd_id', '1002' ) );
	}

	/**
	 * Foursquare goes through the same base lookup, so drafts block it too.
	 *
	 * @dataProvider blocking_status_provider
	 *
	 * @param string $status Status of the earlier import.
	 */
	public function test_foursquare_sync_skips_an_earlier_import_in_any_status( string $status ): void {
		$post_id = self::factory()->post->create( [ 'post_status' => $status ] );
		update_post_meta( $post_id, '_pkiw_checkin_foursquare_id', 'fsq-checkin-1' );

		$result = ( new Foursquare_Checkin_Sync() )->import_checkins( 50 );

		$this->assertSame( 0, $result['imported'], "A {$status} Foursquare import was imported again." );
		$this->assertSame( 1, $result['skipped'] );
	}

	public function test_foursquare_sync_twice_imports_the_checkin_once(): void {
		$first  = ( new Foursquare_Checkin_Sync() )->import_checkins( 50 );
		$second = ( new Foursquare_Checkin_Sync() )->import_checkins( 50 );

		$this->assertSame( 1, $first['imported'] );
		$this->assertSame( 0, $second['imported'] );
		$this->assertSame( 1, $second['skipped'] );
		$this->assertSame( 1, $this->count_posts_with( '_pkiw_checkin_foursquare_id', 'fsq-checkin-1' ) );
	}

	public function test_foursquare_sync_skips_a_checkin_the_import_manager_created(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		update_post_meta( $post_id, '_pkiw_import_source_id', 'foursquare:fsq-checkin-1' );

		$result = ( new Foursquare_Checkin_Sync() )->import_checkins( 50 );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	/**
	 * Statuses that 'post_status' => 'publish' (get_posts' default) missed.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function blocking_status_provider(): array {
		return [
			'draft'   => [ 'draft' ],
			'pending' => [ 'pending' ],
			'private' => [ 'private' ],
			'trash'   => [ 'trash' ],
		];
	}

	/**
	 * Count posts of any status with a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return int
	 */
	private function count_posts_with( string $key, string $value ): int {
		return count(
			get_posts(
				[
					'post_type'   => 'any',
					'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
					'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}

	/**
	 * An Untappd check-in as /user/checkins returns it.
	 *
	 * @param int    $id   Check-in ID.
	 * @param string $beer Beer name.
	 * @return array<string, mixed>
	 */
	private function untappd_checkin( int $id, string $beer ): array {
		return [
			'checkin_id'      => $id,
			'checkin_comment' => 'Tasty.',
			'rating_score'    => 4.5,
			'created_at'      => 'Sat, 12 Sep 2026 20:00:00 +0000',
			'beer'            => [
				'bid'        => $id + 5000,
				'beer_name'  => $beer,
				'beer_style' => 'IPA',
				'beer_abv'   => 8,
			],
			'brewery'         => [
				'brewery_id'   => 77,
				'brewery_name' => 'Test Brewing',
				'country_name' => 'United States',
			],
			'venue'           => [
				'venue_name' => 'The Taproom',
				'location'   => [
					'lat' => 38.58,
					'lng' => -121.49,
				],
			],
		];
	}

	/**
	 * A Foursquare check-in as users/self/checkins returns it.
	 *
	 * @param string $id    Check-in ID.
	 * @param string $venue Venue name.
	 * @return array<string, mixed>
	 */
	private function foursquare_checkin( string $id, string $venue ): array {
		return [
			'id'        => $id,
			'createdAt' => 1789243200,
			'shout'     => 'Coffee.',
			'venue'     => [
				'id'       => 'venue-' . $id,
				'name'     => $venue,
				'location' => [
					'address' => '1 Main St',
					'city'    => 'Oakland',
					'lat'     => 37.8,
					'lng'     => -122.27,
				],
			],
		];
	}
}

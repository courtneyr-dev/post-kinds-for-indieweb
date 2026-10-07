<?php
/**
 * Check-in sync imports get an author when no user is logged in.
 *
 * Foursquare_Checkin_Sync::import_checkin() and
 * Untappd_Checkin_Sync::import_checkin() called wp_insert_post() without
 * post_author, so WordPress used the current user: 0 from WP-Cron or a
 * REST call with no session. They now pick the author the way
 * Import_Manager does (#218, #284).
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\Sync\Foursquare_Checkin_Sync;
use PKIW\Sync\Untappd_Checkin_Sync;
use PKIW\Tests\ApiTestCase;

/**
 * Authorship of posts the check-in sync classes create.
 */
final class CheckinSyncAuthorTest extends ApiTestCase {

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
				'foursquare' => [ 'access_token' => 'fake-foursquare-token' ],
			]
		);

		$this->mock_http_response(
			'api.untappd.com/v4/user/checkins/testuser',
			[
				'response' => [
					'checkins' => [
						'items' => [
							[
								'checkin_id' => 1001,
								'created_at' => 'Sat, 12 Sep 2026 20:00:00 +0000',
								'beer'       => [ 'beer_name' => 'Pliny the Elder' ],
							],
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
							[
								'id'        => 'fsq-checkin-1',
								'createdAt' => 1789243200,
								'venue'     => [
									'id'   => 'venue-fsq-checkin-1',
									'name' => 'Blue Bottle Coffee',
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * A scheduled run uses the configured default author.
	 *
	 * @dataProvider service_provider
	 *
	 * @param string $service Sync class.
	 */
	public function test_cron_import_uses_the_configured_default_author( string $service ): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( 'pkiw_default_author', $editor );

		$this->assertSame( 1, ( new $service() )->import_checkins( 50 )['imported'] );

		$this->assertSame( $editor, $this->imported_post_author() );
	}

	/**
	 * A logged-in user who can create posts still authors their own import.
	 *
	 * @dataProvider service_provider
	 *
	 * @param string $service Sync class.
	 */
	public function test_manual_import_keeps_the_importing_user( string $service ): void {
		$importer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_option( 'pkiw_default_author', self::factory()->user->create( [ 'role' => 'editor' ] ) );
		wp_set_current_user( $importer );

		( new $service() )->import_checkins( 50 );

		$this->assertSame( $importer, $this->imported_post_author() );
	}

	/**
	 * With no user who can create posts, each sync skips the check-in and
	 * logs why, so the run shows more than an error count.
	 *
	 * @dataProvider service_provider
	 *
	 * @param string $service Sync class.
	 */
	public function test_import_with_no_author_skips_and_logs_why( string $service ): void {
		add_filter(
			'user_has_cap',
			static fn( array $allcaps ): array => [ 'edit_posts' => false ] + $allcaps
		);

		$sync = $this->recording_sync( $service );

		$this->assertSame( 0, $sync->import_checkins( 50 )['imported'] );
		$this->assertContains( 'No user can author imported posts', $sync->logged );
	}

	/**
	 * Both check-in sync classes that create posts from fetched check-ins.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function service_provider(): array {
		return [
			'foursquare' => [ Foursquare_Checkin_Sync::class ],
			'untappd'    => [ Untappd_Checkin_Sync::class ],
		];
	}

	/**
	 * A sync instance that keeps its log messages.
	 *
	 * @param string $service Sync class.
	 * @return Foursquare_Checkin_Sync|Untappd_Checkin_Sync
	 */
	private function recording_sync( string $service ): object {
		if ( Untappd_Checkin_Sync::class === $service ) {
			return new class() extends Untappd_Checkin_Sync {
				/**
				 * @var string[]
				 */
				public array $logged = [];

				protected function log( string $message, array $context = [] ): void {
					$this->logged[] = $message;
				}
			};
		}

		return new class() extends Foursquare_Checkin_Sync {
			/**
			 * @var string[]
			 */
			public array $logged = [];

			protected function log( string $message, array $context = [] ): void {
				$this->logged[] = $message;
			}
		};
	}

	/**
	 * Author of the one imported check-in.
	 *
	 * @return int
	 */
	private function imported_post_author(): int {
		$ids = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => 'any',
				'meta_key'    => '_pkiw_imported_from', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		$this->assertCount( 1, $ids );

		return (int) get_post_field( 'post_author', $ids[0] );
	}
}

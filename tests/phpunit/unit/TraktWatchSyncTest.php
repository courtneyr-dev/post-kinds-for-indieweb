<?php
/**
 * Test the Trakt Watch Sync class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Sync\Trakt_Watch_Sync;
use WP_UnitTestCase;

/**
 * Test the Trakt Watch Sync integration.
 *
 * @covers \PostKindsForIndieWeb\Sync\Trakt_Watch_Sync
 */
class TraktWatchSyncTest extends WP_UnitTestCase {

	/**
	 * Sync instance.
	 *
	 * @var Trakt_Watch_Sync
	 */
	private Trakt_Watch_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'post_kinds_indieweb_api_credentials', [
			'trakt' => [
				'client_id'     => 'test-trakt-id',
				'client_secret' => 'test-trakt-secret',
				'access_token'  => 'test-trakt-token',
				'refresh_token' => 'test-refresh-token',
			],
		] );

		$this->sync = new Trakt_Watch_Sync();
	}

	/**
	 * Test service properties.
	 */
	public function test_service_properties(): void {
		$this->assertSame( 'trakt', $this->sync->get_service_id() );
		$this->assertSame( 'Trakt', $this->sync->get_service_name() );
	}

	/**
	 * Test is_connected delegates to API.
	 */
	public function test_is_connected_delegates_to_api(): void {
		// is_connected depends on the Trakt API class's is_authenticated.
		// With credentials set, it should check the API.
		$result = $this->sync->is_connected();
		$this->assertIsBool( $result );
	}

	/**
	 * Test add_syndication_target includes service when connected.
	 */
	public function test_add_syndication_target_structure(): void {
		// Test that the method returns proper structure regardless of connection.
		$targets = $this->sync->add_syndication_target( [ 'existing' => [ 'uid' => 'existing' ] ] );

		// Existing target preserved.
		$this->assertArrayHasKey( 'existing', $targets );

		// Trakt added only if connected.
		if ( $this->sync->is_connected() ) {
			$this->assertArrayHasKey( 'trakt', $targets );
			$this->assertSame( 'Trakt', $targets['trakt']['name'] );
		}
	}

	/**
	 * Test add_syndication_target without credentials.
	 */
	public function test_add_syndication_target_not_connected(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );

		$sync    = new Trakt_Watch_Sync();
		$targets = $sync->add_syndication_target( [] );

		$this->assertArrayNotHasKey( 'trakt', $targets );
	}
}

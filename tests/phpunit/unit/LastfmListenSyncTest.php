<?php
/**
 * Test the Last.fm Listen Sync class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Sync\Lastfm_Listen_Sync;
use WP_UnitTestCase;

/**
 * Test the Last.fm Listen Sync integration.
 *
 * @covers \PostKindsForIndieWeb\Sync\Lastfm_Listen_Sync
 */
class LastfmListenSyncTest extends WP_UnitTestCase {

	/**
	 * Sync instance.
	 *
	 * @var Lastfm_Listen_Sync
	 */
	private Lastfm_Listen_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'post_kinds_indieweb_api_credentials', [
			'lastfm' => [
				'api_key'     => 'test-api-key',
				'api_secret'  => 'test-api-secret',
				'session_key' => 'test-session-key',
				'username'    => 'testuser',
			],
		] );

		$this->sync = new Lastfm_Listen_Sync();
	}

	/**
	 * Test service properties.
	 */
	public function test_service_properties(): void {
		$this->assertSame( 'lastfm', $this->sync->get_service_id() );
		$this->assertSame( 'Last.fm', $this->sync->get_service_name() );
	}

	/**
	 * Test is_connected returns true with session key.
	 */
	public function test_is_connected_true(): void {
		$this->assertTrue( $this->sync->is_connected() );
	}

	/**
	 * Test is_connected returns false without session key.
	 */
	public function test_is_connected_false(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'lastfm' => [
				'api_key' => 'test-key',
			],
		] );

		$sync = new Lastfm_Listen_Sync();
		$this->assertFalse( $sync->is_connected() );
	}

	/**
	 * Test get_username returns stored username.
	 */
	public function test_get_username(): void {
		$this->assertSame( 'testuser', $this->sync->get_username() );
	}

	/**
	 * Test get_username returns empty when not set.
	 */
	public function test_get_username_empty(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [
			'lastfm' => [ 'session_key' => 'key' ],
		] );

		$sync = new Lastfm_Listen_Sync();
		$this->assertSame( '', $sync->get_username() );
	}

	/**
	 * Test add_syndication_target when connected.
	 */
	public function test_add_syndication_target_connected(): void {
		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayHasKey( 'lastfm', $targets );
		$this->assertSame( 'Last.fm', $targets['lastfm']['name'] );
	}

	/**
	 * Test add_syndication_target when disconnected.
	 */
	public function test_add_syndication_target_disconnected(): void {
		update_option( 'post_kinds_indieweb_api_credentials', [] );

		$sync    = new Lastfm_Listen_Sync();
		$targets = $sync->add_syndication_target( [] );

		$this->assertArrayNotHasKey( 'lastfm', $targets );
	}
}

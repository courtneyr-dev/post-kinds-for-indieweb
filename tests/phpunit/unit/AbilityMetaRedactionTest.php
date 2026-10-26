<?php
/**
 * The post-kinds/get-post-meta ability must apply the same location
 * redaction Meta_Fields::redact_location_meta() applies to REST responses
 * (finding K1) — a requester who cannot edit the post must not receive
 * precise location fields the post's visibility tier hides.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Unit;

use PKIW\Abilities\Core_Abilities;
use PKIW\Import_Manager;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @covers \PKIW\Abilities\Core_Abilities::execute_get_post_meta
 */
final class AbilityMetaRedactionTest extends WP_UnitTestCase {

	private Core_Abilities $abilities;
	private int $post_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->register_taxonomy();

		$this->abilities = Core_Abilities::instance();
		$this->author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->post_id   = self::factory()->post->create( [ 'post_author' => $this->author_id ] );
		wp_set_post_terms( $this->post_id, [ 'checkin' ], Taxonomy::TAXONOMY );

		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'geo_latitude', '40.20192' );
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'geo_longitude', '-77.19256' );
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'checkin_address', '123 Sentinel St' );
	}

	/**
	 * Run a callback on an admin screen, where editors get the full location.
	 *
	 * @param callable $callback Callback.
	 * @return mixed What the callback returns.
	 */
	private function on_admin_screen( callable $callback ) {
		set_current_screen( 'edit-post' );
		try {
			return $callback();
		} finally {
			$GLOBALS['current_screen'] = null;
		}
	}

	public function test_subscriber_does_not_see_private_location_fields(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['meta']['geo_latitude'] );
		$this->assertEquals( 0, $result['meta']['geo_longitude'] );
		$this->assertSame( '', $result['meta']['checkin_address'] );
	}

	public function test_subscriber_requesting_location_keys_explicitly_is_still_redacted(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->abilities->execute_get_post_meta(
			[
				'post_id'   => $this->post_id,
				'meta_keys' => [ 'geo_latitude', 'geo_longitude', 'checkin_address' ],
			]
		);

		$this->assertEquals( 0, $result['meta']['geo_latitude'] );
		$this->assertEquals( 0, $result['meta']['geo_longitude'] );
		$this->assertSame( '', $result['meta']['checkin_address'] );
	}

	public function test_author_sees_full_location_on_an_admin_screen(): void {
		wp_set_current_user( $this->author_id );

		$result = $this->on_admin_screen( fn() => $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] ) );

		$this->assertSame( '40.20192', $result['meta']['geo_latitude'] );
		$this->assertSame( '-77.19256', $result['meta']['geo_longitude'] );
		$this->assertSame( '123 Sentinel St', $result['meta']['checkin_address'] );
	}

	public function test_user_with_edit_post_sees_full_location_on_an_admin_screen(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = $this->on_admin_screen( fn() => $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] ) );

		$this->assertSame( '40.20192', $result['meta']['geo_latitude'] );
		$this->assertSame( '123 Sentinel St', $result['meta']['checkin_address'] );
	}

	public function test_user_with_edit_post_gets_the_visitor_location_outside_an_editing_context(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		// The abilities run route and the MCP adapter carry no context=edit.
		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		$this->assertEquals( 0, $result['meta']['geo_latitude'] );
		$this->assertSame( '', $result['meta']['checkin_address'] );
	}

	public function test_author_with_read_post_but_not_edit_post_gets_no_protected_kind_meta(): void {
		$other_author = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_update_post(
			[
				'ID'            => $this->post_id,
				'post_status'   => 'publish',
				'post_author'   => $other_author,
				'post_password' => 'secret',
			]
		);
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'checkin_name', 'Hidden Venue' );
		wp_set_current_user( $this->author_id );

		$all      = $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );
		$explicit = $this->abilities->execute_get_post_meta(
			[
				'post_id'   => $this->post_id,
				'meta_keys' => [ 'checkin_name', 'geo_latitude' ],
			]
		);

		$this->assertSame( [], $all['meta'] );
		$this->assertSame( [], $explicit['meta'] );
	}

	public function test_editor_gets_protected_kind_meta(): void {
		wp_update_post(
			[
				'ID'            => $this->post_id,
				'post_status'   => 'publish',
				'post_password' => 'secret',
			]
		);
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'checkin_name', 'Hidden Venue' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = $this->on_admin_screen( fn() => $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] ) );

		$this->assertSame( 'Hidden Venue', $result['meta']['checkin_name'] );
		$this->assertSame( '40.20192', $result['meta']['geo_latitude'] );
	}

	public function test_public_post_keeps_kind_meta(): void {
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'cite_name', 'Visible Citation' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		$this->assertSame( 'Visible Citation', $result['meta']['cite_name'] );
	}

	public function test_stored_meta_is_untouched_by_redaction(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		$this->assertSame( '40.20192', get_post_meta( $this->post_id, Meta_Fields::PREFIX . 'geo_latitude', true ) );
	}

	/**
	 * Review round 1, Critical 1: the Swarm importer writes precise
	 * location under legacy, unregistered keys —
	 * `_pkiw_checkin_latitude`, `_pkiw_checkin_longitude`,
	 * `_pkiw_checkin_venue`, `_pkiw_checkin_venue_id`
	 * (Import_Manager::build_checkin_payload(), read at
	 * class-import-manager.php:1300-1304) — alongside the registered
	 * `_pkiw_geo_latitude`/`_pkiw_geo_longitude`/`_pkiw_checkin_address`.
	 * REST never exposes the legacy keys because they were never
	 * registered; the ability must match that, not just redact the
	 * registered ones.
	 */
	public function test_subscriber_does_not_see_legacy_imported_checkin_keys_for_private_post(): void {
		$import_manager = new Import_Manager();
		[ , $meta ]      = ( new ReflectionMethod( $import_manager, 'build_checkin_payload' ) )->invoke(
			$import_manager,
			[
				'venue_name' => 'Cafe',
				'address'    => '123 Sentinel St',
				'latitude'   => 40.20192,
				'longitude'  => -77.19256,
				'venue_id'   => '4b0588f1f964a520',
				'shout'      => 'hi',
			]
		);
		foreach ( $meta as $full_key => $value ) {
			update_post_meta( $this->post_id, $full_key, $value );
		}
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		foreach ( [ 'checkin_latitude', 'checkin_longitude', 'checkin_venue', 'checkin_venue_id', 'checkin_shout' ] as $legacy_key ) {
			$this->assertArrayNotHasKey( $legacy_key, $result['meta'], "unregistered legacy key '{$legacy_key}' must not be exposed" );
		}
		// Registered fields still redact, same as before.
		$this->assertEquals( 0, $result['meta']['geo_latitude'] );

		// The explicit meta_keys branch must refuse the same legacy keys by name.
		$explicit = $this->abilities->execute_get_post_meta(
			[
				'post_id'   => $this->post_id,
				'meta_keys' => [ 'checkin_latitude', 'checkin_venue_id', 'geo_latitude' ],
			]
		);
		$this->assertArrayNotHasKey( 'checkin_latitude', $explicit['meta'] );
		$this->assertArrayNotHasKey( 'checkin_venue_id', $explicit['meta'] );
		$this->assertEquals( 0, $explicit['meta']['geo_latitude'] );
	}
}

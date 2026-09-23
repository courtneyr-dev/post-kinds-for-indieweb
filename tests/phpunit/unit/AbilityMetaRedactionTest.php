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
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
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

	public function test_author_sees_full_location(): void {
		wp_set_current_user( $this->author_id );

		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		$this->assertSame( '40.20192', $result['meta']['geo_latitude'] );
		$this->assertSame( '-77.19256', $result['meta']['geo_longitude'] );
		$this->assertSame( '123 Sentinel St', $result['meta']['checkin_address'] );
	}

	public function test_user_with_edit_post_sees_full_location(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = $this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		$this->assertSame( '40.20192', $result['meta']['geo_latitude'] );
		$this->assertSame( '123 Sentinel St', $result['meta']['checkin_address'] );
	}

	public function test_stored_meta_is_untouched_by_redaction(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->abilities->execute_get_post_meta( [ 'post_id' => $this->post_id ] );

		$this->assertSame( '40.20192', get_post_meta( $this->post_id, Meta_Fields::PREFIX . 'geo_latitude', true ) );
	}
}

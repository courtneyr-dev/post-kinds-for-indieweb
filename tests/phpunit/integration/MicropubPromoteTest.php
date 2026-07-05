<?php
/**
 * Micropub `pkiw-promote` property maps to the pkiw_promote meta.
 *
 * @package PostKindsForIndieWeb
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class MicropubPromoteTest extends WP_UnitTestCase {

	public function test_pkiw_promote_property_sets_meta(): void {
		$id = self::factory()->post->create();
		\PostKindsForIndieWeb\Micropub_Content_Builder::apply_promote( $id, [ 'pkiw-promote' => [ '1' ] ] );
		$this->assertSame( '1', get_post_meta( $id, 'pkiw_promote', true ) );
	}

	public function test_string_true_sets_meta(): void {
		$id = self::factory()->post->create();
		\PostKindsForIndieWeb\Micropub_Content_Builder::apply_promote( $id, [ 'pkiw-promote' => [ 'true' ] ] );
		$this->assertSame( '1', get_post_meta( $id, 'pkiw_promote', true ) );
	}

	public function test_absent_property_leaves_meta_unset(): void {
		$id = self::factory()->post->create();
		\PostKindsForIndieWeb\Micropub_Content_Builder::apply_promote( $id, [] );
		$this->assertSame( '', get_post_meta( $id, 'pkiw_promote', true ) );
	}

	public function test_falsey_property_leaves_meta_unset(): void {
		$id = self::factory()->post->create();
		\PostKindsForIndieWeb\Micropub_Content_Builder::apply_promote( $id, [ 'pkiw-promote' => [ 'false' ] ] );
		$this->assertSame( '', get_post_meta( $id, 'pkiw_promote', true ) );
	}
}

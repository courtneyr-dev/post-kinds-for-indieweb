<?php
/**
 * Post surface classification + cached meta.
 *
 * @package PostKindsForIndieWeb
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class PostSurfaceMetaTest extends WP_UnitTestCase {

	private function post_with_kind( string $slug ): int {
		$id = self::factory()->post->create();
		wp_set_object_terms( $id, $slug, 'kind' );
		return $id;
	}

	public function test_non_stream_kind_is_main(): void {
		add_filter( 'pk_stream_kinds', static fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'article' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_stream_kind_is_stream(): void {
		add_filter( 'pk_stream_kinds', static fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'stream', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_promote_forces_main(): void {
		add_filter( 'pk_stream_kinds', static fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'checkin' );
		update_post_meta( $id, 'pk_promote', 1 );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_empty_default_filter_is_main(): void {
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_pk_post_surface_filter_overrides(): void {
		add_filter( 'pk_stream_kinds', static fn() => [ 'checkin' ] );
		add_filter( 'pk_post_surface', static fn() => 'main' );
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}
}

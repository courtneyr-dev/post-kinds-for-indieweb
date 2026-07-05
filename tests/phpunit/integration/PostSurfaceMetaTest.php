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
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'article' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_stream_kind_is_stream(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'stream', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_promote_forces_main(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$id = $this->post_with_kind( 'checkin' );
		update_post_meta( $id, 'pkiw_promote', 1 );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_empty_default_filter_is_main(): void {
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_pkiw_post_surface_filter_overrides(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		add_filter( 'pkiw_post_surface', static fn() => 'main' );
		$id = $this->post_with_kind( 'checkin' );
		$this->assertSame( 'main', \PostKindsForIndieWeb\Post_Surface::get( $id ) );
	}

	public function test_save_caches_surface_meta(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$id = self::factory()->post->create();
		wp_set_object_terms( $id, 'checkin', 'kind' );
		wp_update_post( [ 'ID' => $id ] ); // fire save_post
		$this->assertSame( 'stream', get_post_meta( $id, '_pkiw_surface', true ) );
	}

	public function test_backfill_recomputes_all(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$a = self::factory()->post->create();
		wp_set_object_terms( $a, 'checkin', 'kind' );
		$b = self::factory()->post->create();
		wp_set_object_terms( $b, 'article', 'kind' );
		delete_post_meta( $a, '_pkiw_surface' );
		delete_post_meta( $b, '_pkiw_surface' );

		$count = \PostKindsForIndieWeb\Post_Surface::backfill();

		$this->assertSame( 2, $count );
		$this->assertSame( 'stream', get_post_meta( $a, '_pkiw_surface', true ) );
		$this->assertSame( 'main', get_post_meta( $b, '_pkiw_surface', true ) );
	}

	public function test_register_meta_exposes_pk_promote_in_rest(): void {
		// Invoke registration directly — the test framework resets the meta
		// registry between tests, so we exercise register_meta()'s own output
		// rather than relying on the bootstrap init registration persisting.
		( new \PostKindsForIndieWeb\Post_Surface() )->register_meta();
		// register_post_meta() registers under object subtype 'post'.
		$this->assertTrue( registered_meta_key_exists( 'post', 'pkiw_promote', 'post' ) );
		$keys = get_registered_meta_keys( 'post', 'post' );
		$this->assertTrue( $keys['pkiw_promote']['show_in_rest'] );
	}
}

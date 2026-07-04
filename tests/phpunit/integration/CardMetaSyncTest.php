<?php
/**
 * Card_Meta_Sync integration coverage.
 *
 * @package PostKindsForIndieWeb
 */

declare(strict_types=1);

/**
 * Verifies the first kind-card block's attrs mirror into _postkind_ meta
 * on save, and that empty attrs never clobber existing meta.
 *
 * @group integration
 */
final class CardMetaSyncTest extends WP_UnitTestCase {

	public function test_read_card_attrs_mirror_into_postkind_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","authorName":"Rebecca Yarros","isbn":"9781649374042","publisher":"Entangled","pageCount":517} /-->',
		] );

		// save_post fired during create; assert the mirror.
		$this->assertSame( 'Fourth Wing', get_post_meta( $post_id, '_postkind_read_title', true ) );
		$this->assertSame( 'Rebecca Yarros', get_post_meta( $post_id, '_postkind_read_author', true ) );
		$this->assertSame( '9781649374042', get_post_meta( $post_id, '_postkind_read_isbn', true ) );
		$this->assertSame( 'Entangled', get_post_meta( $post_id, '_postkind_read_publisher', true ) );
		$this->assertSame( '517', get_post_meta( $post_id, '_postkind_read_pages', true ) );
	}

	public function test_manual_meta_not_clobbered_by_empty_attr(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing"} /-->',
		] );
		update_post_meta( $post_id, '_postkind_read_isbn', '9781649374042' );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );

		$this->assertSame( '9781649374042', get_post_meta( $post_id, '_postkind_read_isbn', true ), 'empty attr must not erase existing meta' );
	}
}

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

	public function test_stale_asin_cleared_when_isbn_changes(): void {
		// Bootstrap's default stub is a no-op passthrough (see
		// tests/phpunit/bootstrap.php), so after this resave read_asin
		// stays blank — proving Card_Meta_Sync itself cleared the stale
		// value rather than the completion cascade re-deriving one.
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );
		update_post_meta( $post_id, '_postkind_read_asin', '1649374046' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9780316219280"} /-->',
			]
		);

		$this->assertSame( '9780316219280', get_post_meta( $post_id, '_postkind_read_isbn', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_postkind_read_asin', true ), 'stale asin must be cleared when the isbn changes' );
	}

	public function test_asin_re_derived_from_new_isbn_after_change(): void {
		// Passthrough-plus-derive stub: proves complete_on_save() (which
		// runs after Card_Meta_Sync at save_post:30) sees the cleared
		// read_asin as blank and re-fills it from the new ISBN, rather
		// than the stale ASIN surviving the resave.
		add_filter( 'pkiw_book_completion_service', static function () {
			return new class() {
				public function complete( array $book ): array {
					$book['asin'] = '0316219282'; // Derived ASIN for ISBN B.
					return $book;
				}
			};
		} );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );
		update_post_meta( $post_id, '_postkind_read_asin', '1649374046' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9780316219280"} /-->',
			]
		);

		$this->assertSame( '9780316219280', get_post_meta( $post_id, '_postkind_read_isbn', true ) );
		$this->assertSame( '0316219282', get_post_meta( $post_id, '_postkind_read_asin', true ), 'asin must be re-derived from the new isbn' );
	}
}

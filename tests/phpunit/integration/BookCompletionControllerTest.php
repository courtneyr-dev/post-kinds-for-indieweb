<?php
/**
 * Book_Completion_Controller integration coverage.
 *
 * @package PostKindsForIndieWeb
 */

declare(strict_types=1);

/**
 * Verifies the REST completion route auth-gates correctly, completes via
 * the injectable service seam, and that save-time completion fills only
 * blank read meta (never overwrites values the card/author already set).
 *
 * @group integration
 */
final class BookCompletionControllerTest extends WP_UnitTestCase {

	public function test_rest_route_requires_edit_posts(): void {
		$request  = new WP_REST_Request( 'POST', '/pkiw/v1/book-complete' );
		$request->set_body_params( [ 'isbn' => '9781649374042' ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_rest_route_completes_for_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		// Short-circuit outbound APIs deterministically via the injectable seam:
		add_filter( 'pkiw_book_completion_service', static function () {
			$stub = new class {
				public function complete( array $book ): array {
					return array_merge( [ 'title' => 'Fourth Wing', 'asin' => '1649374046' ], $book );
				}
			};
			return $stub;
		} );

		$request = new WP_REST_Request( 'POST', '/pkiw/v1/book-complete' );
		$request->set_body_params( [ 'isbn' => '9781649374042' ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Fourth Wing', $response->get_data()['title'] );
		$this->assertSame( '1649374046', $response->get_data()['asin'] );
	}

	public function test_save_fills_blank_meta_only(): void {
		add_filter( 'pkiw_book_completion_service', static function () {
			return new class {
				public function complete( array $book ): array {
					return array_merge( $book, [ 'publisher' => 'Entangled', 'asin' => '1649374046' ] );
				}
			};
		} );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );

		$this->assertSame( 'Entangled', get_post_meta( $post_id, '_postkind_read_publisher', true ) );
		$this->assertSame( '1649374046', get_post_meta( $post_id, '_postkind_read_asin', true ) );
	}
}

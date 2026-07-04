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

	public function test_rest_route_drops_unknown_params(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		// Passthrough stub: whatever reaches complete() comes straight back,
		// so any non-canonical key surviving into the response proves a leak.
		add_filter( 'pkiw_book_completion_service', static function () {
			return new class {
				public function complete( array $book ): array {
					return $book;
				}
			};
		} );

		$request = new WP_REST_Request( 'POST', '/pkiw/v1/book-complete' );
		$request->set_body_params(
			[
				'isbn' => '9781649374042',
				'evil' => '<script>x</script>',
			]
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'evil', $data, 'unknown params must not be reflected in the response' );
		$this->assertSame( [ 'isbn' => '9781649374042' ], $data, 'response must contain only canonical keys' );
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

	public function test_resave_with_fully_populated_meta_never_calls_complete(): void {
		$calls = 0;
		add_filter( 'pkiw_book_completion_service', static function () use ( &$calls ) {
			return new class( $calls ) {
				private $calls;
				public function __construct( &$calls ) {
					$this->calls = &$calls;
				}
				public function complete( array $book ): array {
					++$this->calls;
					return $book;
				}
			};
		} );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );

		// Fully populate every completable field before the resave under test.
		update_post_meta( $post_id, '_postkind_read_author', 'Rebecca Yarros' );
		update_post_meta( $post_id, '_postkind_read_publisher', 'Entangled' );
		update_post_meta( $post_id, '_postkind_read_publish_date', '2023-05-02' );
		update_post_meta( $post_id, '_postkind_read_pages', '517' );
		update_post_meta( $post_id, '_postkind_read_cover', 'https://example.com/cover.jpg' );
		update_post_meta( $post_id, '_postkind_read_url', 'https://example.com/book' );
		update_post_meta( $post_id, '_postkind_read_asin', '1649374046' );

		$calls = 0; // Reset after create()'s own save_post fired completion.

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );

		$this->assertSame( 0, $calls, 'complete() must not be called when every completable field is already filled' );
	}

	public function test_isbn_change_clears_asin_and_completion_runs_again(): void {
		// Verifies the F2/F3 interplay: Card_Meta_Sync@25 clears the stale
		// asin when the isbn changes, which makes read_asin blank again,
		// so Book_Completion_Controller@30 must run completion instead of
		// short-circuiting.
		$calls = 0;
		add_filter( 'pkiw_book_completion_service', static function () use ( &$calls ) {
			return new class( $calls ) {
				private $calls;
				public function __construct( &$calls ) {
					$this->calls = &$calls;
				}
				public function complete( array $book ): array {
					++$this->calls;
					$book['asin'] = '0316219282';
					return $book;
				}
			};
		} );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );
		update_post_meta( $post_id, '_postkind_read_author', 'Rebecca Yarros' );
		update_post_meta( $post_id, '_postkind_read_publisher', 'Entangled' );
		update_post_meta( $post_id, '_postkind_read_publish_date', '2023-05-02' );
		update_post_meta( $post_id, '_postkind_read_pages', '517' );
		update_post_meta( $post_id, '_postkind_read_cover', 'https://example.com/cover.jpg' );
		update_post_meta( $post_id, '_postkind_read_url', 'https://example.com/book' );
		update_post_meta( $post_id, '_postkind_read_asin', '1649374046' );

		$calls = 0; // Reset after create()'s own save_post fired completion.

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9780316219280"} /-->',
			]
		);

		$this->assertSame( 1, $calls, 'a changed isbn clears asin, so completion must run again' );
		$this->assertSame( '0316219282', get_post_meta( $post_id, '_postkind_read_asin', true ) );
	}
}

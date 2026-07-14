<?php
// tests/phpunit/unit/BookCompletionTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PKIW\Book_Completion;

final class BookCompletionTest extends TestCase {

	private function completion_with_openlibrary( ?array $ol_book ): Book_Completion {
		$open_library = $this->createStub( \PKIW\APIs\OpenLibrary::class );
		$open_library->method( 'get_by_isbn' )->willReturn( $ol_book );
		$google = $this->createStub( \PKIW\APIs\GoogleBooks::class );
		$google->method( 'search_by_title' )->willReturn( [] );
		$hardcover = $this->createStub( \PKIW\APIs\Hardcover::class );
		$hardcover->method( 'is_configured' )->willReturn( false );
		return new Book_Completion( $open_library, $google, $hardcover );
	}

	public function test_isbn_fills_everything_missing(): void {
		$svc = $this->completion_with_openlibrary( [
			'title'           => 'Fourth Wing',
			'authors'         => [ 'Rebecca Yarros' ],
			'publishers'      => [ 'Entangled' ],
			'publish_date'    => '2023-05-02',
			'number_of_pages' => 517,
			'cover'           => 'https://covers.openlibrary.org/b/id/1-M.jpg',
		] );

		$out = $svc->complete( [ 'isbn' => '9781649374042' ] );

		$this->assertSame( 'Fourth Wing', $out['title'] );
		$this->assertSame( 'Rebecca Yarros', $out['author'] );
		$this->assertSame( 'Entangled', $out['publisher'] );
		$this->assertSame( '517', $out['pages'] );
		$this->assertSame( '1649374046', $out['asin'], 'ISBN-10 derived as print ASIN' );
	}

	public function test_user_values_never_overwritten(): void {
		$svc = $this->completion_with_openlibrary( [ 'title' => 'API Title', 'authors' => [ 'API Author' ] ] );
		$out = $svc->complete( [ 'isbn' => '9781649374042', 'title' => 'My Title' ] );
		$this->assertSame( 'My Title', $out['title'] );
	}

	public function test_amazon_url_provides_asin_without_isbn(): void {
		$svc = $this->completion_with_openlibrary( null );
		$out = $svc->complete( [ 'url' => 'https://www.amazon.com/dp/B0BGYV1G97' ] );
		$this->assertSame( 'B0BGYV1G97', $out['asin'] );
	}

	public function test_offline_apis_lose_no_input_data(): void {
		$svc = $this->completion_with_openlibrary( null );
		$in  = [ 'isbn' => '9781649374042', 'title' => 'Fourth Wing' ];
		$this->assertSame( 'Fourth Wing', $svc->complete( $in )['title'], 'API failure must never drop input' );
	}
}

<?php
// tests/phpunit/unit/IsbnTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PostKindsForIndieWeb\Isbn;

final class IsbnTest extends TestCase {

	public function test_validate(): void {
		$this->assertTrue( Isbn::validate( '9781649374042' ) );
		$this->assertTrue( Isbn::validate( '1-64937-404-6' ) );
		$this->assertFalse( Isbn::validate( '9781649374043' ) ); // bad check digit
		$this->assertFalse( Isbn::validate( 'B0BGYV1G97' ) );    // Kindle ASIN, not ISBN
	}

	public function test_to10_from_13(): void {
		$this->assertSame( '1649374046', Isbn::to10( '9781649374042' ) );
		$this->assertNull( Isbn::to10( '9791234567896' ) ); // 979- has no ISBN-10 form
	}

	public function test_to13_from_10(): void {
		$this->assertSame( '9781649374042', Isbn::to13( '1649374046' ) );
	}

	public function test_asin_from_amazon_urls(): void {
		$this->assertSame( 'B0BGYV1G97', Isbn::asin_from_url( 'https://www.amazon.com/Fourth-Wing-Empyrean-Rebecca-Yarros-ebook/dp/B0BGYV1G97/ref=x' ) );
		$this->assertSame( '1649374046', Isbn::asin_from_url( 'https://www.amazon.com/gp/product/1649374046' ) );
		$this->assertSame( 'B0BGYV1G97', Isbn::asin_from_url( 'https://read.amazon.com/kp/embed?asin=B0BGYV1G97' ) );
		$this->assertNull( Isbn::asin_from_url( 'https://example.com/not-amazon' ) );
	}

	public function test_kindle_embed_url(): void {
		$this->assertSame(
			'https://read.amazon.com/kp/embed?asin=1649374046&preview=inline',
			Isbn::kindle_embed_url( '1649374046' )
		);
	}
}

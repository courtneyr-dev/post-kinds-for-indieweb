<?php
/**
 * API client normalizers must strip HTML tags from provider-controlled
 * string fields before they reach the admin lookup/search UI or the
 * import preview (finding K2) — a title like `<img src=x onerror=1>`
 * from an external API must render as literal text, never as markup.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Unit;

use PKIW\APIs\OpenLibrary;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * @covers \PKIW\APIs\API_Base::sanitize_normalized_result
 * @covers \PKIW\APIs\OpenLibrary::normalize_result
 */
final class ApiNormalizerStripsTagsTest extends WP_UnitTestCase {

	/**
	 * Invoke a protected instance method by name.
	 *
	 * No setAccessible(): Reflection needs it only below PHP 8.1, and this
	 * plugin requires PHP 8.2+.
	 *
	 * @param object            $object Instance to invoke the method on.
	 * @param string            $method Method name.
	 * @param array<int, mixed> $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke_protected( object $object, string $method, array $args = [] ) {
		return ( new ReflectionMethod( $object, $method ) )->invoke( $object, ...$args );
	}

	public function test_openlibrary_strips_tags_from_title(): void {
		$api = new OpenLibrary();

		$result = $this->invoke_protected(
			$api,
			'normalize_result',
			[ [ 'title' => 'A <img src=x onerror=1> B' ] ]
		);

		$this->assertStringNotContainsString( '<img', $result['title'] );
		$this->assertStringNotContainsString( '<', $result['title'] );
		$this->assertStringContainsString( 'A', $result['title'] );
		$this->assertStringContainsString( 'B', $result['title'] );
	}

	public function test_openlibrary_strips_tags_from_author_list(): void {
		$api = new OpenLibrary();

		$result = $this->invoke_protected(
			$api,
			'normalize_result',
			[
				[
					'title'       => 'A Book',
					'author_name' => [ 'Real Author', '<script>alert(1)</script>Evil Author' ],
				],
			]
		);

		$this->assertSame( 'Real Author', $result['authors'][0] );
		$this->assertStringNotContainsString( '<script', $result['authors'][1] );
	}

	public function test_openlibrary_cover_url_survives_stripping(): void {
		$api = new OpenLibrary();

		$result = $this->invoke_protected(
			$api,
			'normalize_result',
			[ [ 'title' => 'A Book', 'cover_i' => 12345 ] ] // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
		);

		$this->assertStringContainsString( 'covers.openlibrary.org', $result['cover'] );
	}
}

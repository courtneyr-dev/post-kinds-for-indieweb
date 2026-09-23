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

use PKIW\APIs\BoardGameGeek;
use PKIW\APIs\OpenLibrary;
use PKIW\APIs\RAWG;
use PKIW\APIs\TMDB;
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
			[
				[
					'title'   => 'A Book',
					'cover_i' => 12345,
				],
			]
		);

		$this->assertStringContainsString( 'covers.openlibrary.org', $result['cover'] );
	}

	/**
	 * Review round 1, Important 2: TMDB::search_movies() and search_tv()
	 * (the methods Admin::lookup_media() actually calls for the admin
	 * movie/TV lookup) call normalize_movie()/normalize_tv() directly and
	 * never went through normalize_result(), so the admin lookup path
	 * returned unstripped provider markup even though search()'s own
	 * results (via normalize_result()) were already clean.
	 */
	public function test_tmdb_search_movies_and_search_tv_strip_tags(): void {
		update_option( 'pkiw_api_credentials', [ 'tmdb' => [ 'enabled' => true, 'api_key' => 'fake-key-for-test' ] ] );
		add_filter(
			'pre_http_request',
			static function () {
				$payload = '<img src=x onerror=alert(1)>';
				$body    = [
					'results' => [
						[
							'id'             => 11,
							'media_type'     => 'movie',
							'title'          => 'M ' . $payload,
							'name'           => 'T ' . $payload,
							'release_date'   => '2020-01-01',
							'first_air_date' => '2020-01-01',
						],
					],
				];
				return [
					'headers'  => [],
					'body'     => wp_json_encode( $body ),
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			},
			1,
			0
		);

		$tmdb   = new TMDB();
		$movies = $tmdb->search_movies( 'probe' );
		$tv     = $tmdb->search_tv( 'probe' );

		$this->assertStringNotContainsString( '<img', $movies[0]['title'] );
		$this->assertStringNotContainsString( '<img', $tv[0]['title'] );
	}

	/**
	 * Review round 2: BoardGameGeek's search() calls parse_search_results()
	 * directly (never normalize_result()) — this was BGG's real,
	 * unsanitized search path, the same bug class the review round 1
	 * TMDB finding named.
	 */
	public function test_bgg_search_strips_tags_from_name(): void {
		update_option( 'pkiw_api_credentials', [ 'bgg' => [ 'api_token' => 'fake-token-for-test' ] ] );
		add_filter(
			'pre_http_request',
			static function () {
				$xml = '<?xml version="1.0" encoding="utf-8"?>'
					. '<items total="1" termsofuse="https://boardgamegeek.com/xmlapi/termsofuse">'
					. '<item type="boardgame" id="13">'
					. '<name type="primary" value="A &lt;img src=x onerror=alert(1)&gt; B"/>'
					. '<yearpublished value="1995"/>'
					. '</item>'
					. '</items>';
				return [
					'headers'  => [],
					'body'     => $xml,
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			},
			1,
			0
		);

		$bgg     = new BoardGameGeek();
		$results = $bgg->search( 'Catan' );

		$this->assertStringNotContainsString( '<img', $results[0]['name'] );
		$this->assertStringContainsString( 'A', $results[0]['name'] );
		$this->assertStringContainsString( 'B', $results[0]['name'] );
	}

	/**
	 * Review round 2, minor 3: a nested URL field (RAWG's stores[].url)
	 * must be escaped, not just tag-stripped — the reviewer's exact case,
	 * `javascript:alert(1)` surviving because $url_keys previously only
	 * matched top-level keys.
	 */
	public function test_rawg_nested_store_url_is_escaped(): void {
		$rawg = new RAWG();

		$result = $this->invoke_protected(
			$rawg,
			'normalize_result',
			[
				[
					'id'     => 1,
					'name'   => 'A Game',
					'slug'   => 'a-game',
					'stores' => [
						[
							'store' => [ 'name' => 'Steam' ],
							'url'   => 'javascript:alert(1)',
						],
					],
				],
			]
		);

		$this->assertSame( '', $result['stores'][0]['url'] );
	}
}

<?php
/**
 * Host normalization and the derived cite host meta.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Atmosphere_Titles;
use PKIW\Meta_Fields;
use PKIW\Standard_Site;

/**
 * One rule for a URL's host: lowercase, no leading www., no port, no
 * trailing dot, and an internationalized name in one form.
 *
 * @group integration
 */
final class HostNormalizeTest extends WP_UnitTestCase {

	/**
	 * Hosts as stored, and the one value each normalizes to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function host_cases(): array {
		return [
			'mixed case'              => [ 'Example.COM', 'example.com' ],
			'leading www'             => [ 'www.example.com', 'example.com' ],
			'leading www, upper case' => [ 'WWW.Example.com', 'example.com' ],
			'www2 is a real label'    => [ 'www2.example.com', 'www2.example.com' ],
			'www inside the name'     => [ 'blog.www.example.com', 'blog.www.example.com' ],
			'port'                    => [ 'example.com:8443', 'example.com' ],
			'trailing dot'            => [ 'www.example.com.', 'example.com' ],
			'punycode'                => [ 'xn--bcher-kva.example', 'bücher.example' ],
			'punycode, upper case'    => [ 'WWW.XN--BCHER-KVA.EXAMPLE', 'bücher.example' ],
			'unicode, upper case'     => [ 'BÜCHER.example', 'bücher.example' ],
			'ipv4'                    => [ '192.0.2.10', '192.0.2.10' ],
			'ipv6'                    => [ '[2001:DB8::1]', '[2001:db8::1]' ],
			'ipv6 with port'          => [ '[::1]:8080', '[::1]' ],
			'ipv6 with junk after'    => [ '[::1]junk', '' ],
			'not an ipv6 address'     => [ '[::::]', '' ],
			'port is not a number'    => [ 'example.com:garbage', '' ],
			'mixed script, punycode'  => [ 'xn--pple-43d.com', 'xn--pple-43d.com' ],
			'mixed script, unicode'   => [ 'аpple.com', 'xn--pple-43d.com' ],
			'label too long'          => [ str_repeat( 'ü', 70 ) . '.example', '' ],
			'surrounding space'       => [ '  example.com ', 'example.com' ],
			'space inside'            => [ 'exa mple.com', '' ],
			'percent-encoded junk'    => [ 'not%20a%20url', '' ],
			'empty label'             => [ 'example..com', '' ],
			'empty'                   => [ '', '' ],
		];
	}

	/**
	 * @dataProvider host_cases
	 *
	 * @param string $host     Host as stored.
	 * @param string $expected Normalized host.
	 */
	public function test_normalize_host( string $host, string $expected ): void {
		$this->skip_unicode_without_intl( $expected );

		$this->assertSame( $expected, \PKIW\normalize_host( $host ) );
	}

	/**
	 * Punycode and Unicode spellings of one name give one value, with or
	 * without the intl extension.
	 */
	public function test_both_spellings_of_a_name_normalize_to_one_value(): void {
		$this->assertSame( \PKIW\normalize_host( 'xn--bcher-kva.example' ), \PKIW\normalize_host( 'BÜCHER.example' ) );
		$this->assertSame( \PKIW\normalize_host( 'xn--pple-43d.com' ), \PKIW\normalize_host( 'аpple.com' ) );
	}

	/**
	 * URLs, and the normalized host each one names.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function url_cases(): array {
		return [
			'plain'               => [ 'https://example.com/a-post', 'example.com' ],
			'www, case and port'  => [ 'https://WWW.Example.com:8443/a?b=c#d', 'example.com' ],
			'user info'           => [ 'https://user:secret@www.example.com/', 'example.com' ],
			'protocol relative'   => [ '//www.example.com/a', 'example.com' ],
			'unicode host'        => [ 'https://BÜCHER.example/regal', 'bücher.example' ],
			'punycode host'       => [ 'https://www.xn--bcher-kva.example/', 'bücher.example' ],
			'ipv6 host'           => [ 'http://[2001:db8::1]:8080/x', '[2001:db8::1]' ],
			'mixed script host'   => [ 'https://xn--pple-43d.com/', 'xn--pple-43d.com' ],
			'no host'             => [ '/just/a/path', '' ],
			'not a url'           => [ 'example.com', '' ],
			'mailto has no host'  => [ 'mailto:someone@example.com', '' ],
			'empty'               => [ '', '' ],
		];
	}

	/**
	 * @dataProvider url_cases
	 *
	 * @param string $url      URL.
	 * @param string $expected Normalized host.
	 */
	public function test_url_host( string $url, string $expected ): void {
		$this->skip_unicode_without_intl( $expected );

		$this->assertSame( $expected, \PKIW\url_host( $url ) );
	}

	/**
	 * Malformed authorities, where a loose read names a different host than
	 * the HTTP client would contact.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function malformed_url_cases(): array {
		return [
			'@ in the user info'     => [ 'https://user@www.example.com:80@x', 'x' ],
			'host after the last @'  => [ 'https://x@victim.example:1@attacker.example/post', 'attacker.example' ],
			'port is not a number'   => [ 'https://example.com:garbage/path', '' ],
			'junk after ipv6'        => [ 'https://[::1]junk/path', '' ],
			'not an ipv6 address'    => [ 'https://[::::]/path', '' ],
			'ipv6 with a bare colon' => [ 'https://[::1]:/path', '[::1]' ],
		];
	}

	/**
	 * @dataProvider malformed_url_cases
	 *
	 * @param string $url      URL.
	 * @param string $expected Normalized host.
	 */
	public function test_url_host_names_the_host_parse_url_names( string $url, string $expected ): void {
		$parsed = wp_parse_url( $url, PHP_URL_HOST );

		$this->assertSame( $expected, \PKIW\url_host( $url ) );
		$this->assertSame( \PKIW\normalize_host( is_string( $parsed ) ? $parsed : '' ), \PKIW\url_host( $url ) );
	}

	public function test_saving_a_cite_url_stores_its_host(): void {
		$post_id = self::factory()->post->create();

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://WWW.Example.com/a-post' );

		$this->assertSame( 'example.com', get_metadata_raw( 'post', $post_id, \PKIW\CITE_HOST_META, true ) );
	}

	public function test_changing_the_cite_url_changes_the_host(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://example.com/a' );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://www.example.net/b' );

		$this->assertSame( 'example.net', get_metadata_raw( 'post', $post_id, \PKIW\CITE_HOST_META, true ) );
	}

	public function test_deleting_the_cite_url_deletes_the_host(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://example.com/a' );

		delete_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url' );

		$this->assertNull( get_metadata_raw( 'post', $post_id, \PKIW\CITE_HOST_META, true ) );
	}

	public function test_a_cite_url_with_no_host_leaves_no_host_row(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://example.com/a' );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'not a url' );

		$this->assertNull( get_metadata_raw( 'post', $post_id, \PKIW\CITE_HOST_META, true ) );
	}

	public function test_atmosphere_reaction_title_names_the_normalized_host(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => '',
			]
		);
		if ( ! term_exists( 'like', 'kind' ) ) {
			wp_insert_term( 'Like', 'kind', [ 'slug' => 'like' ] );
		}
		wp_set_object_terms( $post_id, 'like', 'kind' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://WWW.Example.com/a-post' );

		$title = Atmosphere_Titles::derive( get_post( $post_id ) );

		$this->assertStringContainsString( 'example.com', $title );
		$this->assertStringNotContainsString( 'WWW', $title );
	}

	public function test_atmosphere_reaction_title_keeps_a_mixed_script_host_in_punycode(): void {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => '',
				'post_content' => '',
			]
		);
		if ( ! term_exists( 'like', 'kind' ) ) {
			wp_insert_term( 'Like', 'kind', [ 'slug' => 'like' ] );
		}
		wp_set_object_terms( $post_id, 'like', 'kind' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_url', 'https://xn--pple-43d.com/a-post' );

		$title = Atmosphere_Titles::derive( get_post( $post_id ) );

		$this->assertStringContainsString( 'xn--pple-43d.com', $title );
		$this->assertStringNotContainsString( 'аpple.com', $title );
	}

	public function test_standard_site_reads_the_host_after_the_last_at_sign(): void {
		$same_url = new ReflectionMethod( Standard_Site::class, 'same_url' );
		$same_url->setAccessible( true );

		$this->assertFalse( $same_url->invoke( null, 'https://victim.example/post', 'https://x@victim.example:1@attacker.example/post' ) );
	}

	public function test_standard_site_treats_punycode_and_unicode_hosts_as_one_page(): void {
		$same_url = new ReflectionMethod( Standard_Site::class, 'same_url' );
		$same_url->setAccessible( true );

		$this->assertTrue( $same_url->invoke( null, 'https://www.xn--bcher-kva.example/regal/', 'https://bücher.example/regal' ) );
		$this->assertFalse( $same_url->invoke( null, 'https://bücher.example/regal', 'https://bücher.example/other' ) );
	}

	/**
	 * Skip a case whose expected host is Unicode when intl can't decode one.
	 *
	 * Without intl every internationalized name stays punycode, so only the
	 * ASCII cases hold there.
	 *
	 * @param string $expected Expected host.
	 */
	private function skip_unicode_without_intl( string $expected ): void {
		if ( ! function_exists( 'idn_to_utf8' ) && 1 === preg_match( '/[^\x00-\x7f]/', $expected ) ) {
			$this->markTestSkipped( 'Needs the intl extension to print an internationalized host in Unicode.' );
		}
	}
}

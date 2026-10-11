<?php
/**
 * The reply facts reader (issue 253).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Taxonomy;

/**
 * Covers \PKIW\Reply_Archive::facts() through \PKIW\kind_facts(): the
 * citation fields a theme prints, privacy gates, URL fallbacks and the
 * title used for reply archive links. Names, titles and hosts are invented.
 *
 * @group integration
 */
final class ReplyFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		( new \PKIW\Reply_Archive() )->register();
		add_filter( 'pre_http_request', '__return_empty_array' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A reply post with synced cite meta.
	 *
	 * @param array<string, string> $meta Meta key => value.
	 * @param array<string, mixed>  $args Post args.
	 */
	private function reply( array $meta = [], array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Thanks for this',
					'post_content' => '<!-- wp:paragraph --><p>Reply body.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'reply', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}

		return $id;
	}

	public function test_the_reader_registers_through_kind_facts_register_not_readers(): void {
		$this->assertArrayNotHasKey( 'reply', Kind_Facts::READERS );
		$this->assertSame( [ \PKIW\Reply_Archive::class, 'facts' ], Kind_Facts::reader( 'reply' )['reader'] ?? null );
	}

	public function test_a_full_reply_reports_every_fact_in_order(): void {
		$id = $this->reply(
			[
				'_pkiw_cite_url'     => 'https://www.A11Y.example/alt-text/',
				'_pkiw_cite_name'    => 'Alt text, written down',
				'_pkiw_cite_author'  => 'Mara Quill',
				'_pkiw_cite_summary' => 'One sentence on alt text.',
				'_pkiw_cite_photo'   => 'https://a11y.example/alt.jpg',
			]
		);

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame(
			[
				'url'         => \PKIW\web_url( 'https://www.A11Y.example/alt-text/' ),
				'host'        => 'a11y.example',
				'cited_title' => 'Alt text, written down',
				'author'      => 'Mara Quill',
				'summary'     => 'One sentence on alt text.',
				'picture'     => \PKIW\kind_picture( $id ),
				'name'        => 'Thanks for this',
			],
			$facts
		);
		$this->assertSame( [ 'url', 'host', 'cited_title', 'author', 'summary', 'picture', 'name' ], array_keys( $facts ) );
		$this->assertSame( 'https://a11y.example/alt.jpg', $facts['picture']['url'] );
		$this->assertSame( 'cover', $facts['picture']['source'] );
	}

	public function test_the_stored_cite_host_wins_and_url_host_fills_in_when_it_is_missing(): void {
		$id = $this->reply( [ '_pkiw_cite_url' => 'https://blog.example/post' ] );

		update_post_meta( $id, '_pkiw_cite_host', 'stored.example' );
		$this->assertSame( 'stored.example', \PKIW\kind_facts( $id )['host'] );

		delete_post_meta( $id, '_pkiw_cite_host' );
		$this->assertSame( 'blog.example', \PKIW\kind_facts( $id )['host'] );
	}

	public function test_an_in_reply_to_only_post_reports_url_and_host(): void {
		$id = $this->reply(
			[
				'_pkiw_in_reply_to' => 'https://legacy.example/post/7',
				'_pkiw_cite_name'   => 'Legacy thread',
			]
		);
		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 'https://legacy.example/post/7', $facts['url'] );
		$this->assertSame( 'legacy.example', $facts['host'] );
		$this->assertSame( 'Legacy thread', $facts['cited_title'] );
	}

	public function test_the_cite_url_wins_over_in_reply_to(): void {
		$id = $this->reply(
			[
				'_pkiw_cite_url'    => 'https://primary.example/thread',
				'_pkiw_in_reply_to' => 'https://secondary.example/post/7',
			]
		);

		$this->assertSame( 'https://primary.example/thread', \PKIW\kind_facts( $id )['url'] );
	}

	public function test_a_non_web_cite_url_falls_through_to_in_reply_to(): void {
		$id = $this->reply(
			[
				'_pkiw_cite_url'    => 'ftp://files.example/thread',
				'_pkiw_in_reply_to' => 'https://real.example/post',
			]
		);

		$this->assertSame( 'https://real.example/post', \PKIW\kind_facts( $id )['url'] );
	}

	public function test_a_post_with_no_web_url_reports_an_empty_url(): void {
		$id = $this->reply( [ '_pkiw_cite_url' => 'ftp://files.example/thread' ] );

		$this->assertSame( '', \PKIW\kind_facts( $id )['url'] );
	}

	/**
	 * URL-like citation names are not titles.
	 *
	 * @return array<string, array{0:array<string, string>,1:string}>
	 */
	public function url_like_cite_names(): array {
		return [
			'name equals cite url'          => [
				[
					'_pkiw_cite_url'  => 'https://indieweb.example/thread?token=abc123',
					'_pkiw_cite_name' => 'https://indieweb.example/thread?token=abc123',
				],
				'indieweb.example',
			],
			'name is another absolute url' => [
				[
					'_pkiw_cite_url'  => 'https://indieweb.example/thread',
					'_pkiw_cite_name' => 'https://elsewhere.example/other',
				],
				'indieweb.example',
			],
			'name equals reply url'         => [
				[
					'_pkiw_in_reply_to' => 'https://legacy.example/post/7',
					'_pkiw_cite_name'   => 'https://legacy.example/post/7',
				],
				'legacy.example',
			],
		];
	}

	/**
	 * @dataProvider url_like_cite_names
	 *
	 * @param array<string, string> $meta          Meta key => value.
	 * @param string                $expected_name Reply display name.
	 */
	public function test_a_cite_name_equal_to_the_url_is_not_a_cited_title( array $meta, string $expected_name ): void {
		$id    = $this->reply( $meta, [ 'post_title' => '' ] );
		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( '', $facts['cited_title'] );
		$this->assertSame( $expected_name, $facts['name'] );
		$this->assertStringNotContainsString( 'token=abc123', $facts['name'] );
	}

	public function test_a_password_protected_reply_has_no_facts(): void {
		$id = $this->reply(
			[
				'_pkiw_cite_url'  => 'https://hidden.example/post',
				'_pkiw_cite_name' => 'Hidden thread',
			],
			[ 'post_password' => 'secret' ]
		);

		$this->assertSame( [], \PKIW\kind_facts( $id ) );
		$this->assertSame( [], \PKIW\Reply_Archive::facts( $id ) );
	}

	public function test_a_private_reply_has_facts_only_for_a_user_who_can_read_it(): void {
		$id = $this->reply(
			[ '_pkiw_cite_url' => 'https://private.example/post' ],
			[ 'post_status' => 'private' ]
		);

		wp_set_current_user( 0 );
		$this->assertSame( [], \PKIW\kind_facts( $id ) );
		$this->assertSame( [], \PKIW\Reply_Archive::facts( $id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 'https://private.example/post', \PKIW\kind_facts( $id )['url'] );
		$this->assertSame( 'https://private.example/post', \PKIW\Reply_Archive::facts( $id )['url'] );
	}

	public function test_no_location_key_is_ever_returned(): void {
		$id = $this->reply( [ '_pkiw_cite_url' => 'https://place.example/post' ] );
		update_post_meta( $id, '_pkiw_geo_latitude', '51.5' );
		update_post_meta( $id, '_pkiw_geo_longitude', '-0.12' );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( [ 'url', 'host', 'cited_title', 'author', 'summary', 'picture', 'name' ], array_keys( $facts ) );
		foreach ( array_keys( $facts ) as $key ) {
			$this->assertFalse( str_contains( $key, 'geo' ), $key );
			$this->assertFalse( str_contains( $key, 'lat' ), $key );
			$this->assertFalse( str_contains( $key, 'lon' ), $key );
			$this->assertFalse( str_contains( $key, 'location' ), $key );
			$this->assertFalse( str_contains( $key, 'coordinates' ), $key );
		}
	}

	public function test_the_name_is_the_post_title_else_the_untitled_name(): void {
		$titled = $this->reply( [ '_pkiw_cite_name' => 'Thread' ], [ 'post_title' => 'Thanks for this' ] );
		$this->assertSame( 'Thanks for this', \PKIW\kind_facts( $titled )['name'] );

		$cited = $this->reply(
			[
				'_pkiw_cite_url'  => 'https://indieweb.example/thread',
				'_pkiw_cite_name' => 'Small sites, lasting links',
			],
			[ 'post_title' => '' ]
		);
		$cited_name = \PKIW\untitled_name( get_post( $cited ) );
		$this->assertSame( $cited_name, \PKIW\kind_facts( $cited )['name'] );
		$this->assertSame( 'Small sites, lasting links', $cited_name );

		$cite_host = $this->reply(
			[ '_pkiw_cite_url' => 'https://indieweb.example/thread?token=abc123' ],
			[ 'post_title' => '' ]
		);
		$cite_host_name = \PKIW\untitled_name( get_post( $cite_host ) );
		$this->assertSame( $cite_host_name, \PKIW\kind_facts( $cite_host )['name'] );
		$this->assertSame( 'indieweb.example', $cite_host_name );

		$reply_host = $this->reply(
			[ '_pkiw_in_reply_to' => 'https://legacy.example/post/7' ],
			[ 'post_title' => '' ]
		);
		$reply_host_name = \PKIW\untitled_name( get_post( $reply_host ) );
		$this->assertSame( $reply_host_name, \PKIW\kind_facts( $reply_host )['name'] );
		$this->assertSame( 'legacy.example', $reply_host_name );
	}

	public function test_a_post_of_another_kind_gets_no_reply_facts(): void {
		$id = $this->reply( [ '_pkiw_cite_url' => 'https://note.example/post' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( [], $facts );
		$this->assertArrayNotHasKey( 'cited_title', $facts );
	}
}

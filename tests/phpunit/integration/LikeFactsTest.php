<?php
/**
 * The like facts reader (issue 254).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Covers \PKIW\Like_Archive::facts() through \PKIW\kind_facts(): citation
 * facts come from meta, never stale block attributes, and no location facts
 * leak into the like archive contract.
 *
 * @group integration
 */
final class LikeFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'blocked', 'No network in tests.' );
			}
		);
		( new \PKIW\Like_Archive() )->register();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published like with prefixed meta suffixes.
	 *
	 * @param array<string, string> $meta Meta suffix => value.
	 * @param array<string, mixed>  $args Post args.
	 */
	private function like( array $meta = [], array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Liked a post',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'like', Taxonomy::TAXONOMY );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, Meta_Fields::PREFIX . $suffix, $value );
		}

		return $id;
	}

	public function test_the_reader_registers_through_kind_facts_register_not_readers(): void {
		$reader = Kind_Facts::reader( 'like' );

		$this->assertArrayNotHasKey( 'like', Kind_Facts::READERS );
		$this->assertNotNull( $reader );
		$this->assertIsCallable( $reader['reader'] );
		$this->assertSame( [], $reader['location'] );
	}

	public function test_a_like_reports_every_fact_from_meta(): void {
		$id = $this->like(
			[
				'cite_url'     => 'https://www.notes.example.com/paper-sites',
				'cite_name'    => 'Field notes on paper sites',
				'cite_author'  => 'Rowan Vale',
				'cite_summary' => 'Why a small site can stay slow.',
				'cite_photo'   => 'https://notes.example.com/img/paper.jpg',
			]
		);

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( [ 'url', 'host', 'cited_title', 'author', 'summary', 'picture' ], array_keys( $facts ) );
		$this->assertSame(
			[
				'url'          => 'https://www.notes.example.com/paper-sites',
				'host'         => 'notes.example.com',
				'cited_title'  => 'Field notes on paper sites',
				'author'       => 'Rowan Vale',
				'summary'      => 'Why a small site can stay slow.',
				'picture'      => [
					'source'            => 'cover',
					'attachment_id'     => 0,
					'url'               => 'https://notes.example.com/img/paper.jpg',
					'alt'               => '',
					'remote'            => true,
					'suppress_featured' => false,
				],
			],
			$facts
		);
	}

	public function test_host_prefers_the_stored_row_and_falls_back_to_the_url(): void {
		$id = $this->like( [ 'cite_url' => 'https://WWW.Notes.Example.com:8443/x' ] );

		$this->assertSame( 'notes.example.com', \PKIW\kind_facts( $id )['host'] );

		update_post_meta( $id, \PKIW\CITE_HOST_META, 'stored.example' );
		$this->assertSame( 'stored.example', \PKIW\kind_facts( $id )['host'] );

		delete_post_meta( $id, \PKIW\CITE_HOST_META );
		$this->assertSame( 'notes.example.com', \PKIW\kind_facts( $id )['host'] );

		$this->assertSame( '', \PKIW\kind_facts( $this->like() )['host'] );
	}

	public function test_url_falls_back_to_like_of_and_only_web_urls_print(): void {
		$like_of = $this->like( [ 'like_of' => 'https://zine.example.org/issue-12' ] );
		$this->assertSame( 'https://zine.example.org/issue-12', \PKIW\kind_facts( $like_of )['url'] );
		$this->assertSame( 'zine.example.org', \PKIW\kind_facts( $like_of )['host'] );

		$both = $this->like(
			[
				'cite_url' => 'https://notes.example.com/a',
				'like_of'  => 'https://zine.example.org/issue-12',
			]
		);
		$this->assertSame( 'https://notes.example.com/a', \PKIW\kind_facts( $both )['url'] );

		$ftp = $this->like( [ 'cite_url' => 'ftp://files.example.org/a' ] );
		$this->assertSame( '', \PKIW\kind_facts( $ftp )['url'] );

		$blocked_fallback = $this->like(
			[
				'cite_url' => 'ftp://files.example.org/a',
				'like_of'  => 'https://zine.example.org/issue-12',
			]
		);
		$this->assertSame( '', \PKIW\kind_facts( $blocked_fallback )['url'] );
	}

	public function test_the_micropub_shape_has_no_cited_title(): void {
		$url = 'https://zine.example.org/issue-12';

		$this->assertSame( '', \PKIW\kind_facts( $this->like( [ 'cite_url' => $url, 'cite_name' => $url ] ) )['cited_title'] );
		$this->assertSame( '', \PKIW\kind_facts( $this->like( [ 'cite_name' => 'https://other.example.org/x' ] ) )['cited_title'] );
		$this->assertSame( 'Weekly links', \PKIW\kind_facts( $this->like( [ 'cite_name' => '  Weekly links  ' ] ) )['cited_title'] );
	}

	public function test_a_bare_like_reports_empty_strings_and_an_empty_picture(): void {
		$this->assertSame(
			[
				'url'          => '',
				'host'         => '',
				'cited_title'  => '',
				'author'       => '',
				'summary'      => '',
				'picture'      => [
					'source'            => '',
					'attachment_id'     => 0,
					'url'               => '',
					'alt'               => '',
					'remote'            => false,
					'suppress_featured' => false,
				],
			],
			\PKIW\kind_facts( $this->like() )
		);
	}

	public function test_the_featured_image_comes_first_and_the_remote_cover_flag_is_kept(): void {
		$with_featured = $this->like( [ 'cite_photo' => 'https://notes.example.com/img/paper.jpg' ] );
		$image_id      = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $image_id, '_wp_attachment_image_alt', 'A yellow field' );
		set_post_thumbnail( $with_featured, $image_id );

		$picture = \PKIW\kind_facts( $with_featured )['picture'];
		$this->assertSame( 'featured', $picture['source'] );
		$this->assertSame( $image_id, $picture['attachment_id'] );
		$this->assertSame( wp_get_attachment_url( $image_id ), $picture['url'] );
		$this->assertSame( 'A yellow field', $picture['alt'] );
		$this->assertFalse( $picture['remote'] );
		$this->assertTrue( $picture['suppress_featured'] );

		$remote = \PKIW\kind_facts( $this->like( [ 'cite_photo' => 'https://notes.example.com/img/paper.jpg' ] ) )['picture'];
		$this->assertSame( 'cover', $remote['source'] );
		$this->assertTrue( $remote['remote'] );
	}

	public function test_private_and_protected_likes_have_no_facts(): void {
		$private = $this->like( [ 'cite_url' => 'https://private.example.org/post' ], [ 'post_status' => 'private' ] );
		$reader  = Kind_Facts::reader( 'like' )['reader'];

		wp_set_current_user( 0 );
		$this->assertSame( [], \PKIW\kind_facts( $private ) );
		$this->assertSame( [], call_user_func( $reader, $private ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'https://private.example.org/post', \PKIW\kind_facts( $private )['url'] );

		wp_set_current_user( 0 );
		$protected = $this->like( [ 'cite_url' => 'https://secret.example.org/post' ], [ 'post_password' => 'secret' ] );
		$this->assertSame( [], \PKIW\kind_facts( $protected ) );
		$this->assertSame( [], call_user_func( $reader, $protected ) );
		$this->assertSame( [], call_user_func( $reader, 999999 ) );
	}

	public function test_no_location_keys_ever_appear(): void {
		$facts = \PKIW\kind_facts(
			$this->like(
				[
					'geo_latitude'  => '40.7',
					'geo_longitude' => '-74.0',
					'geo_privacy'   => 'public',
					'cite_url'      => 'https://notes.example.com/a',
				]
			)
		);

		$this->assertSame( [ 'url', 'host', 'cited_title', 'author', 'summary', 'picture' ], array_keys( $facts ) );
	}

	public function test_meta_beats_a_stale_card_attribute(): void {
		$id = $this->like(
			[],
			[
				'post_content' => '<!-- wp:post-kinds-indieweb/like-card ' . wp_json_encode(
					[
						'url'    => 'https://card.example/stale',
						'title'  => 'Stale title',
						'author' => 'Stale Author',
					],
					JSON_UNESCAPED_SLASHES
				) . ' /-->',
			]
		);
		update_post_meta( $id, Meta_Fields::PREFIX . 'cite_url', 'https://fresh.example.org/new' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'cite_name', 'Fresh title' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'cite_author', 'Fresh Author' );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( 'https://fresh.example.org/new', $facts['url'] );
		$this->assertSame( 'fresh.example.org', $facts['host'] );
		$this->assertSame( 'Fresh title', $facts['cited_title'] );
		$this->assertSame( 'Fresh Author', $facts['author'] );
	}
}

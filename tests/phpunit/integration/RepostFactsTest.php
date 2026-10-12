<?php
/**
 * The repost facts reader.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Covers \PKIW\Repost_Archive::facts() through \PKIW\kind_facts(): citation
 * facts come from synced cite meta, URL-shaped names stay private, and no
 * location facts leak into the repost archive contract.
 *
 * @group integration
 */
final class RepostFactsTest extends WP_UnitTestCase {

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
		( new \PKIW\Repost_Archive() )->register();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published repost whose cite meta is synced from a repost-card block.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 * @param array<string, mixed> $args  Post args.
	 */
	private function repost_card( array $attrs, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Reposted a link',
					'post_content' => '<!-- wp:post-kinds-indieweb/repost-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'repost', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * A published repost with prefixed meta suffixes.
	 *
	 * @param array<string, string> $meta Meta suffix => value.
	 * @param array<string, mixed>  $args Post args.
	 */
	private function repost_meta( array $meta = [], array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Reposted a link',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'repost', Taxonomy::TAXONOMY );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, Meta_Fields::PREFIX . $suffix, $value );
		}

		return $id;
	}

	public function test_the_reader_registers_through_kind_facts_register_not_readers(): void {
		$reader = Kind_Facts::reader( 'repost' );

		$this->assertArrayNotHasKey( 'repost', Kind_Facts::READERS );
		$this->assertNotNull( $reader );
		$this->assertIsCallable( $reader['reader'] );
		$this->assertSame( [], $reader['location'] );
	}

	public function test_a_saved_card_gives_the_five_cite_rows_and_archive_facts(): void {
		$id = $this->repost_card(
			[
				'url'         => 'https://quiet.example.com/posts/14',
				'title'       => 'Quiet links',
				'author'      => 'Sample Author',
				'description' => 'A short summary.',
				'image'       => 'https://quiet.example.com/card.jpg',
			]
		);

		$this->assertSame( 'https://quiet.example.com/posts/14', get_metadata_raw( 'post', $id, '_pkiw_cite_url', true ) );
		$this->assertSame( 'Quiet links', get_metadata_raw( 'post', $id, '_pkiw_cite_name', true ) );
		$this->assertSame( 'Sample Author', get_metadata_raw( 'post', $id, '_pkiw_cite_author', true ) );
		$this->assertSame( 'A short summary.', get_metadata_raw( 'post', $id, '_pkiw_cite_summary', true ) );
		$this->assertSame( 'https://quiet.example.com/card.jpg', get_metadata_raw( 'post', $id, '_pkiw_cite_photo', true ) );

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( [ 'url', 'host', 'cited_title', 'author', 'summary', 'picture', 'card_image', 'name', 'name_is_title' ], array_keys( $facts ) );
		$this->assertSame( 'https://quiet.example.com/posts/14', $facts['url'] );
		$this->assertSame( 'Quiet links', $facts['cited_title'] );
		$this->assertSame( 'Sample Author', $facts['author'] );
		$this->assertSame( 'A short summary.', $facts['summary'] );
		$this->assertSame( 'https://quiet.example.com/card.jpg', $facts['card_image'] );
	}

	public function test_cite_meta_without_a_card_gives_the_same_facts(): void {
		$id = $this->repost_meta(
			[
				'cite_url'     => 'https://notes.example.com/reposted',
				'cite_name'    => 'Notes from the field',
				'cite_author'  => 'Jo Example',
				'cite_summary' => 'A field-note summary.',
				'cite_photo'   => 'https://notes.example.com/photo.jpg',
			]
		);

		$facts = \PKIW\kind_facts( $id );

		$this->assertSame( [ 'url', 'host', 'cited_title', 'author', 'summary', 'picture', 'card_image', 'name', 'name_is_title' ], array_keys( $facts ) );
		$this->assertSame( 'https://notes.example.com/reposted', $facts['url'] );
		$this->assertSame( 'Notes from the field', $facts['cited_title'] );
		$this->assertSame( 'Jo Example', $facts['author'] );
		$this->assertSame( 'A field-note summary.', $facts['summary'] );
		$this->assertSame( 'https://notes.example.com/photo.jpg', $facts['card_image'] );
	}

	public function test_repost_of_is_read_only_when_cite_url_is_empty(): void {
		$repost_of = $this->repost_meta( [ 'repost_of' => 'https://quiet.example.com/reposted' ] );
		$this->assertSame( 'https://quiet.example.com/reposted', \PKIW\kind_facts( $repost_of )['url'] );

		$both = $this->repost_meta(
			[
				'cite_url'  => 'https://notes.example.com/cite',
				'repost_of' => 'https://quiet.example.com/reposted',
			]
		);
		$this->assertSame( 'https://notes.example.com/cite', \PKIW\kind_facts( $both )['url'] );

		$blocked_fallback = $this->repost_meta(
			[
				'cite_url'  => 'ftp://files.example.com/reposted',
				'repost_of' => 'https://quiet.example.com/reposted',
			]
		);
		$this->assertSame( '', \PKIW\kind_facts( $blocked_fallback )['url'] );
	}

	public function test_a_card_whose_title_equals_its_url_has_no_cited_title(): void {
		$url   = 'https://quiet.example.com/posts/14';
		$facts = \PKIW\kind_facts( $this->repost_card( [ 'title' => $url, 'url' => $url ] ) );

		$this->assertSame( '', $facts['cited_title'] );
		$this->assertSame( 'quiet.example.com', $facts['host'] );
	}

	public function test_url_and_host_are_sanitized(): void {
		$upper = $this->repost_meta( [ 'cite_url' => 'https://WWW.Example.COM/x' ] );
		$this->assertSame( 'example.com', \PKIW\kind_facts( $upper )['host'] );

		$unsafe = $this->repost_meta( [ 'cite_url' => 'javascript:alert(1)' ] );
		$this->assertSame( '', \PKIW\kind_facts( $unsafe )['url'] );
		$this->assertSame( '', \PKIW\kind_facts( $unsafe )['host'] );
	}

	public function test_host_prefers_the_stored_row_and_falls_back_to_the_url(): void {
		$id = $this->repost_meta( [ 'cite_url' => 'https://WWW.Notes.Example.com:8443/x' ] );

		$this->assertSame( 'notes.example.com', \PKIW\kind_facts( $id )['host'] );

		update_post_meta( $id, \PKIW\CITE_HOST_META, 'stored.example' );
		$this->assertSame( 'stored.example', \PKIW\kind_facts( $id )['host'] );

		delete_post_meta( $id, \PKIW\CITE_HOST_META );
		$this->assertSame( 'notes.example.com', \PKIW\kind_facts( $id )['host'] );

		$this->assertSame( '', \PKIW\kind_facts( $this->repost_meta() )['host'] );
	}

	public function test_picture_sources_and_card_image_are_reported(): void {
		$with_featured = $this->repost_card( [ 'image' => 'https://quiet.example.com/card.jpg' ] );
		$image_id      = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $image_id, '_wp_attachment_image_alt', 'A yellow field' );
		set_post_thumbnail( $with_featured, $image_id );

		$facts   = \PKIW\kind_facts( $with_featured );
		$picture = $facts['picture'];
		$this->assertSame( 'featured', $picture['source'] );
		$this->assertSame( $image_id, $picture['attachment_id'] );
		$this->assertSame( wp_get_attachment_url( $image_id ), $picture['url'] );
		$this->assertSame( 'A yellow field', $picture['alt'] );
		$this->assertFalse( $picture['remote'] );
		$this->assertSame( 'https://quiet.example.com/card.jpg', $facts['card_image'] );

		$local_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$local    = \PKIW\kind_facts( $this->repost_card( [ 'image' => wp_get_attachment_url( $local_id ) ] ) )['picture'];
		$this->assertSame( 'cover', $local['source'] );
		$this->assertGreaterThan( 0, $local['attachment_id'] );
		$this->assertFalse( $local['remote'] );

		$remote = \PKIW\kind_facts( $this->repost_card( [ 'image' => 'https://images.example.org/a.jpg' ] ) )['picture'];
		$this->assertSame( 'cover', $remote['source'] );
		$this->assertTrue( $remote['remote'] );

		$none = \PKIW\kind_facts( $this->repost_meta() )['picture'];
		$this->assertSame( '', $none['source'] );
	}

	public function test_private_and_protected_reposts_have_no_facts_for_visitors(): void {
		$private = $this->repost_meta( [ 'cite_url' => 'https://private.example.com/post' ], [ 'post_status' => 'private' ] );
		$reader  = Kind_Facts::reader( 'repost' )['reader'];

		wp_set_current_user( 0 );
		$this->assertSame( [], \PKIW\kind_facts( $private ) );
		$this->assertSame( [], call_user_func( $reader, $private ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'https://private.example.com/post', \PKIW\kind_facts( $private )['url'] );

		wp_set_current_user( 0 );
		$protected = $this->repost_meta( [ 'cite_url' => 'https://secret.example.com/post' ], [ 'post_password' => 'secret' ] );
		$this->assertSame( [], \PKIW\kind_facts( $protected ) );
		$this->assertSame( [], call_user_func( $reader, $protected ) );
		$this->assertSame( [], call_user_func( $reader, 999999 ) );
	}

	public function test_nested_cards_sync_and_the_first_repost_card_wins(): void {
		$nested = $this->repost_card(
			[],
			[
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-kinds-indieweb/repost-card {"title":"Nested","url":"https://quiet.example.com/nested"} /--></div><!-- /wp:group -->',
			]
		);
		$this->assertSame( 'https://quiet.example.com/nested', \PKIW\kind_facts( $nested )['url'] );

		$two = $this->repost_card(
			[],
			[
				'post_content' => '<!-- wp:post-kinds-indieweb/repost-card {"title":"First","url":"https://quiet.example.com/first"} /--><!-- wp:post-kinds-indieweb/repost-card {"title":"Second","url":"https://quiet.example.com/second"} /-->',
			]
		);
		$this->assertSame( 'https://quiet.example.com/first', \PKIW\kind_facts( $two )['url'] );
		$this->assertSame( 'First', \PKIW\kind_facts( $two )['cited_title'] );
	}

	public function test_no_location_keys_ever_appear(): void {
		$facts = \PKIW\kind_facts(
			$this->repost_meta(
				[
					'geo_latitude'  => '40.7',
					'geo_longitude' => '-74.0',
					'geo_privacy'   => 'public',
					'cite_url'      => 'https://quiet.example.com/a',
				]
			)
		);

		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/lat|lon|geo|location|address/', $key );
		}
	}

	public function test_name_chain_uses_cited_title_post_title_host_then_untitled(): void {
		$cited = \PKIW\kind_facts(
			$this->repost_meta(
				[
					'cite_url'  => 'https://quiet.example.com/posts/13',
					'cite_name' => 'Cited title',
				],
				[ 'post_title' => 'Post title' ]
			)
		);
		$this->assertSame( 'Cited title', $cited['name'] );
		$this->assertFalse( $cited['name_is_title'] );

		$post_title_id = $this->repost_meta( [ 'cite_url' => 'https://quiet.example.com/posts/14' ], [ 'post_title' => 'quiet web, LOUD links' ] );
		$post_title    = \PKIW\kind_facts( $post_title_id );
		// The post-title step uses get_the_title() so title filters, including Title_Privacy, apply.
		$this->assertSame( get_the_title( $post_title_id ), $post_title['name'] );
		$this->assertTrue( $post_title['name_is_title'] );

		$url  = 'https://quiet.example.com/posts/14';
		$host = \PKIW\kind_facts( $this->repost_card( [ 'title' => $url, 'url' => $url ], [ 'post_title' => '' ] ) );
		$this->assertSame( 'quiet.example.com', $host['name'] );
		$this->assertFalse( $host['name_is_title'] );

		$untitled_id = $this->repost_meta( [], [ 'post_title' => '' ] );
		$untitled    = \PKIW\kind_facts( $untitled_id );
		$this->assertSame( \PKIW\untitled_name( get_post( $untitled_id ), false ), $untitled['name'] );
		$this->assertFalse( $untitled['name_is_title'] );
	}
}

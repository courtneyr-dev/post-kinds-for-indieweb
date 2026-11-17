<?php
/** Follow facts coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Post_Type;
use PKIW\Taxonomy;

/** @group integration */
final class FollowFactsTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_stored_name_and_host_are_public_but_private_follow_is_empty(): void {
		$id = $this->follow();
		update_post_meta( $id, '_pkiw_cite_name', 'Ada Lovelace' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://www.ada.example/' );
		$facts = Follow_Archive::facts( $id );
		$this->assertSame( [ 'post_id', 'name', 'name_source', 'url', 'host', 'initial', 'title', 'title_empty', 'photo_id', 'photo_alt', 'date_iso', 'modified_gmt', 'day', 'day_display' ], array_keys( $facts ) );
		$this->assertSame( 'Ada Lovelace', $facts['name'] );
		$this->assertSame( 'stored', $facts['name_source'] );
		$this->assertSame( 'https://www.ada.example/', $facts['url'] );
		$this->assertSame( 'ada.example', $facts['host'] );
		$this->assertSame( 'A', $facts['initial'] );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		$this->assertSame( [], Follow_Archive::facts( $id ), 'Removing the facts gate would expose the private control.' );
	}

	public function test_name_fallbacks_cover_host_url_content_and_untitled_posts(): void {
		$host = $this->follow();
		update_post_meta( $host, '_pkiw_cite_url', 'https://www.host-only.example/path' );
		$this->assertSame( [ 'host-only.example', 'host', 'host-only.example' ], array_values( array_intersect_key( Follow_Archive::facts( $host ), array_flip( [ 'name', 'name_source', 'host' ] ) ) ) );

		$url_name = $this->follow();
		update_post_meta( $url_name, '_pkiw_cite_name', 'https://named-as-url.example/' );
		update_post_meta( $url_name, '_pkiw_cite_url', 'https://named-as-url.example/' );
		$this->assertSame( 'named-as-url.example', Follow_Archive::facts( $url_name )['name'] );
		$this->assertSame( 'host', Follow_Archive::facts( $url_name )['name_source'] );

		$content = $this->follow( [ 'post_content' => '<!-- wp:paragraph --><p>Followed <a class="u-follow-of" href="https://content-only.example/">https://content-only.example/</a></p><!-- /wp:paragraph -->' ] );
		$this->assertSame( 'https://content-only.example/', Follow_Archive::facts( $content )['url'] );
		$this->assertSame( 'content-only.example', Follow_Archive::facts( $content )['name'] );

		$untitled = $this->follow();
		$facts    = Follow_Archive::facts( $untitled );
		$this->assertSame( 'title', $facts['name_source'] );
		$this->assertNotSame( '', $facts['name'], 'untitled_name() must supply the final fallback.' );

		$unsafe = $this->follow();
		update_post_meta( $unsafe, '_pkiw_cite_name', 'Unsafe URL control' );
		update_post_meta( $unsafe, '_pkiw_cite_url', 'javascript:alert(1)' );
		$this->assertSame( '', Follow_Archive::facts( $unsafe )['url'], 'web_url() must reject javascript URLs.' );
	}

	public function test_photo_and_title_rules_use_public_images_and_public_text(): void {
		$pdf = self::factory()->attachment->create_object( [ 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ] );
		$id  = $this->follow( [ 'post_title' => '' ] );
		update_post_meta( $id, '_pkiw_cite_name', 'Case Name' );
		set_post_thumbnail( $id, $pdf );
		$this->assertSame( 0, Follow_Archive::facts( $id )['photo_id'], 'A non-image thumbnail must not become a follow photo.' );

		$private_parent = self::factory()->post->create( [ 'post_status' => 'private' ] );
		$image          = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $private_parent );
		set_post_thumbnail( $id, $image );
		$this->assertSame( 0, Follow_Archive::facts( $id )['photo_id'], 'An image attached to a private parent must not become public.' );

		wp_update_post( [ 'ID' => $image, 'post_parent' => 0 ] );
		update_post_meta( $image, '_wp_attachment_image_alt', 'case name' );
		$facts = Follow_Archive::facts( $id );
		$this->assertSame( $image, $facts['photo_id'] );
		$this->assertSame( '', $facts['photo_alt'], 'An alt equal to the name case-insensitively is redundant.' );
		$this->assertTrue( $facts['title_empty'] );

		$url_title = $this->follow( [ 'post_title' => 'https://title-url.example/' ] );
		update_post_meta( $url_title, '_pkiw_cite_url', 'https://title-url.example/' );
		$this->assertTrue( Follow_Archive::facts( $url_title )['title_empty'], 'A title equal to the cite URL is synthetic.' );
	}

	public function test_facts_reject_non_posts_and_posts_without_follow_term(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		wp_set_object_terms( $page, 'follow', Taxonomy::TAXONOMY );
		$this->assertSame( [], Follow_Archive::facts( $page ) );

		$post = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->assertSame( [], Follow_Archive::facts( $post ), 'A post without the follow term is the positive non-follow control.' );
	}

	public function test_follow_on_the_plugin_storage_type_reaches_every_public_follow_surface(): void {
		$settings       = get_option( 'pkiw_settings', [] );
		$was_registered = post_type_exists( Post_Type::POST_TYPE );
		update_option( 'pkiw_settings', array_merge( is_array( $settings ) ? $settings : [], [ 'import_storage_mode' => 'cpt' ] ) );
		$storage = new Post_Type();
		$storage->maybe_register_post_type();
		register_taxonomy_for_object_type( Taxonomy::TAXONOMY, Post_Type::POST_TYPE );
		try {
			$public = self::factory()->post->create( [ 'post_type' => Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Storage follow' ] );
			wp_set_object_terms( $public, 'follow', Taxonomy::TAXONOMY );
			update_post_meta( $public, '_pkiw_cite_name', 'Storage Person' );
			update_post_meta( $public, '_pkiw_cite_url', 'https://storage-person.example/' );

			$private = self::factory()->post->create( [ 'post_type' => Post_Type::POST_TYPE, 'post_status' => 'private', 'post_title' => 'Private storage follow' ] );
			wp_set_object_terms( $private, 'follow', Taxonomy::TAXONOMY );
			update_post_meta( $private, '_pkiw_cite_name', 'Private Storage Person' );
			update_post_meta( $private, '_pkiw_cite_url', 'https://private-storage.example/' );
			delete_transient( Follow_Archive::INDEX_TRANSIENT );

			$entry   = ( new WP_Block( [ 'blockName' => Follow_Archive::ENTRY_BLOCK, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'postId' => $public, 'postType' => Post_Type::POST_TYPE ] ) )->render();
			$letters = Follow_Archive::render_letters_block();
			$opml    = Follow_Archive::opml_response( 'GET' )['body'];
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$ability = wp_get_ability( 'post-kinds/get-follow-index' )->execute( [] );

			$this->assertStringContainsString( 'Storage Person', $entry, 'The eligible storage follow renders its entry.' );
			$this->assertStringContainsString( 'pkiw_follow_initial=S', $letters, 'Its fresh initial appears in the letters.' );
			$this->assertStringContainsString( 'Storage Person', $opml, 'It appears in OPML.' );
			$this->assertIsArray( $ability );
			$this->assertContains( $public, array_column( $ability['follows'], 'post_id' ), 'It appears in the public ability.' );
			$this->assertStringNotContainsString( 'Private Storage Person', $entry . $letters . $opml . wp_json_encode( $ability ), 'The private storage follow appears nowhere.' );
			$this->assertNotContains( $private, array_column( $ability['follows'], 'post_id' ) );
		} finally {
			unregister_taxonomy_for_object_type( Taxonomy::TAXONOMY, Post_Type::POST_TYPE );
			if ( ! $was_registered && post_type_exists( Post_Type::POST_TYPE ) ) {
				unregister_post_type( Post_Type::POST_TYPE );
			}
			update_option( 'pkiw_settings', $settings );
			delete_transient( Follow_Archive::INDEX_TRANSIENT );
		}
	}

	private function follow( array $post = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish', 'post_title' => '' ], $post ) );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		return (int) $id;
	}
}

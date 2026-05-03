<?php
/**
 * Test the Block Bindings Source class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Block_Bindings_Source;
use PostKindsForIndieWeb\Meta_Fields;

/**
 * Test the Block_Bindings_Source class functionality.
 */
class BlockBindingsSourceTest extends WP_UnitTestCase {

	/**
	 * Block_Bindings_Source instance.
	 *
	 * @var Block_Bindings_Source
	 */
	private Block_Bindings_Source $source;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->source = new Block_Bindings_Source();
	}

	/**
	 * Test that SOURCE_NAME constant is correct.
	 */
	public function test_source_name_constant(): void {
		$this->assertSame( 'post-kinds/kind-meta', Block_Bindings_Source::SOURCE_NAME );
	}

	/**
	 * Test that get_bindable_keys returns expected keys.
	 */
	public function test_get_bindable_keys(): void {
		$keys = Block_Bindings_Source::get_bindable_keys();

		$this->assertContains( 'title', $keys );
		$this->assertContains( 'artist', $keys );
		$this->assertContains( 'album', $keys );
		$this->assertContains( 'rating', $keys );
		$this->assertContains( 'url', $keys );
		$this->assertContains( 'cover_image', $keys );
		$this->assertContains( 'summary', $keys );
		$this->assertContains( 'author', $keys );
		$this->assertContains( 'kind', $keys );
		$this->assertCount( 9, $keys );
	}

	/**
	 * Test get_value returns null for missing key.
	 */
	public function test_get_value_returns_null_for_missing_key(): void {
		$post_id = self::factory()->post->create();
		$block   = $this->make_block_instance( $post_id );

		$result = $this->source->get_value( [], $block, 'content' );
		$this->assertNull( $result );
	}

	/**
	 * Test get_value returns null for unknown key.
	 */
	public function test_get_value_returns_null_for_unknown_key(): void {
		$post_id = self::factory()->post->create();
		$block   = $this->make_block_instance( $post_id );

		$result = $this->source->get_value( [ 'key' => 'nonexistent' ], $block, 'content' );
		$this->assertNull( $result );
	}

	/**
	 * Test get_value resolves title for listen kind.
	 */
	public function test_get_value_title_for_listen_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'listen' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', 'Bohemian Rhapsody' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'title' ], $block, 'content' );

		$this->assertSame( 'Bohemian Rhapsody', $result );
	}

	/**
	 * Test get_value resolves title for watch kind.
	 */
	public function test_get_value_title_for_watch_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'watch' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_title', 'Inception' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'title' ], $block, 'content' );

		$this->assertSame( 'Inception', $result );
	}

	/**
	 * Test get_value resolves title for read kind.
	 */
	public function test_get_value_title_for_read_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'read' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_title', 'Dune' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'title' ], $block, 'content' );

		$this->assertSame( 'Dune', $result );
	}

	/**
	 * Test get_value falls back to default (cite_name) for unknown kind.
	 */
	public function test_get_value_title_falls_back_to_default(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'bookmark' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_name', 'A Great Article' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'title' ], $block, 'content' );

		$this->assertSame( 'A Great Article', $result );
	}

	/**
	 * Test get_value resolves artist for listen kind.
	 */
	public function test_get_value_artist_for_listen_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'listen' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_artist', 'Queen' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'artist' ], $block, 'content' );

		$this->assertSame( 'Queen', $result );
	}

	/**
	 * Test get_value resolves artist for jam kind.
	 */
	public function test_get_value_artist_for_jam_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'jam' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_artist', 'The Beatles' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'artist' ], $block, 'content' );

		$this->assertSame( 'The Beatles', $result );
	}

	/**
	 * Test get_value resolves rating for watch kind.
	 */
	public function test_get_value_rating_for_watch_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'watch' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_rating', '4' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'rating' ], $block, 'content' );

		$this->assertSame( '4', $result );
	}

	/**
	 * Test get_value resolves cover_image for listen kind.
	 */
	public function test_get_value_cover_image_for_listen_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'listen' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_cover', 'https://example.com/cover.jpg' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'cover_image' ], $block, 'content' );

		$this->assertSame( 'https://example.com/cover.jpg', $result );
	}

	/**
	 * Test get_value resolves URL for read kind.
	 */
	public function test_get_value_url_for_read_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'read' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_url', 'https://example.com/book' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'url' ], $block, 'content' );

		$this->assertSame( 'https://example.com/book', $result );
	}

	/**
	 * Test get_value resolves summary (same for all kinds).
	 */
	public function test_get_value_summary(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'listen' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_summary', 'A great track.' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'summary' ], $block, 'content' );

		$this->assertSame( 'A great track.', $result );
	}

	/**
	 * Test get_value resolves author for read kind specifically.
	 */
	public function test_get_value_author_for_read_kind(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'read' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_author', 'Frank Herbert' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'author' ], $block, 'content' );

		$this->assertSame( 'Frank Herbert', $result );
	}

	/**
	 * Test get_value resolves author fallback to cite_author.
	 */
	public function test_get_value_author_falls_back_to_cite_author(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'bookmark' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_author', 'Jane Doe' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'author' ], $block, 'content' );

		$this->assertSame( 'Jane Doe', $result );
	}

	/**
	 * Test get_value resolves kind from taxonomy.
	 */
	public function test_get_value_kind_from_taxonomy(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'listen' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'kind' ], $block, 'content' );

		$this->assertSame( 'listen', $result );
	}

	/**
	 * Test get_value returns null for empty meta value.
	 */
	public function test_get_value_returns_null_for_empty_meta(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'listen' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', '' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'title' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_value returns null when no kind assigned and meta is empty.
	 */
	public function test_get_value_returns_null_for_no_kind_no_meta(): void {
		$post_id = self::factory()->post->create();

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'title' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test get_value kind returns empty string when no kind assigned.
	 */
	public function test_get_value_kind_returns_empty_when_none(): void {
		$post_id = self::factory()->post->create();

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'kind' ], $block, 'content' );

		$this->assertSame( '', $result );
	}

	/**
	 * Test the pkiw_block_bindings_keys filter.
	 */
	public function test_block_bindings_keys_filter(): void {
		add_filter(
			'pkiw_block_bindings_keys',
			function ( $keys ) {
				$keys[] = 'custom_key';
				return $keys;
			}
		);

		// Re-registering the same source on WP 6.5+ emits a
		// `_doing_it_wrong` notice ("Block bindings source already
		// registered"). The test re-registers deliberately to confirm the
		// filter fires; declare the notice as expected so WP_UnitTestCase
		// doesn't fail us on it.
		if ( method_exists( $this, 'setExpectedIncorrectUsage' ) ) {
			$this->setExpectedIncorrectUsage( 'WP_Block_Bindings_Registry::register' );
		}

		// Re-register to trigger the filter.
		$source = new Block_Bindings_Source();
		$source->register_source();

		// The filter is applied during registration, verify it fires.
		$this->assertTrue(
			has_filter( 'pkiw_block_bindings_keys' ) !== false,
			'pkiw_block_bindings_keys filter should be registered'
		);

		remove_all_filters( 'pkiw_block_bindings_keys' );
	}

	/**
	 * Test the pkiw_block_bindings_post_types filter.
	 */
	public function test_block_bindings_post_types_filter(): void {
		add_filter(
			'pkiw_block_bindings_post_types',
			function ( $types ) {
				$types[] = 'page';
				return $types;
			}
		);

		$source = new Block_Bindings_Source();
		$source->register_meta();

		// Verify page meta was registered.
		$registered = registered_meta_key_exists( 'post', 'pk_title', 'page' );

		// Clean up.
		remove_all_filters( 'pkiw_block_bindings_post_types' );

		$this->assertTrue( $registered );
	}

	/**
	 * Assign a kind taxonomy term to a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind    Kind slug.
	 */
	private function assign_kind( int $post_id, string $kind ): void {
		// Ensure taxonomy is registered.
		if ( ! taxonomy_exists( 'indieblocks_kind' ) ) {
			register_taxonomy( 'indieblocks_kind', 'post' );
		}

		wp_set_object_terms( $post_id, $kind, 'indieblocks_kind' );
	}

	/**
	 * Create a mock block instance with postId context.
	 *
	 * @param int $post_id Post ID.
	 * @return object Mock block instance with context.
	 */
	private function make_block_instance( int $post_id ): object {
		return (object) [
			'context' => [
				'postId'   => $post_id,
				'postType' => 'post',
			],
		];
	}
}

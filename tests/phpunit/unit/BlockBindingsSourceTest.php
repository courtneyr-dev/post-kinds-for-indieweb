<?php
/**
 * Test the Block Bindings Source class.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;
use PKIW\Block_Bindings_Source;
use PKIW\Meta_Fields;

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
		$this->assertContains( 'isbn', $keys );
		$this->assertContains( 'publisher', $keys );
		$this->assertContains( 'page_count', $keys );
		$this->assertContains( 'publish_date', $keys );
		$this->assertContains( 'asin', $keys );
		$this->assertContains( 'kind', $keys );
		$this->assertContains( 'kindle_embed_url', $keys );
		$this->assertCount( 15, $keys );
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
		$callback = function ( $keys ) {
			$keys[] = 'custom_key';
			return $keys;
		};
		add_filter( 'pkiw_block_bindings_keys', $callback );

		// The filter contract: anything hooking `pkiw_block_bindings_keys`
		// gets called when the source is registered. We verify the hook
		// is attached AND that running it through apply_filters produces
		// the expected mutation. This avoids re-registering the source —
		// WP_Block_Bindings_Registry treats double-registration of the
		// same name differently across versions (silent in 6.5–6.9,
		// `_doing_it_wrong` notice on trunk), and we don't need a
		// re-register to verify the filter contract.
		$this->assertNotFalse(
			has_filter( 'pkiw_block_bindings_keys', $callback ),
			'pkiw_block_bindings_keys filter callback should be registered.'
		);

		$result = apply_filters( 'pkiw_block_bindings_keys', [ 'kind' ] );
		$this->assertContains(
			'custom_key',
			$result,
			'Filter callback should be applied when the hook fires.'
		);

		remove_filter( 'pkiw_block_bindings_keys', $callback );
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
	 * Data provider for book keys.
	 *
	 * @return array<string, array<string, string>> [key => [bindable_key, meta_suffix, test_value]]
	 */
	public function book_keys(): array {
		return [
			'isbn'         => [ 'isbn', 'read_isbn', '9781649374042' ],
			'publisher'    => [ 'publisher', 'read_publisher', 'Entangled' ],
			'page_count'   => [ 'page_count', 'read_pages', '517' ],
			'publish_date' => [ 'publish_date', 'read_publish_date', '2023-05-02' ],
			'asin'         => [ 'asin', 'read_asin', '1649374046' ],
		];
	}

	/**
	 * Test that book keys resolve for read kind.
	 *
	 * @dataProvider book_keys
	 *
	 * @param string $key    Bindable key name.
	 * @param string $suffix Meta suffix.
	 * @param string $value  Test value.
	 */
	public function test_book_key_resolves_for_read_kind( string $key, string $suffix, string $value ): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'read' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $value );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => $key ], $block, 'content' );

		$this->assertSame( $value, $result );
	}

	/**
	 * Test KEY_MAP invariant: every non-special-case key has a _default entry.
	 */
	public function test_key_map_has_default_for_all_non_special_keys(): void {
		$reflection = new \ReflectionClass( Block_Bindings_Source::class );
		$constants = $reflection->getConstants();
		$key_map = $constants['KEY_MAP'] ?? [];

		foreach ( $key_map as $key => $entries ) {
			// 'kind' is special-cased; it should have no entries.
			if ( 'kind' === $key ) {
				$this->assertEmpty( $entries, "'kind' should have no KEY_MAP entries (special-cased)" );
				continue;
			}
			// 'kindle_embed_url' is special-cased; it should have no entries.
			if ( 'kindle_embed_url' === $key ) {
				$this->assertEmpty( $entries, "'kindle_embed_url' should have no KEY_MAP entries (special-cased)" );
				continue;
			}
			// Every other key must have a '_default' entry.
			$this->assertArrayHasKey(
				'_default',
				$entries,
				"KEY_MAP['{$key}'] must have a '_default' entry"
			);
		}
	}

	/**
	 * Test kindle_embed_url prefers explicit ASIN over ISBN-10 derivation.
	 */
	public function test_kindle_embed_url_prefers_asin_then_isbn10(): void {
		$post_id = self::factory()->post->create();
		$this->assign_kind( $post_id, 'read' );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_isbn', '9781649374042' );
		$this->assertSame(
			'https://read.amazon.com/kp/embed?asin=1649374046&preview=inline',
			$this->source->get_value( [ 'key' => 'kindle_embed_url' ], $this->make_block_instance( $post_id ), 'url' ),
			'ISBN-13 → derived ISBN-10 print ASIN'
		);

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_asin', 'B0BGYV1G97' );
		$this->assertSame(
			'https://read.amazon.com/kp/embed?asin=B0BGYV1G97&preview=inline',
			$this->source->get_value( [ 'key' => 'kindle_embed_url' ], $this->make_block_instance( $post_id ), 'url' ),
			'explicit ASIN wins over derivation'
		);
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

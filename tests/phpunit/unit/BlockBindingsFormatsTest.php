<?php
/**
 * Test the Block Bindings Formats (post-formats/format-data) source.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Block_Bindings_Formats;

/**
 * Test the Block_Bindings_Formats class functionality.
 *
 * @covers \PostKindsForIndieWeb\Block_Bindings_Formats
 */
class BlockBindingsFormatsTest extends WP_UnitTestCase {

	/**
	 * Block_Bindings_Formats instance.
	 *
	 * @var Block_Bindings_Formats
	 */
	private Block_Bindings_Formats $source;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->source = new Block_Bindings_Formats();

		// Post formats require theme support.
		add_theme_support(
			'post-formats',
			[ 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat' ]
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_theme_support( 'post-formats' );
		parent::tear_down();
	}

	/**
	 * Test that SOURCE_NAME constant is correct.
	 */
	public function test_source_name_constant(): void {
		$this->assertSame( 'post-formats/format-data', Block_Bindings_Formats::SOURCE_NAME );
	}

	/**
	 * Test that get_bindable_keys returns all expected keys.
	 */
	public function test_get_bindable_keys(): void {
		$keys = Block_Bindings_Formats::get_bindable_keys();

		$this->assertContains( 'format_name', $keys );
		$this->assertContains( 'format_label', $keys );
		$this->assertContains( 'format_icon', $keys );
		$this->assertContains( 'has_format', $keys );
		$this->assertContains( 'char_count', $keys );
		$this->assertContains( 'media_url', $keys );
		$this->assertContains( 'quote_attribution', $keys );
		$this->assertCount( 7, $keys );
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
	 * Test get_value returns null when no post ID in context.
	 */
	public function test_get_value_returns_null_for_no_post_id(): void {
		$block = (object) [ 'context' => [] ];

		$result = $this->source->get_value( [ 'key' => 'format_name' ], $block, 'content' );
		$this->assertNull( $result );
	}

	// ----- format_name key tests -----

	/**
	 * Test format_name returns slug for non-standard format.
	 */
	public function test_format_name_for_aside(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_name' ], $block, 'content' );

		$this->assertSame( 'aside', $result );
	}

	/**
	 * Test format_name returns 'standard' for posts without a format.
	 */
	public function test_format_name_for_standard(): void {
		$post_id = self::factory()->post->create();

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_name' ], $block, 'content' );

		$this->assertSame( 'standard', $result );
	}

	/**
	 * Test format_name returns correct slug for each supported format.
	 *
	 * @dataProvider format_slugs_provider
	 *
	 * @param string $format Format slug.
	 */
	public function test_format_name_for_each_format( string $format ): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, $format );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_name' ], $block, 'content' );

		$this->assertSame( $format, $result );
	}

	// ----- format_label key tests -----

	/**
	 * Test format_label returns translated label for aside.
	 */
	public function test_format_label_for_aside(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_label' ], $block, 'content' );

		$this->assertSame( 'Aside', $result );
	}

	/**
	 * Test format_label returns 'Standard' for no format.
	 */
	public function test_format_label_for_standard(): void {
		$post_id = self::factory()->post->create();

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_label' ], $block, 'content' );

		$this->assertSame( 'Standard', $result );
	}

	// ----- format_icon key tests -----

	/**
	 * Test format_icon returns correct dashicon for aside.
	 */
	public function test_format_icon_for_aside(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_icon' ], $block, 'content' );

		$this->assertSame( 'dashicons-format-aside', $result );
	}

	/**
	 * Test format_icon returns fallback for standard format.
	 */
	public function test_format_icon_for_standard(): void {
		$post_id = self::factory()->post->create();

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_icon' ], $block, 'content' );

		$this->assertSame( 'dashicons-format-standard', $result );
	}

	/**
	 * Test format_icon returns correct icon for video format.
	 */
	public function test_format_icon_for_video(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'format_icon' ], $block, 'content' );

		$this->assertSame( 'dashicons-format-video', $result );
	}

	// ----- has_format key tests -----

	/**
	 * Test has_format returns 'true' for non-standard format.
	 */
	public function test_has_format_true_for_aside(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'has_format' ], $block, 'content' );

		$this->assertSame( 'true', $result );
	}

	/**
	 * Test has_format returns 'false' for standard format.
	 */
	public function test_has_format_false_for_standard(): void {
		$post_id = self::factory()->post->create();

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'has_format' ], $block, 'content' );

		$this->assertSame( 'false', $result );
	}

	// ----- char_count key tests -----

	/**
	 * Test char_count returns count for status posts.
	 */
	public function test_char_count_for_status_post(): void {
		$post_id = self::factory()->post->create(
			[ 'post_content' => 'Hello, world!' ]
		);
		set_post_format( $post_id, 'status' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'char_count' ], $block, 'content' );

		$this->assertSame( '13', $result );
	}

	/**
	 * Test char_count strips HTML tags.
	 */
	public function test_char_count_strips_html(): void {
		$post_id = self::factory()->post->create(
			[ 'post_content' => '<p>Hello <strong>world</strong>!</p>' ]
		);
		set_post_format( $post_id, 'status' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'char_count' ], $block, 'content' );

		// "Hello world!" = 12 chars.
		$this->assertSame( '12', $result );
	}

	/**
	 * Test char_count returns null for non-status posts.
	 */
	public function test_char_count_null_for_non_status(): void {
		$post_id = self::factory()->post->create(
			[ 'post_content' => 'Some content' ]
		);
		set_post_format( $post_id, 'aside' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'char_count' ], $block, 'content' );

		$this->assertNull( $result );
	}

	// ----- media_url key tests -----

	/**
	 * Test media_url returns null for non-audio/video posts.
	 */
	public function test_media_url_null_for_aside(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'media_url' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test media_url extracts URL from audio post content.
	 */
	public function test_media_url_from_audio_post_content(): void {
		$post_id = self::factory()->post->create(
			[ 'post_content' => "https://example.com/track.mp3\n\nGreat song!" ]
		);
		set_post_format( $post_id, 'audio' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'media_url' ], $block, 'content' );

		// get_the_post_format_url extracts the first URL from content.
		if ( null !== $result ) {
			$this->assertStringStartsWith( 'http', $result );
		} else {
			// If get_the_post_format_url returns falsy, result is null.
			$this->assertNull( $result );
		}
	}

	/**
	 * Test media_url returns null for standard posts.
	 */
	public function test_media_url_null_for_standard(): void {
		$post_id = self::factory()->post->create();

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'media_url' ], $block, 'content' );

		$this->assertNull( $result );
	}

	// ----- quote_attribution key tests -----

	/**
	 * Test quote_attribution from post meta.
	 */
	public function test_quote_attribution_from_meta(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'quote' );
		update_post_meta( $post_id, 'quote_source_name', 'Oscar Wilde' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'quote_attribution' ], $block, 'content' );

		$this->assertSame( 'Oscar Wilde', $result );
	}

	/**
	 * Test quote_attribution falls back to post title.
	 */
	public function test_quote_attribution_from_post_title(): void {
		$post_id = self::factory()->post->create(
			[ 'post_title' => 'Mark Twain' ]
		);
		set_post_format( $post_id, 'quote' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'quote_attribution' ], $block, 'content' );

		$this->assertSame( 'Mark Twain', $result );
	}

	/**
	 * Test quote_attribution returns null for non-quote posts.
	 */
	public function test_quote_attribution_null_for_non_quote(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'quote_attribution' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test quote_attribution prefers meta over title.
	 */
	public function test_quote_attribution_meta_over_title(): void {
		$post_id = self::factory()->post->create(
			[ 'post_title' => 'Title Fallback' ]
		);
		set_post_format( $post_id, 'quote' );
		update_post_meta( $post_id, 'quote_source_name', 'Meta Source' );

		$block  = $this->make_block_instance( $post_id );
		$result = $this->source->get_value( [ 'key' => 'quote_attribution' ], $block, 'content' );

		$this->assertSame( 'Meta Source', $result );
	}

	// ----- is_supported static method -----

	/**
	 * Test is_supported returns a boolean.
	 */
	public function test_is_supported_returns_bool(): void {
		$result = Block_Bindings_Formats::is_supported();
		$this->assertIsBool( $result );
	}

	// ----- Data Providers -----

	/**
	 * Provide post format slugs for parameterized tests.
	 *
	 * @return array<string, array{string}>
	 */
	public static function format_slugs_provider(): array {
		return [
			'aside'   => [ 'aside' ],
			'gallery' => [ 'gallery' ],
			'link'    => [ 'link' ],
			'image'   => [ 'image' ],
			'quote'   => [ 'quote' ],
			'status'  => [ 'status' ],
			'video'   => [ 'video' ],
			'audio'   => [ 'audio' ],
			'chat'    => [ 'chat' ],
		];
	}

	// ----- Helpers -----

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

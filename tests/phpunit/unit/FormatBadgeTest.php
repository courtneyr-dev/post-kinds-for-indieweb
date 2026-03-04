<?php
/**
 * Test the Format Badge block class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Blocks\Format_Badge;

/**
 * Test the Format_Badge class functionality.
 *
 * @covers \PostKindsForIndieWeb\Blocks\Format_Badge
 */
class FormatBadgeTest extends WP_UnitTestCase {

	/**
	 * Format_Badge instance.
	 *
	 * @var Format_Badge
	 */
	private Format_Badge $badge;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->badge = new Format_Badge();

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
	 * Test that BLOCK_NAME constant is correct.
	 */
	public function test_block_name_constant(): void {
		$this->assertSame( 'post-formats/format-badge', Format_Badge::BLOCK_NAME );
	}

	/**
	 * Test is_supported returns a boolean.
	 */
	public function test_is_supported_returns_bool(): void {
		$result = Format_Badge::is_supported();
		$this->assertIsBool( $result );
	}

	// ----- filter_hooked_blocks tests -----

	/**
	 * Test filter ignores non-post-title blocks.
	 */
	public function test_filter_ignores_non_post_title(): void {
		$hooked = [ 'core/some-block' ];

		$result = $this->badge->filter_hooked_blocks( $hooked, 'core/paragraph', 'before', null );

		$this->assertSame( $hooked, $result );
	}

	/**
	 * Test filter ignores non-before position.
	 */
	public function test_filter_ignores_non_before_position(): void {
		$hooked = [ 'core/some-block' ];

		$result = $this->badge->filter_hooked_blocks( $hooked, 'core/post-title', 'after', null );

		$this->assertSame( $hooked, $result );
	}

	/**
	 * Test filter removes badge for standard format.
	 */
	public function test_filter_removes_badge_for_standard_format(): void {
		$post_id = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );

		$hooked = [ Format_Badge::BLOCK_NAME ];

		$result = $this->badge->filter_hooked_blocks( $hooked, 'core/post-title', 'before', null );

		$this->assertNotContains( Format_Badge::BLOCK_NAME, $result );
	}

	/**
	 * Test filter adds badge for non-standard format.
	 */
	public function test_filter_adds_badge_for_aside_format(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );
		$this->go_to( get_permalink( $post_id ) );

		$hooked = [];

		$result = $this->badge->filter_hooked_blocks( $hooked, 'core/post-title', 'before', null );

		$this->assertContains( Format_Badge::BLOCK_NAME, $result );
	}

	/**
	 * Test filter does not duplicate badge if already present.
	 */
	public function test_filter_no_duplicate_for_already_hooked(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );
		$this->go_to( get_permalink( $post_id ) );

		$hooked = [ Format_Badge::BLOCK_NAME ];

		$result = $this->badge->filter_hooked_blocks( $hooked, 'core/post-title', 'before', null );

		$count = array_count_values( $result )[ Format_Badge::BLOCK_NAME ] ?? 0;
		$this->assertSame( 1, $count );
	}

	/**
	 * Test filter preserves other hooked blocks.
	 */
	public function test_filter_preserves_other_blocks(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'quote' );
		$this->go_to( get_permalink( $post_id ) );

		$hooked = [ 'core/some-other-block' ];

		$result = $this->badge->filter_hooked_blocks( $hooked, 'core/post-title', 'before', null );

		$this->assertContains( 'core/some-other-block', $result );
		$this->assertContains( Format_Badge::BLOCK_NAME, $result );
	}

	// ----- render tests -----

	/**
	 * Test render returns empty string for standard format.
	 */
	public function test_render_empty_for_standard_format(): void {
		$post_id = self::factory()->post->create();
		$block   = $this->create_mock_block( $post_id );

		$result = $this->badge->render( [], '', $block );

		$this->assertSame( '', $result );
	}

	/**
	 * Test render returns empty string when no post ID.
	 */
	public function test_render_empty_for_no_post_id(): void {
		$block = $this->createMock( \WP_Block::class );
		$block->context = [];

		// Also ensure get_the_ID() returns falsy.
		$GLOBALS['post'] = null;

		$result = $this->badge->render( [], '', $block );

		$this->assertSame( '', $result );
	}

	/**
	 * Test render returns badge HTML for aside format.
	 */
	public function test_render_badge_for_aside(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'aside' );
		$block = $this->create_mock_block( $post_id );

		$result = $this->badge->render( [], '', $block );

		$this->assertStringContainsString( 'pk-format-badge', $result );
		$this->assertStringContainsString( 'pk-format-badge--aside', $result );
		$this->assertStringContainsString( 'dashicons-format-aside', $result );
		$this->assertStringContainsString( 'Aside', $result );
	}

	/**
	 * Test render returns badge HTML for video format.
	 */
	public function test_render_badge_for_video(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'video' );
		$block = $this->create_mock_block( $post_id );

		$result = $this->badge->render( [], '', $block );

		$this->assertStringContainsString( 'pk-format-badge--video', $result );
		$this->assertStringContainsString( 'dashicons-format-video', $result );
		$this->assertStringContainsString( 'Video', $result );
	}

	/**
	 * Test render returns badge HTML for audio format.
	 */
	public function test_render_badge_for_audio(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'audio' );
		$block = $this->create_mock_block( $post_id );

		$result = $this->badge->render( [], '', $block );

		$this->assertStringContainsString( 'pk-format-badge--audio', $result );
		$this->assertStringContainsString( 'dashicons-format-audio', $result );
		$this->assertStringContainsString( 'Audio', $result );
	}

	/**
	 * Test render badge contains span wrapper.
	 */
	public function test_render_badge_uses_span_wrapper(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'quote' );
		$block = $this->create_mock_block( $post_id );

		$result = $this->badge->render( [], '', $block );

		$this->assertStringStartsWith( '<span ', $result );
		$this->assertStringContainsString( '</span>', $result );
	}

	/**
	 * Test render includes aria-hidden on dashicon span.
	 */
	public function test_render_has_aria_hidden_on_icon(): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, 'link' );
		$block = $this->create_mock_block( $post_id );

		$result = $this->badge->render( [], '', $block );

		$this->assertStringContainsString( 'aria-hidden="true"', $result );
	}

	/**
	 * Test render for each supported format.
	 *
	 * @dataProvider format_slugs_provider
	 *
	 * @param string $format Format slug.
	 * @param string $expected_icon Expected dashicon class.
	 */
	public function test_render_each_format( string $format, string $expected_icon ): void {
		$post_id = self::factory()->post->create();
		set_post_format( $post_id, $format );
		$block = $this->create_mock_block( $post_id );

		$result = $this->badge->render( [], '', $block );

		$this->assertStringContainsString( 'pk-format-badge--' . $format, $result );
		$this->assertStringContainsString( $expected_icon, $result );
	}

	// ----- Data Providers -----

	/**
	 * Provide format slugs and their expected dashicon classes.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function format_slugs_provider(): array {
		return [
			'aside'   => [ 'aside', 'dashicons-format-aside' ],
			'gallery' => [ 'gallery', 'dashicons-format-gallery' ],
			'link'    => [ 'link', 'dashicons-admin-links' ],
			'image'   => [ 'image', 'dashicons-format-image' ],
			'quote'   => [ 'quote', 'dashicons-format-quote' ],
			'status'  => [ 'status', 'dashicons-format-status' ],
			'video'   => [ 'video', 'dashicons-format-video' ],
			'audio'   => [ 'audio', 'dashicons-format-audio' ],
			'chat'    => [ 'chat', 'dashicons-format-chat' ],
		];
	}

	// ----- Helpers -----

	/**
	 * Create a mock WP_Block for testing.
	 *
	 * @param int $post_id Post ID to set in block context.
	 * @return \WP_Block Mock block instance.
	 */
	private function create_mock_block( int $post_id ): \WP_Block {
		$block = $this->createMock( \WP_Block::class );
		$block->context = [
			'postId'   => $post_id,
			'postType' => 'post',
		];

		return $block;
	}
}

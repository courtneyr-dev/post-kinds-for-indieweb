<?php
/**
 * Coverage for the filterable card kind label.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use WP_UnitTestCase;
use function PKIW\get_kind_label;

/**
 * Every card's visible kind label flows through get_kind_label(), so a theme
 * can swap the kind noun ("Watch") for a status verb ("WATCHED") via the
 * pkiw_kind_label filter without touching markup.
 *
 * @covers \PKIW\get_kind_label
 */
final class KindLabelTest extends WP_UnitTestCase {

	/**
	 * With nothing hooked, the default label passes through untouched.
	 */
	public function test_default_label_passes_through(): void {
		$this->assertSame( 'Watch', get_kind_label( 'Watch', 'watch', 'watch-card' ) );
	}

	/**
	 * A filter callback receives the label, kind slug, and render context.
	 */
	public function test_filter_receives_label_kind_and_context(): void {
		$received = [];
		add_filter(
			'pkiw_kind_label',
			static function ( $label, $kind, $context ) use ( &$received ) {
				$received = [ $label, $kind, $context ];
				return 'WATCHED';
			},
			10,
			3
		);

		$this->assertSame( 'WATCHED', get_kind_label( 'Watch', 'watch', 'watch-card' ) );
		$this->assertSame( [ 'Watch', 'watch', 'watch-card' ], $received );
	}

	/**
	 * A non-string filter return falls back to the default label instead of
	 * fataling or emitting garbage.
	 */
	public function test_non_string_filter_return_falls_back(): void {
		add_filter( 'pkiw_kind_label', '__return_null' );

		$this->assertSame( 'Watch', get_kind_label( 'Watch', 'watch', 'watch-card' ) );
	}

	/**
	 * A rendered watch card shows the filtered label text.
	 */
	public function test_watch_card_render_uses_filtered_label(): void {
		add_filter(
			'pkiw_kind_label',
			static function ( $label, $kind, $context ) {
				return 'watch-card' === $context ? 'WATCHED' : $label;
			},
			10,
			3
		);

		$html = $this->render_watch_card();

		$this->assertStringContainsString( '<p class="pk-kindlabel">WATCHED</p>', $html );
	}

	/**
	 * A filtered label is escaped at output — markup in a filter return
	 * renders as text.
	 */
	public function test_filtered_label_is_escaped_at_output(): void {
		add_filter(
			'pkiw_kind_label',
			static function () {
				return '<script>alert(1)</script>';
			}
		);

		$html = $this->render_watch_card();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * The card title and its sub lines render inside one pk-caption wrapper,
	 * after the kind label.
	 */
	public function test_watch_card_groups_title_in_caption(): void {
		$html = $this->render_watch_card();

		$this->assertStringContainsString( '<div class="pk-caption">', $html );

		$label_pos   = strpos( $html, 'pk-kindlabel' );
		$caption_pos = strpos( $html, '<div class="pk-caption">' );
		$title_pos   = strpos( $html, 'pk-title' );
		$this->assertNotFalse( $label_pos );
		$this->assertNotFalse( $title_pos );
		$this->assertTrue( $label_pos < $caption_pos, 'Label renders before the caption wrapper.' );
		$this->assertTrue( $caption_pos < $title_pos, 'Caption wrapper opens before the title.' );
	}

	/**
	 * The generic Stream card passes the kind slug and 'stream-card' context,
	 * and groups its title and date in a pk-caption.
	 */
	public function test_generic_stream_card_filters_label_and_groups_caption(): void {
		$received = [];
		add_filter(
			'pkiw_kind_label',
			static function ( $label, $kind, $context ) use ( &$received ) {
				$received = [ $label, $kind, $context ];
				return 'POSTED';
			},
			10,
			3
		);

		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Untagged thought',
				'post_content' => "<!-- wp:paragraph -->\n<p>Just a thought.</p>\n<!-- /wp:paragraph -->",
			]
		);
		$GLOBALS['post'] = get_post( $post_id );

		$html = \PKIW\render_stream_card();

		$this->assertStringContainsString( '<p class="pk-kindlabel">POSTED</p>', $html );
		$this->assertSame( [ 'Note', 'note', 'stream-card' ], $received );

		// Title and date sit together inside the caption wrapper.
		$this->assertMatchesRegularExpression(
			'#<div class="pk-caption"><h2 class="pk-title p-name">.*</h2><p class="pk-sub pk-stream-date">.*</p></div>#s',
			$html
		);
	}

	/**
	 * Render a watch card block with a title and year.
	 *
	 * @return string Rendered card HTML.
	 */
	private function render_watch_card(): string {
		return render_block(
			[
				'blockName'    => 'post-kinds-indieweb/watch-card',
				'attrs'        => [
					'mediaTitle'  => 'Enola Holmes 3',
					'releaseYear' => 2026,
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}
}

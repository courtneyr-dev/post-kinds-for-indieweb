<?php
/**
 * Audio transcript discovery and pattern coverage (issue 243).
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use PKIW\Audio_Transcript;
use WP_Block_Patterns_Registry;
use WP_UnitTestCase;

/**
 * The transcript reader finds the first non-empty transcript group without
 * copying its text into post meta.
 */
final class AudioTranscriptTest extends WP_UnitTestCase {

	/**
	 * A published post containing block markup.
	 *
	 * @param string $content Block markup.
	 */
	private function post( string $content ): int {
		return self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);
	}

	/**
	 * A transcript group.
	 *
	 * @param string $inner Inner block markup.
	 * @param string $class Group class names.
	 */
	private function group( string $inner, string $class = 'pkiw-transcript' ): string {
		return '<!-- wp:group {"className":"' . esc_attr( $class ) . '"} --><div class="wp-block-group ' . esc_attr( $class ) . '">' . $inner . '</div><!-- /wp:group -->';
	}

	/**
	 * A paragraph block.
	 *
	 * @param string $html Paragraph contents.
	 */
	private function paragraph( string $html ): string {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Extract readable text from a found block.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function found_text( array $block ): string {
		return trim( html_entity_decode( wp_strip_all_tags( serialize_block( $block ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Empty paragraphs and whitespace entities don't count as transcripts.
	 */
	public function test_empty_and_whitespace_only_groups_are_false(): void {
		foreach ( [ '', " \t\n&nbsp;\xC2\xA0 " ] as $text ) {
			$id = $this->post( $this->group( $this->paragraph( $text ) ) );

			$this->assertNull( Audio_Transcript::find( $id ) );
			$this->assertFalse( Audio_Transcript::has_transcript( $id ) );
		}
	}

	/**
	 * Visible descendant text makes the group a transcript.
	 */
	public function test_text_is_a_transcript_and_extra_class_tokens_are_allowed(): void {
		$id    = $this->post( $this->group( $this->paragraph( 'Hello from the porch.' ), 'pkiw-transcript my-class' ) );
		$found = Audio_Transcript::find( get_post( $id ) );

		$this->assertIsArray( $found );
		$this->assertSame( 'core/group', $found['blockName'] );
		$this->assertStringContainsString( 'Hello from the porch.', $this->found_text( $found ) );
		$this->assertTrue( Audio_Transcript::has_transcript( $id ) );
	}

	/**
	 * Nested groups retain document order.
	 */
	public function test_nested_transcript_is_found_inside_columns_and_column(): void {
		$transcript = $this->group( $this->paragraph( 'Nested field note.' ) );
		$content    = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . $transcript . '</div><!-- /wp:column --></div><!-- /wp:columns -->';
		$found      = Audio_Transcript::find( $this->post( $content ) );

		$this->assertIsArray( $found );
		$this->assertStringContainsString( 'Nested field note.', $this->found_text( $found ) );
	}

	/**
	 * The first filled transcript wins.
	 */
	public function test_two_filled_groups_return_the_first(): void {
		$id    = $this->post( $this->group( $this->paragraph( 'First account.' ) ) . $this->group( $this->paragraph( 'Second account.' ) ) );
		$found = Audio_Transcript::find( $id );

		$this->assertIsArray( $found );
		$this->assertStringContainsString( 'First account.', $this->found_text( $found ) );
		$this->assertStringNotContainsString( 'Second account.', $this->found_text( $found ) );
	}

	/**
	 * An empty matching group doesn't stop the search.
	 */
	public function test_empty_group_before_filled_group_returns_the_filled_group(): void {
		$id    = $this->post( $this->group( $this->paragraph( '&nbsp;' ) ) . $this->group( $this->paragraph( 'The second group has words.' ) ) );
		$found = Audio_Transcript::find( $id );

		$this->assertIsArray( $found );
		$this->assertStringContainsString( 'The second group has words.', $this->found_text( $found ) );
	}

	/**
	 * Only an exact class token on a group qualifies.
	 */
	public function test_wrong_class_partial_token_and_non_group_block_are_false(): void {
		$fixtures = [
			$this->group( $this->paragraph( 'Unmarked.' ), 'ordinary-group' ),
			$this->group( $this->paragraph( 'Partial token.' ), 'not-pkiw-transcript' ),
			'<!-- wp:paragraph {"className":"pkiw-transcript"} --><p class="pkiw-transcript">Paragraph only.</p><!-- /wp:paragraph -->',
		];

		foreach ( $fixtures as $content ) {
			$id = $this->post( $content );
			$this->assertNull( Audio_Transcript::find( $id ) );
			$this->assertFalse( Audio_Transcript::has_transcript( $id ) );
		}
	}

	/**
	 * Reading a transcript never creates metadata.
	 */
	public function test_find_and_has_transcript_write_no_post_meta(): void {
		$id     = $this->post( $this->group( $this->paragraph( 'Read without writes.' ) ) );
		$before = get_post_meta( $id );

		$this->assertIsArray( Audio_Transcript::find( $id ) );
		$this->assertTrue( Audio_Transcript::has_transcript( get_post( $id ) ) );
		$this->assertSame( $before, get_post_meta( $id ) );
	}

	/**
	 * Unknown posts have no transcript.
	 */
	public function test_unknown_post_returns_null_and_false(): void {
		$this->assertNull( Audio_Transcript::find( PHP_INT_MAX ) );
		$this->assertFalse( Audio_Transcript::has_transcript( PHP_INT_MAX ) );
	}

	/**
	 * Registration exposes the one-paragraph transcript pattern.
	 */
	public function test_pattern_registers_with_the_documented_shape(): void {
		( new Audio_Transcript() )->register();

		$registry = WP_Block_Patterns_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( Audio_Transcript::PATTERN ) );
		$pattern = $registry->get_registered( Audio_Transcript::PATTERN );
		$this->assertSame( [ 'post-kinds-for-indieweb-in-block-themes' ], $pattern['categories'] );
		$this->assertSame( 'Audio transcript', $pattern['title'] );
		$this->assertStringContainsString( 'pkiw-transcript', $pattern['content'] );
		$this->assertStringNotContainsString( 'wp:heading', $pattern['content'] );

		$blocks = array_values( array_filter( parse_blocks( $pattern['content'] ), static fn( array $block ): bool => null !== $block['blockName'] ) );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/group', $blocks[0]['blockName'] );
		$this->assertSame( 'pkiw-transcript', $blocks[0]['attrs']['className'] );
		$this->assertSame( [ 'core/paragraph' ], array_column( $blocks[0]['innerBlocks'], 'blockName' ) );
		$this->assertSame( '', trim( wp_strip_all_tags( serialize_block( $blocks[0]['innerBlocks'][0] ) ) ) );
	}
}

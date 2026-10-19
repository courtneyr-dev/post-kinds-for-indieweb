<?php
/**
 * The question editor pattern.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Micropub_Content_Builder;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionPatternTest extends WP_UnitTestCase {

	private const PATTERN = 'post-kinds-indieweb/question';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
	}

	/** @return array<string, mixed> */
	private function pattern(): array {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( self::PATTERN );
		$this->assertIsArray( $pattern );

		return $pattern;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return string[]
	 */
	private function names( array $blocks ): array {
		$names = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$names[] = $block['blockName'];
			$names   = array_merge( $names, $this->names( $block['innerBlocks'] ?? [] ) );
		}

		return $names;
	}

	public function test_the_pattern_is_registered_for_post_groups(): void {
		$pattern = $this->pattern();

		$this->assertSame( 'Question', $pattern['title'] );
		$this->assertSame( [ 'core/group' ], $pattern['blockTypes'] );
		$this->assertSame( [ 'post' ], $pattern['postTypes'] );
		$this->assertSame( [ 'post-kinds-for-indieweb-in-block-themes' ], $pattern['categories'] );
	}

	public function test_the_pattern_holds_the_salutation_question_paragraph_and_answers_block(): void {
		$content = (string) $this->pattern()['content'];
		$blocks  = parse_blocks( $content );

		$this->assertSame( [ 'core/group', 'core/paragraph', 'core/paragraph', 'post-kinds-indieweb/answers' ], $this->names( $blocks ) );
		$this->assertStringContainsString( 'is-style-cr-salutation', $content );
		$this->assertStringContainsString( 'Dear readers,', $content );
		$this->assertStringContainsString( '"className":"pkiw-question"', $content );
		$this->assertStringNotContainsString( 'h-entry', $content );
		$this->assertStringNotContainsString( 'core/heading', $content );
	}

	public function test_the_pattern_is_not_locked(): void {
		$content = (string) $this->pattern()['content'];

		$this->assertStringNotContainsString( 'templateLock', $content );
		$this->assertStringNotContainsString( '"lock"', $content );
	}

	public function test_micropub_question_output_keeps_the_p_question_span(): void {
		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			[
				'question' => [ 'What do you do when a draft stops making sense?' ],
			]
		);

		$this->assertStringContainsString( 'class="p-question"', $content );
		$this->assertStringContainsString( 'What do you do when a draft stops making sense?', $content );
	}
}

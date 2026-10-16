<?php
/**
 * The craft project pattern (issue 249).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * The pattern inserts the craft card, a Materials list, a Tools list and the
 * Steps block, each editable and deletable in place. Headings and labels are
 * its only copy.
 *
 * @group integration
 */
final class CraftPatternTest extends WP_UnitTestCase {

	private const PATTERN = 'post-kinds-indieweb/craft-project';

	/**
	 * The registered pattern.
	 *
	 * @return array<string, mixed>
	 */
	private function pattern(): array {
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( self::PATTERN );
		$this->assertIsArray( $pattern, 'The pattern file registers itself.' );

		return $pattern;
	}

	/**
	 * Parsed blocks of the pattern, nulls dropped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks(): array {
		return array_values( array_filter( parse_blocks( (string) $this->pattern()['content'] ), static fn( array $b ): bool => null !== $b['blockName'] ) );
	}

	/**
	 * Block names, depth first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	private function names( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$out[] = $block['blockName'];
			$out   = array_merge( $out, $this->names( $block['innerBlocks'] ?? [] ) );
		}

		return $out;
	}

	public function test_the_pattern_is_registered_in_the_plugins_category_for_posts(): void {
		$pattern = $this->pattern();

		$this->assertSame( 'Craft project', $pattern['title'] );
		$this->assertSame( [ 'post-kinds-for-indieweb-in-block-themes' ], $pattern['categories'] );
		$this->assertSame( [ 'post' ], $pattern['postTypes'] );
		$this->assertTrue( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'post-kinds-for-indieweb-in-block-themes' ) );
	}

	public function test_the_blocks_are_the_card_materials_tools_and_steps_in_that_order_and_nothing_else(): void {
		$this->assertSame(
			[
				'post-kinds-indieweb/craft-card',
				'core/group',
				'core/heading',
				'core/list',
				'core/list-item',
				'core/group',
				'core/heading',
				'core/list',
				'core/list-item',
				'core/group',
				'core/heading',
				'post-kinds-indieweb/steps',
				'post-kinds-indieweb/step',
			],
			$this->names( $this->blocks() )
		);
	}

	public function test_the_three_sections_are_groups_with_the_documented_classes(): void {
		$groups = array_values( array_filter( $this->blocks(), static fn( array $b ): bool => 'core/group' === $b['blockName'] ) );

		$this->assertCount( 3, $groups );
		$this->assertSame(
			[
				'pkiw-craft-section pkiw-craft-section--materials',
				'pkiw-craft-section pkiw-craft-section--tools',
				'pkiw-craft-section pkiw-craft-section--steps',
			],
			array_column( array_column( $groups, 'attrs' ), 'className' )
		);
	}

	public function test_each_section_opens_with_an_h2_and_the_headings_are_the_only_copy(): void {
		$headings = [];
		foreach ( array_filter( $this->blocks(), static fn( array $b ): bool => 'core/group' === $b['blockName'] ) as $group ) {
			$heading = $group['innerBlocks'][0];
			$this->assertSame( 'core/heading', $heading['blockName'] );
			$this->assertSame( 2, (int) ( $heading['attrs']['level'] ?? 2 ), 'H2.' );
			$headings[] = trim( wp_strip_all_tags( $heading['innerHTML'] ) );
		}
		$this->assertSame( [ 'Materials', 'Tools', 'Steps' ], $headings );

		$text = trim( wp_strip_all_tags( (string) $this->pattern()['content'] ) );
		$this->assertSame( 'MaterialsToolsSteps', preg_replace( '/\s+/', '', $text ), 'No paragraph, no placeholder, no sample item.' );
	}

	public function test_the_lists_hold_one_empty_item_each(): void {
		foreach ( array_filter( $this->blocks(), static fn( array $b ): bool => 'core/group' === $b['blockName'] ) as $group ) {
			foreach ( $group['innerBlocks'] as $inner ) {
				if ( 'core/list' !== $inner['blockName'] ) {
					continue;
				}
				$this->assertCount( 1, $inner['innerBlocks'] );
				$this->assertSame( 'core/list-item', $inner['innerBlocks'][0]['blockName'] );
				$this->assertSame( '', trim( wp_strip_all_tags( $inner['innerBlocks'][0]['innerHTML'] ) ) );
			}
		}
	}

	public function test_the_steps_block_holds_one_step_and_the_card_carries_no_attributes(): void {
		$blocks = $this->blocks();
		$this->assertSame( [], $blocks[0]['attrs'], 'The craft card starts empty: its placeholder asks for the type and time.' );

		$steps_group = array_values( array_filter( $blocks, static fn( array $b ): bool => 'core/group' === $b['blockName'] ) )[2];
		$steps       = $steps_group['innerBlocks'][1];
		$this->assertSame( 'post-kinds-indieweb/steps', $steps['blockName'] );
		$this->assertCount( 1, $steps['innerBlocks'] );
		$this->assertSame( 'post-kinds-indieweb/step', $steps['innerBlocks'][0]['blockName'] );
	}

	public function test_every_block_stays_editable_and_deletable_in_place(): void {
		$content = (string) $this->pattern()['content'];

		$this->assertStringNotContainsString( 'templateLock', $content );
		$this->assertStringNotContainsString( '"lock"', $content );
		$this->assertStringNotContainsString( 'contentOnly', $content );
		$this->assertStringNotContainsString( 'metadata', $content, 'No bindings, no overrides, no editing mode.' );
	}

	public function test_the_pattern_names_no_craft_type(): void {
		$pattern = $this->pattern();

		$this->assertDoesNotMatchRegularExpression( '/knitting|woodworking|bookbinding|pottery|embroider|weaving/i', (string) wp_json_encode( $pattern ) );
		$this->assertDoesNotMatchRegularExpression( '/knitting|woodworking|bookbinding|pottery|embroider|weaving/i', (string) file_get_contents( PKIW_PATH . 'patterns/craft-project.php' ) );
	}
}

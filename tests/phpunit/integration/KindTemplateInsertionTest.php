<?php
/** Wrapped strip and zine-export placement in the kind archive templates. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

/** @group integration */
final class KindTemplateInsertionTest extends WrappedTestCase {
	/** @return array<string, array{0: string}> */
	public static function templates(): array {
		$cases = [];
		foreach ( glob( dirname( __DIR__, 3 ) . '/templates/taxonomy-kind*.html' ) ?: [] as $file ) {
			$cases[ basename( $file, '.html' ) ] = [ $file ];
		}

		return $cases;
	}

	/** A new kind template must carry the two lines, so adding one means updating this count. */
	public function test_the_36_kind_templates_are_covered(): void {
		$this->assertCount( 36, self::templates() );
	}

	/** @dataProvider templates */
	public function test_strip_then_zine_part_follow_the_term_description( string $file ): void {
		$blocks = parse_blocks( (string) file_get_contents( $file ) );

		$this->assertSame( 1, $this->count_blocks( $blocks, 'post-kinds-indieweb/wrapped-strip' ) );
		$this->assertSame( 1, $this->count_zine_parts( $blocks ) );

		$mains = array_values( array_filter( $this->named( $blocks ), static fn( array $b ): bool => 'core/group' === $b['blockName'] && 'main' === ( $b['attrs']['tagName'] ?? '' ) ) );
		$this->assertCount( 1, $mains );
		$children = $this->named( $mains[0]['innerBlocks'] );
		$names    = array_column( $children, 'blockName' );
		$at       = array_search( 'core/term-description', $names, true );

		$this->assertIsInt( $at );
		$this->assertSame( 'post-kinds-indieweb/wrapped-strip', $names[ $at + 1 ] ?? null );
		$this->assertSame( 'core/template-part', $names[ $at + 2 ] ?? null );
		$this->assertSame( [], $children[ $at + 1 ]['attrs'] );
		$this->assertSame( [ 'slug' => 'zine-export' ], $children[ $at + 2 ]['attrs'] );
	}

	public function test_read_archive_prints_no_strip_without_a_wrapped_post_and_one_with_it(): void {
		$template = (string) file_get_contents( dirname( __DIR__, 3 ) . '/templates/taxonomy-kind-read.html' );
		$this->read_post();
		$this->go_to( get_term_link( 'read', 'kind' ) );

		$this->assertSame( 0, $this->tag_count( do_blocks( $template ), 'SECTION', 'pkiw-wrapped-strip' ), 'no Wrapped post' );

		$this->wrapped_post();
		$this->go_to( get_term_link( 'read', 'kind' ) );

		$this->assertSame( 1, $this->tag_count( do_blocks( $template ), 'SECTION', 'pkiw-wrapped-strip' ), 'positive control: a published Wrapped post' );
	}

	/** @param array<int, array<string, mixed>> $blocks */
	private function named( array $blocks ): array {
		return array_values( array_filter( $blocks, static fn( array $b ): bool => null !== $b['blockName'] ) );
	}

	/** @param array<int, array<string, mixed>> $blocks */
	private function count_blocks( array $blocks, string $name ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			$count += ( $name === $block['blockName'] ? 1 : 0 ) + $this->count_blocks( $block['innerBlocks'], $name );
		}

		return $count;
	}

	/** @param array<int, array<string, mixed>> $blocks */
	private function count_zine_parts( array $blocks ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			$is_part = 'core/template-part' === $block['blockName'] && 'zine-export' === ( $block['attrs']['slug'] ?? '' );
			$count  += ( $is_part ? 1 : 0 ) + $this->count_zine_parts( $block['innerBlocks'] );
		}

		return $count;
	}
}

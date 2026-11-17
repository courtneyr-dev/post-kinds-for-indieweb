<?php
/** Zine imposition table tests. @package PKIW */
declare(strict_types=1);

use PKIW\Zine_Imposition;

/** @group unit */
final class ZineImpositionTest extends PHPUnit\Framework\TestCase {
	public function test_eight_page_literal_table(): void {
		$this->assertSame(
			[ 5, 4, 3, 2, 6, 7, 8, 1 ],
			array_column( Zine_Imposition::layout( 8 )['sides'][0]['cells'], 'page' )
		);
	}

	public function test_sixteen_page_literal_tables(): void {
		$layout = Zine_Imposition::layout( 16 );
		$this->assertSame( [ 5, 12, 9, 8, 4, 13, 16, 1 ], array_column( $layout['sides'][0]['cells'], 'page' ) );
		$this->assertSame( [ 7, 10, 11, 6, 2, 15, 14, 3 ], array_column( $layout['sides'][1]['cells'], 'page' ) );
	}

	/** @dataProvider page_counts */
	public function test_each_page_occurs_once_and_rows_carry_the_flip( int $pages ): void {
		$layout = Zine_Imposition::layout( $pages );
		$cells  = array_merge( ...array_column( $layout['sides'], 'cells' ) );
		$actual = array_column( $cells, 'page' );
		sort( $actual );
		$this->assertSame( range( 1, $pages ), $actual );
		foreach ( $cells as $cell ) {
			$this->assertSame( 'top' === $cell['row'], $cell['inverted'] );
		}
	}

	public static function page_counts(): array {
		return [ 'eight' => [ 8 ], 'sixteen' => [ 16 ] ];
	}

	public function test_sixteen_page_leaves_are_mirrored(): void {
		$this->assertTrue( Zine_Imposition::leaf_mirror( Zine_Imposition::layout( 16 ) ) );
	}

	public function test_eight_page_top_row_reads_forward_after_rotation(): void {
		$top = array_slice( array_column( Zine_Imposition::layout( 8 )['sides'][0]['cells'], 'page' ), 0, 4 );
		$this->assertSame( [ 2, 3, 4, 5 ], array_reverse( $top ) );
	}

	public function test_other_page_counts_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		Zine_Imposition::layout( 12 );
	}
}

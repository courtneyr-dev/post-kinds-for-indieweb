<?php
/**
 * Printable zine imposition tables.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

namespace PKIW;

/** Compute the fixed 8- and 16-page sheet layouts. */
final class Zine_Imposition {

	/**
	 * Return the literal sheet layout for a booklet size.
	 *
	 * @param int $pages Page count.
	 * @return array{pages:int,sides:array<int,array{side:int,cells:array<int,array{page:int,row:string,col:int,inverted:bool}>}>}
	 * @throws \InvalidArgumentException When the page count isn't 8 or 16.
	 */
	public static function layout( int $pages ): array {
		$rows = match ( $pages ) {
			8 => [
				[ [ 5, 4, 3, 2 ], [ 6, 7, 8, 1 ] ],
			],
			16 => [
				[ [ 5, 12, 9, 8 ], [ 4, 13, 16, 1 ] ],
				[ [ 7, 10, 11, 6 ], [ 2, 15, 14, 3 ] ],
			],
			default => throw new \InvalidArgumentException( 'Zine pages must be 8 or 16.' ),
		};

		$sides = [];
		foreach ( $rows as $side_index => $side_rows ) {
			$cells = [];
			foreach ( $side_rows as $row_index => $row_pages ) {
				foreach ( $row_pages as $column => $page ) {
					$cells[] = [
						'page'     => $page,
						'row'      => 0 === $row_index ? 'top' : 'bottom',
						'col'      => $column + 1,
						'inverted' => 0 === $row_index,
					];
				}
			}
			$sides[] = [
				'side'  => $side_index + 1,
				'cells' => $cells,
			];
		}

		return [
			'pages' => $pages,
			'sides' => $sides,
		];
	}

	/**
	 * Check that consecutive leaf pages occupy mirrored cells.
	 *
	 * @param array<string, mixed> $layout Imposition layout.
	 */
	public static function leaf_mirror( array $layout ): bool {
		if ( 16 !== (int) ( $layout['pages'] ?? 0 ) || 2 !== count( (array) ( $layout['sides'] ?? [] ) ) ) {
			return false;
		}
		$positions = [];
		foreach ( $layout['sides'] as $side ) {
			foreach ( $side['cells'] as $cell ) {
				$positions[ $cell['page'] ] = [ $cell['row'], $cell['col'], $side['side'] ];
			}
		}
		for ( $page = 1; $page < 16; $page += 2 ) {
			$left  = $positions[ $page ] ?? null;
			$right = $positions[ $page + 1 ] ?? null;
			if ( ! is_array( $left ) || ! is_array( $right ) || $left[0] !== $right[0] || 5 - $right[1] !== $left[1] || $left[2] === $right[2] ) {
				return false;
			}
		}

		return true;
	}
}

<?php
/**
 * Block API version coverage for every registered plugin block.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Asserts every plugin block registers with apiVersion 3.
 *
 * The block.json files all declare apiVersion 3, but blocks registered in
 * PHP with a settings array (stream-card) silently default to 1 when the
 * key is omitted — a drift no file-based check can see. This enumerates
 * the live registry across both plugin namespaces so the next omission
 * fails here instead of shipping.
 *
 * @group integration
 */
final class BlockApiVersionTest extends WP_UnitTestCase {

	/**
	 * Every registered plugin block carries API version 3.
	 */
	public function test_all_plugin_blocks_register_api_version_3(): void {
		$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();

		$plugin_blocks = array_filter(
			$registered,
			static function ( string $name ): bool {
				return str_starts_with( $name, 'post-kinds-indieweb/' )
					|| str_starts_with( $name, 'post-kinds/' );
			},
			ARRAY_FILTER_USE_KEY
		);

		// Guard the guard: an empty list would vacuously pass.
		$this->assertGreaterThanOrEqual(
			24,
			count( $plugin_blocks ),
			'Expected the full plugin block roster to be registered.'
		);

		$stragglers = [];
		foreach ( $plugin_blocks as $name => $block_type ) {
			if ( 3 !== (int) $block_type->api_version ) {
				$stragglers[] = $name . ' (v' . $block_type->api_version . ')';
			}
		}

		$this->assertSame(
			[],
			$stragglers,
			'Blocks registered without apiVersion 3: ' . implode( ', ', $stragglers )
		);
	}
}

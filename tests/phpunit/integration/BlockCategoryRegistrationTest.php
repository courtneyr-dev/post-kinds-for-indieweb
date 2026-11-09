<?php
/**
 * Block category registration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Asserts every category a plugin block declares is a registered editor category.
 *
 * A block whose block.json names a category that `block_categories_all` never
 * returns lands in an unregistered group, so the inserter cannot place it
 * under a plugin heading. This reads the live block registry and the
 * block.json files shipped in build/blocks, and compares both with the
 * categories the editor receives.
 *
 * @group integration
 */
final class BlockCategoryRegistrationTest extends WP_UnitTestCase {

	/**
	 * Plugin block namespaces.
	 *
	 * @var array<int, string>
	 */
	private const NAMESPACES = [ 'post-kinds-indieweb/', 'post-kinds/' ];

	/**
	 * Category slugs the editor receives for a post context.
	 *
	 * @return array<int, string>
	 */
	private function editor_category_slugs(): array {
		$context = new WP_Block_Editor_Context( [ 'post' => self::factory()->post->create_and_get() ] );

		return wp_list_pluck( get_block_categories( $context ), 'slug' );
	}

	/**
	 * Registered plugin block types.
	 *
	 * @return array<string, WP_Block_Type>
	 */
	private function plugin_block_types(): array {
		$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();

		return array_filter(
			$registered,
			static function ( string $name ): bool {
				foreach ( self::NAMESPACES as $namespace ) {
					if ( str_starts_with( $name, $namespace ) ) {
						return true;
					}
				}
				return false;
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Every registered plugin block sits in a category the editor knows.
	 */
	public function test_every_registered_block_category_is_registered(): void {
		$blocks = $this->plugin_block_types();

		// Guard the guard: an empty list would vacuously pass.
		$this->assertGreaterThanOrEqual( 24, count( $blocks ), 'Expected the full plugin block roster to be registered.' );

		$known   = $this->editor_category_slugs();
		$missing = [];

		foreach ( $blocks as $name => $block_type ) {
			// Blocks registered in PHP without a category set it in their editor script.
			if ( null === $block_type->category ) {
				continue;
			}

			if ( ! in_array( $block_type->category, $known, true ) ) {
				$missing[] = $name . ' (' . var_export( $block_type->category, true ) . ')';
			}
		}

		$this->assertSame(
			[],
			$missing,
			'Blocks in an unregistered category: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Every shipped build/blocks block.json names a category the editor knows.
	 */
	public function test_every_built_block_json_category_is_registered(): void {
		$files = glob( PKIW_PATH . 'build/blocks/*/block.json' );

		$this->assertNotEmpty( $files, 'Expected block.json files under build/blocks.' );

		$known   = $this->editor_category_slugs();
		$missing = [];

		foreach ( $files as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true );

			if ( ! is_array( $data ) || ! isset( $data['category'] ) ) {
				continue;
			}

			if ( ! in_array( $data['category'], $known, true ) ) {
				$missing[] = basename( dirname( $file ) ) . ' (' . $data['category'] . ')';
			}
		}

		$this->assertSame(
			[],
			$missing,
			'block.json files in an unregistered category: ' . implode( ', ', $missing )
		);
	}

	/**
	 * The plugin registers no category that none of its blocks uses.
	 */
	public function test_no_plugin_category_is_empty(): void {
		$used = [];
		foreach ( $this->plugin_block_types() as $block_type ) {
			$used[] = $block_type->category;
		}

		$context = new WP_Block_Editor_Context( [ 'post' => self::factory()->post->create_and_get() ] );
		$empty   = [];

		foreach ( get_block_categories( $context ) as $category ) {
			if ( ! str_starts_with( $category['slug'], 'post-kinds' ) ) {
				continue;
			}

			if ( ! in_array( $category['slug'], $used, true ) ) {
				$empty[] = $category['slug'];
			}
		}

		$this->assertSame(
			[],
			$empty,
			'Plugin categories with no blocks: ' . implode( ', ', $empty )
		);
	}
}

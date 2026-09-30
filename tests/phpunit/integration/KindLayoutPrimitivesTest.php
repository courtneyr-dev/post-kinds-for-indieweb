<?php
/**
 * Kind archive layout primitives (issue 233).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies the shelf and menu layouts ship as core block styles and Query
 * Loop variations the editor can insert, that the menu entry block is
 * registered for Post Template use, and that the neutral stylesheet carries
 * the shared accessibility requirements.
 *
 * @group integration
 */
final class KindLayoutPrimitivesTest extends WP_UnitTestCase {

	/**
	 * @return array<string, array{string}>
	 */
	public function post_template_styles(): array {
		return [
			'shelf face-out' => [ 'pkiw-shelf' ],
			'shelf spine-out' => [ 'pkiw-shelf-spine' ],
			'menu'           => [ 'pkiw-menu' ],
		];
	}

	/**
	 * @dataProvider post_template_styles
	 */
	public function test_post_template_block_style_is_registered( string $style ): void {
		$registered = WP_Block_Styles_Registry::get_instance()->get_registered( 'core/post-template', $style );

		$this->assertIsArray( $registered, "Block style {$style} is not registered on core/post-template." );
		$this->assertNotEmpty( $registered['label'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function query_variations(): array {
		return [
			'shelf' => [ 'pkiw-kind-shelf', 'is-style-pkiw-shelf' ],
			'menu'  => [ 'pkiw-kind-menu', 'is-style-pkiw-menu' ],
		];
	}

	/**
	 * @dataProvider query_variations
	 */
	public function test_query_variation_is_registered_and_editor_available( string $name, string $style_class ): void {
		$variations = WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' )->get_variations();
		$variation  = $this->find_variation( $variations, $name );

		$this->assertNotNull( $variation, "core/query variation {$name} is not registered." );
		$this->assertContains( 'inserter', $variation['scope'] );
		$this->assertTrue( $variation['attributes']['query']['inherit'] );
		$this->assertStringContainsString( $style_class, wp_json_encode( $variation['innerBlocks'] ) );

		$settings = get_block_editor_server_block_settings();
		$this->assertNotNull(
			$this->find_variation( $settings['core/query']['variations'] ?? [], $name ),
			'Variation must reach the editor through the server block settings.'
		);
	}

	public function test_menu_entry_block_is_registered_for_post_template_use(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'post-kinds-indieweb/menu-entry' );

		$this->assertNotNull( $type );
		$this->assertSame( 3, $type->api_version );
		$this->assertContains( 'core/post-template', $type->ancestor );
		$this->assertContains( 'postId', $type->uses_context );
		$this->assertTrue( $type->is_dynamic() );
	}

	public function test_layout_stylesheet_is_attached_to_post_template(): void {
		$this->assertTrue( wp_style_is( 'pkiw-kind-layouts', 'registered' ) );

		$css = (string) file_get_contents( PKIW_PATH . 'styles/kind-layouts.css' );

		$this->assertStringContainsString( ':focus-visible', $css );
		$this->assertStringContainsString( 'prefers-reduced-motion', $css );
		$this->assertStringContainsString( 'forced-colors: active', $css );
		$this->assertMatchesRegularExpression( '/@media \(max-width: 20em\)/', $css, 'Single column at 320 CSS px.' );
		$this->assertStringContainsString( '--pkiw-shelf-', $css, 'Shelf paint is token driven.' );
	}

	public function test_shelf_renders_one_item_per_post_with_no_filler_elements(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Only listen' ] );
		wp_set_object_terms( $id, 'listen', 'kind' );

		$html = do_blocks(
			'<!-- wp:query {"queryId":7,"query":{"perPage":10,"postType":"post","inherit":false,"taxQuery":{"kind":[' . (int) get_term_by( 'slug', 'listen', 'kind' )->term_id . ']}}} -->
			<div class="wp-block-query"><!-- wp:post-template {"className":"is-style-pkiw-shelf"} -->
			<!-- wp:post-title {"isLink":true} /-->
			<!-- /wp:post-template --></div><!-- /wp:query -->'
		);

		$this->assertSame( 1, substr_count( $html, '<li ' ), 'One list item for one post; empty shelf positions are CSS, not DOM.' );
		$this->assertStringContainsString( 'Only listen', $html );
	}

	/**
	 * @param array<int, array<string, mixed>> $variations Variations.
	 * @param string                           $name       Variation name.
	 * @return array<string, mixed>|null
	 */
	private function find_variation( array $variations, string $name ): ?array {
		foreach ( $variations as $variation ) {
			if ( ( $variation['name'] ?? '' ) === $name ) {
				return $variation;
			}
		}
		return null;
	}
}

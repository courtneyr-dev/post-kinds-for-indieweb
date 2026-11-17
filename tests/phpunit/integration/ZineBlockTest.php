<?php
/** Zine block tests. @package PKIW */
declare(strict_types=1);

use PKIW\Zine_Export;

require_once __DIR__ . '/ZineTestCase.php';

/** @group integration */
final class ZineBlockTest extends ZineTestCase {
	public function test_block_contract_is_registered_with_editor_and_style(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Zine_Export::BLOCK );
		$this->assertNotNull( $block );
		$this->assertSame( 3, (int) $block->api_version );
		$this->assertContains( 'pkiw-zine-export-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-zine', $block->style_handles );
		$this->assertFalse( $block->supports['html'] );
	}

	public function test_archive_markup_uses_native_control_order_and_no_extra_copy(): void {
		$this->go_to( get_term_link( $this->recipe ) );
		$html = render_block( [ 'blockName' => Zine_Export::BLOCK, 'attrs' => [ 'source' => 'archive' ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] );
		$this->assertStringContainsString( '<details class="pkiw-zine-export">', $html );
		$this->assertStringContainsString( '<summary>Export as Zine</summary>', $html );
		$this->assertStringContainsString( '<legend>Booklet pages</legend>', $html );
		$this->assertStringContainsString( '>Preview zine</button>', $html );
		$this->assertStringNotContainsString( 'Public posts only', $html );
		$this->assertStringNotContainsString( 'Build a printable booklet', $html );
	}

	public function test_excluded_kind_archive_renders_nothing(): void {
		$follow = get_term_by( 'slug', 'follow', PKIW\Taxonomy::TAXONOMY );
		$this->go_to( get_term_link( $follow ) );
		$this->assertSame( '', Zine_Export::render_block( [ 'source' => 'archive' ] ) );
	}
}

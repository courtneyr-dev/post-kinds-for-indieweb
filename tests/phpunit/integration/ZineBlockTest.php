<?php
/** Zine block tests. @package PKIW */
declare(strict_types=1);

use PKIW\Zine_Export;
use PKIW\Zine_Request;

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

	/** @dataProvider error_states */
	public function test_error_states_open_the_panel_and_focus_the_first_invalid_control( array $vars, string $field, string $message ): void {
		$this->post( 'Error-state control' );
		$html = $this->render_archive_block( $this->archive_url( $vars ) );
		$this->assertStringContainsString( '<details class="pkiw-zine-export" open>', $html );
		$dom = new DOMDocument();
		$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $dom );
		$this->assertSame( 1, $xpath->query( '//*[@autofocus]' )->length );
		if ( 'pages' === $field ) {
			$invalid = $xpath->query( '//fieldset[@aria-invalid="true"]' )->item( 0 );
			$focused = $xpath->query( '//input[@name="pkiw_zine_pages" and @value="8" and @autofocus]' )->item( 0 );
		} else {
			$invalid = $xpath->query( '//input[@id="pkiw-zine-' . $field . '" and @aria-invalid="true"]' )->item( 0 );
			$focused = $invalid;
		}
		$this->assertInstanceOf( DOMElement::class, $invalid );
		$this->assertInstanceOf( DOMElement::class, $focused );
		$this->assertTrue( $focused->hasAttribute( 'autofocus' ) );
		$description = $invalid->getAttribute( 'aria-describedby' );
		$this->assertNotSame( '', $description );
		$this->assertSame( $message, trim( (string) $xpath->evaluate( 'string(//*[@id="' . $description . '"])' ) ) );
		$this->assertNotSame( $this->print_template(), apply_filters( 'template_include', 'theme-template.php' ) );
	}

	public static function error_states(): array {
		return [
			'invalid From' => [ [ Zine_Request::VAR_PAGES => 8, Zine_Request::VAR_FROM => 'banana' ], 'from', 'Enter a valid From date, like 2026-03-09.' ],
			'invalid To'   => [ [ Zine_Request::VAR_PAGES => 8, Zine_Request::VAR_TO => '2025-02-30' ], 'to', 'Enter a valid To date, like 2026-03-09.' ],
			'From after To' => [ [ Zine_Request::VAR_PAGES => 8, Zine_Request::VAR_FROM => '2025-03-10', Zine_Request::VAR_TO => '2025-03-09' ], 'from', 'From must not be later than To.' ],
			'bad pages'     => [ [ Zine_Request::VAR_PAGES => 12 ], 'pages', 'Choose 8 or 16 pages.' ],
		];
	}

	public function test_zero_state_opens_with_zero_posts_and_keeps_theme_template(): void {
		$this->post( 'Outside zero range', [ 'post_date' => '2025-03-09 12:00:00' ] );
		$url  = $this->archive_url( [ Zine_Request::VAR_PAGES => 8, Zine_Request::VAR_FROM => '2020-01-01', Zine_Request::VAR_TO => '2020-01-02' ] );
		$html = $this->render_archive_block( $url );
		$this->assertStringContainsString( '<details class="pkiw-zine-export" open>', $html );
		$this->assertStringContainsString( '>0 posts</p>', $html );
		$this->assertNotSame( $this->print_template(), apply_filters( 'template_include', 'theme-template.php' ) );
	}

	public function test_block_renders_once_per_page_view(): void {
		$this->go_to( $this->archive_url() );
		$this->assertNotSame( '', Zine_Export::render_block( [ 'source' => 'archive' ] ) );
		$this->assertSame( '', Zine_Export::render_block( [ 'source' => 'archive' ] ) );
	}

	public function test_control_order_ignores_hidden_inputs_and_hidden_inputs_have_no_tabindex(): void {
		$html = $this->render_archive_block( $this->archive_url( [ 'pkiw_recipe_course' => 'soup', 'orderby' => 'title', 'order' => 'asc' ] ) );
		$positions = [
			strpos( $html, '<summary>' ),
			strpos( $html, 'value="8"' ),
			strpos( $html, 'value="16"' ),
			strpos( $html, 'id="pkiw-zine-from"' ),
			strpos( $html, 'id="pkiw-zine-to"' ),
			strpos( $html, '<button type="submit"' ),
		];
		foreach ( $positions as $position ) {
			$this->assertIsInt( $position );
		}
		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions );
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( [ 'tag_name' => 'INPUT' ] ) ) {
			if ( 'hidden' === $tags->get_attribute( 'type' ) ) {
				$this->assertNull( $tags->get_attribute( 'tabindex' ) );
			}
		}
	}
}

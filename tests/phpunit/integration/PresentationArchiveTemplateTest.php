<?php
/** Presentation archive template. */

declare(strict_types=1);

final class PresentationArchiveTemplateTest extends WP_UnitTestCase {

	public function test_template_is_offered_and_uses_an_inherited_query(): void {
		$file = PKIW_PATH . 'templates/taxonomy-kind-presentation.html';
		$this->assertFileExists( $file );
		$template = get_block_template( 'post-kinds-for-indieweb//taxonomy-kind-presentation' );
		$this->assertNotNull( $template );
		$this->assertSame( 'Presentation Archive', $template->title );
		$content = (string) file_get_contents( $file );
		$this->assertNotEmpty( parse_blocks( $content ) );
		$this->assertStringContainsString( '"inherit":true', $content );
		$this->assertStringContainsString( 'post-kinds-indieweb/presentation-entry', $content );
	}

	public function test_default_styles_visually_hide_the_record_link_suffix(): void {
		$css = file_get_contents( PKIW_PATH . 'styles/presentation.css' );
		$this->assertIsString( $css );
		$this->assertStringContainsString( '.pkiw-presentation-record .screen-reader-text', $css );
		$this->assertStringContainsString( 'clip-path: inset(50%)', $css );
		$this->assertStringContainsString( 'position: absolute', $css );
	}
}

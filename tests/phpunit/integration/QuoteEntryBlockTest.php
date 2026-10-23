<?php
/**
 * The quote archive entry block.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuoteEntryBlockTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Entry Author' ] );
	}

	private function quote( array $args = [], array $meta = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Quoted thought',
					'post_author'  => $this->author_id,
					'post_content' => '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Plain quoted text.</p><cite>Block Cite</cite></blockquote><!-- /wp:quote -->',
					'post_date'    => '2026-09-04 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, '_pkiw_cite_' . $key, $value );
		}

		return (int) $id;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="scope">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	public function test_block_registers_with_the_frozen_schema_and_assets(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Quote_Archive::ENTRY_BLOCK );

		$this->assertNotNull( $block );
		$this->assertTrue( Grouped_Archive::is_entry_block( Quote_Archive::ENTRY_BLOCK ) );
		$this->assertSame( 3, $block->api_version );
		$this->assertContains( 'pkiw-quote-index-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-quote-archive', $block->style_handles );
		$this->assertSame( 8, $block->attributes['linesPerPage']['default'] );
		$this->assertSame( 3, $block->attributes['headingLevel']['default'] );
		$this->assertSame( 'content', $block->attributes['emptyLabel']['role'] );
	}

	public function test_readable_entry_has_one_empty_overlay_link_and_resolved_labels(): void {
		$id = $this->quote( [], [ 'name' => 'Collected Essays', 'author' => 'Avery Quill', 'url' => 'https://example.com/work' ] );
		$html = Quote_Archive::entry_html( get_post( $id ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//*[@id="pkiw-quote-' . $id . '-text"]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="pkiw-quote-' . $id . '-cite"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@class="u-url pkiw-quote-entry__link"]' )->length );
		$this->assertSame( '', trim( $xpath->query( '//a' )->item( 0 )->textContent ) );
		$this->assertSame( 'pkiw-quote-' . $id . '-text pkiw-quote-' . $id . '-cite', $xpath->query( '//a' )->item( 0 )->getAttribute( 'aria-labelledby' ) );
		$this->assertSame( 0, $xpath->query( '//button|//input|//select|//textarea|//a[position()>1]' )->length );
		$this->assertSame( 1, $xpath->query( '//span[contains(@class,"u-quotation-of") and contains(@class,"h-cite")][@hidden]' )->length );
		$this->assertStringContainsString( 'Entry Author', $html );
	}

	public function test_short_text_is_e_content_and_long_text_is_p_summary(): void {
		$short = Quote_Archive::entry_html( get_post( $this->quote() ) );
		$words = implode( ' ', array_map( static fn( int $n ): string => 'word' . $n, range( 1, 62 ) ) );
		$long  = Quote_Archive::entry_html( get_post( $this->quote( [ 'post_content' => '<!-- wp:quote --><blockquote><p>' . $words . '</p></blockquote><!-- /wp:quote -->' ] ) ) );

		$this->assertStringContainsString( 'pkiw-quote-entry__text e-content', $short );
		$this->assertStringContainsString( 'pkiw-quote-entry__text p-summary', $long );
		$this->assertStringContainsString( 'word40…', $long );
		$this->assertStringNotContainsString( 'word41', $long );
	}

	public function test_linked_cite_and_highlighter_markup_never_reach_the_card(): void {
		$content = '<!-- wp:quote --><blockquote><p>Words <span class="sme-highlighter">stay plain</span>.</p><cite><a href="https://example.com/author">Linked Cite</a></cite></blockquote><!-- /wp:quote -->';
		$html    = Quote_Archive::entry_html( get_post( $this->quote( [ 'post_content' => $content ] ) ) );

		$this->assertStringContainsString( '<p>Words stay plain.</p>', $html );
		$this->assertStringContainsString( '>Linked Cite</cite>', $html );
		$this->assertStringNotContainsString( 'sme-highlighter', $html );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
	}

	public function test_protected_entry_is_title_link_only_without_quote_or_citation_properties(): void {
		$id = $this->quote( [ 'post_password' => 'secret' ], [ 'name' => 'Locked Work', 'author' => 'Locked Example', 'url' => 'https://example.com/locked' ] );
		$html = Quote_Archive::entry_html( get_post( $id ) );

		$this->assertStringContainsString( 'pkiw-quote-entry--protected', $html );
		$this->assertStringContainsString( 'Protected: Quoted thought', wp_strip_all_tags( $html ) );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		foreach ( [ 'Plain quoted text', 'Locked Work', 'Locked Example', 'u-quotation-of', 'p-author', 'h-card' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
	}

	public function test_heading_link_and_other_heading_follow_the_engine_contract(): void {
		$group = Quote_Archive::source()->group_of( get_post( $this->quote( [], [ 'author' => 'Avery Quill' ] ) ) );
		$this->assertStringContainsString( 'pkiw_quote_author=Avery%20Quill', Quote_Archive::author_url( $group ) );
		$this->assertSame( '', Quote_Archive::author_url( new \PKIW\Grouping\Archive_Group( '', '', '' ) ) );
	}
}

<?php
/**
 * Tag archive entry coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Tag_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class TagEntryBlockTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_entry_has_one_link_and_hidden_microformats(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Post title' ] );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Target title' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://target.example/' );
		add_post_meta( $id, Tag_Archive::LABEL_KEY, 'Label' );
		$html = Tag_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		$this->assertStringContainsString( '<h3', $html );
		$this->assertStringContainsString( 'dt-published', $html );
		$this->assertStringContainsString( 'u-tag-of', $html );
		$this->assertStringContainsString( 'p-category', $html );
	}

	public function test_source_keeps_case_variants_in_one_verbatim_group(): void {
		$source = Tag_Archive::source();
		$this->assertSame( Tag_Archive::SOURCE, $source->id() );
		$this->assertSame( 12, Tag_Archive::preview_per_page( 10, 'tag' ) );
		$this->assertSame( 10, Tag_Archive::preview_per_page( 10, 'note' ) );
	}
}

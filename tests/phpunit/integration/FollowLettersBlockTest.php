<?php
/** Follow letters block coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowLettersBlockTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
	}

	public function test_letters_and_visible_opml_link_render_from_public_rows(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Zora' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://zora.example/' );
		$accented = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $accented, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $accented, '_pkiw_cite_name', 'Émile' );
		update_post_meta( $accented, '_pkiw_cite_url', 'https://emile.example/' );
		$html = Follow_Archive::render_letters_block();
		$this->assertStringContainsString( 'pkiw_follow_initial=Z', $html );
		$this->assertStringContainsString( 'pkiw_follow_initial=%C3%89', $html, 'Encoding the letter before add_query_arg() would double-encode this value.' );
		$this->assertStringContainsString( 'Follows as OPML', $html );
		$this->assertStringNotContainsString( 'aria-current', $html, 'An unfiltered archive has no current letter.' );
	}
}

<?php
/** Follow letters block coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Grouped_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowLettersBlockTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
	}

	public function test_letters_render_once_in_contract_order_without_an_unfiltered_all_link(): void {
		foreach ( [ 'Eden', 'Émile', 'Tara', '9th Street' ] as $name ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
			update_post_meta( $id, '_pkiw_cite_name', $name );
			update_post_meta( $id, '_pkiw_cite_url', 'https://' . sanitize_title( remove_accents( $name ) ) . '.example/' );
		}
		$html = Follow_Archive::render_letters_block();
		$this->assertStringContainsString( 'pkiw_follow_initial=%C3%89', $html, 'Encoding the letter before add_query_arg() would double-encode this value.' );
		$this->assertSame( 1, substr_count( $html, '>E</a>' ) );
		$this->assertSame( 1, substr_count( $html, '>É</a>' ) );
		$this->assertLessThan( strpos( $html, '>É</a>' ), strpos( $html, '>E</a>' ) );
		$this->assertLessThan( strpos( $html, '>#</a>' ), strpos( $html, '>É</a>' ) );
		$this->assertStringContainsString( 'Follows as OPML', $html );
		$this->assertStringNotContainsString( 'aria-current', $html, 'An unfiltered archive has no current letter.' );
		$this->assertStringNotContainsString( '>All</a>', $html );
	}

	public function test_active_letter_adds_all_and_opml_can_be_disabled(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Tara' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://tara.example/' );
		set_query_var( Follow_Archive::VAR_INITIAL, 't' );
		$html = Follow_Archive::render_letters_block( [ 'opmlLink' => false ] );
		$this->assertLessThan( strpos( $html, '>T</a>' ), strpos( $html, '>All</a>' ) );
		$this->assertStringContainsString( 'aria-current="page">T</a>', $html );
		$this->assertStringNotContainsString( 'Follows as OPML', $html );
	}

	public function test_editor_preview_has_no_active_letter_and_empty_index_renders_nothing(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Tara' );
		set_query_var( Follow_Archive::VAR_INITIAL, 'T' );
		$property = new ReflectionProperty( Grouped_Archive::class, 'block_preview' );
		$property->setValue( null, true );
		try {
			$this->assertStringNotContainsString( 'aria-current', Follow_Archive::render_letters_block() );
		} finally {
			$property->setValue( null, false );
		}

		wp_delete_post( $id, true );
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
		$this->assertSame( '', Follow_Archive::render_letters_block(), 'An empty index must not print an empty navigation landmark.' );
	}
}

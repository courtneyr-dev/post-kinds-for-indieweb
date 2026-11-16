<?php
/** Follow Micropub coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Micropub_Content_Builder;
use PKIW\Taxonomy;

/** @group integration */
final class FollowMicropubTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_h_card_builds_paragraph_and_cite_without_remote_photo(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => 'placeholder' ] );
		Micropub_Content_Builder::apply(
			[ 'h' => 'entry', 'follow-of' => [ [ 'type' => [ 'h-card' ], 'properties' => [ 'name' => [ 'Nia' ], 'url' => [ 'https://nia.example/' ], 'photo' => [ 'https://remote.example/p.png' ] ] ] ] ],
			[ 'ID' => $id ]
		);
		$this->assertTrue( has_term( 'follow', Taxonomy::TAXONOMY, $id ) );
		$this->assertStringContainsString( 'u-follow-of', (string) get_post_field( 'post_content', $id ) );
		$this->assertSame( 'https://nia.example/', get_post_meta( $id, '_pkiw_cite_url', true ) );
		$this->assertSame( 'Nia', get_post_meta( $id, '_pkiw_cite_name', true ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_cite_photo' ), 'Reading the h-card photo would reintroduce a remote image row.' );
	}
}

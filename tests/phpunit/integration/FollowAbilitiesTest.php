<?php
/** Follow ability coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Abilities\Follow_Abilities;
use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowAbilitiesTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		delete_transient( Follow_Archive::INDEX_TRANSIENT );
	}

	public function test_index_returns_public_rows_and_normalizes_letter_filter(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Mina' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://mina.example/' );
		$output = Follow_Abilities::instance()->execute_get_follow_index( [ 'letter' => 'm' ] );
		$this->assertSame( [ $id ], array_column( $output['follows'], 'post_id' ) );
		$this->assertSame( 'M', $output['letters'][0]['letter'], 'Removing normalization would make the lowercase filter empty.' );
		$this->assertSame( 1, $output['total'] );
	}
}

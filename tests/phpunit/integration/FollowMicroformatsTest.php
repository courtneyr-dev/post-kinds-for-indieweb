<?php
/** Follow hidden-mf2 coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Microformats;
use PKIW\Taxonomy;

/** @group integration */
final class FollowMicroformatsTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_single_adds_one_follow_h_card_and_respects_existing_card(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Omar' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://omar.example/' );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Singular fixture.
		setup_postdata( $GLOBALS['post'] );
		$mf2  = new Microformats();
		$html = $mf2->add_hidden_mf2_data( '<p>Body</p>' );
		$this->assertSame( 1, substr_count( $html, 'u-follow-of h-card' ) );
		$existing = '<span class="u-follow-of h-card"><data class="u-url" value="https://omar.example/"></data></span>';
		$this->assertSame( $existing, $mf2->add_hidden_mf2_data( $existing ), 'Removing the existing-card check would duplicate follow-of.' );
	}
}

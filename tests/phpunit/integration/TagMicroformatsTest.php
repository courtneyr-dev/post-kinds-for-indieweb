<?php
/**
 * Hidden tag microformats coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Microformats;
use PKIW\Tag_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class TagMicroformatsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Tag_Archive() )->register();
	}

	private function hidden( int $id, string $content ): string {
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture.
		setup_postdata( $GLOBALS['post'] );
		return ( new Microformats() )->add_hidden_mf2_data( $content );
	}

	public function test_cardless_content_and_person_posts_gain_hidden_data_once(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_url', 'https://target.example/' );
		add_post_meta( $id, Tag_Archive::LABEL_KEY, 'Label' );
		$html = $this->hidden( $id, '<p>Body</p>' );
		$this->assertSame( 1, substr_count( $html, 'class="u-tag-of"' ) );
		$this->assertSame( 1, substr_count( $html, 'class="p-category"' ) );
		$existing = '<div class="u-tag-of p-category">Already present</div>';
		$this->assertSame( $existing, $this->hidden( $id, $existing ) );
	}

	public function test_card_and_ineligible_posts_add_nothing(): void {
		$content = '<!-- wp:post-kinds-indieweb/tag-card {"url":"https://target.example/","labels":["Label"]} /-->';
		$id      = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => wp_slash( $content ) ] );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		$rendered = Tag_Archive::render_card( [ 'url' => 'https://target.example/', 'labels' => [ 'Label' ] ], get_post( $id ) );
		$this->assertSame( $rendered, $this->hidden( $id, $rendered ) );
		wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] );
		$this->assertSame( '<p>Body</p>', $this->hidden( $id, '<p>Body</p>' ) );
	}
}

<?php
/**
 * Tag Card render coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Tag_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class TagCardRenderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	private function owner( string $status = 'publish', string $password = '' ): WP_Post {
		$id = self::factory()->post->create( [ 'post_status' => $status, 'post_password' => $password ] );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		return get_post( $id );
	}

	public function test_content_markup_uses_local_fields_and_label_prefixes(): void {
		$html = Tag_Archive::render_card(
			[
				'targetType' => 'content',
				'url'         => 'https://page.example/path',
				'title'       => 'Tagged page',
				'description' => '<img src="https://tracker.example/x">Summary',
				'imageId'     => 999999,
				'labels'      => [ 'One', 'Two' ],
			],
			$this->owner()
		);
		$this->assertStringContainsString( 'class="u-tag-of"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'Labels:', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'tracker.example', $html );
	}

	public function test_person_data_requires_an_eligible_resolved_owner(): void {
		$attributes = [ 'targetType' => 'person', 'url' => 'https://person.example/', 'title' => 'Avery', 'labels' => [ 'Friend' ] ];
		$public     = Tag_Archive::render_card( $attributes, $this->owner() );
		$this->assertStringContainsString( 'u-tag-of h-card', $public );
		$this->assertStringContainsString( '>A<', $public );
		foreach ( [ null, $this->owner( 'draft' ), $this->owner( 'publish', 'secret' ) ] as $owner ) {
			$html = Tag_Archive::render_card( $attributes, $owner );
			$this->assertStringNotContainsString( 'Avery', $html );
			$this->assertStringNotContainsString( 'person.example', $html );
			$this->assertStringContainsString( 'Friend', $html );
		}
	}

	public function test_person_photo_requires_a_local_image_with_a_public_parent(): void {
		$owner = $this->owner();
		$image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $owner->ID );
		$attrs = [ 'targetType' => 'person', 'url' => 'https://person.example/', 'title' => 'Avery', 'imageId' => $image ];
		$html = Tag_Archive::render_card( $attrs, $owner );
		$this->assertStringContainsString( 'class="u-photo', $html );
		$this->assertStringContainsString( 'alt=""', $html );

		$private_parent = $this->owner( 'private' );
		$private_image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $private_parent->ID );
		$this->assertStringNotContainsString( '<img', Tag_Archive::render_card( array_merge( $attrs, [ 'imageId' => $private_image ] ), $owner ) );
		$not_image = self::factory()->attachment->create( [ 'post_mime_type' => 'text/plain', 'post_parent' => $owner->ID ] );
		$this->assertStringNotContainsString( '<img', Tag_Archive::render_card( array_merge( $attrs, [ 'imageId' => $not_image ] ), $owner ) );
	}

	public function test_owner_resolution_fails_closed_without_a_matching_card(): void {
		$owner = $this->owner();
		$block = new WP_Block( [ 'blockName' => Tag_Archive::CARD_BLOCK, 'attrs' => [ 'title' => 'Other' ], 'innerBlocks' => [] ], [ 'postId' => $owner->ID ] );
		$this->assertNull( Tag_Archive::resolve_owner( $block ) );
		$this->assertStringContainsString( '"postId"', file_get_contents( PKIW_PATH . 'src/blocks/tag-card/block.json' ) );
		$this->assertStringNotContainsString( "'tag'", substr( file_get_contents( PKIW_PATH . 'includes/functions-card-render.php' ), strpos( file_get_contents( PKIW_PATH . 'includes/functions-card-render.php' ), '$cite_kinds' ), 200 ) );
		$tag = json_decode( (string) file_get_contents( PKIW_PATH . 'src/blocks/tag-card/block.json' ), true );
		$bookmark = json_decode( (string) file_get_contents( PKIW_PATH . 'src/blocks/bookmark-card/block.json' ), true );
		unset( $bookmark['supports']['color']['gradients'] );
		$this->assertSame( $bookmark['supports'], $tag['supports'], 'N5: Tag Card supports equal Bookmark Card minus gradients.' );
	}

	public function test_owner_resolution_uses_block_context_and_matching_attributes_not_the_global_post(): void {
		$attrs = [ 'targetType' => 'person', 'url' => 'https://owner-positive.example/', 'title' => 'Owner positive', 'labels' => [ 'Owner label' ] ];
		$content = '<!-- wp:' . Tag_Archive::CARD_BLOCK . ' ' . serialize_block_attributes( $attrs ) . ' /-->';
		$eligible = $this->owner();
		wp_update_post( [ 'ID' => $eligible->ID, 'post_content' => wp_slash( $content ) ] );
		$private = $this->owner( 'private' );
		wp_update_post( [ 'ID' => $private->ID, 'post_content' => wp_slash( $content ) ] );
		$GLOBALS['post'] = get_post( $private->ID ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Deliberately wrong global.
		$block = new WP_Block( [ 'blockName' => Tag_Archive::CARD_BLOCK, 'attrs' => $attrs, 'innerBlocks' => [] ], [ 'postId' => $eligible->ID, 'postType' => 'post' ] );
		$owner = Tag_Archive::resolve_owner( $block );
		$this->assertSame( $eligible->ID, $owner->ID );
		$this->assertStringContainsString( 'Owner positive', Tag_Archive::render_card( $attrs, $owner ) );

		$private_block = new WP_Block( [ 'blockName' => Tag_Archive::CARD_BLOCK, 'attrs' => $attrs, 'innerBlocks' => [] ], [ 'postId' => $private->ID, 'postType' => 'post' ] );
		$html = Tag_Archive::render_card( $attrs, Tag_Archive::resolve_owner( $private_block ) );
		$this->assertStringNotContainsString( 'Owner positive', $html );
		$this->assertStringNotContainsString( 'owner-positive.example', $html );
		$this->assertStringContainsString( 'Owner label', $html );
		wp_reset_postdata();
	}
}

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

	public function test_owner_resolution_fails_closed_without_a_matching_card(): void {
		$owner = $this->owner();
		$block = new WP_Block( [ 'blockName' => Tag_Archive::CARD_BLOCK, 'attrs' => [ 'title' => 'Other' ], 'innerBlocks' => [] ], [ 'postId' => $owner->ID ] );
		$this->assertNull( Tag_Archive::resolve_owner( $block ) );
		$this->assertStringContainsString( '"postId"', file_get_contents( PKIW_PATH . 'src/blocks/tag-card/block.json' ) );
		$this->assertStringNotContainsString( "'tag'", substr( file_get_contents( PKIW_PATH . 'includes/functions-card-render.php' ), strpos( file_get_contents( PKIW_PATH . 'includes/functions-card-render.php' ), '$cite_kinds' ), 200 ) );
	}
}

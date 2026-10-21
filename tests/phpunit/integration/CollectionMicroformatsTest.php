<?php
/**
 * Collection microformats coverage for issue 240.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Collection_Items;
use PKIW\Taxonomy;

/**
 * Collection cases parse as h-cite children without replacing the entry's properties.
 *
 * @group integration
 */
final class CollectionMicroformatsTest extends WP_UnitTestCase {

	/** Register kinds and create stable URLs. */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		update_option( 'date_format', 'F j, Y' );
		$this->set_permalink_structure( '/%postname%/' );
		wp_set_current_user( 0 );
		Collection_Items::reset_cache();
	}

	/** Restore URL state. */
	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** One item block. */
	private function item( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/collection-item ' . serialize_block_attributes( $attributes ) . ' /-->';
	}

	/** Find the top-level h-entry. */
	private function entry( array $parsed ): array {
		foreach ( $parsed['items'] ?? [] as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				return $item;
			}
		}
		$this->fail( 'No h-entry parsed.' );
	}

	/** Recursively collect h-cite items from children and property values. */
	private function cites( array $item ): array {
		$found = [];
		foreach ( $item['children'] ?? [] as $child ) {
			if ( in_array( 'h-cite', $child['type'] ?? [], true ) ) {
				$found[] = $child;
			}
			$found = array_merge( $found, $this->cites( $child ) );
		}
		foreach ( $item['properties'] ?? [] as $values ) {
			foreach ( is_array( $values ) ? $values : [] as $value ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				if ( in_array( 'h-cite', $value['type'] ?? [], true ) ) {
					$found[] = $value;
				}
				$found = array_merge( $found, $this->cites( $value ) );
			}
		}
		return $found;
	}

	/** Single content exposes name, URL, date, and photo on each matching h-cite. */
	public function test_rendered_items_parse_as_h_cites(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		$content = $this->item(
			[
				'title'   => 'Field recorder',
				'url'     => 'https://archive.example.test/recorder',
				'date'    => '2026-09-21',
				'mediaId' => $image,
			]
		);
		$html  = '<article class="h-entry"><div class="e-content">' . do_blocks( $content ) . '</div></article>';
		$entry = $this->entry( \Mf2\parse( $html ) );
		$cites = $this->cites( $entry );

		$this->assertCount( 1, $cites );
		$this->assertSame( [ 'Field recorder' ], $cites[0]['properties']['name'] );
		$this->assertSame( [ 'https://archive.example.test/recorder' ], $cites[0]['properties']['url'] );
		$this->assertSame( [ '2026-09-21' ], $cites[0]['properties']['published'] );
		$this->assertNotEmpty( $cites[0]['properties']['photo'] );
	}

	/** The Stream entry's published value comes from the collection post, never newest. */
	public function test_stream_entry_published_property_comes_from_the_post(): void {
		$content = $this->item( [ 'title' => 'Old reel', 'date' => '2020-03-04', 'addedAt' => '2026-10-05T10:00:00Z' ] );
		$id      = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Tape Cabinet',
				'post_date'    => '2026-10-10 14:30:00',
				'post_content' => wp_slash( $content ),
			]
		);
		wp_set_object_terms( $id, Collection_Items::KIND, Taxonomy::TAXONOMY );
		$post            = get_post( $id );
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		$html            = render_block(
			[
				'blockName'    => 'post-kinds-indieweb/stream-card',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$entry           = $this->entry( \Mf2\parse( $html ) );
		$published       = $entry['properties']['published'] ?? [];

		$this->assertNotEmpty( $published );
		$this->assertStringStartsWith( '2026-10-10', (string) $published[0] );
		$this->assertNotContains( '2020-03-04', $published );
	}
}

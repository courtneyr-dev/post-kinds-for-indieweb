<?php
/**
 * Parsed microformats coverage for wish singles and Stream listings.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * Verifies the wish HTML with the same parser consuming the public markup.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class WishMicroformatsTest extends WP_UnitTestCase {

	/**
	 * Register the wish environment and stable author identity.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pre_http_request', '__return_empty_array' );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Restore permalink and loop state.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_reset_postdata();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * Create one public card-only wish.
	 *
	 * @since 1.9.0
	 *
	 * @return int Post ID.
	 */
	private function wish(): int {
		$attrs = [
			'title'     => 'Fictional Lantern',
			'wishType'  => 'item',
			'url'       => 'https://shop.example/lantern',
			'image'     => 'https://images.example/lantern.jpg',
			'imageAlt'  => 'Paper lantern glowing at dusk',
			'price'     => '$48',
			'reason'    => 'Reading light for winter evenings',
			'priority'  => 'high',
			'wishedAt' => '2026-07-04',
		];
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_author'  => self::factory()->user->create( [ 'display_name' => 'Fictional Author' ] ),
				'post_title'   => 'Lantern wish',
				'post_date'    => '2026-07-05 10:00:00',
				'post_content' => '<!-- wp:post-kinds-indieweb/wish-card ' . serialize_block_attributes( $attrs ) . ' /-->',
			]
		);
		wp_set_object_terms( $post_id, 'wish', Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * Locate the first parsed item with a given microformat root type.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int|string, mixed> $tree Parsed tree.
	 * @param string                   $type Root type.
	 * @return array<string, mixed>|null Parsed item.
	 */
	private function find_item( array $tree, string $type ): ?array {
		if ( in_array( $type, $tree['type'] ?? [], true ) ) {
			return $tree;
		}
		foreach ( $tree as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( array_is_list( $value ) ) {
				foreach ( $value as $child ) {
					if ( is_array( $child ) ) {
						$found = $this->find_item( $child, $type );
						if ( null !== $found ) {
							return $found;
						}
					}
				}
				continue;
			}
			$found = $this->find_item( $value, $type );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Own-view wish markup parses as one cite with all five public properties.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_single_parses_name_url_photo_reason_and_wished_date(): void {
		$post_id = $this->wish();
		$this->go_to( get_permalink( $post_id ) );
		$html   = do_blocks( (string) get_post_field( 'post_content', $post_id ) );
		$parsed = \Mf2\parse( '<div class="h-entry">' . $html . '</div>', (string) get_permalink( $post_id ) );
		$entry  = $this->find_item( $parsed, 'h-entry' );
		$cite   = $this->find_item( $entry ?? [], 'h-cite' );

		$this->assertIsArray( $entry );
		$this->assertIsArray( $cite );
		$this->assertSame( [ 'Fictional Lantern' ], $cite['properties']['name'] );
		$this->assertSame( [ 'https://shop.example/lantern' ], $cite['properties']['wish-of'] );
		$this->assertSame( 'https://images.example/lantern.jpg', $cite['properties']['photo'][0]['value'] ?? $cite['properties']['photo'][0] );
		$this->assertSame( 'Paper lantern glowing at dusk', $cite['properties']['photo'][0]['alt'] ?? '' );
		$this->assertSame( [ 'Reading light for winter evenings' ], $cite['properties']['content'] );
		$this->assertSame( '2026-07-04', substr( (string) $cite['properties']['published'][0], 0, 10 ) );
	}

	/**
	 * The routed Stream card keeps entry date and author while citing the item as data.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_archive_stream_parses_entry_properties_and_hidden_wish_data(): void {
		$post_id = $this->wish();
		$url     = get_term_link( 'wish', Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$this->go_to( $url );
		$html = '';
		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $post_id ) {
				$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->' );
				break;
			}
		}
		$this->assertNotSame( '', $html, 'The wish appears in the archive loop.' );
		$html   = '<ul><li class="wp-block-post h-entry">' . $html . '</li></ul>';
		$parsed = \Mf2\parse( $html, $url );
		$entry  = $this->find_item( $parsed, 'h-entry' );

		$this->assertIsArray( $entry );
		$this->assertArrayHasKey( 'published', $entry['properties'] );
		$this->assertArrayHasKey( 'author', $entry['properties'] );
		$this->assertContains( 'h-card', $entry['properties']['author'][0]['type'] );
		$this->assertStringContainsString( '<data class="u-url u-wish-of" value="https://shop.example/lantern" hidden>', $html );
		$this->assertSame( 0, $this->xpath( $html )->query( '//a[contains(concat(" ", normalize-space(@class), " "), " u-wish-of ")]' )->length );
	}

	/**
	 * Parse HTML for link-shape assertions.
	 *
	 * @since 1.9.0
	 *
	 * @param string $html HTML.
	 * @return DOMXPath XPath object.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $dom );
	}
}

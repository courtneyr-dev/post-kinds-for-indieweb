<?php
/** Follow entry block coverage. @package PKIW */
declare(strict_types=1);

use PKIW\Follow_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class FollowEntryBlockTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function test_entry_has_one_link_and_hidden_follow_property(): void {
		$author = self::factory()->user->create( [ 'display_name' => 'Follow Author' ] );
		$id     = self::factory()->post->create( [ 'post_status' => 'publish', 'post_author' => $author ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_name', 'Lin Chen' );
		update_post_meta( $id, '_pkiw_cite_url', 'https://lin.example/' );
		$block = new WP_Block( [ 'blockName' => Follow_Archive::ENTRY_BLOCK, 'attrs' => [ 'headingLevel' => 2 ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'postId' => $id ] );
		$html  = $block->render();
		$this->assertSame( 1, substr_count( $html, '<a ' ), 'A second anchor would break the one-tab-stop card contract.' );
		$this->assertStringContainsString( '<article class="pkiw-follow-entry h-entry" data-pkiw-follow-initial="L">', $html );
		$this->assertStringContainsString( 'href="' . get_permalink( $id ) . '"', $html );
		$this->assertStringContainsString( '<h2 class="pkiw-follow-entry__name">', $html );
		$this->assertStringContainsString( '<p class="pkiw-follow-entry__host"', $html );
		$this->assertStringContainsString( 'class="u-follow-of" value="https://lin.example/"', $html );
		$this->assertStringContainsString( 'h-entry', $html );
		foreach ( [ 'Note', 'pkiw-follow-entry__kind', '_pkiw_cite_photo', 'https://lin.example/</a>', '>January ', '>February ' ] as $banned ) {
			$this->assertStringNotContainsString( $banned, $html );
		}
		$this->assertDoesNotMatchRegularExpression( '/<(?:button|input|select|textarea)\b|tabindex=/i', $html );

		$parsed = \Mf2\parse( $html, get_permalink( $id ) )['items'] ?? [];
		$this->assertCount( 1, $parsed );
		$this->assertSame( [ 'h-entry' ], $parsed[0]['type'] );
		foreach ( [ 'url', 'published', 'author', 'follow-of' ] as $property ) {
			$this->assertArrayHasKey( $property, $parsed[0]['properties'] );
		}
		$this->assertSame( 'https://lin.example/', $parsed[0]['properties']['follow-of'][0] );
		$this->assertArrayNotHasKey( 'card', $parsed[0]['properties'] );
	}

	public function test_repeated_host_names_print_the_host_for_the_accessible_label(): void {
		$ids = [];
		foreach ( [ 'first', 'second' ] as $slug ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
			update_post_meta( $id, '_pkiw_cite_url', 'https://shared.example/' . $slug . '/' );
			$ids[] = $id;
		}
		$previous_query             = $GLOBALS['wp_query'] ?? null;
		$GLOBALS['wp_query']        = new WP_Query();
		$GLOBALS['wp_query']->posts = array_map( 'get_post', $ids );
		try {
			$block = new WP_Block( [ 'blockName' => Follow_Archive::ENTRY_BLOCK, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'postId' => $ids[0] ] );
			$html  = $block->render();
			$this->assertStringContainsString( 'class="pkiw-follow-entry__host"', $html );
			$this->assertStringContainsString( 'aria-labelledby="pkiw-follow-' . $ids[0] . '-name pkiw-follow-' . $ids[0] . '-host"', $html, 'Dropping the repeat branch would leave two cards with the same accessible name.' );
		} finally {
			$GLOBALS['wp_query'] = $previous_query;
		}
	}

	public function test_host_source_omits_the_host_until_the_name_repeats(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_cite_url', 'https://host-source.example/' );
		$html = $this->render( $id );
		$this->assertStringNotContainsString( 'pkiw-follow-entry__host', $html, 'A host-derived name must not repeat its host.' );
	}

	public function test_first_four_photos_are_eager_and_the_fifth_is_lazy(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$ids   = [];
		foreach ( range( 1, 5 ) as $number ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			wp_set_object_terms( $id, 'follow', Taxonomy::TAXONOMY );
			update_post_meta( $id, '_pkiw_cite_name', 'Photo ' . $number );
			update_post_meta( $id, '_pkiw_cite_url', 'https://photo-' . $number . '.example/' );
			set_post_thumbnail( $id, $image );
			$ids[] = $id;
		}
		$previous_query             = $GLOBALS['wp_query'] ?? null;
		$GLOBALS['wp_query']        = new WP_Query();
		$GLOBALS['wp_query']->posts = array_map( 'get_post', $ids );
		try {
			foreach ( array_slice( $ids, 0, 4 ) as $id ) {
				$html = $this->render( $id );
				$this->assertStringContainsString( 'loading="eager"', $html );
				$this->assertStringContainsString( 'fetchpriority="high"', $html );
			}
			$this->assertStringContainsString( 'loading="lazy"', $this->render( $ids[4] ) );
			$this->assertStringNotContainsString( 'fetchpriority="high"', $this->render( $ids[4] ) );
		} finally {
			$GLOBALS['wp_query'] = $previous_query;
		}
	}

	private function render( int $post_id ): string {
		$block = new WP_Block( [ 'blockName' => Follow_Archive::ENTRY_BLOCK, 'attrs' => [ 'headingLevel' => 2 ], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'postId' => $post_id ] );
		return (string) $block->render();
	}
}

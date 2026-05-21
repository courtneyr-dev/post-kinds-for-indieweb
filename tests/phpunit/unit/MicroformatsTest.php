<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Microformats;
use PostKindsForIndieWeb\Taxonomy;
use PostKindsForIndieWeb\Meta_Fields;

class MicroformatsTest extends WP_UnitTestCase {

	private Microformats $microformats;

	public function set_up(): void {
		parent::set_up();
		$this->microformats = new Microformats();
	}

	// ------------------------------------------------------------------
	// get_kind_format: root class mapping
	// ------------------------------------------------------------------

	/**
	 * @dataProvider kind_root_classes_provider
	 */
	public function test_kind_root_class( string $kind, array $expected_root ) {
		$format = $this->microformats->get_kind_format( $kind );
		$this->assertNotNull( $format );
		$this->assertSame( $expected_root, $format['root'] );
	}

	public function kind_root_classes_provider(): array {
		return [
			'note'     => [ 'note',     [ 'h-entry' ] ],
			'article'  => [ 'article',  [ 'h-entry' ] ],
			'reply'    => [ 'reply',    [ 'h-entry' ] ],
			'like'     => [ 'like',     [ 'h-entry' ] ],
			'repost'   => [ 'repost',   [ 'h-entry' ] ],
			'bookmark' => [ 'bookmark', [ 'h-entry' ] ],
			'rsvp'     => [ 'rsvp',     [ 'h-entry' ] ],
			'checkin'  => [ 'checkin',  [ 'h-entry' ] ],
			'listen'   => [ 'listen',   [ 'h-entry' ] ],
			'watch'    => [ 'watch',    [ 'h-entry' ] ],
			'read'     => [ 'read',     [ 'h-entry' ] ],
			'photo'    => [ 'photo',    [ 'h-entry' ] ],
			'video'    => [ 'video',    [ 'h-entry' ] ],
			'event'    => [ 'event',    [ 'h-event' ] ],
			'review'   => [ 'review',   [ 'h-review' ] ],
			'recipe'   => [ 'recipe',   [ 'h-recipe' ] ],
		];
	}

	// ------------------------------------------------------------------
	// get_kind_format: property assertions
	// ------------------------------------------------------------------

	/**
	 * @dataProvider kind_property_provider
	 */
	public function test_kind_has_property( string $kind, string $property_key, string $expected_value ) {
		$format = $this->microformats->get_kind_format( $kind );
		$this->assertNotNull( $format );
		$this->assertArrayHasKey( $property_key, $format['properties'] );
		$this->assertSame( $expected_value, $format['properties'][ $property_key ] );
	}

	public function kind_property_provider(): array {
		return [
			'like: u-like-of'        => [ 'like',     'like-of',     'u-like-of' ],
			'bookmark: u-bookmark-of' => [ 'bookmark', 'bookmark-of', 'u-bookmark-of' ],
			'rsvp: p-rsvp'           => [ 'rsvp',     'rsvp',        'p-rsvp' ],
			'checkin: u-checkin'     => [ 'checkin',  'checkin',      'u-checkin h-card' ],
			'listen: u-listen-of'   => [ 'listen',   'listen-of',    'u-listen-of' ],
			'watch: u-watch-of'     => [ 'watch',    'watch-of',     'u-watch-of' ],
			'read: u-read-of'       => [ 'read',     'read-of',      'u-read-of' ],
			'event: dt-start'       => [ 'event',    'start',        'dt-start' ],
			'event: dt-end'         => [ 'event',    'end',          'dt-end' ],
			'review: p-rating'      => [ 'review',   'rating',       'p-rating' ],
			'review: p-best'        => [ 'review',   'best',         'p-best' ],
			'recipe: p-ingredient'  => [ 'recipe',   'ingredient',   'p-ingredient' ],
			'recipe: e-instructions' => [ 'recipe',  'instructions', 'e-instructions' ],
		];
	}

	// ------------------------------------------------------------------
	// get_kind_format: unknown kind
	// ------------------------------------------------------------------

	public function test_get_kind_format_returns_null_for_unknown() {
		$this->assertNull( $this->microformats->get_kind_format( 'nonexistent_kind_xyz' ) );
	}

	// ------------------------------------------------------------------
	// get_all_formats
	// ------------------------------------------------------------------

	public function test_get_all_formats_returns_non_empty_array() {
		$formats = $this->microformats->get_all_formats();
		$this->assertIsArray( $formats );
		$this->assertNotEmpty( $formats );
	}

	public function test_get_all_formats_contains_expected_kinds() {
		$formats = $this->microformats->get_all_formats();
		$expected = [ 'note', 'article', 'reply', 'like', 'repost', 'bookmark',
			'rsvp', 'checkin', 'listen', 'watch', 'read', 'event', 'photo',
			'video', 'review', 'recipe' ];

		foreach ( $expected as $kind ) {
			$this->assertArrayHasKey( $kind, $formats, "Missing kind: $kind" );
		}
	}

	// ------------------------------------------------------------------
	// add_post_classes
	// ------------------------------------------------------------------

	public function test_add_post_classes_defaults_to_h_entry_without_kind() {
		$post_id = self::factory()->post->create();
		$classes = $this->microformats->add_post_classes( [ 'post' ], [], $post_id );
		$this->assertContains( 'h-entry', $classes );
	}

	public function test_add_post_classes_adds_h_event_for_event_kind() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'event', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'event' ], Taxonomy::TAXONOMY );

		$classes = $this->microformats->add_post_classes( [ 'post' ], [], $post_id );
		$this->assertContains( 'h-event', $classes );
	}

	public function test_add_post_classes_adds_kind_slug_class() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'like', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'like' ], Taxonomy::TAXONOMY );

		$classes = $this->microformats->add_post_classes( [ 'post' ], [], $post_id );
		$this->assertContains( 'kind-like', $classes );
	}

	public function test_add_post_classes_no_duplicates() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'note', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'note' ], Taxonomy::TAXONOMY );

		$classes = $this->microformats->add_post_classes( [ 'h-entry' ], [], $post_id );
		$count   = array_count_values( $classes );
		$this->assertSame( 1, $count['h-entry'] );
	}

	public function test_add_post_classes_adds_h_review_for_review_kind() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'review', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'review' ], Taxonomy::TAXONOMY );

		$classes = $this->microformats->add_post_classes( [ 'post' ], [], $post_id );
		$this->assertContains( 'h-review', $classes );
		$this->assertContains( 'kind-review', $classes );
	}

	// ------------------------------------------------------------------
	// filter_block_output: empty content
	// ------------------------------------------------------------------

	public function test_filter_block_output_skips_empty_content() {
		$block    = [ 'blockName' => 'core/paragraph' ];
		$instance = $this->create_block_instance();

		$result = $this->microformats->filter_block_output( '', $block, $instance );
		$this->assertSame( '', $result );
	}

	// ------------------------------------------------------------------
	// filter_block_output: IndieBlocks blocks
	// ------------------------------------------------------------------

	/**
	 * @dataProvider indieblocks_provider
	 */
	public function test_filter_block_output_skips_indieblocks( string $block_name ) {
		$content  = '<div>Some content</div>';
		$block    = [ 'blockName' => $block_name ];
		$instance = $this->create_block_instance();

		$result = $this->microformats->filter_block_output( $content, $block, $instance );
		$this->assertSame( $content, $result, "Should skip IndieBlocks block: $block_name" );
	}

	public function indieblocks_provider(): array {
		return [
			'bookmark'     => [ 'indieblocks/bookmark' ],
			'like'         => [ 'indieblocks/like' ],
			'reply'        => [ 'indieblocks/reply' ],
			'repost'       => [ 'indieblocks/repost' ],
			'context'      => [ 'indieblocks/context' ],
			'facepile'     => [ 'indieblocks/facepile' ],
			'location'     => [ 'indieblocks/location' ],
			'syndication'  => [ 'indieblocks/syndication' ],
			'link-preview' => [ 'indieblocks/link-preview' ],
		];
	}

	// ------------------------------------------------------------------
	// add_hidden_mf2_data: RSVP
	// ------------------------------------------------------------------

	public function test_hidden_mf2_data_rsvp() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'rsvp', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'rsvp' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', 'yes' );

		$this->go_to( get_permalink( $post_id ) );
		// Force singular context and the_ID.
		global $wp_query;
		$wp_query->is_singular = true;
		$GLOBALS['post']       = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$result = $this->microformats->add_hidden_mf2_data( '<p>Content</p>' );

		$this->assertStringContainsString( 'p-rsvp', $result );
		$this->assertStringContainsString( 'value="yes"', $result );
		$this->assertStringContainsString( 'post-kinds-indieweb-mf2-data', $result );

		wp_reset_postdata();
	}

	// ------------------------------------------------------------------
	// add_hidden_mf2_data: Review
	// ------------------------------------------------------------------

	public function test_hidden_mf2_data_review() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'review', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'review' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '4' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_best', '5' );

		$this->go_to( get_permalink( $post_id ) );
		global $wp_query;
		$wp_query->is_singular = true;
		$GLOBALS['post']       = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$result = $this->microformats->add_hidden_mf2_data( '<p>Review</p>' );

		$this->assertStringContainsString( 'p-rating', $result );
		$this->assertStringContainsString( 'value="4"', $result );
		$this->assertStringContainsString( 'p-best', $result );
		$this->assertStringContainsString( 'value="5"', $result );

		wp_reset_postdata();
	}

	public function test_hidden_mf2_data_review_defaults_best_to_5() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'review', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'review' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '3' );
		// Do NOT set review_best -- should default to 5.

		$this->go_to( get_permalink( $post_id ) );
		global $wp_query;
		$wp_query->is_singular = true;
		$GLOBALS['post']       = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$result = $this->microformats->add_hidden_mf2_data( '<p>Review</p>' );

		$this->assertStringContainsString( 'p-best', $result );
		$this->assertStringContainsString( 'value="5"', $result );

		wp_reset_postdata();
	}

	// ------------------------------------------------------------------
	// add_hidden_mf2_data: Event
	// ------------------------------------------------------------------

	public function test_hidden_mf2_data_event() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'event', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'event' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'event_start', '2026-03-01T10:00:00' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'event_end', '2026-03-01T12:00:00' );

		$this->go_to( get_permalink( $post_id ) );
		global $wp_query;
		$wp_query->is_singular = true;
		$GLOBALS['post']       = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$result = $this->microformats->add_hidden_mf2_data( '<p>Event</p>' );

		$this->assertStringContainsString( 'dt-start', $result );
		$this->assertStringContainsString( 'datetime="2026-03-01T10:00:00"', $result );
		$this->assertStringContainsString( 'dt-end', $result );
		$this->assertStringContainsString( 'datetime="2026-03-01T12:00:00"', $result );

		wp_reset_postdata();
	}

	// ------------------------------------------------------------------
	// XSS prevention in hidden mf2 data
	// ------------------------------------------------------------------

	public function test_hidden_mf2_data_escapes_rsvp_xss() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'rsvp', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'rsvp' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', '"><script>alert(1)</script>' );

		$this->go_to( get_permalink( $post_id ) );
		global $wp_query;
		$wp_query->is_singular = true;
		$GLOBALS['post']       = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$result = $this->microformats->add_hidden_mf2_data( '<p>XSS test</p>' );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringNotContainsString( 'alert(1)', $result );

		wp_reset_postdata();
	}

	public function test_hidden_mf2_data_escapes_review_rating_xss() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'review', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'review' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '"><img src=x onerror=alert(1)>' );

		$this->go_to( get_permalink( $post_id ) );
		global $wp_query;
		$wp_query->is_singular = true;
		$GLOBALS['post']       = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		$result = $this->microformats->add_hidden_mf2_data( '<p>XSS test</p>' );

		$this->assertStringNotContainsString( 'onerror', $result );

		wp_reset_postdata();
	}

	// ------------------------------------------------------------------
	// add_hidden_mf2_data: no output on non-singular
	// ------------------------------------------------------------------

	public function test_hidden_mf2_data_returns_unchanged_on_non_singular() {
		$post_id = self::factory()->post->create();
		wp_insert_term( 'rsvp', Taxonomy::TAXONOMY );
		wp_set_post_terms( $post_id, [ 'rsvp' ], Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', 'yes' );

		// Force non-singular context.
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
		global $wp_query;
		$wp_query->is_singular = false;

		$content = '<p>Content</p>';
		$result  = $this->microformats->add_hidden_mf2_data( $content );

		$this->assertSame( $content, $result );

		wp_reset_postdata();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Create a minimal WP_Block instance for filter_block_output tests.
	 */
	private function create_block_instance(): \WP_Block {
		return new \WP_Block( [ 'blockName' => 'core/paragraph' ] );
	}
}

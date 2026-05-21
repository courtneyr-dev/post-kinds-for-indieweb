<?php
/**
 * Test the Block Bindings class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Block_Bindings;
use PostKindsForIndieWeb\Meta_Fields;

/**
 * Test the Block_Bindings class functionality.
 */
class BlockBindingsTest extends WP_UnitTestCase {

	/**
	 * Block_Bindings instance.
	 *
	 * @var Block_Bindings
	 */
	private Block_Bindings $block_bindings;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->block_bindings = new Block_Bindings();
	}

	/**
	 * Test that SOURCE_NAME constant is correct.
	 */
	public function test_source_name_constant() {
		$this->assertSame( 'post-kinds-indieweb/kind-meta', Block_Bindings::SOURCE_NAME );
	}

	/**
	 * Test that block bindings source is registered.
	 *
	 * The source is registered during plugin bootstrap via the init hook,
	 * so it should already exist by the time tests run.
	 */
	public function test_block_bindings_source_is_registered() {
		$sources = get_all_registered_block_bindings_sources();
		$this->assertArrayHasKey( Block_Bindings::SOURCE_NAME, $sources );
	}

	/**
	 * Test get_bindings returns non-empty array.
	 */
	public function test_get_bindings_returns_non_empty_array() {
		$bindings = $this->block_bindings->get_bindings();
		$this->assertIsArray( $bindings );
		$this->assertNotEmpty( $bindings );
	}

	/**
	 * Test that expected binding keys exist.
	 *
	 * @dataProvider binding_keys_provider
	 */
	public function test_binding_key_exists( string $key ) {
		$bindings = $this->block_bindings->get_bindings();
		$this->assertArrayHasKey( $key, $bindings );
	}

	/**
	 * Data provider for binding keys.
	 */
	public function binding_keys_provider(): array {
		return [
			'cite_name'      => [ 'cite_name' ],
			'cite_url'       => [ 'cite_url' ],
			'rsvp_status'    => [ 'rsvp_status' ],
			'checkin_name'   => [ 'checkin_name' ],
			'listen_track'   => [ 'listen_track' ],
			'listen_artist'  => [ 'listen_artist' ],
			'watch_title'    => [ 'watch_title' ],
			'read_title'     => [ 'read_title' ],
			'event_start'    => [ 'event_start' ],
			'review_rating'  => [ 'review_rating' ],
		];
	}

	/**
	 * Test is_valid_binding returns false for invalid key.
	 */
	public function test_is_valid_binding_returns_false_for_invalid_key() {
		$this->assertFalse( $this->block_bindings->is_valid_binding( 'nonexistent_key' ) );
	}

	/**
	 * Test is_valid_binding returns true for valid key.
	 */
	public function test_is_valid_binding_returns_true_for_valid_key() {
		$this->assertTrue( $this->block_bindings->is_valid_binding( 'cite_name' ) );
	}

	/**
	 * Test get_binding_value returns correct post meta value.
	 */
	public function test_get_binding_value_returns_meta_value() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'cite_name', 'Test Citation' );

		$block          = $this->create_mock_block( $post_id );
		$source_args    = [ 'key' => 'cite_name' ];
		$attribute_name = 'content';

		$result = $this->block_bindings->get_binding_value( $source_args, $block, $attribute_name );
		$this->assertSame( 'Test Citation', $result );
	}

	/**
	 * Test get_binding_value returns null for empty meta.
	 */
	public function test_get_binding_value_returns_null_for_empty_meta() {
		$post_id = self::factory()->post->create();

		$block          = $this->create_mock_block( $post_id );
		$source_args    = [ 'key' => 'cite_name' ];
		$attribute_name = 'content';

		$result = $this->block_bindings->get_binding_value( $source_args, $block, $attribute_name );
		$this->assertNull( $result );
	}

	/**
	 * Test get_binding_value returns null for invalid key.
	 */
	public function test_get_binding_value_returns_null_for_invalid_key() {
		$post_id = self::factory()->post->create();

		$block          = $this->create_mock_block( $post_id );
		$source_args    = [ 'key' => 'nonexistent_key' ];
		$attribute_name = 'content';

		$result = $this->block_bindings->get_binding_value( $source_args, $block, $attribute_name );
		$this->assertNull( $result );
	}

	/**
	 * Test get_binding_value returns null for empty key.
	 */
	public function test_get_binding_value_returns_null_for_empty_key() {
		$post_id = self::factory()->post->create();

		$block          = $this->create_mock_block( $post_id );
		$source_args    = [];
		$attribute_name = 'content';

		$result = $this->block_bindings->get_binding_value( $source_args, $block, $attribute_name );
		$this->assertNull( $result );
	}

	/**
	 * Test RSVP status formatting.
	 *
	 * @dataProvider rsvp_format_provider
	 */
	public function test_rsvp_status_formatting( string $status, string $expected_label ) {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', $status );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'rsvp_status' ], $block, 'content' );

		$this->assertSame( $expected_label, $result );
	}

	/**
	 * Data provider for RSVP status formatting.
	 */
	public function rsvp_format_provider(): array {
		return [
			'yes'        => [ 'yes', 'Yes, attending' ],
			'no'         => [ 'no', 'Not attending' ],
			'maybe'      => [ 'maybe', 'Maybe attending' ],
			'interested' => [ 'interested', 'Interested' ],
		];
	}

	/**
	 * Test RSVP status with invalid value returns null (sanitized to empty by meta registration).
	 */
	public function test_rsvp_status_invalid_value_returns_null() {
		$post_id = self::factory()->post->create();
		// Invalid values are sanitized to empty string by Meta_Fields::sanitize_rsvp_status.
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', 'custom_status' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'rsvp_status' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test computed listen_display field with track and artist.
	 */
	public function test_computed_listen_display_with_track_and_artist() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', 'Bohemian Rhapsody' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_artist', 'Queen' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'listen_display' ], $block, 'content' );

		$this->assertSame( 'Bohemian Rhapsody by Queen', $result );
	}

	/**
	 * Test computed listen_display field with track only.
	 */
	public function test_computed_listen_display_with_track_only() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', 'Unknown Track' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'listen_display' ], $block, 'content' );

		$this->assertSame( 'Unknown Track', $result );
	}

	/**
	 * Test computed listen_display field with no data returns null.
	 */
	public function test_computed_listen_display_with_no_data() {
		$post_id = self::factory()->post->create();

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'listen_display' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test computed coordinates field.
	 */
	public function test_computed_coordinates() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_latitude', '37.7749' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_longitude', '-122.4194' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'geo_coordinates' ], $block, 'content' );

		$this->assertSame( '37.7749, -122.4194', $result );
	}

	/**
	 * Test computed coordinates returns null when missing data.
	 */
	public function test_computed_coordinates_returns_null_when_missing() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_latitude', '37.7749' );
		// No longitude set.

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'geo_coordinates' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test computed full_address field.
	 */
	public function test_computed_full_address() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_address', '123 Main St' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_locality', 'Springfield' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_region', 'IL' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_country', 'US' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'checkin_full_address' ], $block, 'content' );

		$this->assertSame( '123 Main St, Springfield, IL, US', $result );
	}

	/**
	 * Test computed full_address with partial data.
	 */
	public function test_computed_full_address_partial() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_locality', 'Springfield' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_region', 'IL' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'checkin_full_address' ], $block, 'content' );

		$this->assertSame( 'Springfield, IL', $result );
	}

	/**
	 * Test computed full_address returns null when no data.
	 */
	public function test_computed_full_address_returns_null_when_empty() {
		$post_id = self::factory()->post->create();

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'checkin_full_address' ], $block, 'content' );

		$this->assertNull( $result );
	}

	/**
	 * Test computed watch_display field.
	 */
	public function test_computed_watch_display() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_title', 'Inception' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_year', '2010' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'watch_display' ], $block, 'content' );

		$this->assertSame( 'Inception (2010)', $result );
	}

	/**
	 * Test computed watch_display with title only.
	 */
	public function test_computed_watch_display_title_only() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_title', 'Inception' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'watch_display' ], $block, 'content' );

		$this->assertSame( 'Inception', $result );
	}

	/**
	 * Test computed read_progress for finished status.
	 */
	public function test_computed_read_progress_finished() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_status', 'finished' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'read_progress_display' ], $block, 'content' );

		$this->assertSame( 'Completed', $result );
	}

	/**
	 * Test computed read_progress with page numbers.
	 */
	public function test_computed_read_progress_with_pages() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_progress', '150' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_pages', '300' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'read_progress_display' ], $block, 'content' );

		$this->assertSame( 'Page 150 of 300', $result );
	}

	/**
	 * Test computed read_progress with percentage only.
	 */
	public function test_computed_read_progress_percentage() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_progress', '75' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'read_progress_display' ], $block, 'content' );

		$this->assertSame( '75% complete', $result );
	}

	/**
	 * Test computed rating_display field.
	 */
	public function test_computed_rating_display() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '4' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'review_rating_display' ], $block, 'content' );

		$this->assertSame( '4 out of 5', $result );
	}

	/**
	 * Test computed rating_display with custom best.
	 */
	public function test_computed_rating_display_custom_best() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '8' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_best', '10' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'review_rating_display' ], $block, 'content' );

		$this->assertSame( '8 out of 10', $result );
	}

	/**
	 * Test computed star_rating with full stars.
	 */
	public function test_computed_star_rating_full_stars() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '3' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'review_stars' ], $block, 'content' );

		$this->assertSame( "\xe2\x98\x85\xe2\x98\x85\xe2\x98\x85\xe2\x98\x86\xe2\x98\x86", $result );
	}

	/**
	 * Test computed star_rating with half star.
	 */
	public function test_computed_star_rating_half_star() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '3.5' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'review_stars' ], $block, 'content' );

		// 3 full + half + 1 empty = 5 total.
		$expected = "\xe2\x98\x85\xe2\x98\x85\xe2\x98\x85\xc2\xbd\xe2\x98\x86";
		$this->assertSame( $expected, $result );
	}

	/**
	 * Test computed star_rating with zero returns empty string.
	 */
	public function test_computed_star_rating_zero() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '0' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'review_stars' ], $block, 'content' );

		$this->assertSame( '', $result );
	}

	/**
	 * Test computed star_rating with 5 out of 5.
	 */
	public function test_computed_star_rating_perfect() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '5' );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'review_stars' ], $block, 'content' );

		$this->assertSame( "\xe2\x98\x85\xe2\x98\x85\xe2\x98\x85\xe2\x98\x85\xe2\x98\x85", $result );
	}

	/**
	 * Test watch_status formatting.
	 *
	 * @dataProvider watch_status_format_provider
	 */
	public function test_watch_status_formatting( string $status, string $expected_label ) {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_status', $status );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'watch_status' ], $block, 'content' );

		$this->assertSame( $expected_label, $result );
	}

	/**
	 * Data provider for watch status formatting.
	 */
	public function watch_status_format_provider(): array {
		return [
			'watched'   => [ 'watched', 'Watched' ],
			'watching'  => [ 'watching', 'Currently Watching' ],
			'abandoned' => [ 'abandoned', 'Abandoned' ],
		];
	}

	/**
	 * Test read_status formatting.
	 *
	 * @dataProvider read_status_format_provider
	 */
	public function test_read_status_formatting( string $status, string $expected_label ) {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'read_status', $status );

		$block  = $this->create_mock_block( $post_id );
		$result = $this->block_bindings->get_binding_value( [ 'key' => 'read_status' ], $block, 'content' );

		$this->assertSame( $expected_label, $result );
	}

	/**
	 * Data provider for read status formatting.
	 */
	public function read_status_format_provider(): array {
		return [
			'to-read'   => [ 'to-read', 'To Read' ],
			'reading'   => [ 'reading', 'Currently Reading' ],
			'finished'  => [ 'finished', 'Finished' ],
			'abandoned' => [ 'abandoned', 'Abandoned' ],
		];
	}

	/**
	 * Test get_bindings_for_editor returns array with string labels.
	 */
	public function test_get_bindings_for_editor_returns_labels() {
		$editor_bindings = $this->block_bindings->get_bindings_for_editor();

		$this->assertIsArray( $editor_bindings );
		$this->assertNotEmpty( $editor_bindings );

		// Each value should be a string label.
		foreach ( $editor_bindings as $key => $label ) {
			$this->assertIsString( $key, "Key should be a string: {$key}" );
			$this->assertIsString( $label, "Label for '{$key}' should be a string" );
			$this->assertNotEmpty( $label, "Label for '{$key}' should not be empty" );
		}

		// Verify some specific labels exist.
		$this->assertArrayHasKey( 'cite_name', $editor_bindings );
		$this->assertSame( 'Citation Title', $editor_bindings['cite_name'] );
	}

	/**
	 * Test get_bindings_for_editor has same keys as get_bindings.
	 */
	public function test_get_bindings_for_editor_matches_bindings_keys() {
		$bindings        = $this->block_bindings->get_bindings();
		$editor_bindings = $this->block_bindings->get_bindings_for_editor();

		$this->assertSame( array_keys( $bindings ), array_keys( $editor_bindings ) );
	}

	/**
	 * Create a mock WP_Block for testing.
	 *
	 * @param int $post_id Post ID to set in block context.
	 * @return \WP_Block Mock block instance.
	 */
	private function create_mock_block( int $post_id ): \WP_Block {
		$block = $this->createMock( \WP_Block::class );
		$block->context = [ 'postId' => $post_id ];

		return $block;
	}
}

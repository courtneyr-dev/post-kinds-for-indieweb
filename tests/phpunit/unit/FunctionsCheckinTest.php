<?php
/**
 * Test the checkin helper functions.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use function PostKindsForIndieWeb\get_checkins;
use function PostKindsForIndieWeb\get_checkins_at_venue;
use function PostKindsForIndieWeb\get_checkins_by_author;
use function PostKindsForIndieWeb\get_checkins_in_range;
use function PostKindsForIndieWeb\get_checkins_archive_url;
use function PostKindsForIndieWeb\is_checkin;
use function PostKindsForIndieWeb\get_checkin_venue;
use function PostKindsForIndieWeb\get_checkin_location;
use function PostKindsForIndieWeb\get_checkin_count;
use function PostKindsForIndieWeb\get_venue_count;
use function PostKindsForIndieWeb\format_location;

/**
 * Test the checkin helper functions.
 *
 * @covers \PostKindsForIndieWeb\get_checkins
 * @covers \PostKindsForIndieWeb\get_checkins_at_venue
 * @covers \PostKindsForIndieWeb\get_checkins_by_author
 * @covers \PostKindsForIndieWeb\get_checkins_in_range
 * @covers \PostKindsForIndieWeb\get_checkins_archive_url
 * @covers \PostKindsForIndieWeb\is_checkin
 * @covers \PostKindsForIndieWeb\get_checkin_venue
 * @covers \PostKindsForIndieWeb\get_checkin_location
 * @covers \PostKindsForIndieWeb\get_checkin_count
 * @covers \PostKindsForIndieWeb\get_venue_count
 * @covers \PostKindsForIndieWeb\format_location
 */
class FunctionsCheckinTest extends WP_UnitTestCase {

	/**
	 * Ensure taxonomies are registered.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! taxonomy_exists( 'indieblocks_kind' ) ) {
			register_taxonomy( 'indieblocks_kind', 'post' );
		}

		if ( ! taxonomy_exists( 'venue' ) ) {
			register_taxonomy( 'venue', 'post' );
		}
	}

	/**
	 * Create a checkin post with the indieblocks_kind taxonomy.
	 *
	 * @param array $args Post args.
	 * @return int Post ID.
	 */
	private function create_checkin_post( array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[ 'post_status' => 'publish' ],
				$args
			)
		);

		if ( ! term_exists( 'checkin', 'indieblocks_kind' ) ) {
			wp_insert_term( 'checkin', 'indieblocks_kind' );
		}

		wp_set_object_terms( $post_id, 'checkin', 'indieblocks_kind' );

		return $post_id;
	}

	/**
	 * Create a venue term with meta.
	 *
	 * @param string $name Venue name.
	 * @param array  $meta Venue meta.
	 * @return int Term ID.
	 */
	private function create_venue( string $name, array $meta = [] ): int {
		$result = wp_insert_term( $name, 'venue' );
		$term_id = $result['term_id'];

		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}

		return $term_id;
	}

	// ─── get_checkins ───

	/**
	 * Test get_checkins returns query object.
	 */
	public function test_get_checkins_returns_query(): void {
		$this->create_checkin_post();

		$query = get_checkins();

		$this->assertInstanceOf( \WP_Query::class, $query );
	}

	/**
	 * Test get_checkins finds checkin posts.
	 */
	public function test_get_checkins_finds_checkins(): void {
		$this->create_checkin_post();
		$this->create_checkin_post();
		// Regular post (not a checkin).
		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$query = get_checkins();

		$this->assertSame( 2, $query->post_count );
	}

	/**
	 * Test get_checkins with custom args.
	 */
	public function test_get_checkins_with_custom_args(): void {
		$this->create_checkin_post();
		$this->create_checkin_post();
		$this->create_checkin_post();

		$query = get_checkins( [ 'posts_per_page' => 2 ] );

		$this->assertSame( 2, $query->post_count );
	}

	/**
	 * Test get_checkins without checkin term returns all posts.
	 *
	 * When the checkin term doesn't exist, get_checkins skips the
	 * tax_query and returns all matching posts.
	 */
	public function test_get_checkins_without_term(): void {
		// Ensure no checkin term.
		$term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );
		if ( $term ) {
			wp_delete_term( $term->term_id, 'indieblocks_kind' );
		}

		self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$query = get_checkins();

		// Without the term, all posts are returned.
		$this->assertGreaterThanOrEqual( 1, $query->post_count );
	}

	// ─── get_checkins_at_venue ───

	/**
	 * Test get_checkins_at_venue filters by venue.
	 */
	public function test_get_checkins_at_venue(): void {
		$venue_id = $this->create_venue( 'Test Cafe' );

		$post_id = $this->create_checkin_post();
		wp_set_object_terms( $post_id, [ $venue_id ], 'venue' );

		// Checkin at different venue.
		$other_venue = $this->create_venue( 'Other Place' );
		$other_post  = $this->create_checkin_post();
		wp_set_object_terms( $other_post, [ $other_venue ], 'venue' );

		$query = get_checkins_at_venue( $venue_id );

		$this->assertSame( 1, $query->post_count );
		$this->assertSame( $post_id, $query->posts[0]->ID );
	}

	// ─── get_checkins_by_author ───

	/**
	 * Test get_checkins_by_author filters by author.
	 */
	public function test_get_checkins_by_author(): void {
		$user1 = self::factory()->user->create();
		$user2 = self::factory()->user->create();

		$this->create_checkin_post( [ 'post_author' => $user1 ] );
		$this->create_checkin_post( [ 'post_author' => $user2 ] );

		$query = get_checkins_by_author( $user1 );

		$this->assertSame( 1, $query->post_count );
	}

	// ─── get_checkins_in_range ───

	/**
	 * Test get_checkins_in_range with date range.
	 */
	public function test_get_checkins_in_range(): void {
		$this->create_checkin_post( [ 'post_date' => '2024-06-15 12:00:00' ] );
		$this->create_checkin_post( [ 'post_date' => '2024-01-01 12:00:00' ] );

		$query = get_checkins_in_range( '2024-06-01', '2024-07-01' );

		$this->assertSame( 1, $query->post_count );
	}

	/**
	 * Test get_checkins_in_range with only after date.
	 */
	public function test_get_checkins_in_range_after_only(): void {
		$this->create_checkin_post( [ 'post_date' => '2024-06-15 12:00:00' ] );
		$this->create_checkin_post( [ 'post_date' => '2023-01-01 12:00:00' ] );

		$query = get_checkins_in_range( '2024-01-01' );

		$this->assertSame( 1, $query->post_count );
	}

	// ─── get_checkins_archive_url ───

	/**
	 * Test get_checkins_archive_url returns home when no term.
	 */
	public function test_get_checkins_archive_url_no_term(): void {
		// Ensure no checkin term exists.
		$term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );
		if ( $term ) {
			wp_delete_term( $term->term_id, 'indieblocks_kind' );
		}

		$url = get_checkins_archive_url();

		$this->assertSame( home_url( '/' ), $url );
	}

	/**
	 * Test get_checkins_archive_url returns term link.
	 */
	public function test_get_checkins_archive_url_with_term(): void {
		if ( ! term_exists( 'checkin', 'indieblocks_kind' ) ) {
			wp_insert_term( 'checkin', 'indieblocks_kind' );
		}

		$url = get_checkins_archive_url();

		$this->assertStringContainsString( 'checkin', $url );
	}

	// ─── is_checkin ───

	/**
	 * Test is_checkin returns true for checkin post.
	 */
	public function test_is_checkin_true(): void {
		$post_id = $this->create_checkin_post();

		$this->assertTrue( is_checkin( $post_id ) );
	}

	/**
	 * Test is_checkin returns false for regular post.
	 */
	public function test_is_checkin_false(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( is_checkin( $post_id ) );
	}

	/**
	 * Test is_checkin returns false for null.
	 */
	public function test_is_checkin_null(): void {
		$this->assertFalse( is_checkin( null ) );
	}

	/**
	 * Test is_checkin returns false for page.
	 */
	public function test_is_checkin_page(): void {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assertFalse( is_checkin( $page_id ) );
	}

	// ─── get_checkin_venue ───

	/**
	 * Test get_checkin_venue returns venue term.
	 */
	public function test_get_checkin_venue(): void {
		$venue_id = $this->create_venue( 'Test Cafe' );
		$post_id  = $this->create_checkin_post();
		wp_set_object_terms( $post_id, [ $venue_id ], 'venue' );

		$venue = get_checkin_venue( $post_id );

		$this->assertInstanceOf( \WP_Term::class, $venue );
		$this->assertSame( 'Test Cafe', $venue->name );
	}

	/**
	 * Test get_checkin_venue returns null without venue.
	 */
	public function test_get_checkin_venue_null(): void {
		$post_id = $this->create_checkin_post();

		$this->assertNull( get_checkin_venue( $post_id ) );
	}

	/**
	 * Test get_checkin_venue returns null for invalid post.
	 */
	public function test_get_checkin_venue_invalid_post(): void {
		$this->assertNull( get_checkin_venue( 999999 ) );
	}

	// ─── get_checkin_location ───

	/**
	 * Test get_checkin_location from venue term meta.
	 */
	public function test_get_checkin_location_from_venue(): void {
		$venue_id = $this->create_venue( 'Test Cafe', [
			'address'   => '123 Main St',
			'city'      => 'Portland',
			'region'    => 'OR',
			'country'   => 'USA',
			'latitude'  => '45.5',
			'longitude' => '-122.6',
		] );

		$post_id = $this->create_checkin_post();
		wp_set_object_terms( $post_id, [ $venue_id ], 'venue' );

		$location = get_checkin_location( $post_id );

		$this->assertSame( 'Test Cafe', $location['name'] );
		$this->assertSame( '123 Main St', $location['address'] );
		$this->assertSame( 'Portland', $location['city'] );
		$this->assertSame( 'OR', $location['region'] );
		$this->assertSame( 'USA', $location['country'] );
		$this->assertSame( '45.5', $location['latitude'] );
		$this->assertSame( '-122.6', $location['longitude'] );
	}

	/**
	 * Test get_checkin_location from post meta fallback.
	 */
	public function test_get_checkin_location_from_post_meta(): void {
		$post_id = $this->create_checkin_post();
		update_post_meta( $post_id, '_postkind_checkin_venue', 'Some Place' );
		update_post_meta( $post_id, '_postkind_checkin_city', 'Seattle' );
		update_post_meta( $post_id, '_postkind_checkin_latitude', '47.6' );
		update_post_meta( $post_id, '_postkind_checkin_longitude', '-122.3' );

		$location = get_checkin_location( $post_id );

		$this->assertSame( 'Some Place', $location['name'] );
		$this->assertSame( 'Seattle', $location['city'] );
		$this->assertSame( '47.6', $location['latitude'] );
	}

	/**
	 * Test get_checkin_location returns empty for invalid post.
	 */
	public function test_get_checkin_location_invalid_post(): void {
		$this->assertSame( [], get_checkin_location( 999999 ) );
	}

	/**
	 * Test get_checkin_location filters empty values.
	 */
	public function test_get_checkin_location_filters_empty(): void {
		$post_id = $this->create_checkin_post();
		update_post_meta( $post_id, '_postkind_checkin_venue', 'Place' );
		// Don't set other meta.

		$location = get_checkin_location( $post_id );

		$this->assertArrayHasKey( 'name', $location );
		$this->assertArrayNotHasKey( 'city', $location );
		$this->assertArrayNotHasKey( 'latitude', $location );
	}

	// ─── get_checkin_count ───

	/**
	 * Test get_checkin_count returns count.
	 */
	public function test_get_checkin_count(): void {
		$this->create_checkin_post();
		$this->create_checkin_post();

		// Clean term cache to get accurate count.
		clean_term_cache( 0, 'indieblocks_kind' );

		$count = get_checkin_count();

		$this->assertSame( 2, $count );
	}

	/**
	 * Test get_checkin_count returns zero when no term.
	 */
	public function test_get_checkin_count_zero(): void {
		$term = get_term_by( 'slug', 'checkin', 'indieblocks_kind' );
		if ( $term ) {
			wp_delete_term( $term->term_id, 'indieblocks_kind' );
		}

		$this->assertSame( 0, get_checkin_count() );
	}

	// ─── get_venue_count ───

	/**
	 * Test get_venue_count.
	 */
	public function test_get_venue_count(): void {
		$this->create_venue( 'Venue 1' );
		$this->create_venue( 'Venue 2' );

		$count = get_venue_count();

		$this->assertSame( 2, $count );
	}

	/**
	 * Test get_venue_count with hide_empty.
	 */
	public function test_get_venue_count_hide_empty(): void {
		$venue_id = $this->create_venue( 'Used Venue' );
		$this->create_venue( 'Empty Venue' );

		$post_id = $this->create_checkin_post();
		wp_set_object_terms( $post_id, [ $venue_id ], 'venue' );

		$count = get_venue_count( true );

		$this->assertSame( 1, $count );
	}

	/**
	 * Test get_venue_count returns zero when no venues.
	 */
	public function test_get_venue_count_zero(): void {
		$this->assertSame( 0, get_venue_count() );
	}

	// ─── format_location ───

	/**
	 * Test format_location short format.
	 */
	public function test_format_location_short(): void {
		$location = [ 'name' => 'Test Cafe', 'city' => 'Portland' ];

		$this->assertSame( 'Test Cafe', format_location( $location, 'short' ) );
	}

	/**
	 * Test format_location medium format (default).
	 */
	public function test_format_location_medium(): void {
		$location = [
			'name'    => 'Test Cafe',
			'city'    => 'Portland',
			'country' => 'USA',
		];

		$this->assertSame( 'Test Cafe, Portland, USA', format_location( $location ) );
	}

	/**
	 * Test format_location full format.
	 */
	public function test_format_location_full(): void {
		$location = [
			'name'    => 'Test Cafe',
			'address' => '123 Main St',
			'city'    => 'Portland',
			'region'  => 'OR',
			'country' => 'USA',
		];

		$this->assertSame(
			'Test Cafe, 123 Main St, Portland, OR, USA',
			format_location( $location, 'full' )
		);
	}

	/**
	 * Test format_location microformat format.
	 */
	public function test_format_location_microformat(): void {
		$location = [
			'name'      => 'Test Cafe',
			'city'      => 'Portland',
			'latitude'  => '45.5',
			'longitude' => '-122.6',
		];

		$html = format_location( $location, 'microformat' );

		$this->assertStringContainsString( 'p-location h-adr', $html );
		$this->assertStringContainsString( 'p-name', $html );
		$this->assertStringContainsString( 'Test Cafe', $html );
		$this->assertStringContainsString( 'p-locality', $html );
		$this->assertStringContainsString( 'Portland', $html );
		$this->assertStringContainsString( 'h-geo', $html );
		$this->assertStringContainsString( 'p-latitude', $html );
		$this->assertStringContainsString( '45.5', $html );
	}

	/**
	 * Test format_location microformat without geo.
	 */
	public function test_format_location_microformat_no_geo(): void {
		$location = [ 'name' => 'Test Cafe' ];

		$html = format_location( $location, 'microformat' );

		$this->assertStringContainsString( 'p-name', $html );
		$this->assertStringNotContainsString( 'h-geo', $html );
	}

	/**
	 * Test format_location empty returns empty.
	 */
	public function test_format_location_empty(): void {
		$this->assertSame( '', format_location( [] ) );
	}

	/**
	 * Test format_location medium with partial data.
	 */
	public function test_format_location_medium_partial(): void {
		$location = [ 'name' => 'Test Cafe' ];

		$this->assertSame( 'Test Cafe', format_location( $location ) );
	}
}

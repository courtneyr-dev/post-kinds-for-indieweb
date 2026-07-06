<?php
/**
 * Integration tests for Default_Category.
 *
 * When a post has a post kind and the site configures a default category,
 * that category is applied once (on the wp_after_insert_post hook that fires
 * after the kind taxonomy is assigned — the same hook Taxonomy uses, so this
 * covers editor, REST, and Micropub/Outpost content alike).
 *
 * @package PostKindsForIndieWeb
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Tests\Integration;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Default_Category;

class DefaultCategoryTest extends WP_UnitTestCase {

	private int $activity_id;

	public function set_up(): void {
		parent::set_up();
		$this->activity_id = self::factory()->category->create( [ 'name' => 'Activity' ] );
		update_option( 'post_kinds_indieweb_settings', [ 'default_category' => $this->activity_id ] );
	}

	public function tear_down(): void {
		delete_option( 'post_kinds_indieweb_settings' );
		remove_all_filters( 'pkiw_default_category' );
		parent::tear_down();
	}

	/**
	 * Create a published post, give it a kind, and fire the after-insert hook
	 * the way core would once terms are assigned.
	 */
	private function make_kind_post( string $kind = 'watch' ): int {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, $kind, 'kind' );
		do_action( 'wp_after_insert_post', $post_id, get_post( $post_id ), false, null );
		return $post_id;
	}

	/** @return int[] */
	private function category_ids( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, 'category', [ 'fields' => 'ids' ] );
		return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
	}

	public function test_applies_default_category_to_kind_post(): void {
		$post_id = $this->make_kind_post( 'watch' );
		$this->assertContains( $this->activity_id, $this->category_ids( $post_id ) );
	}

	public function test_does_not_apply_to_post_without_a_kind(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		do_action( 'wp_after_insert_post', $post_id, get_post( $post_id ), false, null );
		$this->assertNotContains( $this->activity_id, $this->category_ids( $post_id ) );
	}

	public function test_does_nothing_when_no_default_configured(): void {
		update_option( 'post_kinds_indieweb_settings', [ 'default_category' => 0 ] );
		$post_id = $this->make_kind_post( 'listen' );
		$this->assertNotContains( $this->activity_id, $this->category_ids( $post_id ) );
	}

	public function test_preserves_existing_categories(): void {
		$other   = self::factory()->category->create( [ 'name' => 'Notes' ] );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, [ $other ], 'category' );
		wp_set_object_terms( $post_id, 'read', 'kind' );
		do_action( 'wp_after_insert_post', $post_id, get_post( $post_id ), false, null );
		$ids = $this->category_ids( $post_id );
		$this->assertContains( $this->activity_id, $ids );
		$this->assertContains( $other, $ids, 'existing categories must be kept' );
	}

	public function test_does_not_reapply_after_manual_removal(): void {
		$post_id = $this->make_kind_post( 'watch' );
		wp_remove_object_terms( $post_id, [ $this->activity_id ], 'category' );
		// A later save fires the hook again; the default must not come back.
		do_action( 'wp_after_insert_post', $post_id, get_post( $post_id ), true, null );
		$this->assertNotContains( $this->activity_id, $this->category_ids( $post_id ) );
	}

	public function test_filter_can_override_the_category(): void {
		$other = self::factory()->category->create( [ 'name' => 'Elsewhere' ] );
		add_filter( 'pkiw_default_category', static fn() => $other );
		$post_id = $this->make_kind_post( 'listen' );
		$ids     = $this->category_ids( $post_id );
		$this->assertContains( $other, $ids );
		$this->assertNotContains( $this->activity_id, $ids );
	}

	public function test_filter_returning_zero_skips_application(): void {
		add_filter( 'pkiw_default_category', static fn() => 0 );
		$post_id = $this->make_kind_post( 'watch' );
		$this->assertNotContains( $this->activity_id, $this->category_ids( $post_id ) );
	}

	public function test_backfill_applies_to_existing_kind_posts(): void {
		// Simulate a pre-feature post: kind set, no apply, no marker.
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'read', 'kind' );
		delete_post_meta( $post_id, Default_Category::APPLIED_META );
		$this->assertNotContains( $this->activity_id, $this->category_ids( $post_id ) );

		$stats = ( new Default_Category() )->backfill( false );

		$this->assertContains( $this->activity_id, $this->category_ids( $post_id ) );
		$this->assertGreaterThanOrEqual( 1, $stats['updated'] );
	}

	public function test_backfill_dry_run_changes_nothing(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'read', 'kind' );

		$stats = ( new Default_Category() )->backfill( true );

		$this->assertNotContains( $this->activity_id, $this->category_ids( $post_id ) );
		$this->assertGreaterThanOrEqual( 1, $stats['would_update'] );
	}
}

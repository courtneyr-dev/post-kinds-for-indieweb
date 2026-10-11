<?php
/**
 * Bookmark archive drawers by tag name (issue 258).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Term_Source;
use PKIW\Taxonomy;

/**
 * The bookmark archive files bookmarks under their first tag A to Z, with
 * untagged and protected posts in the empty drawer. Names and URLs are invented.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class BookmarkArchiveTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Set up the bookmark archive fixtures.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		update_option( 'posts_per_page', 20 );
	}

	/**
	 * Restore test globals.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * A published bookmark.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $title    Post title.
	 * @param string   $date     Post date.
	 * @param array<int|string> $tags Tag names, slugs or integer term ids.
	 * @param string   $password Post password.
	 * @return int
	 */
	private function bookmark( string $title, string $date, array $tags = [], string $password = '' ): int {
		$id = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_date'     => $date,
				'post_password' => $password,
				'post_content'  => '<!-- wp:paragraph --><p>Saved link.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $id, 'bookmark', Taxonomy::TAXONOMY );
		if ( [] !== $tags ) {
			wp_set_object_terms( $id, $tags, 'post_tag' );
		}

		return $id;
	}

	/**
	 * Bookmark post ids in the REST grouped order.
	 *
	 * @since 1.9.0
	 *
	 * @return int[]
	 */
	private function rest_ids_by_group(): array {
		$GLOBALS['wp_rest_server'] = null;
		$request                   = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_param( 'kind', [ get_term_by( 'slug', 'bookmark', Taxonomy::TAXONOMY )->term_id ] );
		$request->set_param( 'orderby', 'pkiw_group' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );
	}

	/**
	 * The plugin registers the bookmark tag source.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_the_plugin_registers_the_bookmark_tag_source(): void {
		$source = Grouped_Archive::source( 'bookmark_tags' );

		$this->assertInstanceOf( Term_Source::class, $source );
		$this->assertTrue( $source->files_protected_as_empty() );
		$this->assertSame( '', $source->empty_label() );
		$this->assertContains( 'bookmark', Grouped_Archive::grouped_kinds() );
	}

	/**
	 * Drawers sort by the database collation, then empty last.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_drawers_run_a_to_z_in_the_collation_order_with_the_empty_drawer_last(): void {
		$terms = [];
		foreach (
			[
				'Alpha'             => 'alpha',
				'beta'              => 'beta',
				'Delta'             => 'delta',
				'Gamma'             => 'gamma',
				'Work–life balance' => 'work-life-balance',
				'social media'      => 'social-media',
				'Social Media'      => 'social-media-2',
			] as $name => $slug
		) {
			$created = wp_insert_term( $name, 'post_tag', [ 'slug' => $slug ] );
			$this->assertIsArray( $created );
			$terms[ (int) $created['term_id'] ] = $name;
		}

		$posts_by_term = [];
		$index         = 0;
		foreach ( $terms as $term_id => $name ) {
			$posts_by_term[ $term_id ] = $this->bookmark( 'Bookmark for ' . $name, '2026-04-' . sprintf( '%02d', 10 + $index ) . ' 10:00:00', [ $term_id ] );
			++$index;
		}
		$untagged_old = $this->bookmark( 'No tag old', '2026-04-01 10:00:00' );
		$untagged_new = $this->bookmark( 'No tag new', '2026-04-02 10:00:00' );

		global $wpdb;
		$ids    = implode( ',', array_map( 'intval', array_keys( $terms ) ) );
		$oracle = $wpdb->get_results( "SELECT term_id, name FROM {$wpdb->terms} WHERE term_id IN ({$ids}) ORDER BY name COLLATE utf8mb4_unicode_520_ci ASC, term_id ASC" );
		$this->assertIsArray( $oracle );

		$expected = [];
		foreach ( $oracle as $row ) {
			$expected[] = $posts_by_term[ (int) $row->term_id ];
		}
		$expected[] = $untagged_new;
		$expected[] = $untagged_old;

		$this->assertSame( $expected, $this->rest_ids_by_group() );

		$oracle_names = array_map( static fn( $row ): string => (string) $row->name, $oracle );
		$lower        = array_search( 'social media', $oracle_names, true );
		$upper        = array_search( 'Social Media', $oracle_names, true );
		$this->assertIsInt( $lower );
		$this->assertIsInt( $upper );
		$this->assertSame( $lower + 1, $upper );
	}

	/**
	 * Posts inside one drawer are newest first, then higher id first.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_posts_inside_a_drawer_run_newest_first_then_higher_id_first(): void {
		$old         = $this->bookmark( 'Old alpha', '2026-04-01 10:00:00', [ 'Alpha' ] );
		$new         = $this->bookmark( 'New alpha', '2026-04-03 10:00:00', [ 'Alpha' ] );
		$same_first  = $this->bookmark( 'Same first', '2026-04-02 10:00:00', [ 'Alpha' ] );
		$same_second = $this->bookmark( 'Same second', '2026-04-02 10:00:00', [ 'Alpha' ] );
		$this->assertGreaterThan( $same_first, $same_second );

		$this->assertSame( [ $new, $same_second, $same_first, $old ], $this->rest_ids_by_group() );
	}

	/**
	 * A multi-tag post files under its first tag by A to Z order.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_multi_tag_post_files_under_its_first_tag_a_to_z(): void {
		$multi = $this->bookmark( 'Multi tag', '2026-04-03 10:00:00', [ 'zeta', 'Alpha' ] );
		$beta  = $this->bookmark( 'Beta tag', '2026-04-02 10:00:00', [ 'beta' ] );

		$group = Grouped_Archive::group_of_post( 'bookmark', $multi );
		$this->assertNotNull( $group );
		$this->assertSame( 'Alpha', $group->raw() );
		$this->assertSame( [ $multi, $beta ], $this->rest_ids_by_group() );
	}

	/**
	 * Protected bookmarks file in the empty drawer.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_protected_bookmark_files_in_the_empty_drawer(): void {
		$alpha      = $this->bookmark( 'Alpha public', '2026-04-01 10:00:00', [ 'Alpha' ] );
		$untagged   = $this->bookmark( 'Untagged public', '2026-04-02 10:00:00' );
		$protected  = $this->bookmark( 'Alpha protected', '2026-04-03 10:00:00', [ 'Alpha' ], 'secret' );
		$space_lock = $this->bookmark( 'Space protected', '2026-04-04 10:00:00', [ 'Alpha' ], '  ' );

		$group = Grouped_Archive::group_of_post( 'bookmark', $protected );
		$this->assertNotNull( $group );
		$this->assertSame( '', $group->key() );
		$this->assertTrue( $group->is_empty() );
		$this->assertSame( 'Other', Grouped_Archive::group_label_of_post( 'bookmark', $protected ) );

		$this->assertSame( [ $alpha, $space_lock, $protected, $untagged ], $this->rest_ids_by_group() );
	}

	/**
	 * Drawer labels use stored tag names verbatim.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_drawer_labels_are_the_stored_tag_names_verbatim(): void {
		$id = $this->bookmark( 'Lowercase social', '2026-04-01 10:00:00', [ 'social media' ] );

		$this->assertSame( 'social media', Grouped_Archive::group_label_of_post( 'bookmark', $id ) );
	}

	/**
	 * Empty and untagged-only archives still have a stable order.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_an_empty_kind_and_all_untagged_posts_still_order(): void {
		$this->assertSame( [], $this->rest_ids_by_group() );

		$old = $this->bookmark( 'Old no tag', '2026-04-01 10:00:00' );
		$new = $this->bookmark( 'New no tag', '2026-04-02 10:00:00' );

		$this->assertSame( [ $new, $old ], $this->rest_ids_by_group() );
	}
}

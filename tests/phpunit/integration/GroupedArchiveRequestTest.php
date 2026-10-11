<?php
/**
 * Bookmark archive requests with tag filters (issue 258).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Taxonomy;

/**
 * Bookmark tag filters keep the kind archive as the queried object while
 * still pinning the requested tag drawer. Names and URLs are invented.
 *
 * @since 1.9.0
 *
 * @group integration
 */
final class GroupedArchiveRequestTest extends WP_UnitTestCase {

	/**
	 * Stylesheet active before each test.
	 *
	 * @since 1.9.0
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Bookmark fixture ids.
	 *
	 * @since 1.9.0
	 *
	 * @var array<string, int>
	 */
	private array $bookmarks = [];

	/**
	 * Eat fixture ids.
	 *
	 * @since 1.9.0
	 *
	 * @var array<string, int>
	 */
	private array $eats = [];

	/**
	 * Set up rewrite and request fixtures.
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
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		$this->fixtures();
	}

	/**
	 * Restore rewrite and theme globals.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * A published kind post.
	 *
	 * @since 1.9.0
	 *
	 * @param string   $kind  Kind slug.
	 * @param string   $title Post title.
	 * @param string   $date  Post date.
	 * @param string[] $tags  Tag names.
	 * @return int
	 */
	private function post( string $kind, string $title, string $date, array $tags = [] ): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_date'    => $date,
				'post_content' => '<!-- wp:paragraph --><p>Fixture.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		if ( [] !== $tags ) {
			wp_set_object_terms( $id, $tags, 'post_tag' );
		}

		return $id;
	}

	/**
	 * Shared request fixtures.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	private function fixtures(): void {
		wp_insert_term( 'Alpha', 'post_tag', [ 'slug' => 'alpha' ] );
		wp_insert_term( 'beta', 'post_tag', [ 'slug' => 'beta' ] );
		wp_insert_term( 'zeta', 'post_tag', [ 'slug' => 'zeta' ] );

		$this->bookmarks = [
			'alpha_new' => $this->post( 'bookmark', 'Alpha new bookmark', '2026-05-03 10:00:00', [ 'Alpha' ] ),
			'alpha_old' => $this->post( 'bookmark', 'Alpha old bookmark', '2026-05-01 10:00:00', [ 'Alpha' ] ),
			'beta'      => $this->post( 'bookmark', 'Beta bookmark', '2026-05-04 10:00:00', [ 'beta' ] ),
			'untagged'  => $this->post( 'bookmark', 'Untagged bookmark', '2026-05-05 10:00:00' ),
			'multi'     => $this->post( 'bookmark', 'Zeta and Alpha bookmark', '2026-05-02 10:00:00', [ 'zeta', 'Alpha' ] ),
		];
		$this->eats      = [
			'alpha' => $this->post( 'eat', 'Alpha lunch', '2026-05-03 12:00:00', [ 'Alpha' ] ),
			'beta'  => $this->post( 'eat', 'Beta dinner', '2026-05-02 12:00:00', [ 'beta' ] ),
			'plain' => $this->post( 'eat', 'Plain breakfast', '2026-05-01 12:00:00' ),
		];
	}

	/**
	 * Current main-query post ids.
	 *
	 * @since 1.9.0
	 *
	 * @return int[]
	 */
	private function query_ids(): array {
		return array_map( static fn( WP_Post $post ): int => (int) $post->ID, $GLOBALS['wp_query']->posts );
	}

	/**
	 * Bookmark archive with a tag keeps the kind queried object.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_tag_on_the_bookmark_archive_keeps_the_kind_as_the_queried_object(): void {
		$this->go_to( 'http://example.org/kind/bookmark/?tag=alpha' );

		$this->assertTrue( is_tax( Taxonomy::TAXONOMY, 'bookmark' ) );
		$this->assertFalse( is_tag() );
		$queried = get_queried_object();
		$this->assertInstanceOf( WP_Term::class, $queried );
		$this->assertSame( Taxonomy::TAXONOMY, $queried->taxonomy );
		$this->assertSame( 'bookmark', $queried->slug );
		$this->assertArrayHasKey( 'post_tag', $GLOBALS['wp_query']->tax_query->queried_terms );
		$this->assertSame( [ $this->bookmarks['alpha_new'], $this->bookmarks['multi'], $this->bookmarks['alpha_old'] ], $this->query_ids() );

		$source = Grouped_Archive::source( 'bookmark_tags' );
		$this->assertNotNull( $source );
		foreach ( $GLOBALS['wp_query']->posts as $post ) {
			$this->assertSame( 'Alpha', $source->group_of( $post, $GLOBALS['wp_query'] )->raw() );
		}
	}

	/**
	 * A tag filter pins that drawer for a multi-tag post.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_a_tag_filter_pins_that_tags_drawer_for_a_multi_tag_post(): void {
		$this->go_to( 'http://example.org/kind/bookmark/?tag=zeta' );
		$source = Grouped_Archive::source( 'bookmark_tags' );
		$this->assertNotNull( $source );
		$this->assertSame( [ $this->bookmarks['multi'] ], $this->query_ids() );
		$this->assertSame( 'zeta', $source->group_of( get_post( $this->bookmarks['multi'] ), $GLOBALS['wp_query'] )->raw() );

		$this->go_to( 'http://example.org/kind/bookmark/' );
		$this->assertSame( 'Alpha', $source->group_of( get_post( $this->bookmarks['multi'] ), $GLOBALS['wp_query'] )->raw() );
	}

	/**
	 * Other kinds keep core tag archive behavior.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_other_kinds_keep_core_tag_archive_behavior(): void {
		$this->go_to( 'http://example.org/kind/eat/?tag=alpha' );

		$this->assertTrue( is_tag() );
		$this->assertContains( $this->eats['alpha'], $this->query_ids() );
	}

	/**
	 * Bookmark feeds keep date order and no group vars.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_the_bookmark_feed_is_newest_first_and_sets_no_group_vars(): void {
		$term = get_term_by( 'slug', 'bookmark', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );

		$this->go_to( get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) );

		$this->assertTrue( is_feed() );
		foreach ( [ 'pkiw_group_source', 'pkiw_group_by', 'pkiw_group_order', 'pkiw_group_empty' ] as $var ) {
			$this->assertSame( '', $GLOBALS['wp_query']->get( $var ) );
		}
		$this->assertSame(
			[
				$this->bookmarks['untagged'],
				$this->bookmarks['beta'],
				$this->bookmarks['alpha_new'],
				$this->bookmarks['multi'],
				$this->bookmarks['alpha_old'],
			],
			$this->query_ids()
		);
	}

	/**
	 * Eat feeds keep date order and no group vars.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function test_the_eat_feed_is_newest_first_and_sets_no_group_vars(): void {
		$term = get_term_by( 'slug', 'eat', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );

		$this->go_to( get_term_feed_link( $term->term_id, Taxonomy::TAXONOMY ) );

		$this->assertTrue( is_feed() );
		foreach ( [ 'pkiw_group_source', 'pkiw_group_by', 'pkiw_group_order', 'pkiw_group_empty' ] as $var ) {
			$this->assertSame( '', $GLOBALS['wp_query']->get( $var ) );
		}
		$this->assertSame( [ $this->eats['alpha'], $this->eats['beta'], $this->eats['plain'] ], $this->query_ids() );
	}
}

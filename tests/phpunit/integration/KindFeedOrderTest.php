<?php
/**
 * Kind feeds stay newest first when the archive is grouped (gate G1).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Cases_Source;

/**
 * A kind archive whose template holds an entry block groups its main query.
 * The kind's feed is the same main query with `feed` set, so before the
 * guard it took the grouping vars and the template's page size along. Feed
 * readers expect newest first, so a feed keeps the date order and the
 * site's posts_per_rss, and the HTML archive still groups. Titles and
 * values are invented.
 *
 * @group integration
 */
final class KindFeedOrderTest extends WP_UnitTestCase {

	private const SOURCE = 'w1feed_play';

	/**
	 * Stylesheet active before each test.
	 *
	 * @var string
	 */
	private string $original_stylesheet = '';

	/**
	 * Points the play kind at the fixture source.
	 *
	 * @var callable
	 */
	private $pick_source;

	/**
	 * Template content swapped in for the kind's archive.
	 *
	 * @var string
	 */
	private string $template = '';

	/**
	 * Swaps the template content in.
	 *
	 * @var callable
	 */
	private $swap;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		update_option( 'posts_per_page', 10 );
		update_option( 'posts_per_rss', 3 );

		Grouped_Archive::register_source( new Cases_Source( self::SOURCE, [ 'board' => [ '_pkiw_w1feed_bgg' ] ] ) );
		$this->pick_source = static fn( $id, string $kind ) => 'play' === $kind ? self::SOURCE : $id;
		add_filter( 'pkiw_archive_group_source', $this->pick_source, 10, 2 );

		$this->swap = function ( $templates ) {
			foreach ( $templates as $found ) {
				if ( str_starts_with( $found->slug, 'taxonomy-kind' ) ) {
					$found->content = $this->template;
				}
			}
			return $templates;
		};
		add_filter( 'get_block_templates', $this->swap );
	}

	public function tear_down(): void {
		remove_filter( 'get_block_templates', $this->swap );
		remove_filter( 'pkiw_archive_group_source', $this->pick_source, 10 );
		Grouped_Archive::unregister_source( self::SOURCE );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	/**
	 * Each grouped kind: its template's entry block, the meta it groups by,
	 * and the query var that carries the grouping on the HTML archive.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public function grouped_kinds(): array {
		return [
			'eat menu'      => [ 'eat', 'post-kinds-indieweb/menu-entry', '_pkiw_eat_cuisine', 'pkiw_group_by' ],
			'drink menu'    => [ 'drink', 'post-kinds-indieweb/menu-entry', '_pkiw_drink_type', 'pkiw_group_by' ],
			'play sections' => [ 'play', 'post-kinds-indieweb/archive-sections', '_pkiw_w1feed_bgg', 'pkiw_group_source' ],
		];
	}

	/**
	 * Five posts of a kind. Grouped order differs from date order, and two
	 * share a date so the ID tiebreak shows.
	 *
	 * @param string $kind Kind slug.
	 * @param string $key  Meta key the kind groups by.
	 * @return int[] Post IDs, newest first, ID DESC within a date.
	 */
	private function posts( string $kind, string $key ): array {
		$rows = [
			[ '2026-08-05 10:00:00', 'zeta' ],
			[ '2026-08-04 10:00:00', '' ],
			[ '2026-08-04 10:00:00', 'alpha' ],
			[ '2026-08-03 10:00:00', 'zeta' ],
			[ '2026-08-02 10:00:00', 'alpha' ],
		];
		$ids  = [];
		foreach ( $rows as $i => [ $date, $value ] ) {
			$id = self::factory()->post->create(
				[
					'post_status' => 'publish',
					'post_title'  => "{$kind} {$i}",
					'post_date'   => $date,
				]
			);
			wp_set_object_terms( $id, $kind, 'kind' );
			if ( '' !== $value ) {
				add_post_meta( $id, $key, $value );
			}
			$ids[] = $id;
		}

		// Newest first; the two on 2026-08-04 by ID DESC.
		[ $ids[1], $ids[2] ] = [ $ids[2], $ids[1] ];

		return $ids;
	}

	/**
	 * An inheriting Query Loop holding the entry block with a page size of 20.
	 *
	 * @param string $entry Entry block name.
	 */
	private function grouped_template( string $entry ): string {
		return '<!-- wp:query {"queryId":3,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- wp:' . $entry . ' {"linesPerPage":20} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * The kind's RSS feed URL. get_term_feed_link() escapes its ampersand for
	 * HTML, so the URL is built unescaped here.
	 *
	 * @param string $kind Kind slug.
	 */
	private function feed_url( string $kind ): string {
		$link = get_term_link( $kind, 'kind' );
		$this->assertIsString( $link );

		return add_query_arg( 'feed', 'rss2', $link );
	}

	/**
	 * Grouping vars the main query carries.
	 *
	 * @return array<string, mixed>
	 */
	private function grouping_vars(): array {
		global $wp_query;

		return array_filter(
			(array) $wp_query->query_vars,
			static fn( $value, $name ): bool => str_starts_with( (string) $name, 'pkiw_group' ) && '' !== $value && null !== $value,
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * @dataProvider grouped_kinds
	 */
	public function test_the_kind_feed_carries_no_grouping_and_keeps_posts_per_rss( string $kind, string $entry, string $key ): void {
		$this->posts( $kind, $key );
		$this->template = $this->grouped_template( $entry );

		$this->go_to( $this->feed_url( $kind ) );
		global $wp_query;

		$this->assertTrue( $wp_query->is_feed() && $wp_query->is_tax( 'kind', $kind ), 'The request is the kind feed.' );
		$this->assertSame( [], $this->grouping_vars(), 'No pkiw_group_* var reaches the feed query.' );
		$this->assertSame( 3, (int) $wp_query->get( 'posts_per_page' ), 'The feed shows posts_per_rss, not the archive\'s 20.' );
		$this->assertSame( 3, $wp_query->post_count );
	}

	/**
	 * @dataProvider grouped_kinds
	 */
	public function test_the_kind_feed_orders_newest_first_with_an_id_tiebreak( string $kind, string $entry, string $key ): void {
		$ids            = $this->posts( $kind, $key );
		$this->template = $this->grouped_template( $entry );
		update_option( 'posts_per_rss', 10 );

		$this->go_to( $this->feed_url( $kind ) );
		global $wp_query, $wpdb;

		$this->assertSame( $ids, array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ) );
		$this->assertStringContainsString( "ORDER BY {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC", $wp_query->request );
	}

	/**
	 * @dataProvider grouped_kinds
	 */
	public function test_the_html_archive_still_groups( string $kind, string $entry, string $key, string $var ): void {
		$ids            = $this->posts( $kind, $key );
		$this->template = $this->grouped_template( $entry );

		$this->go_to( get_term_link( $kind, 'kind' ) );
		global $wp_query;

		$this->assertFalse( $wp_query->is_feed() );
		$this->assertNotEmpty( $wp_query->get( $var ), "The archive groups through {$var}." );
		$this->assertSame( 20, (int) $wp_query->get( 'posts_per_page' ), 'The entry block sizes the archive page.' );
		$this->assertNotSame( $ids, array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) ), 'Grouped order is not date order.' );
	}
}

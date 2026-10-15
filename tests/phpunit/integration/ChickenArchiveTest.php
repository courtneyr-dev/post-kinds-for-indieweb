<?php
/**
 * The chicken archive: local-month sections and 24-line pages (issue 250).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Taxonomy;

/**
 * /kind/chicken/ is served by the plugin chicken template and month source.
 *
 * @group integration
 */
final class ChickenArchiveTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private int $original_posts_per_page = 0;

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet      = get_stylesheet();
		$this->original_timezone        = (string) get_option( 'timezone_string' );
		$this->original_posts_per_page  = (int) get_option( 'posts_per_page' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'posts_per_page', 5 );
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		update_option( 'posts_per_page', $this->original_posts_per_page );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_chicken_archive_sections_paginate_by_month_with_no_empty_sections(): void {
		$ids      = $this->seed_archive_posts();
		$page_one = $this->sections( $this->serve( $this->archive_url() ) );
		global $wp_query;

		$this->assertSame( 24, (int) $wp_query->get( 'posts_per_page' ) );
		$this->assertSame( 40, (int) $wp_query->found_posts );
		$this->assertSame( 2, (int) $wp_query->max_num_pages );
		$this->assertSame(
			[
				'October 2026' => 4,
				'August 2026'  => 14,
				'July 2026'    => 6,
			],
			$page_one
		);

		$page_two = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );
		$this->assertSame(
			[
				'July 2026'  => 1,
				'June 2026'  => 12,
				'May 2026'   => 1,
				'March 2026' => 2,
			],
			$page_two
		);
		$this->assertArrayNotHasKey( 'April 2026', $page_two );

		$august = $this->serve( $this->archive_url() );
		$this->assertStringContainsString( 'post-' . $ids['august_late'], $august );
		$this->assertStringContainsString( 'post-' . $ids['august_early'], $august );
	}

	public function test_order_counts_group_keys_and_feed_guard_match_the_frozen_contract(): void {
		$ids = $this->seed_archive_posts();
		$this->serve( $this->archive_url() );
		global $wp_query;

		$count_query = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'tax_query'      => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'chicken' ] ],
			]
		);
		$this->assertSame( $count_query->found_posts, $wp_query->found_posts );
		wp_reset_postdata();

		$page_ids = array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) );
		$sorted   = $page_ids;
		usort(
			$sorted,
			static function ( int $a, int $b ): int {
				$date = strcmp( get_post_field( 'post_date', $b ), get_post_field( 'post_date', $a ) );
				return 0 !== $date ? $date : $b <=> $a;
			}
		);
		$this->assertSame( $sorted, $page_ids );

		$this->assertSame( '2026-08', Grouped_Archive::group_of_post( 'chicken', $ids['august_late'] )->key() );
		$this->assertSame( '2026-08', Grouped_Archive::group_of_post( 'chicken', $ids['august_early'] )->key() );

		$this->go_to( get_term_feed_link( get_term_by( 'slug', 'chicken', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY ) );
		$this->assertTrue( is_feed() );
		$feed_ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( array_slice( $sorted, 0, count( $feed_ids ) ), $feed_ids );
		$this->assertSame( '', (string) $GLOBALS['wp_query']->get( 'pkiw_group_source' ) );
	}

	public function test_zero_chicken_posts_prints_no_month_section_or_entry_list(): void {
		$html = $this->serve( $this->archive_url() );

		$this->assertStringNotContainsString( 'pkiw-chicken__month', $html );
		$this->assertStringNotContainsString( 'pkiw-chicken__entries', $html );
	}

	/**
	 * @return array<string, int>
	 */
	private function seed_archive_posts(): array {
		$ids = [];
		foreach ( [ 4, 3, 2, 1 ] as $day ) {
			$ids[] = $this->chicken( 'October hen ' . $day, sprintf( '2026-10-%02d 10:00:00', $day ) );
		}
		$ids['august_late'] = $this->chicken( 'August late hen', '2026-08-31 23:30:00' );
		foreach ( range( 1, 12 ) as $i ) {
			$ids[] = $this->chicken( 'August hen ' . $i, sprintf( '2026-08-%02d 10:00:00', 31 - $i ) );
		}
		$ids['august_early'] = $this->chicken( 'August early hen', '2026-08-01 00:30:00' );
		foreach ( [ 30, 26, 22, 18, 14, 9, 3 ] as $day ) {
			$ids[] = $this->chicken( 'July hen ' . $day, sprintf( '2026-07-%02d 10:00:00', $day ) );
		}
		foreach ( range( 28, 6, -2 ) as $day ) {
			$ids[] = $this->chicken( 'June hen ' . $day, sprintf( '2026-06-%02d 10:00:00', $day ) );
		}
		$ids[] = $this->chicken( 'May hen', '2026-05-15 10:00:00' );
		$ids[] = $this->chicken( 'March hen 20', '2026-03-20 10:00:00' );
		$ids[] = $this->chicken( 'March hen 04', '2026-03-04 10:00:00' );

		$this->chicken( 'Private hen', '2026-07-20 10:00:00', [ 'post_status' => 'private' ] );
		$this->chicken( 'Draft hen', '2026-07-19 10:00:00', [ 'post_status' => 'draft' ] );
		$note = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Plain note', 'post_date' => '2026-10-11 10:00:00' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );

		return $ids;
	}

	private function chicken( string $title, string $date, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => $date,
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'chicken', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function archive_url(): string {
		$url = get_term_link( 'chicken', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/chicken/', $url );

		return $url;
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-chicken.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * @return array<string, int>
	 */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[contains(concat(" ",@class," ")," pkiw-chicken__month ")]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			if ( $heading instanceof DOMElement ) {
				$out[ trim( $heading->textContent ) ] = $xpath->query( './ol[contains(concat(" ",@class," ")," pkiw-chicken__entries ")]/li', $section )->length;
			}
		}
		return $out;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}
}

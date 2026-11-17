<?php
/** On This Day block coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/** @group integration */
final class OnThisDayBlocksTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		On_This_Day::register_blocks();
		$this->today = new DateTimeImmutable( '2026-09-21 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
		On_This_Day::reset();
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		On_This_Day::reset();
		remove_all_filters( 'pkiw_otd_edge_purge' );
		remove_all_filters( 'pkiw_on_this_day_kinds' );
		parent::tear_down();
	}

	public function test_both_php_blocks_are_registered_with_matching_defaults(): void {
		$card = WP_Block_Type_Registry::get_instance()->get_registered( On_This_Day::BLOCK );
		$entry = WP_Block_Type_Registry::get_instance()->get_registered( On_This_Day::ENTRY_BLOCK );
		$this->assertNotNull( $card );
		$this->assertNotNull( $entry );
		$this->assertSame( 'stream', $card->attributes['context']['default'] );
		$this->assertSame( 3, $card->attributes['headingLevel']['default'] );
		$this->assertSame( 3, $entry->attributes['headingLevel']['default'] );
	}

	public function test_single_is_off_without_edge_opt_in_and_never_renders_inside_a_query_loop(): void {
		$viewed = $this->post( 'Viewed', '2025-09-21 10:00:00' );
		$this->post( 'Earlier', '2024-09-21 10:00:00' );
		$this->go_to( get_permalink( $viewed ) );
		$this->assertSame( '', On_This_Day::render_block( [ 'context' => 'single' ] ) );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$loop = new WP_Block( [ 'blockName' => On_This_Day::BLOCK, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'queryId' => 1 ] );
		$this->assertSame( '', On_This_Day::render_block( [ 'context' => 'single' ], '', $loop ) );
	}

	public function test_single_renders_nothing_outside_post_singular_in_feed_or_without_pick(): void {
		$viewed = $this->post( 'Guard viewed', '2025-09-21 10:00:00' );
		$this->post( 'Guard earlier', '2024-09-21 10:00:00' );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', On_This_Day::render_block( [ 'context' => 'single' ] ) );
		$this->go_to( get_permalink( $viewed ) );
		$GLOBALS['wp_query']->is_feed = true;
		$this->assertSame( '', On_This_Day::render_block( [ 'context' => 'single' ] ) );
		$GLOBALS['wp_query']->is_feed = false;
		$no_pick = $this->post( 'No-pick viewed', '2025-09-22 10:00:00' );
		$this->go_to( get_permalink( $no_pick ) );
		$this->assertSame( '', On_This_Day::render_block( [ 'context' => 'single' ] ) );
	}

	public function test_branch_a_single_chooses_nearest_year_then_time_and_has_accessible_mf2_markup(): void {
		$viewed = $this->post( 'Viewed', '2025-09-21 10:00:00' );
		$this->post( 'Older', '2022-09-21 23:00:00' );
		$winner = $this->post( 'Winner', '2024-09-21 20:00:00' );
		$this->post( 'Earlier same year', '2024-09-21 08:00:00' );
		$this->go_to( get_permalink( $viewed ) );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$html = On_This_Day::render_block( [ 'context' => 'single', 'headingLevel' => 3 ] );
		$this->assertStringContainsString( 'On this day in 2024', $html );
		$this->assertStringContainsString( get_permalink( $winner ), $html );
		$this->assertStringContainsString( 'class="pkiw-otd pkiw-otd--single h-cite"', $html );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		$this->assertStringNotContainsString( 'h-entry', $html );
		$this->assertStringNotContainsString( get_permalink( $viewed ), $html );
	}

	public function test_derived_title_has_no_p_name_and_safe_checkin_names_no_venue(): void {
		$viewed = $this->post( 'Derived viewed', '2026-09-21 10:00:00' );
		$derived = $this->post( '', '2025-09-21 10:00:00' );
		wp_update_post( [ 'ID' => $derived, 'post_content' => 'Derived title words' ] );
		$this->go_to( get_permalink( $viewed ) );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$html = On_This_Day::render_block( [ 'context' => 'single' ] );
		$this->assertStringContainsString( 'class="u-url"', $html );
		$this->assertStringNotContainsString( 'u-url p-name', $html );

		$include_checkin = static function ( array $kinds ): array { $kinds[] = 'checkin'; return $kinds; };
		add_filter( 'pkiw_on_this_day_kinds', $include_checkin );
		wp_update_post( [ 'ID' => $derived, 'post_status' => 'private' ] );
		$venue = 'OTD268 Secret Venue';
		$checkin = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Checked in at ' . $venue, 'post_date' => '2025-09-21 12:00:00' ] );
		wp_set_object_terms( $checkin, 'checkin', Taxonomy::TAXONOMY );
		update_post_meta( $checkin, '_pkiw_checkin_name', $venue );
		update_post_meta( $checkin, '_pkiw_geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $checkin );
		$html = On_This_Day::render_block( [ 'context' => 'single' ] );
		$this->assertStringContainsString( '>Check-in, September 21, 2025</a>', $html );
		$this->assertStringNotContainsString( $venue, $html );
	}

	public function test_single_output_depends_on_viewed_date_not_today_and_never_enters_content_or_rss(): void {
		$viewed = $this->post( 'Stable viewed', '2025-09-21 10:00:00' );
		$this->post( 'Stable earlier', '2024-09-21 10:00:00' );
		$this->go_to( get_permalink( $viewed ) );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		On_This_Day::override_now( new DateTimeImmutable( '2026-01-01 12:00:00', wp_timezone() ) );
		$first = On_This_Day::render_block( [ 'context' => 'single' ] );
		On_This_Day::override_now( new DateTimeImmutable( '2027-12-31 12:00:00', wp_timezone() ) );
		$second = On_This_Day::render_block( [ 'context' => 'single' ] );
		$this->assertSame( $first, $second );
		$content = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $viewed ) );
		$this->assertStringNotContainsString( 'pkiw-otd', $content );
		$GLOBALS['post'] = get_post( $viewed );
		setup_postdata( $GLOBALS['post'] );
		$GLOBALS['wp_query']->is_feed = true;
		$this->assertStringNotContainsString( 'pkiw-otd', get_the_content_feed( 'rss2' ) );
	}

	public function test_archive_entry_returns_inner_markup_only_and_rechecks_visibility(): void {
		$id = $this->post( 'Archive entry', '2025-09-21 10:00:00' );
		$html = On_This_Day::item_html( get_post( $id ), true, 'note' );
		$this->assertStringNotContainsString( '<li', $html );
		$this->assertSame( 1, substr_count( $html, '<a' ) );
		$this->assertStringContainsString( 'p-author h-card', $html );
		$this->assertStringContainsString( 'dt-published', $html );
		wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] );
		$this->assertSame( '', On_This_Day::item_html( get_post( $id ), true, 'note' ) );
	}

	public function test_archive_markup_has_one_root_link_author_and_year_relationship_per_item(): void {
		$kind_label = static fn( string $label, string $kind, string $context ): string => 'VERBATIM KIND LABEL';
		add_filter( 'pkiw_kind_label', $kind_label, 10, 3 );
		$first = $this->post( 'Same archive title', '2025-09-21 12:00:00' );
		$second = $this->post( 'Same archive title', '2024-09-21 11:00:00' );
		$first_image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $first );
		$second_image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $second );
		set_post_thumbnail( $first, $first_image );
		set_post_thumbnail( $second, $second_image );
		$GLOBALS['wp_query'] = new WP_Query( On_This_Day::query_args( $this->today, [ 'pkiw_group_source' => On_This_Day::SOURCE, 'pkiw_group_order' => 'DESC', 'posts_per_page' => 20 ] ) );
		$markup = '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . On_This_Day::ENTRY_BLOCK . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$html = do_blocks( $markup );
		remove_filter( 'pkiw_kind_label', $kind_label, 10 );

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new DOMXPath( $document );
		$this->assertSame( 0, $xpath->query( '//li//li' )->length );
		$items = $xpath->query( '//li[contains(concat(" ", normalize-space(@class), " "), " wp-block-post ")]' );
		$this->assertSame( 2, $items->length );
		foreach ( $items as $item ) {
			$this->assertSame( 1, $xpath->query( './/a', $item )->length );
			$this->assertSame( 1, $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " p-author ")]', $item )->length );
			$this->assertSame( 1, $xpath->query( './/data[contains(concat(" ", normalize-space(@class), " "), " dt-published ") and @hidden]', $item )->length );
			$parsed = \Mf2\parse( (string) $document->saveHTML( $item ) );
			$this->assertCount( 1, $parsed['items'] ?? [] );
		}
		$this->assertSame( 2, substr_count( $html, 'VERBATIM KIND LABEL' ) );
		$this->assertStringContainsString( 'alt=""', $html );
		$this->assertSame( 1, substr_count( $html, 'loading="eager"' ) );
		$this->assertSame( 1, substr_count( $html, 'fetchpriority="high"' ) );
		$this->assertSame( 1, substr_count( $html, 'loading="lazy"' ) );
		$this->assertStringContainsString( 'aria-labelledby="pkiw-group-2025 pkiw-otd-title-' . $first . '"', $html );
		$this->assertStringContainsString( 'aria-labelledby="pkiw-group-2024 pkiw-otd-title-' . $second . '"', $html );
	}

	private function post( string $title, string $date ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => $date ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		return $id;
	}
}

<?php
/** On This Day block coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
use PKIW\Taxonomy;

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

	private function post( string $title, string $date ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => $date ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		return $id;
	}
}

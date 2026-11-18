<?php
/** Wrapped block rendering coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Wrapped_Blocks;
use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedBlocksTest extends WrappedTestCase {
	public function test_main_block_markup_accessible_text_and_rating_label(): void {
		for ( $index = 0; $index < 3; ++$index ) {
			$this->read_post( 2026, [ 'read_pages' => 100, 'read_rating' => 4, 'read_cover' => 'https://example.invalid/cover-' . $index . '.jpg' ], [ 'post_title' => 'Top read ' . $index ] );
		}
		$wrapped = $this->wrapped_post();
		$html    = $this->render_wrapped( $wrapped );
		$this->assertSame( 1, $this->tag_count( $html, 'UL', 'pkiw-wrapped__metrics' ), 'The main block must print one metric list; html=' . $html );
		$this->assertSame( 1, $this->tag_count( $html, 'H2' ) );
		$this->assertSame( 3, $this->tag_count( $html, 'IMG' ) );
		$this->assertSame( 3, $this->tag_count( $html, 'A' ) );
		$this->assertStringContainsString( '300 Pages from 3 finished', $this->html_text( $html ) );
		$this->assertStringContainsString( 'Rated 4 of 5', $this->html_text( $html ) );
		$this->assertStringNotContainsString( 'Rated 4.0 of 5', $this->html_text( $html ) );
		$this->assertStringNotContainsString( 'h-entry', $html );
		$this->assertStringNotContainsString( 'read-of', $html );
		$processor = new WP_HTML_Tag_Processor( $html );
		$this->assertTrue( $processor->next_tag( [ 'tag_name' => 'SECTION', 'class_name' => 'pkiw-wrapped' ] ) );
		$this->assertNull( $processor->get_attribute( 'aria-labelledby' ) );
		while ( $processor->next_tag( [ 'tag_name' => 'IMG' ] ) ) {
			$this->assertSame( '', $processor->get_attribute( 'alt' ) );
		}
	}

	public function test_top_item_covers_sit_in_the_theme_fallback_container(): void {
		$this->read_post( 2026, [ 'read_rating' => 5, 'read_author' => 'Avery Example', 'read_cover' => 'https://example.invalid/cover.jpg' ], [ 'post_title' => 'Covered read' ] );
		$this->read_post( 2026, [ 'read_rating' => 4, 'read_author' => 'Jordan Example' ], [ 'post_title' => 'Uncovered read' ] );
		$html      = $this->render_wrapped( $this->wrapped_post() );
		$processor = new WP_HTML_Tag_Processor( $html );
		$covers    = [];
		while ( $processor->next_tag( [ 'tag_name' => 'SPAN', 'class_name' => 'pkiw-wrapped__cover' ] ) ) {
			$this->assertTrue( $processor->get_attribute( 'data-cr-cover-fallback' ), 'site-ux.js marks a failed cover only inside data-cr-cover-fallback; html=' . $html );
			$this->assertSame( 'true', $processor->get_attribute( 'aria-hidden' ) );
			$covers[ (string) $processor->get_attribute( 'data-title' ) ] = [
				'author' => (string) $processor->get_attribute( 'data-author' ),
				'type'   => (bool) $processor->has_class( 'pkiw-wrapped__cover--type' ),
			];
		}
		$this->assertSame(
			[
				'Covered read'   => [ 'author' => 'Avery Example', 'type' => false ],
				'Uncovered read' => [ 'author' => 'Jordan Example', 'type' => true ],
			],
			$covers
		);
		$this->assertSame( 1, $this->tag_count( $html, 'IMG' ), 'Only the covered read prints an image.' );
		$this->assertSame( 2, $this->tag_count( $html, 'A' ), 'The cover container adds no link.' );
	}

	public function test_wrapped_single_roots_as_h_entry_without_a_kind_property(): void {
		$this->read_post( 2026, [ 'read_rating' => 5 ], [ 'post_title' => 'Counted read' ] );
		$author  = (int) self::factory()->user->create( [ 'display_name' => 'Wrapped Author' ] );
		$wrapped = $this->wrapped_post( 'read', 2026, [], [ 'post_author' => $author ] );
		$this->assertSame( [], wp_get_object_terms( $wrapped, 'kind', [ 'fields' => 'ids' ] ) );
		$this->go_to( get_permalink( $wrapped ) );
		$this->assertTrue( have_posts() );
		the_post();
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $wrapped ) );
		$this->assertStringContainsString( 'pkiw-singular-entry', $html );
		$items = \Mf2\parse( $html )['items'];
		$this->assertCount( 1, $items, 'One root; html=' . $html );
		$entry = $items[0];
		$this->assertSame( [ 'h-entry' ], $entry['type'] );
		$this->assertSame( [ get_the_title( $wrapped ) ], $entry['properties']['name'] ?? null );
		$this->assertSame( [ get_permalink( $wrapped ) ], $entry['properties']['url'] ?? null );
		$this->assertArrayHasKey( 'published', $entry['properties'] );
		$this->assertArrayHasKey( 'author', $entry['properties'] );
		$this->assertArrayNotHasKey( 'read-of', $entry['properties'] );
		wp_reset_postdata();
	}

	public function test_metrics_follow_configured_order_and_drop_unknown_keys(): void {
		for ( $index = 0; $index < 3; ++$index ) {
			$this->read_post( 2026, [ 'read_pages' => 100, 'read_rating' => 4 ] );
		}
		$wrapped = $this->wrapped_post( 'read', 2026, [ 'metrics' => [ 'average_rating', 'finished', 'unknown', 'pages' ] ] );
		$text    = $this->html_text( $this->render_wrapped( $wrapped, [ 'metrics' => [ 'average_rating', 'finished', 'unknown', 'pages' ] ] ) );
		$this->assertLessThan( strpos( $text, '3 Finished' ), strpos( $text, '4.0 Average from 3 rated' ), 'Average must precede Finished; text=' . $text );
		$this->assertLessThan( strpos( $text, '300 Pages from 3 finished' ), strpos( $text, '3 Finished' ), 'Finished must precede Pages; text=' . $text );
		$this->assertStringNotContainsString( 'unknown', $text );
	}

	public function test_saved_year_is_used_and_top_count_never_shapes_cache(): void {
		$prior = max( 2000, (int) wp_date( 'Y' ) - 1 );
		for ( $index = 0; $index < 8; ++$index ) {
			$this->read_post( $prior, [ 'read_rating' => 5 ], [ 'post_title' => 'Prior ' . $index ] );
		}
		$this->read_post( (int) wp_date( 'Y' ), [ 'read_rating' => 1 ], [ 'post_title' => 'Current sentinel' ] );
		$wrapped = $this->wrapped_post( 'read', $prior );
		foreach ( [ [ 3, 5 ], [ 5, 3 ] ] as [ $first, $second ] ) {
			$this->assertSame( $first, $this->tag_count( $this->render_wrapped( $wrapped, [ 'topCount' => $first ] ), 'LI' ) - count( Wrapped_Stats::compute( 'read', $prior )['metrics'] ) );
			$this->assertSame( $second, $this->tag_count( $this->render_wrapped( $wrapped, [ 'topCount' => $second ] ), 'LI' ) - count( Wrapped_Stats::compute( 'read', $prior )['metrics'] ) );
		}
		$this->assertStringNotContainsString( 'Current sentinel', $this->render_wrapped( $wrapped ) );
		$this->assertGreaterThanOrEqual( 8, count( Wrapped_Stats::for_kind_year( 'read', $prior )['top'] ) );
	}

	public function test_host_gate_and_editor_preview_placeholder_have_no_digits(): void {
		$this->read_post();
		$draft = $this->wrapped_post( 'read', 2026, [], [ 'post_status' => 'draft' ] );
		$this->assertSame( '', $this->render_wrapped( $draft ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/wrapped' );
		$request->set_query_params( [ 'post_id' => $draft, 'context' => 'edit', 'attributes' => [ 'kind' => 'read', 'year' => 2026 ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$rendered = (string) $response->get_data()['rendered'];
		$this->assertSame( 'Numbers show once this post is published and public.', $this->html_text( $rendered ) );
		$this->assertSame( preg_replace( '/[0-9]/', '', $this->html_text( $rendered ) ), $this->html_text( $rendered ) );
	}

	public function test_strip_absence_presence_count_line_and_main_query_invariance(): void {
		$this->read_post();
		$this->go_to( get_term_link( 'read', 'kind' ) );
		$before = [ $GLOBALS['wp_query']->found_posts, $GLOBALS['wp_query']->get( 'posts_per_page' ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), $GLOBALS['wp_query']->max_num_pages ];
		$this->assertSame( '', $this->render_strip( [] ) );
		$this->wrapped_post( 'read', 2026, [], [ 'post_status' => 'draft' ] );
		$this->assertSame( '', $this->render_strip( [] ), 'draft Wrapped' );
		$quiet = $this->wrapped_post();
		update_post_meta( $quiet, 'activitypub_content_visibility', 'quiet_public' );
		$this->assertSame( '', $this->render_strip( [] ), 'quiet Wrapped' );
		$wrapped = $this->wrapped_post();
		$html    = $this->render_strip( [] );
		$this->assertSame( 1, $this->tag_count( $html, 'A' ), 'The strip must print one link; html=' . $html );
		$this->assertStringContainsString( get_the_title( $wrapped ), $this->html_text( $html ) );
		$this->assertLessThanOrEqual( 2, substr_count( $this->html_text( $html ), ' · ' ) + 1 );
		$after = [ $GLOBALS['wp_query']->found_posts, $GLOBALS['wp_query']->get( 'posts_per_page' ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), $GLOBALS['wp_query']->max_num_pages ];
		$this->assertSame( $before, $after );
		// is_paged() reads the main query's flag, which parse_query() sets for paged > 1.
		$GLOBALS['wp_query']->is_paged = true;
		$this->assertSame( '', $this->render_strip( [] ), 'page 2' );
		$GLOBALS['wp_query']->is_paged = false;
		$this->assertSame( '', $this->render_strip( [ 'year' => 0 ] ) );
	}
}

<?php
/**
 * RSVP timing source coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Grouping\Rsvp_Timing_Source;
use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpTimingSourceTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		add_filter( 'pkiw_rsvp_now', [ $this, 'fixed_now' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pkiw_rsvp_now', [ $this, 'fixed_now' ] );
		parent::tear_down();
	}

	public function fixed_now(): string {
		return '2026-10-08 22:00:00';
	}

	private function rsvp( string $start, string $date, string $password = '' ): int {
		$content = '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( [ 'eventStart' => $start ] ) . ' /-->';
		$id      = self::factory()->post->create(
			[
				'post_status'   => 'publish',
				'post_title'    => $start ?: 'No start',
				'post_content'  => $content,
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
				'post_password' => $password,
			]
		);
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );
		Rsvp_Archive::refresh_sort( $id );

		return (int) $id;
	}

	public function test_sort_value_handles_instants_days_and_malformed_times(): void {
		$this->assertSame( '2026-10-08 22:00:00', Rsvp_Archive::sort_value( '2026-10-08T18:00' ) );
		$this->assertSame( '2026-10-09 03:59:59', Rsvp_Archive::sort_value( '2026-10-08' ) );
		$this->assertSame( '2026-10-08 18:00:00', Rsvp_Archive::sort_value( '2026-10-08T18:00:00Z' ) );
		$this->assertSame( '', Rsvp_Archive::sort_value( '2026-10-08T25:00' ) );
	}

	public function test_sql_and_php_group_rules_agree_at_the_boundary_and_for_protected_posts(): void {
		$past      = $this->rsvp( '2026-10-08T17:59', '2026-10-01 10:00:00' );
		$boundary  = $this->rsvp( '2026-10-08T18:00', '2026-10-02 10:00:00' );
		$future    = $this->rsvp( '2026-10-08T18:01', '2026-10-03 10:00:00' );
		$date_only = $this->rsvp( '2026-10-08', '2026-10-04 10:00:00' );
		$no_start  = $this->rsvp( '', '2026-10-05 10:00:00' );
		$protected = $this->rsvp( '2026-10-09T18:00', '2026-09-01 10:00:00', 'pass' );

		$source = new Rsvp_Timing_Source();
		$this->assertSame( 'past', $source->group_of( get_post( $past ) )->key() );
		$this->assertSame( 'upcoming', $source->group_of( get_post( $boundary ) )->key() );
		$this->assertSame( 'upcoming', $source->group_of( get_post( $future ) )->key() );
		$this->assertSame( 'upcoming', $source->group_of( get_post( $date_only ) )->key() );
		$this->assertSame( 'past', $source->group_of( get_post( $no_start ) )->key() );
		$this->assertSame( 'past', $source->group_of( get_post( $protected ) )->key() );

		$query = new WP_Query();
		$sql   = $source->sql( $query );
		$this->assertStringContainsString( "THEN 'upcoming' ELSE 'past'", $sql['value'] );
		$this->assertStringContainsString( 'ASC', $sql['within'][0] );
		$this->assertStringContainsString( 'post_date_gmt', $sql['within'][1] );
	}

	public function test_grouped_query_orders_upcoming_ascending_then_past_descending_at_nine_per_page(): void {
		$ids = [];
		foreach ( range( 1, 10 ) as $minute ) {
			$ids[] = $this->rsvp( sprintf( '2026-10-09T18:%02d', $minute ), sprintf( '2026-10-%02d 09:00:00', $minute ) );
		}
		foreach ( range( 1, 10 ) as $minute ) {
			$ids[] = $this->rsvp( sprintf( '2026-10-07T18:%02d', $minute ), sprintf( '2026-09-%02d 09:00:00', $minute ) );
		}

		$template = '<!-- wp:query {"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/rsvp-entry {"linesPerPage":9} /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$probe    = new WP_Query();
		$plan     = Grouped_Archive::main_query_plan( $probe, 'rsvp', $template );
		$this->assertSame( 9, $plan['per_page'] );

		$query = new WP_Query(
			array_merge(
				[
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => $plan['per_page'],
				],
				$plan['vars']
			)
		);

		$this->assertCount( 9, $query->posts );
		$sorts = array_map( static fn( WP_Post $post ): string => (string) get_post_meta( $post->ID, Rsvp_Archive::SORT_KEY, true ), $query->posts );
		$copy  = $sorts;
		sort( $copy );
		$this->assertSame( $copy, $sorts );
	}

	public function test_past_orders_by_latest_event_then_post_date_for_no_start_and_protected_posts(): void {
		$older_event = $this->rsvp( '2026-10-01T18:00', '2026-10-06 09:00:00' );
		$newer_event = $this->rsvp( '2026-10-07T18:00', '2026-10-01 09:00:00' );
		$no_start    = $this->rsvp( '', '2026-10-05 09:00:00' );
		$protected   = $this->rsvp( '2026-10-20T18:00', '2026-09-01 09:00:00', 'pass' );
		$query       = new WP_Query(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'post__in'            => [ $older_event, $newer_event, $no_start, $protected ],
				'posts_per_page'      => -1,
				'pkiw_group_source'   => Rsvp_Archive::SOURCE,
				'pkiw_group_order'    => 'ASC',
				'ignore_sticky_posts' => true,
			]
		);

		$this->assertSame( [ $newer_event, $no_start, $older_event, $protected ], wp_list_pluck( $query->posts, 'ID' ) );
	}

	public function test_labels_and_descending_section_order_use_the_engine_contract(): void {
		$source = new Rsvp_Timing_Source();
		$group  = $source->group_of( get_post( $this->rsvp( '2026-10-09T18:00', '2026-10-01 10:00:00' ) ) );
		$this->assertSame( 'Upcoming', $source->label( $group, [] ) );

		$query = new WP_Query();
		$query->set( 'pkiw_group_source', Rsvp_Archive::SOURCE );
		$query->set( 'pkiw_group_order', 'DESC' );
		$orderby = Grouped_Archive::group_orderby( '', $query );
		$this->assertStringContainsString( ' DESC', $orderby );
	}
}

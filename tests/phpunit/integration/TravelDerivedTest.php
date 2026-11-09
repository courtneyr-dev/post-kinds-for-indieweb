<?php
/** Travel derived facts. @package PKIW */

declare(strict_types=1);

use PKIW\Travel;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/** @group integration */
final class TravelDerivedTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'date_format', 'F j, Y' );
	}

	public function test_date_range_formats_one_day_same_month_same_year_and_cross_year(): void {
		$old = get_option( 'date_format' );
		try {
			update_option( 'date_format', 'F j, Y' );
			$this->assertSame( [ 'start' => '', 'end' => '', 'display' => '' ], Travel::date_range( [] ) );
			$this->assertSame( 'May 4, 2026', Travel::date_range( [ '2026-05-04' ] )['display'] );
			$this->assertSame( 'May 4–8, 2026', Travel::date_range( [ '2026-05-04', '2026-05-08' ] )['display'] );
			$this->assertSame( 'May 4 – June 8, 2026', Travel::date_range( [ '2026-05-04', '2026-06-08' ] )['display'] );
			$this->assertSame( 'December 31, 2026 – January 1, 2027', Travel::date_range( [ '2026-12-31', '2027-01-01' ] )['display'] );
		} finally {
			update_option( 'date_format', $old );
		}
	}

	public function test_route_html_requires_both_ends_and_exposes_hidden_to_word(): void {
		$this->assertSame( '', Travel::route_html( '', 'Seattle' ) );
		$html = Travel::route_html( 'Portland', 'Seattle' );
		$this->assertStringContainsString( 'pkiw-route__from">Portland', $html );
		$this->assertStringContainsString( 'aria-hidden="true">→', $html );
		$this->assertStringContainsString( 'screen-reader-text"> to ', $html );
		$this->assertStringContainsString( 'pkiw-route__to">Seattle', $html );
	}

	public function test_trip_facts_derive_count_route_and_may_4_to_8_range_from_visible_stops_only(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2000-05-01 09:00:00' ] );
		wp_set_object_terms( $id, 'trip', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta(
			$id,
			Travel::STOPS_KEY,
			wp_json_encode(
				[
					[ 'name' => 'Portland', 'date' => '2000-05-04', 'privacy' => 'public' ],
					[ 'name' => 'Seattle', 'date' => '2000-05-08', 'privacy' => 'public' ],
					[ 'name' => 'Hidden Vancouver', 'date' => '2000-05-09', 'privacy' => 'private' ],
				]
			)
		);

		$facts = Travel::trip_facts( $id );
		$this->assertSame( 2, $facts['count'] );
		$this->assertSame( 'Portland', $facts['origin'] );
		$this->assertSame( 'Seattle', $facts['destination'] );
		$this->assertSame( [ 'start' => '2000-05-04', 'end' => '2000-05-08', 'display' => 'May 4–8, 2000' ], $facts['range'] );
		$this->assertStringNotContainsString( 'Hidden Vancouver', wp_json_encode( $facts ) );
	}
}

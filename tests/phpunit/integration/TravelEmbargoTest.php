<?php
/** Travel date embargo. @package PKIW */

declare(strict_types=1);

use PKIW\Travel;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/** @group integration */
final class TravelEmbargoTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
	}

	public function test_reveal_is_first_instant_after_day_ends_anywhere_on_earth(): void {
		$this->assertSame( strtotime( '2026-05-05T12:00:00Z' ), Travel::reveal_at( '2026-05-04' ) );
		$this->assertTrue( Travel::is_embargoed( 'trip', [ 'date' => '2026-05-04' ], '2026-01-01', Travel::reveal_at( '2026-05-04' ) - 1 ) );
		$this->assertFalse( Travel::is_embargoed( 'trip', [ 'date' => '2026-05-04' ], '2026-01-01', Travel::reveal_at( '2026-05-04' ) ) );
	}

	public function test_leg_uses_later_endpoint_day_in_each_stored_offset(): void {
		$leg = [
			'departure' => '2026-05-04T23:30:00-12:00',
			'arrival'   => '2026-05-05T00:15:00+14:00',
		];
		$this->assertSame( '2026-05-05', Travel::last_day( 'itinerary', $leg, '2026-01-01' ) );
		$this->assertTrue( Travel::is_embargoed( 'itinerary', $leg, '2026-01-01', Travel::reveal_at( '2026-05-05' ) - 1 ), 'A D to D+1 leg stays hidden through D+1.' );
		$this->assertFalse( Travel::is_embargoed( 'itinerary', $leg, '2026-01-01', Travel::reveal_at( '2026-05-05' ) ) );
	}

	public function test_naive_leg_uses_site_timezone_day_and_undated_item_uses_fallback(): void {
		$old = get_option( 'timezone_string' );
		try {
			update_option( 'timezone_string', 'America/Los_Angeles' );
			$this->assertSame( '2026-05-04', Travel::last_day( 'itinerary', [ 'departure' => '2026-05-04T23:30:00', 'arrival' => '' ], '2026-01-01' ) );
			$this->assertSame( '2026-01-01', Travel::last_day( 'trip', [ 'date' => '' ], '2026-01-01' ) );
		} finally {
			update_option( 'timezone_string', $old );
		}
	}

	public function test_visible_stops_use_the_post_day_when_a_date_filter_returns_non_string(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2000-05-04 09:00:00' ] );
		wp_set_object_terms( $id, 'trip', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( [ [ 'name' => 'Undated stop', 'date' => '', 'privacy' => 'public' ] ] ) );
		$non_string = static fn() => false;
		add_filter( 'get_the_date', $non_string, 99 );
		try {
			$visible = Travel::visible_stops( $id, Travel::reveal_at( '2000-05-04' ) );
		} finally {
			remove_filter( 'get_the_date', $non_string, 99 );
		}

		$this->assertCount( 1, $visible );
		$this->assertSame( 'Undated stop', $visible[0]['label'] );
	}
}

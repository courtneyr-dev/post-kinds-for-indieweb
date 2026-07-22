<?php
/**
 * Check-in Dashboard block front-end render coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies the dashboard queries the plugin's own kind taxonomy and
 * _pkiw_* meta (matching the REST layer's get_checkins), and that
 * coordinates only reach the map for public-privacy check-ins.
 *
 * @group integration
 */
final class CheckinDashboardRenderTest extends WP_UnitTestCase {

	/**
	 * Create a published check-in post with the given _pkiw_* meta.
	 *
	 * @param array<string, string> $meta Meta key => value pairs.
	 * @return int Post ID.
	 */
	private function create_checkin( array $meta, string $post_date = '' ): int {
		$args = [
			'post_status' => 'publish',
			'post_title'  => 'A check-in',
		];
		if ( '' !== $post_date ) {
			$args['post_date'] = $post_date;
		}
		$post_id = self::factory()->post->create( $args );

		$term_result = wp_set_object_terms( $post_id, 'checkin', 'kind' );
		$this->assertNotWPError( $term_result );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Render the dashboard block through the block pipeline.
	 *
	 * @return string Rendered HTML.
	 */
	private function render_dashboard(): string {
		return do_blocks( '<!-- wp:post-kinds-indieweb/checkin-dashboard /-->' );
	}

	public function test_dashboard_lists_checkins_by_kind_taxonomy_and_pkiw_meta(): void {
		$this->create_checkin(
			[
				'_pkiw_checkin_name'    => 'Reading Terminal Market',
				'_pkiw_checkin_address' => '1136 Arch St',
			]
		);

		$html = $this->render_dashboard();

		$this->assertStringContainsString( 'Reading Terminal Market', $html, 'venue name from _pkiw_checkin_name must render' );
		$this->assertStringContainsString( '1136 Arch St', $html, 'address from _pkiw_checkin_address must render' );
		$this->assertStringNotContainsString( 'No check-ins yet.', $html, 'a kind=checkin post must not leave the dashboard empty' );
	}

	public function test_coordinates_only_exposed_for_public_privacy(): void {
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Public Venue',
				'_pkiw_geo_latitude'  => '39.95333',
				'_pkiw_geo_longitude' => '-75.15928',
				'_pkiw_geo_privacy'   => 'public',
			]
		);
		// No privacy meta: the plugin-wide default is "approximate",
		// which (matching the REST layer) must not expose coordinates.
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Default Privacy Venue',
				'_pkiw_geo_latitude'  => '40.44062',
				'_pkiw_geo_longitude' => '-79.99589',
			]
		);

		$html = $this->render_dashboard();

		$this->assertStringContainsString( '39.95333', $html, 'public check-in coordinates must reach the map data' );
		$this->assertStringNotContainsString( '40.44062', $html, 'non-public check-in coordinates must never reach the markup' );
	}

	public function test_map_data_is_a_json_list_even_when_filter_drops_the_newest_checkin(): void {
		// Older, public: survives the coordinate filter.
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Public Venue',
				'_pkiw_geo_latitude'  => '39.95333',
				'_pkiw_geo_longitude' => '-75.15928',
				'_pkiw_geo_privacy'   => 'public',
			],
			'2026-07-01 12:00:00'
		);
		// Newer, private: sorts first (date DESC), then gets filtered out —
		// the encoded map data must re-index to stay a JSON array, or the
		// view script's checkins.forEach() breaks on a JSON object.
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Private Venue',
				'_pkiw_geo_latitude'  => '40.44062',
				'_pkiw_geo_longitude' => '-79.99589',
				'_pkiw_geo_privacy'   => 'private',
			],
			'2026-07-02 12:00:00'
		);

		$html = $this->render_dashboard();

		$this->assertSame( 1, preg_match( '/data-checkins="([^"]*)"/s', $html, $matches ), 'map container must carry data-checkins' );
		$decoded = json_decode( trim( html_entity_decode( $matches[1], ENT_QUOTES ) ), true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( array_is_list( $decoded ), 'map data must be a JSON list, not an object keyed by original index' );
		$this->assertCount( 1, $decoded );
	}
}

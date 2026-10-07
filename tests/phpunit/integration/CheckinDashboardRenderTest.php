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

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'America/Chicago' );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

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

	public function test_dashboard_keeps_the_local_post_date_and_time(): void {
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Example Cafe',
				'_pkiw_geo_privacy'  => 'public',
			],
			'2026-09-24 22:30:00'
		);

		$html = $this->render_dashboard();

		$this->assertStringContainsString( 'September 24, 2026', $html );
		$this->assertStringContainsString( 'Sep 24, 10:30 pm', $html );
		$this->assertStringNotContainsString( 'September 25, 2026', $html );

		// The grid card and the timeline item each parse as an h-entry
		// published at the same local instant the visible text names.
		$entries = $this->h_entries( \Mf2\parse( $html )['items'] ?? [] );
		$this->assertCount( 2, $entries );
		foreach ( $entries as $entry ) {
			$this->assertSame( [ '2026-09-24T22:30:00-05:00' ], $entry['properties']['published'] ?? [] );
			$this->assertSame( [ 'Example Cafe' ], array_map( 'trim', $entry['properties']['name'] ?? [] ) );
		}
	}

	/**
	 * Collect every h-entry in a parsed mf2 tree.
	 *
	 * @param array<int, array<string, mixed>> $items Parsed items.
	 * @return array<int, array<string, mixed>> h-entry items.
	 */
	private function h_entries( array $items ): array {
		$found = [];
		foreach ( $items as $item ) {
			if ( in_array( 'h-entry', (array) ( $item['type'] ?? [] ), true ) ) {
				$found[] = $item;
			}
			$found = array_merge( $found, $this->h_entries( (array) ( $item['children'] ?? [] ) ) );
		}

		return $found;
	}

	public function test_dashboard_lists_checkins_by_kind_taxonomy_and_pkiw_meta(): void {
		// Public, so the street address is visible to an anonymous viewer.
		$this->create_checkin(
			[
				'_pkiw_checkin_name'    => 'Sentinel Venue Zyx9',
				'_pkiw_checkin_address' => '742 Sentinel Ave Qwrt',
				'_pkiw_geo_privacy'     => 'public',
			]
		);

		$html = $this->render_dashboard();

		$this->assertStringContainsString( 'Sentinel Venue Zyx9', $html, 'venue name from _pkiw_checkin_name must render' );
		$this->assertStringContainsString( '742 Sentinel Ave Qwrt', $html, 'address from _pkiw_checkin_address must render' );
		$this->assertStringNotContainsString( 'No check-ins yet.', $html, 'a kind=checkin post must not leave the dashboard empty' );
	}

	public function test_coordinates_reach_map_for_public_privacy_only(): void {
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Public Venue',
				'_pkiw_geo_latitude'  => '12.345678',
				'_pkiw_geo_longitude' => '-76.543219',
				'_pkiw_geo_privacy'   => 'public',
			]
		);
		// An unset _pkiw_geo_privacy means approximate: the venue name
		// renders and the coordinates stay out of the map data.
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Default Privacy Venue',
				'_pkiw_geo_latitude'  => '23.456789',
				'_pkiw_geo_longitude' => '-65.432198',
			]
		);

		$html = $this->render_dashboard();

		$this->assertStringContainsString( '12.345678', $html, 'public check-in coordinates must reach the map data' );
		$this->assertStringNotContainsString( '23.456789', $html, 'a check-in with an unset (approximate) privacy keeps its coordinates out of the map data' );
		$this->assertStringContainsString( 'Default Privacy Venue', $html );
	}

	public function test_coordinates_hidden_when_geo_public_restricts_them(): void {
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Public Venue',
				'_pkiw_geo_latitude'  => '12.345678',
				'_pkiw_geo_longitude' => '-76.543219',
				'_pkiw_geo_privacy'   => 'public',
			]
		);
		// Simple Location's geo_public '0' (explicitly hidden) overrides
		// the venue-post full-visibility default.
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Hidden Venue',
				'_pkiw_geo_latitude'  => '23.456789',
				'_pkiw_geo_longitude' => '-65.432198',
				'geo_public'          => '0',
			]
		);

		$html = $this->render_dashboard();

		$this->assertStringContainsString( '12.345678', $html, 'public check-in coordinates must reach the map data' );
		$this->assertStringNotContainsString( '23.456789', $html, 'geo_public-hidden check-in coordinates must never reach the markup' );
	}

	public function test_map_data_is_a_json_list_even_when_filter_drops_the_newest_checkin(): void {
		// Older, public: survives the coordinate filter.
		$this->create_checkin(
			[
				'_pkiw_checkin_name' => 'Public Venue',
				'_pkiw_geo_latitude'  => '12.345678',
				'_pkiw_geo_longitude' => '-76.543219',
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
				'_pkiw_geo_latitude'  => '23.456789',
				'_pkiw_geo_longitude' => '-65.432198',
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

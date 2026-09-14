<?php
/**
 * Location-privacy coverage for the Check-in Dashboard block.
 *
 * The dashboard lists every check-in on a public page, so each post's
 * venue name, street address and coordinates must follow the same
 * Meta_Fields::get_visible_location_fields() tiers as the checkin card —
 * in the grid, the timeline, the map's data-checkins JSON and the
 * unique-venues stat.
 *
 * Every value under test is a sentinel string that does not resemble a
 * real place, so a leak is unambiguous in the assertion output.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;

/**
 * @group integration
 */
final class CheckinDashboardLocationPrivacyTest extends WP_UnitTestCase {

	private const VENUE_NAME = 'Sentinel Venue Zyx9';
	private const STREET     = '742 Sentinel Ave Qwrt';
	private const LATITUDE   = '12.345678';
	private const LONGITUDE  = '-76.543219';

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
	}

	/**
	 * Create a published check-in with sentinel location meta.
	 *
	 * @param string $privacy geo_privacy value.
	 * @param string $name    Venue name.
	 * @param string $street  Street address.
	 * @return int Post ID.
	 */
	private function create_checkin( string $privacy, string $name = self::VENUE_NAME, string $street = self::STREET ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Sentinel check-in',
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'checkin', 'kind' ) );

		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', $name );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_address', $street );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_latitude', self::LATITUDE );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_longitude', self::LONGITUDE );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );

		return $post_id;
	}

	private function render_dashboard(): string {
		return do_blocks( '<!-- wp:post-kinds-indieweb/checkin-dashboard /-->' );
	}

	private function become( string $viewer ): void {
		wp_set_current_user( 'editor' === $viewer ? self::factory()->user->create( [ 'role' => 'editor' ] ) : 0 );
	}

	/**
	 * Privacy × viewer, with whether name / street / coordinates should render.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: bool}>
	 */
	public function visibility_matrix(): array {
		return [
			'public, anonymous'      => [ 'public', 'anonymous', true, true, true ],
			'approximate, anonymous' => [ 'approximate', 'anonymous', true, false, false ],
			'private, anonymous'     => [ 'private', 'anonymous', false, false, false ],
			'public, editor'         => [ 'public', 'editor', true, true, true ],
			'approximate, editor'    => [ 'approximate', 'editor', true, true, true ],
			'private, editor'        => [ 'private', 'editor', true, true, true ],
		];
	}

	/**
	 * @dataProvider visibility_matrix
	 */
	public function test_dashboard_follows_location_privacy_tiers( string $privacy, string $viewer, bool $name, bool $street, bool $coordinates ): void {
		$this->create_checkin( $privacy );
		$this->become( $viewer );

		$html = $this->render_dashboard();

		$expectations = [
			'venue name'  => [ $name, self::VENUE_NAME ],
			'street'      => [ $street, self::STREET ],
			'coordinates' => [ $coordinates, self::LATITUDE ],
		];
		foreach ( $expectations as $label => [ $visible, $needle ] ) {
			if ( $visible ) {
				$this->assertStringContainsString( $needle, $html, "{$privacy} check-in {$label} must render for {$viewer}" );
			} else {
				$this->assertStringNotContainsString( $needle, $html, "{$privacy} check-in {$label} must not reach the markup for {$viewer}" );
			}
		}
	}

	public function test_hidden_venue_name_never_leaves_an_empty_link(): void {
		$this->create_checkin( 'private' );
		$this->become( 'anonymous' );

		$html = $this->render_dashboard();

		$this->assertSame( 0, preg_match( '/<a\b[^>]*>\s*<\/a>/', $html ), 'a hidden venue name must fall back to a label, not an empty link' );
	}

	public function test_map_data_carries_only_fully_visible_checkins(): void {
		$this->create_checkin( 'public', 'Sentinel Public Venue', '1 Sentinel Public Way' );
		$this->create_checkin( 'approximate', 'Sentinel Approx Venue', '2 Sentinel Approx Way' );
		$this->create_checkin( 'private', 'Sentinel Private Venue', '3 Sentinel Private Way' );
		$this->become( 'anonymous' );

		$html = $this->render_dashboard();

		$this->assertSame( 1, preg_match( '/data-checkins="([^"]*)"/s', $html, $matches ), 'map container must carry data-checkins' );
		$json = trim( html_entity_decode( $matches[1], ENT_QUOTES ) );
		$this->assertCount( 1, json_decode( $json, true ) );
		$this->assertStringContainsString( 'Sentinel Public Venue', $json );
		foreach ( [ 'Sentinel Approx Venue', '2 Sentinel Approx Way', 'Sentinel Private Venue', '3 Sentinel Private Way' ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $json, 'map data must not carry non-public check-ins' );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function unique_venue_counts(): array {
		return [
			'anonymous' => [ 'anonymous', '1' ],
			'editor'    => [ 'editor', '3' ],
		];
	}

	/**
	 * @dataProvider unique_venue_counts
	 */
	public function test_unique_venues_stat_counts_only_visible_names( string $viewer, string $expected ): void {
		$this->create_checkin( 'approximate', 'Sentinel Approx Venue' );
		$this->create_checkin( 'private', 'Sentinel Private Venue Kqp4' );
		$this->create_checkin( 'private', 'Sentinel Private Venue Wmv7' );
		$this->become( $viewer );

		$html = $this->render_dashboard();

		$this->assertGreaterThanOrEqual( 2, preg_match_all( '/<span class="stat-value">([^<]*)<\/span>/', $html, $matches ) );
		$this->assertSame( $expected, trim( $matches[1][1] ), "unique venues must count only names the {$viewer} viewer can see" );
	}
}

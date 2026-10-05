<?php
/**
 * The check-in archive: list entries and map pins behind the privacy rule.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Checkin_Map;
use PKIW\Meta_Fields;

/**
 * @group integration
 */
final class CheckinArchiveMapTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	/**
	 * A published check-in with sentinel location data.
	 *
	 * @param string $slug    Marks every sentinel value of this post.
	 * @param string $privacy _pkiw_geo_privacy.
	 * @param float  $lat     Latitude.
	 * @param float  $lng     Longitude.
	 */
	private function checkin( string $slug, string $privacy, float $lat, float $lng ): WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => "Title {$slug}",
				'post_date'   => '2026-09-12 14:30:00',
			]
		);
		wp_set_object_terms( $post_id, 'checkin', 'kind' );

		$meta = [
			'checkin_name'        => "Venue {$slug}",
			'checkin_address'     => "1 Street {$slug}",
			'checkin_locality'    => "Town {$slug}",
			'checkin_region'      => "Region {$slug}",
			'checkin_country'     => "Country {$slug}",
			'checkin_postal_code' => "ZIP{$slug}",
			'checkin_url'         => "https://venue.example/{$slug}",
			'checkin_osm_id'      => "node/{$slug}",
			'geo_latitude'        => (string) $lat,
			'geo_longitude'       => (string) $lng,
			'geo_privacy'         => $privacy,
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}

		return get_post( $post_id );
	}

	private function render( array $posts ): string {
		return Checkin_Map::render_archive( $posts, 'class="pkiw-checkin-archive"' );
	}

	public function test_only_public_checkins_get_pins(): void {
		$posts = [
			$this->checkin( 'pubone', 'public', 40.111111, -75.111111 ),
			$this->checkin( 'approx', 'approximate', 41.222222, -76.222222 ),
			$this->checkin( 'secret', 'private', 42.333333, -77.333333 ),
			$this->checkin( 'pubtwo', 'public', 43.444444, -78.444444 ),
		];

		$entries = Checkin_Map::entries( $posts );
		$pins    = Checkin_Map::pins( $entries );

		$this->assertSame( [ 1, 0, 0, 2 ], array_column( $entries, 'number' ) );
		$this->assertCount( 2, $pins );
		$this->assertSame( [ $posts[0]->ID ], $pins[0]['ids'] );
		$this->assertSame( [ $posts[3]->ID ], $pins[1]['ids'] );
	}

	public function test_approximate_entry_keeps_name_and_place_and_no_precise_data(): void {
		$html = $this->render( [ $this->checkin( 'approx', 'approximate', 41.222222, -76.222222 ) ] );

		$this->assertStringContainsString( 'Venue approx', $html );
		$this->assertStringContainsString( 'Town approx', $html );
		foreach ( [ '41.222222', '76.222222', '1 Street approx', 'ZIPapprox', 'venue.example/approx', 'node/approx', 'data-pins', 'p-geo' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, "{$needle} must not reach the page for an approximate check-in" );
		}
	}

	public function test_private_entry_prints_title_and_date_only(): void {
		$html = $this->render(
			[
				$this->checkin( 'pubone', 'public', 40.111111, -75.111111 ),
				$this->checkin( 'secret', 'private', 42.333333, -77.333333 ),
			]
		);

		$this->assertStringContainsString( 'Title secret', $html );
		foreach ( [ 'Venue secret', 'Town secret', 'Region secret', 'Country secret', '42.333333', '77.333333', '1 Street secret', 'ZIPsecret', 'venue.example/secret', 'node/secret' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, "{$needle} must not reach the page for a private check-in" );
		}
	}

	public function test_private_entry_matches_an_entry_with_no_location(): void {
		$private = $this->checkin( 'secret', 'private', 42.333333, -77.333333 );
		$bare_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Title secret',
				'post_date'   => '2026-09-12 14:30:00',
			]
		);

		// Post IDs and the map's unique id differ between any two posts.
		$strip = static fn( string $html ): string => (string) preg_replace( '/(pkiw-checkin-map-|pkiw-checkin-entry-|\?p=)\d+/', '$1N', $html );

		$this->assertSame(
			$strip( $this->render( [ get_post( $bare_id ) ] ) ),
			$strip( $this->render( [ $private ] ) )
		);
	}

	public function test_checkins_at_one_location_share_a_pin(): void {
		$posts = [
			$this->checkin( 'visit1', 'public', 40.111111, -75.111111 ),
			$this->checkin( 'other', 'public', 40.5, -75.5 ),
			$this->checkin( 'visit2', 'public', 40.111119, -75.111118 ),
			$this->checkin( 'visit3', 'public', 40.111111, -75.111111 ),
		];

		$pins = Checkin_Map::pins( Checkin_Map::entries( $posts ) );

		$this->assertCount( 2, $pins );
		$this->assertSame( [ $posts[0]->ID, $posts[2]->ID, $posts[3]->ID ], $pins[0]['ids'] );
		$this->assertSame( [ 1, 3, 4 ], $pins[0]['numbers'] );
		$this->assertStringStartsWith( '3 check-ins at this location', $pins[0]['label'] );
		$this->assertStringStartsWith( '2: Title other', $pins[1]['label'] );
	}

	public function test_every_checkin_stays_in_the_linked_list(): void {
		$posts = [
			$this->checkin( 'visit1', 'public', 40.111111, -75.111111 ),
			$this->checkin( 'visit2', 'public', 40.111111, -75.111111 ),
			$this->checkin( 'secret', 'private', 42.333333, -77.333333 ),
		];

		$html = $this->render( $posts );

		foreach ( $posts as $post ) {
			$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $post ) ) . '"', $html );
		}
		$this->assertSame( 3, substr_count( $html, 'class="pkiw-checkin-archive__entry h-entry' ) );
	}

	public function test_summary_counts_the_page_and_the_mapped_entries(): void {
		$this->assertSame( '6 check-ins · 4 mapped', Checkin_Map::summary( 6, 4 ) );
		$this->assertSame( '3 check-ins · 1 mapped', Checkin_Map::summary( 3, 1 ) );
		$this->assertSame( '3 check-ins', Checkin_Map::summary( 3, 0 ) );
		$this->assertSame( '1 check-in', Checkin_Map::summary( 1, 0 ) );
	}

	public function test_no_map_and_no_leaflet_when_nothing_is_mapped(): void {
		// An earlier test in the same process may have drawn a map.
		wp_dequeue_script( 'pkiw-checkin-map' );

		$html = $this->render( [ $this->checkin( 'approx', 'approximate', 41.222222, -76.222222 ) ] );

		$this->assertStringNotContainsString( 'pkiw-checkin-archive__map', $html );
		$this->assertStringContainsString( '1 check-in', $html );
		$this->assertFalse( wp_script_is( 'pkiw-checkin-map', 'enqueued' ), 'The map script, which pulls in Leaflet, loads only with a pin.' );
	}

	public function test_map_is_a_named_region_hidden_until_the_script_runs(): void {
		$html = $this->render( [ $this->checkin( 'pubone', 'public', 40.111111, -75.111111 ) ] );

		$this->assertMatchesRegularExpression( '/class="pkiw-checkin-archive__map"[^>]*role="region"[^>]*aria-label="Map of the check-ins on this page"[^>]*aria-describedby="(pkiw-checkin-map-\d+-summary)"[^>]*hidden/s', $html );
		$this->assertTrue( wp_script_is( 'pkiw-checkin-map', 'enqueued' ) );
		$this->assertStringContainsString( 'data-label="Show Title pubone on map"', $html );
	}

	public function test_tile_url_and_attribution_are_filterable(): void {
		add_filter( 'pkiw_map_tile_url', static fn(): string => 'https://tiles.example/{z}/{x}/{y}.png' );
		add_filter( 'pkiw_map_tile_attribution', static fn(): string => 'Tiles by <a href="https://tiles.example/">Example</a><script>x</script>' );

		$html = $this->render( [ $this->checkin( 'pubone', 'public', 40.111111, -75.111111 ) ] );

		$this->assertStringContainsString( 'data-tile-url="https://tiles.example/{z}/{x}/{y}.png"', $html );
		$this->assertStringContainsString( 'tiles.example/&quot;&gt;Example', $html );
		$this->assertStringNotContainsString( 'script', $html );
		$this->assertStringNotContainsString( 'tile.openstreetmap.org', $html );
	}

	public function test_each_entry_parses_as_an_h_entry(): void {
		$posts  = [
			$this->checkin( 'pubone', 'public', 40.111111, -75.111111 ),
			$this->checkin( 'secret', 'private', 42.333333, -77.333333 ),
		];
		$parsed = \Mf2\parse( $this->render( $posts ), home_url( '/' ) );
		$items  = $parsed['items'][0]['children'] ?? [];

		$this->assertCount( 2, $items );
		$this->assertSame( [ 'Title pubone' ], $items[0]['properties']['name'] );
		$this->assertSame( [ get_permalink( $posts[0] ) ], $items[0]['properties']['url'] );
		$this->assertArrayHasKey( 'published', $items[0]['properties'] );
		$this->assertSame( [ 'Town pubone' ], $items[0]['properties']['location'][0]['properties']['locality'] );
		$this->assertArrayNotHasKey( 'location', $items[1]['properties'] );
	}

	public function test_template_per_page_reads_the_inheriting_feed(): void {
		$this->assertSame( 24, Checkin_Map::template_per_page( '<!-- wp:post-kinds-indieweb/checkins-feed {"inherit":true,"count":24} /-->' ) );
		$this->assertSame( 24, Checkin_Map::template_per_page( '<!-- wp:post-kinds-indieweb/checkins-feed {"inherit":true} /-->' ) );
		$this->assertSame( 0, Checkin_Map::template_per_page( '<!-- wp:post-kinds-indieweb/checkins-feed {"count":5} /-->' ) );
		$this->assertSame( 0, Checkin_Map::template_per_page( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
	}

	public function test_editor_sees_pins_for_private_checkins(): void {
		$post = $this->checkin( 'secret', 'private', 42.333333, -77.333333 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertCount( 1, Checkin_Map::pins( Checkin_Map::entries( [ $post ] ) ) );
	}

	public function test_archive_template_ships_and_carries_the_page_contract(): void {
		$ids = wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-kind-checkin' ] ] ), 'id' );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-checkin', $ids );

		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-checkin.html' );

		$this->assertSame( 1, substr_count( $content, '<!-- wp:query-title ' ), 'One h1.' );
		$this->assertStringContainsString( '<!-- wp:term-description /-->', $content );
		$this->assertStringContainsString( '"query":{"inherit":true}', $content );
		$this->assertStringContainsString( '<!-- wp:post-kinds-indieweb/checkins-feed {"inherit":true,"count":24} /-->', $content );
		$this->assertStringContainsString( '"layout":{"type":"flex","justifyContent":"center"}', $content, 'Centered pager.' );
		$this->assertStringContainsString( '<!-- wp:query-no-results -->', $content );
		$this->assertSame( 24, Checkin_Map::template_per_page( $content ) );
	}
}

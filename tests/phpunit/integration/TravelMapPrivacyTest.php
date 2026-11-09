<?php
/** Travel map privacy boundaries. @package PKIW */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;
use PKIW\Travel_Map;

/** @group integration */
final class TravelMapPrivacyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Travel() )->register();
		Travel::register_blocks();
		( new Travel_Map() )->register();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		parent::tear_down();
	}

	private function stop( string $name, string $privacy, string $date, float $lat, float $lng ): array {
		$control = str_starts_with( $name, 'Contract' );
		return [
			'name' => $name, 'locality' => $control ? 'Portland' : 'Sentinelburg', 'region' => $control ? 'Oregon' : 'Sentinel', 'country' => 'US',
			'street' => $control ? '2 Contract Way' : '1 Sentinel Way', 'postal_code' => $control ? 'PC244' : 'SE244', 'url' => $control ? 'https://contract.example/harbor' : 'https://sentinel.example/quay',
			'osm_id' => $control ? 'osm-contract' : 'osm-244244', 'venue_id' => $control ? 'venue-contract' : 'venue-244244', 'lat' => $lat, 'lng' => $lng,
			'date' => $date, 'privacy' => $privacy,
		];
	}

	private function trip( string $title, array $stops, ?string $post_privacy = 'public', ?string $geo_public = null, array $args = [] ): WP_Post {
		$id = self::factory()->post->create( array_merge( [
			'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2000-05-01 09:00:00', 'post_excerpt' => '',
			'post_content' => '<!-- wp:post-kinds-indieweb/trip-card /-->',
		], $args ) );
		wp_set_object_terms( $id, Travel::TRIP, Taxonomy::TAXONOMY );
		if ( null !== $post_privacy ) {
			update_post_meta( $id, '_pkiw_geo_privacy', $post_privacy );
		}
		if ( null !== $geo_public ) {
			update_post_meta( $id, 'geo_public', $geo_public );
		}
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( $stops ) );
		return get_post( $id );
	}

	private function itinerary( string $title, array $legs, ?string $post_privacy = 'public', array $args = [] ): WP_Post {
		$id = self::factory()->post->create( array_merge( [
			'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2000-05-01 09:00:00', 'post_excerpt' => '',
			'post_content' => '<!-- wp:post-kinds-indieweb/itinerary-card /-->',
		], $args ) );
		wp_set_object_terms( $id, Travel::ITINERARY, Taxonomy::TAXONOMY );
		if ( null !== $post_privacy ) {
			update_post_meta( $id, '_pkiw_geo_privacy', $post_privacy );
		}
		update_post_meta( $id, Travel::LEGS_KEY, wp_json_encode( $legs ) );
		return get_post( $id );
	}

	private function leg( string $name, string $privacy, string $day, float $lat, float $lng ): array {
		$from = $this->stop( $name . ' From', $privacy, $day, $lat, $lng );
		$to   = $this->stop( $name . ' To', $privacy, $day, $lat + 1, $lng + 1 );
		unset( $from['date'], $from['privacy'], $to['date'], $to['privacy'] );
		return [
			'from' => $from, 'to' => $to,
			'departure' => $day . 'T08:00:00+00:00', 'arrival' => $day . 'T10:00:00+00:00',
			'carrier' => str_starts_with( $name, 'Contract' ) ? 'Contract Rail' : 'Sentinel Air', 'privacy' => $privacy,
		];
	}

	private function render_single( WP_Post $post ): string {
		$this->go_to( get_permalink( $post ) );
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		return (string) apply_filters( 'the_content', $post->post_content );
	}

	private function assert_no_sentinels( string $value ): void {
		foreach ( [ 'Sentinel Quay', 'Sentinelburg', '1 Sentinel Way', 'SE244', 'sentinel.example', 'osm-244244', 'venue-244244', 'Sentinel Air', '0.2442', '0.4424', '2099-12-31' ] as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $value, $needle );
		}
	}

	public function test_hidden_items_add_no_pin_route_number_or_mapped_count(): void {
		$past = '2000-05-04';
		$hidden = $this->stop( 'Sentinel Quay', 'public', $past, 0.244244, 0.442442 );
		$private_item = $this->trip( 'Private item', [ $this->stop( 'Sentinel Quay', 'private', $past, 0.244244, 0.442442 ) ] );
		$approximate = $this->trip( 'Approximate item', [ $this->stop( 'Sentinel Quay', 'approximate', $past, 0.244244, 0.442442 ) ] );
		$protected_tier = $this->trip( 'Protected tier', [ $hidden ], 'public', '2' );
		$post_private = $this->trip( 'Private post', [ $hidden ], 'private' );
		$future = $this->trip( 'Future item', [ $this->stop( 'Sentinel Quay', 'public', '2099-12-31', 0.244244, 0.442442 ) ] );
		$control = $this->trip( 'Public control', [
			$this->stop( 'Contract Harbor', 'public', $past, 45.5152, -122.6784 ),
			$this->stop( 'Contract Terminal', 'public', $past, 47.6062, -122.3321 ),
		] );
		$posts = [ $private_item, $approximate, $protected_tier, $post_private, $future, $control ];

		$GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['wp_query']->posts = $posts;
		$html = Travel_Map::render_block();
		$this->assertStringContainsString( '6 trips · 1 mapped', $html );
		$this->assertStringContainsString( '45.5152', $html );
		$this->assertStringContainsString( '47.6062', $html );
		foreach ( [ 'Sentinel Quay', 'Sentinelburg', '1 Sentinel Way', 'SE244', 'sentinel.example', 'osm-244244', 'venue-244244', '0.2442', '0.4424', '2099-12-31' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html );
		}
		$this->assertSame( [ $control->ID => 1 ], Travel_Map::page_numbers( $posts ) );
		$this->assertSame( [ $control->ID ], array_column( Travel_Map::routes( $posts ), 'entry' ) );
	}

	public function test_single_figure_serializes_only_already_visible_stops(): void {
		$post = $this->trip( 'Single control', [ $this->stop( 'Contract Harbor', 'public', '2000-05-04', 45.5152, -122.6784 ) ] );
		$visible = Travel::visible_stops( $post->ID );
		$html = Travel_Map::single_figure( $visible, $post->ID );
		$this->assertStringContainsString( '&quot;label&quot;:&quot;Contract Harbor&quot;', $html );
		$this->assertStringContainsString( '&quot;targets&quot;:[&quot;pkiw-stop-' . $post->ID . '-1&quot;]', $html );
		$this->assertStringNotContainsString( 'Single control', $html );
		$this->assertStringNotContainsString( 'Go to its list entry', $html );
		$this->assertStringContainsString( '45.5152', $html );
		$this->assertStringNotContainsString( '1 Sentinel Way', $html );
		$this->assertStringNotContainsString( 'SE244', $html );
		$this->assertStringContainsString( '%d stops in this area. Zoom in.', html_entity_decode( $html ) );
	}

	public function test_itinerary_leg_privacy_filters_points_for_real_posts(): void {
		$private = $this->itinerary( 'Private itinerary', [ $this->leg( 'Sentinel Quay', 'private', '2000-05-04', 0.244244, 0.442442 ), $this->leg( 'Contract Harbor', 'public', '2000-05-04', 45.5152, -122.6784 ) ] );
		$future  = $this->itinerary( 'Future itinerary', [ $this->leg( 'Sentinel Quay', 'public', '2099-12-31', 0.244244, 0.442442 ), $this->leg( 'Contract Harbor', 'public', '2000-05-04', 45.5152, -122.6784 ) ] );
		$approx  = $this->itinerary( 'Approximate itinerary', [ $this->leg( 'Contract Approximate', 'approximate', '2000-05-04', 0.244244, 0.442442 ), $this->leg( 'Contract Harbor', 'public', '2000-05-04', 45.5152, -122.6784 ) ] );

		foreach ( [ $private, $future, $approx ] as $post ) {
			$html   = $this->render_single( $post );
			$points = wp_json_encode( Travel_Map::points_for( $post ) );
			$this->assertStringContainsString( 'Contract Harbor From', $html );
			$this->assertStringContainsString( '45.5152', (string) $points );
			$this->assert_no_sentinels( $html . $points );
		}
	}

	public function test_unset_tier_and_password_protection_add_no_map_data(): void {
		$unset     = $this->trip( 'Unset tier', [ $this->stop( 'Contract Harbor', 'public', '2000-05-04', 45.5152, -122.6784 ) ], null );
		$password  = $this->trip( 'Password trip', [ $this->stop( 'Sentinel Quay', 'public', '2000-05-04', 0.244244, 0.442442 ) ], 'public', null, [ 'post_password' => 'secret' ] );
		$control   = $this->trip( 'Public trip', [ $this->stop( 'Contract Terminal', 'public', '2000-05-04', 47.6062, -122.3321 ) ] );
		$posts     = [ $unset, $password, $control ];

		$this->assertStringContainsString( 'Contract Harbor', $this->render_single( $unset ) );
		$this->assertSame( [], Travel_Map::points_for( $unset ) );
		$this->assertSame( [], Travel_Map::points_for( $password ) );
		$this->assertSame( [ $control->ID => 1 ], Travel_Map::page_numbers( $posts ) );

		$GLOBALS['wp_query']        = new WP_Query();
		$GLOBALS['wp_query']->posts = $posts;
		$html                       = Travel_Map::render_block();
		$this->assertStringContainsString( '3 trips · 1 mapped', $html );
		$this->assertStringContainsString( '47.6062', $html );
		$this->assertStringNotContainsString( 'Contract Harbor', $html );
		$this->assert_no_sentinels( $html );
	}

	public function test_single_trip_omits_hidden_stop_from_map_labels_routes_and_counts(): void {
		$post = $this->trip( 'Mixed trip', [
			$this->stop( 'Sentinel Quay', 'private', '2000-05-04', 0.244244, 0.442442 ),
			$this->stop( 'Contract Harbor', 'public', '2000-05-04', 45.5152, -122.6784 ),
		] );
		$html = $this->render_single( $post );

		$this->assertStringContainsString( '>1 stop<', $html );
		$this->assertStringContainsString( 'Contract Harbor', $html );
		$this->assertStringContainsString( 'data-pins=', $html );
		$this->assertStringContainsString( 'data-routes="[]"', $html );
		$this->assert_no_sentinels( $html );
	}
}

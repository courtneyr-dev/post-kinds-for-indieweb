<?php
/** Travel archive entry rendering. @package PKIW */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;
use PKIW\Travel_Archive;
use PKIW\Travel_Map;

/** @group integration */
final class TravelArchiveEntryTest extends WP_UnitTestCase {
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Travel_Archive() )->register();
		( new Travel_Map() )->register();
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function post( string $kind, string $title = 'Archive trip', array $args = [] ): WP_Post {
		$id = self::factory()->post->create( array_merge( [
			'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2000-05-04 09:00:00', 'post_excerpt' => '',
		], $args ) );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		return get_post( $id );
	}

	private function map_post( WP_Post $post ): void {
		update_post_meta( $post->ID, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $post->ID, Travel::STOPS_KEY, wp_json_encode( [ [
			'name' => 'Contract Harbor', 'locality' => 'Portland', 'region' => 'Oregon', 'country' => 'US',
			'street' => '', 'postal_code' => '', 'url' => '', 'osm_id' => '', 'venue_id' => '',
			'lat' => 45.5152, 'lng' => -122.6784, 'date' => '2000-05-04', 'privacy' => 'public',
		] ] ) );
		Travel_Map::page_numbers( [ $post ] );
		Travel_Map::figure( Travel_Map::pins( Travel_Map::points_for( $post ) ), [], 'Map', 'summary' );
	}

	public function test_trip_and_itinerary_entries_have_one_link_and_no_travel_facts(): void {
		$trip = $this->post( Travel::TRIP );
		$this->map_post( $trip );
		$html = Travel_Archive::entry_html( $trip, Travel::TRIP, [ 'headingLevel' => 2 ], true );
		$this->assertSame( 1, substr_count( $html, 'href=' ) );
		$this->assertStringContainsString( 'pkiw-travel-entry pkiw-trip-entry h-entry', $html );
		$this->assertStringContainsString( '<h3 class="pkiw-travel-entry__title">', $html );
		$this->assertStringContainsString( 'class="u-url p-name p-trip"', $html );
		$this->assertStringContainsString( 'data-label="Show Archive trip on map"', $html );
		foreach ( [ 'Contract Harbor', 'Portland', 'May 4', 'stop', 'route', 'privacy' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html );
		}

		$itinerary = $this->post( Travel::ITINERARY, 'Archive itinerary' );
		$html = Travel_Archive::entry_html( $itinerary, Travel::ITINERARY, [ 'headingLevel' => 2 ], true );
		$this->assertSame( 1, substr_count( $html, 'href=' ) );
		$this->assertStringContainsString( 'pkiw-itinerary-entry', $html );
		$this->assertStringContainsString( 'class="u-url p-name p-itinerary"', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_untitled_entry_uses_safe_link_text_without_p_name(): void {
		$post = $this->post( Travel::TRIP, '' );
		$html = Travel_Archive::entry_html( $post, Travel::TRIP, [ 'headingLevel' => 2 ], true );
		$this->assertSame( 1, substr_count( $html, 'href=' ) );
		$this->assertStringContainsString( 'class="u-url p-trip"', $html );
		$this->assertStringNotContainsString( 'class="u-url p-name p-trip"', $html );
		$this->assertStringContainsString( esc_html( \PKIW\untitled_name( $post ) ), $html );
	}

	public function test_protected_entry_is_title_only_and_never_numbered(): void {
		$post = $this->post( Travel::TRIP, 'Locked trip', [ 'post_password' => 'secret' ] );
		$this->map_post( $post );
		$html = Travel_Archive::entry_html( $post, Travel::TRIP, [ 'headingLevel' => 2 ], true );
		$this->assertSame( 1, substr_count( $html, 'href=' ) );
		$this->assertStringContainsString( 'Protected: Locked trip', $html );
		foreach ( [ 'pkiw-travel-map__num', 'dt-published', 'p-author', '<img', 'pkiw_trip_entry_badge_html' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html );
		}
	}

	public function test_first_three_images_rendered_on_page_one_are_eager(): void {
		global $paged, $wp_query;
		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$posts = [];
		foreach ( range( 1, 7 ) as $number ) {
			$post = $this->post( Travel::TRIP, 'Image trip ' . $number );
			if ( $number > 3 ) {
				set_post_thumbnail( $post->ID, $attachment );
			}
			$posts[] = get_post( $post->ID );
		}
		$paged = 1;
		$wp_query = new WP_Query();
		$wp_query->posts = $posts;
		$wp_query->set( 'pkiw_group_source', Travel_Archive::SOURCE );

		$image_index = 0;
		foreach ( $posts as $post ) {
			$html = Travel_Archive::entry_html( $post, Travel::TRIP, [ 'headingLevel' => 2 ], true );
			if ( ! has_post_thumbnail( $post ) ) {
				$this->assertStringNotContainsString( '<img', $html );
				continue;
			}
			if ( $image_index < 3 ) {
				$this->assertStringContainsString( 'loading="eager" fetchpriority="high"', $html );
			} else {
				$this->assertStringNotContainsString( 'fetchpriority="high"', $html );
			}
			++$image_index;
		}
	}

	public function test_a_year_crossing_the_page_break_repeats_its_h2(): void {
		foreach ( range( 1, 13 ) as $day ) {
			$this->post( Travel::TRIP, 'Trip ' . $day, [ 'post_date' => sprintf( '2026-05-%02d 09:00:00', $day ) ] );
		}
		$url = get_term_link( Travel::TRIP, Taxonomy::TAXONOMY );
		$this->assertIsString( $url );
		$heading = '<h2 id="pkiw-group-2026" class="pkiw-travel-year__heading">2026</h2>';
		$this->assertStringContainsString( $heading, $this->serve( $url ) );
		$this->assertStringContainsString( $heading, $this->serve( trailingslashit( $url ) . 'page/2/' ) );
	}

	private function serve( string $url ): string {
		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-trip.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );
		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}
}

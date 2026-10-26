<?php
/**
 * An editor on the front end sees a check-in's location the way a visitor
 * does. The full location stays on admin screens and in REST requests with
 * context=edit, which is how the block editor loads posts, meta and
 * server-side block previews.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Checkin_Map;
use PKIW\Meta_Fields;

use function PKIW\get_checkin_location;

/**
 * @group integration
 */
final class FrontEndLocationSameForEditorsTest extends WP_UnitTestCase {

	private const VENUE_NAME = 'Sentinel Venue Edq7';
	private const STREET     = '19 Sentinel Row Edq7';
	private const LOCALITY   = 'Edqville';
	private const REGION     = 'EQ';
	private const COUNTRY    = 'Edqland';
	private const POSTAL     = 'EDQ7POST';
	private const LATITUDE   = '41.987654';
	private const LONGITUDE  = '-87.123456';
	private const VENUE_URL  = 'https://venue.example/edq7';

	private int $editor_id;

	public function set_up(): void {
		parent::set_up();
		// The test framework wipes registered meta between tests.
		( new Meta_Fields() )->register_meta_fields();
		$this->editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		$GLOBALS['current_screen'] = null;
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_doing_cron' );
		parent::tear_down();
	}

	/**
	 * A published check-in carrying a checkin card and every location key the
	 * card, the checkins feed and the check-in map read.
	 *
	 * @param string $privacy _pkiw_geo_privacy.
	 * @param string $content Post content.
	 */
	private function checkin( string $privacy, string $content = '' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Edq7 check-in',
				'post_content' => '' !== $content ? $content : $this->checkin_block(),
			]
		);
		wp_set_object_terms( $post_id, 'checkin', 'kind' );

		$meta = [
			// The card, the check-in map and REST.
			'checkin_name'        => self::VENUE_NAME,
			'checkin_address'     => self::STREET,
			'checkin_locality'    => self::LOCALITY,
			'checkin_region'      => self::REGION,
			'checkin_country'     => self::COUNTRY,
			'checkin_postal_code' => self::POSTAL,
			'checkin_url'         => self::VENUE_URL,
			'geo_latitude'        => self::LATITUDE,
			'geo_longitude'       => self::LONGITUDE,
			// The keys get_checkin_location() falls back to without a venue term.
			'checkin_venue'       => self::VENUE_NAME,
			'checkin_city'        => self::LOCALITY,
			'checkin_latitude'    => self::LATITUDE,
			'checkin_longitude'   => self::LONGITUDE,
			'geo_privacy'         => $privacy,
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $key, $value );
		}

		return $post_id;
	}

	private function checkin_block(): string {
		return sprintf(
			'<!-- wp:post-kinds-indieweb/checkin-card %s /-->',
			wp_json_encode(
				[
					'venueName'       => self::VENUE_NAME,
					'address'         => self::STREET,
					'locality'        => self::LOCALITY,
					'region'          => self::REGION,
					'country'         => self::COUNTRY,
					'postalCode'      => self::POSTAL,
					'latitude'        => (float) self::LATITUDE,
					'longitude'       => (float) self::LONGITUDE,
					'venueUrl'        => self::VENUE_URL,
					// Public, so the post's row decides.
					'locationPrivacy' => 'public',
				]
			)
		);
	}

	private function eat_block(): string {
		return sprintf(
			'<!-- wp:post-kinds-indieweb/eat-card %s /-->',
			wp_json_encode(
				[
					'name'             => 'Edq7 Dish',
					'restaurant'       => self::VENUE_NAME,
					'restaurantUrl'    => self::VENUE_URL,
					'locationName'     => self::VENUE_NAME,
					'locationAddress'  => self::STREET,
					'locationLocality' => self::LOCALITY,
					'locationRegion'   => self::REGION,
					'locationCountry'  => self::COUNTRY,
					'geoLatitude'      => (float) self::LATITUDE,
					'geoLongitude'     => (float) self::LONGITUDE,
				]
			)
		);
	}

	/**
	 * Render a post's content on its own permalink, as the given user.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID, 0 for a visitor.
	 */
	private function render_front_end( int $post_id, int $user_id ): string {
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $post_id ) );
		return do_blocks( (string) get_post_field( 'post_content', $post_id ) );
	}

	private function assert_no_precise_location( string $html ): void {
		$this->assertStringNotContainsString( self::STREET, $html );
		$this->assertStringNotContainsString( self::POSTAL, $html );
		$this->assertStringNotContainsString( self::LATITUDE, $html );
		$this->assertStringNotContainsString( self::LONGITUDE, $html );
		$this->assertStringNotContainsString( self::VENUE_URL, $html );
	}

	private function assert_no_location( string $html ): void {
		$this->assert_no_precise_location( $html );
		$this->assertStringNotContainsString( self::VENUE_NAME, $html );
		$this->assertStringNotContainsString( self::LOCALITY, $html );
		$this->assertStringNotContainsString( self::COUNTRY, $html );
	}

	private function feed_block(): string {
		return '<!-- wp:post-kinds-indieweb/checkins-feed {"showMap":true,"count":5} /-->';
	}

	/*
	 * Fail on develop 3607f15: the editor override reached the front end.
	 */

	public function test_editor_front_end_private_checkin_card_prints_no_location(): void {
		$html = $this->render_front_end( $this->checkin( 'private' ), $this->editor_id );

		$this->assert_no_location( $html );
	}

	public function test_editor_front_end_approximate_checkin_card_prints_only_the_approximate_tier(): void {
		$html = $this->render_front_end( $this->checkin( 'approximate' ), $this->editor_id );

		$this->assertStringContainsString( self::VENUE_NAME, $html );
		$this->assertStringContainsString( self::LOCALITY, $html );
		$this->assert_no_precise_location( $html );
	}

	public function test_editor_front_end_private_eat_card_prints_no_location(): void {
		$html = $this->render_front_end( $this->checkin( 'private', $this->eat_block() ), $this->editor_id );

		$this->assert_no_location( $html );
	}

	public function test_editor_front_end_approximate_eat_card_prints_only_the_approximate_tier(): void {
		$html = $this->render_front_end( $this->checkin( 'approximate', $this->eat_block() ), $this->editor_id );

		$this->assertStringContainsString( self::VENUE_NAME, $html );
		$this->assert_no_precise_location( $html );
	}

	public function test_editor_front_end_private_checkins_feed_prints_no_location(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( [], get_checkin_location( $post_id ) );
		$this->assert_no_location( do_blocks( $this->feed_block() ) );
	}

	public function test_editor_front_end_approximate_checkins_feed_keeps_only_the_approximate_tier(): void {
		$post_id = $this->checkin( 'approximate' );
		wp_set_current_user( $this->editor_id );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame(
			[
				'name'    => self::VENUE_NAME,
				'city'    => self::LOCALITY,
				'country' => self::COUNTRY,
			],
			array_intersect_key( get_checkin_location( $post_id ), [ 'name' => 1, 'city' => 1, 'country' => 1, 'address' => 1, 'latitude' => 1 ] )
		);
		$this->assert_no_precise_location( do_blocks( $this->feed_block() ) );
	}

	public function test_editor_front_end_private_map_entry_has_no_location(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );
		$this->go_to( get_permalink( $post_id ) );

		$entry = Checkin_Map::entries( [ get_post( $post_id ) ] )[0];

		$this->assertSame( '', $entry['name'] );
		$this->assertSame( [], $entry['place'] );
		$this->assertNull( $entry['lat'] );
		$this->assertNull( $entry['lng'] );
		$this->assert_no_location( Checkin_Map::render_archive( [ get_post( $post_id ) ], 'class="pkiw-checkin-archive"' ) );
	}

	public function test_editor_front_end_approximate_map_entry_has_no_pin(): void {
		$post_id = $this->checkin( 'approximate' );
		wp_set_current_user( $this->editor_id );
		$this->go_to( get_permalink( $post_id ) );

		$entry = Checkin_Map::entries( [ get_post( $post_id ) ] )[0];

		$this->assertSame( self::VENUE_NAME, $entry['name'] );
		$this->assertNull( $entry['lat'] );
	}

	public function test_editor_front_end_ajax_gets_the_visitor_fields(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );
		set_current_screen( 'edit-post' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( Meta_Fields::get_public_location_fields( $post_id ), Meta_Fields::get_visible_location_fields( $post_id ) );
	}

	public function test_editor_cron_gets_the_visitor_fields(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );
		set_current_screen( 'edit-post' );
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->assertSame( Meta_Fields::get_public_location_fields( $post_id ), Meta_Fields::get_visible_location_fields( $post_id ) );
	}

	public function test_save_hooks_on_an_admin_screen_get_the_visitor_fields(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );
		set_current_screen( 'edit-post' );

		$seen = null;
		$spy  = static function ( $id ) use ( $post_id, &$seen ): void {
			if ( $post_id === (int) $id ) {
				$seen = Meta_Fields::get_visible_location_fields( $post_id );
			}
		};
		add_action( 'save_post', $spy, 20 );
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_excerpt' => 'Saved from post.php',
			]
		);
		remove_action( 'save_post', $spy, 20 );

		$this->assertSame( Meta_Fields::get_public_location_fields( $post_id ), $seen );
	}

	public function test_editor_rest_view_context_gets_visitor_meta(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );

		$meta = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/wp/v2/posts/{$post_id}" ) )->get_data()['meta'];

		$this->assertSame( '', $meta['_pkiw_checkin_address'] );
		$this->assertSame( '', $meta['_pkiw_checkin_name'] );
		$this->assertEmpty( $meta['_pkiw_geo_latitude'] );
	}

	/*
	 * Guards: pass before and after.
	 */

	public function test_editor_admin_screen_gets_every_field(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );
		set_current_screen( 'edit-post' );

		$this->assertSame(
			array_fill_keys( array_keys( Meta_Fields::get_public_location_fields( 0 ) ), true ),
			Meta_Fields::get_visible_location_fields( $post_id )
		);
	}

	public function test_editor_rest_edit_context_get_returns_full_location_meta(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', "/wp/v2/posts/{$post_id}" );
		$request->set_param( 'context', 'edit' );
		$meta = rest_get_server()->dispatch( $request )->get_data()['meta'];

		$this->assertSame( self::STREET, $meta['_pkiw_checkin_address'] );
		$this->assertSame( self::VENUE_NAME, $meta['_pkiw_checkin_name'] );
		$this->assertSame( self::LOCALITY, $meta['_pkiw_checkin_locality'] );
		$this->assertEquals( (float) self::LATITUDE, (float) $meta['_pkiw_geo_latitude'] );
	}

	public function test_editor_rest_save_round_trip_keeps_location_meta(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );

		// The block editor loads the post in edit context and saves the
		// whole meta object back when any meta field changed.
		$get = new WP_REST_Request( 'GET', "/wp/v2/posts/{$post_id}" );
		$get->set_param( 'context', 'edit' );
		$meta = rest_get_server()->dispatch( $get )->get_data()['meta'];

		$meta['_pkiw_checkin_region'] = 'EQ2';
		$put                          = new WP_REST_Request( 'PUT', "/wp/v2/posts/{$post_id}" );
		$put->set_body_params(
			[
				'title' => 'Edq7 check-in, edited',
				'meta'  => $meta,
			]
		);
		$response = rest_get_server()->dispatch( $put );

		$this->assertSame( 200, $response->get_status() );
		$saved = $response->get_data()['meta'];
		$this->assertSame( self::STREET, $saved['_pkiw_checkin_address'] );
		$this->assertSame( self::VENUE_NAME, $saved['_pkiw_checkin_name'] );
		$this->assertSame( 'EQ2', $saved['_pkiw_checkin_region'] );

		$this->assertSame( self::STREET, get_post_meta( $post_id, '_pkiw_checkin_address', true ) );
		$this->assertSame( self::VENUE_NAME, get_post_meta( $post_id, '_pkiw_checkin_name', true ) );
		$this->assertSame( self::POSTAL, get_post_meta( $post_id, '_pkiw_checkin_postal_code', true ) );
		$this->assertEquals( (float) self::LATITUDE, (float) get_post_meta( $post_id, '_pkiw_geo_latitude', true ) );
		$this->assertSame( 'private', get_post_meta( $post_id, '_pkiw_geo_privacy', true ) );
	}

	public function test_editor_block_renderer_edit_context_prints_the_full_card(): void {
		$post_id = $this->checkin( 'private' );
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/checkin-card' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'attributes', json_decode( (string) wp_json_encode( parse_blocks( $this->checkin_block() )[0]['attrs'] ), true ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( self::STREET, $response->get_data()['rendered'] );
	}

	public function test_visitor_front_end_output_is_unchanged(): void {
		$approximate = $this->render_front_end( $this->checkin( 'approximate' ), 0 );
		$private     = $this->render_front_end( $this->checkin( 'private' ), 0 );
		$public      = $this->render_front_end( $this->checkin( 'public' ), 0 );

		$this->assertStringContainsString( self::VENUE_NAME, $approximate );
		$this->assert_no_precise_location( $approximate );
		$this->assert_no_location( $private );
		$this->assertStringContainsString( self::STREET, $public );
	}
}

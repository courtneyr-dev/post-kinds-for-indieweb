<?php
/** Travel privacy surface sentinels and wiring. @package PKIW */

declare(strict_types=1);

use PKIW\Integrations\Atmosphere_Document_Map;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;

/** @group integration */
final class TravelPrivacyTest extends WP_UnitTestCase {

	private const APPROXIMATE_CITY = 'Approximate City';
	private const SENTINELS = [
		'Sentinel Quay',
		'Private Sentinelburg',
		'1 Private Sentinel Way',
		'1 Approximate Sentinel Way',
		'1 Future Sentinel Way',
		'Future Sentinel',
		'Future Sentinelburg',
		'SE244',
		'sentinel.example',
		'osm-244244',
		'venue-244244',
		'0.2442',
		'0.4424',
		'2099-12-31',
	];

	private int $editor_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Travel() )->register();
		Travel::register_blocks();
		update_option( 'timezone_string', 'America/New_York' );
		wp_set_current_user( 0 );
		$this->editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		$GLOBALS['current_screen'] = null;
		wp_reset_postdata();
		parent::tear_down();
	}

	private function assertNoSentinels( string $value ): void {
		foreach ( self::SENTINELS as $sentinel ) {
			$this->assertStringNotContainsStringIgnoringCase( $sentinel, $value, $sentinel );
		}
	}

	private function assertVisitorCard( string $html ): void {
		$this->assertStringContainsString( self::APPROXIMATE_CITY, $html, 'The real card rendered the allowed approximate locality.' );
		$this->assertStringContainsString( '>1 stop<', $html, 'Only the approximate past stop counts.' );
		$this->assertNoSentinels( $html );
	}

	private function post(): int {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_date'    => '2000-01-01 09:00:00',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/trip-card /-->',
			]
		);
		wp_set_object_terms( $id, 'trip', Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( $this->stops() ) );
		return (int) $id;
	}

	private function stops(): array {
		return [
			[
				'name' => 'Sentinel Quay', 'locality' => 'Private Sentinelburg', 'street' => '1 Private Sentinel Way',
				'postal_code' => 'SE244-private', 'url' => 'https://sentinel.example/private', 'osm_id' => 'osm-244244-private',
				'venue_id' => 'venue-244244-private', 'lat' => 0.244244, 'lng' => 0.442442, 'date' => '2000-01-02', 'privacy' => 'private',
			],
			[
				'name' => 'Approximate Harbor', 'locality' => self::APPROXIMATE_CITY, 'street' => '1 Approximate Sentinel Way',
				'postal_code' => 'SE244-approximate', 'url' => 'https://sentinel.example/approximate', 'osm_id' => 'osm-244244-approximate',
				'venue_id' => 'venue-244244-approximate', 'lat' => 0.244244, 'lng' => 0.442442, 'date' => '2000-01-03', 'privacy' => 'approximate',
			],
			[
				'name' => 'Future Sentinel', 'locality' => 'Future Sentinelburg', 'street' => '1 Future Sentinel Way',
				'postal_code' => 'SE244-future', 'url' => 'https://sentinel.example/future', 'osm_id' => 'osm-244244-future',
				'venue_id' => 'venue-244244-future', 'lat' => 0.244244, 'lng' => 0.442442, 'date' => '2099-12-31', 'privacy' => 'public',
			],
		];
	}

	private function setGlobalPost( int $id ): void {
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
	}

	private function render( int $id, int $user_id = 0, ?string $url = null ): string {
		wp_set_current_user( $user_id );
		$this->go_to( $url ?? get_permalink( $id ) );
		$this->setGlobalPost( $id );
		return (string) apply_filters( 'the_content', get_post_field( 'post_content', $id ) );
	}

	public function test_logged_out_editor_preview_admin_and_feed_render_the_real_visitor_card(): void {
		$id = $this->post();
		$this->assertVisitorCard( $this->render( $id ) );
		$this->assertVisitorCard( $this->render( $id, $this->editor_id ) );

		$preview = $this->render( $id, $this->editor_id, add_query_arg( 'preview', 'true', get_permalink( $id ) ) );
		$this->assertTrue( is_preview(), 'The editor case uses a front-end preview query.' );
		$this->assertVisitorCard( $preview );

		set_current_screen( 'post' );
		$this->assertTrue( is_admin() );
		$this->setGlobalPost( $id );
		$this->assertVisitorCard( (string) apply_filters( 'the_content', get_post_field( 'post_content', $id ) ) );
		set_current_screen( 'front' );

		$this->go_to( get_permalink( $id ) );
		$GLOBALS['wp_query']->is_feed = true;
		$this->setGlobalPost( $id );
		$this->assertTrue( is_feed() );
		$this->assertVisitorCard( (string) apply_filters( 'the_content', get_post_field( 'post_content', $id ) ) );
	}

	public function test_excerpt_search_binding_name_and_saved_comment_hold_no_hidden_data(): void {
		$id      = $this->post();
		$content = (string) get_post_field( 'post_content', $id );
		$this->assertSame( '<!-- wp:post-kinds-indieweb/trip-card /-->', $content );
		$this->assertSame( [], parse_blocks( $content )[0]['attrs'] );

		$this->go_to( get_permalink( $id ) );
		$this->setGlobalPost( $id );
		$excerpt = get_the_excerpt( $id );
		$this->assertSame( '', $excerpt, 'Core removes the dynamic card from an automatic excerpt.' );
		$this->assertNoSentinels( $excerpt );
		$this->assertNoSentinels( \PKIW\untitled_name( get_post( $id ) ) );

		$this->go_to( home_url( '/?s=' . rawurlencode( 'Sentinel Quay' ) ) );
		$this->assertSame( [], array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );

		$GLOBALS['wp_rest_server'] = null;
		$request = new WP_REST_Request( 'GET', '/wp/v2/search' );
		$request->set_param( 'search', 'Sentinel Quay' );
		$this->assertSame( [], array_map( 'intval', wp_list_pluck( (array) rest_do_request( $request )->get_data(), 'id' ) ) );

		$source = get_block_bindings_source( 'core/post-meta' );
		$this->assertNotNull( $source );
		$block          = new stdClass();
		$block->context = [ 'postId' => $id ];
		$this->assertNull( $source->get_value( [ 'key' => Travel::STOPS_KEY ], $block, 'content' ) );
	}

	public function test_public_reads_show_approximate_city_and_edit_context_returns_stored_json(): void {
		$id = $this->post();
		foreach ( [ Travel::public_json( $id, Travel::STOPS_KEY ), wp_json_encode( Travel::trip_facts( $id ) ), wp_json_encode( Meta_Fields::redact_location_array( [ Travel::STOPS_KEY => get_post_meta( $id, Travel::STOPS_KEY, true ) ], $id ) ) ] as $value ) {
			$this->assertStringContainsString( self::APPROXIMATE_CITY, (string) $value );
			$this->assertNoSentinels( (string) $value );
		}

		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$request->set_param( 'context', 'edit' );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertStringContainsString( '1 Private Sentinel Way', (string) $data['meta'][ Travel::STOPS_KEY ] );
		$this->assertStringContainsString( '1 Approximate Sentinel Way', (string) $data['meta'][ Travel::STOPS_KEY ] );
	}

	public function test_kind_card_map_and_atmosphere_privacy_keys_include_travel(): void {
		$this->assertSame( 'trip', Taxonomy::KIND_CARD_BLOCKS[ Travel::STOP_BLOCK ] );
		$this->assertSame( 'itinerary', Taxonomy::KIND_CARD_BLOCKS[ Travel::LEG_BLOCK ] );
		$keys = ( new ReflectionClassConstant( Atmosphere_Document_Map::class, 'PRIVACY_META_KEYS' ) )->getValue();
		$this->assertContains( Travel::STOPS_KEY, $keys );
		$this->assertContains( Travel::LEGS_KEY, $keys );
	}

	public function test_travel_meta_write_queues_one_atmosphere_update_for_a_shared_post(): void {
		if ( ! defined( 'ATMOSPHERE_VERSION' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to run ATmosphere scheduling.' );
		}

		update_option( 'atmosphere_identity', [ 'did' => 'did:plc:pkiwtest244' ] );
		update_option( 'atmosphere_did', 'did:plc:pkiwtest244' );
		update_option( 'atmosphere_connection', [ 'access_token' => 'test-token' ] );
		$this->assertTrue( \Atmosphere\is_connected() );

		$id = $this->post();
		update_post_meta( $id, \Atmosphere\Transformer\Post::META_TID, '3pkiwtest244' );
		wp_clear_scheduled_hook( 'atmosphere_update_post', [ $id ] );
		update_post_meta( $id, Travel::STOPS_KEY, wp_json_encode( [ [ 'name' => 'Tightened stop', 'date' => '2000-01-01', 'privacy' => 'private' ] ] ) );

		$this->assertNotFalse( wp_next_scheduled( 'atmosphere_update_post', [ $id ] ) );
		wp_clear_scheduled_hook( 'atmosphere_update_post', [ $id ] );
	}
}

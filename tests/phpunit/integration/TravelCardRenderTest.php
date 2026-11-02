<?php
/** Travel card registration and rendering. @package PKIW */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Travel;

/** @group integration */
final class TravelCardRenderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Travel() )->register();
		Travel::register_blocks();
		update_option( 'timezone_string', 'America/Los_Angeles' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
	}

	private function post( string $kind, string $key, array $items ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2000-01-01 09:00:00' ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );
		update_post_meta( $id, $key, wp_json_encode( $items ) );
		return (int) $id;
	}

	public function test_blocks_are_dynamic_attribute_free_and_single_use(): void {
		$control_name = 'pkiw-test/attribute-control';
		if ( WP_Block_Type_Registry::get_instance()->is_registered( $control_name ) ) {
			unregister_block_type( $control_name );
		}
		register_block_type(
			$control_name,
			[
				'api_version' => 3,
				'attributes'  => [],
				'supports'    => [
					'html'      => false,
					'multiple'  => false,
					'reusable'  => false,
					'className' => true,
				],
			]
		);
		// Core adds the supports attributes (className, style) to every block registered by init; run that pass again for the late control.
		WP_Block_Supports::init();
		$core_attributes = array_keys( WP_Block_Type_Registry::get_instance()->get_registered( $control_name )->attributes );
		foreach ( [ Travel::STOP_BLOCK, Travel::LEG_BLOCK ] as $name ) {
			$block = WP_Block_Type_Registry::get_instance()->get_registered( $name );
			$this->assertInstanceOf( WP_Block_Type::class, $block );
			$this->assertSame( 3, $block->api_version );
			$this->assertSame( $core_attributes, array_keys( $block->attributes ), 'The card declares no attributes beyond those core injects for the same supports.' );
			$this->assertFalse( $block->supports['multiple'] );
			$this->assertSame( 'pkiw-travel-card-editor', $block->editor_script_handles[0] );
		}
		unregister_block_type( $control_name );
		$this->assertSame( '<!-- wp:post-kinds-indieweb/trip-card /-->', get_comment_delimited_block_content( Travel::STOP_BLOCK, [], '' ) );
		$this->assertSame( '<!-- wp:post-kinds-indieweb/itinerary-card /-->', get_comment_delimited_block_content( Travel::LEG_BLOCK, [], '' ) );
	}

	public function test_trip_card_prints_visible_stop_microformat_and_no_wrapper_when_empty(): void {
		$id = $this->post(
			'trip',
			Travel::STOPS_KEY,
			[ [ 'name' => 'Harbor', 'locality' => 'Portland', 'region' => 'Oregon', 'country' => 'US', 'street' => '2 Contract Street', 'postal_code' => '97205', 'lat' => 45.5152, 'lng' => -122.6784, 'date' => '2000-05-04', 'privacy' => 'public' ] ]
		);
		$block = new WP_Block( [ 'blockName' => Travel::STOP_BLOCK, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'postId' => $id ] );
		$html  = Travel::render_trip_block( [], '', $block );
		$this->assertStringContainsString( 'wp-block-post-kinds-indieweb-trip-card', $html );
		$this->assertStringContainsString( '>1 stop<', $html );
		$this->assertStringContainsString( 'id="pkiw-stop-' . $id . '-1"', $html );
		$this->assertStringContainsString( 'class="pkiw-trip__place h-adr"', $html );
		$this->assertStringContainsString( 'class="p-geo h-geo"', $html );
		$this->assertStringContainsString( 'datetime="2000-05-04"', $html );

		update_post_meta( $id, Travel::STOPS_KEY, '' );
		$this->assertSame( '', Travel::render_stop_list( Travel::visible_stops( $id ), $id ) );
	}

	public function test_itinerary_card_omits_empty_pairs_and_keeps_stored_wall_clock(): void {
		$id = $this->post(
			'itinerary',
			Travel::LEGS_KEY,
			[ [
				'from' => [ 'name' => 'Station', 'locality' => 'Portland' ],
				'to' => [ 'name' => 'Terminal', 'locality' => 'Seattle' ],
				'departure' => '2000-05-04T08:15:00-04:00', 'arrival' => '', 'carrier' => '', 'privacy' => 'public',
			] ]
		);
		$html = Travel::render_leg_list( Travel::visible_legs( $id ), $id );
		$this->assertStringContainsString( '>Leg 1<', $html );
		$this->assertStringContainsString( '<dt>From</dt>', $html );
		$this->assertStringContainsString( '<dt>To</dt>', $html );
		$this->assertStringContainsString( '<dt>Departure</dt>', $html );
		$this->assertStringNotContainsString( '<dt>Arrival</dt>', $html );
		$this->assertStringNotContainsString( '<dt>Carrier</dt>', $html );
		$this->assertStringContainsString( 'datetime="2000-05-04T08:15:00-04:00"', $html );
		$this->assertStringContainsString( '8:15 am', $html );
		update_post_meta( $id, Travel::LEGS_KEY, '' );
		$this->assertSame( '', Travel::render_leg_list( Travel::visible_legs( $id ), $id ) );
	}
}

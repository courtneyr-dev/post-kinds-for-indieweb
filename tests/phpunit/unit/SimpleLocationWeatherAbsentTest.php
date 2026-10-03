<?php
/**
 * Simple Location weather adapter with Simple Location absent.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Unit;

use PKIW\Block_Bindings;
use PKIW\Integrations\Simple_Location_Weather;
use WP_Block;
use WP_UnitTestCase;

/**
 * Lives in the unit suite on purpose: only integration tests load the
 * Simple Location fixture, and phpunit.xml.dist runs `unit` first, so here
 * Simple Location's functions are genuinely undefined.
 */
final class SimpleLocationWeatherAbsentTest extends WP_UnitTestCase {

	/**
	 * With no Simple Location functions: inactive, null, no markup, no notices.
	 */
	public function test_absent_simple_location_is_inactive_and_silent(): void {
		if ( function_exists( 'get_post_weatherdata' ) ) {
			$this->markTestSkipped( 'Simple Location functions are already defined (real plugin or fixture loaded); absence cannot be tested in this process.' );
		}

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $post_id, 'weather', 'kind' );
		add_post_meta( $post_id, 'weather_temperature', 20 );
		add_post_meta( $post_id, 'geo_public', '1' );

		$block = new WP_Block(
			[
				'blockName' => 'core/paragraph',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		);
		$block->context = [ 'postId' => $post_id ];

		$this->assertFalse( Simple_Location_Weather::is_active() );
		$this->assertNull( Simple_Location_Weather::get_observation( $post_id ) );
		$this->assertSame( '', Simple_Location_Weather::render( $post_id ) );
		$this->assertNull( ( new Block_Bindings() )->get_binding_value( [ 'key' => 'weather_temperature' ], $block, 'content' ) );
		$this->assertStringNotContainsString( 'p-weather', \PKIW\render_generic_stream_card( get_post( $post_id ) ) );
	}
}

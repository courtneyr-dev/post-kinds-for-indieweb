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
use PKIW\Micropub_Content_Builder;
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

	/**
	 * With Simple Location deactivated, a weather post's authored p-weather
	 * paragraph (Micropub's weather_paragraph() markup) still renders on the
	 * single and the Stream card, Post Kinds leaves Simple Location's display
	 * defaults alone, and nothing raises a notice or warning (#209).
	 */
	public function test_authored_weather_renders_without_simple_location(): void {
		if ( function_exists( 'get_post_weatherdata' ) ) {
			$this->markTestSkipped( 'Simple Location functions are already defined (real plugin or fixture loaded); absence cannot be tested in this process.' );
		}

		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			[ 'properties' => [ 'weather' => [ 'Sunny and warm' ] ] ]
		);
		$this->assertSame( [ 'Sunny and warm' ], self::class_texts( $content, 'p-weather' ) );

		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => $content,
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $post_id, 'weather', 'kind' ) );
		$this->assertTrue( has_term( 'weather', 'kind', $post_id ) );
		add_post_meta( $post_id, 'weather_temperature', 20 );
		add_post_meta( $post_id, 'geo_public', '1' );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		// Read Simple Location's display defaults where it would, at
		// the_content priority 12.
		$defaults = null;
		$record   = static function ( $content ) use ( &$defaults ) {
			$defaults = apply_filters( 'simple_location_display_defaults', [ 'weather' => true ] );
			return $content;
		};
		add_filter( 'the_content', $record, 12 );

		$errors = [];
		set_error_handler(
			static function ( int $errno, string $errstr, string $errfile, int $errline ) use ( &$errors ): bool {
				$errors[] = $errstr . ' at ' . $errfile . ':' . $errline;
				return true;
			},
			E_NOTICE | E_WARNING | E_DEPRECATED | E_USER_NOTICE | E_USER_WARNING | E_USER_DEPRECATED
		);
		try {
			$html = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
			$card = \PKIW\render_generic_stream_card( get_post( $post_id ) );
		} finally {
			restore_error_handler();
			remove_filter( 'the_content', $record, 12 );
		}

		$this->assertSame( [], $errors );
		$this->assertSame( [ 'Sunny and warm' ], self::class_texts( $html, 'p-weather' ) );
		$this->assertSame( [ 'weather' => true ], $defaults );
		$this->assertSame( [ 'Sunny and warm' ], self::class_texts( $card, 'pk-excerpt' ) );
		$this->assertSame( [], self::class_texts( $card, 'p-weather' ) );
	}

	/**
	 * Text of every element whose class list holds $class, in document order.
	 *
	 * @param string $html  Markup fragment.
	 * @param string $class One class token.
	 * @return string[]
	 */
	private static function class_texts( string $html, string $class ): array {
		$internal = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $internal );

		$texts = [];
		$nodes = ( new \DOMXPath( $doc ) )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]' );
		foreach ( $nodes as $node ) {
			$texts[] = trim( $node->textContent );
		}
		return $texts;
	}
}

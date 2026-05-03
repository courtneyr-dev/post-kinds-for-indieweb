<?php
/**
 * Tests for the Micropub_Content_Builder bridge.
 *
 * Exercises the per-kind block builders against representative h-entry
 * property bags and confirms the output is the expected block markup.
 * The private static methods are reached via Reflection — same pattern
 * the rest of the plugin's PHPUnit tests use.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use ReflectionMethod;
use WP_UnitTestCase;
use PostKindsForIndieWeb\Micropub_Content_Builder;

/**
 * @covers \PostKindsForIndieWeb\Micropub_Content_Builder
 */
class MicropubContentBuilderTest extends WP_UnitTestCase {

	/**
	 * Invoke a private static method on the builder by name.
	 *
	 * @param string                $method
	 * @param array<int, mixed>     $args
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args = array() ) {
		$ref = new ReflectionMethod( Micropub_Content_Builder::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	// --- detect_kind --------------------------------------------------------

	public function test_detect_kind_eat_takes_precedence_over_location(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'eat-of'   => 'tacos al pastor',
					'location' => 'geo:29.12,-103.24',
				),
			)
		);
		$this->assertSame( 'eat', $kind );
	}

	public function test_detect_kind_checkin_when_only_location(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'location' => 'geo:29.12,-103.24' ) )
		);
		$this->assertSame( 'checkin', $kind );
	}

	public function test_detect_kind_returns_null_for_plain_note(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'content' => 'just a note' ) )
		);
		$this->assertNull( $kind );
	}

	public function test_detect_kind_listen_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'listen-of' => 'https://example.test/track/123' ) )
		);
		$this->assertSame( 'listen', $kind );
	}

	public function test_detect_kind_mood_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'mood' => 'focused' ) )
		);
		$this->assertSame( 'mood', $kind );
	}

	// --- parse_geo_from_location -------------------------------------------

	public function test_parse_geo_extracts_lat_lon(): void {
		$result = $this->invoke_private(
			'parse_geo_from_location',
			array( array( 'location' => 'geo:29.12,-103.24' ) )
		);
		$this->assertEqualsWithDelta( 29.12, $result['lat'], 0.001 );
		$this->assertEqualsWithDelta( -103.24, $result['lon'], 0.001 );
	}

	public function test_parse_geo_handles_altitude(): void {
		$result = $this->invoke_private(
			'parse_geo_from_location',
			array( array( 'location' => 'geo:29.12,-103.24,1500' ) )
		);
		$this->assertEqualsWithDelta( 29.12, $result['lat'], 0.001 );
	}

	public function test_parse_geo_returns_null_for_url_location(): void {
		$result = $this->invoke_private(
			'parse_geo_from_location',
			array( array( 'location' => 'https://example.test/place/' ) )
		);
		$this->assertNull( $result );
	}

	public function test_parse_geo_returns_null_for_missing_location(): void {
		$result = $this->invoke_private(
			'parse_geo_from_location',
			array( array() )
		);
		$this->assertNull( $result );
	}

	// --- Per-kind card builders --------------------------------------------

	public function test_checkin_card_includes_venue_and_geo(): void {
		$markup = $this->invoke_private(
			'checkin_card',
			array(
				array(
					'mp-place-name' => 'St. Johns United Church of Christ',
					'location'      => 'geo:39.93,-77.66',
					'content'       => 'beautiful afternoon',
				),
			)
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/checkin-card', $markup );
		$this->assertStringContainsString( '"venueName":"St. Johns United Church of Christ"', $markup );
		$this->assertStringContainsString( '"latitude":39.93', $markup );
		$this->assertStringContainsString( '"longitude":-77.66', $markup );
		$this->assertStringContainsString( '"note":"beautiful afternoon"', $markup );
	}

	public function test_eat_card_routes_eat_of_to_name(): void {
		$markup = $this->invoke_private(
			'eat_card',
			array(
				array(
					'eat-of'        => 'tacos al pastor',
					'mp-place-name' => 'Tacos Don Cuco',
					'rating'        => '4',
				),
			)
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/eat-card', $markup );
		$this->assertStringContainsString( '"name":"tacos al pastor"', $markup );
		$this->assertStringContainsString( '"locationName":"Tacos Don Cuco"', $markup );
		$this->assertStringContainsString( '"rating":4', $markup );
	}

	public function test_read_card_includes_status_and_review(): void {
		$markup = $this->invoke_private(
			'read_card',
			array(
				array(
					'read-of'     => 'https://openlibrary.org/works/OL45883W',
					'name'        => 'The Phoenix Project',
					'author'      => 'Gene Kim',
					'read-status' => 'reading',
					'rating'      => '5',
					'content'     => 'Halfway through.',
				),
			)
		);
		$this->assertStringContainsString( '"bookTitle":"The Phoenix Project"', $markup );
		$this->assertStringContainsString( '"authorName":"Gene Kim"', $markup );
		$this->assertStringContainsString( '"readStatus":"reading"', $markup );
		$this->assertStringContainsString( '"review":"Halfway through."', $markup );
	}

	public function test_self_closing_block_with_no_attrs(): void {
		$markup = $this->invoke_private(
			'self_closing_block',
			array( 'post-kinds-indieweb/checkin-card', array() )
		);
		$this->assertSame( '<!-- wp:post-kinds-indieweb/checkin-card /-->', $markup );
	}

	// --- wrap_h_entry ------------------------------------------------------

	public function test_wrap_h_entry_includes_e_content_when_body_present(): void {
		$markup = $this->invoke_private(
			'wrap_h_entry',
			array( '<!-- wp:post-kinds-indieweb/checkin-card /-->', 'a sunny afternoon' )
		);
		$this->assertStringContainsString( 'class="wp-block-group h-entry"', $markup );
		$this->assertStringContainsString( 'class="wp-block-group e-content"', $markup );
		$this->assertStringContainsString( '<p>a sunny afternoon</p>', $markup );
	}

	public function test_wrap_h_entry_omits_paragraph_when_body_empty(): void {
		$markup = $this->invoke_private(
			'wrap_h_entry',
			array( '<!-- wp:post-kinds-indieweb/checkin-card /-->', '' )
		);
		$this->assertStringContainsString( 'class="wp-block-group h-entry"', $markup );
		$this->assertStringNotContainsString( 'e-content', $markup );
	}

	// --- end-to-end via apply() --------------------------------------------

	public function test_apply_writes_block_content_for_checkin_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'plain text typed body',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'             => 'entry',
				'mp-place-name' => 'St. Johns',
				'location'      => 'geo:39.93,-77.66',
				'content'       => 'plain text typed body',
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/checkin-card', $content );
		$this->assertStringContainsString( 'plain text typed body', $content );
		$this->assertSame( '1', (string) get_post_meta( $post_id, '_pkiw_block_content_generated', true ) );
	}

	public function test_apply_idempotent_on_second_call(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'first body',
			)
		);

		// First call writes block markup + sets the marker.
		Micropub_Content_Builder::apply(
			array(
				'h'             => 'entry',
				'mp-place-name' => 'Original Venue',
				'location'      => 'geo:1,1',
			),
			array( 'ID' => $post_id )
		);
		$first = (string) get_post_field( 'post_content', $post_id );

		// User then edits in Gutenberg (simulated by setting different content).
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>user edited this</p>',
			)
		);

		// Second Micropub call (e.g., an update from the same client) must
		// NOT clobber the user's edits.
		Micropub_Content_Builder::apply(
			array(
				'h'             => 'entry',
				'mp-place-name' => 'Different Venue',
				'location'      => 'geo:99,99',
			),
			array( 'ID' => $post_id )
		);

		$second = (string) get_post_field( 'post_content', $post_id );
		$this->assertSame( '<p>user edited this</p>', $second );
		$this->assertNotSame( $first, $second );
	}

	public function test_apply_skips_for_plain_note(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'just a note, no card kind',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'       => 'entry',
				'content' => 'just a note, no card kind',
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertSame( 'just a note, no card kind', $content );
		$this->assertSame( '', (string) get_post_meta( $post_id, '_pkiw_block_content_generated', true ) );
	}
}

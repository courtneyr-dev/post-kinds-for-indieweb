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

	// --- photo / gallery ----------------------------------------------------

	public function test_detect_kind_photo_when_only_photo_present(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'photo'   => array( 'https://example.test/uploads/cat.jpg' ),
					'content' => 'a cat',
				),
			)
		);
		$this->assertSame( 'photo', $kind );
	}

	public function test_detect_kind_specific_of_kinds_take_precedence_over_photo(): void {
		// A photo posted with watch-of should still route to 'watch' so
		// the watch-card carries the right structured data; the photo
		// becomes part of the watch card's media slot in a future
		// enhancement, not a separate gallery wrapper.
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'watch-of' => 'https://example.test/movie',
					'photo'    => array( 'https://example.test/uploads/poster.jpg' ),
				),
			)
		);
		$this->assertSame( 'watch', $kind );
	}

	public function test_detect_kind_checkin_takes_precedence_over_photo(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'location' => 'geo:42.0,-71.0',
					'photo'    => array( 'https://example.test/uploads/place.jpg' ),
				),
			)
		);
		$this->assertSame( 'checkin', $kind );
	}

	public function test_photo_card_single_url_produces_image_block(): void {
		$markup = $this->invoke_private(
			'photo_card',
			array(
				array(
					'photo'         => array( 'https://example.test/uploads/cat.jpg' ),
					'mp-photo-alt'  => array( 'a cat' ),
				),
			)
		);
		$this->assertStringContainsString( '<!-- wp:image', $markup );
		$this->assertStringNotContainsString( '<!-- wp:gallery', $markup );
		$this->assertStringContainsString( 'src="https://example.test/uploads/cat.jpg"', $markup );
		$this->assertStringContainsString( 'alt="a cat"', $markup );
	}

	public function test_photo_card_multiple_urls_produce_gallery_block(): void {
		$markup = $this->invoke_private(
			'photo_card',
			array(
				array(
					'photo'        => array(
						'https://example.test/uploads/cat-1.jpg',
						'https://example.test/uploads/cat-2.jpg',
						'https://example.test/uploads/cat-3.jpg',
					),
					'mp-photo-alt' => array( 'first cat', 'second cat', 'third cat' ),
				),
			)
		);
		$this->assertStringContainsString( '<!-- wp:gallery', $markup );
		$this->assertStringContainsString( '"linkTo":"none"', $markup );
		// One core/image block per photo.
		$this->assertSame( 3, substr_count( $markup, '<!-- wp:image' ) );
		// Alt text preserved per-image.
		$this->assertStringContainsString( 'alt="first cat"', $markup );
		$this->assertStringContainsString( 'alt="second cat"', $markup );
		$this->assertStringContainsString( 'alt="third cat"', $markup );
	}

	public function test_photo_card_omits_image_id_when_url_does_not_resolve(): void {
		// URL with no matching attachment in the test DB — block still
		// renders (without `id` attr) so the front-end shows the photo.
		$markup = $this->invoke_private(
			'photo_card',
			array(
				array(
					'photo'        => array( 'https://other-site.test/orphan.jpg' ),
					'mp-photo-alt' => array( 'orphan' ),
				),
			)
		);
		$this->assertStringContainsString( 'src="https://other-site.test/orphan.jpg"', $markup );
		$this->assertStringNotContainsString( 'wp-image-', $markup );
		$this->assertStringNotContainsString( '"id":', $markup );
	}

	public function test_photo_card_handles_missing_alt_array(): void {
		// Some Micropub clients send `photo` without a parallel
		// `mp-photo-alt`. Image blocks should still render, with empty
		// alt attributes (browser defaults applied).
		$markup = $this->invoke_private(
			'photo_card',
			array(
				array(
					'photo' => array( 'https://example.test/uploads/no-alt.jpg' ),
				),
			)
		);
		$this->assertStringContainsString( 'alt=""', $markup );
	}

	public function test_photo_card_returns_empty_when_photo_property_missing(): void {
		$markup = $this->invoke_private(
			'photo_card',
			array( array() )
		);
		$this->assertSame( '', $markup );
	}

	public function test_apply_writes_gallery_block_for_multi_photo_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Hard at work',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'             => 'entry',
				'content'       => array( 'Hard at work' ),
				'photo'         => array(
					'https://example.test/uploads/cat-1.jpg',
					'https://example.test/uploads/cat-2.jpg',
					'https://example.test/uploads/cat-3.jpg',
				),
				'mp-photo-alt'  => array( 'cat-1', 'cat-2', 'cat-3' ),
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'h-entry', $content );
		$this->assertStringContainsString( '<!-- wp:gallery', $content );
		$this->assertStringContainsString( 'cat-1.jpg', $content );
		$this->assertStringContainsString( 'cat-3.jpg', $content );
		// User's typed body still appears as e-content.
		$this->assertStringContainsString( 'e-content', $content );
		$this->assertStringContainsString( 'Hard at work', $content );
		// Idempotency marker is set so subsequent edits aren't clobbered.
		$this->assertSame( '1', (string) get_post_meta( $post_id, '_pkiw_block_content_generated', true ) );
	}

	public function test_apply_writes_single_image_block_for_single_photo(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'one cat',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'            => 'entry',
				'content'      => array( 'one cat' ),
				'photo'        => array( 'https://example.test/uploads/single-cat.jpg' ),
				'mp-photo-alt' => array( 'just one cat' ),
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( '<!-- wp:image', $content );
		$this->assertStringNotContainsString( '<!-- wp:gallery', $content );
		$this->assertStringContainsString( 'alt="just one cat"', $content );
	}

	public function test_apply_handles_string_photo_property(): void {
		// Form-encoded clients may send `photo` as a single string
		// rather than a one-element array. Bridge tolerates both shapes.
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'     => 'entry',
				'photo' => 'https://example.test/uploads/string-cat.jpg',
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'string-cat.jpg', $content );
		$this->assertStringContainsString( '<!-- wp:image', $content );
	}

	public function test_flatten_string_array_handles_scalar_string(): void {
		$out = $this->invoke_private(
			'flatten_string_array',
			array(
				array( 'photo' => 'https://example.test/just-one.jpg' ),
				'photo',
			)
		);
		$this->assertSame( array( 'https://example.test/just-one.jpg' ), $out );
	}

	public function test_flatten_string_array_handles_missing_key(): void {
		$out = $this->invoke_private(
			'flatten_string_array',
			array( array(), 'photo' )
		);
		$this->assertSame( array(), $out );
	}

	public function test_flatten_string_array_replaces_non_strings_with_empty(): void {
		// Index alignment between photo and mp-photo-alt matters; non-
		// scalar entries become empty strings so positional pairing
		// stays stable.
		$out = $this->invoke_private(
			'flatten_string_array',
			array(
				array( 'mp-photo-alt' => array( 'first', null, 'third' ) ),
				'mp-photo-alt',
			)
		);
		$this->assertSame( array( 'first', '', 'third' ), $out );
	}

	// --- photo_card dedupe (issue: Shanske Micropub plugin enriches `$input['photo']` post-sideload) ---

	public function test_photo_card_dedupes_repeated_urls(): void {
		// Reproduces the staging symptom: 3 unique photos arriving as a
		// 6-entry photo array (originals + canonical URLs collapsed to
		// the same local URL) with mp-photo-alt only carrying the first
		// 3 alts. Bridge must produce 3 image blocks total, NOT 6.
		$markup = $this->invoke_private(
			'photo_card',
			array(
				array(
					'photo'        => array(
						'https://example.test/uploads/cat-1.jpg',
						'https://example.test/uploads/cat-2.jpg',
						'https://example.test/uploads/cat-3.jpg',
						'https://example.test/uploads/cat-1.jpg',
						'https://example.test/uploads/cat-2.jpg',
						'https://example.test/uploads/cat-3.jpg',
					),
					'mp-photo-alt' => array( 'Fire', 'Tux', 'Rain' ),
				),
			)
		);
		$this->assertSame( 3, substr_count( $markup, '<!-- wp:image' ) );
		$this->assertStringContainsString( 'alt="Fire"', $markup );
		$this->assertStringContainsString( 'alt="Tux"', $markup );
		$this->assertStringContainsString( 'alt="Rain"', $markup );
		$this->assertStringNotContainsString( 'alt=""', $markup );
	}

	public function test_photo_card_first_occurrence_keeps_alt(): void {
		// When a URL appears twice and only the first occurrence has a
		// matching alt, the kept-image carries that alt — confirms the
		// "first occurrence wins" rule applies to the alt alignment too.
		$markup = $this->invoke_private(
			'photo_card',
			array(
				array(
					'photo'        => array(
						'https://example.test/uploads/cat.jpg',
						'https://example.test/uploads/cat.jpg',
					),
					'mp-photo-alt' => array( 'a cat', '' ),
				),
			)
		);
		$this->assertSame( 1, substr_count( $markup, '<!-- wp:image' ) );
		$this->assertStringContainsString( 'alt="a cat"', $markup );
	}

	public function test_photo_card_dedupe_collapses_to_single_image(): void {
		// Three identical URLs collapse to one image block — and since
		// the deduped count is 1, the gallery wrapper drops away too.
		$markup = $this->invoke_private(
			'photo_card',
			array(
				array(
					'photo'        => array(
						'https://example.test/uploads/single.jpg',
						'https://example.test/uploads/single.jpg',
						'https://example.test/uploads/single.jpg',
					),
					'mp-photo-alt' => array( 'one', 'two', 'three' ),
				),
			)
		);
		$this->assertSame( 1, substr_count( $markup, '<!-- wp:image' ) );
		$this->assertStringNotContainsString( '<!-- wp:gallery', $markup );
		$this->assertStringContainsString( 'alt="one"', $markup );
	}
}

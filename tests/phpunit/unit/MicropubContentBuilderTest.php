<?php
/**
 * Tests for the Micropub_Content_Builder bridge.
 *
 * Exercises the per-kind block builders against representative h-entry
 * property bags and confirms the output is the expected block markup.
 * The private static methods are reached via Reflection — same pattern
 * the rest of the plugin's PHPUnit tests use.
 *
 * @package PKIW
 */

namespace PKIW\Tests\Unit;

use ReflectionMethod;
use WP_UnitTestCase;
use PKIW\Micropub_Content_Builder;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @covers \PKIW\Micropub_Content_Builder
 */
class MicropubContentBuilderTest extends WP_UnitTestCase {

	/**
	 * Recreate the kind terms these tests rely on.
	 *
	 * Earlier suite classes can leak committed term deletions (DDL inside
	 * a test implicitly commits MySQL's rollback transaction), so the
	 * bootstrap-created default terms aren't guaranteed to still exist.
	 */
	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'note', 'checkin', 'eat', 'drink', 'like', 'rsvp', 'jam', 'favorite', 'wish', 'exercise', 'event', 'video', 'quote', 'issue', 'listen', 'comics', 'read' ) as $slug ) {
			if ( ! term_exists( $slug, 'kind' ) ) {
				wp_insert_term( ucfirst( $slug ), 'kind', array( 'slug' => $slug ) );
			}
		}
		$note = get_term_by( 'slug', 'note', 'kind' );
		if ( $note instanceof \WP_Term ) {
			update_option( 'default_term_kind', $note->term_id );
		}
	}

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

	// --- detect_kind — extended kind coverage ------------------------------

	public function test_detect_kind_jam_takes_precedence_over_listen(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'jam-of'    => 'https://example.test/track/9',
					'listen-of' => 'https://example.test/track/9',
				),
			)
		);
		$this->assertSame( 'jam', $kind );
	}

	public function test_detect_kind_favorite_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'favorite-of' => 'https://example.test/post/1' ) )
		);
		$this->assertSame( 'favorite', $kind );
	}

	public function test_detect_kind_wish_recognized_via_canonical_property(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'wish-of' => 'https://example.test/item/1' ) )
		);
		$this->assertSame( 'wish', $kind );
	}

	public function test_detect_kind_wish_recognized_via_wishlist_alias(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'wishlist-of' => 'https://example.test/item/1' ) )
		);
		$this->assertSame( 'wish', $kind );
	}

	public function test_detect_kind_quote_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'quotation-of' => 'https://example.test/essay' ) )
		);
		$this->assertSame( 'quote', $kind );
	}

	public function test_detect_kind_tag_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'tag-of' => 'https://example.test/page' ) )
		);
		$this->assertSame( 'tag', $kind );
	}

	public function test_detect_kind_issue_alias_takes_precedence_over_reply(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'issue-of'    => 'https://example.test/repo',
					'in-reply-to' => 'https://example.test/repo',
				),
			)
		);
		$this->assertSame( 'issue', $kind );
	}

	public function test_detect_kind_craft_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'craft-of' => 'hand-knit scarf' ) )
		);
		$this->assertSame( 'craft', $kind );
	}

	public function test_detect_kind_acquisition_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'acquisition-of' => 'https://example.test/product' ) )
		);
		$this->assertSame( 'acquisition', $kind );
	}

	public function test_detect_kind_exercise_takes_precedence_over_location(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'exercise' => '5k run',
					'location' => 'geo:29.12,-103.24',
				),
			)
		);
		$this->assertSame( 'exercise', $kind );
	}

	public function test_detect_kind_sleep_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'sleep' => '7h 40m' ) )
		);
		$this->assertSame( 'sleep', $kind );
	}

	public function test_detect_kind_trip_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'trip' => 'Chicago to Detroit' ) )
		);
		$this->assertSame( 'trip', $kind );
	}

	public function test_detect_kind_itinerary_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'itinerary' => 'ORD → DTW → YYZ' ) )
		);
		$this->assertSame( 'itinerary', $kind );
	}

	public function test_detect_kind_question_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'question' => 'Best static site host in 2026?' ) )
		);
		$this->assertSame( 'question', $kind );
	}

	public function test_detect_kind_event_takes_precedence_over_checkin(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'name'     => 'WordCamp',
					'start'    => '2026-09-01T10:00:00-04:00',
					'location' => 'geo:29.12,-103.24',
				),
			)
		);
		$this->assertSame( 'event', $kind );
	}

	public function test_detect_kind_review_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'item' => 'https://example.test/gadget' ) )
		);
		$this->assertSame( 'review', $kind );
	}

	public function test_detect_kind_recipe_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'ingredient' => array( '2 eggs', '1 cup flour' ) ) )
		);
		$this->assertSame( 'recipe', $kind );
	}

	public function test_detect_kind_video_takes_precedence_over_photo(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'video' => 'https://example.test/clip.mp4',
					'photo' => 'https://example.test/poster.jpg',
				),
			)
		);
		$this->assertSame( 'video', $kind );
	}

	public function test_detect_kind_audio_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'audio' => 'https://example.test/memo.mp3' ) )
		);
		$this->assertSame( 'audio', $kind );
	}

	// --- explicit_kind ------------------------------------------------------

	public function test_explicit_kind_reads_and_sanitizes_hint(): void {
		$slug = $this->invoke_private(
			'explicit_kind',
			array( array( 'pkiw-kind' => array( 'Jam' ) ) )
		);
		$this->assertSame( 'jam', $slug );
	}

	public function test_explicit_kind_null_when_absent(): void {
		$slug = $this->invoke_private(
			'explicit_kind',
			array( array( 'content' => 'plain note' ) )
		);
		$this->assertNull( $slug );
	}

	// --- typed_paragraph ----------------------------------------------------

	public function test_typed_paragraph_renders_url_as_link_with_mf2_class(): void {
		$markup = $this->invoke_private(
			'typed_paragraph',
			array(
				array( 'favorite-of' => 'https://example.test/post/1' ),
				'favorite-of',
				'u-favorite-of',
				'Favorited',
			)
		);
		$this->assertStringContainsString( '<a class="u-favorite-of" href="https://example.test/post/1">', $markup );
		$this->assertStringContainsString( 'Favorited', $markup );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
	}

	public function test_typed_paragraph_renders_text_as_span(): void {
		$markup = $this->invoke_private(
			'typed_paragraph',
			array(
				array( 'exercise' => '5k run' ),
				'exercise',
				'p-exercise',
				'',
			)
		);
		$this->assertStringContainsString( '<span class="p-exercise">5k run</span>', $markup );
		$this->assertStringNotContainsString( '<a ', $markup );
	}

	public function test_typed_paragraph_empty_when_property_missing(): void {
		$markup = $this->invoke_private(
			'typed_paragraph',
			array( array(), 'favorite-of', 'u-favorite-of', 'Favorited' )
		);
		$this->assertSame( '', $markup );
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
		$this->assertStringContainsString( 'class="wp-block-group pkiw-entry"', $markup );
		$this->assertStringContainsString( 'class="wp-block-group e-content"', $markup );
		$this->assertStringContainsString( '<p>a sunny afternoon</p>', $markup );
	}

	public function test_wrap_h_entry_omits_paragraph_when_body_empty(): void {
		$markup = $this->invoke_private(
			'wrap_h_entry',
			array( '<!-- wp:post-kinds-indieweb/checkin-card /-->', '' )
		);
		$this->assertStringContainsString( 'class="wp-block-group pkiw-entry"', $markup );
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

	// --- pkiw-kind explicit hint --------------------------------------------

	public function test_apply_explicit_hint_overrides_property_inference(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'current jam',
			)
		);

		// listen-of would infer 'listen'; the explicit hint says jam.
		Micropub_Content_Builder::apply(
			array(
				'h'         => 'entry',
				'listen-of' => 'https://example.test/track/9',
				'pkiw-kind' => 'jam',
				'content'   => 'current jam',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'jam' ), $this->kind_slugs( $post_id ) );
	}

	public function test_apply_invalid_hint_falls_back_to_inference(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'listening',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'         => 'entry',
				'listen-of' => 'https://example.test/track/9',
				'pkiw-kind' => 'definitely-not-a-kind',
				'content'   => 'listening',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'listen' ), $this->kind_slugs( $post_id ) );
	}

	public function test_apply_hint_reaches_kinds_with_ambiguous_properties(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'the linked repo mislabels its license',
			)
		);

		// An issue is property-identical to a reply (in-reply-to); only the
		// hint can classify it.
		Micropub_Content_Builder::apply(
			array(
				'h'           => 'entry',
				'in-reply-to' => 'https://example.test/repo',
				'pkiw-kind'   => 'issue',
				'content'     => 'the linked repo mislabels its license',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'issue' ), $this->kind_slugs( $post_id ) );
	}

	public function test_apply_favorite_generates_typed_paragraph_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'placeholder',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'           => 'entry',
				'favorite-of' => 'https://example.test/post/1',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'favorite' ), $this->kind_slugs( $post_id ) );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'u-favorite-of', $content );
		$this->assertStringContainsString( 'https://example.test/post/1', $content );
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
		$this->assertStringContainsString( 'pkiw-entry', $content );
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

	// --- kind term assignment ------------------------------------------------

	/**
	 * Read the post's kind term slugs.
	 *
	 * @param int $post_id
	 * @return string[]
	 */
	private function kind_slugs( int $post_id ): array {
		$slugs = wp_get_post_terms( $post_id, 'kind', array( 'fields' => 'slugs' ) );
		return is_array( $slugs ) ? $slugs : array();
	}

	public function test_detect_term_only_kind_maps_mf2_properties(): void {
		$cases = array(
			'like'     => array( 'like-of' => 'https://example.test/post' ),
			'repost'   => array( 'repost-of' => 'https://example.test/post' ),
			'bookmark' => array( 'bookmark-of' => 'https://example.test/post' ),
			'reply'    => array( 'in-reply-to' => 'https://example.test/post' ),
		);
		foreach ( $cases as $expected => $properties ) {
			$this->assertSame(
				$expected,
				$this->invoke_private( 'detect_term_only_kind', array( $properties ) )
			);
		}
		$this->assertNull(
			$this->invoke_private( 'detect_term_only_kind', array( array( 'content' => 'plain note' ) ) )
		);
	}

	public function test_apply_assigns_detected_kind_term(): void {
		// Micropub inserts the post with no kind terms, so WP core's
		// default_term gives it `note` — apply() must overwrite that.
		// Core only assigns the default when the acting user can assign
		// terms, so run as an author like a real Micropub request.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'coffee stop',
			)
		);
		$this->assertSame( array( 'note' ), $this->kind_slugs( $post_id ) );

		Micropub_Content_Builder::apply(
			array(
				'h'             => 'entry',
				'mp-place-name' => 'Cafe Test',
				'location'      => 'geo:39.93,-77.66',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'checkin' ), $this->kind_slugs( $post_id ) );
	}

	public function test_apply_assigns_term_and_card_for_like_post(): void {
		// Likes now have a card builder: apply() writes the like-card
		// markup (typed body preserved in e-content) AND assigns the term.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Liked this a lot',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'       => 'entry',
				'like-of' => 'https://example.test/great-post',
				'content' => 'Liked this a lot',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'like' ), $this->kind_slugs( $post_id ) );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/like-card', $content );
		$this->assertStringContainsString( 'Liked this a lot', $content );
		$this->assertSame( '1', (string) get_post_meta( $post_id, '_pkiw_block_content_generated', true ) );
	}

	public function test_apply_rsvp_takes_precedence_over_reply(): void {
		// RSVP posts always carry in-reply-to; the rsvp property wins.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'See you there',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'           => 'entry',
				'rsvp'        => 'yes',
				'in-reply-to' => 'https://example.test/event',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'rsvp' ), $this->kind_slugs( $post_id ) );
	}

	public function test_apply_leaves_default_note_for_termless_kind(): void {
		// A kind whose taxonomy term is missing (e.g. a site deleted it)
		// keeps core's `note` default — but still gets its typed content.
		// (follow used to lack a term by default; now that every kind ships
		// a default term, the termless state must be created explicitly.)
		$follow = get_term_by( 'slug', 'follow', 'kind' );
		if ( $follow instanceof \WP_Term ) {
			wp_delete_term( $follow->term_id, 'kind' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'placeholder',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'         => 'entry',
				'follow-of' => 'https://example.test/author',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'note' ), $this->kind_slugs( $post_id ) );
		$this->assertStringContainsString( 'u-follow-of', (string) get_post_field( 'post_content', $post_id ) );
	}

	public function test_apply_does_not_reassign_kind_after_generation(): void {
		// Once the marker is set, a later Micropub update must not clobber
		// a manually-corrected kind (same contract as content idempotency).
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'lunch',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'      => 'entry',
				'eat-of' => 'Tacos',
			),
			array( 'ID' => $post_id )
		);
		$this->assertSame( array( 'eat' ), $this->kind_slugs( $post_id ) );

		// User corrects the kind by hand.
		wp_set_post_terms( $post_id, array( 'drink' ), 'kind' );

		Micropub_Content_Builder::apply(
			array(
				'h'      => 'entry',
				'eat-of' => 'More tacos',
			),
			array( 'ID' => $post_id )
		);

		$this->assertSame( array( 'drink' ), $this->kind_slugs( $post_id ) );
	}

	public function test_apply_gives_a_drink_no_type(): void {
		// Micropub carries no drink type, so the post stores none and its
		// card prints none.
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'a pour',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'h'        => 'entry',
				'drink-of' => 'House pour',
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/drink-card', $content );
		$this->assertStringNotContainsString( 'drinkType', $content );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_drink_type', true ), 'A Micropub drink stores no type.' );

		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( $content );
		$this->assertStringNotContainsString( 'pk-sub', $html, 'A Micropub drink with no type prints no type.' );
		$this->assertStringNotContainsString( 'Coffee', $html );
	}

	// --- Response kinds: like / repost / bookmark / reply --------------------

	public function test_detect_kind_like_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'like-of' => 'https://example.test/liked-post' ) )
		);
		$this->assertSame( 'like', $kind );
	}

	public function test_detect_kind_repost_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'repost-of' => 'https://example.test/reposted' ) )
		);
		$this->assertSame( 'repost', $kind );
	}

	public function test_detect_kind_bookmark_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array( array( 'bookmark-of' => 'https://example.test/saved' ) )
		);
		$this->assertSame( 'bookmark', $kind );
	}

	public function test_detect_kind_reply_recognized(): void {
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'in-reply-to' => 'https://example.test/original',
					'content'     => 'great point!',
				),
			)
		);
		$this->assertSame( 'reply', $kind );
	}

	public function test_detect_kind_rsvp_takes_precedence_over_reply(): void {
		// RSVP posts carry in-reply-to as the event URL — they must stay rsvp.
		$kind = $this->invoke_private(
			'detect_kind',
			array(
				array(
					'in-reply-to' => 'https://example.test/event',
					'rsvp'        => 'yes',
				),
			)
		);
		$this->assertSame( 'rsvp', $kind );
	}

	public function test_like_card_falls_back_to_url_as_title(): void {
		// Contentless URL-only like (what Outpost sends) — the liked URL
		// must surface as the linked title or the card renders bare.
		$markup = $this->invoke_private(
			'like_card',
			array( array( 'like-of' => 'https://example.test/liked-post' ) )
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/like-card', $markup );
		$this->assertStringContainsString( '"title":"https://example.test/liked-post"', $markup );
		$this->assertStringContainsString( '"url":"https://example.test/liked-post"', $markup );
	}

	public function test_like_card_maps_name_author_and_content(): void {
		$markup = $this->invoke_private(
			'like_card',
			array(
				array(
					'like-of' => 'https://example.test/liked-post',
					'name'    => 'A Great Post',
					'author'  => 'Jane Doe',
					'content' => 'loved this',
				),
			)
		);
		$this->assertStringContainsString( '"title":"A Great Post"', $markup );
		$this->assertStringContainsString( '"author":"Jane Doe"', $markup );
		$this->assertStringContainsString( '"description":"loved this"', $markup );
	}

	public function test_repost_card_falls_back_to_url_as_title(): void {
		// Contentless URL-only repost — the reposted URL must surface as
		// the linked title or the card renders bare (same contract as like).
		$markup = $this->invoke_private(
			'repost_card',
			array( array( 'repost-of' => 'https://example.test/reposted' ) )
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/repost-card', $markup );
		$this->assertStringContainsString( '"title":"https://example.test/reposted"', $markup );
		$this->assertStringContainsString( '"url":"https://example.test/reposted"', $markup );
	}

	public function test_repost_card_maps_name_author_and_content(): void {
		$markup = $this->invoke_private(
			'repost_card',
			array(
				array(
					'repost-of' => 'https://example.test/reposted',
					'name'      => 'A Great Post',
					'author'    => 'Jane Doe',
					'content'   => 'sharing this',
				),
			)
		);
		$this->assertStringContainsString( '"title":"A Great Post"', $markup );
		$this->assertStringContainsString( '"author":"Jane Doe"', $markup );
		$this->assertStringContainsString( '"description":"sharing this"', $markup );
	}

	public function test_bookmark_card_uses_name_as_title(): void {
		$markup = $this->invoke_private(
			'bookmark_card',
			array(
				array(
					'bookmark-of' => 'https://example.test/saved',
					'name'        => 'Worth Reading Later',
				),
			)
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/bookmark-card', $markup );
		$this->assertStringContainsString( '"title":"Worth Reading Later"', $markup );
		$this->assertStringContainsString( '"url":"https://example.test/saved"', $markup );
	}

	public function test_bookmark_card_falls_back_to_url_as_title(): void {
		$markup = $this->invoke_private(
			'bookmark_card',
			array( array( 'bookmark-of' => 'https://example.test/saved' ) )
		);
		$this->assertStringContainsString( '"title":"https://example.test/saved"', $markup );
		$this->assertStringContainsString( '"url":"https://example.test/saved"', $markup );
	}

	public function test_reply_card_links_reply_context(): void {
		$markup = $this->invoke_private(
			'reply_card',
			array( array( 'in-reply-to' => 'https://example.test/original' ) )
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/reply-card', $markup );
		$this->assertStringContainsString( '"title":"https://example.test/original"', $markup );
		$this->assertStringContainsString( '"url":"https://example.test/original"', $markup );
	}

	public function test_reply_card_leaves_body_out_of_card(): void {
		// The reply body is the author's own words, not the cited post's
		// content — it belongs in e-content (via wrap_h_entry), never in
		// the h-cite card's description.
		$markup = $this->invoke_private(
			'reply_card',
			array(
				array(
					'in-reply-to' => 'https://example.test/original',
					'content'     => 'Strongly agree with this.',
				),
			)
		);
		$this->assertStringNotContainsString( 'description', $markup );
		$this->assertStringNotContainsString( 'Strongly agree', $markup );
	}

	public function test_fill_empty_content_supplies_like_card(): void {
		// Phantom-post regression (same class as eat/drink/follow/weather in
		// v1.1.0): a contentless like must be non-empty at insert time.
		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			array(
				'properties' => array(
					'like-of' => array( 'https://example.test/liked-post' ),
				),
			)
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/like-card', $content );
	}

	public function test_fill_empty_content_supplies_reply_card(): void {
		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			array(
				'properties' => array(
					'in-reply-to' => array( 'https://example.test/original' ),
				),
			)
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/reply-card', $content );
	}

	// --- comics: read-of with the comics hint (issue 295) --------------------

	/**
	 * A comic read, the shape Outpost sends with the comics hint.
	 *
	 * @return array<string, mixed>
	 */
	private function comic_properties(): array {
		return array(
			'read-of'         => array( 'https://example.test/comics/saga-7' ),
			'name'            => array( 'Saga #7' ),
			'author'          => array( 'Brian K. Vaughan', 'Fiona Staples' ),
			'mp-series'       => array( 'Saga' ),
			'mp-volume'       => array( '2' ),
			'mp-issue-number' => array( '7' ),
			'mp-publisher'    => array( 'Image Comics' ),
			'photo'           => array( 'https://example.test/saga-7.jpg' ),
			'mp-photo-alt'    => array( 'A horned parent holds a swaddled baby.' ),
			'read-status'     => array( 'finished' ),
			'rating'          => array( '4' ),
			'mp-started-at'   => array( '2026-08-30' ),
			'mp-finished-at'  => array( '2026-09-02' ),
			'content'         => array( 'Lying Cat steals every page.' ),
			'pkiw-kind'       => array( 'comics' ),
		);
	}

	/**
	 * Block names in document order, descending into wrapper groups.
	 *
	 * @param string $content Block markup.
	 * @return string[]
	 */
	private function block_names( string $content ): array {
		$names = array();
		$walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
			foreach ( $blocks as $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				if ( 'core/group' !== $block['blockName'] ) {
					$names[] = $block['blockName'];
				}
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( $content ) );
		return $names;
	}

	/**
	 * Attributes of the first block with this name.
	 *
	 * @param string $content Block markup.
	 * @param string $name    Block name.
	 * @return array<string, mixed>|null
	 */
	private function first_attrs( string $content, string $name ): ?array {
		$found = null;
		$walk  = static function ( array $blocks ) use ( &$walk, &$found, $name ): void {
			foreach ( $blocks as $block ) {
				if ( null !== $found ) {
					return;
				}
				if ( $name === $block['blockName'] ) {
					$found = $block['attrs'];
					return;
				}
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( $content ) );
		return $found;
	}

	/** Builds a comic card when a read-of entry carries the comics hint. */
	public function test_comics_hint_on_read_of_builds_a_comic_card(): void {
		$content = (string) $this->invoke_private( 'build_block_content', array( $this->comic_properties() ) );
		$names   = $this->block_names( $content );

		$this->assertSame( 'post-kinds-indieweb/comic-card', $names[0] ?? null );
		$this->assertNotContains( 'post-kinds-indieweb/read-card', $names );
	}

	/** Keeps the read card when a read-of entry has no explicit hint. */
	public function test_read_of_without_the_hint_still_builds_a_read_card(): void {
		$properties = $this->comic_properties();
		unset( $properties['pkiw-kind'] );
		$names = $this->block_names( (string) $this->invoke_private( 'build_block_content', array( $properties ) ) );

		$this->assertSame( 'post-kinds-indieweb/read-card', $names[0] ?? null );
		$this->assertNotContains( 'post-kinds-indieweb/comic-card', $names );
	}

	/** Keeps the read card when a read-of entry carries the read hint. */
	public function test_read_hint_on_read_of_builds_a_read_card(): void {
		$properties              = $this->comic_properties();
		$properties['pkiw-kind'] = array( 'read' );
		$names                   = $this->block_names( (string) $this->invoke_private( 'build_block_content', array( $properties ) ) );

		$this->assertSame( 'post-kinds-indieweb/read-card', $names[0] ?? null );
	}

	/** Builds no comic card when the comics hint appears without read-of. */
	public function test_comics_hint_without_read_of_builds_no_comic_card(): void {
		$names = $this->block_names(
			(string) $this->invoke_private(
				'build_block_content',
				array(
					array(
						'photo'     => array( 'https://example.test/strip.png' ),
						'pkiw-kind' => array( 'comics' ),
						'content'   => array( 'My strip' ),
					),
				)
			)
		);

		$this->assertNotContains( 'post-kinds-indieweb/comic-card', $names );
		$this->assertContains( 'core/image', $names );
	}

	/** Keeps the inferred card when the hint is outside the refinement map. */
	public function test_a_hint_outside_the_refinements_keeps_the_inferred_card(): void {
		$names = $this->block_names(
			(string) $this->invoke_private(
				'build_block_content',
				array(
					array(
						'listen-of' => array( 'https://example.test/track/9' ),
						'pkiw-kind' => array( 'jam' ),
					),
				)
			)
		);

		$this->assertSame( array( 'post-kinds-indieweb/listen-card' ), $names );
	}

	/** Maps every supplied comic property to its comic-card attribute. */
	public function test_comic_card_maps_every_field_it_is_sent(): void {
		$attrs = $this->first_attrs(
			(string) $this->invoke_private( 'comic_card', array( $this->comic_properties() ) ),
			'post-kinds-indieweb/comic-card'
		);

		$this->assertSame(
			array(
				'sourceUrl'     => 'https://example.test/comics/saga-7',
				'title'         => 'Saga #7',
				'creators'      => 'Brian K. Vaughan, Fiona Staples',
				'series'        => 'Saga',
				'volume'        => '2',
				'issueNumber'   => '7',
				'publisher'     => 'Image Comics',
				'coverImage'    => 'https://example.test/saga-7.jpg',
				'coverImageAlt' => 'A horned parent holds a swaddled baby.',
				'readStatus'    => 'finished',
				'rating'        => 4,
				'startedAt'     => '2026-08-30',
				'finishedAt'    => '2026-09-02',
				'review'        => 'Lying Cat steals every page.',
			),
			$attrs
		);
	}

	/** Omits every copy of the cover URL from photos appended below the card. */
	public function test_a_comic_cover_photo_is_not_repeated_below_the_card(): void {
		$properties                 = $this->comic_properties();
		$properties['photo']        = array( 'https://example.test/saga-7.jpg', 'https://example.test/saga-7.jpg', 'https://example.test/panel.jpg' );
		$properties['mp-photo-alt'] = array( 'Cover alt', '', 'Panel alt' );
		$content                    = (string) $this->invoke_private( 'build_block_content', array( $properties ) );

		$this->assertSame( 'Cover alt', $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' )['coverImageAlt'] ?? null );
		$this->assertSame( 1, substr_count( $content, '<!-- wp:image' ), 'only the panel photo follows the card' );
		$this->assertStringContainsString( 'https://example.test/panel.jpg', $content );
		$this->assertSame( 1, substr_count( $content, 'https://example.test/saga-7.jpg' ), 'the cover appears once, on the card' );
	}

	/** Creates a comics post with comic-card markup and comic metadata. */
	public function test_apply_creates_a_comics_post_with_a_comic_card_and_comic_meta(): void {
		( new Meta_Fields() )->register_meta_fields();
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Lying Cat steals every page.',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => $this->comic_properties(),
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertSame( array( 'comics' ), $this->kind_slugs( $post_id ) );
		$this->assertSame( 'comics', Taxonomy::get_first_block_kind( $content ) );

		$expected = array(
			'comic_title'       => 'Saga #7',
			'comic_creators'    => 'Brian K. Vaughan, Fiona Staples',
			'comic_series'      => 'Saga',
			'comic_volume'      => '2',
			'comic_issue'       => '7',
			'comic_publisher'   => 'Image Comics',
			'comic_cover'       => 'https://example.test/saga-7.jpg',
			'comic_cover_alt'   => 'A horned parent holds a swaddled baby.',
			'comic_url'         => 'https://example.test/comics/saga-7',
			'comic_status'      => 'finished',
			'comic_rating'      => '4',
			'comic_started_at'  => '2026-08-30',
			'comic_finished_at' => '2026-09-02',
			'comic_review'      => 'Lying Cat steals every page.',
		);
		foreach ( $expected as $suffix => $value ) {
			$this->assertSame( $value, (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true ), "Meta {$suffix}." );
		}
		$this->assertSame( '', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'read_title', true ) );

		// The entry parses with read-of, as an editor-made comic read does.
		$parsed = \Mf2\parse( '<div class="h-entry">' . do_blocks( $content ) . '</div>' );
		$entry  = null;
		foreach ( $parsed['items'] as $item ) {
			if ( in_array( 'h-entry', $item['type'], true ) ) {
				$entry = $item;
				break;
			}
		}
		$this->assertNotNull( $entry );
		$this->assertArrayHasKey( 'read-of', $entry['properties'] );
	}

	/** Creates a read post from the same request when the hint is absent. */
	public function test_apply_without_the_hint_creates_a_read_post(): void {
		$properties = $this->comic_properties();
		unset( $properties['pkiw-kind'] );
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Lying Cat steals every page.',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => $properties,
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertSame( array( 'read' ), $this->kind_slugs( $post_id ) );
		$this->assertSame( 'read', Taxonomy::get_first_block_kind( $content ) );
		$this->assertSame( '', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_title', true ) );
	}

	/**
	 * Create an image attachment and return its uploads URL, the URL
	 * Micropub's sideload appends to `photo`.
	 *
	 * @param string $file File name under the uploads folder.
	 * @return string
	 */
	private function local_upload_url( string $file ): string {
		$id  = self::factory()->attachment->create_object( '2026/10/' . $file, 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$url = (string) wp_get_attachment_url( $id );
		$this->assertSame( $id, attachment_url_to_postid( $url ), "{$file} resolves to its attachment" );
		return $url;
	}

	/**
	 * Source and alt of every core/image block, in document order.
	 *
	 * @param string $content Block markup.
	 * @return array<int, array{src: string, alt: string}>
	 */
	private function images( string $content ): array {
		$images = array();
		$walk   = static function ( array $blocks ) use ( &$walk, &$images ): void {
			foreach ( $blocks as $block ) {
				if ( 'core/image' === $block['blockName'] ) {
					preg_match( '/src="([^"]*)"/', $block['innerHTML'], $src );
					preg_match( '/alt="([^"]*)"/', $block['innerHTML'], $alt );
					$images[] = array(
						'src' => html_entity_decode( $src[1] ?? '' ),
						'alt' => html_entity_decode( $alt[1] ?? '' ),
					);
				}
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( $content ) );
		return $images;
	}

	/** Uses the sideloaded copy of a remote cover and repeats neither URL below the card. */
	public function test_a_sideloaded_cover_is_the_local_copy_and_no_image_repeats_it(): void {
		$local      = $this->local_upload_url( 'saga-7-cover.jpg' );
		$properties = $this->comic_properties();
		// Micropub appends the sideloaded copy after the remote original.
		$properties['photo'] = array( 'https://example.test/saga-7.jpg', $local );
		$content             = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs               = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );

		$this->assertSame( $local, $attrs['coverImage'] ?? null );
		$this->assertSame( 'A horned parent holds a swaddled baby.', $attrs['coverImageAlt'] ?? null );
		$this->assertSame( array(), $this->images( $content ), 'neither copy of the cover follows the card' );
		$this->assertStringNotContainsString( 'https://example.test/saga-7.jpg', $content );
	}

	/** Pairs each sideloaded copy with its original, so the panel keeps its alt and the cover stays on the card. */
	public function test_a_sideloaded_cover_and_panel_keep_their_alts(): void {
		$cover                      = $this->local_upload_url( 'saga-7-cover.jpg' );
		$panel                      = $this->local_upload_url( 'saga-7-panel.jpg' );
		$properties                 = $this->comic_properties();
		$properties['photo']        = array( 'https://example.test/saga-7.jpg', 'https://example.test/panel.jpg', $cover, $panel );
		$properties['mp-photo-alt'] = array( 'Cover alt', 'Panel alt' );
		$content                    = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs                      = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );
		$images                     = $this->images( $content );

		$this->assertSame( $cover, $attrs['coverImage'] ?? null );
		$this->assertSame( 'Cover alt', $attrs['coverImageAlt'] ?? null );
		$this->assertContains( $panel, array_column( $images, 'src' ) );
		foreach ( $images as $image ) {
			$this->assertNotContains( $image['src'], array( $cover, 'https://example.test/saga-7.jpg' ), 'no image repeats the cover' );
			$this->assertSame( 'Panel alt', $image['alt'], "{$image['src']} keeps the panel's alt" );
		}
	}

	/** Keeps two different local photos apart: the first is the cover, the second follows the card. */
	public function test_two_local_photos_are_a_cover_and_a_panel(): void {
		$cover                      = $this->local_upload_url( 'cover-a.jpg' );
		$panel                      = $this->local_upload_url( 'panel-b.jpg' );
		$properties                 = $this->comic_properties();
		$properties['photo']        = array( $cover, $panel );
		$properties['mp-photo-alt'] = array( 'Cover alt', 'Panel alt' );
		$content                    = (string) $this->invoke_private( 'build_block_content', array( $properties ) );

		$this->assertSame( $cover, $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' )['coverImage'] ?? null );
		$this->assertSame(
			array(
				array(
					'src' => $panel,
					'alt' => 'Panel alt',
				),
			),
			$this->images( $content )
		);
	}

	/** Takes the cover from mp-cover-image and still renders a different photo below the card. */
	public function test_mp_cover_image_is_the_cover_and_the_photo_still_renders(): void {
		$properties                       = $this->comic_properties();
		$properties['mp-cover-image']     = array( 'https://example.test/covers/saga-7-variant.jpg' );
		$properties['mp-cover-image-alt'] = array( 'Variant cover alt' );
		$properties['photo']              = array( 'https://example.test/panel.jpg' );
		$properties['mp-photo-alt']       = array( 'Panel alt' );
		$content                          = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs                            = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );

		$this->assertSame( 'https://example.test/covers/saga-7-variant.jpg', $attrs['coverImage'] ?? null );
		$this->assertSame( 'Variant cover alt', $attrs['coverImageAlt'] ?? null );
		$this->assertSame(
			array(
				array(
					'src' => 'https://example.test/panel.jpg',
					'alt' => 'Panel alt',
				),
			),
			$this->images( $content )
		);
	}

	/** Reads a JSON photo object's value as the cover and its alt as the cover alt. */
	public function test_a_json_photo_object_supplies_the_cover_and_its_alt(): void {
		$properties = $this->comic_properties();
		unset( $properties['mp-photo-alt'] );
		$properties['photo'] = array(
			array(
				'value' => 'https://example.test/saga-7.jpg',
				'alt'   => 'Object alt',
			),
		);
		$content             = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs               = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );

		$this->assertSame( 'https://example.test/saga-7.jpg', $attrs['coverImage'] ?? null );
		$this->assertSame( 'Object alt', $attrs['coverImageAlt'] ?? null );
		$this->assertSame( array(), $this->images( $content ) );
	}

	/** Pairs a sideloaded copy with the JSON photo object it came from. */
	public function test_a_sideloaded_json_photo_object_is_the_local_copy(): void {
		$local      = $this->local_upload_url( 'saga-7-object.jpg' );
		$properties = $this->comic_properties();
		unset( $properties['mp-photo-alt'] );
		$properties['photo'] = array(
			array(
				'value' => 'https://example.test/saga-7.jpg',
				'alt'   => 'Object alt',
			),
			$local,
		);
		$content             = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs               = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );

		$this->assertSame( $local, $attrs['coverImage'] ?? null );
		$this->assertSame( 'Object alt', $attrs['coverImageAlt'] ?? null );
		$this->assertSame( array(), $this->images( $content ) );
	}

	/** Pairs a local photo sent under another scheme with its copy, since Micropub reuses the attachment it names. */
	public function test_a_local_photo_sent_under_another_scheme_is_one_cover_with_its_copy(): void {
		$local = $this->local_upload_url( 'saga-7-scheme.jpg' );
		$sent  = str_replace( 'http://', 'https://', $local );
		$this->assertSame( attachment_url_to_postid( $local ), attachment_url_to_postid( $sent ), 'both URLs name one attachment' );
		$properties          = $this->comic_properties();
		$properties['photo'] = array( $sent, $local );
		$content             = (string) $this->invoke_private( 'build_block_content', array( $properties ) );

		$this->assertSame( $local, $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' )['coverImage'] ?? null );
		$this->assertSame( array(), $this->images( $content ) );
	}

	/** Skips an empty photo value: the next photo is the cover and the rest still follow the card. */
	public function test_a_leading_empty_photo_value_is_skipped(): void {
		$properties                 = $this->comic_properties();
		$properties['photo']        = array( '', 'https://example.test/saga-7.jpg', 'https://example.test/panel.jpg' );
		$properties['mp-photo-alt'] = array( '', 'Cover alt', 'Panel alt' );
		$content                    = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs                      = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );

		$this->assertSame( 'https://example.test/saga-7.jpg', $attrs['coverImage'] ?? null );
		$this->assertSame( 'Cover alt', $attrs['coverImageAlt'] ?? null );
		$this->assertSame(
			array(
				array(
					'src' => 'https://example.test/panel.jpg',
					'alt' => 'Panel alt',
				),
			),
			$this->images( $content )
		);
	}

	/** Reads a photo sent as one string, not a list. */
	public function test_a_scalar_photo_is_the_cover(): void {
		$properties          = $this->comic_properties();
		$properties['photo'] = 'https://example.test/saga-7.jpg';
		$content             = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs               = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );

		$this->assertSame( 'https://example.test/saga-7.jpg', $attrs['coverImage'] ?? null );
		$this->assertSame( 'A horned parent holds a swaddled baby.', $attrs['coverImageAlt'] ?? null );
		$this->assertSame( array(), $this->images( $content ) );
	}

	/** Reads a JSON photo object sent without the surrounding list. */
	public function test_a_bare_json_photo_object_is_the_cover(): void {
		$properties = $this->comic_properties();
		unset( $properties['mp-photo-alt'] );
		$properties['photo'] = array(
			'value' => 'https://example.test/saga-7.jpg',
			'alt'   => 'Object alt',
		);
		$content             = (string) $this->invoke_private( 'build_block_content', array( $properties ) );
		$attrs               = $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' );

		$this->assertSame( 'https://example.test/saga-7.jpg', $attrs['coverImage'] ?? null );
		$this->assertSame( 'Object alt', $attrs['coverImageAlt'] ?? null );
		$this->assertSame( array(), $this->images( $content ) );
	}

	/** Keeps mp-cover-image as sent and repeats neither its photo copy nor the sideloaded one. */
	public function test_mp_cover_image_also_sent_as_a_sideloaded_photo_is_not_repeated(): void {
		$local                        = $this->local_upload_url( 'saga-7-variant.jpg' );
		$properties                   = $this->comic_properties();
		$properties['mp-cover-image'] = array( 'https://example.test/saga-7.jpg' );
		$properties['photo']          = array( 'https://example.test/saga-7.jpg', $local );
		$content                      = (string) $this->invoke_private( 'build_block_content', array( $properties ) );

		$this->assertSame( 'https://example.test/saga-7.jpg', $this->first_attrs( $content, 'post-kinds-indieweb/comic-card' )['coverImage'] ?? null );
		$this->assertSame( array(), $this->images( $content ) );
	}

	/** Saves the sideloaded copy as the comic's cover meta when apply() gets the enriched photo list. */
	public function test_apply_saves_the_local_copy_as_the_comic_cover(): void {
		( new Meta_Fields() )->register_meta_fields();
		$local      = $this->local_upload_url( 'saga-7-apply.jpg' );
		$properties = $this->comic_properties();
		// The shape after_micropub receives: the sent URL, then Micropub's local copy.
		$properties['photo'] = array( 'https://example.test/saga-7.jpg', $local );
		$post_id             = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Lying Cat steals every page.',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => $properties,
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertSame( array( 'comics' ), $this->kind_slugs( $post_id ) );
		$this->assertSame( $local, (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_cover', true ) );
		$this->assertSame( 'A horned parent holds a swaddled baby.', (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'comic_cover_alt', true ) );
		$this->assertSame( array(), $this->images( $content ) );
	}

	/** Builds a comic card before insert when the comic request has no content. */
	public function test_fill_empty_content_supplies_a_comic_card(): void {
		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			array(
				'properties' => array(
					'read-of'   => array( 'https://example.test/comics/saga-7' ),
					'name'      => array( 'Saga #7' ),
					'pkiw-kind' => array( 'comics' ),
				),
			)
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/comic-card', $content );
		$this->assertStringNotContainsString( 'wp:post-kinds-indieweb/read-card', $content );
	}

	/** Falls back to a read post when the site has no comics term. */
	public function test_apply_builds_a_read_post_when_the_site_has_no_comics_term(): void {
		$comics = get_term_by( 'slug', 'comics', 'kind' );
		$this->assertInstanceOf( \WP_Term::class, $comics );
		wp_delete_term( $comics->term_id, 'kind' );
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_content' => 'Lying Cat steals every page.',
			)
		);

		Micropub_Content_Builder::apply(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => $this->comic_properties(),
			),
			array( 'ID' => $post_id )
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertSame( array( 'read' ), $this->kind_slugs( $post_id ) );
		$this->assertSame( 'read', Taxonomy::get_first_block_kind( $content ) );
		$this->assertStringNotContainsString( 'wp:post-kinds-indieweb/comic-card', $content );
	}

	// --- Wire matrix: every kind's Micropub properties -> card attrs --------

	/**
	 * Wire matrix: every Micropub property the builder maps, asserted onto
	 * its card attribute — and every card attribute it can never fill,
	 * declared as a known gap so silence is impossible.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: string, 2: array<string,mixed>, 3: string[]}>
	 *         [ properties, expected block, attr => expected value, known-unfillable attrs ]
	 */
	public function wire_matrix(): array {
		return array(
			'checkin' => array(
				array(
					'mp-place-name' => array( 'Sample venue' ),
					'location'      => array( 'geo:40.0379,-76.3055' ),
					'content'       => array( 'Sample body' ),
				),
				'post-kinds-indieweb/checkin-card',
				array(
					'venueName' => 'Sample venue',
					'note'      => 'Sample body',
					'latitude'  => 40.0379,
					'longitude' => -76.3055,
				),
				array( 'venueType', 'address', 'locality', 'region', 'country', 'postalCode', 'locationPrivacy', 'osmId', 'venueUrl', 'foursquareId', 'checkinAt', 'photo', 'photoAlt', 'showMap' ),
			),
			'eat'     => array(
				array(
					'eat-of'        => array( 'Sample dish' ),
					'content'       => array( 'Sample body' ),
					'rating'        => array( '4' ),
					'mp-place-name' => array( 'Sample venue' ),
					'location'      => array( 'geo:40.0379,-76.3055' ),
				),
				'post-kinds-indieweb/eat-card',
				array(
					'name'         => 'Sample dish',
					'notes'        => 'Sample body',
					'rating'       => 4,
					'locationName' => 'Sample venue',
					'geoLatitude'  => 40.0379,
					'geoLongitude' => -76.3055,
				),
				array( 'restaurant', 'cuisine', 'photo', 'photoAlt', 'ateAt', 'restaurantUrl', 'locationAddress', 'locationLocality', 'locationRegion', 'locationCountry' ),
			),
			'drink'   => array(
				array(
					'drink-of'      => array( 'Sample drink' ),
					'content'       => array( 'Sample body' ),
					'rating'        => array( '4' ),
					'mp-place-name' => array( 'Sample venue' ),
					'location'      => array( 'geo:40.0379,-76.3055' ),
				),
				'post-kinds-indieweb/drink-card',
				array(
					'name'         => 'Sample drink',
					'notes'        => 'Sample body',
					'rating'       => 4,
					'locationName' => 'Sample venue',
					'geoLatitude'  => 40.0379,
					'geoLongitude' => -76.3055,
				),
				array( 'drinkType', 'brand', 'photo', 'photoAlt', 'drankAt', 'venueUrl', 'locationAddress', 'locationLocality', 'locationRegion', 'locationCountry' ),
			),
			'listen'  => array(
				array(
					'listen-of' => array( 'https://example.test/track/123' ),
					'name'      => array( 'Sample track' ),
					'author'    => array( 'Sample artist' ),
					'rating'    => array( '4' ),
				),
				'post-kinds-indieweb/listen-card',
				array(
					'listenUrl'  => 'https://example.test/track/123',
					'trackTitle' => 'Sample track',
					'artistName' => 'Sample artist',
					'rating'     => 4,
				),
				array( 'albumTitle', 'releaseDate', 'coverImage', 'coverImageAlt', 'musicbrainzId', 'listenedAt' ),
			),
			'watch'   => array(
				array(
					'watch-of' => array( 'https://example.test/movie' ),
					'name'     => array( 'Sample film' ),
					'author'   => array( 'Sample director' ),
					'rating'   => array( '4' ),
					'content'  => array( 'Sample review' ),
				),
				'post-kinds-indieweb/watch-card',
				array(
					'watchUrl'   => 'https://example.test/movie',
					'mediaTitle' => 'Sample film',
					'director'   => 'Sample director',
					'rating'     => 4,
					'review'     => 'Sample review',
				),
				array( 'mediaType', 'showTitle', 'seasonNumber', 'episodeNumber', 'episodeTitle', 'releaseYear', 'posterImage', 'posterImageAlt', 'tmdbId', 'imdbId', 'isRewatch', 'watchedAt', 'captionsUrl' ),
			),
			'read'    => array(
				array(
					'read-of'     => array( 'https://openlibrary.org/works/OL45883W' ),
					'name'        => array( 'Sample book' ),
					'author'      => array( 'Sample author' ),
					'read-status' => array( 'reading' ),
					'rating'      => array( '4' ),
					'content'     => array( 'Sample review' ),
				),
				'post-kinds-indieweb/read-card',
				array(
					'bookUrl'    => 'https://openlibrary.org/works/OL45883W',
					'bookTitle'  => 'Sample book',
					'authorName' => 'Sample author',
					'readStatus' => 'reading',
					'rating'     => 4,
					'review'     => 'Sample review',
				),
				array( 'isbn', 'publisher', 'publishDate', 'pageCount', 'currentPage', 'coverImage', 'coverImageAlt', 'openlibraryId', 'startedAt', 'finishedAt' ),
			),
			'comics'  => array(
				array(
					'read-of'         => array( 'https://example.test/comics/saga-7' ),
					'name'            => array( 'Sample comic' ),
					'author'          => array( 'Sample creator' ),
					'mp-series'       => array( 'Sample series' ),
					'mp-volume'       => array( '2' ),
					'mp-issue-number' => array( '7' ),
					'mp-publisher'    => array( 'Sample publisher' ),
					'photo'           => array( 'https://example.test/cover.jpg' ),
					'mp-photo-alt'    => array( 'Sample cover alt' ),
					'read-status'     => array( 'reading' ),
					'rating'          => array( '4' ),
					'mp-started-at'   => array( '2026-07-04' ),
					'mp-finished-at'  => array( '2026-07-05' ),
					'content'         => array( 'Sample review' ),
					'pkiw-kind'       => array( 'comics' ),
				),
				'post-kinds-indieweb/comic-card',
				array(
					'sourceUrl'     => 'https://example.test/comics/saga-7',
					'title'         => 'Sample comic',
					'creators'      => 'Sample creator',
					'series'        => 'Sample series',
					'volume'        => '2',
					'issueNumber'   => '7',
					'publisher'     => 'Sample publisher',
					'coverImage'    => 'https://example.test/cover.jpg',
					'coverImageAlt' => 'Sample cover alt',
					'readStatus'    => 'reading',
					'rating'        => 4,
					'startedAt'     => '2026-07-04',
					'finishedAt'    => '2026-07-05',
					'review'        => 'Sample review',
				),
				array(),
			),
			'play'    => array(
				array(
					'play-of' => array( 'https://example.test/game' ),
					'name'    => array( 'Sample game' ),
					'rating'  => array( '4' ),
				),
				'post-kinds-indieweb/play-card',
				array(
					'gameUrl' => 'https://example.test/game',
					'title'   => 'Sample game',
					'rating'  => 4,
				),
				array( 'platform', 'cover', 'coverAlt', 'status', 'hoursPlayed', 'playedAt', 'review', 'bggId', 'rawgId', 'steamId', 'officialUrl', 'purchaseUrl' ),
			),
			'rsvp'    => array(
				array(
					'in-reply-to' => array( 'https://example.test/event' ),
					'rsvp'        => array( 'yes' ),
					'content'     => array( 'Sample note' ),
				),
				'post-kinds-indieweb/rsvp-card',
				array(
					'eventUrl'   => 'https://example.test/event',
					'rsvpStatus' => 'yes',
					'rsvpNote'   => 'Sample note',
				),
				array( 'eventName', 'eventStart', 'eventEnd', 'eventLocation', 'locationVisibility', 'eventDescription', 'rsvpAt', 'eventImage', 'eventImageAlt', 'rel' ),
			),
			'like'    => array(
				array(
					'like-of' => array( 'https://example.test/liked-post' ),
					'name'    => array( 'Sample title' ),
					'author'  => array( 'Sample author' ),
					'content' => array( 'Sample body' ),
				),
				'post-kinds-indieweb/like-card',
				array(
					'title'       => 'Sample title',
					'url'         => 'https://example.test/liked-post',
					'author'      => 'Sample author',
					'description' => 'Sample body',
				),
				array( 'image', 'imageAlt', 'likedAt', 'rel' ),
			),
			'repost'  => array(
				array(
					'repost-of' => array( 'https://example.test/reposted' ),
					'name'      => array( 'Sample title' ),
					'author'    => array( 'Sample author' ),
					'content'   => array( 'Sample body' ),
				),
				'post-kinds-indieweb/repost-card',
				array(
					'title'       => 'Sample title',
					'url'         => 'https://example.test/reposted',
					'author'      => 'Sample author',
					'description' => 'Sample body',
				),
				array( 'image', 'imageAlt', 'repostedAt', 'rel' ),
			),
			'bookmark' => array(
				array(
					'bookmark-of' => array( 'https://example.test/saved' ),
					'name'        => array( 'Sample title' ),
					'author'      => array( 'Sample author' ),
					'content'     => array( 'Sample body' ),
				),
				'post-kinds-indieweb/bookmark-card',
				array(
					'title'       => 'Sample title',
					'url'         => 'https://example.test/saved',
					'author'      => 'Sample author',
					'description' => 'Sample body',
				),
				array( 'image', 'imageAlt', 'bookmarkedAt', 'rel' ),
			),
			'reply'   => array(
				array(
					'in-reply-to' => array( 'https://example.test/original' ),
					'name'        => array( 'Sample title' ),
					'author'      => array( 'Sample author' ),
					'content'     => array( 'Sample reply body' ),
				),
				'post-kinds-indieweb/reply-card',
				array(
					'title'  => 'Sample title',
					'url'    => 'https://example.test/original',
					'author' => 'Sample author',
				),
				array( 'description', 'image', 'imageAlt', 'repliedAt', 'rel' ),
			),
			'mood'    => array(
				array(
					'mood'    => array( 'focused' ),
					'content' => array( 'Sample note' ),
				),
				'post-kinds-indieweb/mood-card',
				array(
					'mood' => 'focused',
					'note' => 'Sample note',
				),
				// moodKey is never set from Micropub: incoming mood text stays authored.
				array( 'emoji', 'intensity', 'moodAt', 'moodKey' ),
			),
		);
	}

	/**
	 * @dataProvider wire_matrix
	 *
	 * @param array<string,mixed>  $properties  h-entry properties bag.
	 * @param string               $block       Expected card block name.
	 * @param array<string,mixed>  $expected_attrs Attr => expected value.
	 * @param string[]             $known_gaps  Attributes the builder can never fill today.
	 */
	public function test_wire_matrix( array $properties, string $block, array $expected_attrs, array $known_gaps ): void {
		$content = $this->invoke_private( 'build_block_content', array( $properties ) );
		$this->assertIsString( $content, "$block: builder returned null for a recognized kind" );

		$blocks = parse_blocks( $content );
		$card   = null;
		foreach ( $blocks as $b ) {
			if ( $b['blockName'] === $block ) {
				$card = $b;
				break;
			}
			// Card blocks land inside the h-entry group wrapper.
			foreach ( $b['innerBlocks'] ?? array() as $inner ) {
				if ( $inner['blockName'] === $block ) {
					$card = $inner;
					break 2;
				}
			}
		}
		$this->assertNotNull( $card, "$block not emitted" );

		foreach ( $expected_attrs as $attr => $value ) {
			$this->assertSame( $value, $card['attrs'][ $attr ] ?? null, "wire -> $attr" );
		}

		// The ledger must be TRUE: a gap attr that suddenly gets a value means
		// a mapping was added — update the ledger and the docs, don't ignore it.
		foreach ( $known_gaps as $gap ) {
			$this->assertArrayNotHasKey( $gap, $card['attrs'], "$gap is mapped now — remove it from the gap ledger and docs/micropub-field-gaps.md" );
		}

		// Completeness: mapped + gaps + universal (layout) = the block's full attr set.
		$matrix  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/field-matrix.json' ), true );
		$all     = array_keys( $matrix[ $block ]['attributes'] );
		$covered = array_merge( array_keys( $expected_attrs ), $known_gaps, array( 'layout' ) );
		$this->assertSame( array(), array_diff( $all, $covered ), "$block has attrs neither asserted nor declared as gaps" );
	}
}

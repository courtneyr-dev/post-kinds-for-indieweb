<?php
/**
 * The mood facts reader and its intensity rule (issue 248).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Micropub_Content_Builder;
use PKIW\Mood_Vocabulary;

/**
 * A theme reads a mood post through Kind_Facts::get() and gets exactly the
 * keys the contract lists: label, key, has_label, name, family,
 * family_label, family_code, intensity, intensity_text and day. Label and
 * key come from the first mood card, then meta. Intensity comes from the
 * saved rating (1-5), then an intensity attribute the card actually
 * serializes, and never from the block.json default of 3. No note, emoji,
 * time or location is among the facts, and a post a visitor can't read has
 * none.
 *
 * @group integration
 */
final class MoodFactsTest extends WP_UnitTestCase {

	private const KEYS = [ 'label', 'key', 'has_label', 'name', 'family', 'family_label', 'family_code', 'intensity', 'intensity_text', 'day' ];

	public function set_up(): void {
		parent::set_up();
		delete_option( 'pkiw_settings' );
		( new ReflectionProperty( Mood_Vocabulary::class, 'cache' ) )->setValue( null, [] );
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		Mood_Vocabulary::register();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A mood-card block comment.
	 *
	 * @param array<string, mixed> $attrs Attributes to serialize.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/mood-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) . ' /-->';
	}

	/**
	 * A mood post.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $meta    Meta key suffix => value (`_pkiw_<suffix>`).
	 * @param array<string, mixed> $post    Extra post fields.
	 */
	private function mood( string $content, array $meta = [], array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'A mood',
					'post_content' => $content,
					'post_date'    => '2026-09-03 10:00:00',
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'mood', 'kind' );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, Meta_Fields::PREFIX . $suffix, $value );
		}

		return $id;
	}

	/**
	 * Facts through the one door a theme uses.
	 *
	 * @return array<string, mixed>
	 */
	private function facts( int $id ): array {
		clean_post_cache( $id );

		return \PKIW\kind_facts( $id );
	}

	// The keys.

	public function test_the_facts_are_exactly_the_contract_keys(): void {
		$id    = $this->mood( $this->card( [ 'mood' => 'Happy', 'moodKey' => 'happy', 'emoji' => '😊', 'note' => 'A private-ish note', 'moodAt' => '2026-09-03T09:00', 'intensity' => 4 ] ) );
		$facts = $this->facts( $id );

		$this->assertSame( self::KEYS, array_keys( $facts ) );
		$this->assertSame(
			[
				'label'          => 'Happy',
				'key'            => 'happy',
				'has_label'      => true,
				'name'           => 'Happy',
				'family'         => 'joy',
				'family_label'   => 'Joy',
				'family_code'    => 'J',
				'intensity'      => 4,
				'intensity_text' => 'Intensity 4 of 5',
				'day'            => '2026-09-03',
			],
			$facts
		);
		$encoded = (string) wp_json_encode( $facts );
		foreach ( [ 'private-ish', '😊', '09:00', 'emoji', 'note' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $encoded, $leak );
		}
	}

	public function test_the_reader_is_registered_for_the_mood_kind(): void {
		$entry = \PKIW\Kind_Facts::reader( 'mood' );

		$this->assertNotNull( $entry );
		$this->assertSame( [ Mood_Vocabulary::class, 'facts' ], $entry['reader'] );
		$this->assertSame( [], $entry['location'], 'A mood reader names no location facts.' );
	}

	public function test_a_post_with_no_label_gets_the_synthetic_name_and_other(): void {
		$id    = $this->mood( $this->card( [ 'emoji' => '😎' ] ) );
		$facts = $this->facts( $id );

		$this->assertSame( '', $facts['label'] );
		$this->assertFalse( $facts['has_label'] );
		$this->assertSame( 'Mood', $facts['name'] );
		$this->assertSame( 'other', $facts['family'] );
		$this->assertSame( 'O', $facts['family_code'] );
		$this->assertSame( self::KEYS, array_keys( $facts ) );
	}

	public function test_family_label_equals_the_vocabulary_row(): void {
		$id    = $this->mood( $this->card( [ 'mood' => 'Tired' ] ) );
		$facts = $this->facts( $id );

		$this->assertSame( 'physical', $facts['family'] );
		$this->assertSame( Mood_Vocabulary::families()[ $facts['family'] ]['label'], $facts['family_label'] );
		$this->assertSame( Mood_Vocabulary::families()[ $facts['family'] ]['code'], $facts['family_code'] );
	}

	// Intensity.

	public function test_the_micropub_built_card_with_no_rating_has_no_intensity(): void {
		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			[
				'properties' => [
					'mood'    => [ 'focused' ],
					'content' => [ 'Sample note' ],
				],
			]
		);
		$this->assertStringContainsString( 'wp:post-kinds-indieweb/mood-card', $content );
		$id    = $this->mood( $content, [ 'mood_rating' => 0 ] );
		$facts = $this->facts( $id );

		$this->assertNull( $facts['intensity'] );
		$this->assertSame( '', $facts['intensity_text'] );
		$this->assertSame( 'focused', $facts['label'] );
		$this->assertSame( 'other', $facts['family'] );
	}

	public function test_meta_wins_over_a_serialized_attribute(): void {
		$id = $this->mood( $this->card( [ 'mood' => 'Happy', 'intensity' => 2 ] ), [ 'mood_rating' => 4 ] );

		$this->assertSame( 4, $this->facts( $id )['intensity'] );
		$this->assertSame( 4, Mood_Vocabulary::intensity( $id ) );
	}

	public function test_a_serialized_attribute_counts_when_meta_is_zero(): void {
		$id = $this->mood( $this->card( [ 'mood' => 'Happy', 'intensity' => 2 ] ), [ 'mood_rating' => 0 ] );

		$this->assertSame( 2, $this->facts( $id )['intensity'] );
	}

	public function test_no_rating_row_and_no_attribute_is_null(): void {
		$none = $this->mood( $this->card( [ 'mood' => 'Happy' ] ) );
		$zero = $this->mood( $this->card( [ 'mood' => 'Happy' ] ), [ 'mood_rating' => 0 ] );

		$this->assertNull( $this->facts( $none )['intensity'], 'No meta row at all.' );
		$this->assertNull( $this->facts( $zero )['intensity'], 'A zero row.' );
		$this->assertNull( Mood_Vocabulary::intensity( $none ) );
	}

	public function test_the_block_default_of_three_is_never_read(): void {
		// The block.json default is 3, and a comment that omits it means nobody picked one.
		$id = $this->mood( $this->card( [ 'mood' => 'Happy' ] ) );

		$this->assertNull( $this->facts( $id )['intensity'] );
	}

	public function test_an_attribute_outside_one_to_five_is_ignored(): void {
		foreach ( [ 7, 0, -2, '4', null ] as $bad ) {
			$id = $this->mood( $this->card( [ 'mood' => 'Happy', 'intensity' => $bad ] ) );
			$this->assertNull( $this->facts( $id )['intensity'], (string) wp_json_encode( $bad ) );
		}
	}

	public function test_a_raw_meta_row_of_three_with_no_attribute_is_three(): void {
		// post 38084's shape: the editor once wrote 3 to meta for an intensity nobody picked.
		$id = $this->mood( $this->card( [ 'emoji' => '😎' ] ), [ 'mood_rating' => '3' ] );

		$this->assertSame( 3, $this->facts( $id )['intensity'] );
	}

	public function test_a_card_nested_in_a_group_is_found(): void {
		$inner   = $this->card( [ 'mood' => 'Calm', 'moodKey' => 'calm', 'intensity' => 5 ] );
		$content = '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';
		$facts   = $this->facts( $this->mood( $content ) );

		$this->assertSame( 'Calm', $facts['label'] );
		$this->assertSame( 5, $facts['intensity'] );
	}

	// Label.

	public function test_the_card_label_wins_over_meta_and_a_meta_only_post_reads_meta(): void {
		$card = $this->mood( $this->card( [ 'mood' => 'Sad' ] ), [ 'mood_label' => 'Happy', 'mood_key' => 'happy' ] );
		$meta = $this->mood( 'Just text', [ 'mood_label' => 'Calm', 'mood_key' => 'calm' ] );

		$this->assertSame( 'Sad', $this->facts( $card )['label'] );
		$this->assertSame( 'sad', $this->facts( $card )['family'] );
		$this->assertSame( 'Calm', $this->facts( $meta )['label'] );
		$this->assertSame( 'calm', $this->facts( $meta )['family'] );
		$this->assertSame( 'calm', $this->facts( $meta )['key'] );
	}

	public function test_a_keyless_label_keeps_its_typed_text_and_still_has_a_family(): void {
		$facts = $this->facts( $this->mood( $this->card( [ 'mood' => 'happy' ] ) ) );

		$this->assertSame( 'happy', $facts['label'] );
		$this->assertSame( 'joy', $facts['family'] );
	}

	public function test_a_keyed_label_follows_the_spelling_setting(): void {
		update_option( 'pkiw_settings', [ 'mood_spelling' => 'en_GB' ] );
		( new ReflectionProperty( Mood_Vocabulary::class, 'cache' ) )->setValue( null, [] );

		$facts = $this->facts( $this->mood( $this->card( [ 'mood' => 'Energized', 'moodKey' => 'energized' ] ) ) );

		$this->assertSame( 'Energised', $facts['label'] );
		$this->assertSame( 'energy', $facts['family'] );
	}

	public function test_the_day_is_the_local_day(): void {
		// 1:30 am UTC on January 1 is 8:30 pm on December 31 in New York.
		$id = $this->mood( $this->card( [ 'mood' => 'Happy' ] ), [], [ 'post_date' => '2024-12-31 20:30:00', 'post_date_gmt' => '2025-01-01 01:30:00' ] );

		$this->assertSame( '2024-12-31', $this->facts( $id )['day'] );
	}

	// Who can read it.

	public function test_a_protected_private_or_draft_mood_has_no_facts_for_a_visitor(): void {
		$locked  = $this->mood( $this->card( [ 'mood' => 'Happy' ] ), [], [ 'post_password' => 'sesame' ] );
		$private = $this->mood( $this->card( [ 'mood' => 'Happy' ] ), [], [ 'post_status' => 'private' ] );
		$draft   = $this->mood( $this->card( [ 'mood' => 'Happy' ] ), [], [ 'post_status' => 'draft' ] );

		foreach ( [ $locked, $private, $draft ] as $id ) {
			$this->assertSame( [], $this->facts( $id ), "Post {$id}" );
			$this->assertSame( [], Mood_Vocabulary::facts( $id ), "The reader itself, post {$id}" );
		}
	}

	public function test_an_editor_reads_a_private_mood_but_still_not_a_protected_one(): void {
		$private = $this->mood( $this->card( [ 'mood' => 'Happy' ] ), [], [ 'post_status' => 'private' ] );
		$locked  = $this->mood( $this->card( [ 'mood' => 'Happy' ] ), [], [ 'post_password' => 'sesame' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( self::KEYS, array_keys( $this->facts( $private ) ) );
		$this->assertSame( [], $this->facts( $locked ), 'A password stops facts until it is entered.' );
	}

	public function test_a_post_that_does_not_exist_has_no_facts(): void {
		$this->assertSame( [], Mood_Vocabulary::facts( 999999 ) );
	}

	public function test_public_location_rows_reach_no_fact_key(): void {
		$id = $this->mood(
			$this->card( [ 'mood' => 'Happy' ] ),
			[
				'geo_latitude'  => '40.7128',
				'geo_longitude' => '-74.0060',
				'geo_address'   => '1 Example Street',
				'geo_privacy'   => 'public',
			]
		);
		$facts = $this->facts( $id );

		foreach ( array_keys( $facts ) as $key ) {
			foreach ( [ 'geo', 'lat', 'lon', 'location', 'address' ] as $needle ) {
				$this->assertStringNotContainsString( $needle, $key, "Fact key {$key}" );
			}
		}
		$this->assertStringNotContainsString( 'Example Street', (string) wp_json_encode( $facts ) );
	}
}

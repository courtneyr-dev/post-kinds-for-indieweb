<?php
/**
 * The craft kind's facts reader (issue 249).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Craft_Archive;
use PKIW\Kind_Facts;
use PKIW\Taxonomy;

/**
 * Craft_Archive::facts() is the one door a theme reads a craft post through.
 * It returns the stored craft type and time, the post's name and its picture,
 * and returns [] for a post the requester can't see. Names, hosts and places
 * are invented.
 *
 * @group integration
 */
final class CraftFactsTest extends WP_UnitTestCase {

	/**
	 * The keys, in order.
	 *
	 * @var string[]
	 */
	private const KEYS = [ 'craft_type', 'minutes', 'duration_iso', 'duration_label', 'name', 'name_is_title', 'picture', 'featured_in_content' ];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published craft post.
	 *
	 * @param string               $title Post title; '' for an untitled post.
	 * @param string|null          $type  `_pkiw_craft_type`, or null for no row.
	 * @param int|null             $mins  `_pkiw_craft_minutes`, or null for no row.
	 * @param array<string, mixed> $args  Extra post arguments.
	 */
	private function craft( string $title, ?string $type, ?int $mins, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => '2026-08-20 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'craft', Taxonomy::TAXONOMY );
		if ( null !== $type ) {
			add_post_meta( $id, '_pkiw_craft_type', $type );
		}
		if ( null !== $mins ) {
			add_post_meta( $id, '_pkiw_craft_minutes', (string) $mins );
		}

		return $id;
	}

	/**
	 * An image attachment row with no file on disk.
	 *
	 * @param int    $parent Parent post ID.
	 * @param string $alt    Stored alt text.
	 */
	private function attachment( int $parent, string $alt = '' ): int {
		$id = (int) self::factory()->attachment->create_object(
			[
				'file'           => '2026/08/craft-' . wp_generate_password( 8, false ) . '.jpg',
				'post_parent'    => $parent,
				'post_mime_type' => 'image/jpeg',
			]
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * A core/image block for an attachment.
	 *
	 * @param int $id Attachment ID.
	 */
	private function image_block( int $id ): string {
		return '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" alt="Step" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
	}

	public function test_the_reader_is_the_craft_kinds_and_returns_exactly_the_documented_keys(): void {
		$entry = Kind_Facts::reader( 'craft' );
		$this->assertNotNull( $entry, 'Craft_Archive registers its reader through Kind_Facts.' );
		$this->assertSame( [ Craft_Archive::class, 'facts' ], $entry['reader'] );
		$this->assertSame( [], $entry['location'], 'Craft has no location facts.' );

		$id    = $this->craft( 'Wool Scarf', 'Knitting', 1080 );
		$facts = Craft_Archive::facts( $id );

		$this->assertSame( self::KEYS, array_keys( $facts ) );
		$this->assertSame( $facts, Kind_Facts::get( $id ), 'The door returns the reader\'s facts unchanged.' );
	}

	public function test_a_card_post_gives_its_type_time_name_and_featured_picture(): void {
		$id       = $this->craft( 'Wool Scarf', 'Knitting', 1080 );
		$featured = $this->attachment( $id, 'A grey scarf' );
		set_post_thumbnail( $id, $featured );

		$facts = Craft_Archive::facts( $id );

		$this->assertSame( 'Knitting', $facts['craft_type'] );
		$this->assertSame( 1080, $facts['minutes'] );
		$this->assertSame( 'PT18H', $facts['duration_iso'] );
		$this->assertSame( '18 hr', $facts['duration_label'] );
		$this->assertSame( 'Wool Scarf', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertFalse( $facts['featured_in_content'], 'The featured image isn\'t in the content.' );
		$this->assertSame( 'featured', $facts['picture']['source'] );
		$this->assertSame( $featured, $facts['picture']['attachment_id'] );
		$this->assertSame( 'A grey scarf', $facts['picture']['alt'] );
		$this->assertTrue( $facts['picture']['suppress_featured'] );
		$this->assertSame( \PKIW\kind_picture( $id ), $facts['picture'], 'Craft has no card cover: the picture is kind_picture().' );
	}

	public function test_hours_and_minutes_read_as_the_card_helpers_print_them(): void {
		foreach (
			[
				45   => [ 'PT45M', '45 min' ],
				60   => [ 'PT1H', '1 hr' ],
				150  => [ 'PT2H30M', '2 hr 30 min' ],
				1200 => [ 'PT20H', '20 hr' ],
			] as $mins => [ $iso, $label ]
		) {
			$facts = Craft_Archive::facts( $this->craft( 'Time ' . $mins, 'Pottery', $mins ) );

			$this->assertSame( $mins, $facts['minutes'] );
			$this->assertSame( $iso, $facts['duration_iso'], (string) $mins );
			$this->assertSame( $label, $facts['duration_label'], (string) $mins );
		}
	}

	public function test_a_post_with_no_time_has_no_duration_and_a_post_with_no_type_has_an_empty_type(): void {
		$no_time = Craft_Archive::facts( $this->craft( 'Hand-Sewn Notebook', 'Bookbinding', null ) );
		$this->assertSame( 0, $no_time['minutes'] );
		$this->assertSame( '', $no_time['duration_iso'] );
		$this->assertSame( '', $no_time['duration_label'] );
		$this->assertSame( 'Bookbinding', $no_time['craft_type'] );

		$no_type = Craft_Archive::facts( $this->craft( 'Practice Swatches', null, 30 ) );
		$this->assertSame( '', $no_type['craft_type'] );
		$this->assertSame( 30, $no_type['minutes'] );
		$this->assertSame( 'PT30M', $no_type['duration_iso'] );

		$neither = Craft_Archive::facts( $this->craft( 'Blank', null, null ) );
		$this->assertSame( '', $neither['craft_type'] );
		$this->assertSame( 0, $neither['minutes'] );
	}

	public function test_the_stored_type_keeps_its_case_and_loses_only_padding(): void {
		$lower = $this->craft( 'Plant Stand', 'woodworking', 600 );
		$this->assertSame( 'woodworking', Craft_Archive::facts( $lower )['craft_type'], 'Case is kept: the archive prints it verbatim.' );

		// sanitize_text_field() trims every write the plugin makes, so padding takes a raw row.
		$padded = $this->craft( 'Cabled Cowl', null, null );
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->postmeta,
			[
				'post_id'    => $padded,
				'meta_key'   => '_pkiw_craft_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '  Knitting  ', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		wp_cache_delete( $padded, 'post_meta' );
		$this->assertSame( '  Knitting  ', get_metadata_raw( 'post', $padded, '_pkiw_craft_type', true ), 'The padding is stored.' );

		$this->assertSame( 'Knitting', Craft_Archive::facts( $padded )['craft_type'] );
	}

	public function test_a_stored_row_that_is_not_a_positive_number_of_minutes_reads_as_none(): void {
		global $wpdb;
		foreach ( [ '-5', '0', 'soon', '' ] as $raw ) {
			$id = $this->craft( 'Odd ' . $raw, 'Quilting', null );
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->postmeta,
				[
					'post_id'    => $id,
					'meta_key'   => '_pkiw_craft_minutes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $raw, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
			wp_cache_delete( $id, 'post_meta' );

			$facts = Craft_Archive::facts( $id );

			$this->assertSame( 0, $facts['minutes'], "'{$raw}'" );
			$this->assertSame( '', $facts['duration_iso'], "'{$raw}'" );
			$this->assertSame( '', $facts['duration_label'], "'{$raw}'" );
		}
	}

	public function test_a_titled_post_names_itself_and_an_untitled_one_takes_the_kind_and_date(): void {
		$titled = $this->craft( 'A Seed-Stitch Baby Blanket That Took Most Of The Winter', 'Knitting', 0 );
		$facts  = Craft_Archive::facts( $titled );
		$this->assertSame( 'A Seed-Stitch Baby Blanket That Took Most Of The Winter', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );

		$untitled = $this->craft( '', null, null, [ 'post_date' => '2026-08-03 09:15:00' ] );
		$facts    = Craft_Archive::facts( $untitled );
		$this->assertSame( 'Craft, ' . get_the_date( '', $untitled ), $facts['name'] );
		$this->assertSame( 'Craft, August 3, 2026', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'], 'A synthetic name never gets p-name (X8).' );

		$spaces = $this->craft( "  \t ", null, null );
		$this->assertFalse( Craft_Archive::facts( $spaces )['name_is_title'], 'A title of spaces is no title.' );
		$this->assertStringStartsWith( 'Craft, ', Craft_Archive::facts( $spaces )['name'] );
	}

	public function test_the_name_of_an_untitled_post_never_reads_its_content(): void {
		$id = $this->craft( '', 'Knitting', 90, [ 'post_content' => '<!-- wp:paragraph --><p>My private words about the project</p><!-- /wp:paragraph -->' ] );

		$facts = Craft_Archive::facts( $id );

		$this->assertStringNotContainsString( 'private words', $facts['name'] );
		$this->assertSame( 'Craft, ' . get_the_date( '', $id ), $facts['name'] );
	}

	public function test_featured_in_content_is_true_when_a_step_image_is_the_featured_attachment(): void {
		$id       = $this->craft( 'Plant Stand', 'woodworking', 600 );
		$first    = $this->attachment( $id );
		$featured = $this->attachment( $id );
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '<!-- wp:post-kinds-indieweb/craft-card {"craftType":"woodworking","timeMinutes":600} /-->'
					. '<!-- wp:post-kinds-indieweb/steps --><ol class="wp-block-post-kinds-indieweb-steps pkiw-craft-steps">'
					. '<!-- wp:post-kinds-indieweb/step --><li class="wp-block-post-kinds-indieweb-step pkiw-craft-step">' . $this->image_block( $first ) . '<!-- wp:paragraph --><p>Cut the legs.</p><!-- /wp:paragraph --></li><!-- /wp:post-kinds-indieweb/step -->'
					. '<!-- wp:post-kinds-indieweb/step --><li class="wp-block-post-kinds-indieweb-step pkiw-craft-step">' . $this->image_block( $featured ) . '<!-- wp:paragraph --><p>Glue the top.</p><!-- /wp:paragraph --></li><!-- /wp:post-kinds-indieweb/step -->'
					. '</ol><!-- /wp:post-kinds-indieweb/steps -->',
			]
		);
		set_post_thumbnail( $id, $featured );

		$this->assertTrue( Craft_Archive::facts( $id )['featured_in_content'] );
		$this->assertSame( \PKIW\featured_in_content( $id ), Craft_Archive::facts( $id )['featured_in_content'], 'The key is the plugin helper\'s answer, so the theme never calls the helper.' );

		set_post_thumbnail( $id, $this->attachment( $id ) );
		$this->assertFalse( Craft_Archive::facts( $id )['featured_in_content'], 'A featured image that no step shows is not in the content.' );
	}

	public function test_a_post_with_no_picture_has_an_empty_picture_source(): void {
		$facts = Craft_Archive::facts( $this->craft( 'Zine Binding Jig', 'Bookbinding', 90 ) );

		$this->assertSame( '', $facts['picture']['source'] );
		$this->assertSame( 0, $facts['picture']['attachment_id'] );
		$this->assertFalse( $facts['featured_in_content'] );
	}

	public function test_a_password_protected_post_has_no_facts(): void {
		$id = $this->craft( 'Locked Quilt Square', 'Knitting', 120, [ 'post_password' => 'seed-249' ] );
		$this->attachment( $id );

		$this->assertSame( [], Craft_Archive::facts( $id ) );
		$this->assertSame( [], Kind_Facts::get( $id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( [], Craft_Archive::facts( $id ), 'A password stays a password for an administrator without the cookie.' );
	}

	public function test_a_password_of_spaces_still_hides_the_facts(): void {
		$id = $this->craft( 'Spaces Quilt Square', 'Knitting', 120, [ 'post_password' => '  ' ] );

		$this->assertSame( [], Craft_Archive::facts( $id ) );
	}

	public function test_private_and_draft_posts_have_no_facts_for_the_public_and_private_has_them_for_an_editor(): void {
		$private = $this->craft( 'Private Embroidery Hoop', 'Embroidery', 45, [ 'post_status' => 'private' ] );
		$draft   = $this->craft( 'Draft', 'Knitting', 45, [ 'post_status' => 'draft' ] );

		$this->assertSame( [], Craft_Archive::facts( $private ) );
		$this->assertSame( [], Craft_Archive::facts( $draft ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 'Embroidery', Craft_Archive::facts( $private )['craft_type'] );
		$this->assertSame( 'Knitting', Craft_Archive::facts( $draft )['craft_type'] );
	}

	public function test_a_missing_post_has_no_facts(): void {
		$this->assertSame( [], Craft_Archive::facts( 987654 ) );
	}

	public function test_the_facts_carry_no_location_keys_even_with_public_geo_meta(): void {
		$id = $this->craft( 'Spool Holder', '3D printing', 360 );
		foreach ( [ 'geo_latitude' => '40.1', 'geo_longitude' => '-74.2', 'geo_address' => '1 Example Way', 'geo_public' => '1' ] as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		$facts = Craft_Archive::facts( $id );

		$this->assertSame( self::KEYS, array_keys( $facts ) );
		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/geo|place|lat|lon|address|street|locality|region|country/i', $key );
		}
		$this->assertStringNotContainsString( 'Example Way', (string) wp_json_encode( $facts ) );
	}

	public function test_a_card_saved_through_the_editor_feeds_the_reader(): void {
		$id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Wool Scarf',
				'post_content' => '<!-- wp:post-kinds-indieweb/craft-card {"craftType":" Knitting ","timeMinutes":150} /-->',
			]
		);

		$facts = Craft_Archive::facts( $id );

		$this->assertSame( 'craft', Kind_Facts::kind_of( get_post( $id ) ), 'A craft card sets the kind.' );
		$this->assertSame( 'Knitting', $facts['craft_type'] );
		$this->assertSame( 150, $facts['minutes'] );
		$this->assertSame( 'PT2H30M', $facts['duration_iso'] );
	}
}

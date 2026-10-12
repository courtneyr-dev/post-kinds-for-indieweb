<?php
/**
 * Photo kind facts reader tests for issue 241.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Photo_Archive;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * Photo facts are the single read path for the theme and blocks.
 *
 * @group integration
 */
final class PhotoFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A published photo post.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return int Post ID.
	 */
	private function photo( array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Evening Window',
					'post_content' => '',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'photo', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * An image attachment row with no file on disk.
	 *
	 * @param int    $parent Parent post ID.
	 * @param string $file   Relative upload path.
	 * @return int Attachment ID.
	 */
	private function attachment( int $parent = 0, string $file = '' ): int {
		return (int) self::factory()->attachment->create_object(
			[
				'file'           => '' === $file ? '2026/08/photo-' . wp_generate_password( 8, false ) . '.jpg' : $file,
				'post_parent'    => $parent,
				'post_mime_type' => 'image/jpeg',
			]
		);
	}

	/**
	 * A core/image block for an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return string Block markup.
	 */
	private function image_block( int $id ): string {
		return '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" class="wp-image-' . $id . '" alt=""/></figure><!-- /wp:image -->';
	}

	/**
	 * A core/gallery block holding image blocks.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return string Block markup.
	 */
	private function gallery_block( array $ids ): string {
		return '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . implode( '', array_map( [ $this, 'image_block' ], $ids ) ) . '</figure><!-- /wp:gallery -->';
	}

	public function test_a_titled_photo_gives_the_expected_keys_and_values(): void {
		$id   = $this->photo( [ 'post_title' => 'Evening Window' ] );
		$post = get_post( $id );

		$facts = Photo_Archive::facts( $id );

		$this->assertSame( [ 'has_title', 'title', 'name', 'date', 'date_iso', 'lead', 'featured_in_content' ], array_keys( $facts ) );
		$this->assertTrue( $facts['has_title'] );
		$this->assertSame( 'Evening Window', $facts['title'] );
		$this->assertSame( 'Evening Window', $facts['name'] );
		$this->assertSame( get_the_date( '', $post ), $facts['date'] );
		$this->assertSame( get_post_time( 'c', true, $post ), $facts['date_iso'] );
		$this->assertSame( \PKIW\lead_image( $id ), $facts['lead'] );
		$this->assertFalse( $facts['featured_in_content'] );
	}

	public function test_an_untitled_photo_names_itself_from_kind_and_date(): void {
		$id   = $this->photo( [ 'post_title' => '' ] );
		$post = get_post( $id );

		$facts = Photo_Archive::facts( $id );

		$this->assertFalse( $facts['has_title'] );
		$this->assertSame( '', $facts['title'] );
		$this->assertSame( 'Photo, ' . get_the_date( '', $post ), $facts['name'] );
	}

	public function test_the_photo_reader_registers_and_kind_facts_reads_it(): void {
		$id = $this->photo();

		$this->assertNotNull( Kind_Facts::reader( 'photo' ) );
		$this->assertSame( Photo_Archive::facts( $id ), Kind_Facts::get( $id ) );
	}

	public function test_hidden_photos_return_no_facts_except_private_photos_for_admins(): void {
		wp_set_current_user( 0 );
		$protected = $this->photo( [ 'post_password' => 'secret' ] );
		$private   = $this->photo( [ 'post_status' => 'private' ] );

		$this->assertSame( [], Photo_Archive::facts( $protected ) );
		$this->assertSame( [], Kind_Facts::get( $protected ) );
		$this->assertSame( [], Photo_Archive::facts( $private ) );
		$this->assertSame( [], Kind_Facts::get( $private ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertNotSame( [], Photo_Archive::facts( $private ) );
		$this->assertSame( Photo_Archive::facts( $private ), Kind_Facts::get( $private ) );
	}

	public function test_photo_facts_do_not_include_location_keys(): void {
		$id = $this->photo();
		foreach (
			[
				'geo_venue'                              => 'Example Hall',
				'geo_locality'                           => 'Sampletown',
				'geo_region'                             => 'Example Region',
				'geo_country_name'                       => 'Exampleland',
				'geo_address'                            => '100 Example Avenue, Sampletown',
				'geo_latitude'                           => '40.1',
				'geo_longitude'                          => '-75.1',
				'geo_public'                             => '1',
				Meta_Fields::PREFIX . 'geo_privacy'      => 'public',
				Meta_Fields::PREFIX . 'checkin_address'  => '100 Example Avenue',
				Meta_Fields::PREFIX . 'checkin_latitude' => '40.1',
			] as $key => $value
		) {
			update_post_meta( $id, $key, $value );
		}

		foreach ( array_keys( Photo_Archive::facts( $id ) ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/geo|lat|lng|longitude|latitude|address|street|place|location|venue/i', $key );
		}
	}

	public function test_featured_in_content_tracks_gallery_group_and_absent_images(): void {
		$first  = $this->attachment();
		$second = $this->attachment();
		$third  = $this->attachment();

		$gallery = $this->photo( [ 'post_content' => $this->gallery_block( [ $first, $second ] ) ] );
		set_post_thumbnail( $gallery, $second );
		$this->assertTrue( Photo_Archive::facts( $gallery )['featured_in_content'] );

		$group = $this->photo(
			[
				'post_content' => '<!-- wp:group --><div class="wp-block-group">' . $this->image_block( $third ) . '</div><!-- /wp:group -->',
			]
		);
		set_post_thumbnail( $group, $third );
		$this->assertTrue( Photo_Archive::facts( $group )['featured_in_content'] );

		$missing = $this->photo( [ 'post_content' => $this->image_block( $first ) ] );
		set_post_thumbnail( $missing, $third );
		$this->assertFalse( Photo_Archive::facts( $missing )['featured_in_content'] );
	}

	public function test_location_generated_titles_use_the_safe_public_title(): void {
		wp_set_current_user( 0 );
		$id = $this->photo(
			[
				'post_title' => 'Late Light at Example Cafe',
				'post_date'  => '2026-08-20 10:00:00',
			]
		);
		update_post_meta( $id, Title_Privacy::META_KEY, Title_Privacy::SOURCE_LOCATION );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		$facts = Photo_Archive::facts( $id );

		$this->assertSame( get_the_title( $id ), $facts['title'] );
		$this->assertNotSame( get_post_field( 'post_title', $id ), $facts['title'] );
		$this->assertSame( $facts['title'], $facts['name'] );
	}

	public function test_facts_use_the_passed_post_not_the_global_post(): void {
		$a = $this->photo( [ 'post_title' => 'Global Photo' ] );
		$b = $this->photo( [ 'post_title' => 'Requested Photo' ] );

		$GLOBALS['post'] = get_post( $a );
		setup_postdata( $GLOBALS['post'] );

		$this->assertSame( 'Requested Photo', Photo_Archive::facts( $b )['title'] );
		wp_reset_postdata();
	}
}

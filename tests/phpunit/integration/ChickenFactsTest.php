<?php
/**
 * Chicken facts reader for the chicken archive (issue 250).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Chicken_Archive;
use PKIW\Kind_Facts;
use PKIW\Taxonomy;

/**
 * Chicken facts expose only public archive facts for chicken posts.
 *
 * @group integration
 */
final class ChickenFactsTest extends WP_UnitTestCase {

	/**
	 * Original timezone.
	 *
	 * @var string
	 */
	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		( new Taxonomy() )->create_default_terms();
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_chicken_facts_are_registered_for_kind_facts(): void {
		$this->assertNotNull( Kind_Facts::reader( 'chicken' ) );

		$post_id = $this->chicken( 'Hen at the gate' );

		$this->assertSame( Chicken_Archive::facts( $post_id ), \PKIW\kind_facts( $post_id ) );
	}

	public function test_titled_post_exposes_the_archive_fact_contract(): void {
		$post_id = $this->chicken( 'Hen at the gate', [ 'post_date' => '2026-10-10 09:00:00' ] );
		$post    = get_post( $post_id );

		$facts = Chicken_Archive::facts( $post_id );

		$this->assertSame( 'Hen at the gate', $facts['name'] );
		$this->assertTrue( $facts['named'] );
		$this->assertSame( 'Chicken', $facts['kind_label'] );
		$this->assertSame( get_permalink( $post ), $facts['url'] );
		$this->assertSame( get_post_time( 'c', true, $post ), $facts['published'] );
	}

	public function test_untitled_post_uses_the_chicken_synthetic_name(): void {
		$post_id = $this->chicken( '', [ 'post_date' => '2026-10-09 09:00:00' ] );
		$post    = get_post( $post_id );

		$facts = Chicken_Archive::facts( $post_id );

		$this->assertSame( 'Chicken, ' . get_the_date( '', $post ), $facts['name'] );
		$this->assertFalse( $facts['named'] );
	}

	public function test_facts_read_the_requested_post_not_the_global_post(): void {
		$a = $this->chicken( 'Coop doorway', [ 'post_date' => '2026-10-08 08:00:00' ] );
		$b = $this->chicken( 'Backyard visitor', [ 'post_date' => '2026-10-07 17:00:00' ] );

		global $post;
		$post = get_post( $a );
		setup_postdata( $post );

		$facts = Chicken_Archive::facts( $b );

		$this->assertSame( 'Backyard visitor', $facts['name'] );
		$this->assertSame( get_post_time( 'c', true, get_post( $b ) ), $facts['published'] );
		$this->assertSame( get_permalink( $b ), $facts['url'] );
	}

	public function test_featured_in_content_reflects_nested_id_scaled_src_and_false_cases(): void {
		$nested = $this->chicken( 'Nested image' );
		$image  = $this->attachment( '2026/10/nested.jpg' );
		wp_update_post(
			[
				'ID'           => $nested,
				'post_content' => '<!-- wp:group --><div class="wp-block-group">' . $this->image_block( $image, wp_get_attachment_url( $image ) ) . '</div><!-- /wp:group -->',
			]
		);
		set_post_thumbnail( $nested, $image );

		$scaled   = $this->chicken( 'Scaled image' );
		$featured = $this->attachment( '2026/10/lighthouse-scaled.jpg' );
		$base     = wp_get_upload_dir()['baseurl'];
		wp_update_post(
			[
				'ID'           => $scaled,
				'post_content' => $this->image_block( 0, $base . '/2026/10/lighthouse-768x512.jpg' ),
			]
		);
		set_post_thumbnail( $scaled, $featured );

		$different = $this->chicken( 'Different image' );
		$inside    = $this->attachment( '2026/10/inside.jpg' );
		$outside   = $this->attachment( '2026/10/outside.jpg' );
		wp_update_post(
			[
				'ID'           => $different,
				'post_content' => $this->image_block( $inside, wp_get_attachment_url( $inside ) ),
			]
		);
		set_post_thumbnail( $different, $outside );

		$none = $this->chicken( 'No featured image' );

		$this->assertTrue( Chicken_Archive::facts( $nested )['featured_in_content'] );
		$this->assertTrue( Chicken_Archive::facts( $scaled )['featured_in_content'] );
		$this->assertFalse( Chicken_Archive::facts( $different )['featured_in_content'] );
		$this->assertFalse( Chicken_Archive::facts( $none )['featured_in_content'] );
	}

	public function test_no_featured_image_returns_empty_picture_source(): void {
		$facts = Chicken_Archive::facts( $this->chicken( 'No picture' ) );

		$this->assertSame( '', $facts['picture']['source'] );
	}

	public function test_password_private_missing_and_non_chicken_posts_return_empty_facts(): void {
		wp_set_current_user( 0 );
		$protected = $this->chicken( 'Locked coop', [ 'post_password' => 'secret' ] );
		$private   = $this->chicken( 'Private coop', [ 'post_status' => 'private' ] );
		$note      = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Plain note' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );

		$this->assertSame( [], Chicken_Archive::facts( 0 ) );
		$this->assertSame( [], Chicken_Archive::facts( $protected ) );
		$this->assertSame( [], Chicken_Archive::facts( $private ) );
		$this->assertSame( [], Chicken_Archive::facts( $note ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'Private coop', Chicken_Archive::facts( $private )['name'] );
	}

	public function test_location_meta_never_appears_in_chicken_facts(): void {
		$post_id = $this->chicken( 'Mapless hen' );
		update_post_meta( $post_id, 'geo_latitude', '0.5' );
		update_post_meta( $post_id, 'geo_longitude', '-30.5' );
		update_post_meta( $post_id, 'geo_address', '12 Example Lane, Sampletown' );
		update_post_meta( $post_id, 'geo_public', '1' );

		$facts = Chicken_Archive::facts( $post_id );

		foreach ( [ 'latitude', 'longitude', 'geo_address', 'address', 'location', 'geo' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $facts );
		}
	}

	/**
	 * Create a chicken post.
	 *
	 * @param string               $title Post title.
	 * @param array<string, mixed> $args  Extra post args.
	 * @return int
	 */
	private function chicken( string $title, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => '2026-10-01 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'chicken', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * An image attachment row with no file on disk.
	 *
	 * @param string $file Path relative to uploads.
	 * @return int
	 */
	private function attachment( string $file ): int {
		return (int) self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
			]
		);
	}

	/**
	 * A core image block.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $src Image URL.
	 * @return string
	 */
	private function image_block( int $id, string $src ): string {
		$attrs = $id > 0 ? ' {"id":' . $id . '}' : '';
		$class = $id > 0 ? ' class="wp-image-' . $id . '"' : '';

		return '<!-- wp:image' . $attrs . ' --><figure class="wp-block-image"><img src="' . esc_url( $src ) . '"' . $class . '/></figure><!-- /wp:image -->';
	}
}

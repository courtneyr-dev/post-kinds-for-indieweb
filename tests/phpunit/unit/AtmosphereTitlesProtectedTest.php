<?php
/**
 * Protected-post guard for the ATmosphere integration's derived titles.
 *
 * A derived Standard.site title is built from kind meta (track, artist,
 * show, venue). A password-protected post must not publish that meta in
 * its document title, so derive() returns '' for it.
 *
 * @package PKIW
 * @group   atmosphere
 */

namespace PKIW\Tests\Unit;

use PKIW\Integrations\Atmosphere_Titles;
use PKIW\Meta_Fields;

/**
 * Atmosphere_Titles protected-post tests.
 *
 * @group atmosphere
 */
class AtmosphereTitlesProtectedTest extends \WP_UnitTestCase {

	/**
	 * Create an untitled jam post with a track and artist.
	 *
	 * @param string $password Post password, '' for none.
	 * @return \WP_Post
	 */
	private function make_jam( string $password ): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_title'    => '',
				'post_content'  => 'Body text probe for the summary fallback.',
				'post_password' => $password,
			]
		);

		if ( ! term_exists( 'jam', 'kind' ) ) {
			wp_insert_term( 'Jam', 'kind', [ 'slug' => 'jam' ] );
		}
		wp_set_object_terms( $post_id, 'jam', 'kind' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'jam_track', 'Locked Track Probe' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'jam_artist', 'Locked Artist Probe' );

		return get_post( $post_id );
	}

	public function test_protected_post_derives_empty_title() {
		$post = $this->make_jam( 'secret' );

		$this->assertSame( '', Atmosphere_Titles::derive( $post ) );
	}

	/**
	 * Core's "Protected: %s" prefix makes the title non-empty, which hides
	 * the leak; a site that drops the prefix must still get ''.
	 */
	public function test_protected_post_derives_empty_title_without_core_prefix() {
		$post   = $this->make_jam( 'secret' );
		$format = static function () {
			return '%s';
		};
		add_filter( 'protected_title_format', $format );

		$title = Atmosphere_Titles::derive( $post );

		remove_filter( 'protected_title_format', $format );
		$this->assertSame( '', $title );
	}

	public function test_protected_post_skips_the_title_filter() {
		$post  = $this->make_jam( 'secret' );
		$calls = 0;
		$probe = static function ( $title ) use ( &$calls ) {
			++$calls;
			return $title;
		};
		add_filter( 'pkiw_atmosphere_document_title', $probe );

		Atmosphere_Titles::derive( $post );

		remove_filter( 'pkiw_atmosphere_document_title', $probe );
		$this->assertSame( 0, $calls );
	}

	public function test_public_post_still_derives_its_title() {
		$post  = $this->make_jam( '' );
		$title = Atmosphere_Titles::derive( $post );

		$this->assertStringContainsString( 'Locked Track Probe', $title );
	}
}

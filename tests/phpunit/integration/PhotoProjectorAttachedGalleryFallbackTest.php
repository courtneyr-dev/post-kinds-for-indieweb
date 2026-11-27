<?php
/**
 * Photo projector and the attached-media Gallery on a WordPress without Core's resolver.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Photo_Location;
use PKIW\Photo_Projector;
use PKIW\Taxonomy;

/**
 * Without block_core_gallery_resolve_dynamic_source() (WordPress 7.0), an
 * attached-media Gallery renders only its saved children, so it must not
 * claim the projector a later static Gallery qualifies for.
 *
 * @group integration
 */
final class PhotoProjectorAttachedGalleryFallbackTest extends WP_UnitTestCase {

	private const MOUNTS = '//figure[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector__mount ")]';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		Photo_Projector::reset();
		Photo_Location::reset();
		add_filter( 'pkiw_photo_projector_core_resolves_attached_media', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'pkiw_photo_projector_core_resolves_attached_media', '__return_false' );
		wp_dequeue_script( 'pkiw-photo-projector' );
		wp_dequeue_style( 'pkiw-photo-projector' );
		wp_reset_postdata();
		Photo_Projector::reset();
		parent::tear_down();
	}

	/**
	 * An image attachment with responsive metadata and no file on disk.
	 *
	 * @param int    $parent Parent post, or 0.
	 * @param string $date   Upload date.
	 * @return int Attachment ID.
	 */
	private function attachment( int $parent, string $date ): int {
		$file = '2026/08/photo-' . wp_generate_password( 8, false ) . '.jpg';
		$id   = (int) self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_parent'    => $parent,
				'post_mime_type' => 'image/jpeg',
				'post_date'      => $date,
				'post_date_gmt'  => $date,
			]
		);
		update_post_meta(
			$id,
			'_wp_attachment_metadata',
			[
				'width'  => 1600,
				'height' => 1000,
				'file'   => $file,
				'sizes'  => [],
			]
		);

		return $id;
	}

	/**
	 * A static Gallery of the given images.
	 *
	 * @param int[] $ids Attachment IDs in display order.
	 */
	private function static_gallery( array $ids ): string {
		$images = '';
		foreach ( $ids as $id ) {
			$images .= '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '" alt="" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->';
		}

		return '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . $images . '</figure><!-- /wp:gallery -->';
	}

	public function test_attached_gallery_without_core_resolver_leaves_the_projector_to_a_static_gallery(): void {
		$id = (int) self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Harbor walk',
			]
		);
		wp_set_object_terms( $id, 'photo', Taxonomy::TAXONOMY );
		$this->attachment( $id, '2026-08-02 09:00:00' );
		$this->attachment( $id, '2026-08-03 09:00:00' );
		$this->attachment( $id, '2026-08-04 09:00:00' );
		$others = [ $this->attachment( 0, '2026-08-06 09:00:00' ), $this->attachment( 0, '2026-08-07 09:00:00' ) ];
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"},"linkTo":"none"} /-->' . $this->static_gallery( $others ),
			]
		);

		$this->go_to( (string) get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		wp_reset_postdata();

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath  = new DOMXPath( $dom );
		$mounts = [];
		foreach ( $xpath->query( self::MOUNTS . '//img' ) as $img ) {
			if ( $img instanceof DOMElement && preg_match( '/\bwp-image-(\d+)\b/', $img->getAttribute( 'class' ), $m ) ) {
				$mounts[] = (int) $m[1];
			}
		}

		$this->assertSame( 1, $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector ")]' )->length, 'The static Gallery becomes the projector.' );
		$this->assertSame( $others, $mounts );
		$this->assertSame( 2, $xpath->query( '//img[contains(concat(" ", normalize-space(@class), " "), " u-photo ")]' )->length );
	}
}

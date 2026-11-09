<?php
/**
 * Watch-poster display-setting integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings;
use PKIW\Block_Bindings_Source;
use PKIW\Blocks\Now_Playing;
use PKIW\Blocks\Recent_Kinds;
use PKIW\Featured_Artwork;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class WatchPosterHandlingTest extends WP_UnitTestCase {

	private const POSTER = 'https://image.tmdb.org/t/p/w342/display.jpg';

	private function watch_post(): int {
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Display Fixture',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Display Fixture","posterImage":"' . self::POSTER . '"} /-->',
			]
		);
		remove_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		wp_set_object_terms( $post_id, 'watch', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_title', 'Display Fixture' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_poster', self::POSTER );

		return $post_id;
	}

	private function render_card( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		setup_postdata( get_post( $post_id ) );

		return apply_filters( 'the_content', get_post( $post_id )->post_content );
	}

	public function test_display_table_for_local_and_remote_posters(): void {
		$post_id       = $this->watch_post();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		foreach ( [ 'sideload', 'hotlink', 'none', 'unknown' ] as $handling ) {
			update_option( 'pkiw_settings', [ 'image_handling' => $handling ] );
			$remote = PKIW\watch_poster_display( $post_id, self::POSTER );
			if ( 'hotlink' === $handling ) {
				$this->assertSame( [ 'attachment_id' => 0, 'url' => self::POSTER ], $remote );
			} else {
				$this->assertSame( [ 'attachment_id' => 0, 'url' => '' ], $remote );
			}

			update_post_meta( $post_id, Featured_Artwork::SOURCE_META, self::POSTER );
			update_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, $attachment_id );
			$local = PKIW\watch_poster_display( $post_id, self::POSTER );
			if ( 'none' === $handling ) {
				$this->assertSame( [ 'attachment_id' => 0, 'url' => '' ], $local );
			} else {
				$this->assertSame( $attachment_id, $local['attachment_id'] );
				$this->assertSame( wp_get_attachment_url( $attachment_id ), $local['url'] );
			}
			delete_post_meta( $post_id, Featured_Artwork::SOURCE_META );
			delete_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META );
		}
	}

	public function test_sideload_without_local_copy_prints_no_card_media_or_image(): void {
		$post_id = $this->watch_post();
		update_option( 'pkiw_settings', [ 'image_handling' => 'sideload' ] );

		$html = $this->render_card( $post_id );

		$this->assertStringNotContainsString( 'class="pk-media"', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'image.tmdb.org', $html );
	}

	public function test_hotlink_is_the_only_remote_card_mode_and_none_hides_local_copy(): void {
		$post_id = $this->watch_post();
		update_option( 'pkiw_settings', [ 'image_handling' => 'hotlink' ] );
		$this->assertStringContainsString( self::POSTER, $this->render_card( $post_id ) );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		update_post_meta( $post_id, Featured_Artwork::SOURCE_META, self::POSTER );
		update_post_meta( $post_id, Featured_Artwork::ATTACHMENT_META, $attachment_id );
		update_option( 'pkiw_settings', [ 'image_handling' => 'none' ] );

		$html = $this->render_card( $post_id );
		$this->assertStringNotContainsString( 'class="pk-media"', $html );
		$this->assertStringNotContainsString( (string) wp_get_attachment_url( $attachment_id ), $html );
	}

	public function test_every_print_path(): void {
		$post_id = $this->watch_post();
		update_option( 'pkiw_settings', [ 'image_handling' => 'sideload' ] );
		$block = new WP_Block(
			[ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ],
			[ 'postId' => $post_id, 'postType' => 'post' ]
		);
		$block->context = [ 'postId' => $post_id, 'postType' => 'post' ];

		$recent_method = new ReflectionMethod( Recent_Kinds::class, 'get_cover_url' );
		$this->assertSame( '', $recent_method->invoke( new Recent_Kinds(), $post_id, 'watch', Meta_Fields::PREFIX ) );

		WP_Block_Supports::$block_to_render = [ 'blockName' => 'post-kinds-indieweb/now-playing' ];
		try {
			$now_playing = ( new Now_Playing() )->render( [ 'kind' => 'watch' ], '', $block );
		} finally {
			WP_Block_Supports::$block_to_render = null;
		}
		$this->assertStringContainsString( 'pk-now-playing', $now_playing );
		$this->assertStringNotContainsString( self::POSTER, $now_playing );
		$this->assertStringNotContainsString( '<img', $now_playing );

		$this->assertSame( '', ( new Block_Bindings_Source() )->get_value( [ 'key' => 'cover_image' ], $block, 'url' ) );
		$this->assertSame( '', ( new Block_Bindings() )->get_binding_value( [ 'key' => 'watch_poster' ], $block, 'url' ) );
		$this->assertSame(
			[
				'source'            => '',
				'attachment_id'     => 0,
				'url'               => '',
				'alt'               => '',
				'remote'            => false,
				'suppress_featured' => false,
			],
			PKIW\kind_picture( $post_id )
		);

		delete_post_meta( $post_id, Meta_Fields::PREFIX . 'watch_poster' );
		$this->assertNull( ( new Block_Bindings_Source() )->get_value( [ 'key' => 'cover_image' ], $block, 'url' ) );
		$this->assertNull( ( new Block_Bindings() )->get_binding_value( [ 'key' => 'watch_poster' ], $block, 'url' ) );
	}
}

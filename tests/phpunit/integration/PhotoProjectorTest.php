<?php
/**
 * Photo projector tests for issue 241.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Photo_Location;
use PKIW\Photo_Projector;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * Photo singles enhance one gallery or one loose image into projector markup.
 *
 * @group integration
 */
final class PhotoProjectorTest extends WP_UnitTestCase {

	/**
	 * Optional render_block stub.
	 *
	 * @var callable|null
	 */
	private $render_block_stub = null;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		Photo_Projector::reset();
		Photo_Location::reset();
	}

	public function tear_down(): void {
		if ( null !== $this->render_block_stub ) {
			remove_filter( 'render_block', $this->render_block_stub, 10 );
			$this->render_block_stub = null;
		}
		wp_dequeue_script( 'pkiw-photo-projector' );
		wp_dequeue_style( 'pkiw-photo-projector' );
		wp_set_current_user( 0 );
		Photo_Projector::reset();
		parent::tear_down();
	}

	/**
	 * A published post assigned to a kind.
	 *
	 * @param string               $kind    Kind slug.
	 * @param string               $content Content.
	 * @param array<string, mixed> $args    Post arguments.
	 * @return int Post ID.
	 */
	private function post_of_kind( string $kind, string $content, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Gallery Night',
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	/**
	 * A photo post.
	 *
	 * @param string               $content Content.
	 * @param array<string, mixed> $args    Post arguments.
	 * @return int Post ID.
	 */
	private function photo( string $content, array $args = [] ): int {
		return $this->post_of_kind( 'photo', $content, $args );
	}

	/**
	 * An attachment row with responsive metadata but no files on disk.
	 *
	 * @param string $alt     Alt text.
	 * @param string $excerpt Caption.
	 * @param string $file    Relative upload path.
	 * @return int Attachment ID.
	 */
	private function attachment( string $alt = '', string $excerpt = '', string $file = '' ): int {
		$file = '' === $file ? '2026/08/photo-' . wp_generate_password( 8, false ) . '.jpg' : $file;
		$id   = (int) self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => $excerpt,
			]
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		update_post_meta(
			$id,
			'_wp_attachment_metadata',
			[
				'width'  => 1600,
				'height' => 1000,
				'file'   => $file,
				'sizes'  => [
					'medium'    => [
						'file'      => 'medium-' . basename( $file ),
						'width'     => 300,
						'height'    => 188,
						'mime-type' => 'image/jpeg',
					],
					'thumbnail' => [
						'file'      => 'thumb-' . basename( $file ),
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/jpeg',
					],
				],
			]
		);

		return $id;
	}

	/**
	 * A core/image block.
	 *
	 * @param int    $id      Attachment ID, or 0 for remote.
	 * @param string $src     Image source.
	 * @param string $alt     Alt text.
	 * @param string $caption Figcaption HTML.
	 * @param string $extra   Extra attributes for the img tag.
	 * @param string $attrs   Extra block JSON fragment.
	 * @return string Block markup.
	 */
	private function image_block( int $id, string $src = '', string $alt = '', string $caption = '', string $extra = '', string $attrs = '' ): string {
		$src        = '' === $src && $id > 0 ? (string) wp_get_attachment_url( $id ) : $src;
		$json       = $id > 0 ? '{"id":' . $id . $attrs . '}' : ( '' === $attrs ? '' : '{' . ltrim( $attrs, ',' ) . '}' );
		$serialized = '' === $json ? '' : ' ' . $json;
		$class      = $id > 0 ? ' class="wp-image-' . $id . '"' : '';
		$cap        = '' === $caption ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';

		return '<!-- wp:image' . $serialized . ' --><figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"' . $class . $extra . '/>' . $cap . '</figure><!-- /wp:image -->';
	}

	/**
	 * A core/gallery block.
	 *
	 * @param string[] $images  Inner image blocks.
	 * @param string   $caption Gallery caption.
	 * @return string Block markup.
	 */
	private function gallery_block( array $images, string $caption = '' ): string {
		$cap = '' === $caption ? '' : '<figcaption class="blocks-gallery-caption wp-element-caption">' . $caption . '</figcaption>';

		return '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . implode( '', $images ) . $cap . '</figure><!-- /wp:gallery -->';
	}

	/**
	 * Serve a singular post and render its content.
	 *
	 * @param int $id Post ID.
	 */
	private function serve( int $id ): string {
		$this->go_to( (string) get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		wp_reset_postdata();

		return $html;
	}

	/**
	 * XPath for rendered HTML.
	 *
	 * @param string $html HTML.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Add the Outpost-style caption stub.
	 */
	private function add_caption_stub(): void {
		$this->render_block_stub = static function ( string $html, array $block ): string {
			if ( 'core/image' !== ( $block['blockName'] ?? '' ) || str_contains( $html, '<figcaption' ) ) {
				return $html;
			}
			$id      = (int) ( $block['attrs']['id'] ?? 0 );
			$caption = $id > 0 ? trim( (string) get_post_field( 'post_excerpt', $id ) ) : '';
			if ( '' === $caption ) {
				return $html;
			}

			return preg_replace( '#</figure>\s*$#', '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption></figure>', $html ) ?? $html;
		};
		add_filter( 'render_block', $this->render_block_stub, 10, 2 );
	}

	public function test_only_the_first_qualifying_gallery_becomes_the_projector(): void {
		$one = $this->attachment();
		$two = [ $this->attachment(), $this->attachment(), $this->attachment() ];
		$id  = $this->photo( $this->gallery_block( [ $this->image_block( $one ) ] ) . $this->gallery_block( array_map( [ $this, 'image_block' ], $two ) ) );

		$html = $this->serve( $id );

		$this->assertSame( 1, substr_count( $html, 'class="pkiw-projector' ) );
		$this->assertSame( 3, $this->xpath( $html )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector__mount ")]' )->length );
		$this->assertStringContainsString( 'wp-block-gallery', $html );
	}

	public function test_saved_child_order_beats_attachment_id_order(): void {
		$a  = $this->attachment();
		$b  = $this->attachment();
		$c  = $this->attachment();
		$d  = $this->attachment();
		$id = $this->photo( $this->gallery_block( [ $this->image_block( $c ), $this->image_block( $a ), $this->image_block( $d ), $this->image_block( $b ) ] ) );

		preg_match_all( '/wp-image-(\d+)/', $this->serve( $id ), $matches );

		$this->assertSame( [ $c, $a, $d, $b ], array_map( 'intval', array_slice( $matches[1], 0, 4 ) ) );
	}

	public function test_projector_images_keep_u_photo_and_srcset(): void {
		$ids = [ $this->attachment(), $this->attachment() ];
		$id  = $this->photo( $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) ) );
		$html = $this->serve( $id );

		$this->assertSame( 2, $this->xpath( $html )->query( '//img[contains(concat(" ", normalize-space(@class), " "), " u-photo ")]' )->length );
		$this->assertStringContainsString( 'srcset=', $html );
	}

	public function test_caption_precedence_and_alt_preservation(): void {
		$block_caption = $this->attachment( 'Block alt', 'Attachment excerpt' );
		$excerpt       = $this->attachment( 'Excerpt alt', 'Attachment caption' );
		$none          = $this->attachment( '', '' );
		$id            = $this->photo(
			$this->gallery_block(
				[
					$this->image_block( $block_caption, '', 'Block alt', 'Block caption' ),
					$this->image_block( $excerpt, '', 'Excerpt alt' ),
					$this->image_block( $none, '', '' ),
				]
			)
		);
		$html          = $this->serve( $id );

		$this->assertStringContainsString( 'Block caption', $html );
		$this->assertStringNotContainsString( 'Attachment excerpt', $html );
		$this->assertStringContainsString( 'Attachment caption', $html );
		$this->assertSame( 2, $this->xpath( $html )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector__mount ")]//figcaption' )->length );
		$this->assertStringContainsString( 'alt="Block alt"', $html );
		$this->assertStringContainsString( 'alt=""', $html );
	}

	public function test_outpost_style_captions_are_not_duplicated(): void {
		$this->add_caption_stub();
		$ids = [ $this->attachment( '', 'One' ), $this->attachment( '', 'Two' ), $this->attachment( '', 'Three' ) ];
		$id  = $this->photo( $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) ) );
		$html = $this->serve( $id );

		$xpath  = $this->xpath( $html );
		$mounts = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector__mount ")]' );
		$this->assertSame( 3, $mounts->length );
		foreach ( $mounts as $mount ) {
			$this->assertSame( 1, $xpath->query( './/figcaption', $mount )->length );
		}

		$single = $this->photo( $this->image_block( $ids[0] ) );
		$this->assertSame( 1, substr_count( $this->serve( $single ), '<figcaption' ) );
	}

	public function test_server_projector_has_no_controls_or_links_and_uses_medium_thumbnails(): void {
		$ids  = [ $this->attachment(), $this->attachment() ];
		$id   = $this->photo( $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) ) );
		$html = $this->serve( $id );

		$xpath      = $this->xpath( $html );
		$projectors = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector ")]' );
		$this->assertSame( 1, $projectors->length );
		$this->assertSame( 0, $xpath->query( './/button | .//a', $projectors->item( 0 ) )->length, 'The projector prints no button and no link; the entry wrapper\'s hidden u-url sits outside it.' );
		foreach ( $ids as $attachment_id ) {
			$medium = (string) wp_get_attachment_image_url( $attachment_id, 'medium' );
			$this->assertStringContainsString( 'data-pkiw-thumb-src="' . esc_url( $medium ) . '"', $html );
			$this->assertStringNotContainsString( 'thumb-' . basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ), $html );
		}
	}

	public function test_loading_rules_for_local_and_remote_first_images(): void {
		$ids = [ $this->attachment(), $this->attachment(), $this->attachment() ];
		$id  = $this->photo( $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) ) );
		$html = $this->serve( $id );

		$first = $this->xpath( $html )->query( '//img[contains(concat(" ", normalize-space(@class), " "), " wp-image-' . $ids[0] . ' ")]' )->item( 0 );
		$this->assertSame( 'eager', $first->getAttribute( 'loading' ) );
		$this->assertSame( 'high', $first->getAttribute( 'fetchpriority' ) );
		$this->assertStringNotContainsString( 'auto', $first->getAttribute( 'sizes' ), 'The eager slide carries no auto sizes; the lazy ones may.' );
		$this->assertSame( 2, substr_count( $html, 'loading="lazy"' ) );

		$remote = $this->photo(
			$this->gallery_block(
				[
					$this->image_block( 0, 'https://photos.example.net/remote.jpg', 'Remote', '', ' loading="auto"' ),
					$this->image_block( $ids[1] ),
				]
			)
		);
		$remote_html = $this->serve( $remote );
		$this->assertStringNotContainsString( 'fetchpriority="high"', $remote_html );
		$this->assertStringContainsString( 'loading="auto"', $remote_html );
		$this->assertSame( 1, substr_count( $remote_html, 'loading="lazy"' ) );
	}

	public function test_region_labelling_rules(): void {
		$ids     = [ $this->attachment(), $this->attachment() ];
		$content = $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) );
		$titled  = $this->photo( $content, [ 'post_title' => 'Gallery Night' ] );
		$this->assertStringContainsString( 'role="region"', $this->serve( $titled ) );
		$this->assertStringContainsString( 'aria-roledescription="carousel"', $this->serve( $titled ) );
		$this->assertStringContainsString( 'aria-label="Gallery Night"', $this->serve( $titled ) );

		$untitled = $this->photo( $content, [ 'post_title' => '' ] );
		$this->assertStringContainsString( 'aria-label="Photo"', $this->serve( $untitled ) );

		$seen   = [];
		$filter = static function ( string $id, int $post_id ) use ( &$seen ): string {
			$seen[] = [ $id, $post_id ];
			return 'cr-photo-title';
		};
		add_filter( Photo_Projector::LABELLEDBY_FILTER, $filter, 10, 2 );
		$labelledby = $this->serve( $titled );
		remove_filter( Photo_Projector::LABELLEDBY_FILTER, $filter, 10 );

		$this->assertSame( [ [ '', $titled ] ], $seen );
		$this->assertStringContainsString( 'aria-labelledby="cr-photo-title"', $labelledby );
		$this->assertStringNotContainsString( 'aria-label=', $labelledby );
	}

	public function test_location_generated_projector_label_uses_the_safe_title(): void {
		$ids = [ $this->attachment(), $this->attachment() ];
		$id  = $this->photo( $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) ), [ 'post_title' => 'Late Light at Example Cafe' ] );
		update_post_meta( $id, Title_Privacy::META_KEY, Title_Privacy::SOURCE_LOCATION );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		$html = $this->serve( $id );

		$this->assertStringContainsString( 'aria-label="' . esc_attr( get_the_title( $id ) ) . '"', $html );
		$this->assertStringNotContainsString( 'aria-label="Late Light at Example Cafe"', $html );
	}

	public function test_duotone_image_keeps_its_markup(): void {
		$id      = $this->attachment();
		$content = $this->image_block( $id, '', 'Duotone', '', ' class="wp-image-' . $id . ' wp-duotone-test"', ',"style":{"color":{"duotone":["black","white"]}}' );
		$post_id = $this->photo( $content );

		$html = $this->serve( $post_id );

		$this->assertMatchesRegularExpression( '/wp-duotone-[a-z-]+-\d+/', $html, 'Core\'s duotone class survives the mount rewrite.' );
		$this->assertStringContainsString( 'pkiw-projector__mount', $html );
		$this->assertStringContainsString( 'u-photo', $html );
	}

	public function test_single_image_gets_one_mount_without_gallery_chrome(): void {
		$id      = $this->attachment( '', 'Attachment caption' );
		$post_id = $this->photo( $this->image_block( $id ) );
		$html    = $this->serve( $post_id );

		$this->assertSame( 1, $this->xpath( $html )->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector__mount ")]' )->length );
		$this->assertStringNotContainsString( 'class="pkiw-projector', $html );
		$this->assertStringNotContainsString( '<button', $html );
		$this->assertStringContainsString( 'u-photo', $html );
		$this->assertSame( 1, substr_count( $html, '<figcaption' ) );
	}

	public function test_two_loose_images_get_no_projector_or_mount_and_no_script(): void {
		$ids  = [ $this->attachment(), $this->attachment() ];
		$id   = $this->photo( $this->image_block( $ids[0] ) . $this->image_block( $ids[1] ) );
		$html = $this->serve( $id );

		$this->assertStringNotContainsString( 'pkiw-projector', $html );
		$this->assertStringNotContainsString( 'pkiw-projector__mount', $html );
		$this->assertFalse( wp_script_is( 'pkiw-photo-projector', 'enqueued' ) );
	}

	public function test_non_photo_and_protected_posts_are_untouched(): void {
		$ids     = [ $this->attachment(), $this->attachment() ];
		$content = $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) );
		$note    = $this->post_of_kind( 'note', $content );
		$this->assertStringNotContainsString( 'pkiw-projector', $this->serve( $note ) );

		$protected = $this->photo( $content, [ 'post_password' => 'secret' ] );
		$this->assertStringNotContainsString( 'pkiw-projector', $this->serve( $protected ) );
	}

	public function test_assets_enqueue_only_when_needed_and_are_registered_correctly(): void {
		$ids = [ $this->attachment(), $this->attachment() ];
		$this->assertTrue( wp_style_is( 'pkiw-photo-projector', 'registered' ) );

		$single = $this->photo( $this->image_block( $ids[0] ) );
		$this->serve( $single );
		$this->assertFalse( wp_script_is( 'pkiw-photo-projector', 'enqueued' ) );

		$loose = $this->photo( $this->image_block( $ids[0] ) . $this->image_block( $ids[1] ) );
		$this->serve( $loose );
		$this->assertFalse( wp_script_is( 'pkiw-photo-projector', 'enqueued' ) );

		$gallery = $this->photo( $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) ) );
		$this->serve( $gallery );
		$this->assertTrue( wp_script_is( 'pkiw-photo-projector', 'enqueued' ) );

		$script = wp_scripts()->registered['pkiw-photo-projector'];
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'defer', $script->extra['strategy'] ?? '' );
		$this->assertSame( 1, $script->extra['group'] ?? 0 );
	}

	public function test_a_gallery_with_one_image_stays_core_gallery_markup(): void {
		$id = $this->photo( $this->gallery_block( [ $this->image_block( $this->attachment() ) ] ) );

		$html = $this->serve( $id );

		$this->assertStringContainsString( 'wp-block-gallery', $html );
		$this->assertStringNotContainsString( 'pkiw-projector', $html );
	}

	public function test_gallery_of_a_second_photo_is_not_projected_while_another_post_is_queried(): void {
		$ids     = [ $this->attachment(), $this->attachment() ];
		$content = $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) );
		$queried = $this->photo( '<!-- wp:paragraph --><p>Main.</p><!-- /wp:paragraph -->' );
		$other   = $this->photo( $content );

		$this->go_to( (string) get_permalink( $queried ) );
		$GLOBALS['post'] = get_post( $other );
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', $content );
		wp_reset_postdata();

		$this->assertStringNotContainsString( 'pkiw-projector', $html );
		$this->assertFalse( wp_script_is( 'pkiw-photo-projector', 'enqueued' ) );
	}

	public function test_gallery_on_a_photo_comments_feed_is_not_projected(): void {
		$ids     = [ $this->attachment(), $this->attachment() ];
		$content = $this->gallery_block( array_map( [ $this, 'image_block' ], $ids ) );
		$id      = $this->photo( $content );

		$this->go_to( get_post_comments_feed_link( $id ) );
		$this->assertTrue( is_feed() );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', $content );
		wp_reset_postdata();

		$this->assertStringNotContainsString( 'pkiw-projector', $html );
	}
}

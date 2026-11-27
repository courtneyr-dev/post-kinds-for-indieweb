<?php
/**
 * Photo projector and the attached-media Gallery.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Photo_Location;
use PKIW\Photo_Projector;
use PKIW\Taxonomy;

/**
 * A Gallery block whose images come from the media attached to the post
 * (dynamicContent source core/attached-media, WordPress 7.1+) projects like a
 * static Gallery, only from the post's own content, only outside a Query Loop,
 * never with someone else's attachment, and never twice.
 *
 * @group integration
 */
final class PhotoProjectorAttachedGalleryTest extends WP_UnitTestCase {

	private const MOUNTS = '//figure[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector__mount ")]';

	/**
	 * Attachment the photo must never show: attached to a private, unrelated post.
	 *
	 * @var int
	 */
	private int $control = 0;

	/**
	 * Optional render_block_core/gallery stub.
	 *
	 * @var callable|null
	 */
	private $gallery_stub = null;

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'block_core_gallery_resolve_dynamic_source' ) ) {
			$this->markTestSkipped( 'This WordPress has no dynamic Gallery.' );
		}
		( new Taxonomy() )->create_default_terms();
		Photo_Projector::reset();
		Photo_Location::reset();

		$private       = $this->photo( '<!-- wp:paragraph --><p>Private.</p><!-- /wp:paragraph -->', [ 'post_status' => 'private' ] );
		$this->control = $this->attachment( $private, '2026-08-01 12:00:00', 'private-control' );
	}

	public function tear_down(): void {
		if ( null !== $this->gallery_stub ) {
			remove_filter( 'render_block_core/gallery', $this->gallery_stub, 5 );
			$this->gallery_stub = null;
		}
		wp_dequeue_script( 'pkiw-photo-projector' );
		wp_dequeue_style( 'pkiw-photo-projector' );
		wp_reset_postdata();
		Photo_Projector::reset();
		parent::tear_down();
	}

	/**
	 * A published photo post.
	 *
	 * @param string               $content Content.
	 * @param array<string, mixed> $args    Post arguments.
	 * @return int Post ID.
	 */
	private function photo( string $content, array $args = [] ): int {
		$id = (int) self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Harbor walk',
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'photo', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * An image attachment with responsive metadata and no file on disk.
	 *
	 * @param int    $parent Parent post, or 0.
	 * @param string $date   Upload date, which sets Core's attached-media order.
	 * @param string $slug   File name stem.
	 * @return int Attachment ID.
	 */
	private function attachment( int $parent, string $date, string $slug = '' ): int {
		$file = '2026/08/' . ( '' === $slug ? 'photo-' . wp_generate_password( 8, false ) : $slug ) . '.jpg';
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
				'sizes'  => [
					'medium' => [
						'file'      => 'medium-' . basename( $file ),
						'width'     => 300,
						'height'    => 188,
						'mime-type' => 'image/jpeg',
					],
				],
			]
		);

		return $id;
	}

	/**
	 * Three images attached to the post, newest last.
	 *
	 * @param int $parent Post ID.
	 * @return int[] IDs in Core's attached-media order (newest first).
	 */
	private function attach_three( int $parent ): array {
		$a = $this->attachment( $parent, '2026-08-02 09:00:00' );
		$b = $this->attachment( $parent, '2026-08-03 09:00:00' );
		$c = $this->attachment( $parent, '2026-08-04 09:00:00' );

		return [ $c, $b, $a ];
	}

	/**
	 * A Gallery that shows the post's attached media.
	 */
	private function attached_gallery(): string {
		return '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"},"linkTo":"none"} /-->';
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

	/**
	 * A Query Loop of photo posts whose template holds the given blocks.
	 *
	 * @param string $inner Post template contents.
	 */
	private function query_loop( string $inner ): string {
		return '<!-- wp:query {"queryId":7,"query":{"perPage":10,"postType":"post","inherit":false,"order":"desc","orderBy":"date"}} --><div class="wp-block-query"><!-- wp:post-template -->' . $inner . '<!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * Load the singular page for a post.
	 *
	 * @param int $id Post ID.
	 */
	private function go_to_single( int $id ): void {
		$this->go_to( (string) get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * Serve a singular post and render its content.
	 *
	 * @param int $id Post ID.
	 */
	private function serve( int $id ): string {
		$this->go_to_single( $id );
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
	 * Attachment IDs of the projector mounts, in order.
	 *
	 * @param string $html HTML.
	 * @return int[]
	 */
	private function mount_ids( string $html ): array {
		$ids = [];
		foreach ( $this->xpath( $html )->query( self::MOUNTS . '//img' ) as $img ) {
			if ( $img instanceof DOMElement && preg_match( '/\bwp-image-(\d+)\b/', $img->getAttribute( 'class' ), $m ) ) {
				$ids[] = (int) $m[1];
			}
		}

		return $ids;
	}

	/**
	 * What a projector shows, independent of how the Gallery was authored.
	 *
	 * @param string $html HTML.
	 * @return array<string, mixed>
	 */
	private function projector_shape( string $html ): array {
		$xpath = $this->xpath( $html );

		return [
			'projectors' => $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " pkiw-projector ")]' )->length,
			'carousel'   => $xpath->query( '//div[@role="region"][@aria-roledescription="carousel"]' )->length,
			'mounts'     => $this->mount_ids( $html ),
			'u_photo'    => $xpath->query( '//img[contains(concat(" ", normalize-space(@class), " "), " u-photo ")]' )->length,
			'thumbs'     => $xpath->query( self::MOUNTS . '[@data-pkiw-thumb-src]' )->length,
			'eager'      => $xpath->query( self::MOUNTS . '//img[@loading="eager"]' )->length,
		];
	}

	/**
	 * Assert the private control attachment is nowhere in the HTML.
	 *
	 * @param string $html HTML.
	 */
	private function assert_no_control( string $html ): void {
		$this->assertStringNotContainsString( 'private-control', $html, 'The unrelated private attachment never renders.' );
		$this->assertStringNotContainsString( 'wp-image-' . $this->control . '"', $html );
	}

	public function test_attached_media_gallery_becomes_the_projector(): void {
		$id    = $this->photo( $this->attached_gallery() );
		$order = $this->attach_three( $id );

		$html = $this->serve( $id );

		$this->assertSame(
			[
				'projectors' => 1,
				'carousel'   => 1,
				'mounts'     => $order,
				'u_photo'    => 3,
				'thumbs'     => 3,
				'eager'      => 1,
			],
			$this->projector_shape( $html )
		);
		$this->assertTrue( wp_script_is( 'pkiw-photo-projector', 'enqueued' ) );
		$this->assert_no_control( $html );
	}

	public function test_static_and_attached_galleries_project_the_same_photos(): void {
		$dynamic = $this->photo( $this->attached_gallery() );
		$order   = $this->attach_three( $dynamic );
		$static  = $this->photo( $this->static_gallery( $order ) );

		$dynamic_html = $this->serve( $dynamic );
		Photo_Projector::reset();
		$static_html = $this->serve( $static );

		$this->assertSame( $this->projector_shape( $static_html ), $this->projector_shape( $dynamic_html ) );
		$this->assert_no_control( $dynamic_html );
		$this->assert_no_control( $static_html );
	}

	public function test_a_private_attachment_of_the_photo_itself_is_not_shown(): void {
		$id      = $this->photo( $this->attached_gallery() );
		$order   = $this->attach_three( $id );
		$private = $this->attachment( $id, '2026-08-05 09:00:00', 'private-own' );
		wp_update_post(
			[
				'ID'          => $private,
				'post_status' => 'private',
			]
		);

		$html = $this->serve( $id );

		$this->assertSame( $order, $this->mount_ids( $html ) );
		$this->assertStringNotContainsString( 'private-own', $html );
	}

	public function test_one_attached_image_stays_core_gallery_markup(): void {
		$id = $this->photo( $this->attached_gallery() );
		$this->attachment( $id, '2026-08-02 09:00:00' );

		$html = $this->serve( $id );

		$this->assertStringContainsString( 'wp-block-gallery', $html );
		$this->assertStringNotContainsString( 'pkiw-projector', $html );
		$this->assertStringNotContainsString( 'u-photo', $html );
	}

	public function test_a_figure_not_attached_to_the_post_stops_the_projector(): void {
		$id = $this->photo( $this->attached_gallery() );
		$this->attach_three( $id );
		$control            = $this->control;
		$this->gallery_stub = static function ( string $html ) use ( $control ): string {
			$extra = '<figure class="wp-block-image"><img src="https://example.org/x.jpg" class="wp-image-' . $control . '" alt=""/></figure>';
			return (string) preg_replace( '#</figure>\s*$#', $extra . '</figure>', $html );
		};
		add_filter( 'render_block_core/gallery', $this->gallery_stub, 5 );

		$html = $this->serve( $id );

		$this->assertStringNotContainsString( 'pkiw-projector', $html, 'A Gallery showing someone else\'s attachment is left to Core.' );
		$this->assertStringNotContainsString( 'u-photo', $html );
	}

	public function test_attached_gallery_then_static_gallery_projects_once(): void {
		$id     = $this->photo( '' );
		$order  = $this->attach_three( $id );
		$others = [ $this->attachment( 0, '2026-08-06 09:00:00' ), $this->attachment( 0, '2026-08-07 09:00:00' ) ];
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $this->attached_gallery() . $this->static_gallery( $others ),
			]
		);

		$html = $this->serve( $id );

		$shape = $this->projector_shape( $html );
		$this->assertSame( 1, $shape['projectors'] );
		$this->assertSame( $order, $shape['mounts'] );
		$this->assertSame( 3, $shape['u_photo'], 'Only the projected photos carry u-photo.' );
	}

	public function test_attached_gallery_outside_the_post_content_stays_plain(): void {
		$id = $this->photo( $this->attached_gallery() );
		$this->attach_three( $id );

		$this->go_to_single( $id );
		$template = do_blocks( $this->attached_gallery() );
		$content  = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		wp_reset_postdata();

		$this->assertStringContainsString( 'wp-block-gallery', $template, 'Core still renders the template Gallery.' );
		$this->assertStringNotContainsString( 'pkiw-projector', $template );
		$this->assertStringNotContainsString( 'u-photo', $template );
		$this->assertSame( 1, $this->projector_shape( $content )['projectors'], 'The post\'s own Gallery still projects.' );
	}

	/**
	 * @return array<string, array{0:bool}>
	 */
	public function gallery_kinds(): array {
		return [
			'static Gallery'         => [ false ],
			'attached-media Gallery' => [ true ],
		];
	}

	/**
	 * A Query Loop on the photo's own page lists posts; it never projects,
	 * even when it renders the photo's own content, so the page carries one
	 * projector and one set of u-photo images.
	 *
	 * @dataProvider gallery_kinds
	 *
	 * @param bool $dynamic Whether the photo uses an attached-media Gallery.
	 */
	public function test_post_content_inside_a_query_loop_never_projects( bool $dynamic ): void {
		$id    = $this->photo( '' );
		$order = $this->attach_three( $id );
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $dynamic ? $this->attached_gallery() : $this->static_gallery( $order ),
			]
		);
		$other = $this->photo( $this->attached_gallery(), [ 'post_date' => '2020-01-01 00:00:00' ] );
		$this->attach_three( $other );

		$this->go_to_single( $id );
		$main = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		$loop = do_blocks( $this->query_loop( '<!-- wp:post-content /-->' ) );
		wp_reset_postdata();

		$this->assertSame( 1, $this->projector_shape( $main )['projectors'] );
		$this->assertSame( $order, $this->mount_ids( $main ) );
		$this->assertSame( 0, $this->projector_shape( $loop )['projectors'], 'Listed posts keep Core Gallery markup.' );
		$this->assertSame( 0, $this->projector_shape( $loop )['u_photo'], 'No duplicate u-photo in the loop.' );
		$this->assertSame( 2, $this->xpath( $loop )->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " wp-block-gallery ")]' )->length, 'Both listed photos still show their Gallery.' );
		$this->assert_no_control( $main . $loop );
	}

	public function test_attached_gallery_in_a_query_loop_template_shows_each_posts_own_media(): void {
		$id      = $this->photo( '<!-- wp:paragraph --><p>Main.</p><!-- /wp:paragraph -->' );
		$mine    = $this->attach_three( $id );
		$other   = $this->photo( '<!-- wp:paragraph --><p>Other.</p><!-- /wp:paragraph -->', [ 'post_date' => '2020-01-01 00:00:00' ] );
		$theirs  = $this->attach_three( $other );

		$this->go_to_single( $id );
		$loop = do_blocks( $this->query_loop( $this->attached_gallery() ) );
		wp_reset_postdata();

		preg_match_all( '/wp-image-(\d+)/', $loop, $matches );
		$this->assertSame( array_merge( $mine, $theirs ), array_map( 'intval', $matches[1] ), 'Each loop item shows its own attached media, in Core order.' );
		$this->assertStringNotContainsString( 'pkiw-projector', $loop );
		$this->assertStringNotContainsString( 'u-photo', $loop );
		$this->assert_no_control( $loop );
	}

	/**
	 * A Gallery inside a Query Loop in the post body never wins the plan, so
	 * the post's own Gallery after it still projects.
	 *
	 * @dataProvider gallery_kinds
	 *
	 * @param bool $dynamic Whether the post's own Gallery is attached-media.
	 */
	public function test_a_loop_gallery_before_the_posts_own_gallery_does_not_block_it( bool $dynamic ): void {
		$id     = $this->photo( '' );
		$order  = $this->attach_three( $id );
		$others = [ $this->attachment( 0, '2026-08-06 09:00:00' ), $this->attachment( 0, '2026-08-07 09:00:00' ) ];
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $this->query_loop( $this->static_gallery( $others ) ) . ( $dynamic ? $this->attached_gallery() : $this->static_gallery( $order ) ),
			]
		);

		$html = $this->serve( $id );

		$shape = $this->projector_shape( $html );
		$this->assertSame( 1, $shape['projectors'], 'The post body Gallery projects.' );
		$this->assertSame( $order, $shape['mounts'] );
		$this->assertSame( 3, $shape['u_photo'] );
	}

	public function test_oldest_first_gallery_with_101_attachments_projects_cores_first_100(): void {
		$id  = $this->photo( '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media","args":{"order":"asc"}},"linkTo":"none"} /-->' );
		$ids = [];
		for ( $i = 0; $i < 101; $i++ ) {
			$ids[] = $this->attachment( $id, gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00' ) + $i * 3600 ) );
		}

		$html = $this->serve( $id );

		$shape = $this->projector_shape( $html );
		$this->assertSame( 1, $shape['projectors'], 'Core shows the oldest 100, and so does the allowlist.' );
		$this->assertSame( array_slice( $ids, 0, 100 ), $shape['mounts'] );
	}

	/**
	 * @return array<string, array{0:bool}>
	 */
	public function password_states(): array {
		return [
			'logged-out visitor'           => [ false ],
			'visitor with the password'    => [ true ],
		];
	}

	/**
	 * Core's attached-media query inherits attachments without checking the
	 * parent's password, so a template Gallery in a public loop must not list
	 * a protected post's images until the visitor has the password.
	 *
	 * @dataProvider password_states
	 *
	 * @param bool $has_password Whether the visitor sent the post password.
	 */
	public function test_attached_gallery_in_a_loop_hides_a_protected_posts_media( bool $has_password ): void {
		$page      = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		$protected = $this->photo( '<!-- wp:paragraph --><p>Secret.</p><!-- /wp:paragraph -->', [ 'post_password' => 'harbor' ] );
		$hidden    = $this->attach_three( $protected );
		wp_update_post(
			[
				'ID'         => $hidden[0],
				'post_excerpt' => 'secret-caption',
			]
		);
		if ( $has_password ) {
			require_once ABSPATH . WPINC . '/class-phpass.php';
			$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'harbor' );
		}

		$this->go_to( (string) get_permalink( $page ) );
		$loop = do_blocks( $this->query_loop( $this->attached_gallery() ) );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );

		foreach ( $hidden as $attachment ) {
			$url = (string) wp_get_attachment_url( $attachment );
			if ( $has_password ) {
				$this->assertStringContainsString( 'wp-image-' . $attachment . '"', $loop );
			} else {
				$this->assertStringNotContainsString( 'wp-image-' . $attachment . '"', $loop );
				$this->assertStringNotContainsString( $url, $loop );
			}
		}
		if ( $has_password ) {
			$this->assertStringContainsString( 'secret-caption', $loop );
		} else {
			$this->assertStringNotContainsString( 'secret-caption', $loop );
		}
	}

	/**
	 * @return array<string, array{0:bool}>
	 */
	public function block_orders(): array {
		return [
			'Image then Gallery' => [ true ],
			'Gallery then Image' => [ false ],
		];
	}

	/**
	 * One attached image shown by both a standalone Image and an
	 * attached-media Gallery carries u-photo once.
	 *
	 * @dataProvider block_orders
	 *
	 * @param bool $image_first Whether the Image block comes first.
	 */
	public function test_one_image_in_an_image_block_and_an_attached_gallery_is_one_u_photo( bool $image_first ): void {
		$id    = $this->photo( '' );
		$a     = $this->attachment( $id, '2026-08-02 09:00:00' );
		$image = '<!-- wp:image {"id":' . $a . '} --><figure class="wp-block-image"><img src="' . esc_url( (string) wp_get_attachment_url( $a ) ) . '" alt="" class="wp-image-' . $a . '"/></figure><!-- /wp:image -->';
		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => $image_first ? $image . $this->attached_gallery() : $this->attached_gallery() . $image,
			]
		);

		$html = $this->serve( $id );

		$shape = $this->projector_shape( $html );
		$this->assertSame( 1, $shape['u_photo'], 'One photo, one u-photo.' );
		$this->assertSame( 1, $this->xpath( $html )->query( self::MOUNTS )->length );
		$this->assertSame( 1, $this->xpath( $html )->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " wp-block-gallery ")]' )->length, 'Core still renders the Gallery.' );
	}
}

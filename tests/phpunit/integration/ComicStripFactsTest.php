<?php
/**
 * Drawn comic strip facts (issue 266).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Comic_Strip;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicStripFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Comic_Strip() )->register();
		Comic_Strip::reset_series_cache();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		parent::tear_down();
	}

	/**
	 * Build one saved core image block.
	 *
	 * @param int    $id         Attachment ID.
	 * @param string $alt        Saved alt text.
	 * @param string $caption    Saved caption markup.
	 * @param bool   $decorative Whether the block is decorative.
	 */
	private function image( int $id, string $alt, string $caption = '', bool $decorative = false ): string {
		$attrs = [ 'id' => $id ];
		if ( $decorative ) {
			$attrs['isDecorative'] = true;
		}
		$figcaption = '' !== $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '';

		return '<!-- wp:image ' . wp_json_encode( $attrs ) . ' --><figure class="wp-block-image"><img src="https://example.org/panel-' . $id . '.jpg" alt="' . esc_attr( $alt ) . '" />' . $figcaption . '</figure><!-- /wp:image -->';
	}

	/**
	 * Build one saved core gallery block.
	 *
	 * @param string[] $images    Serialized image blocks.
	 * @param string   $class_name Gallery className.
	 */
	private function gallery( array $images, string $class_name = '' ): string {
		$attrs = [];
		if ( '' !== $class_name ) {
			$attrs['className'] = $class_name;
		}
		$json = [] !== $attrs ? ' ' . wp_json_encode( $attrs ) : '';

		return '<!-- wp:gallery' . $json . ' --><figure class="wp-block-gallery has-nested-images">' . implode( '', $images ) . '</figure><!-- /wp:gallery -->';
	}

	/**
	 * Create a comics post with authored strip content.
	 *
	 * @param string               $content Saved block content.
	 * @param array<string, mixed> $args    Post overrides.
	 */
	private function strip( string $content, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Drawn strip',
					'post_content' => $content,
				],
				$args
			)
		);
		wp_set_object_terms( $id, Comic_Strip::KIND, Taxonomy::TAXONOMY );
		update_post_meta( $id, Comic_Strip::SERIES_META, '  Field Notes  ' );
		update_post_meta( $id, Comic_Strip::ISSUE_META, '  12  ' );

		return (int) $id;
	}

	public function test_styled_gallery_facts_preserve_panel_order_and_saved_text(): void {
		add_filter( 'pkiw_comic_strip_styles', static fn(): array => [ 'cr-comic-panels' ] );
		$ids = [
			self::factory()->attachment->create(),
			self::factory()->attachment->create(),
			self::factory()->attachment->create(),
			self::factory()->attachment->create(),
		];
		$content = $this->gallery(
			[
				$this->image( $ids[0], 'Opening panel', 'Avery <strong>opens</strong> the door.' ),
				$this->image( $ids[1], '  Spacing stays  ', "A line<br>break and\n whitespace." ),
				$this->image( $ids[2], '', 'A silent room.' ),
				$this->image( $ids[3], 'Ignored decorative alt', 'The end.', true ),
			],
			'is-style-cr-comic-panels'
		);
		$facts = Comic_Strip::facts( $this->strip( $content ) );

		$this->assertSame( [ 'drawn', 'series', 'issue', 'panels' ], array_keys( $facts ) );
		$this->assertTrue( $facts['drawn'] );
		$this->assertSame( 'Field Notes', $facts['series'] );
		$this->assertSame( '12', $facts['issue'] );
		$this->assertSame( [ 1, 2, 3, 4 ], wp_list_pluck( $facts['panels'], 'position' ) );
		$this->assertSame( array_map( 'intval', $ids ), wp_list_pluck( $facts['panels'], 'attachment_id' ) );
		$this->assertSame( 'https://example.org/panel-' . $ids[0] . '.jpg', $facts['panels'][0]['src'] );
		$this->assertSame( [ 'Opening panel', '  Spacing stays  ', '', '' ], wp_list_pluck( $facts['panels'], 'alt' ) );
		$this->assertSame(
			[ 'Avery opens the door.', 'A line break and whitespace.', 'A silent room.', 'The end.' ],
			wp_list_pluck( $facts['panels'], 'transcript' )
		);
		foreach ( $facts['panels'] as $panel ) {
			$this->assertSame( [ 'position', 'attachment_id', 'src', 'alt', 'transcript' ], array_keys( $panel ) );
		}
	}

	public function test_registered_style_beats_an_earlier_unstyled_gallery(): void {
		add_filter( 'pkiw_comic_strip_styles', static fn(): array => [ '', 'cr-comic-panels', 17 ] );
		$first  = self::factory()->attachment->create();
		$second = self::factory()->attachment->create();
		$priority_post = $this->strip(
			$this->gallery( [ $this->image( $first, 'Unstyled' ) ] )
			. $this->gallery( [ $this->image( $second, 'Styled' ) ], 'other is-style-cr-comic-panels' )
		);
		$order_post = $this->strip(
			$this->gallery( [ $this->image( $second, 'Styled' ) ], 'is-style-cr-comic-panels' )
			. $this->gallery( [ $this->image( $first, 'Unstyled' ) ] )
		);

		$this->assertSame( [ $second ], wp_list_pluck( Comic_Strip::facts( $priority_post )['panels'], 'attachment_id' ) );
		$this->assertSame( [ $second ], wp_list_pluck( Comic_Strip::facts( $order_post )['panels'], 'attachment_id' ) );
	}

	public function test_interactive_gallery_is_skipped_and_single_image_is_the_fallback(): void {
		$gallery_id = self::factory()->attachment->create();
		$image_id   = self::factory()->attachment->create();
		$post       = $this->strip(
			$this->gallery( [ $this->image( $gallery_id, 'Slideshow' ) ], 'is-style-lightbox-slideshow' )
			. $this->image( $image_id, 'Single panel', 'Only panel' )
		);

		$this->assertSame( [ $image_id ], wp_list_pluck( Comic_Strip::facts( $post )['panels'], 'attachment_id' ) );
	}

	public function test_group_wrapper_and_image_without_attachment_keep_saved_src(): void {
		$image = '<!-- wp:image --><figure class="wp-block-image"><img src="https://cdn.example.org/free-panel.png" alt="Remote panel" /><figcaption class="wp-element-caption">Remote transcript</figcaption></figure><!-- /wp:image -->';
		$post  = $this->strip( '<!-- wp:group --><div class="wp-block-group">' . $image . '</div><!-- /wp:group -->' );
		$panel = Comic_Strip::facts( $post )['panels'][0];

		$this->assertSame( 0, $panel['attachment_id'] );
		$this->assertSame( 'https://cdn.example.org/free-panel.png', $panel['src'] );
		$this->assertSame( 'Remote panel', $panel['alt'] );
		$this->assertSame( 'Remote transcript', $panel['transcript'] );
	}

	public function test_no_strip_and_legacy_gallery_have_empty_panel_lists(): void {
		$paragraph = $this->strip( '<!-- wp:paragraph --><p>Words only.</p><!-- /wp:paragraph -->' );
		$legacy    = $this->strip( '<!-- wp:gallery {"images":[{"id":1}]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->' );

		$this->assertSame( [], Comic_Strip::facts( $paragraph )['panels'] );
		$this->assertSame( [], Comic_Strip::facts( $legacy )['panels'] );
	}

	public function test_comic_reads_and_unreadable_strips_have_no_facts(): void {
		$card = $this->strip( '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga"} /-->' );
		$locked = $this->strip( $this->image( self::factory()->attachment->create(), 'Secret' ), [ 'post_password' => 'secret' ] );
		$private = $this->strip( $this->image( self::factory()->attachment->create(), 'Private' ), [ 'post_status' => 'private' ] );
		$draft = $this->strip( $this->image( self::factory()->attachment->create(), 'Draft' ), [ 'post_status' => 'draft' ] );

		$this->assertSame( [], Comic_Strip::facts( $card ) );
		$this->assertSame( [], Comic_Strip::facts( $locked ) );
		$this->assertSame( [], Comic_Strip::facts( $private ) );
		$this->assertSame( [], Comic_Strip::facts( $draft ) );
		$this->assertSame( [], Kind_Facts::get( $locked ) );
	}

	public function test_leftover_status_keeps_a_removed_card_off_the_drawn_contract(): void {
		$id = $this->strip( $this->image( self::factory()->attachment->create(), 'Former card' ) );
		update_post_meta( $id, Comic_Strip::STATUS_META, 'finished' );

		$this->assertFalse( Comic_Strip::is_drawn( get_post( $id ) ) );
		$this->assertSame( [], Comic_Strip::facts( $id ) );
	}

	public function test_registered_kind_reader_matches_the_comic_strip_reader(): void {
		$id = $this->strip( $this->image( self::factory()->attachment->create(), 'Registered reader' ) );

		$this->assertNotNull( Kind_Facts::reader( Comic_Strip::KIND ) );
		$this->assertSame( Comic_Strip::facts( $id ), Kind_Facts::get( $id ) );
	}

	public function test_a_card_post_with_no_stored_status_is_not_drawn_and_not_in_the_series_index(): void {
		$id = $this->strip( '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga"} /-->' );
		delete_post_meta( $id, Comic_Strip::STATUS_META );
		Comic_Strip::reset_series_cache();

		$this->assertFalse( Comic_Strip::is_drawn( get_post( $id ) ) );
		$this->assertSame( [], Comic_Strip::facts( $id ) );
		$this->assertSame( [], Comic_Strip::series_values() );
	}

	public function test_caption_text_folds_whitespace_and_trims_both_ends(): void {
		$id = $this->strip( $this->image( self::factory()->attachment->create(), 'Alt', "\n\t  First   line<br>second\n " ) );

		$this->assertSame( 'First line second', Comic_Strip::facts( $id )['panels'][0]['transcript'] );
	}
}

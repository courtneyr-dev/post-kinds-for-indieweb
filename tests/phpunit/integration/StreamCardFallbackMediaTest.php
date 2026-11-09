<?php
/**
 * Stream card fallback media coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * The PKIW fallback preserves the site oracle except for its privacy-safe title.
 *
 * @group integration
 */
final class StreamCardFallbackMediaTest extends WP_UnitTestCase {

	private const ORACLE_SHA256 = 'aa8e6ad66dc3def58a5fc0ab4a8dbbddb925fcef3b478b60358505b17c8e717d';

	private const BLOCK_NAME = 'post-kinds-indieweb/stream-card';

	private const NOTE_CARD = '<article class="pk-card pk-card--stream k-note h-entry"><div class="pk-body"><div class="pk-caption"></div><div class="pk-meta"><a class="pk-link" href="#">x</a></div></div></article>';

	private const ARTICLE_CARD = '<article class="pk-card pk-card--stream k-article h-entry"><div class="pk-body"><div class="pk-caption"></div></div></article>';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( function_exists( 'PKIW\\Tests\\StreamCardPhotoOracle\\cr_stream_card_photo_fallback' ) ) {
			return;
		}

		$source = (string) file_get_contents( self::oracle_path() );
		$source = substr( $source, 5 );

		// The hash-pinned PHP fixture is trusted test code. A namespace keeps its
		// function out of the suite's global scope and away from the live callback.
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- A byte-for-byte fixture cannot declare its own test namespace.
		eval( 'namespace PKIW\\Tests\\StreamCardPhotoOracle; use \\WP_HTML_Tag_Processor;' . $source );
		remove_filter( 'render_block', 'cr_stream_card_photo_fallback', 10 );
	}

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		WP_Block_Supports::$block_to_render = null;
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'], $GLOBALS['post'], $GLOBALS['pkiw_stream_card_root_seen'] );
		wp_reset_postdata();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private static function oracle_path(): string {
		return dirname( __DIR__ ) . '/fixtures/stream-card-photo/cr-stream-card-photo-1.1.0.php';
	}

	/**
	 * Create a published post for one Stream path.
	 *
	 * @param array<string, mixed> $args Post fields.
	 * @param string               $kind Kind slug.
	 */
	private function post( array $args = [], string $kind = 'note' ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Fallback field note',
					'post_content' => '<!-- wp:paragraph --><p>Field notes.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return $id;
	}

	private function attachment( int $post_id = 0, ?string $alt = null ): int {
		$id = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		if ( null !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * A top-level core/image block whose alt can be a valueless HTML attribute.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|true $alt           Image alt or true for a valueless alt.
	 */
	private function image_block( int $attachment_id, $alt ): string {
		$alt_html = true === $alt ? ' alt' : ' alt="' . esc_attr( $alt ) . '"';

		return '<!-- wp:image {"id":' . $attachment_id . '} -->'
			. '<figure class="wp-block-image"><img src="canola.jpg"' . $alt_html . ' class="wp-image-' . $attachment_id . '" /></figure>'
			. '<!-- /wp:image -->';
	}

	private function block( int $post_id, bool $with_context = true ): WP_Block {
		$block = new WP_Block(
			[
				'blockName'    => self::BLOCK_NAME,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$block->context = $with_context ? [ 'postId' => $post_id, 'postType' => 'post' ] : [];

		return $block;
	}

	/**
	 * Render while satisfying the WordPress 7.0 block-supports precondition.
	 *
	 * @param callable(): string $render Render callback.
	 */
	private function with_block_supports( callable $render ): string {
		WP_Block_Supports::$block_to_render = [ 'blockName' => self::BLOCK_NAME ];
		try {
			return $render();
		} finally {
			WP_Block_Supports::$block_to_render = null;
		}
	}

	private function inner( WP_Block $block ): string {
		return $this->with_block_supports(
			static fn(): string => \PKIW\render_stream_card_inner( [], '', $block )
		);
	}

	private function rendered( WP_Block $block ): string {
		return $this->with_block_supports(
			static fn(): string => \PKIW\render_stream_card( [], '', $block )
		);
	}

	private function oracle( string $html, WP_Block $block ): string {
		return \PKIW\Tests\StreamCardPhotoOracle\cr_stream_card_photo_fallback(
			$html,
			[ 'blockName' => self::BLOCK_NAME ],
			$block
		);
	}

	private function assert_function_matches_oracle( string $html, int $post_id ): string {
		$block    = $this->block( $post_id );
		$expected = $this->oracle( $html, $block );
		$actual   = \PKIW\stream_card_fallback_media( $html, $post_id );
		$this->assertSame( $expected, $actual );

		return $actual;
	}

	private function assert_render_matches_oracle( int $post_id ): string {
		$block    = $this->block( $post_id );
		$expected = $this->oracle( $this->inner( $block ), $block );
		$actual   = $this->rendered( $block );
		$this->assertSame( $expected, $actual );

		return $actual;
	}

	public function test_oracle_fixture_hash_is_pinned(): void {
		$this->assertSame( self::ORACLE_SHA256, hash_file( 'sha256', self::oracle_path() ) );
	}

	public function test_top_level_image_uses_its_block_alt(): void {
		$post_id    = $this->post();
		$attachment = $this->attachment();
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $this->image_block( $attachment, 'Canola at sunset' ) ] );

		$direct = $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id );
		$render = $this->assert_render_matches_oracle( $post_id );

		$this->assertStringContainsString( 'alt="Canola at sunset"', $direct );
		$this->assertStringContainsString( 'alt="Canola at sunset"', $render );
		$this->assertSame( $render, $this->oracle( $render, $this->block( $post_id ) ), 'The site oracle leaves PKIW fallback media unchanged during overlap.' );
	}

	public function test_empty_block_alt_uses_attachment_alt(): void {
		$post_id    = $this->post();
		$attachment = $this->attachment( 0, 'Stored canola description' );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $this->image_block( $attachment, '' ) ] );

		$html = $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id );
		$this->assertStringContainsString( 'alt="Stored canola description"', $html );
		$this->assert_render_matches_oracle( $post_id );
	}

	public function test_missing_alt_uses_post_title(): void {
		$post_id    = $this->post( [ 'post_title' => 'Canola study' ] );
		$attachment = $this->attachment();
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $this->image_block( $attachment, '' ) ] );

		$html = $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id );
		$this->assertStringContainsString( 'alt="Canola study"', $html );
		$this->assert_render_matches_oracle( $post_id );
	}

	public function test_valueless_alt_keeps_the_oracles_string_cast_quirk(): void {
		$post_id    = $this->post();
		$attachment = $this->attachment( 0, 'Attachment alt must not win' );
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $this->image_block( $attachment, true ) ] );

		$html = $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id );
		$this->assertStringContainsString( 'alt="1"', $html );
		$this->assert_render_matches_oracle( $post_id );
	}

	public function test_nested_image_is_ignored_and_attached_image_wins(): void {
		$post_id        = $this->post();
		$nested_image   = $this->attachment();
		$attached_image = $this->attachment( $post_id, 'Attached fallback' );
		$nested         = '<!-- wp:group --><div class="wp-block-group">' . $this->image_block( $nested_image, 'Nested image' ) . '</div><!-- /wp:group -->';
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $nested ] );

		$html = $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id );
		$this->assertStringContainsString( (string) wp_get_attachment_image_url( $attached_image, 'medium' ), $html );
		$this->assertStringNotContainsString( (string) wp_get_attachment_image_url( $nested_image, 'medium' ), $html );
		$this->assert_render_matches_oracle( $post_id );
	}

	public function test_nested_image_without_an_attachment_gives_no_fallback(): void {
		$post_id      = $this->post();
		$nested_image = $this->attachment();
		$nested       = '<!-- wp:group --><div class="wp-block-group">' . $this->image_block( $nested_image, 'Nested image' ) . '</div><!-- /wp:group -->';
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $nested ] );

		$this->assertSame( self::NOTE_CARD, $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id ) );
		$this->assertStringNotContainsString( 'pk-media--stream', $this->assert_render_matches_oracle( $post_id ) );
	}

	public function test_first_attached_image_supplies_the_fallback(): void {
		$post_id    = $this->post();
		$attachment = $this->attachment( $post_id, 'Attached field' );

		$html = $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id );
		$this->assertStringContainsString( (string) wp_get_attachment_image_url( $attachment, 'medium' ), $html );
		$render = $this->assert_render_matches_oracle( $post_id );
		$this->assertStringContainsString( 'pk-media--stream', $render );
	}

	public function test_protected_post_never_gets_fallback_media(): void {
		$post_id = $this->post( [ 'post_password' => 'secret' ] );
		$this->attachment( $post_id, 'Private field' );

		$this->assertSame( self::NOTE_CARD, $this->assert_function_matches_oracle( self::NOTE_CARD, $post_id ) );
		$this->assertStringNotContainsString( 'pk-media', $this->assert_render_matches_oracle( $post_id ) );
	}

	public function test_existing_card_media_is_unchanged(): void {
		$post_id    = $this->post();
		$attachment = $this->attachment( $post_id, 'Featured field' );
		set_post_thumbnail( $post_id, $attachment );
		$card = '<article class="pk-card"><div class="pk-media">existing</div><div class="pk-meta"></div></article>';

		$this->assertSame( $card, $this->assert_function_matches_oracle( $card, $post_id ) );
		$render = $this->assert_render_matches_oracle( $post_id );
		$this->assertSame( 1, substr_count( $render, '<div class="pk-media' ) );
		$this->assertStringContainsString( 'wp-post-image', $render );
		// The card's own thumbnail is "u-photo wp-post-image"; a fallback would add a second u-photo.
		$this->assertSame( 1, substr_count( $render, 'u-photo' ) );
	}

	public function test_listen_card_only_post_gets_the_same_fallback(): void {
		$content = '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"Harbor Signals","artistName":"Alder Street Radio","listenUrl":"https://audio.example.org/harbor-signals"} /-->';
		$post_id = $this->post( [ 'post_content' => $content ], 'listen' );
		$this->attachment( $post_id, 'Harbor artwork' );

		$block  = $this->block( $post_id );
		$direct = $this->assert_function_matches_oracle( $this->inner( $block ), $post_id );
		$html   = $this->assert_render_matches_oracle( $post_id );
		$this->assertStringContainsString( 'k-listen', $direct );
		$this->assertStringContainsString( 'pk-media--stream', $direct );
		$this->assertStringContainsString( 'k-listen', $html );
		$this->assertStringContainsString( 'pk-media--stream', $html );
	}

	public function test_photo_card_without_an_anchor_is_unchanged(): void {
		$post_id    = $this->post( [], 'photo' );
		$attachment = $this->attachment();
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $this->image_block( $attachment, 'Photo card image' ) ] );
		$card = '<article class="pk-card k-photo"><div class="pk-photo"></div></article>';

		$this->assertSame( $card, $this->assert_function_matches_oracle( $card, $post_id ) );
		$this->assert_render_matches_oracle( $post_id );
	}

	public function test_generic_article_uses_its_body_end_anchor(): void {
		$post_id = $this->post( [], 'article' );
		$this->attachment( $post_id, 'Article field' );

		$html = $this->assert_function_matches_oracle( self::ARTICLE_CARD, $post_id );
		$this->assertStringContainsString( 'pk-media--stream', $html );
		$this->assertStringContainsString( '</div></div></article>', $html );
		$render = $this->assert_render_matches_oracle( $post_id );
		$this->assertStringContainsString( 'pk-media--stream', $render );
	}

	public function test_render_without_post_id_context_does_not_run_the_fallback(): void {
		$post_id = $this->post();
		$this->attachment( $post_id, 'Context field' );
		$GLOBALS['post'] = get_post( $post_id );
		$calls            = 0;
		$filter           = static function ( string $media ) use ( &$calls ): string {
			++$calls;
			return $media;
		};
		add_filter( 'pkiw_stream_card_media', $filter, 10, 1 );

		try {
			$without_context = $this->rendered( $this->block( $post_id, false ) );
			$this->assertStringNotContainsString( 'pk-media--stream', $without_context );
			$this->assertSame( 0, $calls );

			$with_context = $this->rendered( $this->block( $post_id ) );
			$this->assertStringContainsString( 'pk-media--stream', $with_context );
			$this->assertSame( 1, $calls, 'The context-backed render is the positive control.' );
		} finally {
			remove_filter( 'pkiw_stream_card_media', $filter, 10 );
		}
	}

	public function test_hidden_title_alt_is_safe_in_admin_preview(): void {
		$post_id = $this->post(
			[
				'post_title' => 'Checked in at Zephyr Tea Room',
				'post_date'  => '2026-08-26 09:15:00',
			],
			'checkin'
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', 'Zephyr Tea Room' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );
		Title_Privacy::mark_location_title( $post_id );
		$this->attachment( $post_id );
		set_current_screen( 'edit' );

		$block  = $this->block( $post_id );
		$oracle = $this->oracle( self::NOTE_CARD, $block );
		$actual = \PKIW\stream_card_fallback_media( self::NOTE_CARD, $post_id );

		$this->assertNotSame( $oracle, $actual, 'C1 intentionally replaces the oracle title fallback on admin screens.' );
		$this->assertStringContainsString( 'alt="Check-in, August 26, 2026"', $actual );
		$this->assertStringNotContainsString( 'Zephyr Tea Room', $actual );

		$rendered = $this->rendered( $block );
		$this->assertStringContainsString( 'alt="Check-in, August 26, 2026"', $rendered );
		$this->assertStringNotContainsString( 'Zephyr Tea Room', $rendered );
	}

	public function test_filter_can_remove_default_media(): void {
		$post_id = $this->post();
		$this->attachment( $post_id, 'Filtered field' );
		$filter = static function ( string $media, WP_Post $post, string $card_html ) use ( $post_id ): string {
			self::assertStringContainsString( 'pk-media--stream', $media );
			self::assertSame( $post_id, $post->ID );
			self::assertSame( self::NOTE_CARD, $card_html );
			return '';
		};
		add_filter( 'pkiw_stream_card_media', $filter, 10, 3 );

		try {
			$this->assertSame( self::NOTE_CARD, \PKIW\stream_card_fallback_media( self::NOTE_CARD, $post_id ) );
		} finally {
			remove_filter( 'pkiw_stream_card_media', $filter, 10 );
		}
	}

	public function test_filter_can_replace_media_at_the_card_anchor(): void {
		$post_id = $this->post();
		$custom = '<div class="pk-media pk-media--custom">Replacement</div>';
		$filter = static function ( string $media ) use ( $custom ): string {
			self::assertSame( '', $media, 'A callback can supply media when the post has no default image.' );
			return $custom;
		};
		add_filter( 'pkiw_stream_card_media', $filter, 10, 3 );

		try {
			$actual = \PKIW\stream_card_fallback_media( self::NOTE_CARD, $post_id );
			$this->assertSame( str_replace( '<div class="pk-meta">', $custom . '<div class="pk-meta">', self::NOTE_CARD ), $actual );
		} finally {
			remove_filter( 'pkiw_stream_card_media', $filter, 10 );
		}
	}

	public function test_filter_does_not_run_for_guarded_cards(): void {
		$post_id = $this->post();
		$this->attachment( $post_id, 'Positive control field' );
		$protected_id = $this->post( [ 'post_password' => 'secret' ] );
		$this->attachment( $protected_id, 'Protected field' );
		$calls  = 0;
		$filter = static function ( string $media ) use ( &$calls ): string {
			++$calls;
			return $media;
		};
		add_filter( 'pkiw_stream_card_media', $filter, 10, 1 );

		try {
			\PKIW\stream_card_fallback_media( self::NOTE_CARD, $protected_id );
			\PKIW\stream_card_fallback_media( '<article><div class="pk-media"></div><div class="pk-meta"></div></article>', $post_id );
			\PKIW\stream_card_fallback_media( '<article><div class="pk-body"></div></article>', $post_id );
			\PKIW\stream_card_fallback_media( self::NOTE_CARD, 0 );
			$this->assertSame( 0, $calls );

			\PKIW\stream_card_fallback_media( self::NOTE_CARD, $post_id );
			$this->assertSame( 1, $calls, 'An eligible card is the positive control for every guard.' );
		} finally {
			remove_filter( 'pkiw_stream_card_media', $filter, 10 );
		}
	}
}

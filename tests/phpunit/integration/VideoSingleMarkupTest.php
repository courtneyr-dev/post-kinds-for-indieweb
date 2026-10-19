<?php
/**
 * Video single markup, privacy gates and primary-player marker (issue 242).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Taxonomy;
use PKIW\Video_Archive;

/**
 * Stands in for Post Formats for Block Themes' mf2 class; PHP 8.2 cannot alias an internal class.
 */
final class PKIW_Test_PFBT_Format_Mf2_Stub {}

/**
 * A plain video single keeps its player outside e-content and exposes one
 * local u-video value. Other request surfaces receive none of that markup.
 *
 * @group integration
 */
final class VideoSingleMarkupTest extends WP_UnitTestCase {

	/**
	 * The PFBT-shaped priority 5 the_content filter, while a test has it added.
	 *
	 * @var Closure|null
	 */
	private ?Closure $pfbt_filter = null;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Video_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		wp_set_current_user( 0 );
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'blocked', 'No network in tests.' );
			}
		);
	}

	public function tear_down(): void {
		if ( null !== $this->pfbt_filter ) {
			remove_filter( 'the_content', $this->pfbt_filter, 5 );
			$this->pfbt_filter = null;
		}
		wp_set_current_user( 0 );
		set_query_var( 'atproto', null );
		set_query_var( 'markdown_request', null );
		set_query_var( 'format', null );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * A video attachment row with no file on disk.
	 */
	private function video_attachment( string $file = '2026/08/seed-242-m1.mp4', ?int $length = 134 ): int {
		$id = (int) self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'video/mp4',
			]
		);
		wp_update_attachment_metadata( $id, null === $length ? [] : [ 'length' => $length ] );

		return $id;
	}

	/**
	 * An image attachment row with no file on disk.
	 */
	private function image_attachment( string $file, string $alt = '' ): int {
		$id = (int) self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'image/png',
			]
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}

	/**
	 * A saved core/video block.
	 */
	private function video_block( int $id, string $html = '' ): string {
		$attrs = $id > 0 ? ' {"id":' . $id . '}' : '';
		if ( '' === $html ) {
			$html = '<figure class="wp-block-video"><video controls src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '"></video></figure>';
		}

		return '<!-- wp:video' . $attrs . ' -->' . $html . '<!-- /wp:video -->';
	}

	/**
	 * A saved core/embed block.
	 */
	private function embed_block( string $suffix = 'a' ): string {
		$url = 'https://www.youtube.com/watch?v=seed242' . $suffix;

		return '<!-- wp:embed {"url":"' . esc_url( $url ) . '","type":"video","providerNameSlug":"youtube"} -->'
			. '<figure class="wp-block-embed is-type-video is-provider-youtube"></figure>'
			. '<!-- /wp:embed -->';
	}

	/**
	 * A paragraph block.
	 */
	private function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A post assigned to the video kind.
	 *
	 * @param string               $content Stored content.
	 * @param array<string, mixed> $args    Extra post arguments.
	 */
	private function video_post( string $content, array $args = [] ): int {
		$id = (int) self::factory()->post->create(
			array_merge(
				[
					'post_status'   => 'publish',
					'post_title'    => 'Seed Orchard Walk',
					'post_content'  => $content,
					'post_date'     => '2026-08-20 10:00:00',
					'post_date_gmt' => '2026-08-20 14:00:00',
				],
				$args
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $id, 'video', Taxonomy::TAXONOMY ) );

		return $id;
	}

	/**
	 * Render a post through the real the_content pipeline.
	 */
	private function filtered( int $post_id ): string {
		$this->go_to( (string) get_permalink( $post_id ) );

		return apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Render content with only Video_Archive's three filters removed.
	 */
	private function without_video_filters( string $content ): string {
		remove_filter( 'the_content', [ Video_Archive::class, 'wrap_prose' ], 8 );
		remove_filter( 'the_content', [ Video_Archive::class, 'append_media_property' ], 98 );
		remove_filter( 'render_block_core/video', [ Video_Archive::class, 'mark_primary_media' ], 240 );
		remove_filter( 'render_block_core/embed', [ Video_Archive::class, 'mark_primary_media' ], 240 );
		try {
			return apply_filters( 'the_content', $content );
		} finally {
			( new Video_Archive() )->register();
		}
	}

	/**
	 * The current request makes all three video filters no-ops.
	 */
	private function assert_current_request_is_noop( int $post_id ): void {
		$content  = (string) get_post_field( 'post_content', $post_id );
		$baseline = $this->without_video_filters( $content );
		$actual   = apply_filters( 'the_content', $content );

		$this->assertSame( $baseline, $actual );
		$this->assertStringNotContainsString( Video_Archive::MARKER_ATTRIBUTE, $actual );
		$this->assertStringNotContainsString( '<data class="u-video"', $actual );
	}

	/**
	 * Behave like post-formats-for-block-themes: the class exists and wraps the whole content.
	 *
	 * The class cannot be undeclared, so it stays for the rest of the process. Tests that need
	 * the plugin absent must not depend on that and are written to hold either way.
	 */
	private function enable_pfbt_wrapper(): void {
		if ( ! class_exists( 'PFBT_Format_Mf2', false ) ) {
			class_alias( PKIW_Test_PFBT_Format_Mf2_Stub::class, 'PFBT_Format_Mf2' );
		}

		$this->pfbt_filter = static fn ( string $content ): string => sprintf( '<div class="%s">%s</div>', esc_attr( 'e-content' ), $content );
		add_filter( 'the_content', $this->pfbt_filter, 5 );
	}

	/**
	 * Parse HTML into XPath.
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/**
	 * Find the top-level h-entry in parsed microformats2 data.
	 *
	 * @param array<string, mixed> $parsed Parsed document.
	 * @return array<string, mixed>
	 */
	private function top_level_h_entry( array $parsed ): array {
		foreach ( $parsed['items'] ?? [] as $item ) {
			if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
				return $item;
			}
		}

		$this->fail( 'No top-level h-entry item was parsed.' );
	}

	public function test_local_video_single_has_one_property_one_prose_wrapper_and_complete_microformats(): void {
		$media    = $this->video_attachment();
		$featured = $this->image_attachment( '2026/08/seed-242-f1.png', 'A sunlit orchard path' );
		$content  = $this->video_block( $media ) . $this->paragraph( 'The path opens after the rain.' );
		$id       = $this->video_post( $content );
		set_post_thumbnail( $id, $featured );

		$html  = $this->filtered( $id );
		$xpath = $this->xpath( $html );
		$url   = (string) wp_get_attachment_url( $media );
		$data  = $xpath->query( '//data[contains(concat(" ", normalize-space(@class), " "), " u-video ") and @hidden]' );
		$this->assertSame( 1, $data->length );
		$this->assertSame( esc_url( $url ), $data->item( 0 )->getAttribute( 'value' ) );

		$wrappers = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " wp-block-group ") and contains(concat(" ", normalize-space(@class), " "), " e-content ")]' );
		$this->assertSame( 1, $wrappers->length );
		$this->assertStringContainsString( 'The path opens after the rain.', $wrappers->item( 0 )->textContent );
		$this->assertSame( 0, $xpath->query( './/video', $wrappers->item( 0 ) )->length );
		$this->assertLessThan( strpos( $html, 'e-content' ), strpos( $html, 'wp-block-video' ) );

		$entry      = $this->top_level_h_entry( \Mf2\parse( $html, (string) get_permalink( $id ) ) );
		$properties = $entry['properties'] ?? [];
		$this->assertSame( [ $url ], $properties['video'] ?? null );
		$this->assertContains( get_permalink( $id ), $properties['url'] ?? [] );
		$this->assertCount( 1, $properties['published'] ?? [] );
		$this->assertCount( 1, $properties['content'] ?? [] );
		$this->assertStringContainsString( 'The path opens after the rain.', $properties['content'][0]['value'] ?? '' );
		$this->assertArrayNotHasKey( 'photo', $properties );
	}

	public function test_embed_singles_add_no_u_video_property(): void {
		foreach ( [ 'a', 'b' ] as $suffix ) {
			$html = $this->filtered( $this->video_post( $this->embed_block( $suffix ) . $this->paragraph( 'Embed notes.' ) ) );
			$this->assertStringNotContainsString( '<data class="u-video"', $html, $suffix );
		}
	}

	public function test_remote_authored_video_stays_but_never_becomes_a_u_video_value(): void {
		$remote  = 'https://media.example.org/seed-242.mp4';
		$content = $this->video_block( 0, '<figure class="wp-block-video"><video controls src="' . $remote . '"></video></figure>' ) . $this->paragraph( 'Remote notes.' );
		$html    = $this->filtered( $this->video_post( $content ) );

		$this->assertStringContainsString( '<video controls src="' . $remote . '">', $html );
		$this->assertStringNotContainsString( '<data class="u-video"', $html );
	}

	public function test_prose_before_the_player_stays_outside_the_content_wrapper(): void {
		$media   = $this->video_attachment();
		$content = $this->paragraph( 'Before the player.' ) . $this->video_block( $media ) . $this->paragraph( 'After the player.' );
		$html    = $this->filtered( $this->video_post( $content ) );
		$xpath   = $this->xpath( $html );
		$wrapper = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " e-content ")]' )->item( 0 );

		$this->assertInstanceOf( DOMElement::class, $wrapper );
		$this->assertStringContainsString( 'After the player.', $wrapper->textContent );
		$this->assertStringNotContainsString( 'Before the player.', $wrapper->textContent );
		$this->assertLessThan( strpos( $html, 'wp-block-video' ), strpos( $html, 'Before the player.' ) );
	}

	public function test_nested_first_player_leaves_raw_content_byte_identical(): void {
		$media   = $this->video_attachment();
		$content = '<!-- wp:group --><div class="wp-block-group">' . $this->video_block( $media ) . '</div><!-- /wp:group -->' . $this->paragraph( 'Nested prose.' );
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( $content, Video_Archive::wrap_prose( $content ) );
	}

	public function test_player_with_nothing_after_it_leaves_raw_content_byte_identical(): void {
		$media   = $this->video_attachment();
		$content = $this->video_block( $media );
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( $content, Video_Archive::wrap_prose( $content ) );
	}

	public function test_player_with_only_whitespace_after_it_leaves_raw_content_byte_identical(): void {
		$media   = $this->video_attachment();
		$content = $this->video_block( $media ) . " \n\t ";
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( $content, Video_Archive::wrap_prose( $content ) );
	}

	public function test_applying_the_content_twice_does_not_wrap_or_add_the_property_twice(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Once only.' ) );
		$this->go_to( (string) get_permalink( $id ) );

		$once  = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		$twice = apply_filters( 'the_content', $once );

		$this->assertSame( 1, substr_count( $twice, 'wp-block-group e-content' ) );
		$this->assertSame( 1, substr_count( $twice, '<data class="u-video"' ) );
	}

	public function test_an_authored_e_content_class_prevents_a_second_wrapper(): void {
		$media   = $this->video_attachment();
		$content = $this->video_block( $media ) . '<!-- wp:group {"className":"e-content"} --><div class="wp-block-group e-content">' . $this->paragraph( 'Author wrapper.' ) . '</div><!-- /wp:group -->';
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( $content, Video_Archive::wrap_prose( $content ) );
		$this->assertSame( 1, substr_count( apply_filters( 'the_content', $content ), 'wp-block-group e-content' ) );
	}

	public function test_feed_request_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Feed prose.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		$GLOBALS['wp_query']->is_feed = true;

		$this->assert_current_request_is_noop( $id );
	}

	public function test_embed_request_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Embed surface prose.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		$GLOBALS['wp_query']->is_embed = true;

		$this->assert_current_request_is_noop( $id );
	}

	public function test_rest_route_content_rendered_has_no_video_single_markup(): void {
		$media    = $this->video_attachment();
		$id       = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'REST prose.' ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
		$this->assertSame( 200, $response->get_status() );
		$html = (string) $response->get_data()['content']['rendered'];

		$this->assertStringNotContainsString( 'wp-block-group e-content', $html );
		$this->assertStringNotContainsString( '<data class="u-video"', $html );
		$this->assertStringNotContainsString( Video_Archive::MARKER_ATTRIBUTE, $html );
	}

	public function test_atproto_projection_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Projection prose.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		set_query_var( 'atproto', '1' );

		$this->assert_current_request_is_noop( $id );
	}

	public function test_markdown_request_flag_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Markdown request prose.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		set_query_var( 'markdown_request', 1 );

		$this->assert_current_request_is_noop( $id );
	}

	public function test_markdown_format_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Markdown format prose.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		set_query_var( 'format', 'markdown' );

		$this->assert_current_request_is_noop( $id );
	}

	public function test_non_singular_request_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Archive prose.' ) );
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $id );
		$this->assertFalse( is_singular() );

		$this->assert_current_request_is_noop( $id );
	}

	public function test_admin_screen_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Admin prose.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );

		$this->assert_current_request_is_noop( $id );
	}

	public function test_missing_loop_post_is_a_noop(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'No global prose.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		$GLOBALS['post'] = null;

		$this->assert_current_request_is_noop( $id );
	}

	public function test_loop_post_that_differs_from_queried_post_is_a_noop(): void {
		$media  = $this->video_attachment();
		$first  = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Queried prose.' ) );
		$second = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Different prose.' ), [ 'post_title' => 'Seed Different Reel' ] );
		$this->go_to( (string) get_permalink( $first ) );
		$GLOBALS['post'] = get_post( $second );

		$this->assert_current_request_is_noop( $second );
	}

	public function test_protected_single_exposes_no_video_content_or_video_markup(): void {
		$media  = $this->video_attachment();
		$url    = (string) wp_get_attachment_url( $media );
		$id     = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Locked prose.' ), [ 'post_password' => 'seed-242' ] );
		$this->go_to( (string) get_permalink( $id ) );
		$content = get_the_content( null, false, get_post( $id ) );
		$html    = apply_filters( 'the_content', $content );

		$this->assertSame( [], Kind_Facts::get( $id ) );
		$this->assertStringNotContainsString( $url, $html );
		$this->assertStringNotContainsString( 'wp-block-group e-content', $html );
		$this->assertStringNotContainsString( '<data class="u-video"', $html );
		$this->assertStringNotContainsString( Video_Archive::MARKER_ATTRIBUTE, $html );
	}

	public function test_note_with_a_video_block_gets_none_of_the_video_single_markup(): void {
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Note prose.' ) );
		$this->assertNotWPError( wp_set_object_terms( $id, [ 'note', 'video' ], Taxonomy::TAXONOMY ) );

		$html = $this->filtered( $id );
		$this->assertStringNotContainsString( 'wp-block-group e-content', $html );
		$this->assertStringNotContainsString( '<data class="u-video"', $html );
		$this->assertStringNotContainsString( Video_Archive::MARKER_ATTRIBUTE, $html );
	}

	public function test_another_kinds_single_never_runs_any_kind_reader(): void {
		$media = $this->video_attachment();
		$id    = (int) self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Seed Plain Craft',
				'post_content' => $this->video_block( $media ) . $this->paragraph( 'Craft prose.' ),
			]
		);
		$this->assertNotWPError( wp_set_object_terms( $id, 'craft', Taxonomy::TAXONOMY ) );

		// A reader for the other kind, so a stray read from a video filter would show. The
		// registry is restored afterwards because other tests rely on its starting state.
		$registered = new ReflectionProperty( Kind_Facts::class, 'registered' );
		$registered->setAccessible( true );
		$before = $registered->getValue();
		Kind_Facts::register( 'craft', static fn(): array => [ 'seed' => 1 ] );
		$reads = 0;
		$count = static function ( $facts ) use ( &$reads ) {
			++$reads;

			return $facts;
		};
		add_filter( 'pkiw_kind_facts', $count );

		try {
			$this->go_to( (string) get_permalink( $id ) );
			$html = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
			$this->assertSame( [ 'seed' => 1 ], Kind_Facts::get( $id ), 'The test reader is live, so the counter can see a read.' );
		} finally {
			remove_filter( 'pkiw_kind_facts', $count );
			$registered->setValue( null, $before );
		}

		$this->assertSame( 1, $reads, 'Only the explicit Kind_Facts::get() call above reached a reader; the video filters read nothing.' );
		$this->assertStringNotContainsString( 'wp-block-group e-content', $html );
		$this->assertStringNotContainsString( '<data class="u-video"', $html );
		$this->assertStringNotContainsString( Video_Archive::MARKER_ATTRIBUTE, $html );
	}

	public function test_micropub_video_link_gets_no_generated_video_markup(): void {
		$url     = 'https://media.example.org/seed-242.mp4';
		$content = '<!-- wp:paragraph --><p><a class="u-video" href="' . $url . '">Watch</a></p><!-- /wp:paragraph -->';
		$html    = $this->filtered( $this->video_post( $content ) );

		$this->assertSame( 1, substr_count( $html, 'class="u-video"' ) );
		$this->assertStringNotContainsString( '<data class="u-video"', $html );
		$this->assertStringNotContainsString( 'wp-block-group e-content', $html );
		$this->assertStringNotContainsString( Video_Archive::MARKER_ATTRIBUTE, $html );
	}

	public function test_primary_local_video_figure_is_marked_once(): void {
		$media = $this->video_attachment();
		$html  = $this->filtered( $this->video_post( $this->video_block( $media ) . $this->paragraph( 'Marked prose.' ) ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//*[@data-pkiw-primary-media="1"]' )->length );
		$this->assertSame( 1, $xpath->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " wp-block-video ") and @data-pkiw-primary-media="1"]' )->length );
	}

	public function test_primary_embed_figure_is_marked(): void {
		$html  = $this->filtered( $this->video_post( $this->embed_block() . $this->paragraph( 'Embed prose.' ) ) );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " wp-block-embed ") and @data-pkiw-primary-media="1"]' )->length );
	}

	public function test_two_identical_embeds_mark_only_the_first(): void {
		$embed = $this->embed_block();
		$html  = $this->filtered( $this->video_post( $embed . $embed . $this->paragraph( 'Two embeds.' ) ) );
		$xpath = $this->xpath( $html );
		$nodes = $xpath->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " wp-block-embed ")]' );

		$this->assertSame( 2, $nodes->length );
		$this->assertSame( '1', $nodes->item( 0 )->getAttribute( Video_Archive::MARKER_ATTRIBUTE ) );
		$this->assertFalse( $nodes->item( 1 )->hasAttribute( Video_Archive::MARKER_ATTRIBUTE ) );
	}

	public function test_synced_pattern_video_is_not_marked_but_the_real_primary_video_is(): void {
		$pattern_media = $this->video_attachment( '2026/08/seed-242-pattern.mp4', 30 );
		$real_media    = $this->video_attachment( '2026/08/seed-242-real.mp4', 134 );
		$pattern_id    = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Seed Pattern Player',
				'post_content' => $this->video_block( $pattern_media ),
			]
		);
		$content = '<!-- wp:block {"ref":' . $pattern_id . '} /-->' . $this->video_block( $real_media ) . $this->paragraph( 'Real player prose.' );
		$html    = $this->filtered( $this->video_post( $content ) );
		$xpath   = $this->xpath( $html );

		$pattern = $xpath->query( '//video[@src="' . wp_get_attachment_url( $pattern_media ) . '"]/parent::figure' )->item( 0 );
		$real    = $xpath->query( '//video[@src="' . wp_get_attachment_url( $real_media ) . '"]/parent::figure' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $pattern );
		$this->assertInstanceOf( DOMElement::class, $real );
		$this->assertFalse( $pattern->hasAttribute( Video_Archive::MARKER_ATTRIBUTE ) );
		$this->assertSame( '1', $real->getAttribute( Video_Archive::MARKER_ATTRIBUTE ) );
	}

	public function test_second_video_is_not_marked(): void {
		$first_media  = $this->video_attachment( '2026/08/seed-242-first.mp4', 134 );
		$second_media = $this->video_attachment( '2026/08/seed-242-second.mp4', 30 );
		$html         = $this->filtered( $this->video_post( $this->video_block( $first_media ) . $this->video_block( $second_media ) . $this->paragraph( 'Two players.' ) ) );
		$xpath        = $this->xpath( $html );

		$first  = $xpath->query( '//video[@src="' . wp_get_attachment_url( $first_media ) . '"]/parent::figure' )->item( 0 );
		$second = $xpath->query( '//video[@src="' . wp_get_attachment_url( $second_media ) . '"]/parent::figure' )->item( 0 );
		$this->assertSame( '1', $first->getAttribute( Video_Archive::MARKER_ATTRIBUTE ) );
		$this->assertFalse( $second->hasAttribute( Video_Archive::MARKER_ATTRIBUTE ) );
	}

	public function test_location_meta_adds_nothing_on_a_video_post_without_a_player(): void {
		$id = $this->video_post( $this->paragraph( 'A location-free note.' ) );
		update_post_meta( $id, 'geo_latitude', 0.5 );
		update_post_meta( $id, 'geo_longitude', -0.5 );
		update_post_meta( $id, 'geo_public', 0 );
		$this->go_to( (string) get_permalink( $id ) );
		$content  = (string) get_post_field( 'post_content', $id );
		$baseline = $this->without_video_filters( $content );
		$actual   = apply_filters( 'the_content', $content );

		$this->assertSame( $baseline, $actual );
		$this->assertStringNotContainsString( '0.5', (string) wp_json_encode( Video_Archive::facts( $id ) ) );
		$this->assertStringNotContainsString( '-0.5', (string) wp_json_encode( Video_Archive::facts( $id ) ) );
		$this->assertStringNotContainsString( '0.5', \PKIW\entry_media_property_html( get_post( $id ) ) );
		$this->assertStringNotContainsString( '-0.5', \PKIW\entry_media_property_html( get_post( $id ) ) );
	}

	public function test_added_markup_never_duplicates_poster_or_track_urls(): void {
		$media   = $this->video_attachment();
		$poster  = $this->image_attachment( '2026/08/seed-242-p1.png', 'A still frame' );
		$uploads = wp_get_upload_dir();
		$track   = trailingslashit( (string) $uploads['baseurl'] ) . '2026/08/seed-242-t1.vtt';
		$inner   = '<figure class="wp-block-video"><video src="' . esc_url( (string) wp_get_attachment_url( $media ) ) . '" poster="' . esc_url( (string) wp_get_attachment_url( $poster ) ) . '"><track kind="captions" srclang="en" label="English" src="' . esc_url( $track ) . '"></video></figure>';
		$id      = $this->video_post( $this->video_block( $media, $inner ) . $this->paragraph( 'Caption notes.' ) );
		$this->go_to( (string) get_permalink( $id ) );
		$content  = (string) get_post_field( 'post_content', $id );
		$baseline = $this->without_video_filters( $content );
		$actual   = apply_filters( 'the_content', $content );

		$this->assertSame( substr_count( $baseline, (string) wp_get_attachment_url( $poster ) ), substr_count( $actual, (string) wp_get_attachment_url( $poster ) ) );
		$this->assertSame( substr_count( $baseline, $track ), substr_count( $actual, $track ) );
		$this->assertSame( substr_count( $baseline, (string) wp_get_attachment_url( $media ) ) + 1, substr_count( $actual, (string) wp_get_attachment_url( $media ) ) );
	}

	public function test_authored_e_content_wrapper_without_pfbt_keeps_todays_behavior(): void {
		$media   = $this->video_attachment();
		$content = '<!-- wp:group {"className":"e-content"} --><div class="wp-block-group e-content">' . $this->video_block( $media ) . $this->paragraph( 'Author prose.' ) . '</div><!-- /wp:group -->';
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( $content, Video_Archive::wrap_prose( $content ) );
	}

	public function test_bare_authored_e_content_div_is_unchanged_when_pfbt_is_absent(): void {
		if ( class_exists( 'PFBT_Format_Mf2', false ) ) {
			$this->markTestSkipped( 'A PFBT stub was declared earlier in this process and cannot be removed.' );
		}

		$media   = $this->video_attachment();
		$content = '<div class="e-content">' . $this->video_block( $media ) . $this->paragraph( 'Author prose.' ) . '</div>';
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( $content, Video_Archive::wrap_prose( $content ) );
	}

	public function test_pfbt_wrapper_keeps_one_prose_only_e_content_with_the_player_outside(): void {
		$this->enable_pfbt_wrapper();
		$media = $this->video_attachment();
		$id    = $this->video_post( $this->video_block( $media ) . $this->paragraph( 'The path opens after the rain.' ) );

		$html     = $this->filtered( $id );
		$xpath    = $this->xpath( $html );
		$wrappers = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " e-content ")]' );
		$wrapper  = $wrappers->item( 0 );

		$this->assertSame( 1, substr_count( $html, 'e-content' ) );
		$this->assertSame( 1, $wrappers->length );
		$this->assertInstanceOf( DOMElement::class, $wrapper );
		$this->assertStringContainsString( 'The path opens after the rain.', $wrapper->textContent );
		$this->assertSame( 0, $xpath->query( './/video', $wrapper )->length );
		$this->assertSame( 0, $xpath->query( './/*[contains(@class, "wp-block-video")]', $wrapper )->length );
		$this->assertLessThan( strpos( $html, 'e-content' ), strpos( $html, 'wp-block-video' ) );
		$this->assertSame( 1, substr_count( $html, '<data class="u-video"' ) );
	}

	public function test_pfbt_wrapper_is_left_intact_when_no_top_level_player_is_found(): void {
		$this->enable_pfbt_wrapper();
		$media   = $this->video_attachment();
		$content = '<!-- wp:group --><div class="wp-block-group">' . $this->video_block( $media ) . '</div><!-- /wp:group -->' . $this->paragraph( 'Nested prose.' );
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );
		$wrapped = '<div class="e-content">' . $content . '</div>';

		$this->assertSame( $wrapped, Video_Archive::wrap_prose( $wrapped ) );
		$this->assertSame( 1, substr_count( apply_filters( 'the_content', $content ), 'e-content' ) );
	}

	public function test_pfbt_wrapper_is_left_intact_when_nothing_follows_the_player(): void {
		$this->enable_pfbt_wrapper();
		$media   = $this->video_attachment();
		$content = $this->video_block( $media );
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );
		$wrapped = '<div class="e-content">' . $content . '</div>';

		$this->assertSame( $wrapped, Video_Archive::wrap_prose( $wrapped ) );
	}

	public function test_pfbt_wrapper_is_not_unwrapped_when_the_first_div_closes_early(): void {
		$this->enable_pfbt_wrapper();
		$media   = $this->video_attachment();
		$content = '<div class="e-content">' . $this->paragraph( 'One.' ) . '</div>' . $this->video_block( $media ) . '<div>' . $this->paragraph( 'Two.' ) . '</div>';
		$id      = $this->video_post( $content );
		$this->go_to( (string) get_permalink( $id ) );

		$this->assertSame( $content, Video_Archive::wrap_prose( $content ) );
	}
}

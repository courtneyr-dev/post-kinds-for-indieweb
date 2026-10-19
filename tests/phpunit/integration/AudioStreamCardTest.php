<?php
/**
 * Audio Stream card coverage (issue 243).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Audio_Archive;
use PKIW\Taxonomy;

/**
 * Audio Stream cards add one duration line and keep every player out of the
 * compact archive surface.
 *
 * @group integration
 */
final class AudioStreamCardTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );
		( new Taxonomy() )->create_default_terms();
		( new Audio_Archive() )->register();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A resolvable audio attachment.
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 */
	private function attachment( array $metadata = [] ): int {
		$id = (int) self::factory()->attachment->create( [ 'post_mime_type' => 'audio/mpeg' ] );
		update_post_meta( $id, '_wp_attached_file', '2026/09/audio-stream-' . $id . '.mp3' );
		wp_update_attachment_metadata( $id, $metadata );

		return $id;
	}

	/**
	 * A core/audio block.
	 *
	 * @param string   $url Audio URL.
	 * @param int|null $id  Optional attachment ID.
	 */
	private function audio_block( string $url, ?int $id = null ): string {
		$attrs = null === $id ? '' : ' {"id":' . $id . '}';

		return '<!-- wp:audio' . $attrs . ' --><figure class="wp-block-audio"><audio controls src="' . esc_url( $url ) . '"></audio></figure><!-- /wp:audio -->';
	}

	/**
	 * A published post assigned to a kind.
	 *
	 * @param string               $kind    Kind slug.
	 * @param string               $content Block content.
	 * @param array<string, mixed> $args    Extra post fields.
	 */
	private function post( string $kind, string $content, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Audio field note',
					'post_content' => $content,
					'post_date'    => '2026-09-18 14:20:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Render the Stream card block inside a real loop.
	 */
	private function card( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
		}
		wp_reset_postdata();

		return $html;
	}

	/**
	 * XPath over a card fragment.
	 */
	private function xpath( string $html ): DOMXPath {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="card-scope">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $doc );
	}

	/**
	 * Rendered links exclude links hidden on themselves or an ancestor.
	 */
	private function visible_anchor_count( string $html ): int {
		return $this->xpath( $html )->query( '//a[@href][not(ancestor-or-self::*[@hidden])]' )->length;
	}

	public function test_duration_line_uses_the_first_attachment_duration(): void {
		$attachment = $this->attachment( [ 'length' => 252 ] );
		$id         = $this->post( 'audio', $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) );
		$html       = $this->card( $id );
		$duration   = \PKIW\attachment_duration( $attachment );

		$this->assertIsArray( $duration );
		$this->assertStringContainsString( '<p class="pk-sub pk-audio-duration">' . \PKIW\duration_time_html( $duration ) . '</p>', $html );
		$this->assertSame( 1, substr_count( $html, 'pk-audio-duration' ) );
	}

	public function test_duration_line_is_absent_without_a_local_attachment_length(): void {
		$no_length = $this->attachment();
		$fixtures  = [
			'external'  => $this->audio_block( 'https://audio.example.org/external.mp3' ),
			'embed'     => '<!-- wp:embed {"url":"https://soundcloud.com/example-account/example-track","providerNameSlug":"soundcloud"} --><figure class="wp-block-embed"></figure><!-- /wp:embed -->',
			'no length' => $this->audio_block( (string) wp_get_attachment_url( $no_length ), $no_length ),
			'no player' => '<!-- wp:paragraph --><p>Words only.</p><!-- /wp:paragraph -->',
		];

		foreach ( $fixtures as $label => $content ) {
			$this->assertStringNotContainsString( 'pk-audio-duration', $this->card( $this->post( 'audio', $content, [ 'post_title' => $label ] ) ), $label );
		}
	}

	public function test_card_order_is_kind_title_duration_then_date(): void {
		$attachment = $this->attachment( [ 'length' => 75 ] );
		$html       = $this->card( $this->post( 'audio', $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) ) );

		$kind     = strpos( $html, '>Audio</span>' );
		$title    = strpos( $html, 'class="pk-title p-name"' );
		$duration = strpos( $html, 'pk-audio-duration' );
		$date     = strpos( $html, 'pk-stream-date' );
		$this->assertIsInt( $kind );
		$this->assertIsInt( $title );
		$this->assertIsInt( $duration );
		$this->assertIsInt( $date );
		$this->assertLessThan( $title, $kind );
		$this->assertLessThan( $duration, $title );
		$this->assertLessThan( $date, $duration );
	}

	public function test_protected_audio_uses_the_protected_card_and_direct_filter_returns_empty(): void {
		$attachment = $this->attachment( [ 'length' => 75 ] );
		$id         = $this->post(
			'audio',
			$this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ),
			[ 'post_password' => 'seed-243-pass' ]
		);
		$html       = $this->card( $id );

		$this->assertStringContainsString( 'pk-card--protected', $html );
		foreach ( [ 'pk-audio-duration', 'u-audio', 'p-author', '<audio', 'wp-block-audio' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
		$this->assertSame( '', Audio_Archive::stream_duration_line( '', get_post( $id ) ) );
	}

	public function test_article_with_audio_is_unchanged_by_the_audio_filter(): void {
		$attachment = $this->attachment( [ 'length' => 75 ] );
		$id         = $this->post( 'article', $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) );
		$post       = get_post( $id );

		$this->assertSame( '<b>existing</b>', Audio_Archive::stream_duration_line( '<b>existing</b>', $post ) );
		$this->assertStringNotContainsString( 'pk-audio-duration', $this->card( $id ) );
	}

	public function test_s5_shape_has_no_visual_media_controls_or_overflow_count(): void {
		$id = $this->post( 'audio', $this->audio_block( 'https://audio.example.org/s5.mp3' ) );
		$featured = (int) self::factory()->attachment->create_object(
			[
				'file'           => '2026/09/featured.jpg',
				'post_parent'    => $id,
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'Caption by <a href="https://example.org/credit">Alex</a>',
			]
		);
		set_post_thumbnail( $id, $featured );
		for ( $n = 0; $n < 5; $n++ ) {
			self::factory()->attachment->create_object(
				[
					'file'           => '2026/09/row-' . $n . '.jpg',
					'post_parent'    => $id,
					'post_mime_type' => 'image/jpeg',
				]
			);
		}

		$html = $this->card( $id );
		foreach ( [ 'pk-media', '<button', '+1', '<img' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}

	public function test_card_has_one_visible_anchor_and_negative_tabindex_links_are_hidden(): void {
		$attachment = $this->attachment( [ 'length' => 75 ] );
		$html       = $this->card( $this->post( 'audio', $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) ) );
		$xpath      = $this->xpath( $html );

		$this->assertSame( 1, $this->visible_anchor_count( $html ) );
		$this->assertSame( 0, $xpath->query( '//a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( $xpath->query( '//a[@tabindex="-1"]' )->length, $xpath->query( '//a[@tabindex="-1"][ancestor-or-self::*[@hidden]]' )->length );
	}

	public function test_hidden_entry_props_hold_only_attachment_backed_u_audio(): void {
		$attachment = $this->attachment( [ 'length' => 75 ] );
		$url        = (string) wp_get_attachment_url( $attachment );
		$html       = $this->card( $this->post( 'audio', $this->audio_block( $url, $attachment ) ) );
		$this->assertSame( 1, $this->xpath( $html )->query( '//span[contains(@class,"pk-entry-props")][@hidden]/data[@class="u-audio"][@value="' . $url . '"]' )->length );

		$embed = $this->card( $this->post( 'audio', '<!-- wp:embed {"url":"https://soundcloud.com/example-account/example-track","providerNameSlug":"soundcloud"} --><figure class="wp-block-embed"></figure><!-- /wp:embed -->' ) );
		$none  = $this->card( $this->post( 'audio', '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->' ) );
		$this->assertStringNotContainsString( 'u-audio', $embed );
		$this->assertStringNotContainsString( 'u-audio', $none );
	}

	public function test_untitled_card_uses_audio_and_a_screen_reader_date_without_p_name(): void {
		$html  = $this->card( $this->post( 'audio', '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->', [ 'post_title' => '' ] ) );
		$xpath = $this->xpath( $html );
		$link  = $xpath->query( '//h2[@class="pk-title"]/a[@class="u-url"]' );

		$this->assertSame( 1, $link->length );
		$this->assertStringStartsWith( 'Audio', trim( $link->item( 0 )->textContent ) );
		$this->assertSame( 1, $xpath->query( '//h2[@class="pk-title"]/a/span[contains(@class,"pk-sr-only")]' )->length );
		$this->assertStringNotContainsString( 'p-name', $html );
	}

	public function test_no_card_surface_embeds_a_player(): void {
		$attachment = $this->attachment( [ 'length' => 75 ] );
		$html       = $this->card( $this->post( 'audio', $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) ) );

		foreach ( [ '<audio', '<iframe', 'ableplayer', 'wp-block-audio' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $html, $needle );
		}
	}
}

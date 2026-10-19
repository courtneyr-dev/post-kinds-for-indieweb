<?php
/**
 * Audio privacy surface coverage (issue 243).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Audio_Archive;
use PKIW\Audio_Transcript;
use PKIW\Integrations\Atmosphere_Eligibility;
use PKIW\Meta_Fields;
use PKIW\Micropub_Content_Builder;
use PKIW\Taxonomy;

/**
 * Audio URLs, transcript text, and attachment identity appear only on the
 * public surfaces that own them and never escape password protection.
 *
 * @group integration
 */
final class AudioPrivacySurfacesTest extends WP_UnitTestCase {

	/**
	 * Fixture values used to prove omission.
	 */
	private const TRANSCRIPT = 'The private transcript fixture has spoken words.';
	private const TITLE      = 'Voice memo at Maple Street Cafe';
	private const ARTIST     = 'Maple Street Band';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Audio_Archive() )->register();
		( new Audio_Transcript() )->register();
		add_filter( 'pre_http_request', '__return_empty_array' );
		$GLOBALS['wp_rest_server'] = null;
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		parent::tear_down();
	}

	/**
	 * A resolvable local audio attachment.
	 *
	 * @param int    $length Duration seconds; zero omits the key.
	 * @param string $title  Attachment title.
	 * @param string $file   Relative attached filename.
	 */
	private function attachment( int $length = 75, string $title = 'Audio fixture', string $file = '' ): int {
		$id = (int) self::factory()->attachment->create(
			[
				'post_mime_type' => 'audio/mpeg',
				'post_title'     => $title,
			]
		);
		update_post_meta( $id, '_wp_attached_file', '' === $file ? '2026/09/privacy-audio-' . $id . '.mp3' : $file );
		$metadata = [
			'title'  => self::ARTIST,
			'artist' => self::ARTIST,
		];
		if ( $length > 0 ) {
			$metadata['length'] = $length;
		}
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
	 * A Micropub u-audio paragraph.
	 */
	private function micropub_block( string $url ): string {
		return '<!-- wp:paragraph --><p><a class="u-audio" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p><!-- /wp:paragraph -->';
	}

	/**
	 * A filled transcript group.
	 *
	 * @param string $text Transcript text.
	 */
	private function transcript( string $text = self::TRANSCRIPT ): string {
		return '<!-- wp:group {"className":"pkiw-transcript"} --><div class="wp-block-group pkiw-transcript"><!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
	}

	/**
	 * A post assigned to the audio kind.
	 *
	 * @param string               $content Block content.
	 * @param array<string, mixed> $args    Extra post fields.
	 */
	private function audio( string $content, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Public audio note',
					'post_content' => $content,
					'post_date'    => '2026-09-18 14:20:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'audio', Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * Render a Stream card for a real loop post.
	 *
	 * @param int $id Post ID.
	 * @param int $heading Heading level.
	 */
	private function card( int $id, int $heading = 2 ): string {
		$this->go_to( get_permalink( $id ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":' . $heading . '} /-->' );
		}
		wp_reset_postdata();

		return $html;
	}

	/**
	 * Render singular content through a real loop.
	 */
	private function single( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$this->assertTrue( have_posts() );
		the_post();

		return (string) apply_filters( 'the_content', get_post_field( 'post_content', $id ) );
	}

	/**
	 * Anonymous REST representation.
	 *
	 * @return array<string, mixed>
	 */
	private function rest( int $id ): array {
		$GLOBALS['wp_rest_server'] = null;
		$response                  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (array) $response->get_data();
	}

	/**
	 * RSS2 content for one post.
	 */
	private function feed_content( int $id ): string {
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		$content = (string) get_the_content_feed( 'rss2' );
		wp_reset_postdata();

		return $content;
	}

	/**
	 * The current RSS2 feed document.
	 */
	private function feed_document(): string {
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed() );
		ob_start();
		try {
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			$out = (string) ob_get_clean();
		}

		return $out;
	}

	/**
	 * XPath over an HTML fragment.
	 */
	private function xpath( string $html ): DOMXPath {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="scope">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $doc );
	}

	public function test_1_protected_attachment_and_micropub_posts_expose_no_private_audio_surface(): void {
		$attachment = $this->attachment();
		$url        = (string) wp_get_attachment_url( $attachment );
		$id         = $this->audio(
			$this->audio_block( $url, $attachment ) . $this->transcript(),
			[
				'post_title'    => 'Locked audio note',
				'post_password' => 'seed-243-pass',
			]
		);

		$this->assertSame( [], Audio_Archive::facts( $id ) );
		$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $id ) ) );
		$card = $this->card( $id );
		$this->assertStringContainsString( 'pk-card--protected', $card );
		foreach ( [ 'u-audio', 'pk-audio-duration', 'p-author', '<audio' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $card, $needle );
		}

		$single = $this->single( $id );
		$this->assertStringNotContainsString( '<data class="u-audio"', $single );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', $single );

		$rest = $this->rest( $id );
		$this->assertTrue( $rest['content']['protected'] );
		$this->assertStringNotContainsString( $url, (string) $rest['content']['rendered'] );
		$this->assertStringNotContainsString( self::TRANSCRIPT, (string) $rest['content']['rendered'] );

		$micropub = $this->audio(
			$this->micropub_block( $url ),
			[
				'post_title'    => 'Locked Micropub audio',
				'post_password' => 'seed-243-pass',
			]
		);
		$html = $this->single( $micropub );
		$this->assertStringNotContainsString( '<data class="u-audio"', $html );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', $html );
	}

	public function test_2_private_audio_has_no_facts_and_is_absent_from_public_kind_query(): void {
		$id = $this->audio( '<!-- wp:paragraph --><p>Private note.</p><!-- /wp:paragraph -->', [ 'post_status' => 'private' ] );
		$this->assertSame( [], Audio_Archive::facts( $id ) );

		$query = new WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => [
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => [ 'audio' ],
					],
				],
			]
		);
		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) ) );
	}

	public function test_3_audio_registers_no_meta_and_duration_registry_is_pinned(): void {
		( new Meta_Fields() )->register_meta_fields();
		$keys = array_keys( get_registered_meta_keys( 'post', 'post' ) );
		foreach ( $keys as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/audio|transcript/i', (string) $key );
		}

		$duration_keys = array_values( array_filter( $keys, static fn( string $key ): bool => str_ends_with( $key, '_duration' ) ) );
		sort( $duration_keys );
		// The set at pkiw-w4: recipe_duration is the only registered key. The exercise and sleep
		// durations are card-decided keys that stay unregistered, so audio adds nothing to either list.
		$this->assertSame( [ '_pkiw_recipe_duration' ], $duration_keys );
		$this->assertContains( 'exercise_duration', Meta_Fields::EXERCISE_KEYS );
		$this->assertContains( 'sleep_duration', Meta_Fields::SLEEP_KEYS );
		$this->assertNotContains( '_pkiw_exercise_duration', $keys );
		$this->assertNotContains( '_pkiw_sleep_duration', $keys );

		foreach ( [ 'includes/class-audio-archive.php', 'includes/class-audio-transcript.php' ] as $relative ) {
			$source = (string) file_get_contents( PKIW_PATH . $relative );
			$this->assertStringNotContainsString( 'register_post_meta', $source, $relative );
			$this->assertStringNotContainsString( 'register_meta(', $source, $relative );
		}
	}

	public function test_4_attachment_identity_never_prints_beyond_the_owned_url_values(): void {
		$attachment = $this->attachment( 75, self::TITLE, '2026/09/maple-street-cafe.m4a' );
		$url        = (string) wp_get_attachment_url( $attachment );
		$id         = $this->audio( $this->audio_block( $url, $attachment ) . $this->transcript(), [ 'post_title' => 'Field note' ] );

		$facts   = (string) wp_json_encode( Audio_Archive::facts( $id ) );
		$card    = $this->card( $id );
		$archive = $this->card( $id, 3 );
		$feed    = $this->feed_content( $id );
		$rest    = (string) wp_json_encode( $this->rest( $id ) );
		foreach ( [ $facts, $card, $archive, $feed, $rest ] as $surface ) {
			$this->assertStringNotContainsString( 'Maple Street', $surface );
		}
		$this->assertStringNotContainsString( 'maple-street-cafe', $facts );

		$this->assertSame( 1, substr_count( $card, 'maple-street-cafe' ) );
		$this->assertSame( 1, $this->xpath( $card )->query( '//span[@hidden]/data[@class="u-audio" and contains(@value,"maple-street-cafe")]' )->length );
		$single = $this->single( $id );
		$this->assertSame( 1, $this->xpath( $single )->query( '//audio[contains(@src,"maple-street-cafe")]' )->length );
	}

	public function test_5_audio_is_standard_site_eligible_and_document_uses_transcript_not_attachment_title(): void {
		$this->assertContains( 'audio', ( new Atmosphere_Eligibility() )->get_eligible_kinds() );
		if ( ! class_exists( '\Atmosphere\Transformer\Document' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to inspect the document record.' );
		}

		$attachment = $this->attachment( 75, self::TITLE, '2026/09/maple-street-cafe.m4a' );
		$id         = $this->audio( $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) . $this->transcript() );
		$record     = ( new \Atmosphere\Transformer\Document( get_post( $id ) ) )->transform();
		$text       = (string) wp_json_encode( $record );
		$this->assertStringContainsString( self::TRANSCRIPT, $text );
		$this->assertStringNotContainsString( self::TITLE, $text );
	}

	public function test_6_rest_meta_names_no_audio_transcript_or_enclosure_key(): void {
		$attachment = $this->attachment();
		$id         = $this->audio( $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) . $this->transcript() );
		$meta       = array_keys( (array) ( $this->rest( $id )['meta'] ?? [] ) );
		foreach ( $meta as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/audio|transcript|enclosure/i', (string) $key );
		}
	}

	public function test_7_external_audio_creates_one_private_enclosure_and_one_feed_element(): void {
		$url = 'https://audio.example.org/clip-one.mp3';
		$id  = $this->audio( $this->audio_block( $url ), [ 'post_title' => 'External audio' ] );
		$head = static function ( $response, array $args, string $request_url ) use ( $url ) {
			if ( $request_url !== $url ) {
				return $response;
			}

			return [
				'headers'  => [ 'content-type' => 'audio/mpeg', 'content-length' => '4321' ],
				'body'     => '',
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		remove_filter( 'pre_http_request', '__return_empty_array' );
		add_filter( 'pre_http_request', $head, 10, 3 );
		try {
			do_enclose( null, $id );
		} finally {
			remove_filter( 'pre_http_request', $head, 10 );
			add_filter( 'pre_http_request', '__return_empty_array' );
		}

		$rows = get_post_meta( $id, 'enclosure', false );
		$this->assertCount( 1, $rows );
		$this->assertSame( $url, strtok( (string) $rows[0], "\n" ) );
		$this->assertStringNotContainsString( self::TITLE, (string) $rows[0] );
		$this->assertArrayNotHasKey( 'enclosure', (array) ( $this->rest( $id )['meta'] ?? [] ) );

		$feed = $this->feed_document();
		$this->assertSame( 1, substr_count( $feed, '<enclosure url="' . esc_url( $url ) . '"' ) );
	}

	public function test_8_micropub_audio_stays_one_paragraph_player_with_duration_and_marker(): void {
		$attachment = $this->attachment( 95 );
		$url        = (string) wp_get_attachment_url( $attachment );
		$content    = Micropub_Content_Builder::fill_empty_content(
			'',
			[
				'properties' => [
					'audio' => [ $url ],
				],
			]
		);
		$id         = $this->audio( $content, [ 'post_title' => 'Micropub audio' ] );
		$this->assertStringContainsString( 'class="u-audio"', get_post_field( 'post_content', $id ) );

		$media = \PKIW\first_media_block( $id, 'audio' );
		$this->assertIsArray( $media );
		$this->assertSame( 'core/paragraph', $media['block'] );
		$this->assertSame( 'attachment', $media['type'] );

		$html = $this->single( $id );
		$this->assertSame( 1, substr_count( $html, 'class="u-audio"' ) );
		$this->assertMatchesRegularExpression( '/<p[^>]*data-pkiw-primary-media="1"/', $html );
		$facts = Audio_Archive::facts( $id );
		$this->assertSame( 95, $facts['duration_seconds'] );
		$this->assertSame( 1, $facts['player_count'] );
	}

	public function test_9_archive_item_and_stream_card_have_no_embedded_player(): void {
		$attachment = $this->attachment();
		$id         = $this->audio( $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) );
		foreach ( [ $this->card( $id ), $this->card( $id, 3 ) ] as $surface ) {
			foreach ( [ '<audio', '<iframe', 'ableplayer' ] as $needle ) {
				$this->assertStringNotContainsString( $needle, $surface, $needle );
			}
		}
	}

	public function test_10_stored_content_is_stable_outside_plain_singular_context(): void {
		$attachment = $this->attachment();
		$content    = $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) . $this->transcript();
		$id         = $this->audio( $content );

		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		$home = (string) apply_filters( 'the_content', $content );
		$this->assertStringContainsString( 'wp-block-audio', $home );
		$this->assertStringContainsString( 'pkiw-transcript', $home );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', $home );
		$this->assertStringNotContainsString( '<data class="u-audio"', $home );

		$this->go_to( get_permalink( $id ) );
		the_post();
		set_query_var( 'atproto', '1' );
		$atproto = (string) apply_filters( 'the_content', $content );
		$this->assertStringContainsString( 'wp-block-audio', $atproto );
		$this->assertStringContainsString( 'pkiw-transcript', $atproto );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', $atproto );
		$this->assertStringNotContainsString( '<data class="u-audio"', $atproto );
		set_query_var( 'atproto', null );
	}

	public function test_11_no_audio_facts_fixture_has_a_location_key(): void {
		$attachment = $this->attachment();
		$ids        = [
			$this->audio( $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) ),
			$this->audio( $this->micropub_block( 'https://audio.example.org/location-check.mp3' ) ),
			$this->audio( '<!-- wp:paragraph --><p>No player.</p><!-- /wp:paragraph -->' ),
		];

		foreach ( $ids as $id ) {
			foreach ( array_keys( Audio_Archive::facts( $id ) ) as $key ) {
				$this->assertDoesNotMatchRegularExpression( '/lat|lon|geo|place|location|address/i', $key );
			}
		}
	}
}

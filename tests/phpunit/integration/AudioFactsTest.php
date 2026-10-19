<?php
/**
 * Audio facts and primary-player marker coverage (issue 243).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Audio_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/**
 * Audio facts expose player shape and duration without copying media or
 * transcript content into the facts array.
 *
 * @group integration
 */
final class AudioFactsTest extends WP_UnitTestCase {

	/**
	 * The keys, in contract order.
	 *
	 * @var string[]
	 */
	private const KEYS = [
		'name',
		'name_is_title',
		'kind_label',
		'published_day',
		'published_iso',
		'player_count',
		'player_kind',
		'player_block',
		'audio_attachment_id',
		'embed_provider',
		'duration_seconds',
		'duration_iso',
		'duration_clock',
		'duration_label',
		'has_transcript',
		'featured_in_content',
		'primary_marker',
	];

	/**
	 * Query vars changed by marker context tests.
	 *
	 * @var array<string, mixed>
	 */
	private array $query_vars = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Audio_Archive() )->register();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		$this->query_vars = isset( $GLOBALS['wp']->query_vars ) ? $GLOBALS['wp']->query_vars : [];
	}

	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( 0 );
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		if ( isset( $GLOBALS['wp'] ) ) {
			$GLOBALS['wp']->query_vars = $this->query_vars;
		}
		parent::tear_down();
	}

	/**
	 * A published audio post.
	 *
	 * @param string               $title   Post title.
	 * @param string               $content Block content.
	 * @param array<string, mixed> $args    Extra post fields.
	 */
	private function audio( string $title = 'Field recording', string $content = '', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
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
	 * A media attachment with a resolvable local URL.
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @param string               $title    Attachment title.
	 * @param string               $file     Relative attached file.
	 */
	private function attachment( array $metadata = [], string $title = 'Audio fixture', string $file = '' ): int {
		$id = (int) self::factory()->attachment->create(
			[
				'post_mime_type' => 'audio/mpeg',
				'post_title'     => $title,
			]
		);
		update_post_meta( $id, '_wp_attached_file', '' === $file ? '2026/09/audio-' . $id . '.mp3' : $file );
		wp_update_attachment_metadata( $id, $metadata );

		return $id;
	}

	/**
	 * A core/audio block.
	 *
	 * @param string   $url Attachment or remote URL.
	 * @param int|null $id  Optional attachment ID.
	 */
	private function audio_block( string $url, ?int $id = null ): string {
		$attrs = null === $id ? '' : ' {"id":' . $id . '}';

		return '<!-- wp:audio' . $attrs . ' --><figure class="wp-block-audio"><audio controls src="' . esc_url( $url ) . '"></audio></figure><!-- /wp:audio -->';
	}

	/**
	 * A SoundCloud core/embed block.
	 */
	private function embed_block(): string {
		$url = 'https://soundcloud.com/example-account/example-track';

		return '<!-- wp:embed {"url":"' . $url . '","type":"rich","providerNameSlug":"soundcloud"} --><figure class="wp-block-embed is-provider-soundcloud"><div class="wp-block-embed__wrapper">' . $url . '</div></figure><!-- /wp:embed -->';
	}

	/**
	 * A Micropub u-audio paragraph.
	 *
	 * @param string $url Audio URL.
	 */
	private function micropub_block( string $url ): string {
		return '<!-- wp:paragraph --><p><a class="u-audio" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p><!-- /wp:paragraph -->';
	}

	/**
	 * A transcript group.
	 *
	 * @param string $text Transcript text.
	 */
	private function transcript( string $text ): string {
		return '<!-- wp:group {"className":"pkiw-transcript"} --><div class="wp-block-group pkiw-transcript"><!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
	}

	/**
	 * Render post content through a real singular loop.
	 */
	private function singular_content( int $id ): string {
		$this->go_to( get_permalink( $id ) );
		$this->assertTrue( have_posts() );
		the_post();

		return (string) apply_filters( 'the_content', get_post_field( 'post_content', $id ) );
	}

	public function test_reader_and_exact_key_contract(): void {
		$entry = Kind_Facts::reader( 'audio' );
		$this->assertNotNull( $entry );
		$this->assertSame( [ Audio_Archive::class, 'facts' ], $entry['reader'] );
		$this->assertSame( [], $entry['location'] );

		$id    = $this->audio();
		$facts = Audio_Archive::facts( $id );
		$this->assertSame( self::KEYS, array_keys( $facts ) );
		$this->assertSame( $facts, Kind_Facts::get( $id ) );
		$this->assertSame( '2026-09-18', $facts['published_day'] );
		$this->assertSame( (string) get_post_time( 'c', true, get_post( $id ) ), $facts['published_iso'] );
		$this->assertSame( Audio_Archive::PRIMARY_MARKER, $facts['primary_marker'] );
	}

	/**
	 * @return array<string, array{int, string, string}>
	 */
	public function durations(): array {
		return [
			'four minutes' => [ 252, 'PT4M12S', '4:12' ],
			'hours'        => [ 3725, 'PT1H2M5S', '1:02:05' ],
			'under minute' => [ 45, 'PT45S', '0:45' ],
		];
	}

	/**
	 * @dataProvider durations
	 */
	public function test_local_attachment_duration_uses_length_not_length_formatted( int $seconds, string $iso, string $clock ): void {
		$attachment = $this->attachment( [ 'length' => $seconds, 'length_formatted' => '4:13' ] );
		$id         = $this->audio( 'Duration ' . $seconds, $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) );
		$facts      = Audio_Archive::facts( $id );

		$this->assertSame( $seconds, $facts['duration_seconds'] );
		$this->assertSame( $iso, $facts['duration_iso'] );
		$this->assertSame( $clock, $facts['duration_clock'] );
		$this->assertSame( human_readable_duration( $clock ), $facts['duration_label'] );
	}

	public function test_non_attachment_and_missing_lengths_have_empty_duration(): void {
		$no_length = $this->attachment();
		$fixtures  = [
			'external'   => $this->audio_block( 'https://audio.example.org/external.mp3' ),
			'embed'      => $this->embed_block(),
			'no length'  => $this->audio_block( (string) wp_get_attachment_url( $no_length ), $no_length ),
			'no player'  => '<!-- wp:paragraph --><p>Quiet.</p><!-- /wp:paragraph -->',
		];

		foreach ( $fixtures as $label => $content ) {
			$facts = Audio_Archive::facts( $this->audio( $label, $content ) );
			$this->assertNull( $facts['duration_seconds'], $label );
			$this->assertSame( '', $facts['duration_iso'], $label );
			$this->assertSame( '', $facts['duration_clock'], $label );
			$this->assertSame( '', $facts['duration_label'], $label );
		}
	}

	public function test_player_shape_count_and_document_order(): void {
		$first  = $this->attachment( [ 'length' => 60 ] );
		$second = $this->attachment( [ 'length' => 120 ] );
		$id     = $this->audio(
			'Two players',
			$this->audio_block( (string) wp_get_attachment_url( $first ), $first )
			. $this->audio_block( (string) wp_get_attachment_url( $second ), $second )
		);
		$facts  = Audio_Archive::facts( $id );

		$this->assertSame( 2, $facts['player_count'] );
		$this->assertSame( 'attachment', $facts['player_kind'] );
		$this->assertSame( 'core/audio', $facts['player_block'] );
		$this->assertSame( $first, $facts['audio_attachment_id'] );
		$this->assertSame( 60, $facts['duration_seconds'] );
	}

	public function test_micropub_embed_and_nested_players_report_their_shape(): void {
		$url      = 'https://audio.example.org/clip-two.mp3';
		$external = Audio_Archive::facts( $this->audio( 'Micropub URL', $this->micropub_block( $url ) ) );
		$this->assertSame( 'core/paragraph', $external['player_block'] );
		$this->assertSame( 'url', $external['player_kind'] );

		$attachment = $this->attachment( [ 'length' => 80 ] );
		$local      = Audio_Archive::facts( $this->audio( 'Micropub local', $this->micropub_block( (string) wp_get_attachment_url( $attachment ) ) ) );
		$this->assertSame( 'attachment', $local['player_kind'] );
		$this->assertSame( $attachment, $local['audio_attachment_id'] );

		$embed = Audio_Archive::facts( $this->audio( 'Embed', $this->embed_block() ) );
		$this->assertSame( 'core/embed', $embed['player_block'] );
		$this->assertSame( 'embed', $embed['player_kind'] );
		$this->assertSame( 'soundcloud', $embed['embed_provider'] );

		$nested = '<!-- wp:group --><div class="wp-block-group">' . $this->audio_block( $url ) . '</div><!-- /wp:group -->';
		$this->assertSame( 'core/audio', Audio_Archive::facts( $this->audio( 'Nested', $nested ) )['player_block'] );
	}

	public function test_names_never_come_from_transcript_or_attachment(): void {
		$titled = Audio_Archive::facts( $this->audio( 'Morning birds' ) );
		$this->assertSame( 'Morning birds', $titled['name'] );
		$this->assertTrue( $titled['name_is_title'] );

		$id       = $this->audio( '', $this->transcript( 'Hello from the porch' ) );
		$untitled = Audio_Archive::facts( $id );
		$this->assertSame( \PKIW\untitled_name( get_post( $id ), false ), $untitled['name'] );
		$this->assertFalse( $untitled['name_is_title'] );
		$this->assertStringNotContainsString( 'Hello from the porch', $untitled['name'] );
	}

	public function test_transcript_and_featured_flags_follow_the_shared_helpers(): void {
		$filled = $this->audio( 'Filled', $this->transcript( 'A spoken account.' ) );
		$empty  = $this->audio( 'Empty', $this->transcript( '&nbsp;' ) );
		$two    = $this->audio( 'Second filled', $this->transcript( '' ) . $this->transcript( 'Later account.' ) );
		$this->assertTrue( Audio_Archive::facts( $filled )['has_transcript'] );
		$this->assertFalse( Audio_Archive::facts( $empty )['has_transcript'] );
		$this->assertTrue( Audio_Archive::facts( $two )['has_transcript'] );

		$image = (int) self::factory()->attachment->create_object(
			[
				'file'           => '2026/09/transcript-cover.jpg',
				'post_mime_type' => 'image/jpeg',
			]
		);
		set_post_thumbnail( $filled, $image );
		wp_update_post(
			[
				'ID'           => $filled,
				'post_content' => '<!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"><img class="wp-image-' . $image . '" src="' . esc_url( (string) wp_get_attachment_url( $image ) ) . '" alt=""/></figure><!-- /wp:image -->' . $this->transcript( 'A spoken account.' ),
			]
		);
		$this->assertSame( \PKIW\featured_in_content( $filled ), Audio_Archive::facts( $filled )['featured_in_content'] );
		$this->assertTrue( Audio_Archive::facts( $filled )['featured_in_content'] );
	}

	public function test_password_private_draft_and_wrong_kind_are_guarded(): void {
		$protected = $this->audio( 'Locked', '', [ 'post_password' => 'seed-243-pass' ] );
		$private   = $this->audio( 'Private', '', [ 'post_status' => 'private' ] );
		$draft     = $this->audio( 'Draft', '', [ 'post_status' => 'draft' ] );
		$article   = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Article' ] );
		wp_set_object_terms( $article, 'article', Taxonomy::TAXONOMY );

		$this->assertSame( [], Audio_Archive::facts( $protected ) );
		$this->assertSame( [], Kind_Facts::get( $protected ) );
		$this->assertSame( [], Audio_Archive::facts( $private ) );
		$this->assertSame( [], Audio_Archive::facts( $draft ) );
		$this->assertSame( [], Audio_Archive::facts( $article ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertNotSame( [], Audio_Archive::facts( $private ) );
		$this->assertNotSame( [], Audio_Archive::facts( $draft ) );
	}

	public function test_valid_password_cookie_reveals_facts(): void {
		$id = $this->audio( 'Locked', '', [ 'post_password' => 'seed-243-pass' ] );
		$this->assertSame( [], Audio_Archive::facts( $id ) );

		require_once ABSPATH . WPINC . '/class-phpass.php';
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = ( new PasswordHash( 8, true ) )->HashPassword( 'seed-243-pass' );
		$this->assertFalse( post_password_required( get_post( $id ) ) );
		$this->assertStringContainsString( 'Locked', Audio_Archive::facts( $id )['name'], 'Core prefixes a protected title even after the password is entered.' );
	}

	public function test_facts_contain_no_media_identity_transcript_or_location(): void {
		$attachment = $this->attachment(
			[
				'length' => 90,
				'title'  => 'Maple Street Band',
				'artist' => 'Maple Street Band',
			],
			'Voice memo at Maple Street Cafe',
			'2026/09/maple-street-cafe.m4a'
		);
		$url   = (string) wp_get_attachment_url( $attachment );
		$id    = $this->audio( 'Public recording', $this->audio_block( $url, $attachment ) . $this->transcript( 'Private transcript fixture.' ) );
		$facts = Audio_Archive::facts( $id );
		$json  = (string) wp_json_encode( $facts );

		foreach ( [ 'Maple Street', 'maple-street-cafe', 'example.org/wp-content', 'Private transcript fixture.', $url ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, $secret );
		}
		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/lat|lon|geo|place|location|address/i', $key );
		}
	}

	public function test_a_title_generated_from_a_hidden_location_is_synthetic_and_never_a_p_name(): void {
		$id = $this->audio( 'Voice memo at Maple Street Cafe', '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->' );
		update_post_meta( $id, Title_Privacy::META_KEY, Title_Privacy::SOURCE_LOCATION );
		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'private' );

		$this->assertTrue( \PKIW\post_title_is_hidden( get_post( $id ) ), 'The fixture title names a hidden location.' );
		$facts = Audio_Archive::facts( $id );

		$this->assertFalse( $facts['name_is_title'], 'A hidden-location title is not a real title, so it gets no p-name (X8).' );
		$this->assertSame( \PKIW\untitled_name( get_post( $id ), false ), $facts['name'] );
		$this->assertStringNotContainsString( 'Maple Street', (string) wp_json_encode( $facts ) );

		update_post_meta( $id, Meta_Fields::PREFIX . 'geo_privacy', 'public' );
		$shown = Audio_Archive::facts( $id );
		$this->assertTrue( $shown['name_is_title'], 'With the place public the stored title is a real title.' );
		$this->assertSame( 'Voice memo at Maple Street Cafe', $shown['name'] );
	}

	public function test_an_untitled_post_is_never_named_from_its_attachment(): void {
		$attachment = $this->attachment(
			[
				'length' => 90,
				'title'  => 'Maple Street Band',
				'artist' => 'Maple Street Band',
			],
			'Voice memo at Maple Street Cafe',
			'2026/09/maple-street-cafe.m4a'
		);
		$id    = $this->audio( '', $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) );
		$facts = Audio_Archive::facts( $id );

		$this->assertFalse( $facts['name_is_title'] );
		$this->assertSame( \PKIW\untitled_name( get_post( $id ), false ), $facts['name'] );
		$this->assertStringNotContainsString( 'Maple Street', $facts['name'] );
		$this->assertStringNotContainsString( 'maple-street-cafe', $facts['name'] );
	}

	public function test_stream_duration_line_requires_visible_audio_with_first_local_duration(): void {
		$attachment = $this->attachment( [ 'length' => 252 ] );
		$post       = get_post( $this->audio( 'Duration', $this->audio_block( (string) wp_get_attachment_url( $attachment ), $attachment ) ) );
		$duration   = \PKIW\attachment_duration( $attachment );
		$this->assertIsArray( $duration );
		$this->assertSame( '<b>before</b><p class="pk-sub pk-audio-duration">' . \PKIW\duration_time_html( $duration ) . '</p>', Audio_Archive::stream_duration_line( '<b>before</b>', $post ) );

		$article = get_post( self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $post->post_content ] ) );
		wp_set_object_terms( $article->ID, 'article', Taxonomy::TAXONOMY );
		$this->assertSame( '<b>before</b>', Audio_Archive::stream_duration_line( '<b>before</b>', $article ) );
	}

	public function test_primary_marker_marks_only_the_first_player_and_resets_per_content_pass(): void {
		$first   = 'https://audio.example.org/first.mp3';
		$second  = 'https://audio.example.org/second.mp3';
		$id      = $this->audio( 'Two players', $this->audio_block( $first ) . $this->audio_block( $second ) );
		$html_one = $this->singular_content( $id );
		$html_two = (string) apply_filters( 'the_content', get_post_field( 'post_content', $id ) );

		foreach ( [ $html_one, $html_two ] as $html ) {
			$this->assertSame( 1, substr_count( $html, 'data-pkiw-primary-media="1"' ) );
			$this->assertLessThan( strpos( $html, $first ), strpos( $html, 'data-pkiw-primary-media="1"' ) );
			$this->assertLessThan( strpos( $html, $second ), strpos( $html, 'data-pkiw-primary-media="1"' ) );
		}
	}

	public function test_embed_and_micropub_primary_tags_receive_the_marker(): void {
		$embed = $this->singular_content( $this->audio( 'Embed', $this->embed_block() ) );
		$this->assertMatchesRegularExpression( '/<figure[^>]*data-pkiw-primary-media="1"/', $embed );

		$micropub = $this->singular_content( $this->audio( 'Micropub', $this->micropub_block( 'https://audio.example.org/clip.mp3' ) ) );
		$this->assertMatchesRegularExpression( '/<p[^>]*data-pkiw-primary-media="1"/', $micropub );
	}

	public function test_marker_skips_no_player_wrong_kind_related_loop_and_protected_post(): void {
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', $this->singular_content( $this->audio( 'No player', '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->' ) ) );

		$article = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $this->audio_block( 'https://audio.example.org/article.mp3' ) ] );
		wp_set_object_terms( $article, 'article', Taxonomy::TAXONOMY );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', $this->singular_content( $article ) );

		$main    = $this->audio( 'Main', '<!-- wp:paragraph --><p>Main.</p><!-- /wp:paragraph -->' );
		$related = $this->audio( 'Related', $this->audio_block( 'https://audio.example.org/related.mp3' ) );
		$this->go_to( get_permalink( $main ) );
		the_post();
		$GLOBALS['post'] = get_post( $related );
		setup_postdata( $GLOBALS['post'] );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', do_blocks( get_post_field( 'post_content', $related ) ) );

		$protected = $this->audio( 'Protected', $this->audio_block( 'https://audio.example.org/protected.mp3' ), [ 'post_password' => 'seed-243-pass' ] );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', $this->singular_content( $protected ) );
	}

	public function test_marker_skips_feed_atproto_markdown_and_rest_contexts(): void {
		$id = $this->audio( 'Contexts', $this->audio_block( 'https://audio.example.org/context.mp3' ) );

		$this->go_to( get_post_comments_feed_link( $id ) );
		if ( have_posts() ) {
			the_post();
		}
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', apply_filters( 'the_content', get_post_field( 'post_content', $id ) ) );

		foreach ( [ 'atproto' => '1', 'markdown_request' => '1', 'format' => 'markdown' ] as $key => $value ) {
			$this->go_to( get_permalink( $id ) );
			the_post();
			set_query_var( $key, $value );
			$this->assertStringNotContainsString( 'data-pkiw-primary-media', apply_filters( 'the_content', get_post_field( 'post_content', $id ) ), $key );
			set_query_var( $key, null );
		}

		$this->go_to( get_permalink( $id ) );
		the_post();
		add_filter( 'wp_is_rest_endpoint', '__return_true' );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', apply_filters( 'the_content', get_post_field( 'post_content', $id ) ) );
		remove_filter( 'wp_is_rest_endpoint', '__return_true' );

		$GLOBALS['wp_rest_server'] = null;
		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) )->get_data();
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', (string) $data['content']['rendered'] );
	}

	public function test_a_related_post_with_the_same_player_does_not_take_the_marker(): void {
		$content = $this->audio_block( 'https://audio.example.org/shared.mp3' );
		$main    = $this->audio( 'Main', $content );
		$related = $this->audio( 'Related', $content );

		$this->go_to( get_permalink( $main ) );
		the_post();
		$GLOBALS['post'] = get_post( $related ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		$this->assertStringNotContainsString( 'data-pkiw-primary-media', do_blocks( $content ), 'A related loop post never takes the main post marker.' );

		$GLOBALS['post'] = get_post( $main ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		$this->assertSame( 1, substr_count( (string) apply_filters( 'the_content', $content ), 'data-pkiw-primary-media="1"' ), 'The main post still gets its marker afterwards.' );
	}

	/**
	 * A core/embed as the editor saves it: the URL sits on its own line, so core's
	 * autoembed (the_content priority 8) rewrites it into provider HTML before the
	 * block renders.
	 */
	private function autoembed_block( string $url ): string {
		return '<!-- wp:embed {"url":"' . $url . '","type":"rich","providerNameSlug":"soundcloud","responsive":true} --><figure class="wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud"><div class="wp-block-embed__wrapper">' . "\n" . $url . "\n" . '</div></figure><!-- /wp:embed -->';
	}

	public function test_an_autoembedded_first_player_receives_the_marker_once(): void {
		$stub = static function ( $result, $url ) {
			return 0 === strpos( (string) $url, 'https://soundcloud.com/' ) ? '<iframe title="Embedded player" src="https://w.soundcloud.com/player/?url=' . rawurlencode( (string) $url ) . '"></iframe>' : $result;
		};
		add_filter( 'pre_oembed_result', $stub, 10, 2 );

		try {
			$one  = $this->singular_content( $this->audio( 'Autoembed', $this->autoembed_block( 'https://soundcloud.com/artist/one' ) ) );
			$both = $this->singular_content( $this->audio( 'Two autoembeds', $this->autoembed_block( 'https://soundcloud.com/artist/one' ) . $this->autoembed_block( 'https://soundcloud.com/artist/two' ) ) );
			$same = $this->singular_content( $this->audio( 'Identical autoembeds', $this->autoembed_block( 'https://soundcloud.com/artist/one' ) . $this->autoembed_block( 'https://soundcloud.com/artist/one' ) ) );
		} finally {
			remove_filter( 'pre_oembed_result', $stub, 10 );
		}

		$this->assertStringContainsString( 'w.soundcloud.com/player', $one, 'Autoembed rewrote the URL line before the block rendered.' );
		$this->assertSame( 1, substr_count( $one, 'data-pkiw-primary-media="1"' ) );
		$this->assertMatchesRegularExpression( '/<figure[^>]*data-pkiw-primary-media="1"[^>]*>.*w\.soundcloud\.com/s', $one );

		$this->assertSame( 1, substr_count( $both, 'data-pkiw-primary-media="1"' ) );
		$this->assertLessThan( strpos( $both, 'artist%2Ftwo' ), strpos( $both, 'data-pkiw-primary-media="1"' ) );

		$this->assertSame( 1, substr_count( $same, 'data-pkiw-primary-media="1"' ), 'Two identical embeds mark only the first.' );
	}

	public function test_a_player_inside_a_synced_pattern_never_takes_the_marker_before_the_stored_first_player(): void {
		$pattern = self::factory()->post->create(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Pattern with audio',
				'post_content' => $this->audio_block( 'https://audio.example.org/inside-pattern.mp3' ),
			]
		);
		$id      = $this->audio( 'Synced', '<!-- wp:block {"ref":' . $pattern . '} /-->' . $this->audio_block( 'https://audio.example.org/stored-first.mp3' ) );
		$this->assertSame( 'https://audio.example.org/stored-first.mp3', \PKIW\first_media_block( $id, 'audio' )['url'], 'A synced pattern is not expanded when the stored first player is chosen.' );

		$html = $this->singular_content( $id );
		$this->assertSame( 1, substr_count( $html, 'data-pkiw-primary-media="1"' ) );
		$this->assertMatchesRegularExpression( '/<figure[^>]*data-pkiw-primary-media="1"[^>]*>\s*<audio[^>]*stored-first\.mp3/s', $html );
		$this->assertDoesNotMatchRegularExpression( '/data-pkiw-primary-media="1"[^>]*>\s*<audio[^>]*inside-pattern\.mp3/s', $html );
	}
}

<?php
/**
 * The video kind's facts reader (issue 242).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Taxonomy;
use PKIW\Video_Archive;

/**
 * Video_Archive::facts() exposes one safe, local primary player to themes.
 *
 * @group integration
 */
final class VideoFactsTest extends WP_UnitTestCase {

	/**
	 * The facts keys, in order.
	 *
	 * @var string[]
	 */
	private const KEYS = [
		'has_player',
		'player',
		'provider',
		'embed_url',
		'attachment_id',
		'src',
		'is_local',
		'poster_id',
		'poster_url',
		'tracks',
		'picture',
		'runtime_seconds',
		'runtime_iso',
		'runtime_clock',
		'runtime_human',
		'name',
		'name_is_title',
		'date_iso',
		'date_display',
		'published_iso',
		'primary_marker',
	];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Video_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A video attachment row with no file on disk.
	 *
	 * @param string   $file   Upload-relative file.
	 * @param int|null $length Runtime seconds, or null for no length key.
	 */
	private function video_attachment( string $file, ?int $length ): int {
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
	 *
	 * @param string $file Upload-relative file.
	 * @param string $alt  Stored alt text.
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
	 *
	 * @param int         $id         Attachment ID; zero omits the id attribute.
	 * @param string|null $inner_html Saved figure HTML, or null for the normal fixture.
	 */
	private function video_block( int $id, ?string $inner_html = null ): string {
		$attrs = $id > 0 ? ' {"id":' . $id . '}' : '';
		$html  = null === $inner_html
			? '<figure class="wp-block-video"><video controls src="' . esc_url( (string) wp_get_attachment_url( $id ) ) . '"></video></figure>'
			: $inner_html;

		return '<!-- wp:video' . $attrs . ' -->' . $html . '<!-- /wp:video -->';
	}

	/**
	 * A saved core/embed block.
	 */
	private function embed_block( string $url = 'https://www.youtube.com/watch?v=seed242' ): string {
		return '<!-- wp:embed {"url":"' . esc_url( $url ) . '","type":"video","providerNameSlug":"youtube"} -->'
			. '<figure class="wp-block-embed is-type-video is-provider-youtube"></figure>'
			. '<!-- /wp:embed -->';
	}

	/**
	 * A post assigned to the video kind.
	 *
	 * @param string               $title   Post title.
	 * @param string               $content Stored content.
	 * @param array<string, mixed> $args    Extra post arguments.
	 */
	private function video_post( string $title, string $content, array $args = [] ): int {
		$id = (int) self::factory()->post->create(
			array_merge(
				[
					'post_status'   => 'publish',
					'post_title'    => $title,
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

	public function test_reader_registration_keys_and_public_door_are_exact_and_idempotent(): void {
		$media = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$id    = $this->video_post( 'Seed Orchard Walk', $this->video_block( $media ) );

		$entry = Kind_Facts::reader( 'video' );
		$this->assertNotNull( $entry );
		$this->assertSame( [ Video_Archive::class, 'facts' ], $entry['reader'] );
		$this->assertSame( [], $entry['location'] );
		$this->assertSame( self::KEYS, array_keys( Video_Archive::facts( $id ) ) );
		$this->assertSame( Video_Archive::facts( $id ), Kind_Facts::get( $id ) );

		$before = Kind_Facts::reader( 'video' );
		( new Video_Archive() )->register();
		( new Video_Archive() )->register();
		$this->assertSame( $before, Kind_Facts::reader( 'video' ) );
	}

	public function test_hidden_post_states_return_no_facts_and_private_is_visible_to_an_administrator(): void {
		$media    = $this->video_attachment( '2026/08/seed-242-hidden.mp4', 134 );
		$content  = $this->video_block( $media );
		$protected = $this->video_post( 'Seed Locked Reel', $content, [ 'post_password' => 'seed-242' ] );
		$private  = $this->video_post( 'Seed Private Reel', $content, [ 'post_status' => 'private' ] );
		$draft    = $this->video_post( 'Seed Draft Reel', $content, [ 'post_status' => 'draft' ] );
		$future   = $this->video_post( 'Seed Future Reel', $content, [ 'post_status' => 'future', 'post_date' => '2030-08-20 10:00:00', 'post_date_gmt' => '2030-08-20 14:00:00' ] );
		$trashed  = $this->video_post( 'Seed Trashed Reel', $content );
		wp_trash_post( $trashed );

		foreach ( [ $protected, $private, $draft, $future, $trashed ] as $id ) {
			$this->assertSame( [], Video_Archive::facts( $id ), (string) $id );
			$this->assertSame( [], Kind_Facts::get( $id ), (string) $id );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $media, Video_Archive::facts( $private )['attachment_id'] );
	}

	public function test_kind_facts_uses_only_the_first_kind_term(): void {
		$media = $this->video_attachment( '2026/08/seed-242-note.mp4', 134 );
		$id    = $this->video_post( 'Seed Note With Video', $this->video_block( $media ) );
		$this->assertNotWPError( wp_set_object_terms( $id, [ 'note', 'video' ], Taxonomy::TAXONOMY ) );
		$this->assertSame( 'note', Kind_Facts::kind_of( get_post( $id ) ) );

		$this->assertSame( [], Kind_Facts::get( $id ) );
	}

	public function test_local_video_featured_picture_and_short_runtime_are_complete(): void {
		$media    = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$featured = $this->image_attachment( '2026/08/seed-242-f1.png', 'A sunlit orchard path' );
		$id       = $this->video_post( 'Seed Orchard Walk', $this->video_block( $media ) );
		set_post_thumbnail( $id, $featured );

		$facts = Video_Archive::facts( $id );

		$this->assertTrue( $facts['has_player'] );
		$this->assertSame( 'video', $facts['player'] );
		$this->assertSame( '', $facts['provider'] );
		$this->assertSame( '', $facts['embed_url'] );
		$this->assertSame( $media, $facts['attachment_id'] );
		$this->assertSame( wp_get_attachment_url( $media ), $facts['src'] );
		$this->assertTrue( $facts['is_local'] );
		$this->assertSame( 'featured', $facts['picture']['source'] );
		$this->assertSame( $featured, $facts['picture']['attachment_id'] );
		$this->assertSame( 'A sunlit orchard path', $facts['picture']['alt'] );
		$this->assertTrue( $facts['picture']['suppress_featured'] );
		$this->assertSame( 134, $facts['runtime_seconds'] );
		$this->assertSame( 'PT2M14S', $facts['runtime_iso'] );
		$this->assertSame( '2:14', $facts['runtime_clock'] );
		$this->assertSame( '2 minutes, 14 seconds', $facts['runtime_human'] );
		$this->assertSame( Video_Archive::MARKER_ATTRIBUTE, $facts['primary_marker'] );
	}

	public function test_local_poster_becomes_the_picture_only_without_a_featured_image(): void {
		$media  = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$poster = $this->image_attachment( '2026/08/seed-242-p1.png', 'A still from the orchard' );
		$html   = '<figure class="wp-block-video"><video controls src="' . esc_url( (string) wp_get_attachment_url( $media ) ) . '" poster="' . esc_url( (string) wp_get_attachment_url( $poster ) ) . '"></video></figure>';
		$id     = $this->video_post( 'Seed Poster Reel', $this->video_block( $media, $html ) );

		$facts = Video_Archive::facts( $id );

		$this->assertSame( $poster, $facts['poster_id'] );
		$this->assertSame( wp_get_attachment_url( $poster ), $facts['poster_url'] );
		$this->assertSame( 'poster', $facts['picture']['source'] );
		$this->assertSame( $poster, $facts['picture']['attachment_id'] );
		$this->assertSame( 'A still from the orchard', $facts['picture']['alt'] );
		$this->assertFalse( $facts['picture']['remote'] );
		$this->assertFalse( $facts['picture']['suppress_featured'] );
	}

	public function test_long_and_missing_attachment_lengths_have_the_documented_runtime_shapes(): void {
		$long = $this->video_attachment( '2026/08/seed-242-m2.mp4', 3725 );
		$none = $this->video_attachment( '2026/08/seed-242-m3.mp4', null );

		$this->assertSame( '1:02:05', Video_Archive::facts( $this->video_post( 'Seed Long Reel', $this->video_block( $long ) ) )['runtime_clock'] );

		$facts = Video_Archive::facts( $this->video_post( 'Seed Silent Clock', $this->video_block( $none ) ) );
		$this->assertSame( wp_get_attachment_url( $none ), $facts['src'] );
		$this->assertSame( 0, $facts['runtime_seconds'] );
		$this->assertSame( '', $facts['runtime_iso'] );
		$this->assertSame( '', $facts['runtime_clock'] );
		$this->assertSame( '', $facts['runtime_human'] );
	}

	public function test_embed_facts_keep_provider_url_and_featured_picture_but_no_file_facts(): void {
		$featured = $this->image_attachment( '2026/08/seed-242-f1.png', 'A painted video card' );
		$url      = 'https://www.youtube.com/watch?v=seed242';
		$id       = $this->video_post( 'Seed Embed', $this->embed_block( $url ) );
		set_post_thumbnail( $id, $featured );

		$facts = Video_Archive::facts( $id );

		$this->assertTrue( $facts['has_player'] );
		$this->assertSame( 'embed', $facts['player'] );
		$this->assertSame( 'youtube', $facts['provider'] );
		$this->assertSame( $url, $facts['embed_url'] );
		$this->assertSame( 0, $facts['attachment_id'] );
		$this->assertSame( '', $facts['src'] );
		$this->assertFalse( $facts['is_local'] );
		$this->assertSame( 0, $facts['runtime_seconds'] );
		$this->assertSame( 'featured', $facts['picture']['source'] );
	}

	public function test_only_the_first_player_supplies_facts(): void {
		$media   = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$content = $this->embed_block() . $this->video_block( $media );
		$facts   = Video_Archive::facts( $this->video_post( 'Seed Embed First', $content ) );

		$this->assertSame( 'embed', $facts['player'] );
		$this->assertSame( '', $facts['src'] );
		$this->assertSame( 0, $facts['runtime_seconds'] );
	}

	public function test_video_tracks_keep_two_local_rows_and_drop_the_remote_row(): void {
		$media   = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$uploads = wp_get_upload_dir();
		$english = trailingslashit( (string) $uploads['baseurl'] ) . '2026/08/seed-242-en.vtt';
		$spanish = trailingslashit( (string) $uploads['baseurl'] ) . '2026/08/seed-242-es.vtt';
		$html    = '<figure class="wp-block-video"><video autoplay muted loop src="' . esc_url( (string) wp_get_attachment_url( $media ) ) . '">'
			. '<track kind="captions" srclang="en" label="English" src="' . esc_url( $english ) . '">'
			. '<track kind="subtitles" srclang="es" label="Español" src="' . esc_url( $spanish ) . '">'
			. '<track kind="captions" srclang="fr" label="Remote" src="https://media.example.org/seed-242-fr.vtt">'
			. '</video></figure>';
		$facts   = Video_Archive::facts( $this->video_post( 'Seed Captioned Reel', $this->video_block( $media, $html ) ) );

		$this->assertSame(
			[
				[ 'kind' => 'captions', 'srclang' => 'en', 'label' => 'English', 'src' => $english ],
				[ 'kind' => 'subtitles', 'srclang' => 'es', 'label' => 'Español', 'src' => $spanish ],
			],
			$facts['tracks']
		);
	}

	public function test_remote_media_never_enters_facts_and_a_valid_id_ignores_stale_html(): void {
		$remote_html = '<figure class="wp-block-video"><video src="https://media.example.org/seed-242.mp4" poster="https://media.example.org/seed-242.png"></video></figure>';
		$remote      = Video_Archive::facts( $this->video_post( 'Seed Remote Reel', $this->video_block( 0, $remote_html ) ) );

		$this->assertTrue( $remote['has_player'] );
		$this->assertSame( '', $remote['src'] );
		$this->assertFalse( $remote['is_local'] );
		$this->assertSame( 0, $remote['poster_id'] );
		$this->assertSame( '', $remote['poster_url'] );
		$this->assertSame( '', $remote['picture']['source'] );
		$this->assertSame( 0, $remote['runtime_seconds'] );
		$this->assertStringNotContainsString( 'media.example.org', (string) wp_json_encode( $remote ) );

		$media = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$stale = Video_Archive::facts( $this->video_post( 'Seed Stale Source', $this->video_block( $media, $remote_html ) ) );
		$this->assertSame( wp_get_attachment_url( $media ), $stale['src'] );
		$this->assertStringNotContainsString( 'media.example.org', $stale['src'] );
	}

	public function test_invalid_and_missing_video_sources_still_report_a_player_without_a_src(): void {
		$image = $this->image_attachment( '2026/08/seed-242-p1.png' );
		$wrong = Video_Archive::facts( $this->video_post( 'Seed Image ID', $this->video_block( $image ) ) );
		$empty = Video_Archive::facts( $this->video_post( 'Seed Empty Player', $this->video_block( 0, '<figure class="wp-block-video"><video></video></figure>' ) ) );

		foreach ( [ $wrong, $empty ] as $facts ) {
			$this->assertTrue( $facts['has_player'] );
			$this->assertSame( '', $facts['src'] );
			$this->assertSame( 0, $facts['runtime_seconds'] );
		}
	}

	public function test_a_micropub_video_link_is_not_a_player_block(): void {
		$content = '<!-- wp:paragraph --><p><a class="u-video" href="https://media.example.org/seed-242.mp4">Watch</a></p><!-- /wp:paragraph -->';
		$facts   = Video_Archive::facts( $this->video_post( 'Seed Micropub Link', $content ) );

		$this->assertFalse( $facts['has_player'] );
		$this->assertSame( '', $facts['player'] );
		$this->assertSame( '', $facts['src'] );
		$this->assertSame( 0, $facts['runtime_seconds'] );
	}

	public function test_names_use_the_requested_post_not_the_loop_global(): void {
		$media    = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$titled   = $this->video_post( 'Seed Requested Reel', $this->video_block( $media ) );
		$untitled = $this->video_post( '', $this->video_block( $media ), [ 'post_date' => '2026-08-03 09:15:00', 'post_date_gmt' => '2026-08-03 13:15:00' ] );
		$spaces   = $this->video_post( "  \t ", $this->video_block( $media ) );
		$other    = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Wrong Global Title' ] );
		$GLOBALS['post'] = get_post( $other );

		$this->assertSame( 'Seed Requested Reel', Video_Archive::facts( $titled )['name'] );
		$this->assertTrue( Video_Archive::facts( $titled )['name_is_title'] );
		$this->assertSame( \PKIW\untitled_name( get_post( $untitled ), false ), Video_Archive::facts( $untitled )['name'] );
		$this->assertFalse( Video_Archive::facts( $untitled )['name_is_title'] );
		$this->assertSame( 'Video, August 3, 2026', Video_Archive::facts( $untitled )['name'] );
		$this->assertSame( \PKIW\untitled_name( get_post( $spaces ), false ), Video_Archive::facts( $spaces )['name'] );
		$this->assertFalse( Video_Archive::facts( $spaces )['name_is_title'] );
		$this->assertStringNotContainsString( 'Wrong Global Title', Video_Archive::facts( $titled )['name'] );
	}

	public function test_dates_use_the_local_calendar_day_and_the_utc_published_instant(): void {
		$media = $this->video_attachment( '2026/08/seed-242-m1.mp4', 134 );
		$id    = $this->video_post(
			'Seed New Year Eve Reel',
			$this->video_block( $media ),
			[
				'post_date'     => '2025-12-31 23:30:00',
				'post_date_gmt' => '2026-01-01 04:30:00',
			]
		);

		$facts = Video_Archive::facts( $id );

		$this->assertSame( '2025-12-31', $facts['date_iso'] );
		$this->assertSame( 'December 31, 2025', $facts['date_display'] );
		$this->assertSame( '2026-01-01T04:30:00+00:00', $facts['published_iso'] );
	}

	public function test_attachment_titles_captions_descriptions_and_extra_metadata_never_leave_facts(): void {
		$media    = $this->video_attachment( '2026/08/seed-242-private.mp4', 134 );
		$poster   = $this->image_attachment( '2026/08/seed-242-private-poster.png', 'Safe poster alt' );
		$featured = $this->image_attachment( '2026/08/seed-242-private-featured.png', 'Safe featured alt' );
		$markers  = [];
		foreach ( [ $media => 'media', $poster => 'poster', $featured => 'featured' ] as $attachment_id => $label ) {
			$markers[] = 'Private ' . $label . ' title 242';
			$markers[] = 'Private ' . $label . ' caption 242';
			$markers[] = 'Private ' . $label . ' description 242';
			wp_update_post(
				[
					'ID'           => $attachment_id,
					'post_title'   => $markers[ count( $markers ) - 3 ],
					'post_excerpt' => $markers[ count( $markers ) - 2 ],
					'post_content' => $markers[ count( $markers ) - 1 ],
				]
			);
		}
		$metadata = wp_get_attachment_metadata( $media );
		foreach ( [ 'artist', 'title', 'album', 'filesize', 'created_timestamp' ] as $key ) {
			$metadata[ $key ] = 'Private ' . $key . ' marker 242';
			$markers[]        = $metadata[ $key ];
		}
		wp_update_attachment_metadata( $media, $metadata );
		$html = '<figure class="wp-block-video"><video src="' . esc_url( (string) wp_get_attachment_url( $media ) ) . '" poster="' . esc_url( (string) wp_get_attachment_url( $poster ) ) . '"></video></figure>';
		$id   = $this->video_post( 'Seed Privacy Reel', $this->video_block( $media, $html ) );
		set_post_thumbnail( $id, $featured );

		$json = (string) wp_json_encode( Video_Archive::facts( $id ) );
		foreach ( $markers as $marker ) {
			$this->assertStringNotContainsString( $marker, $json );
		}
	}

	public function test_reading_facts_writes_no_post_meta_or_options(): void {
		global $wpdb;
		$media         = $this->video_attachment( '2026/08/seed-242-read-only.mp4', 134 );
		$id            = $this->video_post( 'Seed Read Only Reel', $this->video_block( $media ) );
		$before_meta   = get_post_meta( $id );
		$before_options = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} ORDER BY option_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		Video_Archive::facts( $id );

		$this->assertSame( $before_meta, get_post_meta( $id ) );
		$this->assertSame( $before_options, $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} ORDER BY option_id", ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_facts_have_no_location_shaped_key(): void {
		$media = $this->video_attachment( '2026/08/seed-242-no-location.mp4', 134 );
		$facts = Video_Archive::facts( $this->video_post( 'Seed No Location Reel', $this->video_block( $media ) ) );

		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/lat|lon|geo|location/i', $key );
		}
	}

	public function test_geo_meta_never_enters_facts(): void {
		$media = $this->video_attachment( '2026/08/seed-242-geo.mp4', 134 );
		$id    = $this->video_post( 'Seed Geo Reel', $this->video_block( $media ) );
		update_post_meta( $id, 'geo_latitude', 0.5 );
		update_post_meta( $id, 'geo_longitude', -0.5 );
		update_post_meta( $id, 'geo_public', 1 );

		$json = (string) wp_json_encode( Video_Archive::facts( $id ) );
		$this->assertStringNotContainsString( '0.5', $json );
		$this->assertStringNotContainsString( '-0.5', $json );
	}

	public function test_stream_card_media_property_accepts_only_a_visible_local_video(): void {
		$media     = $this->video_attachment( '2026/08/seed-242-stream.mp4', 134 );
		$url       = (string) wp_get_attachment_url( $media );
		$local     = $this->video_post( 'Seed Local Stream Reel', $this->video_block( $media ) );
		$embed     = $this->video_post( 'Seed Embed Stream Reel', $this->embed_block() );
		$remote    = $this->video_post( 'Seed Remote Stream Reel', $this->video_block( 0, '<figure class="wp-block-video"><video src="https://media.example.org/seed-242.mp4"></video></figure>' ) );
		$protected = $this->video_post( 'Seed Locked Stream Reel', $this->video_block( $media ), [ 'post_password' => 'seed-242' ] );
		$private   = $this->video_post( 'Seed Private Stream Reel', $this->video_block( $media ), [ 'post_status' => 'private' ] );
		$note      = $this->video_post( 'Seed Note Stream Reel', $this->video_block( $media ) );
		$this->assertNotWPError( wp_set_object_terms( $note, [ 'note', 'video' ], Taxonomy::TAXONOMY ) );

		$this->assertSame( '<data class="u-video" value="' . esc_url( $url ) . '"></data>', \PKIW\entry_media_property_html( get_post( $local ) ) );
		foreach ( [ $embed, $remote, $protected, $private, $note ] as $id ) {
			$this->assertSame( '', \PKIW\entry_media_property_html( get_post( $id ) ), (string) $id );
		}
	}
}

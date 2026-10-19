<?php
/**
 * Jam facts reader coverage for issue 235.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Jam_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * The jam facts reader normalizes card, meta, Micropub and image data.
 *
 * @group integration
 */
final class JamFactsTest extends WP_UnitTestCase {

	/**
	 * Readers registered before each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $readers_before = [];

	/**
	 * Register terms, meta and deterministic request state.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		add_filter(
			'pre_http_request',
			static fn() => new WP_Error( 'blocked', 'No network in tests.' )
		);
		wp_set_current_user( 0 );
		$this->readers_before = $this->registered()->getValue();
	}

	/**
	 * Restore the shared reader registry and anonymous state.
	 */
	public function tear_down(): void {
		$this->registered()->setValue( null, $this->readers_before );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Kind_Facts' private reader registry.
	 */
	private function registered(): ReflectionProperty {
		$registered = new ReflectionProperty( Kind_Facts::class, 'registered' );
		$registered->setAccessible( true );

		return $registered;
	}

	/**
	 * A serialized jam-card comment.
	 *
	 * @param array<string, mixed> $attrs Card attributes.
	 */
	private function card( array $attrs ): string {
		return '<!-- wp:post-kinds-indieweb/jam-card ' . serialize_block_attributes( $attrs ) . ' /-->';
	}

	/**
	 * Insert a post and assign one kind.
	 *
	 * @param array<string, mixed>|null $attrs Card attributes, or null for no card.
	 * @param array<string, mixed>      $args  Post arguments.
	 * @param string                    $kind  Kind slug.
	 */
	private function jam( ?array $attrs = [], array $args = [], string $kind = 'jam' ): int {
		$content = null === $attrs ? (string) ( $args['post_content'] ?? '' ) : $this->card( $attrs );
		unset( $args['post_content'] );
		$id = wp_insert_post(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Hollow Light',
					'post_date'    => '2026-08-31 23:30:00',
					'post_content' => wp_slash( $content ),
				],
				$args
			),
			true
		);
		$this->assertIsInt( $id );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return $id;
	}

	/**
	 * An uploaded image attachment.
	 */
	private function image(): int {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	/**
	 * Jam facts for a post.
	 *
	 * @return array<string, mixed>
	 */
	private function facts( int $id ): array {
		return Jam_Archive::facts( $id );
	}

	public function test_register_adds_the_reader_to_an_empty_registry(): void {
		$this->assertNotNull( Kind_Facts::reader( 'jam' ), 'Plugin initialization registers the jam reader.' );

		$this->registered()->setValue( null, [] );
		$this->assertNull( Kind_Facts::reader( 'jam' ) );

		( new Jam_Archive() )->register();
		$this->assertNotNull( Kind_Facts::reader( 'jam' ) );
	}

	public function test_card_values_beat_meta_and_the_first_card_wins(): void {
		$id = $this->jam(
			null,
			[
				'post_content' => $this->card(
					[
						'title'  => 'Hollow <strong>Light</strong>',
						'artist' => 'Marlowe &amp; Kite',
						'album'  => 'Signal Bloom',
						'note'   => 'First<br>listen',
						'url'    => 'https://tracks.example.org/t/hollow',
						'rel'    => 'NoFollow bad_token! me nofollow',
					]
				) . $this->card( [ 'title' => 'Second Track' ] ),
			]
		);
		update_post_meta( $id, Meta_Fields::PREFIX . 'jam_track', 'Stale Track' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'jam_artist', 'Stale Artist' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'jam_album', 'Stale Album' );
		update_post_meta( $id, Meta_Fields::PREFIX . 'jam_url', 'https://tracks.example.org/t/stale' );

		$facts = $this->facts( $id );

		$this->assertTrue( $facts['has_card'] );
		$this->assertSame( 'Hollow Light', $facts['title'] );
		$this->assertSame( 'Marlowe & Kite', $facts['artist'] );
		$this->assertSame( 'Signal Bloom', $facts['album'] );
		$this->assertSame( 'https://tracks.example.org/t/hollow', $facts['url'] );
		$this->assertSame( 'card', $facts['url_source'] );
		$this->assertSame( 'Firstlisten', $facts['note'] );
		$this->assertSame( 'nofollow badtoken me', $facts['rel'] );
		$this->assertSame( 'track', $facts['name_source'] );
	}

	public function test_quick_post_and_micropub_shapes_use_their_available_sources(): void {
		$quick = $this->jam( null, [ 'post_title' => 'The Quiet Orchards by Marlowe Kite' ] );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_track', 'The Quiet Orchards' );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_artist', 'Marlowe Kite' );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_cover', 'https://covers.example.net/quiet.png' );
		update_post_meta( $quick, Meta_Fields::PREFIX . 'jam_url', 'https://tracks.example.org/t/quiet' );
		$quick_facts = $this->facts( $quick );
		$this->assertFalse( $quick_facts['has_card'] );
		$this->assertSame( 'The Quiet Orchards', $quick_facts['title'] );
		$this->assertSame( 'Marlowe Kite', $quick_facts['artist'] );
		$this->assertSame( 'https://tracks.example.org/t/quiet', $quick_facts['url'] );
		$this->assertSame( 'meta', $quick_facts['url_source'] );
		$this->assertSame( 'https://covers.example.net/quiet.png', $quick_facts['picture']['url'] );
		$this->assertSame( 'track', $quick_facts['name_source'] );

		$micropub = $this->jam(
			null,
			[
				'post_title'   => '',
				'post_content' => '<p>Jamming to <a class="u-jam-of" href="https://tracks.example.org/t/1">https://tracks.example.org/t/1</a></p>',
			]
		);
		$micropub_facts = $this->facts( $micropub );
		$this->assertSame( '', $micropub_facts['title'] );
		$this->assertSame( '', $micropub_facts['artist'] );
		$this->assertSame( 'https://tracks.example.org/t/1', $micropub_facts['url'] );
		$this->assertSame( 'content', $micropub_facts['url_source'] );
		$this->assertSame( 'untitled', $micropub_facts['name_source'] );
		$this->assertFalse( $micropub_facts['name_is_title'] );
		$this->assertStringStartsWith( 'Jam,', $micropub_facts['name'] );

		$bad = $this->jam(
			null,
			[
				'post_title'   => '',
				'post_content' => '<p><a class="u-jam-of" href="javascript:alert(1)">bad</a></p>',
			]
		);
		$this->assertSame( '', $this->facts( $bad )['url'] );
		$this->assertSame( '', $this->facts( $bad )['url_source'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function date_zones(): array {
		return [
			'Chicago'  => [ 'America/Chicago' ],
			'New York' => [ 'America/New_York' ],
			'Honolulu' => [ 'Pacific/Honolulu' ],
		];
	}

	/**
	 * @dataProvider date_zones
	 */
	public function test_jammed_day_never_shifts_across_site_timezones( string $zone ): void {
		update_option( 'timezone_string', $zone );
		$facts = $this->facts( $this->jam( [ 'title' => 'Hollow Light', 'jammedAt' => '2026-09-01T00:30' ] ) );

		$this->assertSame( '2026-09-01', $facts['jammed'] );
		$this->assertSame( 'September 1, 2026', $facts['jammed_display'] );
		$this->assertSame( '2026-09-01', $facts['date'] );
		$this->assertSame( 'September 1, 2026', $facts['date_display'] );
		$this->assertTrue( $facts['date_is_stored'] );
	}

	public function test_post_date_fills_the_date_only_when_jammed_at_is_empty(): void {
		$empty  = $this->facts( $this->jam( [ 'title' => 'Hollow Light', 'jammedAt' => '' ] ) );
		$stored = $this->facts( $this->jam( [ 'title' => 'Hollow Light', 'jammedAt' => '2026-09-01T00:30' ] ) );

		$this->assertSame( '2026-08-31', $empty['date'] );
		$this->assertFalse( $empty['date_is_stored'] );
		$this->assertSame( '2026-09-01', $stored['date'] );
		$this->assertTrue( $stored['date_is_stored'] );
	}

	public function test_name_chain_and_track_heading_match_are_explicit(): void {
		$track = $this->facts( $this->jam( [ 'title' => '  hollow light  ' ], [ 'post_title' => 'Hollow Light' ] ) );
		$this->assertSame( 'track', $track['name_source'] );
		$this->assertSame( 'hollow light', $track['name'] );
		$this->assertFalse( $track['name_is_title'] );
		$this->assertTrue( $track['track_is_h1'] );

		$different = $this->facts( $this->jam( [ 'title' => 'Hollow Light' ], [ 'post_title' => 'Hollow Light by Marlowe Kite' ] ) );
		$this->assertFalse( $different['track_is_h1'] );

		$titled = $this->facts( $this->jam( null, [ 'post_title' => 'Late-night jam' ] ) );
		$this->assertSame( 'title', $titled['name_source'] );
		$this->assertSame( 'Late-night jam', $titled['name'] );
		$this->assertTrue( $titled['name_is_title'] );
		$this->assertFalse( $titled['track_is_h1'] );

		$untitled = $this->facts( $this->jam( null, [ 'post_title' => '', 'post_content' => '<p>Listening.</p>' ] ) );
		$this->assertSame( 'untitled', $untitled['name_source'] );
		$this->assertFalse( $untitled['name_is_title'] );
	}

	public function test_hidden_posts_return_no_facts_but_authorized_private_posts_do(): void {
		$protected = $this->jam( [ 'title' => 'Locked Track' ], [ 'post_password' => 'x' ] );
		$private   = $this->jam( [ 'title' => 'Private Track' ], [ 'post_status' => 'private' ] );
		$draft     = $this->jam( [ 'title' => 'Draft Track' ], [ 'post_status' => 'draft' ] );

		$this->assertSame( [], $this->facts( $protected ) );
		$this->assertSame( [], $this->facts( $private ) );
		$this->assertSame( [], $this->facts( $draft ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertNotSame( [], $this->facts( $private ) );
		$this->assertNotSame( [], $this->facts( $draft ) );
		$this->assertSame( [], $this->facts( $protected ), 'A password remains a facts boundary for logged-in users.' );
	}

	public function test_public_shape_has_no_location_keys_and_non_jams_are_not_read(): void {
		$id    = $this->jam( [ 'title' => 'Hollow Light' ] );
		$facts = $this->facts( $id );

		$this->assertSame(
			[
				'has_card',
				'title',
				'artist',
				'album',
				'url',
				'url_source',
				'note',
				'rel',
				'cover_alt',
				'cover_alt_fallback',
				'picture',
				'jammed',
				'jammed_display',
				'date',
				'date_display',
				'date_is_stored',
				'name',
				'name_source',
				'name_is_title',
				'track_is_h1',
			],
			array_keys( $facts )
		);
		$this->assertSame( [ 'source', 'attachment_id', 'url', 'alt', 'remote', 'suppress_featured' ], array_keys( $facts['picture'] ) );
		foreach ( array_keys( $facts ) as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/lat|lon|geo|location/i', $key );
		}

		$note = $this->jam( [ 'title' => 'Not a jam reader result' ], [], 'note' );
		$this->assertSame( [], \PKIW\kind_facts( $note ) );
	}

	public function test_picture_sources_follow_featured_card_local_remote_and_empty_order(): void {
		$featured_id = $this->image();
		$cover_id    = $this->image();

		$featured = $this->jam( [ 'title' => 'Featured Track', 'cover' => (string) wp_get_attachment_url( $cover_id ) ] );
		set_post_thumbnail( $featured, $featured_id );
		$this->assertSame( 'featured', $this->facts( $featured )['picture']['source'] );

		$local   = $this->facts( $this->jam( [ 'title' => 'Local Cover', 'cover' => (string) wp_get_attachment_url( $cover_id ) ] ) )['picture'];
		$this->assertSame( 'cover', $local['source'] );
		$this->assertSame( $cover_id, $local['attachment_id'] );
		$this->assertFalse( $local['remote'] );

		$remote = $this->facts( $this->jam( [ 'title' => 'Remote Cover', 'cover' => 'https://covers.example.net/a.png' ] ) )['picture'];
		$this->assertSame( 'cover', $remote['source'] );
		$this->assertSame( 0, $remote['attachment_id'] );
		$this->assertTrue( $remote['remote'] );

		$empty = $this->facts( $this->jam( [ 'title' => 'No Cover' ] ) )['picture'];
		$this->assertSame( '', $empty['source'] );
		$this->assertSame( '', $empty['url'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function alt_fallbacks(): array {
		return [
			'both'        => [ 'Hollow Light', 'Marlowe Kite', 'Hollow Light by Marlowe Kite' ],
			'title only'  => [ 'Hollow Light', '', 'Hollow Light' ],
			'artist only' => [ '', 'Marlowe Kite', 'Marlowe Kite' ],
			'neither'     => [ '', '', '' ],
		];
	}

	/**
	 * @dataProvider alt_fallbacks
	 */
	public function test_cover_alt_fallback_uses_only_available_parts( string $title, string $artist, string $expected ): void {
		$facts = $this->facts(
			$this->jam(
				[
					'title'    => $title,
					'artist'   => $artist,
					'cover'    => 'https://covers.example.net/a.png',
					'coverAlt' => '',
				]
			)
		);

		$this->assertSame( $expected, $facts['cover_alt_fallback'] );
	}

	public function test_album_falls_back_to_meta_when_the_card_has_none(): void {
		$id = $this->jam( [ 'title' => 'Hollow Light' ] );
		update_post_meta( $id, Meta_Fields::PREFIX . 'jam_album', 'Signal Bloom' );
		$this->assertSame( 'Signal Bloom', $this->facts( $id )['album'] );

		$with_card_album = $this->jam( [ 'title' => 'Hollow Light', 'album' => 'Card Album' ] );
		update_post_meta( $with_card_album, Meta_Fields::PREFIX . 'jam_album', 'Signal Bloom' );
		$this->assertSame( 'Card Album', $this->facts( $with_card_album )['album'] );
	}

	/**
	 * A remote cover URL that Featured_Artwork already sideloaded resolves to its
	 * library copy (cover_local_copy via Featured_Artwork::SOURCE_META), so the
	 * page never hotlinks the image host.
	 */
	public function test_a_sideloaded_remote_cover_resolves_to_its_local_copy(): void {
		$url = 'https://covers.example.net/sideloaded.png';
		$id  = $this->jam( [ 'title' => 'Hollow Light', 'cover' => $url ] );

		$this->assertTrue( $this->facts( $id )['picture']['remote'], 'Without a copy the remote URL stays remote.' );

		$attachment = $this->image();
		update_post_meta( $id, \PKIW\Featured_Artwork::SOURCE_META, $url );
		update_post_meta( $id, \PKIW\Featured_Artwork::ATTACHMENT_META, $attachment );

		$after = $this->facts( $id )['picture'];
		$this->assertSame( 'cover', $after['source'] );
		$this->assertSame( $attachment, $after['attachment_id'] );
		$this->assertSame( (string) wp_get_attachment_url( $attachment ), $after['url'] );
		$this->assertFalse( $after['remote'] );
	}
}

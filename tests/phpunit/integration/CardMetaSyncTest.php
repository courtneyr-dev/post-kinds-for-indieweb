<?php
/**
 * Card_Meta_Sync integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Verifies the first kind-card block's attrs mirror into _pkiw_ meta
 * on save, and that empty attrs never clobber existing meta.
 *
 * @group integration
 */
final class CardMetaSyncTest extends WP_UnitTestCase {

	public function test_read_card_attrs_mirror_into_pkiw_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","authorName":"Rebecca Yarros","isbn":"9781649374042","publisher":"Entangled","pageCount":517} /-->',
		] );

		// save_post fired during create; assert the mirror.
		$this->assertSame( 'Fourth Wing', get_post_meta( $post_id, '_pkiw_read_title', true ) );
		$this->assertSame( 'Rebecca Yarros', get_post_meta( $post_id, '_pkiw_read_author', true ) );
		$this->assertSame( '9781649374042', get_post_meta( $post_id, '_pkiw_read_isbn', true ) );
		$this->assertSame( 'Entangled', get_post_meta( $post_id, '_pkiw_read_publisher', true ) );
		$this->assertSame( '517', get_post_meta( $post_id, '_pkiw_read_pages', true ) );
	}

	public function test_checkin_card_attrs_mirror_into_pkiw_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/checkin-card {"venueName":"Reading Terminal Market","venueType":"cafe","address":"1136 Arch St","locality":"Philadelphia","region":"PA","country":"US","latitude":39.95333,"longitude":-75.15928,"locationPrivacy":"public","venueUrl":"https://readingterminalmarket.org","photo":"https://example.com/photo.jpg"} /-->',
		] );

		$this->assertSame( 'Reading Terminal Market', get_post_meta( $post_id, '_pkiw_checkin_name', true ) );
		$this->assertSame( 'cafe', get_post_meta( $post_id, '_pkiw_checkin_type', true ) );
		$this->assertSame( '1136 Arch St', get_post_meta( $post_id, '_pkiw_checkin_address', true ) );
		$this->assertSame( 'Philadelphia', get_post_meta( $post_id, '_pkiw_checkin_locality', true ) );
		$this->assertSame( 'PA', get_post_meta( $post_id, '_pkiw_checkin_region', true ) );
		$this->assertSame( 'US', get_post_meta( $post_id, '_pkiw_checkin_country', true ) );
		$this->assertSame( '39.95333', get_post_meta( $post_id, '_pkiw_geo_latitude', true ) );
		$this->assertSame( '-75.15928', get_post_meta( $post_id, '_pkiw_geo_longitude', true ) );
		$this->assertSame( 'public', get_post_meta( $post_id, '_pkiw_geo_privacy', true ) );
		$this->assertSame( 'https://readingterminalmarket.org', get_post_meta( $post_id, '_pkiw_checkin_url', true ) );
		$this->assertSame( 'https://example.com/photo.jpg', get_post_meta( $post_id, '_pkiw_checkin_photo', true ) );
	}

	public function test_listen_card_attrs_mirror_into_pkiw_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"One","artistName":"U2","albumTitle":"Achtung Baby","coverImage":"https://coverartarchive.org/release/abc/front-500.jpg","musicbrainzId":"mbid-123","listenUrl":"https://musicbrainz.org/recording/one","rating":5} /-->',
		] );

		$this->assertSame( 'One', get_post_meta( $post_id, '_pkiw_listen_track', true ) );
		$this->assertSame( 'U2', get_post_meta( $post_id, '_pkiw_listen_artist', true ) );
		$this->assertSame( 'Achtung Baby', get_post_meta( $post_id, '_pkiw_listen_album', true ) );
		$this->assertSame( 'https://coverartarchive.org/release/abc/front-500.jpg', get_post_meta( $post_id, '_pkiw_listen_cover', true ) );
		$this->assertSame( 'mbid-123', get_post_meta( $post_id, '_pkiw_listen_mbid', true ) );
		$this->assertSame( 'https://musicbrainz.org/recording/one', get_post_meta( $post_id, '_pkiw_listen_url', true ) );
		$this->assertSame( '5', get_post_meta( $post_id, '_pkiw_listen_rating', true ) );
	}

	public function test_card_nested_in_h_entry_group_still_syncs(): void {
		// Regression: the Micropub bridge wraps its card inside an h-entry
		// core/group, and a top-level-only block walk never reached it —
		// Micropub-created posts got no _pkiw_* meta at all.
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:group {"className":"h-entry","layout":{"type":"constrained"}} --><div class="wp-block-group h-entry"><!-- wp:post-kinds-indieweb/listen-card {"trackTitle":"American Obituary","artistName":"U2"} /--></div><!-- /wp:group -->',
		] );

		$this->assertSame( 'American Obituary', get_post_meta( $post_id, '_pkiw_listen_track', true ) );
		$this->assertSame( 'U2', get_post_meta( $post_id, '_pkiw_listen_artist', true ) );
	}

	public function test_manual_meta_not_clobbered_by_empty_attr(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing"} /-->',
		] );
		update_post_meta( $post_id, '_pkiw_read_isbn', '9781649374042' );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'touch' ] );

		$this->assertSame( '9781649374042', get_post_meta( $post_id, '_pkiw_read_isbn', true ), 'empty attr must not erase existing meta' );
	}

	public function test_stale_asin_cleared_when_isbn_changes(): void {
		// Bootstrap's default stub is a no-op passthrough (see
		// tests/phpunit/bootstrap.php), so after this resave read_asin
		// stays blank — proving Card_Meta_Sync itself cleared the stale
		// value rather than the completion cascade re-deriving one.
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );
		update_post_meta( $post_id, '_pkiw_read_asin', '1649374046' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9780316219280"} /-->',
			]
		);

		$this->assertSame( '9780316219280', get_post_meta( $post_id, '_pkiw_read_isbn', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_read_asin', true ), 'stale asin must be cleared when the isbn changes' );
	}

	public function test_asin_re_derived_from_new_isbn_after_change(): void {
		// Passthrough-plus-derive stub: proves complete_on_save() (which
		// runs after Card_Meta_Sync at save_post:30) sees the cleared
		// read_asin as blank and re-fills it from the new ISBN, rather
		// than the stale ASIN surviving the resave.
		add_filter( 'pkiw_book_completion_service', static function () {
			return new class() {
				public function complete( array $book ): array {
					$book['asin'] = '0316219282'; // Derived ASIN for ISBN B.
					return $book;
				}
			};
		} );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9781649374042"} /-->',
		] );
		update_post_meta( $post_id, '_pkiw_read_asin', '1649374046' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Fourth Wing","isbn":"9780316219280"} /-->',
			]
		);

		$this->assertSame( '9780316219280', get_post_meta( $post_id, '_pkiw_read_isbn', true ) );
		$this->assertSame( '0316219282', get_post_meta( $post_id, '_pkiw_read_asin', true ), 'asin must be re-derived from the new isbn' );
	}

	public function test_eat_card_menu_fields_mirror_into_pkiw_meta(): void {
		$post_id = self::factory()->post->create( [
			// Slashed the way the editor's REST save delivers it.
			'post_content' => wp_slash( '<!-- wp:post-kinds-indieweb/eat-card {"name":"Cacio e pepe","cuisine":"Italian","rating":4,"ateAt":"2026-09-01T19:30","notes":"Peppery.\nWould order again."} /-->' ),
		] );

		$this->assertSame( 'Cacio e pepe', get_post_meta( $post_id, '_pkiw_eat_name', true ) );
		$this->assertSame( 'Italian', get_post_meta( $post_id, '_pkiw_eat_cuisine', true ) );
		$this->assertSame( '4', get_post_meta( $post_id, '_pkiw_eat_rating', true ) );
		$this->assertSame( '2026-09-01T19:30', get_post_meta( $post_id, '_pkiw_eat_ate_at', true ) );
		$this->assertSame( "Peppery.\nWould order again.", get_post_meta( $post_id, '_pkiw_eat_notes', true ), 'Notes keep line breaks.' );
	}

	public function test_drink_card_menu_fields_mirror_into_pkiw_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"Imperial stout","drinkType":"beer","brand":"Tröegs","rating":5,"drankAt":"2026-09-02T21:00","notes":"Roasty."} /-->',
		] );

		$this->assertSame( 'Imperial stout', get_post_meta( $post_id, '_pkiw_drink_name', true ) );
		$this->assertSame( 'beer', get_post_meta( $post_id, '_pkiw_drink_type', true ) );
		$this->assertSame( 'Tröegs', get_post_meta( $post_id, '_pkiw_drink_brewery', true ) );
		$this->assertSame( '5', get_post_meta( $post_id, '_pkiw_drink_rating', true ) );
		$this->assertSame( '2026-09-02T21:00', get_post_meta( $post_id, '_pkiw_drink_drank_at', true ) );
		$this->assertSame( 'Roasty.', get_post_meta( $post_id, '_pkiw_drink_notes', true ) );
	}

	public function test_drink_type_stays_unset_when_attr_omitted(): void {
		// The drink type has no default: a card saved without one leaves the
		// meta empty, so the menu files the post under its generic section.
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"Cortado"} /-->',
		] );

		$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_drink_type', true ), 'A drink saved without a type is given none.' );
	}

	public function test_drink_type_default_never_overwrites_existing_meta(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"Oolong"} /-->',
		] );
		update_post_meta( $post_id, '_pkiw_drink_type', 'tea' );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'resave' ] );

		$this->assertSame( 'tea', get_post_meta( $post_id, '_pkiw_drink_type', true ) );
	}

	/**
	 * Register a meta key the way Meta_Fields does, with a registered default.
	 *
	 * get_post_meta() returns a registered default for a post with no row, so
	 * these tests read stored rows with get_metadata_raw().
	 *
	 * @param string $key     Full meta key.
	 * @param string $default Registered default.
	 */
	private function register_with_default( string $key, string $default ): void {
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		unregister_post_meta( 'post', $key );
		register_post_meta(
			'post',
			$key,
			[
				'type'    => 'string',
				'single'  => true,
				'default' => $default,
			]
		);
	}

	public function test_a_card_default_writes_a_row_for_a_rowless_key(): void {
		( new \PKIW\Meta_Fields() )->register_meta_fields();

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga"} /-->',
		] );

		$this->assertSame( 'reading', get_metadata_raw( 'post', $post_id, '_pkiw_comic_status', true ) );
	}

	public function test_a_card_default_writes_a_row_even_when_the_key_registers_a_default(): void {
		// Issue 234: _pkiw_read_status registers 'reading', so get_post_meta()
		// never returned '' and a card default never reached the database.
		$this->register_with_default( '_pkiw_comic_status', 'reading' );

		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga"} /-->',
		] );

		$this->assertSame( 'reading', get_metadata_raw( 'post', $post_id, '_pkiw_comic_status', true ) );
	}

	public function test_a_card_default_never_replaces_a_stored_row_behind_a_registered_default(): void {
		$this->register_with_default( '_pkiw_comic_status', 'reading' );
		$content = '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga"} /-->';
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		update_post_meta( $post_id, '_pkiw_comic_status', 'finished' );

		\PKIW\Card_Meta_Sync::sync_content( $post_id, $content );

		$this->assertSame( 'finished', get_metadata_raw( 'post', $post_id, '_pkiw_comic_status', true ) );
	}

	public function test_backfill_writes_the_card_default_row_behind_a_registered_default(): void {
		$this->register_with_default( '_pkiw_comic_status', 'reading' );
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga"} /-->',
		] );
		delete_post_meta( $post_id, '_pkiw_comic_status' );

		\PKIW\Card_Meta_Sync::backfill_batch( 0, 100 );

		$this->assertSame( 'reading', get_metadata_raw( 'post', $post_id, '_pkiw_comic_status', true ) );
	}

	public function test_backfill_gives_no_drink_a_type_and_keeps_a_stored_one(): void {
		$unset  = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"House pour"} /-->',
		] );
		$stored = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/drink-card {"name":"Cortado"} /-->',
		] );
		// A post saved while the card still had a default: no drinkType in
		// its comment, "coffee" in its meta.
		update_post_meta( $stored, '_pkiw_drink_type', 'coffee' );

		\PKIW\Card_Meta_Sync::backfill_batch( 0, 100 );

		$this->assertNull( get_metadata_raw( 'post', $unset, '_pkiw_drink_type', true ), 'The backfill writes no type for a drink that has none.' );
		$this->assertSame( 'coffee', get_post_meta( $stored, '_pkiw_drink_type', true ), 'The backfill leaves a stored type as it is.' );
	}

	public function test_backfill_restores_menu_meta_in_batches_without_touching_content(): void {
		$ids = [];
		foreach ( [ 'Ramen', 'Pho', 'Tacos' ] as $name ) {
			$ids[ $name ] = self::factory()->post->create( [
				'post_content' => '<!-- wp:post-kinds-indieweb/eat-card {"name":"' . $name . '","cuisine":"Street"} /-->',
			] );
		}
		$plain = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>No card.</p><!-- /wp:paragraph -->' ] );

		// Simulate posts saved before this sync existed.
		foreach ( $ids as $id ) {
			delete_post_meta( $id, '_pkiw_eat_name' );
			delete_post_meta( $id, '_pkiw_eat_cuisine' );
		}
		$before = array_map( static fn( $id ) => get_post( $id )->post_content . '|' . get_post( $id )->post_modified_gmt, $ids );

		$first = \PKIW\Card_Meta_Sync::backfill_batch( 0, 2 );
		$this->assertSame( 2, $first['processed'] );
		$this->assertFalse( $first['done'] );

		$cursor = $first['last_id'];
		$passes = 1;
		do {
			$next   = \PKIW\Card_Meta_Sync::backfill_batch( $cursor, 2 );
			$cursor = $next['last_id'];
			++$passes;
		} while ( ! $next['done'] && $passes < 10 );

		$this->assertTrue( $next['done'] );
		foreach ( $ids as $name => $id ) {
			$this->assertSame( $name, get_post_meta( $id, '_pkiw_eat_name', true ) );
			$this->assertSame( 'Street', get_post_meta( $id, '_pkiw_eat_cuisine', true ) );
		}
		$this->assertSame( '', get_post_meta( $plain, '_pkiw_eat_name', true ) );

		$after = array_map( static fn( $id ) => get_post( $id )->post_content . '|' . get_post( $id )->post_modified_gmt, $ids );
		$this->assertSame( $before, $after, 'Backfill never rewrites post content or bumps modified dates.' );

		// Idempotent: a second full run changes nothing.
		$again = \PKIW\Card_Meta_Sync::backfill_batch( 0, 100 );
		$this->assertTrue( $again['done'] );
		foreach ( $ids as $name => $id ) {
			$this->assertSame( $name, get_post_meta( $id, '_pkiw_eat_name', true ) );
		}
	}

	/**
	 * Whether this test registered the stand-in play source.
	 *
	 * @var bool
	 */
	private bool $registered_play_source = false;

	public function tear_down(): void {
		if ( $this->registered_play_source ) {
			\PKIW\Grouped_Archive::unregister_source( 'w1-p0c-play' );
			$this->registered_play_source = false;
		}
		parent::tear_down();
	}

	/**
	 * A play's archive group key, by the rule the archive uses.
	 *
	 * W1-PPLAY registers the play source. Until it merges, the same cases
	 * (#237 decision:43) stand in, and only when no play source exists.
	 *
	 * @param int $post_id Post ID.
	 * @return string 'video', 'board' or ''.
	 */
	private function play_group_key( int $post_id ): string {
		if ( null === \PKIW\Grouped_Archive::group_of_post( 'play', $post_id ) ) {
			\PKIW\Grouped_Archive::register_source(
				new \PKIW\Grouping\Cases_Source(
					'w1-p0c-play',
					[
						'video' => [ '_pkiw_play_rawg_id', '_pkiw_play_steam_id' ],
						'board' => [ '_pkiw_play_bgg_id' ],
					],
					[ 'video', 'board' ]
				),
				'play'
			);
			$this->registered_play_source = true;
		}

		return \PKIW\Grouped_Archive::group_of_post( 'play', $post_id )->key();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function video_provider_switches(): array {
		return [
			'rawg to bgg'  => [ 'rawgId', '_pkiw_play_rawg_id', '900008' ],
			'steam to bgg' => [ 'steamId', '_pkiw_play_steam_id', '900006' ],
		];
	}

	/**
	 * A play-card switched from a video provider to BGG files under board,
	 * so the stale video ID can't keep it in the video group (#237 PL3).
	 *
	 * @dataProvider video_provider_switches
	 */
	public function test_switching_a_play_card_to_bgg_clears_the_video_id_and_files_it_as_board( string $attr, string $key, string $id ): void {
		$this->ensure_kind_term( 'play' );
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"Tidepool Express","' . $attr . '":"' . $id . '"} /-->',
		] );
		wp_set_object_terms( $post_id, 'play', 'kind' );
		$this->assertSame( $id, get_metadata_raw( 'post', $post_id, $key, true ) );
		$this->assertSame( 'video', $this->play_group_key( $post_id ) );

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"Tidepool Express","bggId":"900108"} /-->',
		] );

		$this->assertNull( get_metadata_raw( 'post', $post_id, $key, true ), 'The video ID the card dropped is gone.' );
		$this->assertSame( '900108', get_metadata_raw( 'post', $post_id, '_pkiw_play_bgg_id', true ) );
		$this->assertSame( 'board', $this->play_group_key( $post_id ) );
	}

	/**
	 * Only the provider IDs follow the card. Every other play field keeps
	 * its meta when the card leaves it blank or out.
	 */
	public function test_a_play_card_never_erases_meta_other_than_its_provider_ids(): void {
		$first = [
			'title'       => 'Forest Paths',
			'platform'    => 'Board Game',
			'status'      => 'completed',
			'hoursPlayed' => 2.5,
			'cover'       => 'https://example.test/covers/forest-paths.jpg',
			'rating'      => 5,
			'review'      => 'A calm route-building game.',
			'gameUrl'     => 'https://example.test/games/forest-paths',
			'officialUrl' => 'https://example.test/official/forest-paths',
			'purchaseUrl' => 'https://example.test/buy/forest-paths',
			'bggId'       => '9990001',
		];
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $first ) . ' /-->',
		] );
		$before = [];
		foreach ( \PKIW\Card_Meta_Sync::ATTR_META_MAP['post-kinds-indieweb/play-card'] as $suffix ) {
			$before[ $suffix ] = get_metadata_raw( 'post', $post_id, '_pkiw_' . $suffix, true );
		}

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"","platform":"","cover":"","bggId":"9990001"} /-->',
		] );

		foreach ( $before as $suffix => $value ) {
			$this->assertSame( $value, get_metadata_raw( 'post', $post_id, '_pkiw_' . $suffix, true ), "_pkiw_{$suffix} keeps its meta." );
		}
		$this->assertSame( 'Forest Paths', $before['play_title'] );
	}

	/**
	 * Quick Post and the sidebar store provider IDs with no card. A save
	 * with no play-card leaves them alone.
	 */
	public function test_a_meta_only_play_keeps_its_provider_ids_on_save(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Rainy day charades.</p><!-- /wp:paragraph -->',
		] );
		update_post_meta( $post_id, '_pkiw_play_rawg_id', '900012' );
		update_post_meta( $post_id, '_pkiw_play_bgg_id', '9990099' );

		wp_update_post( [ 'ID' => $post_id, 'post_title' => 'Lantern Drift' ] );

		$this->assertSame( '900012', get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ) );
		$this->assertSame( '9990099', get_metadata_raw( 'post', $post_id, '_pkiw_play_bgg_id', true ) );
	}

	/**
	 * The backfill runs the same sync, so a stale provider ID on a stored
	 * play-card goes when it runs. The X15 diff has to list these deletes.
	 */
	public function test_the_backfill_clears_a_provider_id_the_card_no_longer_has(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"Tidepool Express","bggId":"900108"} /-->',
		] );
		update_post_meta( $post_id, '_pkiw_play_rawg_id', '900008' );

		\PKIW\Card_Meta_Sync::backfill_batch( 0, 100 );

		$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ) );
		$this->assertSame( '900108', get_metadata_raw( 'post', $post_id, '_pkiw_play_bgg_id', true ) );
	}

	/**
	 * The editor drops readStatus when it equals the block.json default, so
	 * a To Read card switched back to Currently Reading saves with no
	 * readStatus. The save writes 'reading' over the stored 'to-read' (#234).
	 */
	public function test_a_save_with_read_status_omitted_writes_reading_over_a_stored_status(): void {
		$this->register_with_default( '_pkiw_read_status', 'reading' );
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"The Quiet Orchard","readStatus":"to-read"} /-->',
		] );
		$this->assertSame( 'to-read', get_metadata_raw( 'post', $post_id, '_pkiw_read_status', true ) );

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"The Quiet Orchard"} /-->',
		] );

		$this->assertSame( 'reading', get_metadata_raw( 'post', $post_id, '_pkiw_read_status', true ) );
	}

	/**
	 * The backfill stays fill-only: a rowless read gets 'reading', and a
	 * stored finished or abandoned status never changes, even on a card
	 * that leaves readStatus out.
	 */
	public function test_the_backfill_fills_a_rowless_read_and_keeps_finished_and_abandoned(): void {
		$this->register_with_default( '_pkiw_read_status', 'reading' );
		$content   = '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Piranesi"} /-->';
		$rowless   = self::factory()->post->create( [ 'post_content' => $content ] );
		$finished  = self::factory()->post->create( [ 'post_content' => $content ] );
		$abandoned = self::factory()->post->create( [ 'post_content' => $content ] );
		delete_post_meta( $rowless, '_pkiw_read_status' );
		update_post_meta( $finished, '_pkiw_read_status', 'finished' );
		update_post_meta( $abandoned, '_pkiw_read_status', 'abandoned' );
		$this->assertNull( get_metadata_raw( 'post', $rowless, '_pkiw_read_status', true ) );

		\PKIW\Card_Meta_Sync::backfill_batch( 0, 100 );

		$this->assertSame( 'reading', get_metadata_raw( 'post', $rowless, '_pkiw_read_status', true ) );
		$this->assertSame( 'finished', get_metadata_raw( 'post', $finished, '_pkiw_read_status', true ) );
		$this->assertSame( 'abandoned', get_metadata_raw( 'post', $abandoned, '_pkiw_read_status', true ) );
	}

	/**
	 * The comic card keeps its fill-only default on save in W1. It has the
	 * same latent bug as the read card, filed as a follow-up.
	 */
	public function test_the_comic_card_status_stays_fill_only_on_save(): void {
		$this->register_with_default( '_pkiw_comic_status', 'reading' );
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga","readStatus":"finished"} /-->',
		] );

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => '<!-- wp:post-kinds-indieweb/comic-card {"title":"Saga"} /-->',
		] );

		$this->assertSame( 'finished', get_metadata_raw( 'post', $post_id, '_pkiw_comic_status', true ) );
	}

	/**
	 * The REST controller writes request meta after save_post. A card that
	 * says finished still wins over request meta that says reading (#234
	 * risk 1).
	 */
	public function test_a_rest_save_keeps_the_card_status_over_request_meta(): void {
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params( [
			'title'   => 'Piranesi',
			'status'  => 'publish',
			'content' => '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Piranesi","readStatus":"finished"} /-->',
			'meta'    => [ '_pkiw_read_status' => 'reading' ],
		] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'finished', get_metadata_raw( 'post', $response->get_data()['id'], '_pkiw_read_status', true ) );
	}

	/**
	 * Create a post through the REST posts route as an administrator.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $meta    Request meta.
	 * @return int The new post's ID.
	 */
	private function rest_create( string $content, array $meta ): int {
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params( [
			'title'   => 'Saved over REST',
			'status'  => 'publish',
			'content' => $content,
			'meta'    => $meta,
		] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );

		return (int) $response->get_data()['id'];
	}

	/**
	 * A REST save whose meta sets a stricter location privacy than the
	 * check-in card keeps the request's value, as it did before the
	 * wp_after_insert_post pass, and a visitor gets no location.
	 */
	public function test_a_rest_save_keeps_a_stricter_location_privacy_from_request_meta(): void {
		$card    = [
			'venueName'       => 'Sentinel Pier Qx7',
			'address'         => '41 Sentinel Wharf Rd',
			'locality'        => 'Port Qx7',
			'latitude'        => 12.345678,
			'longitude'       => -76.543219,
			'locationPrivacy' => 'public',
		];
		$post_id = $this->rest_create(
			'<!-- wp:post-kinds-indieweb/checkin-card ' . wp_json_encode( $card ) . ' /-->',
			[ '_pkiw_geo_privacy' => 'private' ]
		);

		$this->assertSame( 'private', get_metadata_raw( 'post', $post_id, '_pkiw_geo_privacy', true ) );

		wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data();
		$this->go_to( get_permalink( $post_id ) );
		$html = do_blocks( (string) get_post_field( 'post_content', $post_id ) );

		foreach ( [ 'Sentinel Pier Qx7', '41 Sentinel Wharf Rd', 'Port Qx7', '12.345678', '-76.543219' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, (string) wp_json_encode( $data['meta'] ), "REST meta shows {$needle}." );
			$this->assertStringNotContainsString( $needle, $data['content']['rendered'], "REST content.rendered shows {$needle}." );
			$this->assertStringNotContainsString( $needle, $html, "The rendered card shows {$needle}." );
		}
	}

	/**
	 * The wp_after_insert_post pass rewrites the read-card status and the
	 * play-card provider IDs only. Any other key the request's meta set
	 * keeps the request's value.
	 */
	public function test_a_rest_save_keeps_other_request_meta_over_the_card(): void {
		$post_id = $this->rest_create(
			'<!-- wp:post-kinds-indieweb/checkin-card {"venueName":"Card Venue"} /-->',
			[ '_pkiw_checkin_name' => 'Sidebar Venue' ]
		);

		$this->assertSame( 'Sidebar Venue', get_metadata_raw( 'post', $post_id, '_pkiw_checkin_name', true ) );
	}

	/**
	 * A read card left at Currently Reading saves with no readStatus. Stale
	 * request meta that says to-read still loses to the card's default.
	 */
	public function test_a_rest_save_writes_the_read_status_default_over_request_meta(): void {
		$post_id = $this->rest_create(
			'<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"The Quiet Orchard"} /-->',
			[ '_pkiw_read_status' => 'to-read' ]
		);

		$this->assertSame( 'reading', get_metadata_raw( 'post', $post_id, '_pkiw_read_status', true ) );
	}

	/**
	 * The editor sends the meta it loaded, so a REST save can carry a
	 * provider ID the card just dropped. The card still decides it.
	 */
	public function test_a_rest_save_drops_a_provider_id_the_card_left_out_of_request_meta(): void {
		$post_id = $this->rest_create(
			'<!-- wp:post-kinds-indieweb/play-card {"title":"Tidepool Express","bggId":"900108"} /-->',
			[ '_pkiw_play_rawg_id' => '900008' ]
		);

		$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ) );
		$this->assertSame( '900108', get_metadata_raw( 'post', $post_id, '_pkiw_play_bgg_id', true ) );
	}

	/**
	 * Update a post through the REST posts route as an administrator.
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $content Post content.
	 * @param array<string, mixed> $meta    Request meta; none when empty.
	 */
	private function rest_update( int $post_id, string $content, array $meta = [] ): void {
		( new \PKIW\Meta_Fields() )->register_meta_fields();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$params = [ 'content' => $content ];
		if ( [] !== $meta ) {
			$params['meta'] = $meta;
		}
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( $params );

		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * A Quick Post play: a paragraph and a RAWG ID, no card.
	 *
	 * @return int Post ID.
	 */
	private function quick_post_play(): int {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Lunch-break game.</p><!-- /wp:paragraph -->',
		] );
		update_post_meta( $post_id, '_pkiw_play_rawg_id', '3498' );

		return $post_id;
	}

	/**
	 * A play card that names no provider ID leaves the one Quick Post stored.
	 * Only a card that names another provider clears it.
	 */
	public function test_a_play_card_with_no_provider_id_keeps_a_quick_post_provider_id_on_save(): void {
		$post_id = $this->quick_post_play();

		$this->rest_update( $post_id, '<!-- wp:post-kinds-indieweb/play-card {"title":"Chess"} /-->' );

		$this->assertSame( '3498', get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ) );
	}

	/**
	 * The editor sends the meta it loaded, so the REST controller writes the
	 * RAWG ID back after save_post. The after-insert pass keeps it, on this
	 * save and the next, while the card names no provider ID.
	 */
	public function test_the_after_insert_pass_keeps_a_provider_id_a_card_with_none_leaves_alone(): void {
		$post_id = $this->quick_post_play();
		$card    = '<!-- wp:post-kinds-indieweb/play-card {"title":"Chess"} /-->';

		$this->rest_update( $post_id, $card, [ '_pkiw_play_rawg_id' => '3498' ] );
		$this->assertSame( '3498', get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ), 'The save that adds the card keeps it.' );

		$this->rest_update( $post_id, $card, [ '_pkiw_play_rawg_id' => '3498' ] );
		$this->assertSame( '3498', get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ), 'The next save keeps it.' );
	}

	/**
	 * The backfill runs the same rule: a stored play card that names no
	 * provider ID keeps every provider ID the post has.
	 */
	public function test_the_backfill_keeps_provider_ids_under_a_card_that_names_none(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"Chess"} /-->',
		] );
		update_post_meta( $post_id, '_pkiw_play_rawg_id', '3498' );
		update_post_meta( $post_id, '_pkiw_play_steam_id', '900006' );
		update_post_meta( $post_id, '_pkiw_play_bgg_id', '9990099' );

		\PKIW\Card_Meta_Sync::backfill_batch( 0, 100 );

		$this->assertSame( '3498', get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ) );
		$this->assertSame( '900006', get_metadata_raw( 'post', $post_id, '_pkiw_play_steam_id', true ) );
		$this->assertSame( '9990099', get_metadata_raw( 'post', $post_id, '_pkiw_play_bgg_id', true ) );
	}

	/**
	 * Play cards over stored provider IDs. bggId files a play as board,
	 * rawgId and steamId as video. A card clears a stored ID only when it
	 * names an ID from the other group and none from the stored ID's own,
	 * and only a string that sanitizes to a non-empty ID names one.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, string>, 2: array<string, ?string>}>
	 */
	public function provider_id_cards(): array {
		return [
			'a RAWG card keeps a stored Steam ID'               => [
				[ 'title' => 'Dota 2', 'rawgId' => '3498' ],
				[ '_pkiw_play_steam_id' => '570' ],
				[ '_pkiw_play_rawg_id' => '3498', '_pkiw_play_steam_id' => '570' ],
			],
			'a Steam card keeps a stored RAWG ID'               => [
				[ 'title' => 'Dota 2', 'steamId' => '570' ],
				[ '_pkiw_play_rawg_id' => '3498' ],
				[ '_pkiw_play_rawg_id' => '3498', '_pkiw_play_steam_id' => '570' ],
			],
			'a card naming BGG and RAWG keeps a stored Steam ID' => [
				[ 'title' => 'Catan', 'bggId' => '13', 'rawgId' => '3498' ],
				[ '_pkiw_play_steam_id' => '570' ],
				[ '_pkiw_play_bgg_id' => '13', '_pkiw_play_rawg_id' => '3498', '_pkiw_play_steam_id' => '570' ],
			],
			'a RAWG card clears a stored BGG ID'                => [
				[ 'title' => 'Catan', 'rawgId' => '3498' ],
				[ '_pkiw_play_bgg_id' => '13' ],
				[ '_pkiw_play_bgg_id' => null, '_pkiw_play_rawg_id' => '3498' ],
			],
			'a whitespace RAWG ID keeps the stored one'         => [
				[ 'title' => 'Chess', 'rawgId' => '   ' ],
				[ '_pkiw_play_rawg_id' => '3498' ],
				[ '_pkiw_play_rawg_id' => '3498' ],
			],
			'a whitespace BGG ID keeps the stored IDs'          => [
				[ 'title' => 'Chess', 'bggId' => ' ' ],
				[ '_pkiw_play_bgg_id' => '9990099', '_pkiw_play_rawg_id' => '3498' ],
				[ '_pkiw_play_bgg_id' => '9990099', '_pkiw_play_rawg_id' => '3498' ],
			],
			'a BGG ID of 0 names no provider'                   => [
				[ 'title' => 'Chess', 'bggId' => 0 ],
				[ '_pkiw_play_rawg_id' => '3498' ],
				[ '_pkiw_play_rawg_id' => '3498', '_pkiw_play_bgg_id' => null ],
			],
			'a RAWG ID of true names no provider'               => [
				[ 'title' => 'Chess', 'rawgId' => true ],
				[ '_pkiw_play_steam_id' => '900006' ],
				[ '_pkiw_play_steam_id' => '900006', '_pkiw_play_rawg_id' => null ],
			],
			'a RAWG ID of false keeps the stored one'           => [
				[ 'title' => 'Chess', 'rawgId' => false ],
				[ '_pkiw_play_rawg_id' => '3498' ],
				[ '_pkiw_play_rawg_id' => '3498' ],
			],
			'a BGG ID of bare tags names no provider'           => [
				[ 'title' => 'Chess', 'bggId' => '<b></b>' ],
				[ '_pkiw_play_rawg_id' => '3498' ],
				[ '_pkiw_play_rawg_id' => '3498', '_pkiw_play_bgg_id' => null ],
			],
			'a RAWG ID of stripped octets keeps the stored IDs' => [
				[ 'title' => 'Catan', 'rawgId' => '%41%42' ],
				[ '_pkiw_play_bgg_id' => '13', '_pkiw_play_rawg_id' => '3498' ],
				[ '_pkiw_play_bgg_id' => '13', '_pkiw_play_rawg_id' => '3498' ],
			],
		];
	}

	/**
	 * A post with provider IDs stored and no card, as the sidebar or Quick
	 * Post leaves it.
	 *
	 * @param array<string, string> $stored Meta key => provider ID.
	 * @return int Post ID.
	 */
	private function play_with_stored_ids( array $stored ): int {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:paragraph --><p>Game night.</p><!-- /wp:paragraph -->',
		] );
		foreach ( $stored as $key => $id ) {
			update_post_meta( $post_id, $key, $id );
		}

		return $post_id;
	}

	/**
	 * @param int                   $post_id  Post ID.
	 * @param array<string, ?string> $expected Meta key => raw row, null for none.
	 */
	private function assert_provider_ids( int $post_id, array $expected ): void {
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, get_metadata_raw( 'post', $post_id, $key, true ), $key );
		}
	}

	/**
	 * A REST save that adds the card, with no request meta.
	 *
	 * @dataProvider provider_id_cards
	 *
	 * @param array<string, mixed>   $attrs    Play-card attributes.
	 * @param array<string, string>  $stored   Provider IDs stored before the save.
	 * @param array<string, ?string> $expected Raw rows after it.
	 */
	public function test_a_play_card_settles_stored_provider_ids_on_save( array $attrs, array $stored, array $expected ): void {
		$post_id = $this->play_with_stored_ids( $stored );

		$this->rest_update( $post_id, '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attrs ) . ' /-->' );

		$this->assert_provider_ids( $post_id, $expected );
	}

	/**
	 * The same save with the stored IDs sent back as request meta, as the
	 * editor sends what it loaded, so the after-insert pass decides.
	 *
	 * @dataProvider provider_id_cards
	 *
	 * @param array<string, mixed>   $attrs    Play-card attributes.
	 * @param array<string, string>  $stored   Provider IDs stored before the save and sent as request meta.
	 * @param array<string, ?string> $expected Raw rows after it.
	 */
	public function test_a_play_card_settles_provider_ids_from_request_meta( array $attrs, array $stored, array $expected ): void {
		$post_id = $this->play_with_stored_ids( $stored );

		$this->rest_update( $post_id, '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attrs ) . ' /-->', $stored );

		$this->assert_provider_ids( $post_id, $expected );
	}

	/**
	 * The backfill applies the same rule to a card already in post_content.
	 *
	 * @dataProvider provider_id_cards
	 *
	 * @param array<string, mixed>   $attrs    Play-card attributes.
	 * @param array<string, string>  $stored   Provider IDs stored before the backfill.
	 * @param array<string, ?string> $expected Raw rows after it.
	 */
	public function test_the_backfill_settles_provider_ids_by_the_same_rule( array $attrs, array $stored, array $expected ): void {
		global $wpdb;
		$post_id = $this->play_with_stored_ids( $stored );
		$wpdb->update( $wpdb->posts, [ 'post_content' => '<!-- wp:post-kinds-indieweb/play-card ' . wp_json_encode( $attrs ) . ' /-->' ], [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );

		\PKIW\Card_Meta_Sync::backfill_batch( 0, 100 );

		$this->assert_provider_ids( $post_id, $expected );
	}

	/**
	 * #340 shipped version 3 on main, so W1 needs its own number.
	 */
	public function test_w1_bumps_the_backfill_version_once_to_4(): void {
		$this->assertSame( '4', \PKIW\Card_Meta_Sync::BACKFILL_VERSION );
	}

	/**
	 * A site that finished #340's backfill 3 runs W1's, which clears a
	 * provider ID the play card no longer has.
	 */
	public function test_a_site_that_finished_backfill_3_runs_the_w1_backfill(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/play-card {"title":"Tidepool Express","bggId":"900108"} /-->',
		] );
		update_post_meta( $post_id, '_pkiw_play_rawg_id', '900008' );
		update_option( \PKIW\Card_Meta_Sync::BACKFILL_OPTION, '3', false );
		delete_option( \PKIW\Card_Meta_Sync::BACKFILL_CURSOR );
		wp_clear_scheduled_hook( \PKIW\Card_Meta_Sync::BACKFILL_HOOK );

		\PKIW\Card_Meta_Sync::maybe_schedule_backfill();
		$this->assertNotFalse( wp_next_scheduled( \PKIW\Card_Meta_Sync::BACKFILL_HOOK ), 'A site that finished backfill 3 runs it again.' );

		wp_clear_scheduled_hook( \PKIW\Card_Meta_Sync::BACKFILL_HOOK );
		\PKIW\Card_Meta_Sync::run_backfill_event();

		$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_play_rawg_id', true ) );
		$this->assertSame( \PKIW\Card_Meta_Sync::BACKFILL_VERSION, get_option( \PKIW\Card_Meta_Sync::BACKFILL_OPTION ) );
	}

	/**
	 * Make sure a `kind` term exists so it can be assigned to a post.
	 *
	 * @param string $slug Kind slug.
	 */
	private function ensure_kind_term( string $slug ): void {
		if ( ! term_exists( $slug, 'kind' ) ) {
			wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
		}
	}

	public function test_backfill_schedules_once_and_marks_complete(): void {
		delete_option( \PKIW\Card_Meta_Sync::BACKFILL_OPTION );
		wp_clear_scheduled_hook( \PKIW\Card_Meta_Sync::BACKFILL_HOOK );

		\PKIW\Card_Meta_Sync::maybe_schedule_backfill();
		$this->assertNotFalse( wp_next_scheduled( \PKIW\Card_Meta_Sync::BACKFILL_HOOK ) );

		// A lost event is rescheduled on the next check, not duplicated.
		\PKIW\Card_Meta_Sync::maybe_schedule_backfill();
		$this->assertCount( 1, array_filter( _get_cron_array(), static fn( $hooks ) => isset( $hooks[ \PKIW\Card_Meta_Sync::BACKFILL_HOOK ] ) ) );

		wp_clear_scheduled_hook( \PKIW\Card_Meta_Sync::BACKFILL_HOOK );
		\PKIW\Card_Meta_Sync::run_backfill_event();

		$this->assertSame( \PKIW\Card_Meta_Sync::BACKFILL_VERSION, get_option( \PKIW\Card_Meta_Sync::BACKFILL_OPTION ) );

		\PKIW\Card_Meta_Sync::maybe_schedule_backfill();
		$this->assertFalse( wp_next_scheduled( \PKIW\Card_Meta_Sync::BACKFILL_HOOK ), 'A completed backfill is not rescheduled.' );
	}

	public function test_a_site_that_finished_backfill_2_resyncs_a_card_behind_an_rsvp_card(): void {
		$post_id = self::factory()->post->create( [
			'post_content' => '<!-- wp:post-kinds-indieweb/rsvp-card {"eventName":"Quill Meetup"} /-->' . "\n\n" . '<!-- wp:post-kinds-indieweb/read-card {"bookTitle":"Quill Primer"} /-->',
		] );
		// Saved while the RSVP card sat in ATTR_META_MAP, so the read card never synced.
		delete_post_meta( $post_id, '_pkiw_read_title' );
		update_option( \PKIW\Card_Meta_Sync::BACKFILL_OPTION, '2', false );
		delete_option( \PKIW\Card_Meta_Sync::BACKFILL_CURSOR );
		wp_clear_scheduled_hook( \PKIW\Card_Meta_Sync::BACKFILL_HOOK );

		\PKIW\Card_Meta_Sync::maybe_schedule_backfill();
		$this->assertNotFalse( wp_next_scheduled( \PKIW\Card_Meta_Sync::BACKFILL_HOOK ), 'A site that finished backfill 2 runs it again.' );

		wp_clear_scheduled_hook( \PKIW\Card_Meta_Sync::BACKFILL_HOOK );
		\PKIW\Card_Meta_Sync::run_backfill_event();

		$this->assertSame( 'Quill Primer', get_post_meta( $post_id, '_pkiw_read_title', true ) );
	}
}

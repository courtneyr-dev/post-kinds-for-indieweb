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

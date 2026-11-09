<?php
/**
 * RSVP card-to-meta synchronization coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Card_Meta_Sync;
use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpSyncTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
	}

	private function card( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( $attributes ) . ' /-->';
	}

	private function post( string $content, string $kind = 'rsvp' ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $content ] );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	public function test_save_syncs_five_rows_without_copying_location(): void {
		$content = $this->card(
			[
				'eventName'     => 'RSVP event',
				'eventUrl'      => 'https://events.example/rsvp',
				'rsvpStatus'    => 'remote',
				'eventStart'    => '2026-10-08T18:00',
				'eventEnd'      => '2026-10-08T20:00',
				'eventLocation' => 'Never copied hall',
			]
		);
		$id = $this->post( $content );

		Card_Meta_Sync::sync_content( $id, $content, true );

		$this->assertSame( 'RSVP event', get_post_meta( $id, '_pkiw_cite_name', true ) );
		$this->assertSame( 'https://events.example/rsvp', get_post_meta( $id, '_pkiw_cite_url', true ) );
		$this->assertSame( 'remote', get_post_meta( $id, '_pkiw_rsvp_status', true ) );
		$this->assertSame( '2026-10-08T18:00', get_post_meta( $id, '_pkiw_event_start', true ) );
		$this->assertSame( '2026-10-08T20:00', get_post_meta( $id, '_pkiw_event_end', true ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_event_location' ) );
	}

	public function test_save_defaults_status_and_clears_only_card_decided_dates(): void {
		$id = $this->post( $this->card( [ 'eventName' => 'Original', 'eventStart' => '2026-10-08T18:00' ] ) );
		update_post_meta( $id, '_pkiw_cite_url', 'https://events.example/keep' );
		update_post_meta( $id, '_pkiw_event_end', '2026-10-08T20:00' );
		update_post_meta( $id, '_pkiw_event_location', 'Meta-box location' );

		$content = $this->card( [ 'eventName' => '', 'eventStart' => '2026-10-08T25:00', 'eventEnd' => '' ] );
		Card_Meta_Sync::sync_content( $id, $content, true );

		$this->assertSame( 'Original', get_post_meta( $id, '_pkiw_cite_name', true ) );
		$this->assertSame( 'https://events.example/keep', get_post_meta( $id, '_pkiw_cite_url', true ) );
		$this->assertSame( 'yes', get_post_meta( $id, '_pkiw_rsvp_status', true ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_event_start' ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_event_end' ) );
		$this->assertSame( 'Meta-box location', get_post_meta( $id, '_pkiw_event_location', true ) );
	}

	public function test_rsvp_and_existing_card_maps_sync_in_either_document_order(): void {
		$read = '<!-- wp:post-kinds-indieweb/read-card {"readStatus":"finished"} /-->';
		$rsvp = $this->card( [ 'eventName' => 'Map event', 'rsvpStatus' => 'yes' ] );

		foreach ( [ $read . $rsvp, $rsvp . $read ] as $content ) {
			$id = $this->post( $content );
			Card_Meta_Sync::sync_content( $id, $content, true );
			$this->assertSame( 'finished', get_post_meta( $id, '_pkiw_read_status', true ) );
			$this->assertSame( 'Map event', get_post_meta( $id, '_pkiw_cite_name', true ) );
		}
	}

	public function test_backfill_only_adds_absent_rows_and_is_idempotent(): void {
		$content = $this->card( [ 'eventName' => 'New name', 'eventUrl' => 'https://events.example/new', 'eventStart' => '2026-10-08T18:00' ] );
		$id      = $this->post( $content );
		foreach ( [ '_pkiw_cite_name', '_pkiw_cite_url', '_pkiw_rsvp_status', '_pkiw_event_start', '_pkiw_event_end', Rsvp_Archive::SORT_KEY ] as $key ) {
			delete_post_meta( $id, $key );
		}
		update_post_meta( $id, '_pkiw_cite_name', 'Existing name' );

		Card_Meta_Sync::sync_content( $id, $content, false );
		$first = get_post_meta( $id );
		Card_Meta_Sync::sync_content( $id, $content, false );

		$this->assertSame( 'Existing name', get_post_meta( $id, '_pkiw_cite_name', true ) );
		$this->assertSame( 'https://events.example/new', get_post_meta( $id, '_pkiw_cite_url', true ) );
		$this->assertSame( $first, get_post_meta( $id ) );
	}

	public function test_backfill_never_copies_a_meta_box_name_when_the_card_name_is_empty(): void {
		$content = $this->card( [ 'eventName' => '', 'eventUrl' => 'https://events.example/card-only' ] );
		$id      = $this->post( $content );
		delete_post_meta( $id, '_pkiw_cite_name' );
		delete_post_meta( $id, '_pkiw_cite_url' );
		delete_post_meta( $id, '_pkiw_rsvp_status' );
		update_post_meta( $id, '_pkiw_event_name', 'Meta-box fallback' );

		Card_Meta_Sync::sync_content( $id, $content, false );

		$this->assertFalse( metadata_exists( 'post', $id, '_pkiw_cite_name' ) );
		$this->assertSame( 'Meta-box fallback', get_post_meta( $id, '_pkiw_event_name', true ) );
		$this->assertSame( 'https://events.example/card-only', get_post_meta( $id, '_pkiw_cite_url', true ) );
	}

	public function test_sort_follows_start_kind_changes_and_cardless_meta_writes(): void {
		$content = $this->card( [ 'eventStart' => '2026-10-08T18:00' ] );
		$id      = $this->post( $content );
		Card_Meta_Sync::sync_content( $id, $content, true );
		Rsvp_Archive::refresh_sort( $id );
		$this->assertSame( '2026-10-08 22:00:00', get_post_meta( $id, Rsvp_Archive::SORT_KEY, true ) );

		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		$this->assertFalse( metadata_exists( 'post', $id, Rsvp_Archive::SORT_KEY ) );

		$cardless = $this->post( '<p>Card-less RSVP.</p>' );
		update_post_meta( $cardless, '_pkiw_event_start', '2026-10-09' );
		$this->assertSame( '2026-10-10 03:59:59', get_post_meta( $cardless, Rsvp_Archive::SORT_KEY, true ) );
	}

	public function test_after_insert_reasserts_the_card_date_over_stale_rest_meta(): void {
		$content = $this->card( [ 'eventStart' => '2026-10-08T18:00' ] );
		$id      = $this->post( $content );
		update_post_meta( $id, '_pkiw_event_start', '2026-10-01T10:00' );

		( new Card_Meta_Sync() )->resync_after_insert( $id, get_post( $id ) );

		$this->assertSame( '2026-10-08T18:00', get_post_meta( $id, '_pkiw_event_start', true ) );
	}

	public function test_event_without_an_rsvp_card_keeps_event_rows(): void {
		$id = $this->post( '<!-- wp:post-kinds-indieweb/event-card {"eventName":"Event"} /-->', 'event' );
		update_post_meta( $id, '_pkiw_event_start', '2026-10-08T18:00' );
		update_post_meta( $id, '_pkiw_event_end', '2026-10-08T20:00' );

		Card_Meta_Sync::sync_content( $id, get_post_field( 'post_content', $id ), true );

		$this->assertSame( '2026-10-08T18:00', get_post_meta( $id, '_pkiw_event_start', true ) );
		$this->assertSame( '2026-10-08T20:00', get_post_meta( $id, '_pkiw_event_end', true ) );
	}
}

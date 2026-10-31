<?php
/**
 * Sentinel privacy coverage for the new RSVP archive and abilities.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Rsvp_Abilities;
use PKIW\Operations;
use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpArchivePrivacyTest extends WP_UnitTestCase {

	private const LOCATION = 'Rv251 Sentinel Hall, 1 Sentinel Way';
	private const EVENT    = 'https://events.example/rv251-sentinel';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
	}

	private function card( string $status, string $start, string $visibility ): string {
		return '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode(
			[
				'eventName'          => 'Sentinel event',
				'eventUrl'           => self::EVENT,
				'eventStart'         => $start,
				'eventLocation'      => self::LOCATION,
				'locationVisibility' => $visibility,
				'rsvpStatus'         => $status,
			]
		) . ' /-->';
	}

	private function rsvp( string $status, string $start, string $visibility, ?string $row, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Sentinel RSVP',
					'post_content' => $this->card( $status, $start, $visibility ),
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );
		if ( null !== $row ) {
			update_post_meta( $id, '_pkiw_rsvp_location_privacy', $row );
		}
		Rsvp_Archive::refresh_sort( $id );

		return (int) $id;
	}

	public function test_every_nonpublic_shape_omits_the_sentinel_for_visitors_and_editors(): void {
		$private_future = $this->rsvp( 'yes', '2027-10-08T18:00', 'private', 'private' );
		$private_past   = $this->rsvp( 'no', '2025-10-08T18:00', 'private', 'private' );
		$geo_private    = $this->rsvp( 'remote', '2027-10-09T18:00', 'public', 'public' );
		update_post_meta( $geo_private, '_pkiw_geo_privacy', 'private' );
		$missing_row = $this->rsvp( 'maybe', '2025-10-09T18:00', 'public', null );
		$cardless = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<p>Quick RSVP.</p>' ] );
		wp_set_object_terms( $cardless, 'rsvp', Taxonomy::TAXONOMY );
		update_post_meta( $cardless, '_pkiw_event_url', self::EVENT );
		update_post_meta( $cardless, '_pkiw_event_location', self::LOCATION );
		update_post_meta( $cardless, '_pkiw_rsvp_value', 'yes' );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user ) {
			wp_set_current_user( $user );
			foreach ( [ $private_future, $private_past, $geo_private, $missing_row, $cardless ] as $id ) {
				$facts = Rsvp_Archive::facts( (int) $id );
				$this->assertStringNotContainsString( self::LOCATION, wp_json_encode( $facts ), 'Facts ' . $id );
				$this->assertStringNotContainsString( self::LOCATION, Rsvp_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true ), 'Entry ' . $id );
				$ability = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] );
				$this->assertStringNotContainsString( self::LOCATION, wp_json_encode( $ability ), 'Ability ' . $id );
			}
			$this->assertStringNotContainsString( self::LOCATION, wp_json_encode( Operations::backfill_rsvp_meta( true, null ) ) );
		}
	}

	public function test_protected_future_is_past_and_prints_no_timing_status_poster_or_author(): void {
		$id = $this->rsvp( 'yes', '2027-10-08T18:00', 'public', 'public', [ 'post_password' => 'pass' ] );
		$html = Rsvp_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );

		$this->assertSame( 'past', Rsvp_Archive::group_of_post( get_post( $id ) ) );
		$this->assertSame( [], Rsvp_Archive::facts( $id ) );
		$this->assertStringContainsString( 'pkiw-rsvp-entry--protected', $html );
		foreach ( [ self::LOCATION, 'pkiw-rsvp-entry__day', 'p-rsvp', '<figure', 'p-author' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $html );
		}
		$error = Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'forbidden', $error->get_error_code() );
	}

	public function test_all_public_location_appears_only_in_single_facts_not_archive_or_backfill(): void {
		$id = $this->rsvp( 'interested', '2027-10-08T18:00', 'public', 'public' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );

		$this->assertSame( self::LOCATION, Rsvp_Archive::facts( $id )['location'] );
		$this->assertSame( self::LOCATION, Rsvp_Abilities::instance()->execute_get_rsvp_facts( [ 'post_id' => $id ] )['location'] );
		$this->assertStringContainsString( self::LOCATION, do_blocks( get_post_field( 'post_content', $id ) ) );
		$this->assertStringNotContainsString( self::LOCATION, Rsvp_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true ) );
		$this->assertStringNotContainsString( self::LOCATION, wp_json_encode( Operations::backfill_rsvp_meta( true, null ) ) );
	}
}

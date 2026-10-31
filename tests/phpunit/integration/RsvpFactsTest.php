<?php
/**
 * RSVP facts reader coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class RsvpFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		add_filter( 'pkiw_rsvp_now', [ $this, 'fixed_now' ] );
	}

	public function tear_down(): void {
		remove_filter( 'pkiw_rsvp_now', [ $this, 'fixed_now' ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function fixed_now(): string {
		return '2026-10-08 12:00:00';
	}

	private function card( array $attributes ): string {
		return '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( $attributes ) . ' /-->';
	}

	private function rsvp( array $attributes = [], array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'RSVP post title',
					'post_content' => $this->card( $attributes ),
					'post_date'    => '2026-10-01 09:00:00',
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	public function test_all_card_attributes_produce_the_frozen_fact_shape(): void {
		$id = $this->rsvp(
			[
				'eventName'          => 'October Event',
				'eventUrl'           => 'https://events.example/october',
				'eventStart'         => '2026-10-08T18:00',
				'eventEnd'           => '2026-10-08T20:30',
				'eventLocation'      => 'Public Hall',
				'locationVisibility' => 'public',
				'eventDescription'   => 'A public description.',
				'rsvpStatus'         => 'remote',
				'rsvpNote'           => '<strong>Bringing notes.</strong>',
				'rsvpAt'             => '2026-10-01',
				'eventImage'         => 'https://images.example/event.jpg',
				'eventImageAlt'      => 'Event poster',
			]
		);
		update_post_meta( $id, '_pkiw_rsvp_location_privacy', 'public' );
		update_post_meta( $id, '_pkiw_geo_privacy', 'public' );

		$facts = Rsvp_Archive::facts( $id );

		$this->assertSame(
			[
				'name', 'name_is_title', 'title', 'url', 'url_host', 'has_card',
				'status', 'status_label', 'start_iso', 'start_day', 'day_display',
				'time_display', 'end_iso', 'all_day', 'sort', 'group', 'location',
				'description', 'note', 'rsvp_at_iso', 'rsvp_at_display', 'image',
				'image_alt', 'image_local', 'date_iso',
			],
			array_keys( $facts )
		);
		$this->assertSame( 'October Event', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
		$this->assertSame( 'events.example', $facts['url_host'] );
		$this->assertSame( 'remote', $facts['status'] );
		$this->assertSame( 'Attending Remotely', $facts['status_label'] );
		$this->assertSame( '2026-10-08T18:00:00-04:00', $facts['start_iso'] );
		$this->assertSame( '2026-10-08', $facts['start_day'] );
		$this->assertSame( 'October 8, 2026', $facts['day_display'] );
		$this->assertSame( '6:00 pm – 8:30 pm', $facts['time_display'] );
		$this->assertSame( '2026-10-08 22:00:00', $facts['sort'] );
		$this->assertSame( 'upcoming', $facts['group'] );
		$this->assertSame( 'Public Hall', $facts['location'] );
		$this->assertSame( '<strong>Bringing notes.</strong>', $facts['note'] );
		$this->assertFalse( $facts['image_local'] );
	}

	public function test_card_precedence_defaults_and_meta_fallbacks_match_each_writer(): void {
		$micropub = $this->rsvp(
			[
				'eventUrl'   => 'https://events.example/micropub',
				'rsvpStatus' => 'maybe',
				'rsvpNote'   => 'Maybe.',
			],
			[ 'post_title' => 'Micropub fallback title' ]
		);
		$missing_status = $this->rsvp( [ 'eventName' => 'Default status' ] );
		$meta_box       = $this->rsvp( [], [ 'post_content' => '<p>Card-less.</p>' ] );
		update_post_meta( $meta_box, '_pkiw_event_name', 'Meta-box event' );
		update_post_meta( $meta_box, '_pkiw_event_url', 'https://events.example/meta-box' );
		update_post_meta( $meta_box, '_pkiw_rsvp_value', 'maybe' );
		$sidebar = $this->rsvp( [], [ 'post_content' => '<p>Card-less.</p>' ] );
		update_post_meta( $sidebar, '_pkiw_cite_name', 'Sidebar event' );
		update_post_meta( $sidebar, '_pkiw_cite_url', 'https://events.example/sidebar' );
		update_post_meta( $sidebar, '_pkiw_rsvp_status', 'interested' );

		$this->assertSame( 'Micropub fallback title', Rsvp_Archive::facts( $micropub )['name'] );
		$this->assertTrue( Rsvp_Archive::facts( $micropub )['name_is_title'] );
		$this->assertSame( 'yes', Rsvp_Archive::facts( $missing_status )['status'] );
		$this->assertSame( 'Meta-box event', Rsvp_Archive::facts( $meta_box )['name'] );
		$this->assertSame( 'maybe', Rsvp_Archive::facts( $meta_box )['status'] );
		$this->assertSame( 'Sidebar event', Rsvp_Archive::facts( $sidebar )['name'] );
		$this->assertSame( 'interested', Rsvp_Archive::facts( $sidebar )['status'] );
	}

	public function test_datetime_date_only_offset_and_malformed_values_follow_card_wall_clock(): void {
		$date_only = Rsvp_Archive::facts( $this->rsvp( [ 'eventStart' => '2026-10-08' ] ) );
		$offset    = Rsvp_Archive::facts( $this->rsvp( [ 'eventStart' => '2026-10-08T18:00:00Z' ] ) );
		$malformed = Rsvp_Archive::facts( $this->rsvp( [ 'eventStart' => '2026-10-08T25:00' ] ) );

		$this->assertTrue( $date_only['all_day'] );
		$this->assertSame( '2026-10-09 03:59:59', $date_only['sort'] );
		$this->assertSame( '', $date_only['time_display'] );
		$this->assertSame( '2:00 pm', $offset['time_display'] );
		$this->assertSame( '', $malformed['start_iso'] );
		$this->assertSame( '', $malformed['start_day'] );
		$this->assertSame( '', $malformed['sort'] );
	}

	public function test_a_card_with_a_cleared_start_never_falls_back_to_stale_meta(): void {
		$id = $this->rsvp( [ 'eventName' => 'Cleared event', 'eventStart' => '' ] );
		update_post_meta( $id, '_pkiw_event_start', '2026-10-08T18:00' );

		$facts = Rsvp_Archive::facts( $id );
		$this->assertSame( '', $facts['start_iso'] );
		$this->assertSame( '', $facts['sort'] );
	}

	public function test_non_rsvp_protected_private_and_draft_posts_have_no_public_facts(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$protected = $this->rsvp( [ 'eventName' => 'Hidden' ], [ 'post_password' => 'pass' ] );
		$private   = $this->rsvp( [ 'eventName' => 'Private' ], [ 'post_status' => 'private' ] );
		$draft     = $this->rsvp( [ 'eventName' => 'Draft' ], [ 'post_status' => 'draft' ] );
		wp_set_current_user( 0 );

		foreach ( [ $note, $protected, $private, $draft ] as $id ) {
			$this->assertSame( [], Rsvp_Archive::facts( (int) $id ), 'Fixture ' . $id );
		}
	}
}

<?php
/**
 * RSVP backfill operation and CLI coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Rsvp_Abilities;
use PKIW\CLI_Commands;
use PKIW\Operations;
use PKIW\Rsvp_Archive;
use PKIW\Taxonomy;

require_once __DIR__ . '/../fixtures/wp-cli-stub.php';

/**
 * @group integration
 */
final class RsvpBackfillTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Rsvp_Archive() )->register();
		update_option( 'timezone_string', 'America/New_York' );
		WP_CLI::reset();
	}

	private function post( array $attrs = [], bool $with_card = true ): int {
		$content = $with_card
			? '<!-- wp:post-kinds-indieweb/rsvp-card ' . wp_json_encode( $attrs ) . ' /-->'
			: '<p>Card-less RSVP.</p>';
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $content ] );
		wp_set_object_terms( $id, 'rsvp', Taxonomy::TAXONOMY );
		foreach ( [ '_pkiw_cite_name', '_pkiw_cite_url', '_pkiw_rsvp_status', '_pkiw_event_start', '_pkiw_event_end', Rsvp_Archive::SORT_KEY ] as $key ) {
			delete_post_meta( $id, $key );
		}

		return (int) $id;
	}

	public function test_dry_run_lists_only_nonempty_plans_with_values_and_never_location(): void {
		$full = $this->post( [ 'eventName' => 'Backfill event', 'eventUrl' => 'https://events.example/backfill', 'rsvpStatus' => 'remote', 'eventStart' => '2026-10-08T18:00', 'eventEnd' => '2026-10-08T20:00', 'eventLocation' => 'Never planned' ] );
		$cardless = $this->post( [], false );
		add_post_meta( $cardless, '_pkiw_event_start', '2026-10-09' );
		delete_post_meta( $cardless, Rsvp_Archive::SORT_KEY );
		$complete = $this->post( [ 'eventName' => 'Complete' ] );
		foreach ( Rsvp_Archive::backfill_plan( $complete ) as $op ) {
			add_post_meta( $complete, $op['key'], $op['value'], true );
		}

		$result = Operations::backfill_rsvp_meta( true, null );
		$this->assertIsArray( $result );
		$this->assertSame( [ $full, $cardless ], array_column( $result['candidates'], 'id' ) );
		$json = wp_json_encode( $result );
		$this->assertStringContainsString( '_pkiw_cite_name', $json );
		$this->assertStringContainsString( '_pkiw_rsvp_sort', $json );
		$this->assertStringNotContainsString( 'event_location', $json );
		$this->assertStringNotContainsString( 'Never planned', $json );
	}

	public function test_omitted_dry_run_defaults_true_and_write_without_ids_is_refused(): void {
		$this->post( [ 'eventName' => 'Candidate' ] );
		$result = Rsvp_Abilities::instance()->execute_backfill_rsvp_meta( [] );
		$this->assertTrue( $result['dry_run'] );

		$refused = Operations::backfill_rsvp_meta( false, null );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'pkiw_ids_required', $refused->get_error_code() );
	}

	public function test_write_applies_only_candidates_and_reports_exists_and_not_candidate(): void {
		$candidate = $this->post( [ 'eventName' => 'Write me', 'eventStart' => '2026-10-08T18:00' ] );
		$partial   = $this->post( [ 'eventName' => 'Race', 'eventUrl' => 'https://events.example/race' ] );
		$exists    = $this->post( [ 'eventName' => 'Already won the race', 'eventUrl' => 'https://events.example/existing' ] );
		$plan      = Rsvp_Archive::backfill_plan( $partial );
		$outside   = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<p>Not RSVP.</p>' ] );
		$race      = null;
		$race      = static function ( $check, int $object_id, string $meta_key, $meta_value, bool $unique ) use ( $exists, &$race ) {
			if ( $exists !== $object_id ) {
				return $check;
			}

			remove_filter( 'add_post_metadata', $race, 10 );
			add_post_meta( $object_id, $meta_key, $meta_value, $unique );
			add_filter( 'add_post_metadata', $race, 10, 5 );

			return false;
		};

		add_post_meta( $partial, $plan[0]['key'], 'Concurrent value', true );
		add_filter( 'add_post_metadata', $race, 10, 5 );
		try {
			$result = Operations::backfill_rsvp_meta( false, [ $candidate, $partial, $exists, $outside ] );
		} finally {
			remove_filter( 'add_post_metadata', $race, 10 );
		}

		$this->assertSame( [ $candidate, $partial ], $result['written'] );
		$this->assertNotContains( [ 'id' => $partial, 'reason' => 'exists' ], $result['skipped'] );
		$this->assertContains( [ 'id' => $exists, 'reason' => 'exists' ], $result['skipped'] );
		$this->assertContains( [ 'id' => $outside, 'reason' => 'not_candidate' ], $result['skipped'] );
		$this->assertSame( 'Concurrent value', get_post_meta( $partial, $plan[0]['key'], true ) );
		foreach ( array_slice( $plan, 1 ) as $op ) {
			$this->assertSame( $op['value'], get_post_meta( $partial, $op['key'], true ) );
		}
		$this->assertSame( [], Operations::backfill_rsvp_meta( true, null )['candidates'] );
	}

	public function test_cli_prints_the_same_dry_run_json_as_the_operation(): void {
		$this->post( [ 'eventName' => 'CLI candidate' ] );
		$expected = Operations::backfill_rsvp_meta( true, null );
		( new CLI_Commands() )->rsvp_meta( [ 'backfill' ], [ 'dry-run' => true ] );

		$this->assertNotEmpty( WP_CLI::$calls );
		$this->assertSame( 'log', WP_CLI::$calls[0][0] );
		$this->assertSame( $expected, json_decode( WP_CLI::$calls[0][1], true ) );
	}
}

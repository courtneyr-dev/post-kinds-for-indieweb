<?php
namespace PostKindsForIndieWeb\Tests\Unit;

use WP_UnitTestCase;
use PostKindsForIndieWeb\Import_Manager;

class ImportManagerTest extends WP_UnitTestCase {

	private Import_Manager $manager;

	public function set_up(): void {
		parent::set_up();
		$this->manager = new Import_Manager();
	}

	public function test_get_sources_returns_array() {
		$sources = $this->manager->get_sources();
		$this->assertIsArray( $sources );
		$this->assertNotEmpty( $sources );
	}

	/**
	 * @dataProvider expected_sources_provider
	 */
	public function test_source_exists( string $source_key ) {
		$sources = $this->manager->get_sources();
		$this->assertArrayHasKey( $source_key, $sources );
	}

	public function expected_sources_provider(): array {
		return [
			'listenbrainz'           => [ 'listenbrainz' ],
			'lastfm'                 => [ 'lastfm' ],
			'trakt_movies'           => [ 'trakt_movies' ],
			'trakt_shows'            => [ 'trakt_shows' ],
			'simkl'                  => [ 'simkl' ],
			'hardcover'              => [ 'hardcover' ],
			'foursquare'             => [ 'foursquare' ],
			'readwise_books'         => [ 'readwise_books' ],
			'readwise_articles'      => [ 'readwise_articles' ],
			'readwise_podcasts'      => [ 'readwise_podcasts' ],
			'readwise_tweets'        => [ 'readwise_tweets' ],
			'readwise_supplementals' => [ 'readwise_supplementals' ],
		];
	}

	/**
	 * @dataProvider expected_sources_provider
	 */
	public function test_source_has_required_fields( string $source_key ) {
		$sources = $this->manager->get_sources();
		$source  = $sources[ $source_key ];

		$this->assertArrayHasKey( 'name', $source );
		$this->assertArrayHasKey( 'type', $source );
		$this->assertArrayHasKey( 'kind', $source );
		$this->assertArrayHasKey( 'api_class', $source );
		$this->assertArrayHasKey( 'fetch_method', $source );
		$this->assertArrayHasKey( 'batch_size', $source );
	}

	public function test_start_import_rejects_invalid_source() {
		$result = $this->manager->start_import( 'nonexistent_source_xyz' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Unknown import source', $result['error'] );
	}

	public function test_start_import_creates_job_with_pending_status() {
		// Use lastfm since it doesn't require auth (requires_auth = false).
		$result = $this->manager->start_import( 'lastfm', [ 'username' => 'testuser' ] );

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'job_id', $result );

		$job = $this->manager->get_job( $result['job_id'] );
		$this->assertNotNull( $job );
		$this->assertSame( 'pending', $job['status'] );
		$this->assertSame( 'lastfm', $job['source'] );
		$this->assertSame( 0, $job['progress'] );
		$this->assertSame( 0, $job['imported'] );
		$this->assertSame( 0, $job['skipped'] );
		$this->assertSame( 0, $job['failed'] );
		$this->assertNull( $job['started_at'] );
		$this->assertNull( $job['completed_at'] );
	}

	public function test_get_status_returns_job_data() {
		$result = $this->manager->start_import( 'lastfm', [ 'username' => 'testuser' ] );
		$status = $this->manager->get_status( $result['job_id'] );

		$this->assertNotNull( $status );
		$this->assertSame( $result['job_id'], $status['id'] );
		$this->assertSame( 'lastfm', $status['source'] );
		$this->assertSame( 'pending', $status['status'] );
		$this->assertArrayHasKey( 'progress', $status );
		$this->assertArrayHasKey( 'imported', $status );
		$this->assertArrayHasKey( 'skipped', $status );
		$this->assertArrayHasKey( 'failed', $status );
		$this->assertArrayHasKey( 'errors', $status );
		$this->assertArrayHasKey( 'elapsed', $status );
	}

	public function test_get_status_returns_null_for_unknown_job() {
		$status = $this->manager->get_status( 'nonexistent-job-id' );
		$this->assertNull( $status );
	}

	public function test_cancel_import_sets_cancelled_status() {
		$result = $this->manager->start_import( 'lastfm', [ 'username' => 'testuser' ] );
		$job_id = $result['job_id'];

		$cancelled = $this->manager->cancel_import( $job_id );
		$this->assertTrue( $cancelled );

		$status = $this->manager->get_status( $job_id );
		$this->assertSame( 'cancelled', $status['status'] );
	}

	public function test_cancel_import_sets_completed_at() {
		$result = $this->manager->start_import( 'lastfm', [ 'username' => 'testuser' ] );
		$job_id = $result['job_id'];

		$this->manager->cancel_import( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertNotNull( $job['completed_at'] );
	}

	public function test_cancel_import_returns_false_for_unknown_job() {
		$result = $this->manager->cancel_import( 'nonexistent-job-id' );
		$this->assertFalse( $result );
	}

	public function test_get_job_returns_null_for_unknown_job() {
		$job = $this->manager->get_job( 'nonexistent-job-id' );
		$this->assertNull( $job );
	}

	public function test_start_import_job_has_uuid() {
		$result = $this->manager->start_import( 'lastfm', [ 'username' => 'testuser' ] );
		$job_id = $result['job_id'];

		// UUID v4 format check.
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$job_id
		);
	}

	public function test_start_import_job_stores_options() {
		$options = [
			'username' => 'testuser',
			'limit'    => 25,
		];
		$result = $this->manager->start_import( 'lastfm', $options );
		$job    = $this->manager->get_job( $result['job_id'] );

		$this->assertSame( $options, $job['options'] );
	}

	public function test_start_import_schedules_cron_event() {
		$result = $this->manager->start_import( 'lastfm', [ 'username' => 'testuser' ] );

		$this->assertTrue( $result['success'] );
		// The job should have scheduled a cron event for processing.
		$scheduled = wp_next_scheduled( 'post_kinds_indieweb_process_import', [ $result['job_id'], 'lastfm' ] );
		$this->assertNotFalse( $scheduled );
	}

	public function test_lastfm_requires_username() {
		// lastfm has requires_username = true.
		$result = $this->manager->start_import( 'lastfm', [] );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Username required', $result['error'] );
	}

	public function test_source_kinds_are_valid() {
		$valid_kinds = [ 'listen', 'watch', 'read', 'checkin', 'bookmark', 'note' ];
		$sources     = $this->manager->get_sources();

		foreach ( $sources as $key => $source ) {
			$this->assertContains(
				$source['kind'],
				$valid_kinds,
				"Source '{$key}' has invalid kind '{$source['kind']}'"
			);
		}
	}

	public function test_source_batch_sizes_are_positive() {
		$sources = $this->manager->get_sources();

		foreach ( $sources as $key => $source ) {
			$this->assertGreaterThan(
				0,
				$source['batch_size'],
				"Source '{$key}' has non-positive batch_size"
			);
		}
	}
}

<?php
/**
 * Imported posts get an author when no user is logged in.
 *
 * Import_Manager created posts with 'post_author' => get_current_user_id().
 * Scheduled sync and the later batches of every import run from WP-Cron as
 * user 0, so their posts had no author (#218). The job now records the
 * author when it starts: the importing user for a manual run, the
 * pkiw_default_author setting otherwise, and the earliest administrator
 * when that setting doesn't name a user who can create posts.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\APIs\API_Base;
use PKIW\Import_Manager;
use PKIW\Tests\ApiTestCase;
use ReflectionProperty;

/**
 * Authorship of imported posts.
 */
final class ImportAuthorTest extends ApiTestCase {

	/**
	 * Import manager under test.
	 *
	 * @var Import_Manager
	 */
	private Import_Manager $manager;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );

		update_option( 'pkiw_api_credentials', [ 'readwise' => [ 'access_token' => 'fake-readwise-token' ] ] );

		$books                                 = $this->load_fixture( 'readwise/books.json' );
		$books['results'][0]['num_highlights'] = 0;
		$this->mock_http_response( 'readwise.io/api/v2/books/', $books );

		$this->manager = new Import_Manager();
	}

	public function test_cron_run_uses_the_configured_default_author(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( 'pkiw_default_author', $editor );

		$job = $this->run_import();

		$this->assertSame( $editor, $this->imported_post_author() );
		$this->assertSame( $editor, $job['author_id'] );
		$this->assertSame( 'default_author', $job['author_source'] );
	}

	public function test_cron_run_without_the_setting_uses_user_1(): void {
		delete_option( 'pkiw_default_author' );

		$job = $this->run_import();

		$this->assertSame( 1, $this->imported_post_author() );
		$this->assertSame( 1, $job['author_id'] );
	}

	public function test_manual_run_keeps_the_importing_user_when_cron_runs_the_batch(): void {
		$importer = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_option( 'pkiw_default_author', self::factory()->user->create( [ 'role' => 'editor' ] ) );

		wp_set_current_user( $importer );
		$started = $this->start();
		// Batches run from WP-Cron, with no user.
		wp_set_current_user( 0 );
		$job = $this->process( $started );

		$this->assertSame( $importer, $this->imported_post_author() );
		$this->assertSame( $importer, $job['author_id'] );
		$this->assertSame( 'current_user', $job['author_source'] );
	}

	/**
	 * A default author who isn't a user, or can't create posts, is replaced
	 * by the earliest administrator.
	 *
	 * @dataProvider invalid_default_author_provider
	 *
	 * @param string $kind 'missing' for a user ID with no user, 'subscriber' for a user who can't create posts.
	 */
	public function test_invalid_default_author_falls_back_to_the_earliest_administrator( string $kind ): void {
		self::factory()->user->create( [ 'role' => 'administrator' ] );
		$default = 'missing' === $kind ? 999999 : self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_option( 'pkiw_default_author', $default );

		$job = $this->run_import();

		$this->assertSame( 1, $this->imported_post_author() );
		$this->assertSame( 1, $job['author_id'] );
		$this->assertSame( 'fallback_administrator', $job['author_source'] );
	}

	/**
	 * Default authors the fallback replaces.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function invalid_default_author_provider(): array {
		return [
			'no such user' => [ 'missing' ],
			'subscriber'   => [ 'subscriber' ],
		];
	}

	public function test_import_does_not_start_when_no_user_can_own_the_posts(): void {
		( new \WP_User( 1 ) )->set_role( 'subscriber' );
		update_option( 'pkiw_default_author', 999999 );
		$before = $this->count_posts();

		$started = $this->manager->start_import( 'readwise_books', [ 'limit' => 20 ] );

		$this->assertFalse( $started['success'] );
		$this->assertStringContainsString( 'author', $started['error'] );
		$this->assertSame( $before, $this->count_posts() );
	}

	/**
	 * A job queued before the author was recorded resolves it when it runs.
	 */
	public function test_job_queued_without_an_author_resolves_one_at_run_time(): void {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( 'pkiw_default_author', $editor );

		$started = $this->start();
		$job     = $this->manager->get_job( $started['job_id'] );
		unset( $job['author_id'], $job['author_source'] );
		update_option( 'pkiw_import_job_' . $started['job_id'], $job, false );

		$job = $this->process( $started );

		$this->assertSame( $editor, $this->imported_post_author() );
		$this->assertSame( $editor, $job['author_id'] );
	}

	/**
	 * Start and run one readwise_books job.
	 *
	 * @return array<string, mixed> The finished job.
	 */
	private function run_import(): array {
		return $this->process( $this->start() );
	}

	/**
	 * Start a readwise_books job with the options Scheduled_Sync::run_import() passes.
	 *
	 * @return array<string, mixed> start_import() result.
	 */
	private function start(): array {
		$started = $this->manager->start_import(
			'readwise_books',
			[
				'skip_existing'   => true,
				'update_existing' => false,
				'create_posts'    => true,
				'limit'           => 20,
			]
		);
		$this->assertTrue( $started['success'], $started['error'] ?? '' );

		return $started;
	}

	/**
	 * Process a started job's first batch.
	 *
	 * @param array<string, mixed> $started start_import() result.
	 * @return array<string, mixed> The finished job.
	 */
	private function process( array $started ): array {
		( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );

		$this->manager->process_import_batch( $started['job_id'], 'readwise_books' );

		$job = $this->manager->get_job( $started['job_id'] );
		$this->assertSame( 'completed', $job['status'], implode( '; ', $job['errors'] ) );
		$this->assertSame( 1, $job['imported'], implode( '; ', $job['errors'] ) );

		return $job;
	}

	/**
	 * Author of the post the job imported.
	 *
	 * @return int
	 */
	private function imported_post_author(): int {
		$posts = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => 'any',
				'title'       => 'Read Atomic Habits',
			]
		);
		$this->assertCount( 1, $posts );

		return (int) $posts[0]->post_author;
	}

	/**
	 * Count posts in every status.
	 *
	 * @return int
	 */
	private function count_posts(): int {
		return count(
			get_posts(
				[
					'post_type'   => 'any',
					'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}
}

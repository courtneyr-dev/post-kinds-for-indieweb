<?php
/**
 * Import runs skip items an earlier run imported, whatever their status.
 *
 * Imports create drafts by default. A WP_Query without post_status matches
 * draft, pending and future posts only inside wp-admin, and private posts
 * only for a logged-in reader. The hourly pkiw_scheduled_sync cron runs
 * outside wp-admin with no current user, so find_existing_post() never saw
 * the drafts an earlier run created, and every run imported the same
 * Readwise books again. These tests run as user 0 outside wp-admin, through
 * start_import() and process_import_batch(), the path a scheduled job takes.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\APIs\API_Base;
use PKIW\Import_Manager;
use PKIW\Tests\ApiTestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Duplicate detection for background imports.
 */
final class ImportSkipExistingTest extends ApiTestCase {

	/**
	 * Book title in tests/phpunit/fixtures/readwise/books.json.
	 */
	private const TITLE = 'Atomic Habits';

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

		// No highlights, so a run makes one request and never waits out
		// Readwise's one-request-per-three-seconds rate limit.
		$books                                     = $this->load_fixture( 'readwise/books.json' );
		$books['results'][0]['num_highlights']     = 0;
		$this->mock_http_response( 'readwise.io/api/v2/books/', $books );

		$this->manager = new Import_Manager();
	}

	public function test_second_run_skips_the_draft_the_first_run_imported(): void {
		$first = $this->run_readwise_books_import();

		$this->assertSame( 1, $first['imported'] );
		$imported = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => 'any',
				'title'       => 'Read ' . self::TITLE,
			]
		);
		$this->assertCount( 1, $imported );
		$this->assertSame( 'draft', $imported[0]->post_status );
		$this->assertSame( self::TITLE, get_post_meta( $imported[0]->ID, '_pkiw_cite_name', true ) );

		$before = $this->count_posts();
		$second = $this->run_readwise_books_import();

		$this->assertSame( 0, $second['imported'], 'The second run imported the book again.' );
		$this->assertSame( 1, $second['skipped'] );
		$this->assertSame( $before, $this->count_posts() );
	}

	/**
	 * A trashed import blocks re-import too: see find_existing_post().
	 *
	 * @dataProvider existing_post_provider
	 *
	 * @param string $status Status of the post already on the site.
	 * @param string $match  'meta' to match on _pkiw_cite_name, 'title' to match on the post title only.
	 */
	public function test_run_skips_an_item_that_already_exists( string $status, string $match ): void {
		$post_id = $this->create_existing_post( $status, $match );
		$before  = $this->count_posts();

		$job = $this->run_readwise_books_import();

		$this->assertSame( 0, $job['imported'], "A {$status} post matched by {$match} was imported again." );
		$this->assertSame( 1, $job['skipped'] );
		$this->assertSame( $before, $this->count_posts() );
		$this->assertSame( $status, get_post_status( $post_id ) );
	}

	/**
	 * Existing post status and how it matches the imported book.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function existing_post_provider(): array {
		return [
			'draft by cite name'   => [ 'draft', 'meta' ],
			'pending by cite name' => [ 'pending', 'meta' ],
			'private by cite name' => [ 'private', 'meta' ],
			'future by cite name'  => [ 'future', 'meta' ],
			'publish by cite name' => [ 'publish', 'meta' ],
			'trash by cite name'   => [ 'trash', 'meta' ],
			'draft by title'       => [ 'draft', 'title' ],
			'publish by title'     => [ 'publish', 'title' ],
			'trash by title'       => [ 'trash', 'title' ],
		];
	}

	public function test_update_existing_updates_the_live_copy_not_a_trashed_copy(): void {
		$live    = $this->create_existing_post( 'publish', 'meta', '2026-01-01 00:00:00' );
		$trashed = $this->create_existing_post( 'trash', 'meta', '2026-06-01 00:00:00' );

		$job = $this->run_readwise_books_import( [ 'update_existing' => true ] );

		$this->assertSame( 0, $job['imported'] );
		$this->assertSame( 1, $job['updated'] );
		$this->assertSame( 'James Clear', get_post_meta( $live, '_pkiw_cite_author', true ) );
		$this->assertSame( '', get_post_meta( $trashed, '_pkiw_cite_author', true ) );
	}

	/**
	 * Listen and watch lookups add a date_query; drafts still match.
	 *
	 * @dataProvider dated_kind_provider
	 *
	 * @param string               $kind      Import kind.
	 * @param array<string, mixed> $item      Item as the API client returns it.
	 * @param string               $cite_name _pkiw_cite_name on the earlier import.
	 */
	public function test_draft_is_found_for_dated_kinds( string $kind, array $item, string $cite_name ): void {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_title'  => 'Imported earlier',
				'post_date'   => '2026-09-01 20:00:00',
			]
		);
		update_post_meta( $post_id, '_pkiw_cite_name', $cite_name );

		$find = new ReflectionMethod( Import_Manager::class, 'find_existing_post' );

		$this->assertSame( $post_id, $find->invoke( $this->manager, $item, [ 'kind' => $kind ] ) );
	}

	/**
	 * Items for kinds whose lookup is limited to the item's day.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public function dated_kind_provider(): array {
		return [
			'watch'                   => [ 'watch', [ 'title' => 'Heat', 'watched_at' => '2026-09-01T20:00:00Z' ], 'Heat' ],
			'listen, music track'     => [ 'listen', [ 'track' => 'Teardrop', 'listened_at' => strtotime( '2026-09-01 20:00:00 UTC' ) ], 'Teardrop' ],
			'listen, podcast episode' => [ 'listen', [ 'episode_title' => 'Episode 12', 'last_highlight' => '2026-09-01T20:00:00Z' ], 'Episode 12' ],
		];
	}

	/**
	 * Run one readwise_books job with the options Scheduled_Sync::run_import() passes.
	 *
	 * @param array<string, mixed> $options Options to override.
	 * @return array<string, mixed> The finished job.
	 */
	private function run_readwise_books_import( array $options = [] ): array {
		( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );

		$started = $this->manager->start_import(
			'readwise_books',
			array_merge(
				[
					'skip_existing'   => true,
					'update_existing' => false,
					'create_posts'    => true,
					'limit'           => 20,
				],
				$options
			)
		);
		$this->assertTrue( $started['success'] );

		$this->manager->process_import_batch( $started['job_id'], 'readwise_books' );

		$job = $this->manager->get_job( $started['job_id'] );
		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( 0, $job['failed'], implode( '; ', $job['errors'] ) );

		return $job;
	}

	/**
	 * Create the post an earlier import left behind.
	 *
	 * @param string      $status    Post status, or 'trash' for a trashed draft.
	 * @param string      $match     'meta' or 'title'.
	 * @param string|null $post_date Local post date.
	 * @return int Post ID.
	 */
	private function create_existing_post( string $status, string $match, ?string $post_date = null ): int {
		$args = [
			'post_type'   => 'post',
			'post_status' => 'trash' === $status ? 'draft' : $status,
			// A title match needs the imported title; a cite-name match must not also match on title.
			'post_title'  => 'title' === $match ? 'Read ' . self::TITLE : 'Notes on a habits book',
		];
		if ( 'future' === $status ) {
			$post_date = gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS );
		}
		if ( null !== $post_date ) {
			$args['post_date'] = $post_date;
		}

		$post_id = self::factory()->post->create( $args );

		if ( 'meta' === $match ) {
			update_post_meta( $post_id, '_pkiw_cite_name', self::TITLE );
		}
		if ( 'trash' === $status ) {
			wp_trash_post( $post_id );
		}

		return $post_id;
	}

	/**
	 * Posts of type post in every status, trash included.
	 *
	 * @return int
	 */
	private function count_posts(): int {
		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => [ 'any', 'trash' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return count( $query->posts );
	}
}

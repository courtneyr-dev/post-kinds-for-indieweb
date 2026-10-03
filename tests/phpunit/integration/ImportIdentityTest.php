<?php
/**
 * Bookmark, check-in and note imports find their earlier imports.
 *
 * Import_Manager::find_existing_post() built a lookup for listen, watch and
 * read only and returned null for every other kind, so Readwise Articles,
 * Readwise Tweets (bookmark), Foursquare (checkin) and Readwise
 * Supplementals (note) imported every item again on each scheduled run
 * (#217). Each of these sources now stores a per-source identity on create
 * and looks it up before importing. Posts imported before the identity
 * existed are matched on the fields they do have.
 *
 * These tests run as user 0 outside wp-admin, through start_import() and
 * process_import_batch(), the path a scheduled job takes.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use PKIW\APIs\API_Base;
use PKIW\Import_Manager;
use PKIW\Tests\ApiTestCase;
use ReflectionProperty;

/**
 * Duplicate detection for kinds without a title lookup.
 */
final class ImportIdentityTest extends ApiTestCase {

	/**
	 * Import manager under test.
	 *
	 * @var Import_Manager
	 */
	private Import_Manager $manager;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );

		update_option(
			'pkiw_api_credentials',
			[
				'readwise'   => [ 'access_token' => 'fake-readwise-token' ],
				'foursquare' => [ 'access_token' => 'fake-foursquare-token' ],
			]
		);

		$this->manager = new Import_Manager();
	}

	/**
	 * A second scheduled run creates nothing.
	 *
	 * @dataProvider source_provider
	 *
	 * @param string                           $source   Import source.
	 * @param string                           $url      URL substring the API client requests.
	 * @param array<string, mixed>             $response API response.
	 * @param array<int, string>               $ids      Identities the first run stores.
	 */
	public function test_second_run_creates_no_posts( string $source, string $url, array $response, array $ids ): void {
		$this->mock_http_response( $url, $response );

		$first = $this->run_import( $source );

		$this->assertSame( count( $ids ), $first['imported'] );
		foreach ( $ids as $id ) {
			$this->assertSame( 1, $this->count_posts_with( '_pkiw_import_source_id', $id ), "No post stores identity {$id}." );
		}

		$before = $this->count_posts();
		$second = $this->run_import( $source );

		$this->assertSame( 0, $second['imported'], "The second {$source} run imported items again." );
		$this->assertSame( count( $ids ), $second['skipped'] );
		$this->assertSame( $before, $this->count_posts() );
	}

	/**
	 * An earlier import blocks re-import in any status, trash included.
	 *
	 * @dataProvider status_provider
	 *
	 * @param string $status Status of the earlier import.
	 */
	public function test_earlier_import_in_any_status_blocks_reimport( string $status ): void {
		$this->mock_http_response( 'readwise.io/api/v2/books/', $this->readwise_response( [ $this->article( 501, 'An Article' ) ] ) );
		$post_id = self::factory()->post->create( [ 'post_status' => $status ] );
		update_post_meta( $post_id, '_pkiw_import_source_id', 'readwise_articles:501' );

		$job = $this->run_import( 'readwise_articles' );

		$this->assertSame( 0, $job['imported'], "A {$status} import was imported again." );
		$this->assertSame( 1, $job['skipped'] );
	}

	/**
	 * Two different notes with the same title are two posts.
	 */
	public function test_notes_with_the_same_title_are_separate_items(): void {
		$this->mock_http_response(
			'readwise.io/api/v2/books/',
			$this->readwise_response( [ $this->supplemental( 701, 'Meeting notes' ), $this->supplemental( 702, 'Meeting notes' ) ] )
		);

		$first  = $this->run_import( 'readwise_supplementals' );
		$second = $this->run_import( 'readwise_supplementals' );

		$this->assertSame( 2, $first['imported'] );
		$this->assertSame( 0, $second['imported'] );
		$this->assertSame( 2, $second['skipped'] );
	}

	/**
	 * Posts imported before the identity existed are found on the fields
	 * they have, and get the identity so later runs match on it.
	 *
	 * @dataProvider legacy_post_provider
	 *
	 * @param string                $source   Import source.
	 * @param string                $url      URL substring the API client requests.
	 * @param array<string, mixed>  $response API response.
	 * @param array<string, string> $meta     Meta the pre-upgrade import wrote.
	 * @param string                $identity Identity the item has now.
	 */
	public function test_pre_upgrade_import_is_found_and_backfilled( string $source, string $url, array $response, array $meta, string $identity ): void {
		$this->mock_http_response( $url, $response );
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		$job = $this->run_import( $source );

		$this->assertSame( 0, $job['imported'], "The pre-upgrade {$source} import was imported again." );
		$this->assertSame( 1, $job['skipped'] );
		$this->assertSame( $identity, get_post_meta( $post_id, '_pkiw_import_source_id', true ) );
	}

	/**
	 * A bookmark the site owner made by hand isn't an earlier import.
	 */
	public function test_hand_made_bookmark_of_the_same_url_does_not_block_import(): void {
		$this->mock_http_response( 'readwise.io/api/v2/books/', $this->readwise_response( [ $this->article( 501, 'An Article' ) ] ) );
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		update_post_meta( $post_id, '_pkiw_cite_url', 'https://example.com/article-501' );

		$job = $this->run_import( 'readwise_articles' );

		$this->assertSame( 1, $job['imported'] );
		$this->assertSame( '', get_post_meta( $post_id, '_pkiw_import_source_id', true ) );
	}

	/**
	 * A pre-upgrade post that already belongs to another item isn't reused.
	 */
	public function test_legacy_fallback_ignores_posts_that_have_an_identity(): void {
		$this->mock_http_response( 'readwise.io/api/v2/books/', $this->readwise_response( [ $this->supplemental( 702, 'Meeting notes' ) ] ) );
		$post_id = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		update_post_meta( $post_id, '_pkiw_cite_name', 'Meeting notes' );
		update_post_meta( $post_id, '_pkiw_imported_from', 'Readwise Supplementals' );
		update_post_meta( $post_id, '_pkiw_import_source_id', 'readwise_supplementals:701' );

		$job = $this->run_import( 'readwise_supplementals' );

		$this->assertSame( 1, $job['imported'] );
		$this->assertSame( 'readwise_supplementals:701', get_post_meta( $post_id, '_pkiw_import_source_id', true ) );
	}

	/**
	 * Sources whose kind had no duplicate lookup.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<int, string>}>
	 */
	public function source_provider(): array {
		return [
			'readwise_articles (bookmark)'   => [
				'readwise_articles',
				'readwise.io/api/v2/books/',
				$this->readwise_response( [ $this->article( 501, 'An Article' ), $this->article( 502, 'Another Article' ) ] ),
				[ 'readwise_articles:501', 'readwise_articles:502' ],
			],
			'readwise_tweets (bookmark)'     => [
				'readwise_tweets',
				'readwise.io/api/v2/books/',
				$this->readwise_response( [ $this->article( 601, 'A thread' ) ] ),
				[ 'readwise_tweets:601' ],
			],
			'readwise_supplementals (note)'  => [
				'readwise_supplementals',
				'readwise.io/api/v2/books/',
				$this->readwise_response( [ $this->supplemental( 701, 'A note' ) ] ),
				[ 'readwise_supplementals:701' ],
			],
			'foursquare (checkin)'           => [
				'foursquare',
				'api.foursquare.com/v2/users/self/checkins',
				$this->foursquare_response( [ 'fsq-checkin-1', 'fsq-checkin-2' ] ),
				[ 'foursquare:fsq-checkin-1', 'foursquare:fsq-checkin-2' ],
			],
		];
	}

	/**
	 * Every status an earlier import can be in.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function status_provider(): array {
		return [
			'draft'   => [ 'draft' ],
			'pending' => [ 'pending' ],
			'private' => [ 'private' ],
			'publish' => [ 'publish' ],
			'trash'   => [ 'trash' ],
		];
	}

	/**
	 * Pre-upgrade imports and the fields they carry.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, string>, 4: string}>
	 */
	public function legacy_post_provider(): array {
		return [
			'bookmark by cite URL'              => [
				'readwise_articles',
				'readwise.io/api/v2/books/',
				$this->readwise_response( [ $this->article( 501, 'An Article' ) ] ),
				[
					'_pkiw_cite_url'      => 'https://example.com/article-501',
					'_pkiw_imported_from' => 'Readwise Articles',
				],
				'readwise_articles:501',
			],
			'note by title and URL'             => [
				'readwise_supplementals',
				'readwise.io/api/v2/books/',
				$this->readwise_response( [ $this->supplemental( 701, 'A note' ) ] ),
				[
					'_pkiw_cite_name'     => 'A note',
					'_pkiw_cite_url'      => 'https://example.com/note-701',
					'_pkiw_imported_from' => 'Readwise Supplementals',
				],
				'readwise_supplementals:701',
			],
			'checkin from the Foursquare sync'  => [
				'foursquare',
				'api.foursquare.com/v2/users/self/checkins',
				$this->foursquare_response( [ 'fsq-checkin-1' ] ),
				[ '_pkiw_checkin_foursquare_id' => 'fsq-checkin-1' ],
				'foursquare:fsq-checkin-1',
			],
		];
	}

	/**
	 * Run one job with the options Scheduled_Sync::run_import() passes.
	 *
	 * @param string $source Import source.
	 * @return array<string, mixed> The finished job.
	 */
	private function run_import( string $source ): array {
		( new ReflectionProperty( API_Base::class, 'last_request_times' ) )->setValue( null, [] );

		$started = $this->manager->start_import(
			$source,
			[
				'skip_existing'   => true,
				'update_existing' => false,
				'create_posts'    => true,
				'limit'           => 50,
			]
		);
		$this->assertTrue( $started['success'], $started['error'] ?? '' );

		$this->manager->process_import_batch( $started['job_id'], $source );

		$job = $this->manager->get_job( $started['job_id'] );
		$this->assertSame( 'completed', $job['status'], implode( '; ', $job['errors'] ) );
		$this->assertSame( 0, $job['failed'], implode( '; ', $job['errors'] ) );

		return $job;
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

	/**
	 * Count posts of any status with a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param string $value Meta value.
	 * @return int
	 */
	private function count_posts_with( string $key, string $value ): int {
		return count(
			get_posts(
				[
					'post_type'   => 'any',
					'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
					'meta_key'    => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			)
		);
	}

	/**
	 * A Readwise /books/ response.
	 *
	 * @param array<int, array<string, mixed>> $results Results.
	 * @return array<string, mixed>
	 */
	private function readwise_response( array $results ): array {
		return [
			'count'          => count( $results ),
			'nextPageCursor' => null,
			'results'        => $results,
		];
	}

	/**
	 * A Readwise article or tweet source.
	 *
	 * @param int    $id    Readwise book ID.
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	private function article( int $id, string $title ): array {
		return [
			'id'                => $id,
			'title'             => $title,
			'author'            => 'A Writer',
			'category'          => 'articles',
			'source'            => 'reader',
			'source_url'        => 'https://example.com/article-' . $id,
			'num_highlights'    => 0,
			'last_highlight_at' => '2026-09-01T10:00:00Z',
			'updated'           => '2026-09-01T10:00:00Z',
		];
	}

	/**
	 * A Readwise supplemental source.
	 *
	 * @param int    $id    Readwise book ID.
	 * @param string $title Title.
	 * @return array<string, mixed>
	 */
	private function supplemental( int $id, string $title ): array {
		return [
			'id'                => $id,
			'title'             => $title,
			'category'          => 'supplementals',
			'source'            => 'supplemental',
			'source_url'        => 'https://example.com/note-' . $id,
			'num_highlights'    => 0,
			'last_highlight_at' => '2026-09-01T10:00:00Z',
			'updated'           => '2026-09-01T10:00:00Z',
			'document_note'     => 'Note body.',
		];
	}

	/**
	 * A Foursquare users/self/checkins response.
	 *
	 * @param array<int, string> $ids Check-in IDs.
	 * @return array<string, mixed>
	 */
	private function foursquare_response( array $ids ): array {
		$items = [];
		foreach ( $ids as $id ) {
			$items[] = [
				'id'        => $id,
				'createdAt' => 1789243200,
				'venue'     => [
					'id'   => 'venue-' . $id,
					'name' => 'Venue ' . $id,
				],
			];
		}

		return [ 'response' => [ 'checkins' => [ 'items' => $items ] ] ];
	}
}

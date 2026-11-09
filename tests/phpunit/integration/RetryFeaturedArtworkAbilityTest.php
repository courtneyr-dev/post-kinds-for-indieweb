<?php
/**
 * Retry-featured-artwork ability and CLI coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Artwork_Fetch_Guard;
use PKIW\CLI_Commands;
use PKIW\Featured_Artwork;
use PKIW\Meta_Fields;
use PKIW\Operations;
use PKIW\Taxonomy;

require_once dirname( __DIR__ ) . '/fixtures/wp-cli-stub.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-cli-commands.php';

/**
 * @group integration
 */
final class RetryFeaturedArtworkAbilityTest extends WP_UnitTestCase {

	private const POSTER = 'https://image.tmdb.org/t/p/w342/ability.jpg';

	private int $http_requests = 0;

	public function set_up(): void {
		parent::set_up();
		// The test base unregisters every meta key after each test.
		( new Meta_Fields() )->register_meta_fields();
		$this->http_requests = 0;
		_set_cron_array( [] );
		update_option( 'pkiw_settings', [ 'image_handling' => 'sideload' ] );
		Artwork_Fetch_Guard::use_resolver( static fn( string $host ): array => [ '93.184.216.34' ] );
		add_filter(
			'pre_http_request',
			function () {
				++$this->http_requests;
				return new WP_Error( 'http_request_failed', 'fixture failure' );
			}
		);
		WP_CLI::reset();
	}

	public function tear_down(): void {
		Artwork_Fetch_Guard::use_resolver( null );
		parent::tear_down();
	}

	private function watch_post(): int {
		add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Ability Fixture',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Ability Fixture","posterImage":"' . self::POSTER . '"} /-->',
			]
		);
		remove_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		wp_set_object_terms( $post_id, 'watch', Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * @param string $state    Retry state.
	 * @param int    $attempts Attempt count.
	 */
	private function state_candidate( string $state, int $attempts ): int {
		$post_id = $this->watch_post();
		update_post_meta( $post_id, Featured_Artwork::SOURCE_META, self::POSTER );
		update_post_meta(
			$post_id,
			Featured_Artwork::RETRY_META,
			[
				'url'      => self::POSTER,
				'attempts' => $attempts,
				'next'     => 'scheduled' === $state ? time() : 0,
				'state'    => $state,
				'code'     => 'http_request_failed',
			]
		);

		return $post_id;
	}

	private function legacy_candidate(): int {
		$post_id = $this->watch_post();
		update_post_meta( $post_id, Featured_Artwork::SOURCE_META, self::POSTER );

		return $post_id;
	}

	private function ability(): WP_Ability {
		$ability = wp_get_ability( 'post-kinds/retry-featured-artwork' );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		return $ability;
	}

	public function test_registered_as_administrator_only_and_not_mcp_public(): void {
		$ability = $this->ability();
		$this->assertFalse( $ability->get_meta()['mcp']['public'] );
		$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );

		foreach ( [ 'subscriber', 'editor' ] as $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			$result = $ability->execute( [] );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertIsArray( $ability->execute( [] ) );
	}

	public function test_dry_run_lists_scheduled_exhausted_and_legacy_but_not_permanent(): void {
		$scheduled = $this->state_candidate( 'scheduled', 2 );
		$exhausted = $this->state_candidate( 'exhausted', 4 );
		$legacy    = $this->legacy_candidate();
		$permanent = $this->state_candidate( 'permanent', 1 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability()->execute( [ 'dry_run' => true ] );

		$this->assertSame( 0, $this->http_requests );
		$this->assertSame( [ $scheduled, $exhausted, $legacy ], wp_list_pluck( $result['candidates'], 'id' ) );
		$this->assertSame( [ 'scheduled', 'exhausted', 'legacy' ], wp_list_pluck( $result['candidates'], 'state' ) );
		$this->assertNotContains( $permanent, wp_list_pluck( $result['candidates'], 'id' ) );
		$this->assertSame( [ 2, 4, 0 ], wp_list_pluck( $result['candidates'], 'attempts' ) );
		$this->assertSame( [ 'image.tmdb.org', 'image.tmdb.org', 'image.tmdb.org' ], wp_list_pluck( $result['candidates'], 'host' ) );
		$this->assertSame( 0, $result['fetched'] );
	}

	public function test_failing_real_run_preserves_state_and_creates_no_legacy_state_or_event(): void {
		$scheduled = $this->state_candidate( 'scheduled', 2 );
		$legacy    = $this->legacy_candidate();
		$before    = get_post_meta( $scheduled, Featured_Artwork::RETRY_META, true );

		$result = Operations::retry_featured_artwork( false, [ $scheduled, $legacy ] );

		$this->assertSame( 2, $result['fetched'] );
		$this->assertSame( 0, $result['succeeded'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertSame( 2, $this->http_requests );
		$this->assertSame( $before, get_post_meta( $scheduled, Featured_Artwork::RETRY_META, true ) );
		$this->assertFalse( metadata_exists( 'post', $legacy, Featured_Artwork::RETRY_META ) );
		$this->assertFalse( wp_next_scheduled( Featured_Artwork::RETRY_HOOK, [ $legacy ] ) );
	}

	public function test_real_run_fetches_at_most_ten_candidates(): void {
		$ids = [];
		for ( $i = 0; $i < 11; ++$i ) {
			$ids[] = $this->legacy_candidate();
		}

		$result = Operations::retry_featured_artwork( false, $ids );

		$this->assertSame( 10, $result['fetched'] );
		$this->assertSame( 10, $result['failed'] );
		$this->assertCount( 11, $result['candidates'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 10, $this->http_requests );
	}

	public function test_real_run_rechecks_thumbnail_after_taking_the_lock(): void {
		global $wpdb;

		$post_id       = $this->state_candidate( 'scheduled', 1 );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertSame( 0, get_post_thumbnail_id( $post_id ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulate cron setting the thumbnail after candidate discovery primed the cache.
			$wpdb->postmeta,
			[ 'post_id' => $post_id, 'meta_key' => '_thumbnail_id', 'meta_value' => $attachment_id ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Targeted test fixture insert.
			[ '%d', '%s', '%d' ]
		);

		$result = Operations::retry_featured_artwork( false, [ $post_id ] );

		$this->assertSame( [ $post_id ], wp_list_pluck( $result['candidates'], 'id' ) );
		$this->assertSame( 0, $result['fetched'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $this->http_requests );
	}

	public function test_real_run_rechecks_candidate_state_after_taking_the_lock(): void {
		global $wpdb;

		$post_id   = $this->state_candidate( 'scheduled', 1 );
		$scheduled = get_post_meta( $post_id, Featured_Artwork::RETRY_META, true );
		$permanent = array_merge( $scheduled, [ 'state' => 'permanent', 'next' => 0 ] );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulate cron changing C1 after candidate discovery primed the cache.
			$wpdb->postmeta,
			[ 'meta_value' => maybe_serialize( $permanent ) ],
			[ 'post_id' => $post_id, 'meta_key' => Featured_Artwork::RETRY_META ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Targeted test fixture update.
			[ '%s' ],
			[ '%d', '%s' ]
		);

		$result = Operations::retry_featured_artwork( false, [ $post_id ] );

		$this->assertSame( [ $post_id ], wp_list_pluck( $result['candidates'], 'id' ) );
		$this->assertSame( 0, $result['fetched'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $this->http_requests );
	}

	public function test_held_candidate_lock_counts_as_skipped_without_fetching(): void {
		global $wpdb;

		$post_id = $this->state_candidate( 'scheduled', 1 );
		$lock    = Featured_Artwork::RETRY_LOCK_PREFIX . $post_id;
		$wpdb->insert( $wpdb->options, [ 'option_name' => $lock, 'option_value' => time() . ':held', 'autoload' => 'off' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.

		$result = Operations::retry_featured_artwork( false, [ $post_id ] );

		$this->assertSame( 0, $result['fetched'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $this->http_requests );
	}

	public function test_output_matches_the_registered_schema(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->state_candidate( 'scheduled', 1 );

		$result = $this->ability()->execute( [ 'dry_run' => true ] );

		$this->assertSame( [ 'dry_run', 'candidates', 'fetched', 'succeeded', 'failed', 'skipped' ], array_keys( $result ) );
		$this->assertSame( [ 'id', 'state', 'attempts', 'host' ], array_keys( $result['candidates'][0] ) );
		$this->assertWPError( $this->ability()->execute( [ 'dry_run' => true, 'ids' => range( 1, 21 ) ] ) );
	}

	public function test_state_meta_not_in_get_post_meta(): void {
		$post_id = $this->state_candidate( 'scheduled', 1 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$meta_result = wp_get_ability( 'post-kinds/get-post-meta' )->execute( [ 'post_id' => $post_id ] );
		$this->assertArrayHasKey( 'watch_title', $meta_result['meta'] );
		$this->assertArrayNotHasKey( 'featured_artwork_retry', $meta_result['meta'] );
		$this->assertArrayNotHasKey( Featured_Artwork::RETRY_META, $meta_result['meta'] );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$this->assertSame( 200, $response->get_status() );
		$rest = $response->get_data();
		$this->assertArrayHasKey( Meta_Fields::PREFIX . 'watch_title', $rest['meta'] );
		$this->assertArrayNotHasKey( Featured_Artwork::RETRY_META, (array) ( $rest['meta'] ?? [] ) );
	}

	public function test_featured_artwork_cli_synopsis_accepts_retry_ids(): void {
		$doc = (string) ( new ReflectionMethod( CLI_Commands::class, 'featured_artwork' ) )->getDocComment();
		$this->assertMatchesRegularExpression( "/<action>\s+\* : Must be 'backfill' or 'retry'\./", $doc );
		$this->assertMatchesRegularExpression( '/\[--ids=<list>\]\s+\* : Limit retries to a comma-separated post ID list\./', $doc );
		preg_match_all( '/^\s*\* (<action>|\[--[^\]]+\])/m', $doc, $matches );
		$this->assertSame( [ '<action>', '[--dry-run]', '[--ids=<list>]' ], $matches[1] );
	}

	public function test_cli_retry_dry_run_prints_the_operations_candidate_count(): void {
		$this->state_candidate( 'scheduled', 1 );
		$this->legacy_candidate();
		$expected = count( Operations::retry_featured_artwork( true, [] )['candidates'] );
		WP_CLI::reset();

		( new CLI_Commands() )->featured_artwork( [ 'retry' ], [ 'dry-run' => true ] );

		$this->assertSame( [ [ 'success', "Dry run: {$expected} retry candidate(s)." ] ], WP_CLI::$calls );
		$this->assertSame( 0, $this->http_requests );
	}
}

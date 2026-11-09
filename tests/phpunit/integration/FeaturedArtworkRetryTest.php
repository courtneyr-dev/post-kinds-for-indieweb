<?php
/**
 * Featured artwork retry integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Artwork_Fetch_Guard;
use PKIW\Featured_Artwork;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class FeaturedArtworkRetryTest extends WP_UnitTestCase {

	private const URL_1 = 'https://image.tmdb.org/t/p/w342/one.jpg';
	private const URL_2 = 'https://image.tmdb.org/t/p/w342/two.jpg';
	private const COMIC_URL = 'https://static.metron.cloud/media/issue/one.jpg';

	/** @var string[] */
	private array $responses = [];

	private int $http_requests = 0;

	public function set_up(): void {
		parent::set_up();
		$this->responses     = [];
		$this->http_requests = 0;
		_set_cron_array( [] );
		update_option( 'pkiw_settings', [ 'image_handling' => 'sideload' ] );
		Artwork_Fetch_Guard::use_resolver( static fn( string $host ): array => [ '93.184.216.34' ] );
		add_filter( 'pre_http_request', [ $this, 'http_response' ], 10, 3 );
	}

	public function tear_down(): void {
		Artwork_Fetch_Guard::use_resolver( null );
		parent::tear_down();
	}

	/**
	 * Deterministic HTTP stand-in.
	 *
	 * @param mixed  $preempt Existing preempt result.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return array|WP_Error
	 */
	public function http_response( $preempt, array $args, string $url ) {
		unset( $preempt, $url );
		++$this->http_requests;
		$response = array_shift( $this->responses ) ?? 'failed';
		if ( 'throw' === $response ) {
			throw new RuntimeException( 'fixture exception' );
		}
		if ( 'failed' === $response ) {
			return new WP_Error( 'http_request_failed', 'fixture failure with private details' );
		}

		$status = 'success' === $response ? 200 : (int) $response;
		$body   = '';
		if ( 200 === $status ) {
			$body = (string) file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture bytes.
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- download_url() test stream.
			}
		}

		return [
			'response' => [
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Fixture failure',
			],
			'headers'  => [ 'content-type' => 'image/jpeg' ],
			'body'     => $body,
			'cookies'  => [],
		];
	}

	private function watch_post( string $url = self::URL_1, bool $allow_fetch = false ): int {
		if ( ! $allow_fetch ) {
			add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		}
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Fixture Watch',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Fixture Watch","posterImage":"' . $url . '"} /-->',
			]
		);
		if ( ! $allow_fetch ) {
			remove_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		}
		wp_set_object_terms( $post_id, 'watch', Taxonomy::TAXONOMY );

		return $post_id;
	}

	private function comic_post( string $url = self::COMIC_URL, string $metron_id = '101', bool $allow_fetch = false ): int {
		if ( ! $allow_fetch ) {
			add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		}
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Fixture Comic',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:post-kinds-indieweb/comic-card {"title":"Fixture Comic","metronId":"' . $metron_id . '","coverImage":"' . $url . '"} /-->',
			]
		);
		if ( ! $allow_fetch ) {
			remove_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
		}
		wp_set_object_terms( $post_id, 'comics', Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function retry( int $post_id ): array {
		$retry = get_post_meta( $post_id, Featured_Artwork::RETRY_META, true );
		$this->assertIsArray( $retry );
		return $retry;
	}

	private function cron_count( int $post_id ): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ Featured_Artwork::RETRY_HOOK ] ?? [] ) as $event ) {
				if ( [ $post_id ] === ( $event['args'] ?? [] ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * @param array<string,mixed> $overrides Retry values to override.
	 */
	private function set_retry( int $post_id, array $overrides = [] ): array {
		$retry = array_merge(
			[
				'url'      => self::URL_1,
				'attempts' => 1,
				'next'     => time(),
				'state'    => 'scheduled',
				'code'     => 'http_request_failed',
			],
			$overrides
		);
		update_post_meta( $post_id, Featured_Artwork::RETRY_META, $retry );

		return $retry;
	}

	public function test_state_has_no_message(): void {
		$post_id = $this->watch_post();
		foreach ( [ HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS ] as $index => $delay ) {
			$this->responses[] = 'failed';
			$before            = time();
			$result            = Featured_Artwork::apply( $post_id, self::URL_1 );
			$this->assertWPError( $result );
			$retry = $this->retry( $post_id );
			$this->assertSame( $index + 1, $retry['attempts'] );
			$this->assertSame( 'scheduled', $retry['state'] );
			$this->assertEqualsWithDelta( $before + $delay, $retry['next'], 5 );
			$this->assertSame( 1, $this->cron_count( $post_id ) );
		}

		$this->responses[] = 'failed';
		Featured_Artwork::apply( $post_id, self::URL_1 );
		$retry = $this->retry( $post_id );
		$this->assertSame( 4, $retry['attempts'] );
		$this->assertSame( 'exhausted', $retry['state'] );
		$this->assertSame( 0, $retry['next'] );
		$this->assertSame( 0, $this->cron_count( $post_id ) );
		$this->assertSame( [ 'url', 'attempts', 'next', 'state', 'code' ], array_keys( $retry ) );
		$this->assertStringNotContainsString( 'private details', serialize( $retry ) );
	}

	/**
	 * @dataProvider retryable_status_provider
	 */
	public function test_429_and_server_errors_schedule_a_retry( int $status ): void {
		$post_id          = $this->watch_post();
		$this->responses[] = (string) $status;

		Featured_Artwork::apply( $post_id, self::URL_1 );

		$this->assertSame( 'scheduled', $this->retry( $post_id )['state'] );
	}

	/** @return array<string,array{0:int}> */
	public static function retryable_status_provider(): array {
		return [
			'rate limited' => [ 429 ],
			'server error' => [ 503 ],
		];
	}

	public function test_404_is_permanent_and_schedules_no_event(): void {
		$post_id          = $this->watch_post();
		$this->responses[] = '404';

		Featured_Artwork::apply( $post_id, self::URL_1 );

		$retry = $this->retry( $post_id );
		$this->assertSame( 'permanent', $retry['state'] );
		$this->assertSame( 'http_404', $retry['code'] );
		$this->assertSame( 0, $this->cron_count( $post_id ) );
	}

	public function test_refused_save_makes_permanent_state_without_request_and_allowed_host_fetches(): void {
		$refused = $this->watch_post( 'https://169.254.169.254/latest/meta-data/poster.jpg', true );
		$retry   = $this->retry( $refused );
		$this->assertSame( 'permanent', $retry['state'] );
		$this->assertSame( 'pkiw_artwork_refused', $retry['code'] );
		$this->assertSame( 0, $this->http_requests );

		$this->watch_post( self::URL_1, true );
		$this->assertSame( 1, $this->http_requests, 'An allowed public host reaches the HTTP stand-in.' );
	}

	public function test_success_clears_retry_state_and_event(): void {
		$post_id          = $this->watch_post();
		$this->responses[] = 'failed';
		Featured_Artwork::apply( $post_id, self::URL_1 );
		$this->assertSame( 1, $this->cron_count( $post_id ) );

		$this->responses[] = 'success';
		$result            = Featured_Artwork::apply( $post_id, self::URL_1 );

		$this->assertIsInt( $result );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
		$this->assertSame( 0, $this->cron_count( $post_id ) );
	}

	public function test_metron_comic_twin_retries_then_saves_a_local_cover(): void {
		$post_id          = $this->comic_post();
		$this->responses[] = 'failed';
		$result            = Featured_Artwork::apply( $post_id, self::COMIC_URL );

		$this->assertWPError( $result );
		$this->assertSame( 'scheduled', $this->retry( $post_id )['state'] );
		$this->assertSame( 1, $this->cron_count( $post_id ) );

		$this->responses[] = 'success';
		$result            = Featured_Artwork::apply( $post_id, self::COMIC_URL );
		$this->assertIsInt( $result );
		$this->assertSame( $result, get_post_thumbnail_id( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
	}

	public function test_metron_comic_save_fetches_a_static_cover(): void {
		$this->responses[] = 'success';

		$post_id = $this->comic_post( self::COMIC_URL, '101', true );

		$this->assertSame( 1, $this->http_requests );
		$this->assertGreaterThan( 0, get_post_thumbnail_id( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
	}

	public function test_backfill_includes_only_comics_with_a_digit_metron_id(): void {
		$metron = $this->comic_post();
		$legacy = $this->comic_post( self::COMIC_URL, '' );
		$this->responses[] = 'success';

		$stats = ( new Featured_Artwork() )->backfill();

		$this->assertSame( 1, $stats['updated'] );
		$this->assertSame( 1, $stats['skipped'] );
		$this->assertSame( 1, $this->http_requests );
		$this->assertGreaterThan( 0, get_post_thumbnail_id( $metron ) );
		$this->assertSame( 0, get_post_thumbnail_id( $legacy ) );
		$this->assertFalse( metadata_exists( 'post', $metron, Featured_Artwork::RETRY_META ) );
	}

	public function test_only_the_first_nested_comic_card_can_enable_metron_sideloading(): void {
		$this->responses[] = 'success';
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Two Comic Cards',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-kinds-indieweb/comic-card {"title":"Legacy First","coverImage":"' . self::COMIC_URL . '"} /--></div><!-- /wp:group --><!-- wp:post-kinds-indieweb/comic-card {"title":"Metron Second","metronId":"202","coverImage":"' . self::COMIC_URL . '"} /-->',
			]
		);
		wp_set_object_terms( $post_id, 'comics', Taxonomy::TAXONOMY );

		$this->assertSame( 0, $this->http_requests );
		$this->assertSame( 0, get_post_thumbnail_id( $post_id ) );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
	}

	public function test_metron_comic_twin_refuses_other_hosts_and_ignores_non_digit_ids(): void {
		$refused = $this->comic_post( 'https://example.com/cover.jpg', '101', true );
		$retry   = $this->retry( $refused );
		$this->assertSame( 'permanent', $retry['state'] );
		$this->assertSame( 'pkiw_artwork_refused', $retry['code'] );
		$this->assertSame( 0, $this->http_requests );

		$non_digit = $this->comic_post( self::COMIC_URL, '101x', true );
		$this->assertSame( 0, $this->http_requests );
		$this->assertSame( 0, get_post_thumbnail_id( $non_digit ) );
		$this->assertFalse( metadata_exists( 'post', $non_digit, Featured_Artwork::RETRY_META ) );
		$this->assertSame( 0, $this->cron_count( $non_digit ) );
	}

	public function test_unscheduled_failure_writes_no_retry_state_or_event(): void {
		$post_id          = $this->watch_post();
		$this->responses[] = 'failed';

		$result = Featured_Artwork::apply( $post_id, self::URL_1, false );

		$this->assertWPError( $result );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
		$this->assertSame( 0, $this->cron_count( $post_id ) );
	}

	public function test_failing_backfill_writes_no_retry_state_or_event(): void {
		$post_id          = $this->watch_post();
		$this->responses[] = 'failed';

		$stats = ( new Featured_Artwork() )->backfill();

		$this->assertSame( 1, $stats['failed'] );
		$this->assertSame( 1, $this->http_requests );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
		$this->assertSame( 0, $this->cron_count( $post_id ) );
	}

	public function test_save_with_changed_or_removed_poster_clears_old_event(): void {
		$post_id          = $this->watch_post();
		$this->responses[] = 'failed';
		Featured_Artwork::apply( $post_id, self::URL_1 );

		$this->responses[] = 'failed';
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Fixture Watch","posterImage":"' . self::URL_2 . '"} /-->',
			]
		);
		$retry = $this->retry( $post_id );
		$this->assertSame( self::URL_2, $retry['url'] );
		$this->assertSame( 1, $retry['attempts'] );
		$this->assertSame( 1, $this->cron_count( $post_id ) );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:post-kinds-indieweb/watch-card {"mediaTitle":"Fixture Watch"} /-->',
			]
		);
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
		$this->assertSame( 0, $this->cron_count( $post_id ) );
	}

	public function test_trash_and_delete_clear_state_lock_and_event(): void {
		global $wpdb;

		foreach ( [ 'trash', 'delete' ] as $action ) {
			$post_id = $this->watch_post();
			$this->set_retry( $post_id );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, Featured_Artwork::RETRY_HOOK, [ $post_id ] );
			$lock = Featured_Artwork::RETRY_LOCK_PREFIX . $post_id;
			$wpdb->insert( $wpdb->options, [ 'option_name' => $lock, 'option_value' => '1:fixture', 'autoload' => 'off' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture for the production lock.

			if ( 'trash' === $action ) {
				wp_trash_post( $post_id );
			} else {
				wp_delete_post( $post_id, true );
			}

			$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify lock deletion.
			$this->assertSame( 0, $this->cron_count( $post_id ) );
		}
	}

	/**
	 * @dataProvider invalid_retry_context_provider
	 */
	public function test_invalid_retry_contexts_clear_state_without_a_request( string $context ): void {
		$post_id = 'page' === $context
			? self::factory()->post->create( [ 'post_type' => 'page' ] )
			: $this->watch_post();
		$this->set_retry( $post_id );
		update_post_meta( $post_id, Featured_Artwork::SOURCE_META, self::URL_1 );

		switch ( $context ) {
			case 'trash':
				wp_update_post( [ 'ID' => $post_id, 'post_status' => 'trash' ] );
				$this->set_retry( $post_id );
				break;
			case 'auto-draft':
				wp_update_post( [ 'ID' => $post_id, 'post_status' => 'auto-draft' ] );
				$this->set_retry( $post_id );
				break;
			case 'not-watch':
				wp_set_object_terms( $post_id, 'listen', Taxonomy::TAXONOMY );
				delete_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY );
				break;
			case 'setting':
				update_option( 'pkiw_settings', [ 'image_handling' => 'hotlink' ] );
				break;
			case 'featured':
				set_post_thumbnail( $post_id, self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' ) );
				break;
			case 'filter':
				add_filter( 'pkiw_set_featured_from_artwork', '__return_false' );
				break;
			case 'url':
				$this->set_retry( $post_id, [ 'url' => self::URL_2 ] );
				break;
		}

		Featured_Artwork::run_retry( $post_id );

		$this->assertSame( 0, $this->http_requests );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
	}

	/** @return array<string,array{0:string}> */
	public static function invalid_retry_context_provider(): array {
		return [
			'wrong post type' => [ 'page' ],
			'trash'           => [ 'trash' ],
			'auto draft'      => [ 'auto-draft' ],
			'wrong kind'      => [ 'not-watch' ],
			'wrong setting'   => [ 'setting' ],
			'featured image'  => [ 'featured' ],
			'disabled filter' => [ 'filter' ],
			'changed URL'     => [ 'url' ],
		];
	}

	public function test_missing_inactive_and_future_state_stop_unchanged(): void {
		$post_id = $this->watch_post();
		Featured_Artwork::run_retry( $post_id );
		$this->assertSame( 0, $this->http_requests );

		foreach ( [ [ 'state' => 'exhausted' ], [ 'next' => time() + 300 ] ] as $override ) {
			$expected = $this->set_retry( $post_id, $override );
			Featured_Artwork::run_retry( $post_id );
			$this->assertSame( $expected, get_post_meta( $post_id, Featured_Artwork::RETRY_META, true ) );
			$this->assertSame( 0, $this->http_requests );
		}
	}

	public function test_fresh_lock_stops_and_stale_lock_is_taken_over(): void {
		global $wpdb;

		$post_id = $this->watch_post();
		$this->set_retry( $post_id );
		$lock = Featured_Artwork::RETRY_LOCK_PREFIX . $post_id;
		$wpdb->insert( $wpdb->options, [ 'option_name' => $lock, 'option_value' => time() . ':fresh', 'autoload' => 'off' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		Featured_Artwork::run_retry( $post_id );
		$this->assertSame( 0, $this->http_requests );

		$wpdb->update( $wpdb->options, [ 'option_value' => ( time() - 301 ) . ':stale' ], [ 'option_name' => $lock ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$this->responses[] = 'failed';
		Featured_Artwork::run_retry( $post_id );

		$this->assertSame( 1, $this->http_requests );
		$this->assertSame( 2, $this->retry( $post_id )['attempts'] );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify release.
	}

	public function test_retry_refreshes_state_after_taking_the_lock(): void {
		global $wpdb;

		$post_id = $this->watch_post();
		$due     = $this->set_retry( $post_id );
		$this->assertSame( $due, $this->retry( $post_id ) );
		$future         = $due;
		$future['next'] = time() + 300;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulate another retry process changing C1 after this process primed its cache.
			$wpdb->postmeta,
			[ 'meta_value' => maybe_serialize( $future ) ],
			[ 'post_id' => $post_id, 'meta_key' => Featured_Artwork::RETRY_META ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Targeted test fixture update.
			[ '%s' ],
			[ '%d', '%s' ]
		);

		Featured_Artwork::run_retry( $post_id );

		$this->assertSame( 0, $this->http_requests );
		$this->assertSame( $future, get_post_meta( $post_id, Featured_Artwork::RETRY_META, true ) );
	}

	public function test_retry_rechecks_the_thumbnail_after_taking_the_lock(): void {
		global $wpdb;

		$post_id      = $this->watch_post();
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->set_retry( $post_id );
		$this->assertSame( 0, get_post_thumbnail_id( $post_id ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulate another process setting the thumbnail after this process primed its cache.
			$wpdb->postmeta,
			[ 'post_id' => $post_id, 'meta_key' => '_thumbnail_id', 'meta_value' => $attachment_id ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Targeted test fixture insert.
			[ '%d', '%s', '%d' ]
		);

		Featured_Artwork::run_retry( $post_id );

		$this->assertSame( 0, $this->http_requests );
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
	}

	public function test_duplicate_due_event_fetches_once_and_increments_once(): void {
		$post_id = $this->watch_post();
		$this->set_retry( $post_id );
		$this->responses[] = 'failed';

		do_action( Featured_Artwork::RETRY_HOOK, $post_id );
		do_action( Featured_Artwork::RETRY_HOOK, $post_id );

		$this->assertSame( 1, $this->http_requests );
		$this->assertSame( 2, $this->retry( $post_id )['attempts'] );
		$this->assertLessThanOrEqual( 1, $this->cron_count( $post_id ) );
		$this->assertCount(
			0,
			get_children(
				[
					'post_parent' => $post_id,
					'post_type'   => 'attachment',
				]
			)
		);
	}

	public function test_duplicate_due_event_success_fetches_once_and_creates_one_attachment(): void {
		$post_id          = $this->watch_post();
		$this->responses[] = 'success';
		$this->set_retry( $post_id );

		do_action( Featured_Artwork::RETRY_HOOK, $post_id );
		do_action( Featured_Artwork::RETRY_HOOK, $post_id );

		$this->assertSame( 1, $this->http_requests );
		$this->assertCount(
			1,
			get_children(
				[
					'post_parent' => $post_id,
					'post_type'   => 'attachment',
				]
			)
		);
		$this->assertFalse( metadata_exists( 'post', $post_id, Featured_Artwork::RETRY_META ) );
	}

	public function test_lock_is_released_when_fetch_throws(): void {
		global $wpdb;

		$post_id          = $this->watch_post();
		$this->responses[] = 'throw';
		$this->set_retry( $post_id );
		try {
			Featured_Artwork::run_retry( $post_id );
			$this->fail( 'Expected fixture exception.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'fixture exception', $error->getMessage() );
		}

		$lock = Featured_Artwork::RETRY_LOCK_PREFIX . $post_id;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify finally release.
	}

	/**
	 * @dataProvider non_sideload_setting_provider
	 */
	public function test_hotlink_and_none_save_and_backfill_fetch_through_the_guard_without_retry( string $setting ): void {
		update_option( 'pkiw_settings', [ 'image_handling' => $setting ] );
		$resolver_calls = 0;
		Artwork_Fetch_Guard::use_resolver(
			static function ( string $host ) use ( &$resolver_calls ): array {
				++$resolver_calls;
				return [ '93.184.216.34' ];
			}
		);
		$this->responses[] = 'success';
		$saved              = $this->watch_post( self::URL_1, true );
		$this->responses[]  = 'success';
		$backfilled         = $this->watch_post( self::URL_2 );

		( new Featured_Artwork() )->backfill();
		$this->responses[] = 'failed';
		$failed            = $this->watch_post( 'https://image.tmdb.org/t/p/w342/failed.jpg', true );

		$this->assertGreaterThan( 0, get_post_thumbnail_id( $saved ) );
		$this->assertGreaterThan( 0, get_post_thumbnail_id( $backfilled ) );
		$this->assertSame( 3, $resolver_calls );
		$this->assertSame( 3, $this->http_requests );
		$this->assertFalse( metadata_exists( 'post', $saved, Featured_Artwork::RETRY_META ) );
		$this->assertFalse( metadata_exists( 'post', $backfilled, Featured_Artwork::RETRY_META ) );
		$this->assertFalse( metadata_exists( 'post', $failed, Featured_Artwork::RETRY_META ) );
		$this->assertSame( 0, $this->cron_count( $saved ) );
		$this->assertSame( 0, $this->cron_count( $backfilled ) );
		$this->assertSame( 0, $this->cron_count( $failed ) );
	}

	public function test_clear_retry_returns_early_without_state_or_lock(): void {
		$post_id = $this->watch_post();
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Featured_Artwork::RETRY_HOOK, [ $post_id ] );

		Featured_Artwork::clear_retry( $post_id );

		$this->assertSame( 1, $this->cron_count( $post_id ) );
	}

	/** @return array<string,array{0:string}> */
	public static function non_sideload_setting_provider(): array {
		return [
			'hotlink' => [ 'hotlink' ],
			'none'    => [ 'none' ],
		];
	}
}

<?php
/** On This Day transient and invalidation coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
use PKIW\Abilities\On_This_Day_Abilities;
use PKIW\Public_Listing;
use PKIW\Taxonomy;
use PKIW\Title_Privacy;

/** @group integration */
final class OnThisDayCacheTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->today = new DateTimeImmutable( '2026-07-12 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
		On_This_Day::register_blocks();
		On_This_Day::reset();
		delete_transient( On_This_Day::transient_key( $this->today ) );
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		On_This_Day::reset();
		remove_all_filters( 'pkiw_on_this_day_kinds' );
		remove_all_filters( 'pkiw_otd_edge_purge' );
		On_This_Day::override_headers_for_tests( null );
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_post_made_private_disappears_on_next_request(): void {
		$id = $this->post( 'Private flip sentinel' );
		[ $viewed, $page ] = $this->surface_context();
		$before = $this->surface_snapshot( $viewed, $page );
		foreach ( $before as $surface => $output ) {
			$this->assertStringContainsString( 'Private flip sentinel', $output, $surface );
		}
		$this->assertIsArray( get_transient( On_This_Day::transient_key( $this->today ) ) );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		On_This_Day::flush_invalidation();
		$this->assertFalse( get_transient( On_This_Day::transient_key( $this->today ) ) );
		$after = $this->surface_snapshot( $viewed, $page );
		foreach ( $after as $surface => $output ) {
			$this->assertStringNotContainsString( 'Private flip sentinel', $output, $surface );
		}
	}

	/** @dataProvider hiding_event_provider */
	public function test_each_hiding_event_updates_every_surface_on_the_next_request( string $event ): void {
		$id = $this->post( 'Event surface sentinel ' . $event );
		[ $viewed, $page ] = $this->surface_context();
		$before = $this->surface_snapshot( $viewed, $page );
		foreach ( $before as $surface => $output ) {
			$this->assertStringContainsString( 'Event surface sentinel ' . $event, $output, $surface );
		}
		if ( 'trash' === $event ) {
			wp_trash_post( $id );
		} elseif ( 'password' === $event ) {
			wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] );
		} elseif ( 'kind-removed' === $event ) {
			wp_remove_object_terms( $id, 'note', Taxonomy::TAXONOMY );
			$without_none = static fn( array $kinds ): array => array_values( array_diff( $kinds, [ '__none__' ] ) );
			add_filter( 'pkiw_on_this_day_kinds', $without_none );
		} elseif ( 'date-moved' === $event ) {
			wp_update_post( [ 'ID' => $id, 'post_date' => '2025-07-13 23:00:00' ] );
		} elseif ( 'visibility-meta' === $event ) {
			update_post_meta( $id, Public_Listing::meta_keys()[0], 'quiet_public' );
		} else {
			add_filter( 'pkiw_on_this_day_kinds', static fn(): array => [ '__none__' ] );
		}
		On_This_Day::flush_invalidation();
		if ( 'filter-change' !== $event ) {
			$this->assertFalse( get_transient( On_This_Day::transient_key( $this->today ) ), $event );
		}
		$after = $this->surface_snapshot( $viewed, $page );
		foreach ( $after as $surface => $output ) {
			$this->assertStringNotContainsString( 'Event surface sentinel ' . $event, $output, $surface );
		}
	}

	public static function hiding_event_provider(): array {
		return [
			'trash' => [ 'trash' ],
			'password' => [ 'password' ],
			'kind removed' => [ 'kind-removed' ],
			'date moved' => [ 'date-moved' ],
			'visibility meta' => [ 'visibility-meta' ],
			'kind filter hash' => [ 'filter-change' ],
		];
	}

	/** @dataProvider non_hiding_event_provider */
	public function test_non_hiding_c7_event_rebuilds_every_surface_without_dropping_control( string $event ): void {
		$other = $this->post( 'Other event post ' . $event );
		$id = $this->post( 'Non-hiding event sentinel ' . $event );
		[ $viewed, $page ] = $this->surface_context();
		$before = $this->surface_snapshot( $viewed, $page );
		foreach ( $before as $surface => $output ) {
			$this->assertStringContainsString( 'Non-hiding event sentinel ' . $event, $output, $surface );
		}
		if ( 'different-post-meta' === $event ) {
			update_post_meta( $other, Public_Listing::meta_keys()[0], 'quiet_public' );
		} elseif ( 'thumbnail' === $event ) {
			update_post_meta( $id, '_thumbnail_id', 999999 );
			On_This_Day::flush_invalidation();
			On_This_Day::served_rows( $this->today );
			delete_post_meta( $id, '_thumbnail_id' );
		} elseif ( 'title-meta' === $event ) {
			update_post_meta( $id, Title_Privacy::deciding_meta()[0], 'private' );
		} elseif ( 'timezone' === $event ) {
			update_option( 'timezone_string', 'America/New_York' );
		} elseif ( 'gmt-offset' === $event ) {
			update_option( 'timezone_string', '' );
			update_option( 'gmt_offset', 5.5 );
		} elseif ( 'kind-renamed' === $event ) {
			$term = get_term_by( 'slug', 'note', Taxonomy::TAXONOMY );
			$this->assertInstanceOf( WP_Term::class, $term );
			wp_update_term( $term->term_id, Taxonomy::TAXONOMY, [ 'name' => 'Note renamed for cache test' ] );
		} elseif ( 'kind-deleted' === $event ) {
			$term = get_term_by( 'slug', 'note', Taxonomy::TAXONOMY );
			$this->assertInstanceOf( WP_Term::class, $term );
			wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );
		} else {
			wp_remove_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		}
		On_This_Day::flush_invalidation();
		$this->assertFalse( get_transient( On_This_Day::transient_key( $this->today ) ), $event );
		$after = $this->surface_snapshot( $viewed, $page );
		foreach ( $after as $surface => $output ) {
			$this->assertStringContainsString( 'Non-hiding event sentinel ' . $event, $output, $surface );
		}
	}

	public static function non_hiding_event_provider(): array {
		return [
			'visibility meta on another post' => [ 'different-post-meta' ],
			'thumbnail removal' => [ 'thumbnail' ],
			'title privacy meta' => [ 'title-meta' ],
			'timezone option' => [ 'timezone' ],
			'gmt offset option' => [ 'gmt-offset' ],
			'kind term rename' => [ 'kind-renamed' ],
			'kind term delete' => [ 'kind-deleted' ],
			'kind relationship removal' => [ 'kind-removed-default-none' ],
		];
	}

	public function test_all_display_deciding_events_delete_the_primed_transient(): void {
		$id = $this->post( 'Events sentinel' );
		$events = [
			'password' => static fn() => wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] ),
			'date' => static fn() => wp_update_post( [ 'ID' => $id, 'post_date' => '2025-07-13 10:00:00' ] ),
			'visibility' => static fn() => update_post_meta( $id, Public_Listing::meta_keys()[0], 'quiet_public' ),
			'thumbnail' => static fn() => delete_post_meta( $id, '_thumbnail_id' ),
			'title-meta' => static fn() => update_post_meta( $id, Title_Privacy::deciding_meta()[0], 'changed' ),
		];
		foreach ( $events as $name => $event ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'publish', 'post_password' => '', 'post_date' => '2025-07-12 10:00:00' ] );
			delete_post_meta( $id, Public_Listing::meta_keys()[0] );
			if ( 'thumbnail' === $name ) {
				update_post_meta( $id, '_thumbnail_id', 999999 );
			}
			On_This_Day::served_rows( $this->today );
			$event();
			On_This_Day::flush_invalidation();
			$this->assertFalse( get_transient( On_This_Day::transient_key( $this->today ) ), $name );
		}
	}

	public function test_kind_hash_corruption_and_hidden_cached_ids_are_misses_or_filtered(): void {
		$id = $this->post( 'Cache shape sentinel' );
		$key = On_This_Day::transient_key( $this->today );
		On_This_Day::served_rows( $this->today );
		$cached = get_transient( $key );
		$this->assertSame( [ 'kinds', 'rows' ], array_keys( $cached ) );
		$this->assertSame( [ 'id', 'year', 'ts' ], array_keys( $cached['rows'][0] ) );
		$this->assertStringNotContainsString( 'Cache shape sentinel', wp_json_encode( $cached ) );

		set_transient( $key, [ 'kinds' => 'wrong', 'rows' => $cached['rows'] ], HOUR_IN_SECONDS );
		$this->assertSame( [ $id ], array_column( On_This_Day::served_rows( $this->today ), 'id' ) );
		update_post_meta( $id, Public_Listing::meta_keys()[0], 'quiet_public' );
		set_transient( $key, $cached, HOUR_IN_SECONDS );
		$this->assertSame( [], On_This_Day::served_rows( $this->today ), 'Every cached ID must pass the fresh row_visible() check.' );
	}

	public function test_trash_password_kind_removal_and_a_different_posts_visibility_flush_today(): void {
		$id = $this->post( 'Mutation sentinel' );
		$other = $this->post( 'Different post sentinel' );
		$key = On_This_Day::transient_key( $this->today );
		$mutations = [
			'trash' => static fn() => wp_trash_post( $id ),
			'password' => static fn() => wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] ),
			'kind' => static fn() => wp_remove_object_terms( $id, 'note', Taxonomy::TAXONOMY ),
			'different-post-meta' => static fn() => update_post_meta( $other, Public_Listing::meta_keys()[0], 'quiet_public' ),
		];
		foreach ( $mutations as $name => $mutation ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'publish', 'post_password' => '' ] );
			wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
			delete_post_meta( $other, Public_Listing::meta_keys()[0] );
			On_This_Day::served_rows( $this->today );
			$mutation();
			On_This_Day::flush_invalidation();
			$this->assertFalse( get_transient( $key ), $name );
		}
	}

	public function test_kind_filter_timezone_and_term_rules_cannot_reuse_the_old_hash(): void {
		$id = $this->post( 'Rule sentinel' );
		$key = On_This_Day::transient_key( $this->today );
		On_This_Day::served_rows( $this->today );
		$before = get_transient( $key );
		$notes_only = static fn(): array => [ '__none__' ];
		add_filter( 'pkiw_on_this_day_kinds', $notes_only );
		$this->assertSame( [], On_This_Day::served_rows( $this->today ) );
		$this->assertNotSame( $before['kinds'], get_transient( $key )['kinds'] );
		remove_filter( 'pkiw_on_this_day_kinds', $notes_only );

		On_This_Day::served_rows( $this->today );
		$old_timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Asia/Kolkata' );
		On_This_Day::flush_invalidation();
		$this->assertFalse( get_transient( $key ) );
		update_option( 'timezone_string', $old_timezone );

		On_This_Day::served_rows( $this->today );
		$term = get_term_by( 'slug', 'note', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		wp_update_term( $term->term_id, Taxonomy::TAXONOMY, [ 'name' => 'Notes renamed' ] );
		On_This_Day::flush_invalidation();
		$this->assertFalse( get_transient( $key ) );
		On_This_Day::served_rows( $this->today );
		wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );
		On_This_Day::flush_invalidation();
		$this->assertFalse( get_transient( $key ), 'Deleting an opted-in kind must flush the day.' );
	}

	public function test_fifty_updates_collapse_to_one_edge_purge_call(): void {
		if ( ! function_exists( 'pantheon_wp_clear_edge_paths' ) || ! function_exists( 'pantheon_wp_clear_edge_keys' ) ) {
			$this->markTestSkipped( 'Pantheon test doubles load with OnThisDayHeadersTest.' );
		}
		$id = $this->post( 'Burst sentinel' );
		$post = get_post( $id );
		$GLOBALS['pkiw_otd_test_path_purges'] = [];
		$GLOBALS['pkiw_otd_test_key_purges'] = [];
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		for ( $i = 0; $i < 50; $i++ ) {
			On_This_Day::post_updated( $id, $post, $post );
		}
		On_This_Day::flush_invalidation();
		$this->assertCount( 1, $GLOBALS['pkiw_otd_test_path_purges'] );
		$this->assertCount( 1, $GLOBALS['pkiw_otd_test_key_purges'] );
		remove_filter( 'pkiw_otd_edge_purge', '__return_true' );
	}

	public function test_keys_ttl_midnight_and_non_today_reads_are_site_local(): void {
		$this->assertSame( 'pkiw_otd_2026-07-12', On_This_Day::transient_key( $this->today ) );
		$this->assertSame( 30, On_This_Day::seconds_until_midnight( new DateTimeImmutable( '2026-07-12 23:59:30', wp_timezone() ) ) );
		$this->assertSame( 86400, On_This_Day::seconds_until_midnight( new DateTimeImmutable( '2026-07-12 00:00:00', wp_timezone() ) ) );
		$past = $this->today->modify( '-30 days' );
		On_This_Day::served_rows( $past );
		$this->assertFalse( get_transient( On_This_Day::transient_key( $past ) ), 'Non-today reads must remain uncached.' );
	}

	public function test_database_transient_timeout_matches_seconds_until_midnight(): void {
		$this->assertFalse( (bool) wp_using_ext_object_cache(), 'This assertion reads the database transient timeout option.' );
		$this->post( 'TTL sentinel' );
		$key = On_This_Day::transient_key( $this->today );
		On_This_Day::served_rows( $this->today );
		$remaining = (int) get_option( '_transient_timeout_' . $key ) - time();
		$this->assertEqualsWithDelta( On_This_Day::seconds_until_midnight(), $remaining, 2 );
	}

	public function test_midnight_rollover_uses_a_new_key_and_never_the_previous_days_rows(): void {
		$first = $this->post( 'Before midnight sentinel' );
		$second = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'After midnight sentinel', 'post_date' => '2025-07-13 10:00:00' ] );
		wp_set_object_terms( $second, 'note', Taxonomy::TAXONOMY );
		$before = new DateTimeImmutable( '2026-07-12 23:59:58', wp_timezone() );
		On_This_Day::override_now( $before );
		$key_before = On_This_Day::transient_key( $before );
		$this->assertSame( [ $first ], array_column( On_This_Day::served_rows( $before ), 'id' ) );

		$after = new DateTimeImmutable( '2026-07-13 00:00:01', wp_timezone() );
		On_This_Day::override_now( $after );
		$key_after = On_This_Day::transient_key( $after );
		$this->assertNotSame( $key_before, $key_after );
		$this->assertSame( [ $second ], array_column( On_This_Day::served_rows( $after ), 'id' ) );
		$this->assertNotContains( $first, array_column( On_This_Day::served_rows( $after ), 'id' ) );
	}

	public function test_thirty_past_ability_dates_create_no_date_transients(): void {
		global $wpdb;
		On_This_Day::served_rows( $this->today );
		for ( $days = 1; $days <= 30; $days++ ) {
			$result = On_This_Day_Abilities::instance()->execute( [ 'date' => $this->today->modify( '-' . $days . ' days' )->format( 'Y-m-d' ) ] );
			$this->assertIsArray( $result );
		}
		$like = $wpdb->esc_like( '_transient_pkiw_otd_' ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		$this->assertSame( [ '_transient_' . On_This_Day::transient_key( $this->today ) ], array_values( $names ) );
	}

	public function test_cached_value_contains_no_title_url_or_meta_key_strings(): void {
		$id = $this->post( 'Cache private-data sentinel' );
		$cached = get_transient( On_This_Day::transient_key( $this->today ) );
		if ( false === $cached ) {
			On_This_Day::served_rows( $this->today );
			$cached = get_transient( On_This_Day::transient_key( $this->today ) );
		}
		$json = (string) wp_json_encode( $cached );
		$this->assertStringNotContainsString( get_the_title( $id ), $json );
		$this->assertStringNotContainsString( (string) get_permalink( $id ), $json );
		foreach ( Public_Listing::meta_keys() as $meta_key ) {
			$this->assertStringNotContainsString( $meta_key, $json );
		}
	}

	public function test_midnight_event_drops_yesterday_and_schedules_the_next_local_midnight(): void {
		$now = new DateTimeImmutable( '2026-07-12 00:00:05', wp_timezone() );
		On_This_Day::override_now( $now );
		set_transient( 'pkiw_otd_2026-07-11', [ 'kinds' => 'x', 'rows' => [] ], HOUR_IN_SECONDS );
		wp_clear_scheduled_hook( On_This_Day::CRON_HOOK );
		On_This_Day::midnight();
		$this->assertFalse( get_transient( 'pkiw_otd_2026-07-11' ) );
		$next = wp_next_scheduled( On_This_Day::CRON_HOOK );
		$this->assertSame( ( new DateTimeImmutable( '2026-07-13 00:00:05', wp_timezone() ) )->getTimestamp(), $next );
		wp_clear_scheduled_hook( On_This_Day::CRON_HOOK );
	}

	public function test_no_option_is_read_or_written_for_kinds(): void {
		$this->assertFalse( get_option( 'pkiw_on_this_day_kinds', false ) );
		On_This_Day::kinds();
		$this->assertFalse( get_option( 'pkiw_on_this_day_kinds', false ) );
	}

	private function post( string $title ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2025-07-12 23:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		return $id;
	}

	private function surface_context(): array {
		$viewed = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Viewed surface post', 'post_date' => '2026-07-12 12:00:00' ] );
		wp_set_object_terms( $viewed, 'note', Taxonomy::TAXONOMY );
		self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Current stream filler', 'post_date' => '2026-07-11 12:00:00' ] );
		$content = '<!-- wp:post-kinds-indieweb/on-this-day {"context":"stream"} /-->'
			. '<!-- wp:query {"queryId":1,"query":{"perPage":1,"postType":"post","order":"desc","orderBy":"date","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cache Stream proof', 'post_content' => $content ] );
		return [ $viewed, $page ];
	}

	private function surface_snapshot( int $viewed, int $page ): array {
		$GLOBALS['wp_query'] = new WP_Query( On_This_Day::query_args( $this->today, [ 'pkiw_group_source' => On_This_Day::SOURCE, 'pkiw_group_order' => 'DESC', 'posts_per_page' => 20 ] ) );
		$markup = '<!-- wp:query {"queryId":7,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:' . On_This_Day::ENTRY_BLOCK . ' /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$archive = do_blocks( $markup );

		$this->go_to( get_permalink( $viewed ) );
		add_filter( 'pkiw_otd_edge_purge', '__return_true' );
		$single = On_This_Day::render_block( [ 'context' => 'single' ] );
		remove_filter( 'pkiw_otd_edge_purge', '__return_true' );

		$this->go_to( get_permalink( $page ) );
		$GLOBALS['post'] = get_post( $page );
		setup_postdata( $GLOBALS['post'] );
		On_This_Day::override_headers_for_tests( static function (): void {} );
		On_This_Day::send_day_headers();
		$stream = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $page ) );
		$ability = (string) wp_json_encode( On_This_Day_Abilities::instance()->execute() );
		return compact( 'archive', 'single', 'stream', 'ability' );
	}
}

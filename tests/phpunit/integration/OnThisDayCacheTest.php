<?php
/** On This Day transient and invalidation coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
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
		On_This_Day::reset();
		delete_transient( On_This_Day::transient_key( $this->today ) );
	}

	public function tear_down(): void {
		On_This_Day::override_now( null );
		On_This_Day::reset();
		remove_all_filters( 'pkiw_on_this_day_kinds' );
		parent::tear_down();
	}

	public function test_post_made_private_disappears_on_next_request(): void {
		$id = $this->post( 'Private flip sentinel' );
		$this->assertSame( [ $id ], array_column( On_This_Day::served_rows( $this->today ), 'id' ) );
		$this->assertIsArray( get_transient( On_This_Day::transient_key( $this->today ) ) );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		On_This_Day::flush_invalidation();
		$this->assertFalse( get_transient( On_This_Day::transient_key( $this->today ) ) );
		$this->assertSame( [], On_This_Day::served_rows( $this->today ) );
		$this->assertSame( '', On_This_Day::item_html( get_post( $id ), true, 'note' ) );
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

	public function test_no_option_is_read_or_written_for_kinds(): void {
		$this->assertFalse( get_option( 'pkiw_on_this_day_kinds', false ) );
		On_This_Day::kinds();
		$this->assertFalse( get_option( 'pkiw_on_this_day_kinds', false ) );
	}

	private function post( string $title ): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => '2025-07-12 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		return $id;
	}
}

<?php
/** Wrapped cache and invalidation coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Public_Listing;
use PKIW\Taxonomy;
use PKIW\Wrapped_Stats;

/** Pantheon cache test double for X-P267. */
final class X267_Pantheon_Cache {
	/** @var string[] */
	public array $urls = [];
	/** @var array<int, array|string> */
	public array $regex = [];

	/** Record URL purges. */
	public function enqueue_urls( array $urls ): void {
		$this->urls = array_merge( $this->urls, $urls );
	}

	/** Record regex purges. */
	public function enqueue_regex( array|string $regex ): void {
		$this->regex[] = $regex;
	}
}

// PHPUnit loads every test file, so this and PublicEligibilityTest's double must read the same global.
if ( ! function_exists( 'Pantheon_Cache' ) ) {
	/** Return the shared Pantheon cache test double. */
	function Pantheon_Cache(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Pantheon API test double.
		$GLOBALS['pkiw_test_pantheon_cache'] ??= new X267_Pantheon_Cache();
		return $GLOBALS['pkiw_test_pantheon_cache'];
	}
}

/** @group integration */
final class WrappedCacheTest extends WrappedTestCase {
	public function set_up(): void {
		parent::set_up();
		$GLOBALS['pkiw_test_pantheon_cache'] = new X267_Pantheon_Cache();
	}

	public function test_a_every_tracked_event_changes_generation_stamp(): void {
		$id = $this->read_post();
		foreach ( Wrapped_Stats::meta_keys() as $key ) {
			$this->assertStampChanges(
				static function () use ( $id, $key ): void {
					update_post_meta( $id, $key, 'x-' . wp_generate_uuid4() );
				},
				'meta ' . $key
			);
		}
		$this->assertStampChanges( static fn() => wp_set_object_terms( $id, 'watch', Taxonomy::TAXONOMY ), 'kind replacement' );
		$this->assertStampChanges( static fn() => wp_set_object_terms( $id, 'read', Taxonomy::TAXONOMY, true ), 'kind addition' );
		$this->assertStampChanges( static fn() => wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] ), 'password set' );
		$this->assertStampChanges( static fn() => wp_update_post( [ 'ID' => $id, 'post_password' => '' ] ), 'password cleared' );
		$type = $this->read_post();
		$this->assertStampChanges( static fn() => wp_update_post( [ 'ID' => $type, 'post_type' => 'page' ] ), 'post type leaves post' );
		$this->assertStampChanges( static fn() => wp_update_post( [ 'ID' => $id, 'post_date' => '2025-06-15 12:00:00' ] ), 'date out' );
		$this->assertStampChanges( static fn() => wp_update_post( [ 'ID' => $id, 'post_date' => '2026-06-15 12:00:00' ] ), 'date in' );
		$this->assertStampChanges( static fn() => wp_trash_post( $id ), 'trash' );
		$this->assertStampChanges( static fn() => wp_untrash_post( $id ), 'untrash' );
		$this->assertStampChanges( static fn() => update_option( 'timezone_string', 'America/New_York' ), 'timezone' );
		$this->assertStampChanges( static fn() => update_option( 'gmt_offset', -5 ), 'offset' );
		$this->assertStampChanges( static fn() => delete_post_meta_by_key( '_pkiw_read_rating' ), 'delete all tracked meta' );
		$delete = $this->read_post();
		$stamps = [];
		foreach ( [ 'before_delete_post', 'deleted_post', 'after_delete_post' ] as $hook ) {
			add_action( $hook, static function () use ( &$stamps, $hook ): void {
				$stamps[ $hook ] = (string) get_option( 'pkiw_wrapped_gen' );
			}, 100 );
		}
		wp_delete_post( $delete, true );
		$this->assertCount( 3, array_unique( $stamps ), 'Each permanent-delete phase writes a different stamp.' );
	}

	public function test_b_private_flip_lowers_count_without_manual_transient_delete(): void {
		$id = $this->read_post();
		Wrapped_Stats::bump();
		$this->assertSame( 1, $this->metric( Wrapped_Stats::for_kind_year( 'read', 2026 ), 'finished' )['value'] );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		$this->assertSame( 0, $this->metric( Wrapped_Stats::for_kind_year( 'read', 2026 ), 'finished' )['value'] );
	}

	public function test_c_single_key_and_option_cache_eviction_never_restore_old_generation(): void {
		$id_2025 = $this->read_post( 2025 );
		$this->read_post( 2026 );
		Wrapped_Stats::bump();
		$gen = (string) get_option( 'pkiw_wrapped_gen' );
		Wrapped_Stats::for_kind_year( 'read', 2025 );
		Wrapped_Stats::for_kind_year( 'read', 2026 );
		delete_transient( Wrapped_Stats::transient_key( 'read', 2026, $gen ) );
		$this->assertSame( Wrapped_Stats::compute( 'read', 2026 ), $this->withoutGen( Wrapped_Stats::for_kind_year( 'read', 2026 ) ) );
		$this->assertSame( Wrapped_Stats::compute( 'read', 2025 ), $this->withoutGen( Wrapped_Stats::for_kind_year( 'read', 2025 ) ) );
		$stale = $this->cacheShape( Wrapped_Stats::compute( 'read', 2025 ), $gen );
		wp_update_post( [ 'ID' => $id_2025, 'post_status' => 'private' ] );
		$new_gen = (string) get_option( 'pkiw_wrapped_gen' );
		set_transient( Wrapped_Stats::transient_key( 'read', 2025, $gen ), $stale, DAY_IN_SECONDS );
		wp_cache_delete( 'pkiw_wrapped_gen', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertNotSame( $gen, $new_gen );
		$this->assertSame( 0, $this->metric( Wrapped_Stats::for_kind_year( 'read', 2025 ), 'finished' )['value'] );
		$this->assertArrayNotHasKey( 'pkiw_wrapped_gen', wp_load_alloptions() );
	}

	public function test_d_generation_bump_during_compute_stores_only_under_old_key(): void {
		$this->read_post();
		Wrapped_Stats::bump();
		$old_gen = (string) get_option( 'pkiw_wrapped_gen' );
		$fired   = false;
		$mid = static function () use ( &$fired ): void {
			if ( ! $fired ) {
				$fired = true;
				Wrapped_Stats::bump();
			}
		};
		add_action( 'pre_get_posts', $mid );
		Wrapped_Stats::for_kind_year( 'read', 2026 );
		remove_action( 'pre_get_posts', $mid );
		$new_gen = (string) get_option( 'pkiw_wrapped_gen' );
		$this->assertNotSame( $old_gen, $new_gen );
		$this->assertNotFalse( get_transient( Wrapped_Stats::transient_key( 'read', 2026, $old_gen ) ) );
		$this->assertFalse( get_transient( Wrapped_Stats::transient_key( 'read', 2026, $new_gen ) ) );
		Wrapped_Stats::for_kind_year( 'read', 2026 );
		$this->assertNotFalse( get_transient( Wrapped_Stats::transient_key( 'read', 2026, $new_gen ) ) );
	}

	public function test_e_save_hook_runs_after_card_sync_and_surface_and_never_uses_save_post_post(): void {
		$this->assertSame( 99, has_action( 'save_post', [ Wrapped_Stats::class, 'on_save' ] ) );
		$this->assertFalse( has_action( 'save_post_post', [ Wrapped_Stats::class, 'on_save' ] ) );
		// Both register as [ $this, method ], so has_action() with a class string can't see them.
		$this->assertSame( 25, $this->instance_priority( 'save_post', PKIW\Card_Meta_Sync::class, 'sync' ) );
		$this->assertSame( 20, $this->instance_priority( 'save_post', PKIW\Post_Surface::class, 'on_save' ) );
		$order   = [];
		$current = '';
		$surface = static function () use ( &$order, &$current ): void {
			if ( '' !== $current ) {
				$order[ $current ][] = 'surface';
			}
		};
		$card = static function () use ( &$order, &$current ): void {
			if ( '' !== $current ) {
				$order[ $current ][] = 'card';
			}
		};
		$wrapped = static function () use ( &$order, &$current ): void {
			if ( '' !== $current ) {
				$order[ $current ][] = 'wrapped';
			}
		};
		add_action( 'save_post', $surface, 20 );
		add_action( 'save_post', $card, 25 );
		add_action( 'save_post', $wrapped, 100 );
		$current = 'wp_insert_post';
		$this->read_post();
		$current = '';
		$rest_id = $this->read_post();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$current = 'REST update';
		$request = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $rest_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'title' => 'Hook order REST' ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$current = '';
		$publish = $this->read_post( 2026, [], [ 'post_status' => 'draft' ] );
		$current = 'wp_publish_post';
		wp_publish_post( $publish );
		remove_action( 'save_post', $surface, 20 );
		remove_action( 'save_post', $card, 25 );
		remove_action( 'save_post', $wrapped, 100 );
		foreach ( [ 'wp_insert_post', 'REST update', 'wp_publish_post' ] as $case ) {
			$actual = $order[ $case ] ?? [];
			$this->assertNotSame( [], $actual, $case . ': no save_post callbacks were recorded; actual=' . print_r( $actual, true ) );
			$this->assertSame( 0, count( $actual ) % 3, $case . ': callback count must contain complete surface/card/wrapped groups; actual=' . print_r( $actual, true ) );
			foreach ( array_chunk( $actual, 3 ) as $sequence ) {
				$this->assertSame( [ 'surface', 'card', 'wrapped' ], $sequence, $case . ': each save_post pass must preserve hook order; actual=' . print_r( $actual, true ) );
			}
		}
	}

	public function test_f_gap_payloads_miss_after_rest_exercise_bulk_cron_and_delete_writes(): void {
		register_post_meta( 'post', 'activitypub_content_visibility', [ 'type' => 'string', 'single' => true, 'show_in_rest' => true ] );
		$rest_id = $this->read_post();
		$this->seedAtHook( 'wp_insert_post', 100, static fn( int $id ): bool => $rest_id === $id );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $rest_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'title' => 'REST write', 'meta' => [ 'activitypub_content_visibility' => 'quiet_public' ] ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( 0, $this->metric( Wrapped_Stats::for_kind_year( 'read', 2026 ), 'finished' )['value'] );

		$save_id = $this->read_post();
		$this->seedAtHook( 'save_post', 50, static fn( int $id ): bool => $save_id === $id );
		$request = new WP_REST_Request( 'PUT', '/wp/v2/posts/' . $save_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'title' => 'REST save gap', 'meta' => [ 'activitypub_content_visibility' => 'quiet_public' ] ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( Wrapped_Stats::compute( 'read', 2026 ), $this->withoutGen( Wrapped_Stats::for_kind_year( 'read', 2026 ) ), 'save_post 50 payload must miss' );

		for ( $index = 0; $index < 3; ++$index ) {
			$exercise = $this->kind_post( 'exercise', 2026, [ 'exercise_duration' => 'PT1H', 'exercise_details_public' => '1' ] );
		}
		$this->seedAtHook( 'wp_after_insert_post', 100, static fn( int $id ): bool => $exercise === $id, 'exercise' );
		wp_update_post( [ 'ID' => $exercise, 'post_title' => 'Seed point' ] );
		update_post_meta( $exercise, '_pkiw_exercise_details_public', '0' );
		$this->assertNull( $this->metric( Wrapped_Stats::for_kind_year( 'exercise', 2026 ), 'duration' ) );

		$bulk = [ $this->read_post(), $this->read_post() ];
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$this->seedAtHook( 'wp_after_insert_post', 100, static fn( int $id ): bool => $bulk[0] === $id );
		bulk_edit_posts( [ 'post' => $bulk, '_status' => 'private' ] );
		$this->assertSame( Wrapped_Stats::compute( 'read', 2026 ), $this->withoutGen( Wrapped_Stats::for_kind_year( 'read', 2026 ) ) );

		$cron_ids = [ $this->read_post(), $this->read_post(), $this->read_post() ];
		$this->seedAtHook( 'wp_after_insert_post', 100, static fn( int $id ): bool => $cron_ids[0] === $id );
		add_filter( 'wp_doing_cron', '__return_true' );
		foreach ( $cron_ids as $id ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'private' ] );
		}
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( Wrapped_Stats::compute( 'read', 2026 ), $this->withoutGen( Wrapped_Stats::for_kind_year( 'read', 2026 ) ) );

		foreach ( [ 'before_delete_post', 'deleted_post' ] as $hook ) {
			$id = $this->read_post();
			$this->seedAtHook( $hook, 100, static fn( int $seen ): bool => $id === $seen );
			wp_delete_post( $id, true );
			$this->assertSame( Wrapped_Stats::compute( 'read', 2026 ), $this->withoutGen( Wrapped_Stats::for_kind_year( 'read', 2026 ) ), $hook );
		}
	}

	public function test_f_stamp_per_write_and_side_effects_dedupe_only_at_shutdown(): void {
		$id       = $this->read_post();
		$updates  = 0;
		$stamps   = [];
		$changed  = [];
		$option = static function () use ( &$updates ): void {
			++$updates;
		};
		$event = static function () use ( &$stamps ): void {
			$stamps[] = (string) get_option( 'pkiw_wrapped_gen' );
		};
		$notice = static function ( string $kind, array $years ) use ( &$changed ): void {
			$changed[] = [ $kind, $years ];
		};
		add_action( 'update_option_pkiw_wrapped_gen', $option );
		$probes = [
			'transition_post_status' => static function ( string $new, string $old, WP_Post $post ) use ( $event ): void {
				if ( 'post' === $post->post_type && $new !== $old ) {
					$event();
				}
			},
			'save_post'              => static function ( int $post_id, WP_Post $post ) use ( $event ): void {
				if ( 'post' === $post->post_type && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
					$event();
				}
			},
			'post_updated'           => static function ( int $post_id, WP_Post $after, WP_Post $before ) use ( $event ): void {
				if ( 'post' === $after->post_type || 'post' === $before->post_type ) {
					$event();
				}
			},
			'wp_after_insert_post'   => static function ( int $post_id, WP_Post $post ) use ( $event ): void {
				if ( 'post' === $post->post_type && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
					$event();
				}
			},
			'updated_post_meta'      => static function ( $meta_id, int $post_id, string $key ) use ( $event ): void {
				if ( 'post' === get_post_type( $post_id ) && in_array( $key, Wrapped_Stats::meta_keys(), true ) ) {
					$event();
				}
			},
			'set_object_terms'       => static function ( int $post_id, $terms, array $tt_ids, string $taxonomy ) use ( $event ): void {
				if ( Taxonomy::TAXONOMY === $taxonomy && 'post' === get_post_type( $post_id ) ) {
					$event();
				}
			},
			'deleted_term_relationships' => static function ( int $post_id, array $tt_ids, string $taxonomy ) use ( $event ): void {
				if ( Taxonomy::TAXONOMY === $taxonomy && 'post' === get_post_type( $post_id ) ) {
					$event();
				}
			},
		];
		foreach ( $probes as $hook => $probe ) {
			add_action( $hook, $probe, 100, 6 );
		}
		add_action( 'pkiw_wrapped_numbers_changed', $notice, 10, 2 );
		// core applies tax_input only for a user who can assign the terms (post.php:5012).
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'private', 'meta_input' => [ '_pkiw_read_rating' => 5 ], 'tax_input' => [ 'kind' => [ 'watch' ] ] ] );
		$this->assertSame( [ 'watch' ], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ), 'the kind term leg must have run' );
		$this->assertGreaterThan( 1, $updates );
		$this->assertSame( $updates, count( $stamps ), 'The option-write count must equal the tracked-event count; stamps=' . print_r( $stamps, true ) );
		$this->assertSame( count( $stamps ), count( array_unique( $stamps ) ), 'Every tracked event must expose a unique option value; stamps=' . print_r( $stamps, true ) );
		$this->run_shutdown();
		$this->run_shutdown();
		$this->assertCount( 1, array_filter( $changed, static fn( array $row ): bool => 'read' === $row[0] ) );
		$this->assertSame( count( array_unique( $GLOBALS['pkiw_test_pantheon_cache']->urls ) ), count( $GLOBALS['pkiw_test_pantheon_cache']->urls ) );
		$this->assertSame( [ '^/stream/page/' ], $GLOBALS['pkiw_test_pantheon_cache']->regex );
		remove_action( 'update_option_pkiw_wrapped_gen', $option );
		remove_action( 'pkiw_wrapped_numbers_changed', $notice, 10 );
		foreach ( $probes as $hook => $probe ) {
			remove_action( $hook, $probe, 100 );
		}

		$changed = [];
		add_action( 'pkiw_wrapped_numbers_changed', $notice, 10, 2 );
		$add_only = $this->read_post();
		// The fixture swaps core's default `note` for `read`, a real removal; flush that first.
		$this->run_shutdown();
		$changed = [];
		wp_set_object_terms( $add_only, 'watch', Taxonomy::TAXONOMY, true );
		$this->run_shutdown();
		$this->assertSame( [], $changed, 'Adding a kind without removing one is not a leaving side effect.' );
		remove_action( 'pkiw_wrapped_numbers_changed', $notice, 10 );
	}

	public function test_g_purge_includes_every_published_wrapped_permalink_and_no_extra_feeds(): void {
		$first  = $this->wrapped_post( 'read', 2025 );
		$second = $this->wrapped_post( 'read', 2026 );
		$id     = $this->read_post();
		wp_update_post( [ 'ID' => $id, 'post_password' => 'secret' ] );
		$this->run_shutdown();
		$urls = $GLOBALS['pkiw_test_pantheon_cache']->urls;
		foreach ( [ home_url( '/' ), home_url( '/stream/' ), home_url( '/feed/' ), home_url( '/kind/read/' ), get_permalink( $first ), get_permalink( $second ) ] as $url ) {
			$this->assertContains( $url, $urls, $url );
		}
		$this->assertNotContains( home_url( '/comments/feed/' ), $urls );
		$this->assertNotContains( home_url( '/kind/read/feed/' ), $urls );

		$GLOBALS['pkiw_test_pantheon_cache'] = new X267_Pantheon_Cache();
		for ( $index = 0; $index < 3; ++$index ) {
			$exercise = $this->kind_post( 'exercise', 2026, [ 'exercise_duration' => 'PT1H', 'exercise_details_public' => '1' ] );
		}
		update_post_meta( $exercise, '_pkiw_exercise_details_public', '0' );
		$this->run_shutdown();
		$this->assertContains( home_url( '/kind/exercise/' ), $GLOBALS['pkiw_test_pantheon_cache']->urls );

		$GLOBALS['pkiw_test_pantheon_cache'] = new X267_Pantheon_Cache();
		$type = $this->read_post();
		wp_update_post( [ 'ID' => $type, 'post_type' => 'page' ] );
		$this->run_shutdown();
		$this->assertContains( home_url( '/kind/read/' ), $GLOBALS['pkiw_test_pantheon_cache']->urls );
	}

	public function test_g_counted_post_that_becomes_a_wrapped_host_is_purged(): void {
		$year = (int) wp_date( 'Y' ) - 1;
		$id   = $this->read_post( $year );
		$this->run_shutdown();
		$GLOBALS['pkiw_test_pantheon_cache'] = new X267_Pantheon_Cache();
		wp_update_post( [ 'ID' => $id, 'post_content' => '<!-- wp:post-kinds-indieweb/wrapped {"kind":"read","year":' . $year . '} /-->' ] );
		$this->assertSame( [], wp_get_post_terms( $id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] ) );
		$this->run_shutdown();
		$this->assertContains( home_url( '/kind/read/' ), $GLOBALS['pkiw_test_pantheon_cache']->urls, 'removing a real kind from a new Wrapped host leaves that kind' );
	}

	public function test_h_stale_cached_top_row_triggers_bump_and_recompute(): void {
		global $wpdb;
		$id = $this->read_post();
		Wrapped_Stats::bump();
		Wrapped_Stats::for_kind_year( 'read', 2026 );
		$before = (string) get_option( 'pkiw_wrapped_gen' );
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'private' ], [ 'ID' => $id ] );
		clean_post_cache( $id );
		$data  = Wrapped_Stats::for_kind_year( 'read', 2026 );
		$after = (string) get_option( 'pkiw_wrapped_gen' );
		$this->assertNotSame( $before, $after );
		$this->assertSame( [], $data['top'] );
		$this->assertSame( 0, $this->metric( $data, 'finished' )['value'] );
	}

	public function test_i_transient_keys_remain_below_core_limit_for_long_kind(): void {
		$this->assertLessThanOrEqual( 172, strlen( Wrapped_Stats::transient_key( str_repeat( 'x', 200 ), 2026, str_repeat( 'a', 16 ) ) ) );
	}

	public function test_j_cached_payload_is_locale_free_and_hit_redecorates_current_request(): void {
		$this->read_post();
		Wrapped_Stats::bump();
		$gettext = static fn( string $translation ): string => 'XX-' . $translation;
		$number  = static fn(): string => 'X';
		add_filter( 'gettext', $gettext );
		add_filter( 'number_format_i18n', $number );
		Wrapped_Stats::for_kind_year( 'read', 2026 );
		remove_filter( 'gettext', $gettext );
		remove_filter( 'number_format_i18n', $number );
		$gen     = (string) get_option( 'pkiw_wrapped_gen' );
		$payload = get_transient( Wrapped_Stats::transient_key( 'read', 2026, $gen ) );
		$this->assertArrayNotHasKey( 'label', $payload['metrics'][0] );
		$this->assertArrayNotHasKey( 'display', $payload['metrics'][0] );
		$queries = 0;
		$probe = static function ( string $sql ) use ( &$queries ): string {
			++$queries;
			return $sql;
		};
		add_filter( 'posts_request', $probe );
		$data = Wrapped_Stats::for_kind_year( 'read', 2026 );
		remove_filter( 'posts_request', $probe );
		$this->assertSame( 0, $queries, 'A locale redecoration hit must not recompute candidates.' );
		$this->assertStringStartsNotWith( 'XX-', $data['metrics'][0]['label'] );
		$this->assertNotSame( 'X', $data['metrics'][0]['display'] );
	}

	public function test_k_missing_option_and_stale_notoptions_never_cache_until_bump(): void {
		$this->read_post();
		delete_option( 'pkiw_wrapped_gen' );
		$data = Wrapped_Stats::for_kind_year( 'read', 2026 );
		$this->assertSame( Wrapped_Stats::compute( 'read', 2026 ), $data );
		$this->assertSame( '', (string) get_option( 'pkiw_wrapped_gen', '' ) );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$notoptions = is_array( $notoptions ) ? $notoptions : [];
		$notoptions['pkiw_wrapped_gen'] = true;
		wp_cache_set( 'notoptions', $notoptions, 'options' );
		$this->assertSame( Wrapped_Stats::compute( 'read', 2026 ), Wrapped_Stats::for_kind_year( 'read', 2026 ) );
		Wrapped_Stats::bump();
		Wrapped_Stats::for_kind_year( 'read', 2026 );
		$this->assertNotFalse( get_transient( Wrapped_Stats::transient_key( 'read', 2026, (string) get_option( 'pkiw_wrapped_gen' ) ) ) );
		$this->assertArrayNotHasKey( 'pkiw_wrapped_gen', (array) wp_cache_get( 'notoptions', 'options' ) );
	}

	private function assertStampChanges( callable $write, string $case ): void {
		Wrapped_Stats::bump();
		$before = (string) get_option( 'pkiw_wrapped_gen' );
		$write();
		$this->assertNotSame( $before, (string) get_option( 'pkiw_wrapped_gen' ), $case );
	}

	private function cacheShape( array $data, string $gen ): array {
		foreach ( $data['metrics'] as &$metric ) {
			unset( $metric['label'], $metric['display'] );
		}
		unset( $metric );
		$data['gen'] = $gen;
		return $data;
	}

	private function withoutGen( array $data ): array {
		unset( $data['gen'] );
		return $data;
	}

	private function seedAtHook( string $hook, int $priority, callable $matches, string $kind = 'read' ): void {
		// A run-once flag, not remove_action(): removing the running callback makes WP_Hook skip the
		// next priority, which would skip Title_Privacy's PHP_INT_MAX end_save() and leak its counter.
		$done     = false;
		$callback = function ( int $id ) use ( $matches, $kind, &$done ): void {
			if ( $done || ! $matches( $id ) ) {
				return;
			}
			$done    = true;
			$gen     = (string) get_option( 'pkiw_wrapped_gen' );
			$payload = $this->cacheShape( Wrapped_Stats::compute( $kind, 2026 ), $gen );
			// No top rows: the render-time top-row guard must not be what makes the next read miss.
			$payload['top'] = [];
			$payload['metrics'][0]['value'] += 100;
			set_transient( Wrapped_Stats::transient_key( $kind, 2026, $gen ), $payload, DAY_IN_SECONDS );
		};
		add_action( $hook, $callback, $priority, 1 );
	}

	/**
	 * Run Wrapped's shutdown callback the way core's shutdown hook would. Firing every shutdown
	 * callback would run core's wp_ob_end_flush_all() and close PHPUnit's output buffer.
	 */
	private function run_shutdown(): void {
		$this->assertSame( 99, has_action( 'shutdown', [ Wrapped_Stats::class, 'flush_side_effects' ] ) );
		Wrapped_Stats::flush_side_effects();
	}

	/** Priority of an object-method callback on a hook, or 0. */
	private function instance_priority( string $hook, string $class_name, string $method ): int {
		global $wp_filter;
		foreach ( $wp_filter[ $hook ]->callbacks ?? [] as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof $class_name && $method === $function[1] ) {
					return (int) $priority;
				}
			}
		}

		return 0;
	}
}

<?php
/** Wrapped metric registry coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Comic_Strip;
use PKIW\Public_Listing;
use PKIW\Wrapped_Stats;

/** @group integration */
final class WrappedStatsRegistryTest extends WrappedTestCase {
	public function test_registry_matches_c2_keys_sources_and_flags(): void {
		$registry = Wrapped_Stats::registry();
		$this->assertSame( [ 'read', 'listen', 'watch', 'comics', 'play', 'exercise', 'sleep' ], array_keys( $registry ) );
		$this->assertSame( [ 'finished', 'pages', 'streak', 'average_rating' ], array_column( $registry['read']['metrics'], 'key' ) );
		$this->assertSame( [ 'listens', 'artists' ], array_column( $registry['listen']['metrics'], 'key' ) );
		$this->assertSame( [ 'watched', 'rewatched' ], array_column( $registry['watch']['metrics'], 'key' ) );
		$this->assertSame( [ 'finished', 'drawn' ], array_column( $registry['comics']['metrics'], 'key' ) );
		$this->assertSame( [ 'completed', 'hours' ], array_column( $registry['play']['metrics'], 'key' ) );
		$this->assertSame( [ 'posts', 'duration' ], array_column( $registry['exercise']['metrics'], 'key' ) );
		$this->assertSame( [ 'posts', 'duration' ], array_column( $registry['sleep']['metrics'], 'key' ) );
		$this->assertSame( 'finished', $registry['read']['main'] );
		$this->assertTrue( $registry['read']['top'] );
		$this->assertFalse( $registry['listen']['top'] );
		$expected = [
			'read'     => [
				'finished'       => [ 'read_finished_at', [], [ 'read_status' => 'finished' ] ],
				'pages'          => [ 'read_finished_at', [ 'read_pages' ], [ 'read_status' => 'finished' ] ],
				'streak'         => [ null, [], [] ],
				'average_rating' => [ 'read_finished_at', [ 'read_rating' ], [ 'read_status' => 'finished' ] ],
			],
			'listen'   => [
				'listens' => [ 'listen_listened_at', [], [] ],
				'artists' => [ 'listen_listened_at', [ 'listen_artist' ], [] ],
			],
			'watch'    => [
				'watched'   => [ null, [], [ 'watch_status' => 'watched' ] ],
				'rewatched' => [ null, [ 'watch_is_rewatch' ], [ 'watch_status' => 'watched' ] ],
			],
			'comics'   => [
				'finished' => [ 'comic_finished_at', [], [ 'comic_status' => 'finished' ] ],
				'drawn'    => [ null, [ 'comic_status' ], [] ],
			],
			'play'     => [
				'completed' => [ null, [], [ 'play_status' => 'completed' ] ],
				'hours'     => [ null, [ 'play_hours' ], [] ],
			],
			'exercise' => [
				'posts'    => [ null, [], [] ],
				'duration' => [ null, [ 'exercise_duration' ], [] ],
			],
			'sleep'    => [
				'posts'    => [ null, [], [] ],
				'duration' => [ null, [ 'sleep_duration' ], [] ],
			],
		];
		foreach ( $registry as $entry ) {
			foreach ( $entry['metrics'] as $metric ) {
				$this->assertArrayHasKey( 'date_key', $metric );
				$this->assertArrayHasKey( 'source', $metric );
				$this->assertArrayHasKey( 'status', $metric );
			}
		}
		foreach ( $expected as $kind => $metrics ) {
			foreach ( $registry[ $kind ]['metrics'] as $metric ) {
				$this->assertSame( $metrics[ $metric['key'] ], [ $metric['date_key'], $metric['source'], $metric['status'] ], $kind . '.' . $metric['key'] );
			}
		}
	}

	public function test_registry_has_no_cost_health_location_or_book_contract(): void {
		$encoded = wp_json_encode( Wrapped_Stats::registry() );
		$this->assertIsString( $encoded );
		foreach ( [ 'acquisition_price', 'cost', 'exercise_distance', 'exercise_splits', 'exercise_pb', 'sleep_bedtime', 'sleep_wake', 'location' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $encoded, $forbidden );
		}
		$this->assertFalse( has_filter( 'pkiw_wrapped_registry' ) );
		$this->assertFalse( has_filter( 'pkiw_wrapped_metrics' ) );
	}

	public function test_labels_use_existing_status_sources_and_pin_every_scope_string(): void {
		for ( $index = 0; $index < 3; ++$index ) {
			$this->read_post( 2026, [ 'read_pages' => 100 + $index, 'read_rating' => 3 + $index ] );
		}
		$data   = Wrapped_Stats::compute( 'read', 2026 );
		$labels = array_column( $data['metrics'], 'label', 'key' );
		$this->assertSame( \PKIW\read_status_labels()['finished'], $labels['finished'], 'Read Finished must use the existing status label; labels=' . print_r( $labels, true ) );
		$this->assertSame( 'Pages from 3 finished', $labels['pages'] );
		$this->assertSame( 'Longest week streak', $labels['streak'] );
		$this->assertSame( 'Average from 3 rated', $labels['average_rating'] );
		$play = $this->kind_post( 'play', 2026, [ 'play_status' => 'completed', 'play_hours' => 2 ] );
		$this->assertGreaterThan( 0, $play );
		$play_data = Wrapped_Stats::compute( 'play', 2026 );
		$this->assertSame( \PKIW\play_status_labels()['completed'], $this->metric( $play_data, 'completed' )['label'] );
		$this->assertSame( 'Hours played from 1 post', $this->metric( $play_data, 'hours' )['label'] );
		$this->kind_post( 'listen', 2026, [ 'listen_artist' => 'Alder Radio', 'listen_listened_at' => '2026-05-01' ] );
		$this->kind_post( 'watch', 2026, [ 'watch_status' => 'watched', 'watch_is_rewatch' => '1' ] );
		$this->kind_post( 'comics', 2026, [ 'comic_status' => 'finished', 'comic_finished_at' => '2026-05-01' ] );
		for ( $index = 0; $index < 3; ++$index ) {
			$this->kind_post( 'exercise', 2026, [ 'exercise_duration' => 'PT1H', 'exercise_details_public' => '1' ] );
			$this->kind_post( 'sleep', 2026, [ 'sleep_duration' => 480 ] );
		}
		$this->kind_post( 'acquisition', 2026 );
		// Lists, not key maps: exercise and sleep both use the key `duration`.
		$all_labels = array_merge(
			array_values( $labels ),
			array_column( Wrapped_Stats::compute( 'listen', 2026 )['metrics'], 'label' ),
			array_column( Wrapped_Stats::compute( 'watch', 2026 )['metrics'], 'label' ),
			array_column( Wrapped_Stats::compute( 'comics', 2026 )['metrics'], 'label' ),
			array_column( $play_data['metrics'], 'label' ),
			array_column( Wrapped_Stats::compute( 'exercise', 2026 )['metrics'], 'label' ),
			array_column( Wrapped_Stats::compute( 'sleep', 2026 )['metrics'], 'label' ),
			array_column( Wrapped_Stats::compute( 'acquisition', 2026 )['metrics'], 'label' )
		);
		foreach ( [ 'Finished', 'Pages from 3 finished', 'Longest week streak', 'Average from 3 rated', 'Listens', 'Artists', 'Watched', 'Rewatched', 'Drawn', 'Completed', 'Hours played from 1 post', 'Posts', 'Exercise time from 3 posts', 'Sleep time from 3 posts' ] as $expected_label ) {
			$this->assertContains( $expected_label, $all_labels, $expected_label );
		}
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/includes/class-wrapped-stats.php' );
		$this->assertIsString( $source );
		foreach ( [ 'Pages from %d finished', 'Average from %d rated', 'Hours played from %d post', 'Hours played from %d posts', 'Exercise time from %d post', 'Exercise time from %d posts', 'Sleep time from %d post', 'Sleep time from %d posts' ] as $label ) {
			$this->assertStringContainsString( $label, $source, $label );
		}
		$this->assertStringNotContainsStringIgnoringCase( 'book', implode( ' ', $all_labels ) );
		$this->assertStringNotContainsString( 'apply_filters', $source );
	}

	public function test_drawn_uses_comic_strip_predicate_and_excludes_card_with_empty_status(): void {
		$drawn = $this->kind_post( 'comics', 2026, [ 'comic_status' => '' ], [ 'post_content' => '<p>Hand drawn strip</p>' ] );
		$card  = $this->kind_post( 'comics', 2026, [ 'comic_status' => '' ], [ 'post_content' => '<!-- wp:post-kinds-indieweb/comic-card /-->' ] );
		$this->assertTrue( Comic_Strip::is_drawn( get_post( $drawn ) ) );
		$this->assertFalse( Comic_Strip::is_drawn( get_post( $card ) ) );
		$this->assertSame( 1, $this->metric( Wrapped_Stats::compute( 'comics', 2026 ), 'drawn' )['value'] );
		$this->go_to( Comic_Strip::series_url() );
		$this->assertContains( $drawn, array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
		$actual = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertNotContains( $card, $actual, 'The Drawn archive must exclude the comic-card fixture; actual=' . print_r( $actual, true ) );
	}

	public function test_meta_keys_derive_registry_and_public_listing_sources_without_q10_literals(): void {
		$keys = Wrapped_Stats::meta_keys();
		foreach ( Public_Listing::meta_keys() as $key ) {
			$this->assertContains( $key, $keys );
		}
		foreach ( [ '_pkiw_read_status', '_pkiw_read_finished_at', '_pkiw_watch_is_rewatch', '_pkiw_comic_finished_at', '_pkiw_exercise_details_public' ] as $key ) {
			$this->assertContains( $key, $keys );
		}
		$paths = [
			dirname( __DIR__, 3 ) . '/includes/class-wrapped-stats.php',
			dirname( __DIR__, 3 ) . '/includes/class-wrapped-post.php',
			dirname( __DIR__, 3 ) . '/includes/class-wrapped-blocks.php',
			dirname( __DIR__, 3 ) . '/includes/abilities/class-wrapped-abilities.php',
			dirname( __DIR__, 3 ) . '/includes/functions-wrapped.php',
		];
		foreach ( $paths as $path ) {
			$source = file_get_contents( $path );
			$this->assertIsString( $source );
			$this->assertStringNotContainsString( 'quiet_' . 'public', $source, $path );
			$this->assertStringNotContainsString( 'activitypub_' . 'content_visibility', $source, $path );
		}
	}
}

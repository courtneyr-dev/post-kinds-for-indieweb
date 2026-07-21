<?php
/**
 * Integration tests for the firehose feed.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Integration;

use WP_Query;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class FirehoseFeedTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'pkiw_settings', [ 'import_storage_mode' => 'hidden' ] );
	}

	public function tear_down(): void {
		delete_option( 'pkiw_settings' );
		parent::tear_down();
	}

	public function test_main_feed_excludes_imported_posts_and_firehose_includes_them(): void {
		$normal_id   = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$imported_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		add_post_meta( $imported_id, '_pkiw_imported_from', 'lastfm' );

		$main_feed_ids = $this->query_feed( 'rss2' );
		$firehose_ids  = $this->query_feed( 'firehose' );

		$this->assertSame( [ $normal_id ], $main_feed_ids );
		$this->assertSame( [ $normal_id, $imported_id ], $firehose_ids );
	}

	public function test_firehose_feed_and_rewrite_rule_are_registered(): void {
		global $wp_rewrite;

		$this->assertContains( 'firehose', $wp_rewrite->feeds );
		$this->assertArrayHasKey( '^firehose/?$', $wp_rewrite->extra_rules_top );
		$this->assertSame(
			'index.php?feed=firehose&pkiw_include_imported=1',
			$wp_rewrite->extra_rules_top['^firehose/?$']
		);
		$this->assertContains( 'pkiw_include_imported', apply_filters( 'query_vars', [] ) );
	}

	/**
	 * Run a feed request as the main query and return its post IDs.
	 *
	 * @param string $feed Feed name.
	 * @return int[] Post IDs.
	 */
	private function query_feed( string $feed ): array {
		global $wp_query, $wp_the_query;

		$query        = new WP_Query();
		$wp_query     = $query;
		$wp_the_query = $query;

		$query->query(
			[
				'feed'           => $feed,
				'fields'         => 'ids',
				'order'          => 'ASC',
				'orderby'        => 'ID',
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'posts_per_page' => -1,
			]
		);

		return array_map( 'intval', $query->posts );
	}
}

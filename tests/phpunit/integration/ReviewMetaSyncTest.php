<?php
/**
 * The review post's rating, item name and item URL, filled from its first
 * star-rating block.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Issue 252: a review keeps its rating in the star-rating block, and the
 * archive reads it from meta. The fill runs on wp_after_insert_post at 30,
 * after the kind sync (10) and the default category (20), and from the
 * batched backfill. It never overwrites a stored value. Every read here is
 * a raw row, because the registered defaults (0 and 5) hide an empty row
 * from get_post_meta().
 *
 * @group integration
 */
final class ReviewMetaSyncTest extends WP_UnitTestCase {

	private const KETTLE = '<!-- wp:post-kinds-indieweb/star-rating {"rating":4,"maxRating":5,"itemName":"Fictional Kettle","itemUrl":"https://shop.example.com/kettle"} /-->';

	/**
	 * The fill is hooked at wp_after_insert_post 30, on the sync class.
	 */
	public function test_the_fill_runs_at_wp_after_insert_post_priority_30(): void {
		$found = [];
		foreach ( $GLOBALS['wp_filter']['wp_after_insert_post']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && ( $function[0] instanceof \PKIW\Card_Meta_Sync || \PKIW\Card_Meta_Sync::class === $function[0] ) ) {
					$found[] = $priority;
				}
			}
		}
		sort( $found );

		// resync_after_insert() stays at 5; the review fill is the one at 30.
		$this->assertSame( [ 5, 30 ], $found );
	}

	/**
	 * A save fills the rating pair, the item name and the item URL.
	 */
	public function test_a_review_save_fills_the_rating_name_and_url(): void {
		$post_id = $this->review( self::KETTLE );

		$this->assert_rows( $post_id, '4', '5', 'Fictional Kettle', 'https://shop.example.com/kettle' );
	}

	/**
	 * Half stars and a scale of 10.
	 */
	public function test_the_pair_keeps_a_half_star_and_a_scale_of_ten(): void {
		$post_id = $this->review( '<!-- wp:post-kinds-indieweb/star-rating {"rating":8.5,"maxRating":10} /-->' );

		$this->assertSame( '8.5', get_metadata_raw( 'post', $post_id, '_pkiw_review_rating', true ) );
		$this->assertSame( '10', get_metadata_raw( 'post', $post_id, '_pkiw_review_best', true ) );
	}

	/**
	 * The editor drops an attribute that equals its default, so a 4 of 5
	 * saves with no maxRating. The pair still lands as 4 of 5.
	 */
	public function test_a_block_without_max_rating_fills_best_with_five(): void {
		$post_id = $this->review( '<!-- wp:post-kinds-indieweb/star-rating {"rating":3} /-->' );

		$this->assertSame( '3', get_metadata_raw( 'post', $post_id, '_pkiw_review_rating', true ) );
		$this->assertSame( '5', get_metadata_raw( 'post', $post_id, '_pkiw_review_best', true ) );
	}

	/**
	 * Quick Post writes the default rows 0 and 5. A block saying 8 of 10
	 * replaces both, as a pair: best stays 5 over a rating of 8 otherwise.
	 */
	public function test_default_rows_zero_and_five_give_way_to_a_block_of_eight_of_ten(): void {
		$post_id = $this->review( '<!-- wp:paragraph --><p>Notes.</p><!-- /wp:paragraph -->' );
		update_post_meta( $post_id, '_pkiw_review_rating', 0 );
		update_post_meta( $post_id, '_pkiw_review_best', 5 );

		$this->update_content( $post_id, '<!-- wp:post-kinds-indieweb/star-rating {"rating":8,"maxRating":10} /-->' );

		$this->assertSame( '8', get_metadata_raw( 'post', $post_id, '_pkiw_review_rating', true ) );
		$this->assertSame( '10', get_metadata_raw( 'post', $post_id, '_pkiw_review_best', true ) );
	}

	/**
	 * An empty-string rating row counts as unset, the same as a missing one.
	 */
	public function test_an_empty_string_rating_row_is_filled(): void {
		$post_id = $this->review( '<!-- wp:paragraph --><p>Notes.</p><!-- /wp:paragraph -->' );
		$this->put_raw_row( $post_id, '_pkiw_review_rating', '' );

		$this->update_content( $post_id, '<!-- wp:post-kinds-indieweb/star-rating {"rating":2,"maxRating":5} /-->' );

		$this->assertSame( '2', get_metadata_raw( 'post', $post_id, '_pkiw_review_rating', true ) );
	}

	/**
	 * A stored 4 of 5 stays when the block says 8 of 10, and so does its
	 * best: the pair is never half-replaced.
	 */
	public function test_a_stored_rating_is_never_overwritten(): void {
		$post_id = $this->review( '<!-- wp:paragraph --><p>Notes.</p><!-- /wp:paragraph -->' );
		update_post_meta( $post_id, '_pkiw_review_rating', 4 );
		update_post_meta( $post_id, '_pkiw_review_best', 5 );

		$this->update_content( $post_id, '<!-- wp:post-kinds-indieweb/star-rating {"rating":8,"maxRating":10} /-->' );

		$this->assertSame( '4', get_metadata_raw( 'post', $post_id, '_pkiw_review_rating', true ) );
		$this->assertSame( '5', get_metadata_raw( 'post', $post_id, '_pkiw_review_best', true ) );
	}

	/**
	 * A stored item name and URL stay; the empty one is filled.
	 */
	public function test_item_name_and_url_fill_only_when_their_row_is_empty(): void {
		$post_id = $this->review( '<!-- wp:paragraph --><p>Notes.</p><!-- /wp:paragraph -->' );
		update_post_meta( $post_id, '_pkiw_review_item_name', 'Kept Name' );

		$this->update_content( $post_id, self::KETTLE );

		$this->assertSame( 'Kept Name', get_metadata_raw( 'post', $post_id, '_pkiw_review_item_name', true ) );
		$this->assertSame( 'https://shop.example.com/kettle', get_metadata_raw( 'post', $post_id, '_pkiw_review_item_url', true ) );

		update_post_meta( $post_id, '_pkiw_review_item_url', 'https://kept.example.com/' );
		$this->update_content( $post_id, '<!-- wp:post-kinds-indieweb/star-rating {"rating":4,"itemName":"Other","itemUrl":"https://other.example.com/"} /-->' );

		$this->assertSame( 'Kept Name', get_metadata_raw( 'post', $post_id, '_pkiw_review_item_name', true ) );
		$this->assertSame( 'https://kept.example.com/', get_metadata_raw( 'post', $post_id, '_pkiw_review_item_url', true ) );
	}

	/**
	 * A block with no rating fills no pair, but still names the item.
	 */
	public function test_a_block_with_no_rating_fills_the_name_but_not_the_pair(): void {
		$post_id = $this->review( '<!-- wp:post-kinds-indieweb/star-rating {"itemName":"Fictional Lamp"} /-->' );

		$this->assertSame( 'Fictional Lamp', get_metadata_raw( 'post', $post_id, '_pkiw_review_item_name', true ) );
		$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_review_rating', true ) );
		$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_review_best', true ) );
	}

	/**
	 * Only the first star-rating block counts, wherever it sits.
	 */
	public function test_the_first_star_rating_block_wins_even_nested(): void {
		$post_id = $this->review(
			'<!-- wp:group --><div class="wp-block-group"><!-- wp:post-kinds-indieweb/star-rating {"rating":5,"itemName":"First"} /--></div><!-- /wp:group -->'
			. '<!-- wp:post-kinds-indieweb/star-rating {"rating":1,"itemName":"Second"} /-->'
		);

		$this->assertSame( '5', get_metadata_raw( 'post', $post_id, '_pkiw_review_rating', true ) );
		$this->assertSame( 'First', get_metadata_raw( 'post', $post_id, '_pkiw_review_item_name', true ) );
	}

	/**
	 * A post of another kind with a star-rating block is not a review.
	 */
	public function test_a_non_review_post_with_a_star_rating_block_is_not_written(): void {
		$this->ensure_kind_term( 'note' );
		$post_id = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>A note.</p><!-- /wp:paragraph -->' ] );
		wp_set_object_terms( $post_id, 'note', 'kind' );

		$this->update_content( $post_id, self::KETTLE );
		\PKIW\Card_Meta_Sync::backfill_batch( 0, 500 );

		foreach ( [ 'review_rating', 'review_best', 'review_item_name', 'review_item_url' ] as $suffix ) {
			$this->assertNull( get_metadata_raw( 'post', $post_id, '_pkiw_' . $suffix, true ), $suffix );
		}
	}

	/**
	 * The backfill fills a review saved before the fill existed.
	 */
	public function test_the_backfill_fills_a_review(): void {
		$post_id = $this->review( self::KETTLE );
		foreach ( [ 'review_rating', 'review_best', 'review_item_name', 'review_item_url' ] as $suffix ) {
			delete_post_meta( $post_id, '_pkiw_' . $suffix );
		}

		\PKIW\Card_Meta_Sync::backfill_batch( 0, 500 );

		$this->assert_rows( $post_id, '4', '5', 'Fictional Kettle', 'https://shop.example.com/kettle' );
	}

	/**
	 * Running the backfill again changes nothing: the rows hold, and no
	 * review row is written, not even with the same value.
	 */
	public function test_the_backfill_is_idempotent(): void {
		$post_id = $this->review( self::KETTLE );
		foreach ( [ 'review_rating', 'review_best', 'review_item_name', 'review_item_url' ] as $suffix ) {
			delete_post_meta( $post_id, '_pkiw_' . $suffix );
		}
		\PKIW\Card_Meta_Sync::backfill_batch( 0, 500 );
		$first = $this->rows( $post_id );

		$writes = [];
		$watch  = static function ( $check, $object_id, $meta_key ) use ( &$writes ) {
			if ( str_starts_with( (string) $meta_key, '_pkiw_review_' ) ) {
				$writes[] = $meta_key;
			}
			return $check;
		};
		foreach ( [ 'update_post_metadata', 'add_post_metadata', 'delete_post_metadata' ] as $filter ) {
			add_filter( $filter, $watch, 1, 3 );
		}
		\PKIW\Card_Meta_Sync::backfill_batch( 0, 500 );
		foreach ( [ 'update_post_metadata', 'add_post_metadata', 'delete_post_metadata' ] as $filter ) {
			remove_filter( $filter, $watch, 1 );
		}

		$this->assertSame( [ '4', '5', 'Fictional Kettle', 'https://shop.example.com/kettle' ], $first );
		$this->assertSame( $first, $this->rows( $post_id ) );
		$this->assertSame( [], $writes );
	}

	/**
	 * A revision of a review is not a review post.
	 */
	public function test_a_revision_is_not_filled(): void {
		$post_id = $this->review( '<!-- wp:paragraph --><p>Notes.</p><!-- /wp:paragraph -->' );
		$this->update_content( $post_id, self::KETTLE );
		$revisions = wp_get_post_revisions( $post_id );

		foreach ( $revisions as $revision ) {
			$this->assertNull( get_metadata_raw( 'post', $revision->ID, '_pkiw_review_rating', true ) );
		}
	}

	/**
	 * A review post carrying the kind term, saved twice so the second save
	 * (wp_after_insert_post) sees the kind the first one assigned.
	 *
	 * @param string $content Post content.
	 * @return int Post ID.
	 */
	private function review( string $content ): int {
		$this->ensure_kind_term( 'review' );
		$post_id = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>Draft.</p><!-- /wp:paragraph -->' ] );
		wp_set_object_terms( $post_id, 'review', 'kind' );
		$this->update_content( $post_id, $content );

		return $post_id;
	}

	/**
	 * Save new content, which fires wp_after_insert_post for the post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content.
	 */
	private function update_content( int $post_id, string $content ): void {
		wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ] );
	}

	/**
	 * Write a raw row without the registered sanitizer.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param string $value   Value.
	 */
	private function put_raw_row( int $post_id, string $key, string $value ): void {
		global $wpdb;
		delete_post_meta( $post_id, $key );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Stores an empty row on purpose.
			$wpdb->postmeta,
			[
				'post_id'    => $post_id,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
			]
		);
		wp_cache_delete( $post_id, 'post_meta' );
	}

	/**
	 * The four review rows, raw.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, mixed>
	 */
	private function rows( int $post_id ): array {
		return array_map(
			static fn( string $suffix ) => get_metadata_raw( 'post', $post_id, '_pkiw_' . $suffix, true ),
			[ 'review_rating', 'review_best', 'review_item_name', 'review_item_url' ]
		);
	}

	/**
	 * Assert the four review rows.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $rating  Rating.
	 * @param string $best    Best.
	 * @param string $name    Item name.
	 * @param string $url     Item URL.
	 */
	private function assert_rows( int $post_id, string $rating, string $best, string $name, string $url ): void {
		$this->assertSame( [ $rating, $best, $name, $url ], $this->rows( $post_id ) );
	}

	/**
	 * Make sure a `kind` term exists so it can be assigned to a post.
	 *
	 * @param string $slug Kind slug.
	 */
	private function ensure_kind_term( string $slug ): void {
		if ( ! term_exists( $slug, 'kind' ) ) {
			wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
		}
	}
}

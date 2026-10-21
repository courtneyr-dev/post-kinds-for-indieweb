<?php
/**
 * Public quote index aggregation and invalidation.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuoteIndexTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
	}

	private function quote( string $date, string $author = '', string $work = '', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_date'   => $date,
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		if ( '' !== $author ) {
			add_post_meta( $id, '_pkiw_cite_author', $author );
		}
		if ( '' !== $work ) {
			add_post_meta( $id, '_pkiw_cite_name', $work );
		}

		return (int) $id;
	}

	public function test_index_counts_case_insensitively_and_orders_authors_as_the_archive_sections_do(): void {
		$newer = $this->quote( '2026-09-06 10:00:00', '  Riley  ', 'Shared Work' );
		$this->quote( '2026-09-05 10:00:00', 'riley', 'shared work' );
		$this->quote( '2026-09-04 10:00:00', 'Álvaro', 'Atlas' );
		$this->quote( '2026-09-03 10:00:00', 'Bea', 'Shared Work' );
		$this->quote( '2026-09-02 10:00:00' );

		$index = Quote_Archive::index();

		$this->assertSame( 5, $index['total'] );
		$this->assertEqualsCanonicalizing( [ 'Bea', 'Riley', 'Álvaro' ], array_column( $index['authors'], 'name' ) );
		$counts = array_column( $index['authors'], 'count', 'name' );
		ksort( $counts );
		$this->assertSame( [ 'Bea' => 1, 'Riley' => 2, 'Álvaro' => 1 ], $counts );
		$this->assertSame( [ $newer ], array_slice( $index['initials']['R']['ids'], 0, 1 ) );
		$this->assertSame( [ 'Atlas', 'Shared Work', 'Shared Work' ], array_column( $index['works'], 'name' ) );
		$this->assertSame( [ false, true, true ], array_column( $index['works'], 'shared_title' ) );

		// The tabs and the sections come from one order: the one the archive query sorts by.
		$grouped = new WP_Query(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => -1,
				'ignore_sticky_posts' => true,
				'tax_query'           => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => 'quote' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Test.
				'pkiw_group_source'   => Quote_Archive::SOURCE,
				'pkiw_group_order'    => 'ASC',
				'pkiw_group_empty'    => 'last',
			]
		);
		$order = [];
		foreach ( $grouped->posts as $post_id ) {
			$author = trim( (string) get_post_meta( (int) $post_id, '_pkiw_cite_author', true ) );
			if ( '' !== $author && ! in_array( mb_strtolower( $author ), $order, true ) ) {
				$order[] = mb_strtolower( $author );
			}
		}
		$this->assertSame( $order, array_map( 'mb_strtolower', array_column( $index['authors'], 'name' ) ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public function excluded_statuses(): array {
		return [
			'draft'          => [ [ 'post_status' => 'draft' ] ],
			'private'        => [ [ 'post_status' => 'private' ] ],
			'future'         => [ [ 'post_status' => 'future', 'post_date' => '2036-09-01 10:00:00' ] ],
			'password'       => [ [ 'post_password' => 'secret' ] ],
			'spaces password' => [ [ 'post_password' => '   ' ] ],
		];
	}

	/**
	 * @dataProvider excluded_statuses
	 *
	 * @param array<string, mixed> $args Post arguments.
	 */
	public function test_each_non_public_guard_is_excluded_from_the_index( array $args ): void {
		$this->quote( '2026-09-02 10:00:00', 'Public Example', 'Public Work' );
		$this->quote( '2026-09-01 10:00:00', 'Excluded Example', 'Excluded Work', $args );

		$this->assertSame( [ 'Public Example' ], array_column( Quote_Archive::index()['authors'], 'name' ) );
	}

	public function test_trashed_quote_and_non_quote_with_cite_meta_are_excluded(): void {
		$this->quote( '2026-09-03 10:00:00', 'Public Example', 'Public Work' );
		$trashed = $this->quote( '2026-09-02 10:00:00', 'Trashed Example', 'Trashed Work' );
		wp_trash_post( $trashed );
		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		add_post_meta( $note, '_pkiw_cite_author', 'Note Example' );

		$this->assertSame( [ 'Public Example' ], array_column( Quote_Archive::index()['authors'], 'name' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function cite_keys(): array {
		return [
			'author' => [ '_pkiw_cite_author' ],
			'work'   => [ '_pkiw_cite_name' ],
			'url'    => [ '_pkiw_cite_url' ],
		];
	}

	/**
	 * @dataProvider cite_keys
	 */
	public function test_each_cite_meta_key_invalidates_the_transient( string $key ): void {
		$id = $this->quote( '2026-09-01 10:00:00', 'Avery', 'Book' );
		Quote_Archive::index();
		$this->assertNotFalse( get_transient( Quote_Archive::INDEX_TRANSIENT ) );

		update_post_meta( $id, $key, 'Changed' );

		$this->assertFalse( get_transient( Quote_Archive::INDEX_TRANSIENT ) );
	}

	public function test_status_password_trash_untrash_and_kind_changes_invalidate_the_transient(): void {
		$id = $this->quote( '2026-09-01 10:00:00', 'Avery', 'Book' );
		$changes = [
			static fn() => wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] ),
			static fn() => wp_update_post( [ 'ID' => $id, 'post_status' => 'publish', 'post_password' => 'secret' ] ),
			static fn() => wp_trash_post( $id ),
			static fn() => wp_untrash_post( $id ),
			static fn() => wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY ),
		];
		foreach ( $changes as $change ) {
			set_transient( Quote_Archive::INDEX_TRANSIENT, [ 'sentinel' => true ] );
			$change();
			$this->assertFalse( get_transient( Quote_Archive::INDEX_TRANSIENT ) );
		}
	}

	public function test_a_bare_status_transition_invalidates_the_transient(): void {
		$id = $this->quote( '2026-09-01 10:00:00', 'Avery', 'Book' );
		set_transient( Quote_Archive::INDEX_TRANSIENT, [ 'sentinel' => true ] );

		wp_transition_post_status( 'private', 'publish', get_post( $id ) );

		$this->assertFalse( get_transient( Quote_Archive::INDEX_TRANSIENT ) );
	}
}

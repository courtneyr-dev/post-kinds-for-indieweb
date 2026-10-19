<?php
/**
 * Listen media type podcast backfill coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Listen_Media_Type;

/**
 * @group integration
 */
final class ListenMediaTypeBackfillTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! term_exists( 'listen', 'kind' ) ) {
			wp_insert_term( 'Listen', 'kind', [ 'slug' => 'listen' ] );
		}
		if ( ! term_exists( 'note', 'kind' ) ) {
			wp_insert_term( 'Note', 'kind', [ 'slug' => 'note' ] );
		}
	}

	public function test_candidates_require_the_listen_kind_legacy_signals_and_supported_status(): void {
		$k01       = $this->candidate( 'K01 private report sentinel' );
		$k02       = $this->candidate( 'K02 explicit music' );
		$no_source = $this->candidate( 'M03 no source', [ 'source' => '' ] );
		$not_listen = $this->candidate( 'N01 wrong kind', [ 'kind' => 'note' ] );
		$trashed    = $this->candidate( 'Trashed podcast' );
		$private    = $this->candidate(
			'Private podcast',
			[ 'status' => 'private' ]
		);
		$draft      = $this->candidate(
			'Draft podcast',
			[
				'status'   => 'draft',
				'password' => 'secret',
			]
		);
		update_post_meta( $k02, Listen_Media_Type::META, Listen_Media_Type::MUSIC );
		wp_trash_post( $trashed );

		$rows = Listen_Media_Type::candidates();

		$this->assertSame( [ $k01, $private, $draft ], array_column( $rows, 'id' ) );
		$this->assertNotContains( $k02, array_column( $rows, 'id' ) );
		$this->assertNotContains( $no_source, array_column( $rows, 'id' ) );
		$this->assertNotContains( $not_listen, array_column( $rows, 'id' ) );
		$this->assertNotContains( $trashed, array_column( $rows, 'id' ) );
		$this->assertSame( 'publish', $rows[0]['status'] );
		$this->assertFalse( $rows[0]['password'] );
		$this->assertSame( [ 'artist-equals-album', 'source' ], $rows[0]['signals'] );
		$this->assertSame( 'private', $rows[1]['status'] );
		$this->assertFalse( $rows[1]['password'] );
		$this->assertSame( 'draft', $rows[2]['status'] );
		$this->assertTrue( $rows[2]['password'] );
		$this->assertSame(
			'ID ' . $k01 . ' status publish password no signals artist-equals-album,source',
			Listen_Media_Type::report_line( $rows[0] )
		);
		$this->assertStringNotContainsString( 'K01 private report sentinel', Listen_Media_Type::report_line( $rows[0] ) );
	}

	public function test_candidates_is_a_read_only_dry_run(): void {
		global $wpdb;

		$post_id = $this->candidate( 'Dry run candidate' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count raw rows to prove the candidate scan writes nothing.
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ) );

		$this->assertSame( [ $post_id ], array_column( Listen_Media_Type::candidates(), 'id' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count raw rows to prove the candidate scan writes nothing.
		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id ) );
		$this->assertSame( $before, $after );
		$this->assertFalse( metadata_exists( 'post', $post_id, Listen_Media_Type::META ) );
	}

	public function test_apply_writes_only_selected_candidates_without_post_side_effects(): void {
		global $wpdb;

		$k01      = $this->candidate( 'Selected candidate' );
		$unpicked = $this->candidate( 'Unselected candidate' );
		update_post_meta( $k01, '_pkiw_custom_privacy_marker', 'private' );
		$wpdb->update( $wpdb->posts, [ 'post_modified' => '2020-01-02 03:04:05' ], [ 'ID' => $k01 ], [ '%s' ], [ '%d' ] );
		clean_post_cache( $k01 );
		$modified = (string) get_post_field( 'post_modified', $k01 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count raw rows to verify one meta-only write.
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $k01 ) );
		$trashed = 0;
		$deleted = 0;
		$on_trash = static function () use ( &$trashed ): void {
			++$trashed;
		};
		$on_delete = static function () use ( &$deleted ): void {
			++$deleted;
		};
		add_action( 'wp_trash_post', $on_trash );
		add_action( 'before_delete_post', $on_delete );

		$result = Listen_Media_Type::apply( [ $k01 ] );

		remove_action( 'wp_trash_post', $on_trash );
		remove_action( 'before_delete_post', $on_delete );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count raw rows to verify one meta-only write.
		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $k01 ) );
		$this->assertSame( [ 'written' => [ $k01 ], 'skipped' => [] ], $result );
		$this->assertSame( $before + 1, $after );
		$this->assertSame( Listen_Media_Type::PODCAST, Listen_Media_Type::stored( $k01 ) );
		$this->assertFalse( metadata_exists( 'post', $unpicked, Listen_Media_Type::META ) );
		$this->assertSame( $modified, (string) get_post_field( 'post_modified', $k01 ) );
		$this->assertSame( 'private', get_post_meta( $k01, '_pkiw_custom_privacy_marker', true ) );
		$this->assertSame( 0, $trashed );
		$this->assertSame( 0, $deleted );

		$second = Listen_Media_Type::apply( [ $k01 ] );
		$this->assertSame( [ 'written' => [], 'skipped' => [ $k01 => 'not a candidate' ] ], $second );
		$this->assertSame( $after, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $k01 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_parse_ids_keeps_positive_unique_ids_in_input_order(): void {
		$this->assertSame( [ 7, 2, 9 ], Listen_Media_Type::parse_ids( '7, 2,7,0,-3,banana,9' ) );
	}

	/**
	 * Create one post with the legacy podcast evidence shape.
	 *
	 * @param array{kind?:string, status?:string, password?:string, artist?:string, album?:string, source?:string} $args Overrides.
	 */
	private function candidate( string $title, array $args = [] ): int {
		$args = array_merge(
			[
				'kind'     => 'listen',
				'status'   => 'publish',
				'password' => '',
				'artist'   => 'The Example Show',
				'album'    => 'The Example Show',
				'source'   => 'Snipd',
			],
			$args
		);
		$post_id = self::factory()->post->create(
			[
				'post_status'   => $args['status'],
				'post_password' => $args['password'],
				'post_title'    => $title,
			]
		);
		wp_set_object_terms( $post_id, $args['kind'], 'kind' );
		update_post_meta( $post_id, '_pkiw_listen_artist', $args['artist'] );
		update_post_meta( $post_id, '_pkiw_listen_album', $args['album'] );
		if ( '' !== $args['source'] ) {
			update_post_meta( $post_id, '_pkiw_source', $args['source'] );
		}

		return $post_id;
	}
}

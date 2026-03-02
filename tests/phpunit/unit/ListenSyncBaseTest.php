<?php
/**
 * Test the Listen Sync Base class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Sync\Listen_Sync_Base;
use WP_UnitTestCase;

/**
 * Concrete test stub for the abstract Listen_Sync_Base.
 */
class Test_Listen_Sync extends Listen_Sync_Base {

	/**
	 * Whether connected.
	 *
	 * @var bool
	 */
	public bool $connected = true;

	/**
	 * Syndication result.
	 *
	 * @var array|false
	 */
	public $syndication_result = false;

	/**
	 * Recorded syndicate calls.
	 *
	 * @var array
	 */
	public array $syndicate_calls = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->service_id   = 'testmusic';
		$this->service_name = 'Test Music';
		parent::__construct();
	}

	public function is_connected(): bool {
		return $this->connected;
	}

	protected function syndicate_listen( int $post_id, array $listen_data ) {
		$this->syndicate_calls[] = [ 'post_id' => $post_id, 'data' => $listen_data ];
		return $this->syndication_result;
	}

	// Expose protected methods for testing.
	public function test_is_listen_post( int $post_id ): bool {
		return $this->is_listen_post( $post_id );
	}

	public function test_is_syndication_enabled( int $post_id ): bool {
		return $this->is_syndication_enabled( $post_id );
	}

	public function test_is_already_syndicated( int $post_id ): bool {
		return $this->is_already_syndicated( $post_id );
	}

	public function test_was_imported_from_service( int $post_id ): bool {
		return $this->was_imported_from_service( $post_id );
	}

	public function test_get_listen_data_from_post( int $post_id ): array {
		return $this->get_listen_data_from_post( $post_id );
	}

	public function test_add_syndication_link( int $post_id, string $url ): void {
		$this->add_syndication_link( $post_id, $url );
	}
}

/**
 * Test the Listen Sync Base class.
 *
 * @covers \PostKindsForIndieWeb\Sync\Listen_Sync_Base
 */
class ListenSyncBaseTest extends WP_UnitTestCase {

	/**
	 * Test sync instance.
	 *
	 * @var Test_Listen_Sync
	 */
	private Test_Listen_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->sync = new Test_Listen_Sync();
	}

	/**
	 * Create a post with the listen kind taxonomy.
	 *
	 * @return int Post ID.
	 */
	private function create_listen_post(): int {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		if ( ! taxonomy_exists( 'kind' ) ) {
			register_taxonomy( 'kind', 'post' );
		}
		if ( ! term_exists( 'listen', 'kind' ) ) {
			wp_insert_term( 'listen', 'kind' );
		}

		wp_set_post_terms( $post_id, [ 'listen' ], 'kind' );
		return $post_id;
	}

	/**
	 * Test constructor sets meta keys.
	 */
	public function test_constructor_sets_meta_keys(): void {
		$this->assertSame( 'testmusic', $this->sync->get_service_id() );
		$this->assertSame( 'Test Music', $this->sync->get_service_name() );
	}

	/**
	 * Test is_listen_post returns true for listen posts.
	 */
	public function test_is_listen_post_true(): void {
		$post_id = $this->create_listen_post();
		$this->assertTrue( $this->sync->test_is_listen_post( $post_id ) );
	}

	/**
	 * Test is_listen_post returns false for other posts.
	 */
	public function test_is_listen_post_false(): void {
		$post_id = self::factory()->post->create();
		$this->assertFalse( $this->sync->test_is_listen_post( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled false without settings.
	 */
	public function test_is_syndication_enabled_false_without_settings(): void {
		$post_id = self::factory()->post->create();
		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled true with config.
	 */
	public function test_is_syndication_enabled_true_with_config(): void {
		$post_id = self::factory()->post->create();
		update_option( 'post_kinds_indieweb_settings', [
			'listen_sync_to_testmusic' => true,
		] );

		$this->assertTrue( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled false on post opt-out.
	 */
	public function test_is_syndication_enabled_false_on_opt_out(): void {
		$post_id = self::factory()->post->create();
		update_option( 'post_kinds_indieweb_settings', [
			'listen_sync_to_testmusic' => true,
		] );
		update_post_meta( $post_id, '_postkind_syndicate_testmusic', '0' );

		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled false when disconnected.
	 */
	public function test_is_syndication_enabled_false_disconnected(): void {
		$post_id = self::factory()->post->create();
		update_option( 'post_kinds_indieweb_settings', [
			'listen_sync_to_testmusic' => true,
		] );
		$this->sync->connected = false;

		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_already_syndicated false for new post.
	 */
	public function test_is_already_syndicated_false(): void {
		$post_id = self::factory()->post->create();
		$this->assertFalse( $this->sync->test_is_already_syndicated( $post_id ) );
	}

	/**
	 * Test is_already_syndicated true with external ID.
	 */
	public function test_is_already_syndicated_true(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_listen_testmusic_id', 'scrobble-123' );

		$this->assertTrue( $this->sync->test_is_already_syndicated( $post_id ) );
	}

	/**
	 * Test was_imported_from_service matching.
	 */
	public function test_was_imported_true(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_imported_from', 'testmusic' );

		$this->assertTrue( $this->sync->test_was_imported_from_service( $post_id ) );
	}

	/**
	 * Test was_imported_from_service non-matching.
	 */
	public function test_was_imported_false(): void {
		$post_id = self::factory()->post->create();
		$this->assertFalse( $this->sync->test_was_imported_from_service( $post_id ) );
	}

	/**
	 * Test get_listen_data_from_post extracts meta.
	 */
	public function test_get_listen_data_from_post(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_listen_track', 'Bohemian Rhapsody' );
		update_post_meta( $post_id, '_postkind_listen_artist', 'Queen' );
		update_post_meta( $post_id, '_postkind_listen_album', 'A Night at the Opera' );
		update_post_meta( $post_id, '_postkind_listen_duration', '354' );
		update_post_meta( $post_id, '_postkind_listen_mbid', 'mbid-123' );

		$data = $this->sync->test_get_listen_data_from_post( $post_id );

		$this->assertSame( 'Bohemian Rhapsody', $data['track'] );
		$this->assertSame( 'Queen', $data['artist'] );
		$this->assertSame( 'A Night at the Opera', $data['album'] );
		$this->assertSame( '354', $data['duration'] );
		$this->assertSame( 'mbid-123', $data['mbid'] );
	}

	/**
	 * Test get_listen_data includes optional MBIDs.
	 */
	public function test_get_listen_data_includes_mbids(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_listen_track', 'Test' );
		update_post_meta( $post_id, '_postkind_listen_artist', 'Artist' );
		update_post_meta( $post_id, '_postkind_listen_album_mbid', 'album-mbid' );
		update_post_meta( $post_id, '_postkind_listen_artist_mbid', 'artist-mbid' );

		$data = $this->sync->test_get_listen_data_from_post( $post_id );

		$this->assertSame( 'album-mbid', $data['album_mbid'] );
		$this->assertSame( 'artist-mbid', $data['artist_mbid'] );
	}

	/**
	 * Test add_syndication_link stores meta.
	 */
	public function test_add_syndication_link(): void {
		$post_id = self::factory()->post->create();
		$this->sync->test_add_syndication_link( $post_id, 'https://testmusic.com/scrobble/1' );

		$this->assertSame(
			'https://testmusic.com/scrobble/1',
			get_post_meta( $post_id, '_postkind_syndication_testmusic', true )
		);
	}

	/**
	 * Test maybe_syndicate_listen skips non-publish.
	 */
	public function test_maybe_syndicate_skips_non_publish(): void {
		$post = self::factory()->post->create_and_get();

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_listen( 'draft', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_listen skips non-post type.
	 */
	public function test_maybe_syndicate_skips_non_post_type(): void {
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'page' ] );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_listen( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_listen skips non-listen posts.
	 */
	public function test_maybe_syndicate_skips_non_listen(): void {
		$post = self::factory()->post->create_and_get();

		update_option( 'post_kinds_indieweb_settings', [
			'listen_sync_to_testmusic' => true,
		] );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_listen( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_listen performs syndication.
	 */
	public function test_maybe_syndicate_performs_syndication(): void {
		$post_id = $this->create_listen_post();
		$post    = get_post( $post_id );

		update_option( 'post_kinds_indieweb_settings', [
			'listen_sync_to_testmusic' => true,
		] );
		update_post_meta( $post_id, '_postkind_listen_track', 'Test Song' );
		update_post_meta( $post_id, '_postkind_listen_artist', 'Test Artist' );

		$this->sync->syndication_result = [ 'id' => 'scr-42', 'url' => 'https://testmusic.com/42' ];
		$this->sync->maybe_syndicate_listen( 'publish', 'draft', $post );

		$this->assertCount( 1, $this->sync->syndicate_calls );
		$this->assertSame(
			'scr-42',
			get_post_meta( $post_id, '_postkind_listen_testmusic_id', true )
		);
	}

	/**
	 * Test maybe_syndicate skips when missing track/artist.
	 */
	public function test_maybe_syndicate_skips_missing_data(): void {
		$post_id = $this->create_listen_post();
		$post    = get_post( $post_id );

		update_option( 'post_kinds_indieweb_settings', [
			'listen_sync_to_testmusic' => true,
		] );
		// No track or artist meta set.

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_listen( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test add_syndication_target when connected.
	 */
	public function test_add_syndication_target_connected(): void {
		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayHasKey( 'testmusic', $targets );
		$this->assertSame( 'Test Music', $targets['testmusic']['name'] );
	}

	/**
	 * Test add_syndication_target when disconnected.
	 */
	public function test_add_syndication_target_disconnected(): void {
		$this->sync->connected = false;

		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayNotHasKey( 'testmusic', $targets );
	}
}

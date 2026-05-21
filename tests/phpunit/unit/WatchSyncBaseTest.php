<?php
/**
 * Test the Watch Sync Base class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Sync\Watch_Sync_Base;
use WP_UnitTestCase;

/**
 * Concrete test stub for the abstract Watch_Sync_Base.
 */
class Test_Watch_Sync extends Watch_Sync_Base {

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
		$this->service_id   = 'testvideo';
		$this->service_name = 'Test Video';
		parent::__construct();
	}

	public function is_connected(): bool {
		return $this->connected;
	}

	protected function syndicate_watch( int $post_id, array $watch_data ) {
		$this->syndicate_calls[] = [ 'post_id' => $post_id, 'data' => $watch_data ];
		return $this->syndication_result;
	}

	// Expose protected methods.
	public function test_is_watch_post( int $post_id ): bool {
		return $this->is_watch_post( $post_id );
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

	public function test_get_watch_data_from_post( int $post_id ): array {
		return $this->get_watch_data_from_post( $post_id );
	}

	public function test_add_syndication_link( int $post_id, string $url ): void {
		$this->add_syndication_link( $post_id, $url );
	}
}

/**
 * Test the Watch Sync Base class.
 *
 * @covers \PostKindsForIndieWeb\Sync\Watch_Sync_Base
 */
class WatchSyncBaseTest extends WP_UnitTestCase {

	/**
	 * Test sync instance.
	 *
	 * @var Test_Watch_Sync
	 */
	private Test_Watch_Sync $sync;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->sync = new Test_Watch_Sync();
	}

	/**
	 * Create a post with the watch kind taxonomy.
	 *
	 * @return int Post ID.
	 */
	private function create_watch_post(): int {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		if ( ! taxonomy_exists( 'kind' ) ) {
			register_taxonomy( 'kind', 'post' );
		}
		if ( ! term_exists( 'watch', 'kind' ) ) {
			wp_insert_term( 'watch', 'kind' );
		}

		wp_set_post_terms( $post_id, [ 'watch' ], 'kind' );
		return $post_id;
	}

	/**
	 * Test constructor sets service properties.
	 */
	public function test_constructor(): void {
		$this->assertSame( 'testvideo', $this->sync->get_service_id() );
		$this->assertSame( 'Test Video', $this->sync->get_service_name() );
	}

	/**
	 * Test is_watch_post returns true for watch posts.
	 */
	public function test_is_watch_post_true(): void {
		$post_id = $this->create_watch_post();
		$this->assertTrue( $this->sync->test_is_watch_post( $post_id ) );
	}

	/**
	 * Test is_watch_post returns false for other posts.
	 */
	public function test_is_watch_post_false(): void {
		$post_id = self::factory()->post->create();
		$this->assertFalse( $this->sync->test_is_watch_post( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled false without settings.
	 */
	public function test_is_syndication_enabled_false_no_settings(): void {
		$post_id = self::factory()->post->create();
		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled true with config.
	 */
	public function test_is_syndication_enabled_true(): void {
		$post_id = self::factory()->post->create();
		update_option( 'post_kinds_indieweb_settings', [
			'watch_sync_to_testvideo' => true,
		] );

		$this->assertTrue( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled false on post opt-out.
	 */
	public function test_is_syndication_enabled_false_opt_out(): void {
		$post_id = self::factory()->post->create();
		update_option( 'post_kinds_indieweb_settings', [
			'watch_sync_to_testvideo' => true,
		] );
		update_post_meta( $post_id, '_postkind_syndicate_testvideo', '0' );

		$this->assertFalse( $this->sync->test_is_syndication_enabled( $post_id ) );
	}

	/**
	 * Test is_syndication_enabled false when disconnected.
	 */
	public function test_is_syndication_enabled_false_disconnected(): void {
		$post_id = self::factory()->post->create();
		update_option( 'post_kinds_indieweb_settings', [
			'watch_sync_to_testvideo' => true,
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
		update_post_meta( $post_id, '_postkind_watch_testvideo_id', 'watch-123' );

		$this->assertTrue( $this->sync->test_is_already_syndicated( $post_id ) );
	}

	/**
	 * Test was_imported_from_service matching.
	 */
	public function test_was_imported_true(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_imported_from', 'testvideo' );

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
	 * Test get_watch_data_from_post for movie.
	 */
	public function test_get_watch_data_movie(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_watch_title', 'Inception' );
		update_post_meta( $post_id, '_postkind_watch_year', '2010' );
		update_post_meta( $post_id, '_postkind_watch_tmdb_id', '27205' );
		update_post_meta( $post_id, '_postkind_watch_imdb_id', 'tt1375666' );
		update_post_meta( $post_id, '_postkind_rating', '9' );

		$data = $this->sync->test_get_watch_data_from_post( $post_id );

		$this->assertSame( 'Inception', $data['title'] );
		$this->assertSame( '2010', $data['year'] );
		$this->assertSame( '27205', $data['tmdb_id'] );
		$this->assertSame( 'tt1375666', $data['imdb_id'] );
		$this->assertSame( '9', $data['rating'] );
		$this->assertSame( 'movie', $data['type'] );
	}

	/**
	 * Test get_watch_data_from_post for TV episode.
	 */
	public function test_get_watch_data_episode(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_watch_title', 'Ozymandias' );
		update_post_meta( $post_id, '_postkind_watch_season', '5' );
		update_post_meta( $post_id, '_postkind_watch_episode', '14' );
		update_post_meta( $post_id, '_postkind_watch_show_title', 'Breaking Bad' );

		$data = $this->sync->test_get_watch_data_from_post( $post_id );

		$this->assertSame( 'episode', $data['type'] );
		$this->assertSame( '5', $data['season'] );
		$this->assertSame( '14', $data['episode'] );
		$this->assertSame( 'Breaking Bad', $data['show_title'] );
	}

	/**
	 * Test get_watch_data detects rewatch.
	 */
	public function test_get_watch_data_rewatch(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_postkind_watch_title', 'Test Movie' );
		update_post_meta( $post_id, '_postkind_watch_rewatch', '1' );

		$data = $this->sync->test_get_watch_data_from_post( $post_id );

		$this->assertSame( '1', $data['is_rewatch'] );
	}

	/**
	 * Test add_syndication_link stores meta.
	 */
	public function test_add_syndication_link(): void {
		$post_id = self::factory()->post->create();
		$this->sync->test_add_syndication_link( $post_id, 'https://testvideo.com/watch/1' );

		$this->assertSame(
			'https://testvideo.com/watch/1',
			get_post_meta( $post_id, '_postkind_syndication_testvideo', true )
		);
	}

	/**
	 * Test maybe_syndicate_watch skips non-publish.
	 */
	public function test_maybe_syndicate_skips_non_publish(): void {
		$post = self::factory()->post->create_and_get();

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_watch( 'draft', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_watch skips non-post type.
	 */
	public function test_maybe_syndicate_skips_page(): void {
		$post = self::factory()->post->create_and_get( [ 'post_type' => 'page' ] );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_watch( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_watch performs syndication.
	 */
	public function test_maybe_syndicate_performs_syndication(): void {
		$post_id = $this->create_watch_post();
		$post    = get_post( $post_id );

		update_option( 'post_kinds_indieweb_settings', [
			'watch_sync_to_testvideo' => true,
		] );
		update_post_meta( $post_id, '_postkind_watch_title', 'Test Movie' );

		$this->sync->syndication_result = [ 'id' => 'watch-55', 'url' => 'https://testvideo.com/55' ];
		$this->sync->maybe_syndicate_watch( 'publish', 'draft', $post );

		$this->assertCount( 1, $this->sync->syndicate_calls );
		$this->assertSame(
			'watch-55',
			get_post_meta( $post_id, '_postkind_watch_testvideo_id', true )
		);
		$this->assertSame(
			'https://testvideo.com/55',
			get_post_meta( $post_id, '_postkind_syndication_testvideo', true )
		);
	}

	/**
	 * Test maybe_syndicate_watch skips missing title.
	 */
	public function test_maybe_syndicate_skips_missing_title(): void {
		$post_id = $this->create_watch_post();
		$post    = get_post( $post_id );

		update_option( 'post_kinds_indieweb_settings', [
			'watch_sync_to_testvideo' => true,
		] );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_watch( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test maybe_syndicate_watch skips already syndicated.
	 */
	public function test_maybe_syndicate_skips_already_syndicated(): void {
		$post_id = $this->create_watch_post();
		$post    = get_post( $post_id );

		update_option( 'post_kinds_indieweb_settings', [
			'watch_sync_to_testvideo' => true,
		] );
		update_post_meta( $post_id, '_postkind_watch_title', 'Test Movie' );
		update_post_meta( $post_id, '_postkind_watch_testvideo_id', 'already-done' );

		$this->sync->syndication_result = [ 'id' => 'ext-1' ];
		$this->sync->maybe_syndicate_watch( 'publish', 'draft', $post );

		$this->assertEmpty( $this->sync->syndicate_calls );
	}

	/**
	 * Test add_syndication_target when connected.
	 */
	public function test_add_syndication_target_connected(): void {
		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayHasKey( 'testvideo', $targets );
		$this->assertSame( 'Test Video', $targets['testvideo']['name'] );
	}

	/**
	 * Test add_syndication_target when disconnected.
	 */
	public function test_add_syndication_target_disconnected(): void {
		$this->sync->connected = false;

		$targets = $this->sync->add_syndication_target( [] );

		$this->assertArrayNotHasKey( 'testvideo', $targets );
	}
}

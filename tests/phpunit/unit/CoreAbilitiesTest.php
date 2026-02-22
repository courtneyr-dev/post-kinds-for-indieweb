<?php
/**
 * Test the Core Abilities provider class.
 *
 * @package PostKindsForIndieWeb
 */

namespace PostKindsForIndieWeb\Tests\Unit;

use PostKindsForIndieWeb\Abilities\Core_Abilities;
use PostKindsForIndieWeb\Taxonomy;
use PostKindsForIndieWeb\Meta_Fields;
use WP_UnitTestCase;

/**
 * Test the Core Abilities provider functionality.
 *
 * @covers \PostKindsForIndieWeb\Abilities\Core_Abilities
 */
class CoreAbilitiesTest extends WP_UnitTestCase {

	/**
	 * Core_Abilities instance.
	 *
	 * @var Core_Abilities
	 */
	private Core_Abilities $abilities;

	/**
	 * Taxonomy instance.
	 *
	 * @var Taxonomy
	 */
	private Taxonomy $taxonomy;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->abilities = Core_Abilities::instance();
		$this->taxonomy  = new Taxonomy();
		$this->taxonomy->register_taxonomy();
		$this->taxonomy->create_default_terms();
	}

	/**
	 * Test execute_list_kinds returns all kinds.
	 */
	public function test_execute_list_kinds_returns_all() {
		$result = $this->abilities->execute_list_kinds( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'kinds', $result );
		$this->assertCount( 24, $result['kinds'] );
		$this->assertSame( 24, $result['total'] );
	}

	/**
	 * Test execute_list_kinds contains 'note' kind.
	 */
	public function test_execute_list_kinds_contains_note() {
		$result = $this->abilities->execute_list_kinds( [] );
		$slugs  = array_column( $result['kinds'], 'slug' );

		$this->assertContains( 'note', $slugs );
	}

	/**
	 * Test execute_list_kind_fields for listen kind.
	 */
	public function test_execute_list_kind_fields_for_listen() {
		$result = $this->abilities->execute_list_kind_fields( [ 'kind' => 'listen' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'fields', $result );

		$keys = array_column( $result['fields'], 'key' );
		$this->assertContains( 'listen_track', $keys );
		$this->assertContains( 'listen_artist', $keys );
		$this->assertContains( 'listen_album', $keys );
	}

	/**
	 * Test execute_list_kind_fields for watch kind.
	 */
	public function test_execute_list_kind_fields_for_watch() {
		$result = $this->abilities->execute_list_kind_fields( [ 'kind' => 'watch' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'fields', $result );

		$keys = array_column( $result['fields'], 'key' );
		$this->assertContains( 'watch_title', $keys );
		$this->assertContains( 'watch_tmdb_id', $keys );
	}

	/**
	 * Test execute_list_kind_fields for unknown kind returns error.
	 */
	public function test_execute_list_kind_fields_unknown_kind() {
		$result = $this->abilities->execute_list_kind_fields( [ 'kind' => 'nonexistent' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test execute_create_post with listen kind.
	 */
	public function test_execute_create_post_with_listen_kind() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = $this->abilities->execute_create_post( [
			'kind'         => 'listen',
			'title'        => 'Test Listen Post',
			'content'      => 'Listening to a great track.',
			'status'       => 'draft',
			'listen_track' => 'Test Track',
			'listen_artist' => 'Test Artist',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'post_id', $result );

		$post_id = $result['post_id'];

		// Verify kind term assigned.
		$terms = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY );
		$this->assertNotEmpty( $terms );
		$this->assertSame( 'listen', $terms[0]->slug );

		// Verify meta set.
		$this->assertSame( 'Test Track', get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', true ) );
		$this->assertSame( 'Test Artist', get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_artist', true ) );
	}

	/**
	 * Test execute_set_kind.
	 */
	public function test_execute_set_kind() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = self::factory()->post->create();
		$result  = $this->abilities->execute_set_kind( [
			'post_id' => $post_id,
			'kind'    => 'bookmark',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );

		$terms = wp_get_post_terms( $post_id, Taxonomy::TAXONOMY );
		$this->assertNotEmpty( $terms );
		$this->assertSame( 'bookmark', $terms[0]->slug );
	}

	/**
	 * Test execute_get_kind.
	 */
	public function test_execute_get_kind() {
		$post_id = self::factory()->post->create();
		wp_set_post_terms( $post_id, [ 'listen' ], Taxonomy::TAXONOMY );

		$result = $this->abilities->execute_get_kind( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'listen', $result['kind_slug'] );
	}

	/**
	 * Test execute_update_post_meta.
	 */
	public function test_execute_update_post_meta() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$post_id = self::factory()->post->create();
		$result  = $this->abilities->execute_update_post_meta( [
			'post_id'    => $post_id,
			'meta_key'   => 'listen_track',
			'meta_value' => 'Updated Track Name',
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertTrue( $result['success'] );

		$this->assertSame(
			'Updated Track Name',
			get_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', true )
		);
	}

	/**
	 * Test execute_get_post_meta.
	 */
	public function test_execute_get_post_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_track', 'My Track' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'listen_artist', 'My Artist' );

		$result = $this->abilities->execute_get_post_meta( [
			'post_id'   => $post_id,
			'meta_keys' => [ 'listen_track', 'listen_artist' ],
		] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'meta', $result );
		$this->assertSame( 'My Track', $result['meta']['listen_track'] );
		$this->assertSame( 'My Artist', $result['meta']['listen_artist'] );
	}
}

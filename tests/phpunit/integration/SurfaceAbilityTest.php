<?php
/**
 * Surface read ability integration coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Taxonomy;

/**
 * @group integration
 */
final class SurfaceAbilityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->register_taxonomy();
		foreach ( [ 'article', 'checkin' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( ucfirst( $slug ), Taxonomy::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'pkiw_stream_kinds' );
		parent::tear_down();
	}

	public function test_stream_kind_returns_stream_details_and_cached_value(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$post_id = $this->post_with_kind( 'checkin' );
		update_post_meta( $post_id, '_pkiw_surface', 'main' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertSame( 'stream', $result['surface'] );
		$this->assertSame( 'main', $result['cached'] );
		$this->assertFalse( $result['promoted'] );
		$this->assertSame( [ 'checkin' ], $result['kinds'] );
		$this->assertSame( [ 'checkin' ], $result['stream_kinds'] );
	}

	public function test_promoted_stream_kind_returns_main(): void {
		add_filter( 'pkiw_stream_kinds', static fn() => [ 'checkin' ] );
		$post_id = $this->post_with_kind( 'checkin' );
		update_post_meta( $post_id, 'pkiw_promote', 1 );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'main', $result['surface'] );
		$this->assertTrue( $result['promoted'] );
	}

	public function test_no_stream_filter_returns_main_and_empty_stream_kinds(): void {
		$post_id = $this->post_with_kind( 'checkin' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'main', $result['surface'] );
		$this->assertSame( [], $result['stream_kinds'] );
	}

	public function test_published_post_is_readable_by_subscriber(): void {
		$post_id = $this->post_with_kind( 'article' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	public function test_draft_post_is_denied_to_subscriber(): void {
		$post_id = $this->post_with_kind( 'article', [ 'post_status' => 'draft' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability()->execute( [ 'post_id' => $post_id ] ) );
	}

	public function test_logged_out_request_is_denied(): void {
		$post_id = $this->post_with_kind( 'article' );
		wp_set_current_user( 0 );

		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability()->execute( [ 'post_id' => $post_id ] ) );
	}

	public function test_unknown_post_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertErrorCode( 'ability_invalid_permissions', $this->ability()->execute( [ 'post_id' => 999999 ] ) );
	}

	public function test_password_protected_post_is_forbidden_to_subscriber(): void {
		$post_id = $this->post_with_kind( 'checkin', [ 'post_password' => 'secret' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );

		$this->assertErrorCode( 'forbidden', $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_password_protected_post_is_visible_to_author_and_editor(): void {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id   = $this->post_with_kind(
			'checkin',
			[
				'post_author'   => $author_id,
				'post_password' => 'secret',
			]
		);

		foreach ( [ $author_id, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$result = $this->ability()->execute( [ 'post_id' => $post_id ] );
			$this->assertIsArray( $result );
			$this->assertSame( $post_id, $result['post_id'] );
			$this->assertSame( [ 'checkin' ], $result['kinds'] );
		}
	}

	public function test_post_without_kind_returns_empty_kinds(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$result = $this->ability()->execute( [ 'post_id' => $post_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['kinds'] );
	}

	/**
	 * Fetch the registered surface ability.
	 *
	 * @return WP_Ability
	 */
	private function ability() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'Abilities API not available on this WordPress version (needs 6.9+).' );
		}

		$ability = wp_get_ability( 'post-kinds/get-surface' );
		$this->assertNotNull( $ability, 'The post-kinds/get-surface ability must be registered.' );
		return $ability;
	}

	/**
	 * Create a published post and assign one kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $args Post overrides.
	 */
	private function post_with_kind( string $kind, array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[ 'post_status' => 'publish' ],
				$args
			)
		);
		wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
		return $post_id;
	}

	/**
	 * Assert a WP_Error has one exact code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Ability result.
	 */
	private function assertErrorCode( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}

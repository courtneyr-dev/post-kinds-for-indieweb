<?php
/**
 * Render coverage for the PHP-only Recent Kinds block.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * Renders post-kinds/recent-kinds through render_block() the way a template
 * does. Before #283 the render callback called the Taxonomy instance method
 * is_valid_kind() statically, which throws Error on PHP 8 for every render.
 *
 * @group integration
 */
final class RecentKindsBlockTest extends WP_UnitTestCase {

	/**
	 * Create a published post assigned to a kind.
	 *
	 * @param string $kind  Kind slug.
	 * @param string $title Post title.
	 */
	private function make_kind_post( string $kind, string $title ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);

		$result = wp_set_object_terms( $post_id, $kind, 'kind' );
		$this->assertNotWPError( $result );

		return $post_id;
	}

	/**
	 * Render the block with the given attributes.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function render( array $attrs ): string {
		return render_block(
			[
				'blockName'    => 'post-kinds/recent-kinds',
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * The block is registered on this WordPress version (7.0+).
	 */
	public function test_block_is_registered(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'post-kinds/recent-kinds' ) );
	}

	/**
	 * A valid kind renders that kind's posts inside the kind-modified wrapper.
	 */
	public function test_valid_kind_renders_wrapper_and_post(): void {
		$this->make_kind_post( 'watch', 'Recent watch probe' );

		$html = $this->render(
			[
				'kind'      => 'watch',
				'count'     => 5,
				'showCover' => false,
			]
		);

		$this->assertStringContainsString( 'pk-recent-kinds pk-recent-kinds--watch', $html );
		$this->assertStringContainsString( 'Recent watch probe', $html );
	}

	/**
	 * An unknown kind falls back to listen instead of querying a bogus term.
	 */
	public function test_invalid_kind_falls_back_to_listen(): void {
		$this->make_kind_post( 'listen', 'Recent listen probe' );

		$html = $this->render(
			[
				'kind'      => 'not-a-real-kind',
				'count'     => 5,
				'showCover' => false,
			]
		);

		$this->assertStringContainsString( 'pk-recent-kinds pk-recent-kinds--listen', $html );
		$this->assertStringContainsString( 'Recent listen probe', $html );
		$this->assertStringNotContainsString( 'not-a-real-kind', $html );
	}

	/**
	 * A valid kind with no published posts renders nothing, without throwing.
	 */
	public function test_valid_kind_with_no_posts_renders_empty(): void {
		// The term must exist for is_valid_kind(); the listen post proves the
		// empty result is not a silent fallback to listen.
		if ( ! term_exists( 'read', 'kind' ) ) {
			$this->assertNotWPError( wp_insert_term( 'Read', 'kind', [ 'slug' => 'read' ] ) );
		}
		$this->make_kind_post( 'listen', 'Unrelated listen post' );

		$html = $this->render(
			[
				'kind'      => 'read',
				'count'     => 5,
				'showCover' => true,
			]
		);

		$this->assertSame( '', $html );
	}

	/**
	 * A password-protected jam never lists: no title, track name, cover or
	 * link, including a password of spaces, which `post_password = ''`
	 * matches under the column's collation. The public jam's list, covers
	 * included, is byte-identical with and without them.
	 */
	public function test_protected_jam_never_lists(): void {
		global $wpdb;

		$public_id = $this->make_kind_post( 'jam', 'Public jam probe' );
		update_post_meta( $public_id, '_pkiw_jam_track', 'Public Track Probe' );
		update_post_meta( $public_id, '_pkiw_jam_cover', 'https://example.com/public-cover.jpg' );

		$attrs  = [
			'kind'      => 'jam',
			'count'     => 5,
			'showCover' => true,
		];
		$before = $this->render( $attrs );

		$locked = [];
		foreach ( [ 'secret', '   ' ] as $i => $password ) {
			$post_id = $this->make_kind_post( 'jam', "Locked jam probe {$i}" );
			// Write the password directly so a password of spaces survives as stored.
			$wpdb->update( $wpdb->posts, [ 'post_password' => $password ], [ 'ID' => $post_id ] );
			clean_post_cache( $post_id );
			update_post_meta( $post_id, '_pkiw_jam_track', "Locked Track Probe {$i}" );
			update_post_meta( $post_id, '_pkiw_jam_cover', "https://example.com/locked-cover-{$i}.jpg" );
			$this->assertSame( $password, get_post( $post_id )->post_password );
			$locked[] = $post_id;
		}

		$after = $this->render( $attrs );

		$this->assertStringContainsString( 'Public Track Probe', $after );
		$this->assertStringContainsString( 'public-cover.jpg', $after );
		$this->assertStringNotContainsString( 'Locked', $after );
		$this->assertStringNotContainsString( 'locked-cover', $after );
		foreach ( $locked as $post_id ) {
			$this->assertStringNotContainsString( (string) get_permalink( $post_id ), $after );
		}
		$this->assertSame( $before, $after );
	}
}

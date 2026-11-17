<?php
/** Wrapped post-meta synchronization coverage. @package PKIW */
declare(strict_types=1);

require_once __DIR__ . '/WrappedTestCase.php';

use PKIW\Meta_Fields;
use PKIW\Wrapped_Post;

/** @group integration */
final class WrappedPostTest extends WrappedTestCase {
	public function test_nested_block_saves_kind_and_year_meta(): void {
		$year    = (int) wp_date( 'Y' );
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-kinds-indieweb/wrapped {"kind":"read","year":' . $year . '} /--></div><!-- /wp:group -->';
		$id      = (int) self::factory()->post->create( [ 'post_status' => 'draft', 'post_content' => $content ] );
		$this->assertSame( 'read', get_post_meta( $id, Wrapped_Post::KIND_META, true ) );
		$this->assertSame( $year, (int) get_post_meta( $id, Wrapped_Post::YEAR_META, true ) );
	}

	public function test_unknown_out_of_range_zero_and_missing_year_delete_meta_and_render_nothing(): void {
		foreach ( [
			[ 'kind' => 'unknown', 'year' => 2026 ],
			[ 'kind' => 'read', 'year' => 1999 ],
			[ 'kind' => 'read', 'year' => (int) wp_date( 'Y' ) + 1 ],
			[ 'kind' => 'read', 'year' => 0 ],
			[ 'kind' => 'read' ],
		] as $attrs ) {
			$id = (int) self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<!-- wp:post-kinds-indieweb/wrapped ' . wp_json_encode( $attrs ) . ' /-->' ] );
			$this->assertSame( '', get_post_meta( $id, Wrapped_Post::KIND_META, true ), wp_json_encode( $attrs ) );
			$this->assertSame( '', get_post_meta( $id, Wrapped_Post::YEAR_META, true ), wp_json_encode( $attrs ) );
			$this->assertSame( '', do_blocks( (string) get_post_field( 'post_content', $id ) ), wp_json_encode( $attrs ) );
		}
	}

	public function test_removing_block_deletes_meta_and_restores_default_kind(): void {
		$id = $this->wrapped_post( 'read', 2026, [], [ 'post_status' => 'draft' ] );
		wp_update_post( [ 'ID' => $id, 'post_content' => '<!-- wp:paragraph --><p>Removed</p><!-- /wp:paragraph -->' ] );
		$this->assertSame( '', get_post_meta( $id, Wrapped_Post::KIND_META, true ) );
		$this->assertSame( '', get_post_meta( $id, Wrapped_Post::YEAR_META, true ) );
		$terms = wp_get_post_terms( $id, 'kind', [ 'fields' => 'ids' ] );
		$this->assertContains( (int) get_option( 'default_term_kind' ), $terms, 'Removing the Wrapped block must restore the configured default term; actual=' . print_r( $terms, true ) );
	}

	public function test_synced_pattern_is_unsupported_and_does_not_make_host_wrapped(): void {
		$ref = (int) self::factory()->post->create( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => '<!-- wp:post-kinds-indieweb/wrapped {"kind":"read","year":2026} /-->' ] );
		$id  = (int) self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<!-- wp:block {"ref":' . $ref . '} /-->' ] );
		$this->assertSame( '', get_post_meta( $id, Wrapped_Post::KIND_META, true ) );
		$this->assertSame( '', get_post_meta( $id, Wrapped_Post::YEAR_META, true ) );
		$this->assertSame( '', do_blocks( (string) get_post_field( 'post_content', $id ) ) );
	}

	public function test_wrapped_meta_is_private_and_outside_meta_fields_api(): void {
		Wrapped_Post::register_meta();
		$registered = get_registered_meta_keys( 'post', 'post' );
		foreach ( [ Wrapped_Post::KIND_META, Wrapped_Post::YEAR_META ] as $key ) {
			$this->assertArrayHasKey( $key, $registered );
			$this->assertFalse( $registered[ $key ]['show_in_rest'] );
			$this->assertFalse( ( new Meta_Fields() )->is_valid_field( $key ) );
			$this->assertFalse( ( new Meta_Fields() )->is_valid_field( substr( $key, strlen( '_pkiw_' ) ) ) );
		}
		$id = $this->wrapped_post();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
		$this->assertArrayNotHasKey( 'meta', array_intersect_key( (array) $response->get_data(), array_flip( [ Wrapped_Post::KIND_META, Wrapped_Post::YEAR_META ] ) ) );
		$this->assertArrayNotHasKey( Wrapped_Post::KIND_META, (array) ( $response->get_data()['meta'] ?? [] ) );
	}
}

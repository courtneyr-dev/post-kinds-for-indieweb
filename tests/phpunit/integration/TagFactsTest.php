<?php
/**
 * Public tag facts coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Tag_Archive;
use PKIW\Taxonomy;

/** @group integration */
final class TagFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	private function tag( array $post = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish' ], $post ) );
		wp_set_object_terms( $id, 'tag', Taxonomy::TAXONOMY );
		return (int) $id;
	}

	public function test_facts_have_the_frozen_shape_and_cardless_fallbacks(): void {
		$id = $this->tag( [ 'post_title' => 'Stored post title' ] );
		update_post_meta( $id, '_pkiw_cite_url', 'https://target.example/path' );
		update_post_meta( $id, '_pkiw_cite_name', 'Target title' );
		update_post_meta( $id, '_pkiw_cite_summary', 'Summary' );
		add_post_meta( $id, Tag_Archive::LABEL_KEY, 'First' );
		$facts = Tag_Archive::facts( $id );
		$this->assertSame(
			[ 'target_type', 'url', 'url_host', 'title', 'display_title', 'title_is_target', 'labels', 'label', 'description', 'image', 'image_id', 'image_alt', 'initial', 'tagged_iso', 'tagged_display', 'date_iso', 'has_card' ],
			array_keys( $facts )
		);
		$this->assertSame( 'Target title', $facts['display_title'] );
		$this->assertSame( 'target.example', $facts['url_host'] );
		$this->assertSame( 'First', $facts['label'] );
		$this->assertFalse( $facts['has_card'] );
	}

	public function test_every_ineligible_status_returns_no_facts_for_every_role(): void {
		$ids = [
			$this->tag( [ 'post_status' => 'draft' ] ),
			$this->tag( [ 'post_status' => 'private' ] ),
			$this->tag( [ 'post_status' => 'future', 'post_date' => '2030-01-01 00:00:00' ] ),
			$this->tag( [ 'post_password' => 'secret' ] ),
		];
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ), self::factory()->user->create( [ 'role' => 'administrator' ] ) ] as $user ) {
			wp_set_current_user( $user );
			foreach ( $ids as $id ) {
				$this->assertSame( [], Tag_Archive::facts( $id ) );
			}
		}
	}
}

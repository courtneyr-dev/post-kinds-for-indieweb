<?php
/**
 * Governed-kind Yoast sitemap coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Yoast_SEO;
use PKIW\Taxonomy;

/** @group integration */
final class YoastSeoTest extends WP_UnitTestCase {

	public function test_only_ineligible_tag_and_follow_ids_are_excluded(): void {
		( new Taxonomy() )->create_default_terms();
		$excluded = [];
		foreach ( [ 'tag', 'follow' ] as $kind ) {
			$public    = self::factory()->post->create( [ 'post_status' => 'publish' ] );
			$protected = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => 'secret' ] );
			wp_set_object_terms( $public, $kind, Taxonomy::TAXONOMY );
			wp_set_object_terms( $protected, $kind, Taxonomy::TAXONOMY );
			$excluded[] = $protected;
		}
		$this->assertSame( $excluded, ( new Yoast_SEO() )->exclude_hidden_kind_posts( [] ) );
	}
}

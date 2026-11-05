<?php
/**
 * The registered Yoast schema filter leaves a presentation graph unchanged.
 *
 * The file name sorts after YoastSeoIntegrationTest on purpose. That test asserts
 * WPSEO_VERSION is undefined, and this one defines it so Yoast_SEO::register() wires
 * its filters. A constant cannot be undefined, so this class has to run later.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Integrations\Yoast_SEO;

/**
 * @group integration
 */
final class ZzPresentationYoastSchemaTest extends WP_UnitTestCase {

	public function test_registered_yoast_filter_leaves_a_real_presentation_graph_unchanged(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', 'test' );
		}
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Talk' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'SENTINEL_LOCKED' );
		update_post_meta( $post_id, '_pkiw_presentation_event_url', 'https://example.com/SENTINEL_LOC' );
		$this->go_to( get_permalink( $post_id ) );

		$integration = new Yoast_SEO();
		$integration->register();
		$this->assertSame( 11, has_filter( 'wpseo_schema_graph', [ $integration, 'filter_schema_graph' ] ) );
		$graph   = [ [ '@type' => 'Article', 'headline' => 'Talk' ] ];
		$context = (object) [ 'main_image_url' => '', 'canonical' => get_permalink( $post_id ) ];
		try {
			$result = apply_filters( 'wpseo_schema_graph', $graph, $context );
		} finally {
			remove_filter( 'wpseo_schema_graph', [ $integration, 'filter_schema_graph' ], 11 );
			foreach ( [ 'wpseo_title', 'wpseo_opengraph_title', 'wpseo_twitter_title', 'wpseo_schema_graph' ] as $hook ) {
				remove_filter( $hook, [ PKIW\Title_Privacy::class, 'scrub_stored_title' ], 20 );
			}
		}
		$this->assertSame( $graph, $result );
		$json = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( '_pkiw_presentation_', $json );
		$this->assertStringNotContainsString( 'SENTINEL_LOCKED', $json );
		$this->assertStringNotContainsString( 'SENTINEL_LOC', $json );
	}
}

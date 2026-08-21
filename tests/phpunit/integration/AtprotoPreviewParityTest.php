<?php
/**
 * Preview-parity guard for ATmosphere's ?atproto projection.
 *
 * ATmosphere's record preview renders `the_content` during a singular GET.
 * The hidden mf2 markers this plugin adds on that filter belong to the
 * HTML page, not to derived record fields — publishing renders in a cron
 * context where `is_singular()` is false, so without a guard the preview
 * would carry marker text (a hidden p-name duplicates the title into
 * plain text) that the written record never has. These tests pin the
 * guard: mf2 output stays on ordinary singular views and disappears when
 * the `atproto` query var is present.
 *
 * @package PKIW
 * @group   atmosphere
 */

namespace PKIW\Tests\Integration;

use PKIW\Meta_Fields;

/**
 * Atproto preview-parity tests.
 */
class AtprotoPreviewParityTest extends \WP_UnitTestCase {

	/**
	 * Create a published RSVP post and enter its singular view.
	 *
	 * RSVP posts emit hidden mf2 data (the p-rsvp value) on the_content,
	 * which makes marker leakage directly observable.
	 *
	 * @return \WP_Post
	 */
	private function make_singular_rsvp(): \WP_Post {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Going to WordCamp',
				'post_content' => '<p>See you there.</p>',
				'post_status'  => 'publish',
			]
		);
		wp_set_object_terms( $post_id, 'rsvp', 'kind' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'rsvp_status', 'yes' );

		$this->go_to( get_permalink( $post_id ) );
		setup_postdata( get_post( $post_id ) );

		return get_post( $post_id );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_singular_view_still_carries_hidden_mf2_markers() {
		$post = $this->make_singular_rsvp();

		$rendered = apply_filters( 'the_content', $post->post_content );

		$this->assertStringContainsString( 'p-rsvp', $rendered );
	}

	public function test_atproto_preview_render_carries_no_mf2_markers() {
		$post = $this->make_singular_rsvp();

		// ATmosphere registers `atproto` as a public query var; simulate the
		// bare `?atproto` selector (empty string, distinct from absent).
		$GLOBALS['wp_query']->set( 'atproto', '' );

		$rendered = apply_filters( 'the_content', $post->post_content );

		$this->assertStringNotContainsString( 'p-rsvp', $rendered );
		$this->assertStringNotContainsString( 'pkiw-singular-entry', $rendered );
		$this->assertStringNotContainsString( 'u-url', $rendered );
		$this->assertStringContainsString( 'See you there.', $rendered );
	}

	public function test_named_atproto_selector_is_also_guarded() {
		$post = $this->make_singular_rsvp();

		$GLOBALS['wp_query']->set( 'atproto', 'site.standard.document' );

		$rendered = apply_filters( 'the_content', $post->post_content );

		$this->assertStringNotContainsString( 'p-rsvp', $rendered );
	}
}

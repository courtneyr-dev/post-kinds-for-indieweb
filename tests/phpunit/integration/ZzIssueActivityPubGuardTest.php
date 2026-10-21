<?php
/**
 * The issue facts list stays out of ActivityPub request content.
 *
 * The file name sorts after QuestionContentStepTest on purpose. That test asserts the
 * ActivityPub request function does not exist before it loads the same stand-in, and
 * the stand-in is a process-wide function that cannot be unloaded. A separate-process
 * test fails under this bootstrap because PHP deprecation output corrupts the child's
 * result.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Issue_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ZzIssueActivityPubGuardTest extends WP_UnitTestCase {

	public function tear_down(): void {
		unset( $GLOBALS['pkiw_test_activitypub_request'] );
		$this->go_to( home_url( '/' ) );
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	public function test_an_activitypub_request_leaves_content_unchanged(): void {
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Export drops the last row',
				'post_content' => '<p>Ticket body.</p>',
			]
		);
		wp_set_object_terms( $post_id, 'issue', Taxonomy::TAXONOMY );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'issue_number', '101' );
		$content = '<p>ActivityPub body.</p>';
		$this->go_to( (string) get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		require_once dirname( __DIR__ ) . '/fixtures/activitypub-request-stub.php';
		$GLOBALS['pkiw_test_activitypub_request'] = false;
		$this->assertStringContainsString( 'pkiw-issue-facts', Issue_Archive::decorate_content( $content ) );
		$GLOBALS['pkiw_test_activitypub_request'] = true;
		$this->assertSame( $content, Issue_Archive::decorate_content( $content ) );
	}
}

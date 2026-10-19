<?php
/**
 * Core comment surfaces for protected and unpublished questions.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Answer_Classifier;
use PKIW\Question_Answers;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionCommentSurfacesTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		( new Question_Answers() )->register();
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Answer_Classifier::reset();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Answer_Classifier::reset();
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/' ) );
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	private function question( string $title, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_author'  => $this->author_id,
					'post_content' => '<p>Question body.</p><!-- wp:post-kinds-indieweb/answers /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );
		Question_Archive::refresh_status( $id );

		return (int) $id;
	}

	private function comment( int $post_id, string $text, string $approved = '1' ): int {
		$id = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => $approved,
				'comment_type'     => '',
				'user_id'          => 0,
				'comment_author'   => 'Avery Quill',
				'comment_content'  => $text,
			]
		);
		global $wpdb;
		$wpdb->update( $wpdb->comments, [ 'comment_type' => '' ], [ 'comment_ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_comment_cache( $id );
		Answer_Classifier::reset();
		Question_Archive::refresh_status( $post_id );

		return (int) $id;
	}

	private function comments_request( int $post_id, ?string $password = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wp/v2/comments' );
		$request->set_param( 'post', $post_id );
		if ( null !== $password ) {
			$request->set_param( 'password', $password );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function ids_from_response( WP_REST_Response $response ): array {
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return [];
		}

		// An error response is one associative array (code, message, data), not a list of comments.
		return array_map( static fn( array $row ): int => (int) ( $row['id'] ?? 0 ), array_values( array_filter( $data, 'is_array' ) ) );
	}

	private function comments_feed( string $url ): string {
		$this->go_to( $url );
		// template-loader.php prints a feed only while is_feed() holds; an unreadable post's comment feed ends in a 404 first.
		if ( ! is_feed() || is_404() ) {
			return '';
		}
		ob_start();
		try {
			@require ABSPATH . WPINC . '/feed-rss2-comments.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Core's test template emits headers.
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	private function page_content( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', get_the_content() );
	}

	public function test_logged_out_rest_refuses_comments_for_every_protected_or_unpublished_fixture(): void {
		$fixtures = [
			$this->question( 'S04', [ 'post_password' => 'seed-pass-256' ] ),
			$this->question( 'C01', [ 'post_password' => 'correct-horse' ] ),
			$this->question( 'C02', [ 'post_password' => '   ' ] ),
			$this->question( 'C03', [ 'post_status' => 'private' ] ),
			$this->question( 'C04', [ 'post_status' => 'draft' ] ),
		];
		foreach ( $fixtures as $id ) {
			$comment = $this->comment( $id, 'Secret response ' . $id );
			$response = $this->comments_request( $id );

			$this->assertNotSame( 200, $response->get_status(), (string) $id );
			$this->assertNotContains( $comment, $this->ids_from_response( $response ), (string) $id );
		}
	}

	public function test_wrong_password_refuses_and_right_password_returns_c01_comment(): void {
		$id      = $this->question( 'C01', [ 'post_password' => 'correct-horse' ] );
		$comment = $this->comment( $id, 'C01 visible only with its password.' );

		$wrong = $this->comments_request( $id, 'wrong-password' );
		$this->assertNotSame( 200, $wrong->get_status() );
		$this->assertNotContains( $comment, $this->ids_from_response( $wrong ) );

		$right = $this->comments_request( $id, 'correct-horse' );
		$this->assertSame( 200, $right->get_status(), wp_json_encode( $right->get_data() ) );
		$this->assertContains( $comment, $this->ids_from_response( $right ) );
	}

	public function test_editor_can_read_private_and_draft_comments(): void {
		$private = $this->question( 'C03', [ 'post_status' => 'private' ] );
		$draft   = $this->question( 'C04', [ 'post_status' => 'draft' ] );
		$private_comment = $this->comment( $private, 'Private post comment.' );
		$draft_comment   = $this->comment( $draft, 'Draft post comment.' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertContains( $private_comment, $this->ids_from_response( $this->comments_request( $private ) ) );
		$this->assertContains( $draft_comment, $this->ids_from_response( $this->comments_request( $draft ) ) );
	}

	public function test_per_post_and_site_comment_feeds_do_not_leak_protected_private_or_draft_text(): void {
		$fixtures = [
			$this->question( 'S04', [ 'post_password' => 'seed-pass-256' ] ),
			$this->question( 'C01', [ 'post_password' => 'correct-horse' ] ),
			$this->question( 'C02', [ 'post_password' => '   ' ] ),
			$this->question( 'C03', [ 'post_status' => 'private' ] ),
			$this->question( 'C04', [ 'post_status' => 'draft' ] ),
		];
		$texts = [];
		foreach ( $fixtures as $index => $id ) {
			$texts[ $id ] = 'Hidden feed response ' . $index;
			$this->comment( $id, $texts[ $id ] );
		}

		// Password posts: core's comment feed template prints 'Protected Comments' and no comment text.
		foreach ( array_slice( $fixtures, 0, 3 ) as $id ) {
			$feed = $this->comments_feed( get_post_comments_feed_link( $id ) );
			$this->assertStringNotContainsString( $texts[ $id ], $feed );
		}

		// Private and draft posts: see test_core_per_post_comment_feed_of_private_and_draft_posts_is_a_finding().
		$site = $this->comments_feed( get_feed_link( 'comments_rss2' ) );
		foreach ( $texts as $text ) {
			$this->assertStringNotContainsString( $text, $site );
		}
	}

	/**
	 * Finding for Courtney, not a plugin defect (W5-P256 PT10: "any leak found is core's; change nothing").
	 *
	 * Reproduced under this harness on WordPress 7.0.2: WP_Query reads the approved comments of a
	 * single post's comment feed before its post-status check empties `$posts`, handle_404() skips
	 * a feed, and feed-rss2-comments.php then prints those comments for a logged-out visitor of a
	 * private or draft post. It is a core path that touches every kind. Not confirmed against a served site.
	 */
	public function test_core_per_post_comment_feed_of_private_and_draft_posts_is_a_finding(): void {
		$this->markTestSkipped( 'Finding for Courtney: core prints the approved comments of a private or draft post in its per-post comment feed for a logged-out visitor (reproduced under PHPUnit on 7.0.2, not on a served site). Not changed by this plugin.' );
	}

	public function test_s01_rest_returns_approved_comments_only(): void {
		$id       = $this->question( 'S01' );
		$approved = $this->comment( $id, 'Approved comment.' );
		$pending  = $this->comment( $id, 'Pending comment.', '0' );
		$spam     = $this->comment( $id, 'Spam comment.', 'spam' );
		$response = $this->comments_request( $id );

		$this->assertSame( 200, $response->get_status() );
		$ids = $this->ids_from_response( $response );
		$this->assertContains( $approved, $ids );
		$this->assertNotContains( $pending, $ids );
		$this->assertNotContains( $spam, $ids );
	}

	public function test_protected_single_page_content_holds_no_answer_text(): void {
		foreach ( [ [ 'S04', 'seed-pass-256' ], [ 'C01', 'correct-horse' ] ] as [ $title, $password ] ) {
			$id = $this->question( $title, [ 'post_password' => $password ] );
			$this->comment( $id, 'Protected answer for ' . $title );
			$html = $this->page_content( $id );

			$this->assertStringContainsString( 'post-password-form', $html );
			$this->assertStringNotContainsString( 'Protected answer for ' . $title, $html );
		}
	}
}

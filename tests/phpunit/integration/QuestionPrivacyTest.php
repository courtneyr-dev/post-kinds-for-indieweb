<?php
/**
 * Privacy boundaries for questions and their answers.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Answer_Classifier;
use PKIW\Meta_Fields;
use PKIW\Question_Answers;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionPrivacyTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Question_Archive() )->register();
		( new Question_Answers() )->register();
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Site Author' ] );
		Answer_Classifier::reset();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		Answer_Classifier::reset();
		set_query_var( 'atproto', null );
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/' ) );
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	private function question( array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Private surface fixture',
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

	private function answer( int $post_id, array $args = [] ): int {
		$id = self::factory()->comment->create(
			array_merge(
				[
					'comment_post_ID'      => $post_id,
					'comment_approved'     => '1',
					'comment_type'         => '',
					'user_id'              => 0,
					'comment_author'       => 'Avery Quill',
					'comment_author_url'   => 'https://a.example.net/',
					'comment_author_IP'    => '192.0.2.25',
					'comment_author_email' => 'avery@example.net',
					'comment_content'      => 'Answer words that belong only on the public single.',
				],
				$args
			)
		);
		if ( '' === ( $args['comment_type'] ?? '' ) ) {
			// wp_insert_comment() normalizes an empty type; IndieBlocks rows retain it.
			global $wpdb;
			$wpdb->update( $wpdb->comments, [ 'comment_type' => '' ], [ 'comment_ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			clean_comment_cache( $id );
		}
		Answer_Classifier::reset();
		Question_Archive::refresh_status( $post_id );

		return (int) $id;
	}

	private function rest_post( int $post_id ): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
	}

	private function singular_content( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', get_the_content() );
	}

	private function stream_card( int $post_id ): string {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.

		return \PKIW\render_stream_card();
	}

	private function render_post_feed( string $url ): string {
		$this->go_to( $url );
		ob_start();
		try {
			@require ABSPATH . WPINC . '/feed-rss2.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Core's test template emits headers.
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	public function test_logged_out_rest_hides_status_and_protected_content_and_refuses_private_rows(): void {
		$protected = [
			$this->question( [ 'post_title' => 'S04', 'post_password' => 'seed-pass-256' ] ),
			$this->question( [ 'post_title' => 'C01', 'post_password' => 'correct-horse' ] ),
			$this->question( [ 'post_title' => 'C02', 'post_password' => '   ' ] ),
		];
		foreach ( $protected as $id ) {
			$response = $this->rest_post( $id );
			$this->assertSame( 200, $response->get_status() );
			$data = (array) $response->get_data();
			$this->assertTrue( $data['content']['protected'] );
			$this->assertSame( '', $data['content']['rendered'] );
			$this->assertStringNotContainsString( Question_Archive::STATUS_KEY, (string) wp_json_encode( $data ) );
		}

		foreach ( [ $this->question( [ 'post_title' => 'C03', 'post_status' => 'private' ] ), $this->question( [ 'post_title' => 'C04', 'post_status' => 'draft' ] ) ] as $id ) {
			$response = $this->rest_post( $id );
			$this->assertNotSame( 200, $response->get_status() );
			$this->assertStringNotContainsString( Question_Archive::STATUS_KEY, (string) wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_abilities_never_read_or_write_the_status_key(): void {
		$id        = $this->question();
		$abilities = Core_Abilities::instance();
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$get = $abilities->execute_get_post_meta( [ 'post_id' => $id, 'meta_keys' => [ 'question_status' ] ] );
			$this->assertIsArray( $get );
			$this->assertArrayNotHasKey( 'question_status', $get['meta'] );
			$update = $abilities->execute_update_post_meta( [ 'post_id' => $id, 'meta_key' => 'question_status', 'meta_value' => 'answered' ] );
			$this->assertWPError( $update );
		}
	}

	public function test_status_words_in_derived_meta_do_not_make_question_content_searchable(): void {
		$answered = $this->question( [ 'post_title' => 'First fixture' ] );
		$this->answer( $answered );
		$waiting = $this->question( [ 'post_title' => 'Second fixture' ] );

		foreach ( [ 'answered' => $answered, 'waiting' => $waiting ] as $search => $id ) {
			$query = new WP_Query( [ 's' => $search, 'post_status' => 'publish', 'fields' => 'ids' ] );
			$this->assertNotContains( $id, $query->posts, $search );
		}
	}

	public function test_site_and_kind_feeds_hold_no_answer_text_name_or_url(): void {
		$id = $this->question( [ 'post_title' => 'Feed question' ] );
		$this->answer( $id );

		foreach ( [ home_url( '/feed/' ), home_url( '/kind/question/feed/' ) ] as $url ) {
			$feed = $this->render_post_feed( $url );
			foreach ( [ 'Answer words that belong only', 'Avery Quill', 'https://a.example.net/' ] as $private ) {
				$this->assertStringNotContainsString( $private, $feed, $url );
			}
		}
	}

	public function test_protected_questions_print_no_answers_and_have_no_public_facts(): void {
		foreach ( [ 'seed-pass-256', 'correct-horse' ] as $password ) {
			$id = $this->question( [ 'post_password' => $password, 'post_content' => '<p>Hidden question.</p><!-- wp:post-kinds-indieweb/answers /-->' ] );
			$this->answer( $id, [ 'comment_content' => 'Hidden answer.' ] );

			$this->assertStringNotContainsString( 'Hidden answer.', $this->singular_content( $id ) );
			$this->assertSame( [], Question_Archive::facts( $id ) );
		}
	}

	public function test_protected_stream_card_is_title_only(): void {
		$id = $this->question( [ 'post_title' => 'S04 protected title', 'post_password' => 'seed-pass-256', 'post_content' => '<p>Hidden question body.</p>' ] );
		$this->answer( $id, [ 'comment_content' => 'Hidden answer body.' ] );
		$html = $this->stream_card( $id );

		$this->assertStringContainsString( 'S04 protected title', $html );
		$this->assertStringContainsString( 'pk-card--protected', $html );
		foreach ( [ 'Hidden question body', 'Hidden answer body', 'pk-answer-count', 'pk-excerpt' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
	}

	public function test_atproto_projection_content_holds_no_answer_text(): void {
		$id = $this->question();
		$this->answer( $id );
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		set_query_var( 'atproto', 1 );
		$content = apply_filters( 'the_content', get_the_content() );

		$this->assertStringContainsString( 'Question body.', $content );
		$this->assertStringNotContainsString( 'Answer words that belong only', $content );
	}

	public function test_answer_markup_strips_remote_media_and_private_comment_fields(): void {
		$id = $this->question();
		$this->answer(
			$id,
			[
				'comment_content' => '<p>Safe answer.</p><img src="https://a.example.net/a.jpg"><picture><source srcset="https://a.example.net/a.webp"></picture><video src="https://a.example.net/a.mp4"></video><audio src="https://a.example.net/a.mp3"></audio><iframe src="https://a.example.net/"></iframe><object></object><embed><svg></svg><style>.x{}</style><script>alert(1)</script>',
			]
		);
		$html = $this->singular_content( $id );

		$this->assertStringContainsString( 'Safe answer.', $html );
		// The entry's own hidden author h-card carries the post author's Gravatar; the check is on the answers section.
		$this->assertSame( 1, preg_match( '#<section class="pkiw-answers".*?</section>#s', $html, $section ) );
		foreach ( [ '<img', '<picture', '<source', '<video', '<audio', '<iframe', '<object', '<embed', '<svg', '<style', '<script', 'avery@example.net', '192.0.2.25', 'avatar', 'gravatar' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $section[0] );
		}
		$this->assertStringNotContainsString( '<img', $html );
	}
}

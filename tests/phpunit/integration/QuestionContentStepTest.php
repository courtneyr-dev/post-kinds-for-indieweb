<?php
/**
 * Answers are appended only in the singular content step.
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
final class QuestionContentStepTest extends WP_UnitTestCase {

	private int $author_id;

	/** @var array<string, int> */
	private array $comments = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		( new Question_Answers() )->register();
		update_option( 'thread_comments', 1 );
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Site Author' ] );
		Answer_Classifier::reset();
	}

	public function tear_down(): void {
		set_query_var( 'atproto', null );
		Answer_Classifier::reset();
		$this->go_to( home_url( '/' ) );
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	private function post( string $kind = 'question', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'    => 'publish',
					'post_title'     => 'How do you name a new post kind?',
					'post_author'    => $this->author_id,
					'post_content'   => '<!-- wp:paragraph --><p>Dear readers, question body.</p><!-- /wp:paragraph --><!-- wp:post-kinds-indieweb/answers /-->',
					'post_excerpt'   => '',
					'comment_status' => 'open',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function comment( int $post_id, string $key, array $args = [], array $meta = [] ): int {
		$id = self::factory()->comment->create(
			array_merge(
				[
					'comment_post_ID'    => $post_id,
					'comment_approved'   => '1',
					'comment_type'       => '',
					'comment_parent'     => 0,
					'user_id'            => 0,
					'comment_author'     => 'Avery Quill',
					'comment_author_url' => 'https://a.example.net/',
					'comment_content'    => 'Unique ' . $key . ' text.',
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
		foreach ( $meta as $name => $value ) {
			add_comment_meta( $id, $name, $value );
		}
		$this->comments[ $key ] = (int) $id;

		return (int) $id;
	}

	private function s01(): int {
		$id = $this->post();
		$c1 = $this->comment( $id, 'c01' );
		$this->comment( $id, 'c02', [ 'comment_type' => 'comment', 'comment_author' => 'Bryn Alder', 'comment_author_url' => '' ], [ 'protocol' => 'webmention', 'webmention_source_url' => 'https://b.example.org/reply/1' ] );
		$this->comment( $id, 'c03', [ 'comment_parent' => $c1, 'user_id' => $this->author_id, 'comment_author' => 'Site Author', 'comment_author_url' => '' ] );
		$this->comment( $id, 'c07', [ 'user_id' => $this->author_id, 'comment_author' => 'Site Author', 'comment_author_url' => '' ] );
		Answer_Classifier::reset();

		return $id;
	}

	private function singular_content( int $post_id, ?string $content = null ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', null === $content ? (string) get_post_field( 'post_content', $post_id ) : $content );
	}

	public function test_question_content_is_wrapped_once_then_name_then_answers(): void {
		$id   = $this->s01();
		$html = $this->singular_content( $id );

		$this->assertSame( 1, substr_count( $html, 'pkiw-question-body e-content' ) );
		$this->assertSame( 1, substr_count( $html, 'class="p-name" hidden' ) );
		$this->assertSame( 1, substr_count( $html, 'class="pkiw-answers"' ) );
		$this->assertLessThan( strpos( $html, 'class="p-name" hidden' ), strpos( $html, 'pkiw-question-body e-content' ) );
		$this->assertLessThan( strpos( $html, 'class="pkiw-answers"' ), strpos( $html, 'class="p-name" hidden' ) );
	}

	public function test_existing_e_content_and_p_name_tokens_are_not_duplicated(): void {
		$id = $this->post(
			'question',
			[
				'post_content' => '<!-- wp:html --><div class="e-content"><p>Own body.</p><span class="p-name" hidden>Own name</span></div><!-- /wp:html --><!-- wp:post-kinds-indieweb/answers /-->',
			]
		);
		$html = $this->singular_content( $id );

		$this->assertSame( 1, substr_count( $html, 'e-content' ) );
		$this->assertSame( 1, substr_count( $html, 'class="p-name" hidden' ), 'The post author h-card keeps its own p-name; only the entry-level hidden name counts.' );
	}

	public function test_existing_question_body_makes_the_filter_idempotent(): void {
		$id    = $this->post();
		$first = $this->singular_content( $id );
		$again = Question_Answers::decorate_content( $first );

		$this->assertSame( $first, $again );
	}

	public function test_untitled_question_gets_no_hidden_name_and_keeps_p_question(): void {
		$id = $this->post(
			'question',
			[
				'post_title'   => '',
				'post_content' => '<p><span class="p-question">What do you do when a draft stops making sense?</span></p>',
			]
		);
		$html = $this->singular_content( $id );

		$this->assertStringContainsString( 'class="p-question"', $html );
		$this->assertStringNotContainsString( 'class="p-name" hidden', $html );
		$this->assertStringNotContainsString( 'Dear readers,', $html );
	}

	public function test_non_question_is_returned_byte_for_byte(): void {
		$id      = $this->post( 'note' );
		$content = "<p>Note bytes.\n  Keep spacing.</p>";
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );

		$this->assertSame( $content, Question_Answers::decorate_content( $content ) );
	}

	public function test_protected_question_content_is_unchanged_and_has_no_answers(): void {
		$id      = $this->post( 'question', [ 'post_password' => 'seed-pass-256' ] );
		$content = '<p>Protected words.</p>';
		$this->comment( $id, 'protected-answer' );

		$this->assertSame( $content, Question_Answers::decorate_content( $content ) );
		$this->assertStringNotContainsString( 'protected-answer', $this->singular_content( $id, $content ) );
	}

	public function test_feed_atproto_and_head_content_passes_are_unchanged(): void {
		$id      = $this->s01();
		$content = '<p>Projection body.</p>';
		$GLOBALS['post'] = get_post( $id );

		$feed = get_term_feed_link( get_term_by( 'slug', 'question', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY );
		$this->go_to( $feed );
		$this->assertSame( $content, Question_Answers::decorate_content( $content ) );

		$this->go_to( get_permalink( $id ) );
		set_query_var( 'atproto', '1' );
		$this->assertSame( $content, Question_Answers::decorate_content( $content ) );
		set_query_var( 'atproto', null );

		$seen  = '';
		$probe = static function () use ( &$seen, $content ): void {
			$seen = Question_Answers::decorate_content( $content );
		};
		// Core's own wp_head callbacks (global styles) trip incorrect-usage notices in a bare test, so only the probe runs; the hooks come back after the test.
		remove_all_actions( 'wp_head' );
		add_action( 'wp_head', $probe );
		do_action( 'wp_head' );
		$this->assertSame( $content, $seen );
	}

	public function test_markdown_alternate_request_content_holds_no_answer_text(): void {
		// markdown-alternate renders `apply_filters( 'the_content', $post->post_content )` on a singular request.
		$id      = $this->s01();
		$content = '<p>Markdown body.</p>';
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );

		foreach ( [ 'markdown_request' => '1', 'format' => 'markdown' ] as $var => $value ) {
			set_query_var( $var, $value );
			$this->assertSame( $content, Question_Answers::decorate_content( $content ), $var );
			set_query_var( $var, '' );
		}
		$this->assertStringContainsString( 'pkiw-answers', Question_Answers::decorate_content( $content ), 'The same request without the markdown vars still prints the section.' );
	}

	public function test_get_the_excerpt_contains_no_answer_text(): void {
		$id = $this->s01();
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );

		$excerpt = get_the_excerpt( get_post( $id ) );
		$this->assertStringNotContainsString( 'Unique c01 text', $excerpt );
		$this->assertStringNotContainsString( 'Unique c02 text', $excerpt );
		$this->assertStringNotContainsString( 'pkiw-answers', $excerpt );
	}

	public function test_rest_content_rendered_has_no_answer_section_or_answer_text(): void {
		$id = $this->s01();
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$response = rest_get_server()->dispatch( $request );
		$rendered = (string) $response->get_data()['content']['rendered'];

		$this->assertStringNotContainsString( 'pkiw-answers', $rendered );
		$this->assertStringNotContainsString( 'Unique c01 text', $rendered );
	}

	public function test_attributes_for_reads_first_nested_answers_block_and_applies_filter(): void {
		$id = $this->post(
			'question',
			[
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-kinds-indieweb/answers {"headingLabel":"Replies","headingLevel":3,"responseLinkLabel":"Respond"} /--></div><!-- /wp:group --><!-- wp:post-kinds-indieweb/answers {"headingLabel":"Ignored"} /-->',
			]
		);
		$filter = static function ( array $attrs ): array { $attrs['responseLinkLabel'] = 'Filtered response'; return $attrs; };
		add_filter( 'pkiw_answers_attributes', $filter );
		$attrs = Question_Answers::attributes_for( get_post( $id ) );
		remove_filter( 'pkiw_answers_attributes', $filter );

		$this->assertSame( 'Replies', $attrs['headingLabel'] );
		$this->assertSame( 3, $attrs['headingLevel'] );
		$this->assertSame( 'Filtered response', $attrs['responseLinkLabel'] );
	}

	public function test_post_without_block_uses_defaults(): void {
		$id = $this->post( 'question', [ 'post_content' => '<p>Older question.</p>' ] );

		$this->assertSame( [ 'headingLabel' => 'Answers', 'headingLevel' => 2, 'responseLinkLabel' => 'Leave an answer' ], Question_Answers::attributes_for( get_post( $id ) ) );
	}

	public function test_every_comment_text_occurs_once_across_answers_and_core_list(): void {
		$id      = $this->s01();
		$content = $this->singular_content( $id );
		$query   = new WP_Comment_Query( [ 'post_id' => $id, 'status' => 'approve', 'hierarchical' => 'threaded' ] );
		$core    = wp_list_comments( [ 'echo' => false ], $query->comments );
		$page    = $content . $core;

		foreach ( [ 'c01', 'c02', 'c03', 'c07' ] as $key ) {
			$this->assertSame( 1, substr_count( $page, 'Unique ' . $key . ' text.' ), $key );
		}
	}

	public function test_answer_count_helper_is_not_a_second_source_of_truth(): void {
		$id = $this->s01();

		$this->assertSame( Answer_Classifier::count( $id ), Question_Answers::answer_count_for( $id ) );
	}
}

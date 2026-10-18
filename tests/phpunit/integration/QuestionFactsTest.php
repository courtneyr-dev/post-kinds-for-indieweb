<?php
/**
 * Facts exposed for question archives and Stream cards.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Answer_Classifier;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		Answer_Classifier::reset();
		update_option( 'timezone_string', 'America/New_York' );
	}

	public function tear_down(): void {
		Answer_Classifier::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function question( array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'How do you name a new post kind?',
					'post_content' => '<!-- wp:paragraph --><p>Question body.</p><!-- /wp:paragraph -->',
					'post_date'    => '2026-09-04 10:30:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	public function test_facts_have_the_frozen_keys_and_values_for_a_titled_waiting_question(): void {
		$id    = $this->question();
		$facts = Question_Archive::facts( $id );

		$this->assertSame( [ 'name', 'name_is_title', 'status', 'answer_count', 'date_iso', 'day_short' ], array_keys( $facts ) );
		$this->assertSame( 'How do you name a new post kind?', $facts['name'] );
		$this->assertTrue( $facts['name_is_title'] );
		$this->assertSame( 'waiting', $facts['status'] );
		$this->assertSame( 0, $facts['answer_count'] );
		$this->assertSame( 'Sep 4', $facts['day_short'] );
		$this->assertSame( get_post_time( 'c', true, get_post( $id ) ), $facts['date_iso'] );
	}

	public function test_answer_count_and_status_come_from_the_classifier(): void {
		$id = $this->question();
		self::factory()->comment->create( [ 'comment_post_ID' => $id, 'comment_approved' => '1', 'comment_type' => '' ] );
		Answer_Classifier::reset();

		$facts = Question_Archive::facts( $id );
		$this->assertSame( Answer_Classifier::count( $id ), $facts['answer_count'] );
		$this->assertSame( 'answered', $facts['status'] );
	}

	public function test_untitled_micropub_question_uses_its_question_text_without_pretending_it_is_a_title(): void {
		$id = $this->question(
			[
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => '<p><span class="p-question">What do you do when a draft stops making sense?</span></p>',
			]
		);

		$facts = Question_Archive::facts( $id );
		$this->assertSame( 'What do you do when a draft stops making sense?', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
	}

	public function test_untitled_pattern_question_skips_the_leading_salutation_and_trims_to_twenty_five_words(): void {
		$words   = array_map( static fn( int $n ): string => 'word' . $n, range( 1, 30 ) );
		$content = '<!-- wp:paragraph {"className":"is-style-cr-salutation"} --><p class="is-style-cr-salutation">Dear readers,</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>' . implode( ' ', $words ) . '</p><!-- /wp:paragraph -->';
		$id = $this->question( [ 'post_title' => '', 'post_excerpt' => '', 'post_content' => $content ] );

		$facts = Question_Archive::facts( $id );
		$this->assertSame( implode( ' ', array_slice( $words, 0, 25 ) ) . '…', $facts['name'] );
		$this->assertStringNotContainsString( 'Dear readers', $facts['name'] );
		$this->assertFalse( $facts['name_is_title'] );
	}

	public function test_a_first_block_that_is_not_the_salutation_stays_in_the_thought(): void {
		$content = '<!-- wp:paragraph {"className":"ordinary"} --><p class="ordinary">Keep this first paragraph.</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p>Then this one.</p><!-- /wp:paragraph -->';
		$id = $this->question( [ 'post_title' => '', 'post_excerpt' => '', 'post_content' => $content ] );

		$this->assertStringStartsWith( 'Keep this first paragraph.', Question_Archive::thought( get_post( $id ) ) );
	}

	public function test_excerpt_wins_over_content_for_the_synthetic_name(): void {
		$id = $this->question( [ 'post_title' => '', 'post_excerpt' => 'Question from excerpt.', 'post_content' => '<p>Ignored body.</p>' ] );

		$this->assertSame( 'Question from excerpt.', Question_Archive::facts( $id )['name'] );
	}

	public function test_empty_thought_falls_back_to_the_untitled_name_contract(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_false' );
		$id = $this->question( [ 'post_title' => '', 'post_excerpt' => '', 'post_content' => '' ] );
		remove_filter( 'wp_insert_post_empty_content', '__return_false' );

		$this->assertSame( \PKIW\untitled_name( get_post( $id ), false ), Question_Archive::facts( $id )['name'] );
	}

	public function test_non_question_protected_private_and_draft_posts_have_no_facts_for_a_visitor(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$protected = $this->question( [ 'post_password' => 'seed-pass-256' ] );
		$private   = $this->question( [ 'post_status' => 'private' ] );
		$draft     = $this->question( [ 'post_status' => 'draft' ] );
		wp_set_current_user( 0 );

		foreach ( [ $note, $protected, $private, $draft ] as $id ) {
			$this->assertSame( [], Question_Archive::facts( $id ), 'Fixture ' . $id );
		}
	}
}

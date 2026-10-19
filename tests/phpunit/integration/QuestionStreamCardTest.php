<?php
/**
 * Question cards on the Stream.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Answer_Classifier;
use PKIW\Grouped_Archive;
use PKIW\Question_Answers;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionStreamCardTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		( new Question_Answers() )->register();
		Answer_Classifier::reset();
		update_option( 'timezone_string', 'America/New_York' );
	}

	public function tear_down(): void {
		Answer_Classifier::reset();
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
					'post_title'   => 'How do you name a new post kind?',
					'post_content' => '<!-- wp:paragraph --><p>A question body with enough text to make a useful summary for the Stream card without copying any answer text into it.</p><!-- /wp:paragraph --><!-- wp:post-kinds-indieweb/answers /-->',
					'post_excerpt' => '',
					'post_date'    => '2026-09-04 10:30:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );
		Question_Archive::refresh_status( $id );

		return (int) $id;
	}

	private function answer( int $post_id, string $content = 'A third-party answer.' ): int {
		$id = self::factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => '',
				'user_id'          => 0,
				'comment_content'  => $content,
			]
		);
		global $wpdb;
		$wpdb->update( $wpdb->comments, [ 'comment_type' => '' ], [ 'comment_ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_comment_cache( $id );
		Answer_Classifier::reset();
		Question_Archive::refresh_status( $post_id );

		return (int) $id;
	}

	private function render( int $post_id, array $attributes = [] ): string {
		$GLOBALS['post'] = get_post( $post_id );

		return \PKIW\render_stream_card( $attributes );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	public function test_question_card_has_the_frozen_root_and_one_visible_link(): void {
		$id    = $this->question();
		$html  = $this->render( $id );
		$xpath = $this->xpath( $html );

		$this->assertStringStartsWith( '<article class="pk-card pk-card--stream k-question h-entry">', $html );
		$this->assertMatchesRegularExpression( '/<article\b[^>]*class="[^"]*\bpk-card\b[^"]*\bh-entry\b/', $html );
		$this->assertSame( 1, $xpath->query( '//a[@href][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( 0, $xpath->query( '//a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( get_permalink( $id ), $xpath->query( '//h2[contains(@class,"pk-title")]/a[@class="u-url"]' )->item( 0 )->getAttribute( 'href' ) );
	}

	public function test_card_order_is_label_title_day_count_then_optional_excerpt(): void {
		$html  = $this->render( $this->question( [ 'post_excerpt' => 'A short authored excerpt.' ] ) );
		$order = [ 'pk-kindlabel', 'pk-title', 'pk-stream-date', 'pk-answer-count', 'pk-excerpt' ];
		$last  = -1;
		foreach ( $order as $token ) {
			$position = strpos( $html, $token );
			$this->assertNotFalse( $position, $token );
			$this->assertGreaterThan( $last, $position, $token );
			$last = $position;
		}
	}

	public function test_card_cuts_badge_media_secondary_link_icon_and_read_more(): void {
		$html = $this->render( $this->question() );

		foreach ( [ 'pk-badge', 'pk-media', 'pk-link', 'Read more', '<svg' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
	}

	public function test_thirty_one_word_excerpt_trims_to_twenty_words_and_an_ellipsis(): void {
		$words = array_map( static fn( int $n ): string => 'word' . $n, range( 1, 31 ) );
		$html  = $this->render( $this->question( [ 'post_excerpt' => implode( ' ', $words ) ] ) );
		$text  = implode( ' ', array_slice( $words, 0, 20 ) ) . '…';

		$this->assertStringContainsString( '<p class="pk-excerpt p-summary">' . $text . '</p>', $html );
		$this->assertStringNotContainsString( 'word21', $html );
	}

	public function test_untitled_card_has_no_p_name_and_no_excerpt(): void {
		$id = $this->question(
			[
				'post_title'   => '',
				'post_excerpt' => '',
				'post_content' => '<p><span class="p-question">What do you do when a draft stops making sense?</span></p>',
			]
		);
		$html = $this->render( $id );

		$this->assertStringContainsString( '>What do you do when a draft stops making sense?</a>', $html );
		$this->assertStringNotContainsString( 'pk-title p-name', $html );
		$this->assertStringNotContainsString( 'pk-excerpt', $html );
	}

	public function test_untitled_card_with_a_written_excerpt_names_itself_from_it_and_prints_it_once(): void {
		$excerpt = 'The written excerpt is the name an untitled question takes.';
		$id      = $this->question(
			[
				'post_title'   => '',
				'post_excerpt' => $excerpt,
				'post_content' => '<p>Body text that is not the name.</p>',
			]
		);
		$html = $this->render( $id );

		$this->assertSame( 1, substr_count( $html, $excerpt ) );
		$this->assertStringNotContainsString( 'pk-title p-name', $html );
		$this->assertStringNotContainsString( 'pk-excerpt', $html );
	}

	public function test_day_and_datetime_come_from_the_same_stored_post_date(): void {
		$id    = $this->question();
		$html  = $this->render( $id );
		$xpath = $this->xpath( $html );
		$time  = $xpath->query( '//p[contains(@class,"pk-stream-date")]/time[@class="dt-published"]' )->item( 0 );

		$this->assertSame( 'Sep 4', trim( $time->textContent ) );
		$this->assertSame( get_post_time( 'c', true, get_post( $id ) ), $time->getAttribute( 'datetime' ) );
	}

	public function test_answer_count_pluralization_uses_classifier_for_zero_one_and_two(): void {
		$id = $this->question();
		$this->assertStringContainsString( '<p class="pk-sub pk-answer-count">0 answers</p>', $this->render( $id ) );
		$this->answer( $id, 'First answer.' );
		$this->assertStringContainsString( '<p class="pk-sub pk-answer-count">1 answer</p>', $this->render( $id ) );
		$this->answer( $id, 'Second answer.' );
		$html = $this->render( $id );

		$this->assertStringContainsString( '<p class="pk-sub pk-answer-count">2 answers</p>', $html );
		$this->assertSame( 2, Answer_Classifier::count( $id ) );
	}

	public function test_s01_answer_count_matches_the_row_facts_archive_single_and_stream(): void {
		$id = $this->question();
		$this->answer( $id, 'First S01 answer.' );
		$this->answer( $id, 'Second S01 answer.' );

		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );
		$single = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		$card   = $this->render( $id );

		$this->assertSame( 2, Answer_Classifier::count( $id ) );
		$this->assertSame( 'answered', get_post_meta( $id, Question_Archive::STATUS_KEY, true ) );
		$this->assertSame( 2, Question_Archive::facts( $id )['answer_count'] );
		$this->assertSame( 'answered', Grouped_Archive::group_of_post( 'question', $id )->key() );
		$this->assertStringContainsString( 'Answers 2', wp_strip_all_tags( $single ) );
		$this->assertStringContainsString( '>2 answers</p>', $card );
	}

	public function test_stream_card_returns_first_argument_unchanged_for_a_non_question(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Note' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$before = '<article class="sentinel">Earlier callback</article>';

		$this->assertSame( $before, Question_Archive::stream_card( $before, get_post( $note ), 2, [] ) );
	}

	public function test_protected_question_stays_on_the_shared_title_only_path(): void {
		$id   = $this->question( [ 'post_password' => 'seed-pass-256', 'post_content' => '<p>Hidden question.</p>' ] );
		$this->answer( $id, 'Hidden answer.' );
		$html = $this->render( $id );

		$this->assertStringContainsString( 'pk-card--protected', $html );
		$this->assertStringNotContainsString( 'Hidden question', $html );
		$this->assertStringNotContainsString( 'Hidden answer', $html );
		$this->assertStringNotContainsString( 'pk-answer-count', $html );
	}

	public function test_heading_level_follows_the_block_attribute(): void {
		$html = $this->render( $this->question(), [ 'headingLevel' => 4 ] );

		$this->assertStringContainsString( '<h4 class="pk-title p-name">', $html );
		$this->assertStringNotContainsString( '<h2 class="pk-title', $html );
	}

	public function test_block_renderer_rest_preview_uses_the_same_question_card(): void {
		$id = $this->question();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/post-kinds-indieweb/stream-card' );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $id );
		$request->set_param( 'attributes', [ 'headingLevel' => 3 ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$html = (string) $response->get_data()['rendered'];
		$this->assertStringContainsString( 'pk-card pk-card--stream k-question h-entry', $html );
		$this->assertStringContainsString( '<h3 class="pk-title p-name">', $html );
	}

	public function test_answer_text_never_enters_the_excerpt(): void {
		$id = $this->question();
		$this->answer( $id, 'Unique answer text for S01.' );
		$html = $this->render( $id );

		$this->assertStringNotContainsString( 'Unique answer text for S01', $html );
	}
}

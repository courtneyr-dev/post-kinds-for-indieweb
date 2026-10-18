<?php
/**
 * Parsed microformats for question singles and answers.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Answer_Classifier;
use PKIW\Micropub_Content_Builder;
use PKIW\Question_Answers;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionMicroformatsTest extends WP_UnitTestCase {

	private int $author_id;

	/** @var array<string, int> */
	private array $comments = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		( new Question_Answers() )->register();
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Site Author' ] );
		update_option( 'thread_comments', 1 );
		Answer_Classifier::reset();
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
					'post_author'  => $this->author_id,
					'post_content' => '<p>Question body.</p><!-- wp:post-kinds-indieweb/answers /-->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );

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
					'comment_content'    => 'Text for ' . $key . '.',
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
		$id = $this->question();
		$c1 = $this->comment( $id, 'c01' );
		$this->comment( $id, 'c02', [ 'comment_type' => 'comment', 'comment_author' => 'Bryn Alder', 'comment_author_url' => '' ], [ 'protocol' => 'webmention', 'webmention_source_url' => 'https://b.example.org/reply/1' ] );
		$this->comment( $id, 'c03', [ 'comment_parent' => $c1, 'user_id' => $this->author_id, 'comment_author' => 'Site Author', 'comment_author_url' => '' ] );
		Answer_Classifier::reset();

		return $id;
	}

	private function content( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) );
	}

	/** @return array<string, mixed> */
	private function entry( string $html, int $post_id ): array {
		$parsed = \Mf2\parse( $html, get_permalink( $post_id ) );
		$entries = array_values( array_filter( $parsed['items'] ?? [], static fn( array $item ): bool => in_array( 'h-entry', $item['type'] ?? [], true ) ) );
		$this->assertCount( 1, $entries, 'One top-level h-entry.' );

		return $entries[0];
	}

	public function test_full_s01_single_parses_one_entry_with_name_content_published_author_and_two_comments(): void {
		$id         = $this->s01();
		$html       = $this->content( $id );
		$entry      = $this->entry( $html, $id );
		$properties = $entry['properties'];

		$this->assertSame( [ 'How do you name a new post kind?' ], $properties['name'] );
		$this->assertCount( 1, $properties['content'] );
		$this->assertStringContainsString( 'Question body.', $properties['content'][0]['value'] );
		$this->assertCount( 1, $properties['published'] );
		$this->assertSame( [ 'Site Author' ], $properties['author'][0]['properties']['name'] );
		$this->assertCount( 2, $properties['comment'] );
		foreach ( $properties['comment'] as $comment ) {
			$this->assertSame( [ 'h-cite' ], $comment['type'] );
			foreach ( [ 'author', 'published', 'url', 'content' ] as $property ) {
				$this->assertArrayHasKey( $property, $comment['properties'] );
			}
		}
	}

	public function test_c03_is_nested_as_a_comment_of_c01_not_an_orphan_top_level_cite(): void {
		$id         = $this->s01();
		$html       = $this->content( $id );
		$entry      = $this->entry( $html, $id );
		$comments   = $entry['properties']['comment'];
		$c01        = array_values( array_filter( $comments, fn( array $comment ): bool => str_contains( (string) ( $comment['properties']['content'][0]['value'] ?? '' ), 'Text for c01.' ) ) )[0];
		$nested     = $c01['properties']['comment'] ?? [];

		$this->assertCount( 1, $nested );
		$this->assertStringContainsString( 'Text for c03.', $nested[0]['properties']['content'][0]['value'] );
		$this->assertCount( 0, array_filter( $entry['children'] ?? [], static fn( array $item ): bool => in_array( 'h-cite', $item['type'] ?? [], true ) ) );
	}

	public function test_theme_post_class_root_gets_decorated_content_without_nested_entry(): void {
		$id = $this->s01();
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id );
		setup_postdata( $GLOBALS['post'] );
		$classes = implode( ' ', get_post_class( '', $id ) );
		$inner   = apply_filters( 'the_content', (string) get_post_field( 'post_content', $id ) );
		$html    = '<article class="' . esc_attr( $classes ) . '">' . $inner . '</article>';

		$this->assertSame( 1, substr_count( $html, 'h-entry' ) );
		$entry = $this->entry( $html, $id );
		$this->assertSame( [ 'How do you name a new post kind?' ], $entry['properties']['name'] );
		$this->assertCount( 2, $entry['properties']['comment'] );
	}

	public function test_titled_question_gets_hidden_name_and_untitled_question_gets_none(): void {
		$titled = $this->question();
		$this->assertStringContainsString( '<span class="p-name" hidden>How do you name a new post kind?</span>', $this->content( $titled ) );

		$untitled = $this->question( [ 'post_title' => '', 'post_content' => '<p><span class="p-question">What now?</span></p>' ] );
		$html     = $this->content( $untitled );
		$entry    = $this->entry( $html, $untitled );
		$this->assertArrayNotHasKey( 'name', $entry['properties'] );
	}

	public function test_micropub_question_keeps_p_question_inside_e_content(): void {
		$micropub = Micropub_Content_Builder::fill_empty_content( '', [ 'question' => [ 'What do you do when a draft stops making sense?' ] ] );
		$id       = $this->question( [ 'post_title' => '', 'post_content' => $micropub ] );
		$html     = $this->content( $id );
		$entry    = $this->entry( $html, $id );

		$this->assertStringContainsString( 'class="p-question"', $html );
		$this->assertStringContainsString( 'What do you do when a draft stops making sense?', $entry['properties']['content'][0]['value'] );
	}
}

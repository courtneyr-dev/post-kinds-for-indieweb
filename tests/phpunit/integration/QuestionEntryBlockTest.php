<?php
/**
 * The question archive entry block.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionEntryBlockTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Entry Author' ] );
	}

	private function question( array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'How do you name a new post kind?',
					'post_content' => '<p>Question body.</p>',
					'post_excerpt' => '',
					'post_author'  => $this->author_id,
					'post_date'    => '2026-09-04 10:00:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="scope">' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	public function test_the_block_registers_in_php_with_the_frozen_schema_and_assets(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Question_Archive::ENTRY_BLOCK );

		$this->assertNotNull( $block );
		$this->assertTrue( Grouped_Archive::is_entry_block( Question_Archive::ENTRY_BLOCK ) );
		$this->assertSame( 3, $block->api_version );
		$this->assertContains( 'pkiw-question-entry-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-kind-layouts', $block->style_handles );
		$this->assertSame( 12, $block->attributes['linesPerPage']['default'] );
		$this->assertSame( 'content', $block->attributes['groupLabels']['role'] );
		$this->assertSame( 'content', $block->attributes['emptyLabel']['role'] );
		$this->assertFalse( $block->supports['html'] );
		$this->assertFalse( $block->supports['reusable'] );
		$this->assertSame( [ 'postId', 'postType', 'queryId', 'templateSlug' ], $block->uses_context );
	}

	public function test_editor_script_receives_both_status_labels_before_the_script(): void {
		$before = wp_scripts()->get_data( 'pkiw-question-entry-editor', 'before' );
		$inline = implode( "\n", is_array( $before ) ? $before : [] );

		$this->assertStringContainsString( 'window.pkiwQuestionEntry', $inline );
		$this->assertStringContainsString( 'Answered', $inline );
		$this->assertStringContainsString( 'Waiting for an answer', $inline );
	}

	public function test_titled_entry_has_exactly_one_rendered_link_and_hidden_date_and_author(): void {
		$id    = $this->question();
		$html  = Question_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//div[contains(@class,"pkiw-question-entry")]/h3[contains(@class,"pkiw-question-entry__title") and contains(@class,"p-name")]/a[@class="u-url" and @href]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@href][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( 0, $xpath->query( '//a[@tabindex="-1"][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertSame( 1, $xpath->query( '//data[@class="dt-published" and @hidden]' )->length );
		$this->assertSame( 1, $xpath->query( '//span[@hidden]//*[contains(@class,"p-author") and contains(@class,"h-card")]' )->length );
		$this->assertSame( get_permalink( $id ), $xpath->query( '//h3/a' )->item( 0 )->getAttribute( 'href' ) );
	}

	public function test_heading_level_is_clamped_and_increments_only_inside_a_section(): void {
		$post = get_post( $this->question() );

		$this->assertStringContainsString( '<h2 ', Question_Archive::entry_html( $post, [ 'headingLevel' => 1 ], false ) );
		$this->assertStringContainsString( '<h3 ', Question_Archive::entry_html( $post, [ 'headingLevel' => 2 ], true ) );
		$this->assertStringContainsString( '<h6 ', Question_Archive::entry_html( $post, [ 'headingLevel' => 6 ], true ) );
	}

	public function test_entry_has_no_visible_date_chip_icon_count_excerpt_kind_or_menu_markup(): void {
		$html  = Question_Archive::entry_html( get_post( $this->question() ), [ 'headingLevel' => 2 ], true );
		$xpath = $this->xpath( $html );

		$this->assertSame( 0, $xpath->query( '//time[not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertStringNotContainsString( 'pkiw-menu-entry', $html );
		$this->assertStringNotContainsString( '<svg', $html );
		foreach ( [ 'pk-kindlabel', 'pk-answer-count', 'pk-excerpt', 'pk-badge' ] as $class ) {
			$this->assertStringNotContainsString( $class, $html );
		}
	}

	public function test_untitled_entry_uses_the_question_text_without_p_name(): void {
		$id = $this->question(
			[
				'post_title'   => '',
				'post_content' => '<p><span class="p-question">What do you do when a draft stops making sense?</span></p>',
			]
		);
		$html = Question_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );

		$this->assertStringContainsString( '>What do you do when a draft stops making sense?</a>', $html );
		$this->assertStringNotContainsString( 'pkiw-question-entry__title p-name', $html );
		$this->assertStringNotContainsString( '(no title)', $html );
	}

	public function test_a_one_hundred_twenty_character_title_is_not_truncated(): void {
		$title = str_repeat( 'T', 120 );
		$html  = Question_Archive::entry_html( get_post( $this->question( [ 'post_title' => $title ] ) ), [ 'headingLevel' => 2 ], true );

		$this->assertStringContainsString( '>' . $title . '</a>', $html );
		$this->assertStringNotContainsString( '…', $html );
	}

	public function test_protected_entry_prints_only_the_title_link_and_hidden_entry_properties(): void {
		$id    = $this->question( [ 'post_password' => 'seed-pass-256', 'post_content' => '<p>Hidden words.</p>' ] );
		$html  = Question_Archive::entry_html( get_post( $id ), [ 'headingLevel' => 2 ], true );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//a[@href][not(ancestor-or-self::*[@hidden])]' )->length );
		$this->assertStringContainsString( 'Protected: How do you name a new post kind?', wp_strip_all_tags( $html ) );
		$this->assertStringNotContainsString( 'Hidden words', $html );
		$this->assertStringNotContainsString( 'post-password-form', $html );
	}

	public function test_block_renderer_preview_renders_the_same_entry_contract(): void {
		$id = $this->question();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Question_Archive::ENTRY_BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $id );
		$request->set_param( 'pkiw_kind', 'question' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertStringContainsString( 'pkiw-question-entry', (string) $response->get_data()['rendered'] );
	}
}

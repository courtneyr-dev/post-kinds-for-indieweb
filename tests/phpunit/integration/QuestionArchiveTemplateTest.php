<?php
/**
 * The question archive block template.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionArchiveTemplateTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
	}

	public function test_the_template_is_registered_and_exists_once(): void {
		$file = PKIW_PATH . 'templates/taxonomy-kind-question.html';

		$this->assertFileExists( $file );
		$this->assertSame( 1, count( glob( PKIW_PATH . 'templates/taxonomy-kind-question.html' ) ?: [] ) );
	}

	public function test_the_template_uses_an_inherited_query_and_the_question_entry_block(): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-question.html' );

		$this->assertStringContainsString( '<!-- wp:query {"queryId":0,"query":{"inherit":true},"className":"pkiw-kind-archive"} -->', $content );
		$this->assertStringContainsString( '<!-- wp:post-kinds-indieweb/question-entry {"linesPerPage":12} /-->', $content );
		$this->assertStringContainsString( '<!-- wp:term-description /-->', $content );
		$this->assertStringContainsString( '<!-- wp:query-pagination ', $content );
		$this->assertStringContainsString( '<!-- wp:query-pagination-previous /-->', $content );
		$this->assertStringContainsString( '<!-- wp:query-pagination-numbers /-->', $content );
		$this->assertStringContainsString( '<!-- wp:query-pagination-next /-->', $content );
	}

	public function test_the_template_has_no_query_no_results_block(): void {
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-question.html' );

		$this->assertStringNotContainsString( 'wp:query-no-results', $content );
	}
}

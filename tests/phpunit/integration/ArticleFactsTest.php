<?php
/**
 * Article facts for future template adapters (issue 263).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class ArticleFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->register_article_archive();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_article_facts_include_reading_minutes(): void {
		$post_id = $this->article( [ 'post_content' => str_repeat( 'word ', 1000 ) ] );

		$this->assertSame( [ 'reading_minutes' => 5 ], \PKIW\Kind_Facts::get( $post_id ) );
	}

	public function test_article_facts_are_empty_for_a_protected_post(): void {
		$post_id = $this->article(
			[
				'post_content'  => str_repeat( 'word ', 1000 ),
				'post_password' => 'secret',
			]
		);

		$this->assertSame( [], \PKIW\Kind_Facts::get( $post_id ) );
	}

	public function test_article_facts_are_empty_for_a_private_post_when_logged_out(): void {
		$post_id = $this->article(
			[
				'post_status'  => 'private',
				'post_content' => str_repeat( 'word ', 1000 ),
			]
		);
		wp_set_current_user( 0 );

		$this->assertSame( [], \PKIW\Kind_Facts::get( $post_id ) );
	}

	public function test_article_facts_have_no_location_keys(): void {
		$post_id = $this->article( [ 'post_content' => str_repeat( 'word ', 1000 ) ] );

		$this->assertSame( [ 'reading_minutes' ], array_keys( \PKIW\Kind_Facts::get( $post_id ) ) );
	}

	private function register_article_archive(): void {
		$this->assertTrue( class_exists( 'PKIW\\Article_Archive' ), 'PKIW\\Article_Archive must exist for issue 263.' );
		$this->assertTrue( class_exists( 'PKIW\\Reading_Time' ), 'PKIW\\Reading_Time must exist for issue 263.' );
		if ( null === \PKIW\Kind_Facts::reader( 'article' ) ) {
			( new \PKIW\Article_Archive() )->register();
		}
	}

	/**
	 * @param array<string, mixed> $args Extra post args.
	 */
	private function article( array $args = [] ): int {
		if ( ! term_exists( 'article', 'kind' ) ) {
			wp_insert_term( 'Article', 'kind', [ 'slug' => 'article' ] );
		}
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Fictional Article',
					'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, 'article', 'kind' );

		return $post_id;
	}
}

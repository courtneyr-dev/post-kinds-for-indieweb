<?php
/**
 * Facts exposed for quote archive cards.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuoteFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
		delete_transient( Quote_Archive::INDEX_TRANSIENT );
	}

	private function quote( array $args = [], array $meta = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'A quotation',
					'post_content' => '<!-- wp:quote --><blockquote class="wp-block-quote"><p>First quoted sentence.</p><cite>Block Author</cite></blockquote><!-- /wp:quote -->',
					'post_date'    => '2026-09-04 10:30:00',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, '_pkiw_cite_' . $key, $value );
		}

		return (int) $id;
	}

	public function test_quote_block_facts_have_the_frozen_keys_and_plain_text(): void {
		$id    = $this->quote( [], [ 'name' => 'Collected Essays', 'author' => 'Avery Quill', 'url' => 'https://example.com/essays' ] );
		$facts = Quote_Archive::facts( $id );

		$this->assertSame( [ 'text', 'excerpt', 'is_trimmed', 'work', 'author', 'url', 'block_cite', 'cite', 'date_iso' ], array_keys( $facts ) );
		$this->assertSame( 'First quoted sentence.', $facts['text'] );
		$this->assertSame( 'Block Author', $facts['block_cite'] );
		$this->assertSame( 'Collected Essays', $facts['cite'] );
		$this->assertSame( 'Avery Quill', $facts['author'] );
		$this->assertSame( 'https://example.com/essays', $facts['url'] );
		$this->assertFalse( $facts['is_trimmed'] );
		$this->assertSame( get_post_time( 'c', true, get_post( $id ) ), $facts['date_iso'] );
	}

	public function test_pullquote_and_nested_inner_blocks_are_read_in_document_order(): void {
		$content = '<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Before <strong>inside</strong> after.</p><cite><a href="https://example.com/">Linked Cite</a></cite></blockquote></figure><!-- /wp:pullquote -->';
		$facts   = Quote_Archive::facts( $this->quote( [ 'post_content' => $content ] ) );

		$this->assertSame( 'Before inside after.', $facts['text'] );
		$this->assertSame( 'Linked Cite', $facts['block_cite'] );
		$this->assertSame( 'Linked Cite', $facts['cite'] );
		$this->assertStringNotContainsString( '<', $facts['text'] . $facts['block_cite'] );
	}

	public function test_no_quote_block_falls_back_to_plain_post_content(): void {
		$id = $this->quote( [ 'post_content' => '<!-- wp:paragraph --><p>Fallback <em>body</em> text.</p><!-- /wp:paragraph -->[caption]removed[/caption]' ] );

		$this->assertSame( 'Fallback body text.', Quote_Archive::facts( $id )['text'] );
	}

	public function test_sixty_two_word_quote_is_trimmed_to_forty_words(): void {
		$words   = array_map( static fn( int $number ): string => 'word' . $number, range( 1, 62 ) );
		$content = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>' . implode( ' ', $words ) . '</p></blockquote><!-- /wp:quote -->';
		$facts   = Quote_Archive::facts( $this->quote( [ 'post_content' => $content ] ) );

		$this->assertTrue( $facts['is_trimmed'] );
		$this->assertSame( implode( ' ', array_slice( $words, 0, 40 ) ) . '…', $facts['excerpt'] );
	}

	public function test_untitled_quote_keeps_its_quote_text(): void {
		$facts = Quote_Archive::facts( $this->quote( [ 'post_title' => '' ] ) );

		$this->assertSame( 'First quoted sentence.', $facts['text'] );
	}

	public function test_non_quote_and_non_public_posts_have_no_facts(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => '<p>Not a quote.</p>' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$protected = $this->quote( [ 'post_password' => 'secret' ] );
		$private   = $this->quote( [ 'post_status' => 'private' ] );
		$draft     = $this->quote( [ 'post_status' => 'draft' ] );
		wp_set_current_user( 0 );

		foreach ( [ $note, $protected, $private, $draft ] as $id ) {
			$this->assertSame( [], Quote_Archive::facts( $id ), 'Fixture ' . $id );
		}
	}
}

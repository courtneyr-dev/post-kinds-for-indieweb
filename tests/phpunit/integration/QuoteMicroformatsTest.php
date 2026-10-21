<?php
/**
 * Hidden quotation-of data on quote singles.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Microformats;
use PKIW\Quote_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuoteMicroformatsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Quote_Archive() )->register();
	}

	private function quote( array $meta = [], array $args = [] ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'publish', 'post_content' => '<p>Quote body.</p>' ], $args ) );
		wp_set_object_terms( $id, 'quote', Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, '_pkiw_cite_' . $key, $value );
		}

		return (int) $id;
	}

	private function hidden( int $post_id, string $content = '<p>Quote body.</p>' ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );

		return ( new Microformats() )->add_hidden_mf2_data( $content );
	}

	public function test_single_adds_one_hidden_h_cite_with_each_stored_property(): void {
		$html = $this->hidden( $this->quote( [ 'name' => 'Collected Essays', 'author' => 'Avery Quill', 'url' => 'https://example.com/work' ] ) );

		$this->assertSame( 1, substr_count( $html, 'u-quotation-of h-cite' ) );
		$this->assertStringContainsString( '<span class="p-name">Collected Essays</span>', $html );
		$this->assertStringContainsString( '<span class="p-author">Avery Quill</span>', $html );
		$this->assertStringContainsString( '<data class="u-url" value="https://example.com/work"></data>', $html );
	}

	public function test_any_one_cite_property_is_enough_and_unstored_children_are_omitted(): void {
		$html = $this->hidden( $this->quote( [ 'author' => 'Avery Quill' ] ) );

		$this->assertStringContainsString( 'u-quotation-of h-cite', $html );
		$this->assertStringContainsString( 'p-author', $html );
		$this->assertStringNotContainsString( 'p-name', $html );
		$this->assertStringNotContainsString( 'class="u-url"', $html );
	}

	public function test_existing_quotation_of_protected_and_empty_quotes_add_nothing(): void {
		$existing = $this->quote( [ 'name' => 'Book' ] );
		$html     = $this->hidden( $existing, '<span class="u-quotation-of h-cite">Existing</span>' );
		$this->assertSame( 1, substr_count( $html, 'u-quotation-of' ) );

		$protected = $this->quote( [ 'name' => 'Locked Work' ], [ 'post_password' => 'secret' ] );
		$this->assertStringNotContainsString( 'u-quotation-of', $this->hidden( $protected ) );
		$this->assertStringNotContainsString( 'u-quotation-of', $this->hidden( $this->quote() ) );
	}
}

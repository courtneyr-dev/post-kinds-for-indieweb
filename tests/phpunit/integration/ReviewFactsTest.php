<?php
/**
 * Review facts reader for the review archive (issue 252).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Review_Archive;
use PKIW\Taxonomy;

/**
 * Review facts expose the public archive facts for review posts only.
 *
 * @group integration
 */
final class ReviewFactsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_review_facts_are_registered_for_kind_facts(): void {
		$this->assertNotNull( Kind_Facts::reader( 'review' ) );

		$post_id = $this->review( 'Lantern Review', [ 'rating' => 4, 'best' => 5 ] );

		$this->assertSame( Review_Archive::review_facts( $post_id ), \PKIW\kind_facts( $post_id ) );
	}

	public function test_meta_rating_supplies_rating_best_label_and_stars(): void {
		$facts = Review_Archive::review_facts( $this->review( 'Lantern Review', [ 'rating' => 4, 'best' => 5 ] ) );

		$this->assertSame( 4.0, $facts['rating'] );
		$this->assertSame( 5, $facts['best'] );
		$this->assertSame( 'Rated 4 of 5', $facts['rating_label'] );
		$this->assertSame( [ 'value' => 4.0, 'best' => 5, 'full' => 4, 'half' => false, 'empty' => 1 ], $facts['stars'] );
	}

	public function test_zero_meta_rating_falls_back_to_the_first_star_rating_block_pair_and_item(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:post-kinds-indieweb/star-rating {"rating":8,"maxRating":10,"itemName":"Fictional Atlas","itemUrl":"https://books.example.com/atlas"} /--></div><!-- /wp:group -->';
		$facts   = Review_Archive::review_facts(
			$this->review(
				'Atlas Review',
				[
					'rating' => 0,
					'best'   => 5,
				],
				$content
			)
		);

		$this->assertSame( 8.0, $facts['rating'] );
		$this->assertSame( 10, $facts['best'] );
		$this->assertSame( 'Fictional Atlas', $facts['item_name'] );
		$this->assertSame( 'https://books.example.com/atlas', $facts['item_url'] );
	}

	public function test_meta_rating_pair_never_mixes_with_a_block_pair(): void {
		$content = '<!-- wp:post-kinds-indieweb/star-rating {"rating":8,"maxRating":10,"itemName":"Block Name"} /-->';
		$facts   = Review_Archive::review_facts( $this->review( 'Stored Review', [ 'rating' => 4 ], $content ) );

		$this->assertSame( 4.0, $facts['rating'] );
		$this->assertSame( 5, $facts['best'] );
		$this->assertSame( 'Block Name', $facts['item_name'] );
	}

	/**
	 * @dataProvider blank_best_rows
	 *
	 * @param string|int $best Stored best row.
	 */
	public function test_blank_or_zero_best_counts_as_five( $best ): void {
		$facts = Review_Archive::review_facts( $this->review( 'Five Scale', [ 'rating' => 4, 'best' => $best ] ) );

		$this->assertSame( 5, $facts['best'] );
	}

	/**
	 * Best rows that should fall back to five.
	 *
	 * @return array<string, array{0:string|int}>
	 */
	public function blank_best_rows(): array {
		return [
			'zero'  => [ 0 ],
			'empty' => [ '' ],
		];
	}

	public function test_half_star_rating_label_and_counts(): void {
		$facts = Review_Archive::review_facts( $this->review( 'Half Review', [ 'rating' => 3.5, 'best' => 5 ] ) );

		$this->assertSame( 'Rated 3.5 of 5', $facts['rating_label'] );
		$this->assertSame( 3, $facts['stars']['full'] );
		$this->assertTrue( $facts['stars']['half'] );
		$this->assertSame( 1, $facts['stars']['empty'] );
	}

	public function test_item_url_drops_unsafe_schemes_and_keeps_web_urls(): void {
		$bad  = Review_Archive::review_facts( $this->review( 'Script Review', [ 'item_url' => 'javascript:alert(1)' ] ) );
		$good = Review_Archive::review_facts( $this->review( 'Web Review', [ 'item_url' => 'https://items.example.com/path?x=1' ] ) );

		$this->assertSame( '', $bad['item_url'] );
		$this->assertSame( 'https://items.example.com/path?x=1', $good['item_url'] );
	}

	public function test_pullquote_reads_the_first_nested_pullquote_without_cite_and_decodes_entities(): void {
		$content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Salt &amp; paper&nbsp;works.</p><cite>Ignored Source</cite></blockquote></figure><!-- /wp:pullquote --></div><!-- /wp:group --><!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Second quote.</p></blockquote></figure><!-- /wp:pullquote -->';
		$facts   = Review_Archive::review_facts( $this->review( 'Quote Review', [], $content ) );

		$this->assertSame( 'Salt & paper works.', $facts['pullquote'] );
	}

	public function test_no_pullquote_returns_an_empty_string(): void {
		$facts = Review_Archive::review_facts( $this->review( 'No Quote', [], '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( '', $facts['pullquote'] );
	}

	public function test_private_protected_missing_and_non_review_posts_return_empty_facts(): void {
		wp_set_current_user( 0 );
		$protected = $this->review( 'Locked Review', [ 'rating' => 4 ], '', [ 'post_password' => 'secret' ] );
		$private   = $this->review( 'Private Review', [ 'rating' => 4 ], '', [ 'post_status' => 'private' ] );
		$note      = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );

		$this->assertSame( [], Review_Archive::review_facts( 0 ) );
		$this->assertSame( [], Review_Archive::review_facts( $protected ) );
		$this->assertSame( [], Review_Archive::review_facts( $private ) );
		$this->assertSame( [], Review_Archive::review_facts( $note ) );
	}

	public function test_category_fact_uses_the_grouped_archive_source(): void {
		$alpha   = self::factory()->category->create_and_get( [ 'name' => 'Alpha Books', 'slug' => 'alpha-books' ] );
		$post_id = $this->review( 'Alpha Review' );
		wp_set_post_categories( $post_id, [ $alpha->term_id ] );

		$facts = Review_Archive::review_facts( $post_id );

		$this->assertSame( $alpha->term_id, $facts['category']['id'] );
		$this->assertSame( 'Alpha Books', $facts['category']['name'] );
		$this->assertSame( 'alpha-books', $facts['category']['slug'] );
		$this->assertSame( get_category_link( $alpha ), $facts['category']['link'] );
	}

	public function test_default_category_and_no_relationship_category_facts(): void {
		$default_id = (int) get_option( 'default_category' );
		$default    = get_term( $default_id, 'category' );
		$defaulted  = $this->review( 'Default Review' );
		$empty      = $this->review( 'Empty Review' );
		wp_delete_object_term_relationships( $empty, [ 'category' ] );

		$default_facts = Review_Archive::review_facts( $defaulted );
		$empty_facts   = Review_Archive::review_facts( $empty );

		$this->assertSame( $default_id, $default_facts['category']['id'] );
		$this->assertSame( $default->name, $default_facts['category']['name'] );
		$this->assertSame( $default->slug, $default_facts['category']['slug'] );
		$this->assertSame( get_category_link( $default ), $default_facts['category']['link'] );
		$this->assertSame( 0, $empty_facts['category']['id'] );
		$this->assertSame( '', $empty_facts['category']['name'] );
		$this->assertSame( '', $empty_facts['category']['slug'] );
		$this->assertSame( '', $empty_facts['category']['link'] );
		$this->assertTrue( Grouped_Archive::group_of_post( 'review', $empty )->is_empty() );
	}

	/**
	 * Create a review post with optional review meta.
	 *
	 * @param string               $title   Post title.
	 * @param array<string, mixed> $meta    Review meta suffix => value.
	 * @param string               $content Post content.
	 * @param array<string, mixed> $post    Extra post fields.
	 * @return int
	 */
	private function review( string $title, array $meta = [], string $content = '', array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'review', Taxonomy::TAXONOMY );
		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $id, Meta_Fields::PREFIX . 'review_' . $suffix, $value );
		}

		return $id;
	}
}

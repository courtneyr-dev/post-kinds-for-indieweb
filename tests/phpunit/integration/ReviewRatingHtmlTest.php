<?php
/**
 * Review rating HTML helpers (issue 252).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Review_Archive;

/**
 * Review rating helper markup avoids microformats in archive summaries.
 *
 * @group integration
 */
final class ReviewRatingHtmlTest extends WP_UnitTestCase {

	public function test_review_rating_html_matches_the_card_label_and_star_counts(): void {
		$html = Review_Archive::review_rating_html( [ 'rating' => 3.5, 'best' => 5 ] );

		$this->assertStringContainsString( '<span class="pkiw-review-rating__label">' . esc_html( \PKIW\card_rating_label( 3.5, 5 ) ) . '</span>', $html );
		$this->assertSame( 3, substr_count( $html, '<svg class=""' ) );
		$this->assertSame( 1, substr_count( $html, '<svg class="half"' ) );
		$this->assertSame( 1, substr_count( $html, '<svg class="off"' ) );
	}

	public function test_review_rating_html_has_one_decorative_wrapper_and_no_microformat_rating(): void {
		$html = Review_Archive::review_rating_html( [ 'rating' => 4, 'best' => 5 ] );

		$this->assertSame( 1, substr_count( $html, 'aria-hidden="true"' ) );
		$this->assertStringNotContainsString( 'p-rating', $html );
		$this->assertStringNotContainsString( 'role="img"', $html );
		$this->assertStringNotContainsString( '<data', $html );
	}

	public function test_review_rating_html_returns_empty_for_zero_or_missing_rating(): void {
		$this->assertSame( '', Review_Archive::review_rating_html( [ 'rating' => 0, 'best' => 5 ] ) );
		$this->assertSame( '', Review_Archive::review_rating_html( [] ) );
	}

	public function test_best_ten_rating_uses_ten_star_slots(): void {
		$html = Review_Archive::review_rating_html( [ 'rating' => 8, 'best' => 10 ] );

		$this->assertSame( 8, substr_count( $html, '<svg class=""' ) );
		$this->assertSame( 0, substr_count( $html, '<svg class="half"' ) );
		$this->assertSame( 2, substr_count( $html, '<svg class="off"' ) );
	}

	public function test_review_item_html_with_microformats_and_plain_markup(): void {
		$url   = 'https://items.example.com/lantern';
		$facts = [
			'item_name' => 'Lantern & Kettle',
			'item_url'  => $url,
		];

		$this->assertSame(
			'<span class="p-item h-product"><a class="p-name u-url" href="' . esc_url( $url ) . '">Lantern &amp; Kettle</a></span>',
			Review_Archive::review_item_html( $facts, true )
		);
		$this->assertSame(
			'<span class="pkiw-review-item"><a href="' . esc_url( $url ) . '">Lantern &amp; Kettle</a></span>',
			Review_Archive::review_item_html( $facts, false )
		);
	}

	public function test_review_item_html_without_url_and_with_empty_name(): void {
		$this->assertSame(
			'<span class="p-item h-product"><span class="p-name">&lt;Clean&gt;</span></span>',
			Review_Archive::review_item_html( [ 'item_name' => '<Clean>', 'item_url' => '' ], true )
		);
		$this->assertSame(
			'<span class="pkiw-review-item">&lt;Clean&gt;</span>',
			Review_Archive::review_item_html( [ 'item_name' => '<Clean>', 'item_url' => '' ], false )
		);
		$this->assertSame( '', Review_Archive::review_item_html( [ 'item_name' => '', 'item_url' => 'https://items.example.com/' ], true ) );
	}
}

<?php
/**
 * Review rating parity with block bindings (issue 252).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Block_Bindings;
use PKIW\Meta_Fields;

/**
 * The archive stars and existing binding stars share the same counts.
 *
 * @group integration
 */
final class ReviewRatingParityTest extends WP_UnitTestCase {

	public function test_card_star_counts_match_the_block_binding_string_for_five_and_ten_point_scales(): void {
		$binding = new Block_Bindings();
		$method  = new ReflectionMethod( $binding, 'generate_star_rating' );
		$method->setAccessible( true );

		foreach ( [ 5, 10 ] as $best ) {
			for ( $rating = 0.5; $rating <= $best; $rating += 0.5 ) {
				$counts = \PKIW\card_star_counts( $rating, $best );
				$stars  = (string) $method->invoke( $binding, $rating, $best );

				$this->assertSame( $counts['full'], substr_count( $stars, '★' ), "{$rating} of {$best}: full stars" );
				$this->assertSame( $counts['half'], str_contains( $stars, '½' ), "{$rating} of {$best}: half star" );
				$this->assertSame( $counts['empty'], substr_count( $stars, '☆' ), "{$rating} of {$best}: empty stars" );
			}
		}
	}

	public function test_review_rating_display_binding_outputs_four_out_of_five(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', '4' );

		$this->assertSame( '4 out of 5', $this->binding( 'review_rating_display', $post_id ) );
	}

	/**
	 * Resolve a binding key for a post.
	 *
	 * @param string $key     Binding key.
	 * @param int    $post_id Post ID.
	 * @return string|null
	 */
	private function binding( string $key, int $post_id ): ?string {
		$block          = new WP_Block(
			[
				'blockName' => 'core/paragraph',
				'attrs'     => [],
			],
			[ 'postId' => $post_id ]
		);
		$block->context = [ 'postId' => $post_id ];

		return ( new Block_Bindings() )->get_binding_value( [ 'key' => $key ], $block, 'content' );
	}
}

<?php
/**
 * Shared card rating coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class CardRatingTest extends WP_UnitTestCase {

	/**
	 * @dataProvider star_counts
	 *
	 * @param array{value: float, best: int, full: int, half: bool, empty: int} $expected Expected counts.
	 */
	public function test_card_star_counts( float $rating, int $best, array $expected ): void {
		$this->assertSame( $expected, \PKIW\card_star_counts( $rating, $best ) );
	}

	/**
	 * @return array<string, array{0: float, 1: int, 2: array{value: float, best: int, full: int, half: bool, empty: int}}>
	 */
	public function star_counts(): array {
		return [
			'four'         => [ 4.0, 5, [ 'value' => 4.0, 'best' => 5, 'full' => 4, 'half' => false, 'empty' => 1 ] ],
			'half'         => [ 3.5, 5, [ 'value' => 3.5, 'best' => 5, 'full' => 3, 'half' => true, 'empty' => 1 ] ],
			'zero'         => [ 0.0, 5, [ 'value' => 0.0, 'best' => 5, 'full' => 0, 'half' => false, 'empty' => 5 ] ],
			'negative'     => [ -1.0, 5, [ 'value' => 0.0, 'best' => 5, 'full' => 0, 'half' => false, 'empty' => 5 ] ],
			'clamped high' => [ 9.0, 5, [ 'value' => 5.0, 'best' => 5, 'full' => 5, 'half' => false, 'empty' => 0 ] ],
			'ten scale'    => [ 8.0, 10, [ 'value' => 8.0, 'best' => 10, 'full' => 8, 'half' => false, 'empty' => 2 ] ],
			'invalid best' => [ 4.0, 0, [ 'value' => 4.0, 'best' => 5, 'full' => 4, 'half' => false, 'empty' => 1 ] ],
		];
	}

	public function test_card_rating_labels_format_whole_and_fractional_values(): void {
		$this->assertSame( 'Rated 4 of 5', \PKIW\card_rating_label( 4.0 ) );
		$this->assertSame( 'Rated 3.5 of 5', \PKIW\card_rating_label( 3.5 ) );
		$this->assertSame( 'Rated 8 of 10', \PKIW\card_rating_label( 8.0, 10 ) );
	}

	public function test_card_rating_html_renders_half_star_and_machine_value(): void {
		$html = \PKIW\card_rating_html( 3.5 );

		$this->assertSame( 3, substr_count( $html, '<svg class=""' ) );
		$this->assertSame( 1, substr_count( $html, '<svg class="half"' ) );
		$this->assertSame( 1, substr_count( $html, '<svg class="off"' ) );
		$this->assertStringContainsString( '<data class="p-rating" value="3.5" hidden></data>', $html );
		$this->assertSame( '', \PKIW\card_rating_html( 0.0 ) );
	}

	/**
	 * Beside visible "Rated N of 5" text, the decorative stars stay out of
	 * the accessibility tree, so the rating is announced once. p-rating
	 * stays for parsers (#232 PL8).
	 */
	public function test_decorative_rating_hides_the_stars_and_keeps_p_rating(): void {
		$html = \PKIW\card_rating_html( 3.5, 5, true );

		$this->assertStringContainsString( '<div class="pk-stars" aria-hidden="true">', $html );
		$this->assertStringNotContainsString( 'role="img"', $html );
		$this->assertStringNotContainsString( 'aria-label', $html );
		$this->assertSame( 1, substr_count( $html, '<svg class="half"' ) );
		$this->assertStringContainsString( '<data class="p-rating" value="3.5" hidden></data>', $html );
		$this->assertSame( '', \PKIW\card_rating_html( 0.0, 5, true ) );

		$parsed = \Mf2\parse( '<div class="h-cite">' . $html . '</div>' );
		$this->assertSame( [ '3.5' ], $parsed['items'][0]['properties']['rating'] );
	}

	/**
	 * The default variant still names the stars with role="img".
	 */
	public function test_the_default_rating_still_names_the_stars(): void {
		$html = \PKIW\card_rating_html( 4 );

		$this->assertStringContainsString( '<div class="pk-stars" role="img" aria-label="Rated 4 of 5">', $html );
		$this->assertStringNotContainsString( 'aria-hidden', $html );
	}

	/**
	 * A rating above best prints best in the label and in p-rating, so the
	 * parsed value never disagrees with the stars. The comic card has
	 * clamped both since #228.
	 */
	public function test_a_rating_above_best_prints_best_in_p_rating_too(): void {
		$html = \PKIW\card_rating_html( 9 );

		$this->assertStringContainsString( 'aria-label="Rated 5 of 5"', $html );
		$this->assertStringContainsString( '<data class="p-rating" value="5" hidden></data>', $html );
		$this->assertStringContainsString( '<data class="p-rating" value="10" hidden></data>', \PKIW\card_rating_html( 12, 10 ) );
	}

	/**
	 * The star row is inline-flex, so it runs right to left on an RTL
	 * site and the half star's filled side has to face the full stars.
	 */
	public function test_half_star_fills_the_side_facing_the_full_stars(): void {
		global $wp_locale;

		$this->assertStringContainsString( 'clip-path:inset(0 50% 0 0)', \PKIW\card_rating_html( 3.5 ) );

		$direction                = $wp_locale->text_direction;
		$wp_locale->text_direction = 'rtl';
		try {
			$html = \PKIW\card_rating_html( 3.5 );
		} finally {
			$wp_locale->text_direction = $direction;
		}

		$this->assertStringContainsString( 'clip-path:inset(0 0 0 50%)', $html );
		$this->assertStringNotContainsString( 'clip-path:inset(0 50% 0 0)', $html );
	}

	/**
	 * @dataProvider rating_cards
	 *
	 * @param array<string, mixed> $attributes Base attributes.
	 */
	public function test_every_rating_card_uses_the_shared_fractional_markup( string $kind, array $attributes ): void {
		$with_rating    = $this->render_card( $kind, array_merge( $attributes, [ 'rating' => 4.5 ] ) );
		$without_rating = $this->render_card( $kind, $attributes );

		$this->assertStringContainsString( 'aria-label="Rated 4.5 of 5"', $with_rating );
		$this->assertSame( 1, substr_count( $with_rating, '<svg class="half"' ) );
		$this->assertStringContainsString( '<data class="p-rating" value="4.5" hidden></data>', $with_rating );
		$this->assertSame( $this->plain_text( $without_rating ), $this->plain_text( $with_rating ) );

		$parsed = \Mf2\parse( '<div class="h-entry">' . $with_rating . '</div>' );
		$this->assertContains( '4.5', $this->rating_values( $parsed['items'] ?? [] ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function rating_cards(): array {
		return [
			'watch'  => [ 'watch', [ 'mediaTitle' => 'Example film' ] ],
			'listen' => [ 'listen', [ 'trackTitle' => 'Example song' ] ],
			'read'   => [ 'read', [ 'bookTitle' => 'Example book' ] ],
			'play'   => [ 'play', [ 'title' => 'Example game' ] ],
			'comic'  => [ 'comic', [ 'title' => 'Example comic', 'readStatus' => 'finished' ] ],
			'eat'    => [ 'eat', [ 'name' => 'Example meal' ] ],
			'drink'  => [ 'drink', [ 'name' => 'Example drink' ] ],
		];
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_card( string $kind, array $attributes ): string {
		return do_blocks( sprintf( '<!-- wp:post-kinds-indieweb/%s-card %s /-->', $kind, wp_json_encode( $attributes ) ) );
	}

	private function plain_text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	/**
	 * @param array<int, mixed> $items Parsed items.
	 * @return array<int, string>
	 */
	private function rating_values( array $items ): array {
		$found = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( $item['properties']['rating'] ?? [] as $value ) {
				$found[] = (string) $value;
			}
			foreach ( $item['properties'] ?? [] as $values ) {
				if ( is_array( $values ) ) {
					$found = array_merge( $found, $this->rating_values( $values ) );
				}
			}
			$found = array_merge( $found, $this->rating_values( $item['children'] ?? [] ) );
		}

		return $found;
	}
}

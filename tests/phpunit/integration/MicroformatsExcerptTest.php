<?php
/**
 * Automatic excerpts stay free of the hidden mf2 markers (bug wave item 4).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Review_Archive;
use PKIW\Taxonomy;

/**
 * The hidden author h-card, hidden p-name and hidden review facts belong to
 * the HTML page. wp_trim_excerpt() runs the_content on a singular request,
 * so the markers must not reach the trimmed text.
 *
 * @group integration
 */
final class MicroformatsExcerptTest extends WP_UnitTestCase {

	private int $author_id = 0;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		if ( null === \PKIW\Kind_Facts::reader( 'review' ) ) {
			( new Review_Archive() )->register();
		}
		$this->author_id = self::factory()->user->create( [ 'display_name' => 'Excerpt Author Name' ] );
	}

	public function test_automatic_excerpt_on_an_article_single_has_no_hidden_author_or_title(): void {
		$post_id = $this->kind_post(
			'article',
			'Zephyr Quillfeather Title',
			'<!-- wp:paragraph --><p>Plain authored prose.</p><!-- /wp:paragraph -->'
		);
		$this->go_to_post( $post_id );

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'Plain authored prose', $excerpt );
		$this->assertStringNotContainsString( 'Excerpt Author Name', $excerpt, 'The hidden author h-card text stays out of the excerpt.' );
		$this->assertStringNotContainsString( 'Zephyr Quillfeather Title', $excerpt, 'The hidden p-name title stays out of the excerpt.' );
	}

	public function test_automatic_excerpt_on_a_review_single_has_no_hidden_item_text(): void {
		$post_id = $this->kind_post(
			'review',
			'Quillfeather Review Title',
			'<!-- wp:paragraph --><p>The beam held steady.</p><!-- /wp:paragraph -->'
		);
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_rating', 4 );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_best', 5 );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_item_name', 'Fictional Lantern Item' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'review_item_url', 'https://items.example.com/lantern' );
		$this->go_to_post( $post_id );

		$this->assertStringContainsString(
			'Fictional Lantern Item',
			apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) ),
			'Precondition: the page markup does carry the hidden item name.'
		);

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'The beam held steady', $excerpt );
		$this->assertStringNotContainsString( 'Fictional Lantern Item', $excerpt, 'The hidden review item name stays out of the excerpt.' );
		$this->assertStringNotContainsString( 'Excerpt Author Name', $excerpt );
		$this->assertStringNotContainsString( 'Quillfeather Review Title', $excerpt );
	}

	public function test_automatic_excerpt_on_a_public_checkin_single_has_no_hidden_venue_or_address(): void {
		$post_id = $this->kind_post(
			'checkin',
			'Quillfeather Checkin Title',
			'<!-- wp:paragraph --><p>Lunch was quiet.</p><!-- /wp:paragraph -->'
		);
		$values = [
			'checkin_name'     => 'Marigold Noodle House',
			'checkin_address'  => '14 Cobblestone Lane',
			'checkin_locality' => 'Fictional Heights',
			'checkin_region'   => 'Westmark Province',
			'checkin_country'  => 'Elbonia',
		];
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', 'public' );
		foreach ( $values as $suffix => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $value );
		}
		$this->go_to_post( $post_id );

		$page = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		foreach ( $values as $value ) {
			$this->assertStringContainsString( $value, $page, 'Precondition: the page markup carries the hidden check-in value.' );
		}

		$excerpt = get_the_excerpt( $post_id );

		$this->assertStringContainsString( 'Lunch was quiet', $excerpt );
		foreach ( $values as $value ) {
			$this->assertStringNotContainsString( $value, $excerpt, 'The hidden check-in h-card text stays out of the excerpt.' );
		}
	}

	/**
	 * Byte-identical guard: outside the excerpt the page markup keeps every
	 * hidden marker, and building an excerpt first does not change it.
	 */
	public function test_the_content_outside_the_excerpt_keeps_every_hidden_marker_byte_for_byte(): void {
		$post_id = $this->kind_post(
			'article',
			'Zephyr Quillfeather Title',
			'<!-- wp:paragraph --><p>Plain authored prose.</p><!-- /wp:paragraph -->'
		);
		$this->go_to_post( $post_id );

		$raw     = get_post_field( 'post_content', $post_id );
		$before  = apply_filters( 'the_content', $raw );
		$excerpt = get_the_excerpt( $post_id );
		$after   = apply_filters( 'the_content', $raw );

		$this->assertNotSame( '', $excerpt );
		$this->assertSame( $before, $after, 'Building an excerpt leaves the_content output untouched.' );
		$this->assertStringContainsString( 'pkiw-singular-entry', $after );
		$this->assertStringContainsString( 'u-url', $after );
		$this->assertStringContainsString( 'dt-published', $after );
		$this->assertStringContainsString( 'p-author h-card', $after );
		$this->assertStringContainsString( '<span class="p-name" hidden>Zephyr Quillfeather Title</span>', $after );
		$this->assertStringContainsString( 'Excerpt Author Name', $after );
		$this->assertSame( $this->expected_article_markup( $post_id ), $after, 'The page markup matches the pre-fix capture exactly.' );
	}

	public function test_a_manual_excerpt_is_returned_untouched(): void {
		$post_id = $this->kind_post(
			'article',
			'Zephyr Quillfeather Title',
			'<!-- wp:paragraph --><p>Plain authored prose.</p><!-- /wp:paragraph -->',
			[ 'post_excerpt' => 'A hand-written summary.' ]
		);
		$this->go_to_post( $post_id );

		$this->assertSame( 'A hand-written summary.', get_the_excerpt( $post_id ) );
	}

	private function expected_article_markup( int $post_id ): string {
		return sprintf(
			'<div class="h-entry kind-article pkiw-singular-entry"><div class="e-content"><p class="wp-block-paragraph">Plain authored prose.</p></div><a class="u-url" href="%1$s" hidden></a><time class="dt-published" datetime="%2$s" hidden></time><span hidden><span class="p-author h-card"><span class="p-name">Excerpt Author Name</span><data class="u-url" value="%3$s"></data><data class="u-photo" value="%4$s"></data></span></span><span class="p-name" hidden>Zephyr Quillfeather Title</span></div>',
			esc_url( get_permalink( $post_id ) ),
			get_post_time( 'c', true, $post_id ),
			esc_url( get_author_posts_url( (int) get_post_field( 'post_author', $post_id ) ) ),
			esc_url( get_avatar_url( (int) get_post_field( 'post_author', $post_id ), [ 'size' => 96 ] ) )
		);
	}

	private function kind_post( string $kind, string $title, string $content, array $extra = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_author'  => $this->author_id,
					'post_content' => $content,
					'post_excerpt' => '',
				],
				$extra
			)
		);
		$this->assertNotWPError( wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY ), 'The kind term is assigned.' );

		return $id;
	}

	private function go_to_post( int $post_id ): void {
		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertTrue( is_singular(), 'The request resolves as singular.' );
		setup_postdata( get_post( $post_id ) );
	}
}

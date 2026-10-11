<?php
/**
 * Four Stars review block (issue 252).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Review_Archive;
use PKIW\Taxonomy;

/**
 * Four Stars lists the newest reviews rated exactly four out of five.
 *
 * @group integration
 */
final class FourStarsBlockTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/four-stars';

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->go_to( get_term_link( 'review', Taxonomy::TAXONOMY ) );
	}

	public function tear_down(): void {
		if ( post_type_exists( 'pkiw_hidden_test' ) ) {
			unregister_taxonomy_for_object_type( Taxonomy::TAXONOMY, 'pkiw_hidden_test' );
			unregister_post_type( 'pkiw_hidden_test' );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_members_are_exact_four_of_five_equivalents_only(): void {
		$in = [
			$this->review( 'Four of Five', 4, 5, '2026-01-05 10:00:00', 'Fictional Four' ),
			$this->review( 'Four Point Zero', '4.0', 5, '2026-01-04 10:00:00', 'Fictional Four Point Zero', true ),
			$this->review( 'Eight of Ten', 8, 10, '2026-01-03 10:00:00', 'Fictional Eight' ),
			$this->review( 'Best Zero', 4, 0, '2026-01-02 10:00:00', 'Fictional Zero Best' ),
			$this->review( 'No Best', 4, null, '2026-01-01 10:00:00', 'Fictional No Best' ),
		];
		$this->review( 'Five of Five', 5, 5, '2026-01-06 10:00:00', 'Fictional Five' );
		$this->review( 'Three and Half', 3.5, 5, '2026-01-06 09:00:00', 'Fictional Three Half' );
		$this->review( 'Four and Half', 4.5, 5, '2026-01-06 08:00:00', 'Fictional Four Half' );
		$this->review( 'Zero Rating', 0, 5, '2026-01-06 07:00:00', 'Fictional Zero' );
		$this->review( 'Draft Review', 4, 5, '2026-01-06 06:00:00', 'Fictional Draft', false, [ 'post_status' => 'draft' ] );
		$this->review( 'Private Review', 4, 5, '2026-01-06 05:00:00', 'Fictional Private', false, [ 'post_status' => 'private' ] );
		$this->review( 'Locked Review', 4, 5, '2026-01-06 04:00:00', 'Fictional Locked', false, [ 'post_password' => '  ' ] );
		$watch = $this->review( 'Watch Review', 4, 5, '2026-01-06 03:00:00', 'Fictional Watch' );
		wp_set_object_terms( $watch, 'watch', Taxonomy::TAXONOMY );
		$this->hidden_review();

		$html = $this->render( [ 'count' => 6 ] );

		$this->assertSame( [ 'Fictional Four', 'Fictional Four Point Zero', 'Fictional Eight', 'Fictional Zero Best', 'Fictional No Best' ], $this->titles( $html ) );
		foreach ( $in as $post_id ) {
			$this->assertStringContainsString( get_permalink( $post_id ), $html );
		}
		foreach ( [ 'Fictional Five', 'Fictional Three Half', 'Fictional Four Half', 'Fictional Zero', 'Fictional Draft', 'Fictional Private', 'Fictional Locked', 'Fictional Watch', 'Hidden Type' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html );
		}
	}

	public function test_order_is_newest_first_with_higher_id_first_on_equal_dates(): void {
		$first  = $this->review( 'First Equal', 4, 5, '2026-01-01 10:00:00', 'First Equal Item' );
		$second = $this->review( 'Second Equal', 4, 5, '2026-01-01 10:00:00', 'Second Equal Item' );
		$this->assertGreaterThan( $first, $second );

		$this->assertSame( [ 'Second Equal Item', 'First Equal Item' ], $this->titles( $this->render() ) );
	}

	public function test_no_members_or_paged_archive_renders_nothing(): void {
		$this->assertSame( '', $this->render() );

		update_option( 'posts_per_page', 1 );
		$this->review( 'Four One', 4, 5, '2026-01-02 10:00:00', 'Four One' );
		$this->review( 'Four Two', 4, 5, '2026-01-01 10:00:00', 'Four Two' );
		$this->go_to( get_term_link( 'review', Taxonomy::TAXONOMY ) );
		$this->assertNotSame( '', $this->render() );
		$this->go_to( add_query_arg( 'paged', 2, get_term_link( 'review', Taxonomy::TAXONOMY ) ) );
		$this->assertTrue( is_paged() );
		$this->assertSame( '', $this->render() );
	}

	public function test_count_clamps_to_one_through_six(): void {
		foreach ( range( 1, 8 ) as $i ) {
			$this->review( 'Four ' . $i, 4, 5, sprintf( '2026-01-%02d 10:00:00', $i ), 'Four Item ' . $i );
		}

		$this->assertCount( 1, $this->items( $this->render( [ 'count' => 0 ] ) ) );
		$this->assertCount( 6, $this->items( $this->render( [ 'count' => 9 ] ) ) );
	}

	public function test_heading_level_heading_text_and_unique_aria_labels(): void {
		$this->review( 'Four One', 4, 5, '2026-01-01 10:00:00', 'Four One' );

		$low  = $this->render( [ 'headingLevel' => 1 ] );
		$high = $this->render( [ 'headingLevel' => 9, 'heading' => 'Four star shelf' ] );
		$both = $this->render() . $this->render();

		$this->assertStringContainsString( '<h2', $low );
		$this->assertStringContainsString( '<h4', $high );
		$this->assertStringContainsString( '>Four star shelf</h4>', $high );
		$this->assertSame( 1, preg_match( '/<section [^>]*aria-labelledby="([^"]+)"[^>]*><h4 id="\1"/', $high ) );
		$this->assertSame( 2, preg_match_all( '/<h2 id="([^"]+)" class="pkiw-four-stars__heading">/', $both, $ids ) );
		$this->assertNotSame( $ids[1][0], $ids[1][1] );
	}

	public function test_link_text_falls_back_to_safe_title_then_untitled_name(): void {
		$this->review( 'Titled Review', 4, 5, '2026-01-03 10:00:00', '' );
		$untitled = $this->review( '', 4, 5, '2026-01-02 10:00:00', '' );

		$this->assertSame( [ 'Titled Review', \PKIW\untitled_name( get_post( $untitled ) ) ], $this->titles( $this->render() ) );
	}

	public function test_markup_contains_no_image_time_or_category(): void {
		$id         = $this->review( 'Four With Cover', 4, 5, '2026-01-01 10:00:00', 'Four With Cover' );
		$attachment = self::factory()->attachment->create_object( 'four-cover.png', 0, [ 'post_mime_type' => 'image/png' ] );
		set_post_thumbnail( $id, $attachment );
		wp_set_post_categories( $id, [ self::factory()->category->create( [ 'name' => 'Alpha Books' ] ) ] );

		$html = $this->render();

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<time', $html );
		$this->assertStringNotContainsString( 'Alpha Books', $html );
	}

	public function test_block_registration_defaults(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Review_Archive::FOUR_STARS_BLOCK );

		$this->assertNotNull( $block );
		$this->assertSame( 6, $block->attributes['count']['default'] );
		$this->assertSame( 2, $block->attributes['headingLevel']['default'] );
		$this->assertSame( 'content', $block->attributes['heading']['role'] );
		$this->assertContains( 'pkiw-four-stars-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-four-stars-editor', 'registered' ) );
	}

	/**
	 * Render the dynamic block.
	 *
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string
	 */
	private function render( array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Create a review with stored rating meta.
	 *
	 * @param string           $title       Post title.
	 * @param int|float|string $rating      Rating.
	 * @param int|null         $best        Best, or null for no row.
	 * @param string           $date        Post date.
	 * @param string           $item_name   Item name.
	 * @param bool             $raw_rating  Whether to insert the rating row raw.
	 * @param array<string,mixed> $post     Extra post fields.
	 * @return int
	 */
	private function review( string $title, $rating, ?int $best, string $date, string $item_name, bool $raw_rating = false, array $post = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => $title,
					'post_date'   => $date,
				],
				$post
			)
		);
		wp_set_object_terms( $id, 'review', Taxonomy::TAXONOMY );
		if ( $raw_rating ) {
			global $wpdb;
			$wpdb->insert(
				$wpdb->postmeta,
				[
					'post_id'    => $id,
					'meta_key'   => Meta_Fields::PREFIX . 'review_rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => (string) $rating, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				]
			);
			wp_cache_delete( $id, 'post_meta' );
		} else {
			update_post_meta( $id, Meta_Fields::PREFIX . 'review_rating', $rating );
		}
		if ( null !== $best ) {
			update_post_meta( $id, Meta_Fields::PREFIX . 'review_best', $best );
		}
		if ( '' !== $item_name ) {
			update_post_meta( $id, Meta_Fields::PREFIX . 'review_item_name', $item_name );
		}

		return $id;
	}

	/**
	 * Create an otherwise qualifying hidden-type review.
	 *
	 * @return void
	 */
	private function hidden_review(): void {
		register_post_type( 'pkiw_hidden_test', [ 'public' => false ] );
		register_taxonomy_for_object_type( Taxonomy::TAXONOMY, 'pkiw_hidden_test' );
		$this->review( 'Hidden Type Review', 4, 5, '2026-01-06 02:00:00', 'Hidden Type', false, [ 'post_type' => 'pkiw_hidden_test' ] );
	}

	/**
	 * Parse HTML.
	 *
	 * @param string $html HTML.
	 * @return DOMXPath
	 */
	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );

		return new DOMXPath( $dom );
	}

	/**
	 * Item link text and href.
	 *
	 * @param string $html HTML.
	 * @return array<int, array{title:string,href:string}>
	 */
	private function items( string $html ): array {
		$out = [];
		foreach ( $this->xpath( $html )->query( '//section/ul/li/a' ) as $a ) {
			$out[] = [
				'title' => trim( $a->textContent ),
				'href'  => $a->getAttribute( 'href' ),
			];
		}

		return $out;
	}

	/**
	 * Item link texts.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	private function titles( string $html ): array {
		return wp_list_pluck( $this->items( $html ), 'title' );
	}
}

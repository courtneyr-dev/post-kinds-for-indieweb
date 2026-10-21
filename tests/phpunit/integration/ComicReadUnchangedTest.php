<?php
/**
 * The shipped comic read stays byte-identical (issue 266 guard).
 *
 * Issue 266 adds a drawn-strip view beside the comics read. These goldens were
 * captured from develop before the strip class existed, so a drift in the
 * comic card, its Stream card, its facts or the unfiltered archive query shows
 * here.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicReadUnchangedTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/comic-card';

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'posts_per_page', 12 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_reset_postdata();
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function attributes(): array {
		return [
			'title'         => 'Saga',
			'creators'      => 'Brian K. Vaughan, Fiona Staples',
			'series'        => 'Saga',
			'volume'        => '2',
			'issueNumber'   => '7',
			'publisher'     => 'Image Comics',
			'coverImage'    => 'https://example.com/covers/saga-7.jpg',
			'coverImageAlt' => 'A horned parent holds a swaddled baby beside a winged parent.',
			'sourceUrl'     => 'https://example.com/comics/saga-7',
			'readStatus'    => 'finished',
			'rating'        => 4,
			'startedAt'     => '2026-08-30',
			'finishedAt'    => '2026-09-02',
			'review'        => 'Lying Cat steals every page.',
		];
	}

	private function comic_read(): int {
		$post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Saga 7',
				'post_name'    => 'saga-seven-read',
				'post_date'    => '2026-09-03 10:00:00',
				'post_content' => '<!-- wp:' . self::BLOCK . ' ' . serialize_block_attributes( $this->attributes() ) . ' /-->',
			]
		);
		wp_set_object_terms( $post_id, 'comics', Taxonomy::TAXONOMY );

		return (int) $post_id;
	}

	private function normalize( string $markup ): string {
		return trim( (string) preg_replace( [ '/>\s+</', '/\s+/' ], [ '><', ' ' ], $markup ) );
	}

	private function golden( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/' . $name;
	}

	private function assert_golden( string $name, string $actual ): void {
		$this->assertSame( $this->normalize( (string) file_get_contents( $this->golden( $name ) ) ), $this->normalize( $actual ), $name );
	}

	public function test_the_comic_card_render_is_unchanged(): void {
		$html = render_block(
			[
				'blockName'    => self::BLOCK,
				'attrs'        => $this->attributes(),
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);

		$this->assert_golden( 'comic-card.golden.html', $html );
	}

	public function test_the_comic_reads_stream_card_is_unchanged(): void {
		$post_id = $this->comic_read();
		$this->go_to( home_url( '/' ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $GLOBALS['post'] );
		$html = do_blocks( '<!-- wp:post-kinds-indieweb/stream-card {"headingLevel":3} /-->' );
		wp_reset_postdata();

		$this->assert_golden( 'comic-read-stream-card.golden.html', $html );
	}

	public function test_a_comic_read_has_no_kind_facts(): void {
		$post_id = $this->comic_read();

		$this->assertSame( [], Kind_Facts::get( $post_id ) );
	}

	public function test_the_card_still_maps_to_comics_and_saves_its_status_and_title(): void {
		$post_id = $this->comic_read();

		$this->assertSame( 'comics', Taxonomy::KIND_CARD_BLOCKS[ self::BLOCK ] );
		$this->assertSame( 'finished', get_post_meta( $post_id, '_pkiw_comic_status', true ) );
		$this->assertSame( 'Saga', get_post_meta( $post_id, '_pkiw_comic_title', true ) );
		$this->assertSame( 'Saga', get_post_meta( $post_id, '_pkiw_comic_series', true ) );
	}

	public function test_the_unfiltered_comics_archive_query_is_unchanged(): void {
		$this->comic_read();
		$term = get_term_by( 'slug', 'comics', Taxonomy::TAXONOMY );
		$this->go_to( (string) get_term_link( $term ) );
		global $wp_query;

		$sql = (string) preg_replace( [ '/\b\d+\b/', '/' . preg_quote( $GLOBALS['wpdb']->prefix, '/' ) . '/' ], [ 'N', 'wp_' ], (string) $wp_query->request );
		$this->assertEmpty( $wp_query->get( 'meta_query' ) );
		$this->assertSame( '', (string) $wp_query->get( 'pkiw_group_source' ) );
		$this->assert_golden( 'comic-read-archive-query.golden.sql', $sql );
	}
}

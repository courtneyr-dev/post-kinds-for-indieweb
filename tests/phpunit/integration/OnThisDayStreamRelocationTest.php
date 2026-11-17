<?php
/** On This Day Stream relocation coverage. @package PKIW */
declare(strict_types=1);

use PKIW\On_This_Day;
use PKIW\Taxonomy;

/** @group integration */
final class OnThisDayStreamRelocationTest extends WP_UnitTestCase {
	private DateTimeImmutable $today;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->today = new DateTimeImmutable( '2026-10-11 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
		On_This_Day::reset();
	}

	public function tear_down(): void {
		unset( $_GET['query-1-page'] );
		wp_reset_postdata();
		On_This_Day::override_now( null );
		On_This_Day::reset();
		parent::tear_down();
	}

	public function test_page_one_inserts_one_card_immediately_before_the_first_item(): void {
		$pick = $this->memory();
		$page = $this->page();
		$this->prime_page_request( $page );
		$this->assertSame( '', On_This_Day::render_block( [ 'context' => 'stream', 'headingLevel' => 3 ] ) );
		$list = '<ul><li class="wp-block-post post-999">Normal</li></ul>';
		$html = On_This_Day::relocate_stream_card( $list, [], new WP_Block( [ 'blockName' => 'core/post-template', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'queryId' => 1 ] ) );
		$this->assertSame( 1, substr_count( $html, 'li class="wp-block-post pkiw-otd-stream-item"' ) );
		$this->assertLessThan( strpos( $html, 'post-999' ), strpos( $html, 'pkiw-otd-stream-item' ) );
		$this->assertStringContainsString( 'article class="pk-card pk-card--stream pkiw-otd pkiw-otd--stream h-cite"', $html );
		$this->assertStringContainsString( 'A year ago today', $html );
		$this->assertStringContainsString( get_permalink( $pick ), $html );
		$this->assertStringNotContainsString( 'h-entry', $html );
	}

	public function test_page_two_existing_pick_empty_template_and_reset_do_not_insert(): void {
		$pick = $this->memory();
		$page = $this->page();
		$this->prime_page_request( $page );
		On_This_Day::render_block( [ 'context' => 'stream' ] );
		$_GET['query-1-page'] = '2';
		$loop = new WP_Block( [ 'blockName' => 'core/post-template', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'queryId' => 1 ] );
		$this->assertSame( '<ul><li>Normal</li></ul>', On_This_Day::relocate_stream_card( '<ul><li>Normal</li></ul>', [], $loop ) );

		unset( $_GET['query-1-page'] );
		On_This_Day::render_block( [ 'context' => 'stream' ] );
		$listed = '<ul><li class="wp-block-post post-' . $pick . '">Pick</li></ul>';
		$this->assertSame( $listed, On_This_Day::relocate_stream_card( $listed, [], $loop ) );
		On_This_Day::render_block( [ 'context' => 'stream' ] );
		$this->assertSame( '', On_This_Day::relocate_stream_card( '', [], $loop ) );
		On_This_Day::render_block( [ 'context' => 'stream' ] );
		On_This_Day::reset();
		$this->assertSame( '<ul><li>Next request</li></ul>', On_This_Day::relocate_stream_card( '<ul><li>Next request</li></ul>', [], $loop ) );
	}

	private function prime_page_request( int $page_id ): void {
		$this->go_to( get_permalink( $page_id ) );
		$GLOBALS['post'] = get_post( $page_id );
		setup_postdata( $GLOBALS['post'] );
		On_This_Day::override_headers_for_tests( static function (): void {} );
		On_This_Day::send_day_headers();
		$this->assertTrue( On_This_Day::is_uncached() );
	}

	private function page(): int {
		return self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:post-kinds-indieweb/on-this-day {"context":"stream"} /-->' ] );
	}

	private function memory(): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Memory', 'post_date' => '2025-10-11 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		return $id;
	}
}

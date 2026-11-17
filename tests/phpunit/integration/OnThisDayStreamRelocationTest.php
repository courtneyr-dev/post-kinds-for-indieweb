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
		On_This_Day::register_blocks();
		$this->today = new DateTimeImmutable( '2026-10-11 12:00:00', wp_timezone() );
		On_This_Day::override_now( $this->today );
		On_This_Day::reset();
	}

	public function tear_down(): void {
		unset( $_GET['query-1-page'] );
		wp_reset_postdata();
		On_This_Day::override_now( null );
		On_This_Day::reset();
		if ( WP_Block_Patterns_Registry::get_instance()->is_registered( 'pkiw-test/on-this-day' ) ) {
			unregister_block_pattern( 'pkiw-test/on-this-day' );
		}
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

	public function test_query_page_values_follow_cores_exact_page_expression(): void {
		$this->memory();
		$page = $this->page();
		$loop = new WP_Block( [ 'blockName' => 'core/post-template', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'queryId' => 1 ] );
		foreach ( [ '-1' => false, '0' => true, 'abc' => false, '1' => true ] as $value => $expected ) {
			On_This_Day::reset();
			$this->prime_page_request( $page );
			On_This_Day::render_block( [ 'context' => 'stream' ] );
			$_GET['query-1-page'] = $value;
			$html = On_This_Day::relocate_stream_card( '<ul><li class="post-999">Normal</li></ul>', [], $loop );
			$this->assertSame( $expected, str_contains( $html, 'pkiw-otd-stream-item' ), (string) $value );
		}
	}

	public function test_only_first_post_template_after_block_is_changed_and_absent_block_changes_nothing(): void {
		$this->memory();
		$page = $this->page();
		$this->prime_page_request( $page );
		$loop = new WP_Block( [ 'blockName' => 'core/post-template', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ], [ 'queryId' => 1 ] );
		$first = '<ul><li class="post-999">First</li></ul>';
		$second = '<ul><li class="post-998">Second</li></ul>';
		$this->assertSame( '', On_This_Day::render_block( [ 'context' => 'stream' ] ), 'The block position emits zero bytes.' );
		$this->assertStringContainsString( 'pkiw-otd-stream-item', On_This_Day::relocate_stream_card( $first, [], $loop ) );
		$this->assertSame( $second, On_This_Day::relocate_stream_card( $second, [], $loop ) );
		On_This_Day::reset();
		$this->assertSame( $first, On_This_Day::relocate_stream_card( $first, [], $loop ), 'No block leaves the first Post Template unchanged.' );
	}

	public function test_rest_and_outside_page_content_never_relocate_the_card(): void {
		$this->memory();
		$page = $this->page_with_loop( true );
		$response = rest_do_request( '/wp/v2/pages/' . $page );
		$this->assertStringNotContainsString( 'pkiw-otd', (string) ( $response->get_data()['content']['rendered'] ?? '' ) );

		$other = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Other request post' ] );
		$this->go_to( get_permalink( $other ) );
		$GLOBALS['post'] = get_post( $page );
		setup_postdata( $GLOBALS['post'] );
		$html = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $page ) );
		$this->assertStringNotContainsString( 'pkiw-otd', $html );
		$this->assertNull( $this->state( 'pending' ) );
	}

	public function test_block_after_query_loop_leaves_no_card_or_pending_state(): void {
		$this->memory();
		$page = $this->page_with_loop( false );
		$this->prime_page_request( $page );
		$html = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $page ) );
		$this->assertStringNotContainsString( 'pkiw-otd-stream-item', $html );
		$this->assertNull( $this->state( 'pending' ) );
		$this->assertSame( 0, $this->state( 'content_depth' ) );
	}

	public function test_nested_excerpt_content_keeps_one_relocation_and_restores_depth_zero(): void {
		$this->memory();
		$filler = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Generic Stream filler', 'post_content' => 'Body used to synthesize an excerpt.', 'post_excerpt' => '', 'post_date' => '2026-10-10 12:00:00' ] );
		wp_set_object_terms( $filler, 'note', Taxonomy::TAXONOMY );
		$page = $this->page_with_loop( true );
		$this->prime_page_request( $page );
		$html = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $page ) );
		$this->assertSame( 1, substr_count( $html, 'pkiw-otd-stream-item' ) );
		$this->assertSame( 0, $this->state( 'content_depth' ) );
		$this->assertNull( $this->state( 'pending' ) );
	}

	public function test_template_part_template_pattern_and_synced_pattern_cannot_create_pending_state(): void {
		$this->memory();
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>No saved block.</p><!-- /wp:paragraph -->' ] );
		$this->go_to( get_permalink( $page ) );
		$GLOBALS['post'] = get_post( $page );
		setup_postdata( $GLOBALS['post'] );
		On_This_Day::override_headers_for_tests( static function (): void {} );
		On_This_Day::send_day_headers();
		$this->assertFalse( On_This_Day::is_uncached() );
		$block = '<!-- wp:post-kinds-indieweb/on-this-day {"context":"stream"} /-->';
		register_block_pattern( 'pkiw-test/on-this-day', [ 'title' => 'OTD test', 'content' => $block ] );
		$synced = self::factory()->post->create( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => $block ] );
		$template_part = self::factory()->post->create( [ 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => 'otd-test-part', 'post_content' => $block ] );
		wp_set_object_terms( $template_part, get_stylesheet(), 'wp_theme' );
		$contexts = [
			'template string' => $block,
			'page-stream template' => '<!-- wp:group --><div class="wp-block-group">' . $block . '</div><!-- /wp:group -->',
			'template part' => '<!-- wp:template-part {"slug":"otd-test-part","theme":"' . get_stylesheet() . '"} /-->',
			'core pattern' => '<!-- wp:pattern {"slug":"pkiw-test/on-this-day"} /-->',
			'synced pattern' => '<!-- wp:block {"ref":' . $synced . '} /-->',
		];
		foreach ( $contexts as $name => $markup ) {
			On_This_Day::reset();
			On_This_Day::override_headers_for_tests( static function (): void {} );
			$this->assertStringNotContainsString( 'pkiw-otd', do_blocks( $markup ), $name );
			$this->assertNull( $this->state( 'pending' ), $name );
		}
	}

	public function test_headers_already_sent_prevents_card_and_pending_state(): void {
		$this->memory();
		$page = $this->page_with_loop( true );
		$this->go_to( get_permalink( $page ) );
		$GLOBALS['post'] = get_post( $page );
		setup_postdata( $GLOBALS['post'] );
		On_This_Day::override_headers_for_tests( static function (): void {}, true );
		On_This_Day::send_day_headers();
		$this->assertFalse( On_This_Day::is_uncached() );
		$html = (string) apply_filters( 'the_content', (string) get_post_field( 'post_content', $page ) );
		$this->assertStringNotContainsString( 'pkiw-otd-stream-item', $html );
		$this->assertNull( $this->state( 'pending' ) );
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

	private function page_with_loop( bool $block_first ): int {
		$block = '<!-- wp:post-kinds-indieweb/on-this-day {"context":"stream"} /-->';
		$query = '<!-- wp:query {"queryId":1,"query":{"perPage":1,"postType":"post","order":"desc","orderBy":"date","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/stream-card /--><!-- /wp:post-template --></div><!-- /wp:query -->';
		$content = $block_first ? $block . $query : $query . $block;
		return self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content ] );
	}

	private function memory(): int {
		$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Memory', 'post_date' => '2025-10-11 10:00:00' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		return $id;
	}

	private function state( string $property ): mixed {
		$reflection = new ReflectionProperty( On_This_Day::class, $property );
		return $reflection->getValue();
	}
}

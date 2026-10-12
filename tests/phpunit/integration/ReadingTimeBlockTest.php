<?php
/**
 * Reading time block rendering privacy (issue 263).
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class ReadingTimeBlockTest extends WP_UnitTestCase {

	private const BLOCK = 'post-kinds-indieweb/reading-time';

	public function set_up(): void {
		parent::set_up();
		$this->register_reading_time();
	}

	public function tear_down(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_the_block_registers_inside_post_templates(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertNotNull( $type );
		$this->assertSame( 3, $type->api_version );
		$this->assertSame( [ 'core/post-template' ], $type->ancestor );
		$this->assertContains( 'postId', $type->uses_context );
		$this->assertContains( 'postType', $type->uses_context );
	}

	public function test_a_query_post_template_prints_reading_time_after_the_title(): void {
		$post_id = $this->article( 1000, 'Long Article' );
		$this->go_to( home_url( '/' ) );

		$html = do_blocks(
			'<!-- wp:query {"queryId":7,"query":{"inherit":false,"postType":"post","perPage":1,"include":[' . $post_id . ']}} --><div class="wp-block-query">'
			. '<!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- wp:post-kinds-indieweb/reading-time /--><!-- /wp:post-template -->'
			. '</div><!-- /wp:query -->'
		);

		$this->assertStringContainsString( 'wp-block-post-kinds-indieweb-reading-time', $html );
		$this->assertStringContainsString( '5 min read', $html );
		$this->assertLessThan( strpos( $html, '5 min read' ), strpos( $html, 'Long Article' ) );
	}

	public function test_a_protected_post_prints_no_reading_time_block(): void {
		$post_id = $this->article( 1000, 'Locked Article', [ 'post_password' => 'secret' ] );

		$html = $this->render_reading_time_for_post( $post_id );

		$this->assertSame( '', $html );
	}

	public function test_a_reading_time_block_in_post_content_does_not_print_in_the_content(): void {
		$post_id = $this->article( 1000, 'Pasted Block', [ 'post_content' => '<!-- wp:post-kinds-indieweb/reading-time /-->' ] );
		$GLOBALS['post'] = get_post( $post_id );

		$this->assertStringNotContainsString( 'min read', apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) ) );
	}

	public function test_a_reading_time_block_in_post_content_does_not_print_in_rss2(): void {
		$post_id = $this->article( 1000, 'Feed Block', [ 'post_content' => '<!-- wp:post-kinds-indieweb/reading-time /-->' ] );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertStringNotContainsString( 'min read', get_echo( 'do_feed_rss2' ) );
	}

	public function test_a_reading_time_block_in_post_content_does_not_print_in_rest_content(): void {
		$post_id = $this->article( 1000, 'REST Block', [ 'post_content' => '<!-- wp:post-kinds-indieweb/reading-time /-->' ] );

		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data();

		$this->assertStringNotContainsString( 'min read', (string) $data['content']['rendered'] );
	}

	public function test_a_reading_time_block_in_post_content_does_not_print_in_the_embed_excerpt(): void {
		$post_id = $this->article( 1000, 'Embed Block', [ 'post_content' => '<!-- wp:post-kinds-indieweb/reading-time /-->' ] );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['wp_query']->post = get_post( $post_id );
		$GLOBALS['wp_query']->posts = [ get_post( $post_id ) ];
		$GLOBALS['wp_query']->current_post = -1;

		$this->assertStringNotContainsString( 'min read', get_echo( 'the_excerpt_embed' ) );
	}

	public function test_template_depth_returns_to_zero_after_a_post_template_with_posts(): void {
		$this->article( 1000, 'Depth Article' );

		do_blocks( '<!-- wp:query {"queryId":7,"query":{"inherit":false,"postType":"post","perPage":1}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/reading-time /--><!-- /wp:post-template --></div><!-- /wp:query -->' );

		$this->assertSame( 0, $this->template_depth() );
	}

	public function test_template_depth_returns_to_zero_after_an_empty_post_template(): void {
		do_blocks( '<!-- wp:query {"queryId":7,"query":{"inherit":false,"postType":"post","perPage":1,"search":"issue-263-no-posts-here"}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-kinds-indieweb/reading-time /--><!-- /wp:post-template --></div><!-- /wp:query -->' );

		$this->assertSame( 0, $this->template_depth() );
	}

	public function test_the_block_renderer_request_prints_reading_time_for_its_post_id(): void {
		$post_id = $this->article( 1000, 'Renderer Article' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . self::BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertStringContainsString( '5 min read', (string) $response->get_data()['rendered'] );
	}

	private function register_reading_time(): void {
		$this->assertTrue( class_exists( 'PKIW\\Reading_Time' ), 'PKIW\\Reading_Time must exist for issue 263.' );
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			( new \PKIW\Reading_Time() )->register();
			do_action( 'init' );
		}
	}

	/**
	 * @param array<string, mixed> $args Extra post args.
	 */
	private function article( int $words, string $title, array $args = [] ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'word ', $words ) . '</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);

		return $post_id;
	}

	private function render_reading_time_for_post( int $post_id ): string {
		$parsed = [
			'blockName'    => self::BLOCK,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		];
		$block = new WP_Block( $parsed, [ 'postId' => $post_id, 'postType' => 'post' ] );

		return \PKIW\Reading_Time::render( [], '', $block );
	}

	private function template_depth(): int {
		$property = new ReflectionProperty( \PKIW\Reading_Time::class, 'template_depth' );
		$property->setAccessible( true );

		return (int) $property->getValue();
	}
}

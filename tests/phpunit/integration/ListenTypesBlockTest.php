<?php
/**
 * Listen type navigation block (issue 236).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Listen_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ListenTypesBlockTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		$this->original_timezone   = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Listen_Archive() )->register();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function archive_url(): string {
		return (string) get_term_link( 'listen', Taxonomy::TAXONOMY );
	}

	private function render(): string {
		return render_block(
			[
				'blockName'    => Listen_Archive::TYPES_BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * @return array<int, array{label:string, href:string, current:string}>
	 */
	private function links( string $html ): array {
		$xpath = $this->xpath( $html );
		$links = [];
		foreach ( $xpath->query( '//nav/ul/li/a' ) as $link ) {
			$links[] = [
				'label'   => trim( $link->textContent ),
				'href'    => $link->getAttribute( 'href' ),
				'current' => $link->getAttribute( 'aria-current' ),
			];
		}

		return $links;
	}

	public function test_markup_has_the_exact_structure_classes_and_links(): void {
		$base = $this->archive_url();
		$this->go_to( $base );
		$html = $this->render();

		$this->assertSame(
			'<nav class="wp-block-post-kinds-indieweb-listen-types" aria-label="Listen types"><ul class="pk-listen-types__list"><li class="pk-listen-types__item"><a class="pk-listen-types__link" href="' . esc_url( $base ) . '" aria-current="page">All</a></li><li class="pk-listen-types__item"><a class="pk-listen-types__link" href="' . esc_url( add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', $base ) ) . '">Podcasts</a></li></ul></nav>',
			$html
		);
	}

	public function test_current_link_tracks_the_archive_view_and_is_absent_elsewhere(): void {
		$base = $this->archive_url();
		$this->go_to( $base );
		$this->assertSame( [ 'All' ], wp_list_pluck( wp_list_filter( $this->links( $this->render() ), [ 'current' => 'page' ] ), 'label' ) );

		$this->go_to( add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', $base ) );
		$this->assertSame( [ 'Podcasts' ], array_values( wp_list_pluck( wp_list_filter( $this->links( $this->render() ), [ 'current' => 'page' ] ), 'label' ) ) );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( [], wp_list_filter( $this->links( $this->render() ), [ 'current' => 'page' ] ) );
	}

	public function test_block_renderer_preview_opens_with_all_current(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Listen_Archive::TYPES_BLOCK );
		$request->set_param( 'context', 'edit' );
		apply_filters( 'rest_request_before_callbacks', null, null, $request );
		$this->assertTrue( Grouped_Archive::is_block_preview() );
		apply_filters( 'rest_request_after_callbacks', null, null, $request );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( [ 'All' ], wp_list_pluck( wp_list_filter( $this->links( (string) $response->get_data()['rendered'] ), [ 'current' => 'page' ] ), 'label' ) );
		$this->assertFalse( Grouped_Archive::is_block_preview() );
	}

	public function test_the_php_block_registration_needs_no_block_json_or_front_end_script(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Listen_Archive::TYPES_BLOCK );

		$this->assertNotNull( $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertContains( 'pkiw-listen-types-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-listen-types-editor', 'registered' ) );
		$this->assertSame( [], $block->script_handles, 'The links need no front-end JavaScript.' );
		$this->assertFileDoesNotExist( PKIW_PATH . 'src/blocks/listen-types/block.json' );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}
}

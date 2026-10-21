<?php
/**
 * Comic series navigation block (issue 266).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Comic_Strip;
use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ComicSeriesBlockTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Meta_Fields() )->register_meta_fields();
		( new Taxonomy() )->create_default_terms();
		( new Comic_Strip() )->register();
		Comic_Strip::reset_series_cache();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	private function gallery( int $attachment_id ): string {
		return '<!-- wp:gallery --><figure class="wp-block-gallery has-nested-images"><!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img src="https://example.org/' . $attachment_id . '.jpg" alt="Panel" /><figcaption class="wp-element-caption">Transcript</figcaption></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
	}

	/**
	 * Create a comics strip for the series index.
	 *
	 * @param string               $series Series value.
	 * @param array<string, mixed> $args   Post overrides.
	 */
	private function strip( string $series, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => '' !== $series ? $series : 'Standalone',
					'post_content' => $this->gallery( self::factory()->attachment->create() ),
				],
				$args
			)
		);
		wp_set_object_terms( $id, Comic_Strip::KIND, Taxonomy::TAXONOMY );
		if ( '' !== $series ) {
			update_post_meta( $id, Comic_Strip::SERIES_META, $series );
		}
		Comic_Strip::reset_series_cache();

		return (int) $id;
	}

	private function archive_url(): string {
		return (string) get_term_link( Comic_Strip::KIND, Taxonomy::TAXONOMY );
	}

	private function drawn_url( string $series = '' ): string {
		$args = [ Comic_Strip::VIEW_VAR => Comic_Strip::VIEW_DRAWN ];
		if ( '' !== $series ) {
			$args[ Comic_Strip::SERIES_VAR ] = $series;
		}

		return add_query_arg( $args, $this->archive_url() );
	}

	/**
	 * Render the dynamic block.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function render( array $attrs = [] ): string {
		return render_block(
			[
				'blockName'    => Comic_Strip::SERIES_BLOCK,
				'attrs'        => $attrs,
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * Read navigation links.
	 *
	 * @return array<int, array{label:string,href:string,current:string}>
	 */
	private function links( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
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

	public function test_nav_lists_public_series_in_heading_order_with_custom_labels(): void {
		$this->strip( 'Series 10' );
		$this->strip( 'Series 2' );
		$this->strip( 'Field Notes', [ 'post_date' => '2026-10-01 10:00:00' ] );
		$this->strip( 'field notes', [ 'post_date' => '2026-10-02 10:00:00' ] );
		$this->strip( 'Secret Series', [ 'post_password' => 'secret' ] );
		$this->strip( 'Private Series', [ 'post_status' => 'private' ] );
		$this->strip( 'Draft Series', [ 'post_status' => 'draft' ] );
		$this->go_to( $this->drawn_url() );
		$html = $this->render( [ 'label' => 'Browse series', 'allLabel' => 'Every strip' ] );
		$links = $this->links( $html );

		$this->assertStringContainsString( 'aria-label="Browse series"', $html );
		$this->assertSame( [ 'Every strip', 'field notes', 'Series 10', 'Series 2' ], wp_list_pluck( $links, 'label' ) );
		$this->assertSame( [ 'Every strip' ], wp_list_pluck( wp_list_filter( $links, [ 'current' => 'page' ] ), 'label' ) );
		$this->assertStringNotContainsString( Comic_Strip::SERIES_VAR, $links[0]['href'] );
		foreach ( [ 'Secret Series', 'Private Series', 'Draft Series', 'Field Notes' ] as $hidden ) {
			$this->assertNotContains( $hidden, wp_list_pluck( $links, 'label' ) );
		}
	}

	public function test_exactly_the_valid_requested_series_is_current(): void {
		$this->strip( 'Field Notes' );
		$this->strip( 'Small Web Stories' );
		$this->go_to( $this->drawn_url( 'Small Web Stories' ) );
		$links = $this->links( $this->render() );

		$this->assertSame( [ 'Small Web Stories' ], array_values( wp_list_pluck( wp_list_filter( $links, [ 'current' => 'page' ] ), 'label' ) ) );
		$this->assertStringContainsString( Comic_Strip::SERIES_VAR, $links[2]['href'] );
	}

	public function test_unknown_and_hostile_series_values_select_all_without_echoing_input(): void {
		$post_id = $this->strip( 'Field Notes' );
		$hostile = '"><script>alert(1)</script>';
		$this->go_to( $this->drawn_url( $hostile ) );
		$html = $this->render();
		$links = $this->links( $html );

		$this->assertContains( $post_id, array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) ) );
		$this->assertStringNotContainsString( $hostile, $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( [ 'All series' ], wp_list_pluck( wp_list_filter( $links, [ 'current' => 'page' ] ), 'label' ) );
	}

	public function test_series_cache_resets_when_stored_series_changes(): void {
		$post_id = $this->strip( 'Field Notes' );
		$this->assertSame( [ 'Field Notes' ], Comic_Strip::series_values() );

		update_post_meta( $post_id, Comic_Strip::SERIES_META, 'Small Web Stories' );
		$this->assertSame( [ 'Small Web Stories' ], Comic_Strip::series_values() );

		delete_post_meta( $post_id, Comic_Strip::SERIES_META );
		$this->assertSame( [], Comic_Strip::series_values() );
	}

	public function test_block_prints_nothing_off_drawn_view_or_without_public_series(): void {
		$this->strip( 'Field Notes' );
		$this->go_to( $this->archive_url() );
		$this->assertSame( '', $this->render() );

		foreach ( get_posts( [ 'post_type' => 'post', 'numberposts' => -1 ] ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
		Comic_Strip::reset_series_cache();
		$this->strip( '' );
		$this->go_to( $this->drawn_url() );
		$this->assertSame( '', $this->render() );
	}

	public function test_empty_label_attributes_fall_back_to_translated_defaults(): void {
		$this->strip( 'Field Notes' );
		$this->go_to( $this->drawn_url() );
		$html = $this->render( [ 'label' => '  ', 'allLabel' => '' ] );

		$this->assertStringContainsString( 'aria-label="Series"', $html );
		$this->assertSame( 'All series', $this->links( $html )[0]['label'] );
	}

	public function test_block_renderer_preview_marks_all_series_current(): void {
		$this->strip( 'Field Notes' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Comic_Strip::SERIES_BLOCK );
		$request->set_param( 'context', 'edit' );
		apply_filters( 'rest_request_before_callbacks', null, null, $request );
		$this->assertTrue( Grouped_Archive::is_block_preview() );
		apply_filters( 'rest_request_after_callbacks', null, null, $request );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$links = $this->links( (string) $response->get_data()['rendered'] );
		$this->assertSame( [ 'All series' ], wp_list_pluck( wp_list_filter( $links, [ 'current' => 'page' ] ), 'label' ) );
		$this->assertFalse( Grouped_Archive::is_block_preview() );
	}

	public function test_php_registration_has_no_block_json_or_front_end_script(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Comic_Strip::SERIES_BLOCK );

		$this->assertNotNull( $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertContains( 'pkiw-comic-series-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-comic-series-editor', 'registered' ) );
		$this->assertSame( [], $block->script_handles );
		$this->assertFileDoesNotExist( PKIW_PATH . 'src/blocks/comic-series/block.json' );
	}

	public function test_series_labels_are_escaped_in_text_and_attributes(): void {
		$this->strip( 'Tom & Jerry "quoted"' );
		$this->go_to( $this->drawn_url() );
		$html = $this->render();

		$this->assertStringContainsString( '>Tom &amp; Jerry &quot;quoted&quot;</a>', $html );
		$this->assertStringNotContainsString( '>Tom & Jerry "quoted"</a>', $html );
	}

	public function test_any_case_variant_of_the_series_var_selects_the_merged_series(): void {
		$old   = $this->strip( 'Field Notes', [ 'post_date' => '2026-10-01 10:00:00' ] );
		$new   = $this->strip( 'field notes', [ 'post_date' => '2026-10-02 10:00:00' ] );
		$other = $this->strip( 'Small Web Stories', [ 'post_date' => '2026-10-03 10:00:00' ] );

		foreach ( [ 'field notes', 'FIELD NOTES', 'Field Notes', '  Field notes ' ] as $requested ) {
			$this->go_to( $this->drawn_url( $requested ) );
			$ids = array_map( 'intval', wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
			$this->assertEqualsCanonicalizing( [ $old, $new ], $ids, $requested );
			$this->assertNotContains( $other, $ids, $requested );

			$links = $this->links( $this->render() );
			$this->assertSame( [ 'field notes' ], array_values( wp_list_pluck( wp_list_filter( $links, [ 'current' => 'page' ] ), 'label' ) ), $requested );
		}

		$this->go_to( $this->drawn_url( 'Not a series' ) );
		$this->assertCount( 3, $GLOBALS['wp_query']->posts );
	}
}

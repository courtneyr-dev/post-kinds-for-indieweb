<?php
/**
 * The Read order block (issue 234).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Read_Archive;
use PKIW\Taxonomy;

/**
 * Two links above the read archive: All (the shelves) and A–Z by author.
 * Each is the archive's own URL, so the order works without JavaScript and
 * the pager keeps it. The link for the view on screen carries
 * `aria-current="page"`. The block is server-rendered, so the Site Editor
 * prints the same links, with All current. Titles are invented.
 *
 * @group integration
 */
final class ReadOrderBlockTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		// Pretty permalinks, as the site runs: /kind/read/page/2/. The kind
		// taxonomy registered before the structure was set, so add its permastruct.
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	private function archive_url(): string {
		$url = get_term_link( 'read', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/read/', $url );

		return $url;
	}

	private function render(): string {
		return render_block(
			[
				'blockName'    => Read_Archive::ORDER_BLOCK,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
	}

	/**
	 * The nav's accessible name and its links in document order.
	 *
	 * @param string $html Rendered block.
	 * @return array{label: string, links: array<int, array{label: string, href: string, current: string}>}
	 */
	private function nav( string $html ): array {
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		$xpath = new DOMXPath( $dom );
		$nav   = $xpath->query( '//nav' )->item( 0 );
		$links = [];
		foreach ( $xpath->query( '//nav/ul/li/a' ) as $a ) {
			$links[] = [
				'label'   => trim( $a->textContent ),
				'href'    => $a->getAttribute( 'href' ),
				'current' => $a->getAttribute( 'aria-current' ),
			];
		}

		return [
			'label' => $nav instanceof DOMElement ? $nav->getAttribute( 'aria-label' ) : '',
			'links' => $links,
		];
	}

	/**
	 * The two links with the given one current.
	 *
	 * @param string $current 'all', 'author' or '' for neither.
	 * @return array<int, array{label: string, href: string, current: string}>
	 */
	private function expected( string $current ): array {
		$base = $this->archive_url();

		return [
			[
				'label'   => 'All',
				'href'    => $base,
				'current' => 'all' === $current ? 'page' : '',
			],
			[
				'label'   => 'A–Z by author',
				'href'    => add_query_arg( Read_Archive::QUERY_VAR, 'author', $base ),
				'current' => 'author' === $current ? 'page' : '',
			],
		];
	}

	public function test_the_block_is_registered_with_an_editor_script(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Read_Archive::ORDER_BLOCK );

		$this->assertNotNull( $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertTrue( $block->is_dynamic() );
		$this->assertContains( 'pkiw-read-order-editor', $block->editor_script_handles );
		$this->assertTrue( wp_script_is( 'pkiw-read-order-editor', 'registered' ) );
		$this->assertStringEndsWith( 'assets/js/read-order-editor.js', wp_scripts()->registered['pkiw-read-order-editor']->src );
		$this->assertFalse( $block->supports['html'] ?? true );
	}

	public function test_on_the_archive_all_is_current(): void {
		$this->go_to( $this->archive_url() );
		$nav = $this->nav( $this->render() );

		$this->assertSame( 'Read order', $nav['label'] );
		$this->assertSame( $this->expected( 'all' ), $nav['links'] );
	}

	public function test_in_the_a_to_z_view_its_link_is_current_on_every_page(): void {
		$this->go_to( add_query_arg( Read_Archive::QUERY_VAR, 'author', $this->archive_url() ) );
		$this->assertSame( $this->expected( 'author' ), $this->nav( $this->render() )['links'] );

		foreach ( self::factory()->post->create_many( 13, [ 'post_status' => 'publish' ] ) as $id ) {
			wp_set_object_terms( $id, 'read', Taxonomy::TAXONOMY );
		}
		$this->go_to( add_query_arg( Read_Archive::QUERY_VAR, 'author', $this->archive_url() . 'page/2/' ) );
		$this->assertSame( $this->expected( 'author' ), $this->nav( $this->render() )['links'], 'Both links go to page 1 of their view.' );
	}

	public function test_an_order_the_archive_does_not_take_leaves_all_current(): void {
		$this->go_to( add_query_arg( Read_Archive::QUERY_VAR, 'title', $this->archive_url() ) );

		$this->assertSame( $this->expected( 'all' ), $this->nav( $this->render() )['links'] );
	}

	public function test_away_from_the_read_archive_no_link_is_current(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( $this->expected( '' ), $this->nav( $this->render() )['links'] );

		$eat = get_term_link( 'eat', Taxonomy::TAXONOMY );
		$this->assertIsString( $eat );
		$this->go_to( add_query_arg( Read_Archive::QUERY_VAR, 'author', $eat ) );
		$this->assertSame( $this->expected( '' ), $this->nav( $this->render() )['links'] );
	}

	public function test_the_editor_preview_shows_all_current(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Read_Archive::ORDER_BLOCK );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$nav = $this->nav( (string) $response->get_data()['rendered'] );
		$this->assertSame( 'Read order', $nav['label'] );
		$this->assertSame( $this->expected( 'all' ), $nav['links'] );
	}

	public function test_without_a_read_term_the_block_prints_nothing(): void {
		$term = get_term_by( 'slug', 'read', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );

		$this->assertSame( '', $this->render() );
	}
}

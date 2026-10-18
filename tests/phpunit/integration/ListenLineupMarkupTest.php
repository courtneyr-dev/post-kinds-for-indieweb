<?php
/**
 * Podcast lineup archive markup and pagination (issue 236).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Listen_Archive;
use PKIW\Listen_Media_Type;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class ListenLineupMarkupTest extends WP_UnitTestCase {

	private string $original_stylesheet = '';

	private string $original_timezone = '';

	private string $original_date_format = '';

	/**
	 * @var array<string, int>
	 */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet  = get_stylesheet();
		$this->original_timezone    = (string) get_option( 'timezone_string' );
		$this->original_date_format = (string) get_option( 'date_format' );
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'date_format', 'F j, Y' );
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
		update_option( 'date_format', $this->original_date_format );
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Add one planned fixture.
	 *
	 * @param string               $key     Plan key.
	 * @param string               $episode Episode name.
	 * @param string               $show    Show name.
	 * @param string               $date    Published date.
	 * @param array<string, mixed> $args    Post overrides.
	 * @param array<string, mixed> $meta    Meta overrides.
	 */
	private function add( string $key, string $episode, string $show, string $date, array $args = [], array $meta = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'Listened to ' . $episode,
					'post_name'    => 'seed-236-' . strtolower( $key ),
					'post_date'    => $date,
					'post_content' => '<!-- wp:paragraph --><p>Listened to &quot;' . esc_html( $episode ) . '&quot; from ' . esc_html( $show ) . '.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'listen', Taxonomy::TAXONOMY );
		$values = array_merge(
			[
				Listen_Media_Type::META => 'podcast',
				'_pkiw_listen_track'     => $episode,
				'_pkiw_listen_artist'    => $show,
				'_pkiw_listen_album'     => $show,
				'_pkiw_source'           => 'Example Source',
			],
			$meta
		);
		foreach ( $values as $meta_key => $value ) {
			update_post_meta( $id, $meta_key, $value );
		}
		$this->ids[ $key ] = (int) $id;

		return (int) $id;
	}

	private function local_cover( int $post_id ): int {
		$image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		set_post_thumbnail( $post_id, $image );

		return $image;
	}

	private function seed(): void {
		$this->add( 'A01', 'Arrival Window', 'alder street radio', '2026-08-28 10:00:00' );
		$this->add( 'A02', 'Bridge Frequency', 'alder street radio', '2026-08-27 10:00:00' );
		$this->local_cover( $this->ids['A02'] );
		$this->add( 'A03', 'Copper Wire', 'alder street radio', '2026-08-14 10:00:00', [], [ '_pkiw_listen_listened_at' => '2026-08-02' ] );
		$this->add( 'A04', 'Distant Relay', 'alder street radio', '2026-08-13 10:00:00' );
		$this->add( 'A05', 'Evening Carrier', 'alder street radio', '2026-08-12 10:00:00', [], [ '_pkiw_listen_listened_at' => '2026-07-31T21:30:00' ] );

		foreach ( [ 'B01' => 'Brass One', 'B02' => 'Brass Two', 'B03' => 'Brass Three', 'B04' => 'Brass Four' ] as $key => $episode ) {
			$this->add( $key, $episode, 'Brass Tacks FM', '2026-08-' . ( 'B03' === $key || 'B04' === $key ? '20' : ( 'B01' === $key ? '22' : '21' ) ) . ' 10:00:00' );
		}

		$this->add( 'C01', 'Season Finale', 'Copper Kettle Hour', '2026-08-19 10:00:00' );
		$this->add( 'C02', 'Season Finale', 'Copper Kettle Hour', '2026-08-18 10:00:00' );
		$this->add( 'C03', 'Copper Three', 'Copper Kettle Hour', '2026-08-17 10:00:00' );
		$this->local_cover( $this->ids['C03'] );
		$this->add( 'C04', 'Copper Four', 'Copper Kettle Hour', '2026-08-16 10:00:00' );
		$this->add( 'C05', 'Copper Five', 'Copper Kettle Hour', '2026-08-15 10:00:00' );

		$delta = 'Delta Quiet Hours With A Remarkably Long Programme Title That Must Wrap At Every Width Without Clipping Or Ellipsis';
		$this->add( 'D01', substr( str_repeat( 'Long episode title ', 8 ), 0, 130 ), $delta, '2026-08-11 10:00:00' );
		$this->add( 'D02', 'Delta Two', $delta, '2026-08-10 10:00:00', [], [ '_pkiw_listen_cover' => 'https://img.example.org/covers/delta.png' ] );
		$this->add( 'O01', 'Open One', 'Open Source Hours', '2026-08-09 10:00:00' );
		$this->local_cover( $this->ids['O01'] );

		foreach ( [ 'SC1', 'SC2', 'SC3', 'SC4' ] as $at => $key ) {
			$this->add( $key, 'Signal ' . ( $at + 1 ), 'Signal & Craft', sprintf( '2026-08-%02d 10:00:00', 8 - $at ) );
		}
		$this->local_cover( $this->ids['SC1'] );
		$this->add( 'L1', 'Debug One', 'The Long Debug', '2026-08-04 10:00:00' );
		$this->add( 'L2', 'Debug Two', 'The Long Debug', '2026-08-03 10:00:00' );
		$this->add( 'G01', 'Ground One', '', '2026-08-02 10:00:00' );
		$this->local_cover( $this->ids['G01'] );
		$this->add( 'G02', 'Ground Two', '', '2026-08-01 10:00:00' );
		$this->add( 'G03', 'Ground Three', '', '2026-07-31 10:00:00' );
		$this->add( 'H01', 'Hidden One', 'alder street radio', '2026-07-30 10:00:00', [ 'post_password' => 'secret' ] );

		$this->add( 'M01', 'Music Control', 'alder street radio', '2026-08-29 10:00:00', [], [ Listen_Media_Type::META => 'music' ] );
	}

	private function archive_url( int $page = 1 ): string {
		$path = 1 === $page ? '' : 'page/' . $page . '/';

		return add_query_arg( Listen_Archive::QUERY_VAR, 'podcast', get_term_link( 'listen', Taxonomy::TAXONOMY ) . $path );
	}

	private function serve( string $url, string $find = '', string $replace = '' ): string {
		$swap = null;
		if ( '' !== $find ) {
			$swap = static function ( array $templates ) use ( $find, $replace ): array {
				foreach ( $templates as $at => $template ) {
					if ( 'taxonomy-kind-listen-podcast' === $template->slug ) {
						$templates[ $at ] = clone $template;
						$templates[ $at ]->content = str_replace( $find, $replace, $template->content );
					}
				}

				return $templates;
			};
			add_filter( 'get_block_templates', $swap );
		}

		try {
			$this->go_to( $url );
			$template = resolve_block_template(
				'taxonomy',
				[ 'taxonomy-kind-listen-podcast.php', 'taxonomy-kind-listen.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ],
				''
			);
			$this->assertNotNull( $template );
			$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-listen-podcast', $template->id );

			global $_wp_current_template_id, $_wp_current_template_content;
			$_wp_current_template_id      = $template->id;
			$_wp_current_template_content = $template->content;

			return get_the_block_template_html();
		} finally {
			if ( null !== $swap ) {
				remove_filter( 'get_block_templates', $swap );
			}
		}
	}

	public function test_pages_follow_the_frozen_station_map_and_page_four_is_a_404(): void {
		$this->seed();
		$one = $this->station_map( $this->xpath( $this->serve( $this->archive_url( 1 ) ) ) );
		$two = $this->station_map( $this->xpath( $this->serve( $this->archive_url( 2 ) ) ) );
		$three = $this->station_map( $this->xpath( $this->serve( $this->archive_url( 3 ) ) ) );

		$this->assertSame( [ 'alder street radio' => 5, 'Brass Tacks FM' => 4, 'Copper Kettle Hour' => 3 ], $one );
		$this->assertSame( [ 'Copper Kettle Hour' => 2, 'Delta Quiet Hours With A Remarkably Long Programme Title That Must Wrap At Every Width Without Clipping Or Ellipsis' => 2, 'Open Source Hours' => 1, 'Signal & Craft' => 4, 'The Long Debug' => 2, 'Other' => 1 ], $two );
		$this->assertSame( [ 'Other' => 3 ], $three );

		$this->serve( $this->archive_url( 4 ) );
		$this->assertTrue( is_404() );
	}

	public function test_each_line_is_one_episode_link_and_one_date_with_no_card_extras(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url( 1 ) ) . $this->serve( $this->archive_url( 2 ) ) );
		$lines = $xpath->query( '//*[contains(concat(" ",@class," ")," pkiw-episode ")]' );
		$this->assertSame( 24, $lines->length );
		foreach ( $lines as $line ) {
			$this->assertSame( 1, $xpath->query( './/a', $line )->length );
			$this->assertSame( 1, $xpath->query( './/time', $line )->length );
			$this->assertSame( 0, $xpath->query( './/img|.//iframe|.//svg|.//button|.//video|.//audio|.//p', $line )->length );
			$markup = $line->ownerDocument->saveHTML( $line );
			$this->assertDoesNotMatchRegularExpression( '/pk-rating|pk-embed|pk-kindlabel|\bListened\b/', $markup );
		}
		$this->assertStringContainsString( '>Arrival Window</a>', $xpath->document->saveHTML() );
		$this->assertStringNotContainsString( '>Listened to Arrival Window</a>', $xpath->document->saveHTML() );
	}

	public function test_duplicate_episode_names_get_resolving_aria_labels_only_on_the_duplicates(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url( 1 ) ) );
		$links = $xpath->query( '//a[@aria-labelledby]' );
		$this->assertSame( 2, $links->length );
		foreach ( $links as $link ) {
			$ids = preg_split( '/\s+/', trim( $link->getAttribute( 'aria-labelledby' ) ) );
			$this->assertCount( 2, $ids );
			$name = $xpath->query( '//*[@id="' . $ids[0] . '"]' )->item( 0 );
			$date = $xpath->query( '//*[@id="' . $ids[1] . '"]' )->item( 0 );
			$this->assertSame( 'Season Finale', trim( $name->textContent ) );
			$this->assertNotSame( '', trim( $date->textContent ) );
		}
	}

	public function test_listened_dates_override_published_dates_on_the_line(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url( 1 ) ) );

		$this->assertSame( '2026-08-02', $this->line_time( $xpath, $this->ids['A03'] )->getAttribute( 'datetime' ) );
		$this->assertSame( '2026-07-31', $this->line_time( $xpath, $this->ids['A05'] )->getAttribute( 'datetime' ) );
	}

	public function test_station_covers_are_page_scoped_decorative_and_have_request_priority(): void {
		$this->seed();
		$one = $this->xpath( $this->serve( $this->archive_url( 1 ) ) );
		$this->assertSame( 1, $one->query( '//section[.//h2[normalize-space()="alder street radio"]]//img' )->length );
		$this->assertSame( 1, $one->query( '//section[.//h2[normalize-space()="Copper Kettle Hour"]]//img' )->length );
		$this->assertSame( 1, $one->query( '//section[.//h2[normalize-space()="Brass Tacks FM"]]//div[contains(@class,"pkiw-lineup__cover--none")]' )->length );
		$images = $one->query( '//div[contains(@class,"pkiw-lineup__cover")]//img' );
		$this->assertSame( 'eager', $images->item( 0 )->getAttribute( 'loading' ) );
		$this->assertSame( 'high', $images->item( 0 )->getAttribute( 'fetchpriority' ) );
		foreach ( $images as $at => $image ) {
			$this->assertSame( '', $image->getAttribute( 'alt' ) );
			if ( 0 < $at ) {
				$this->assertSame( 'lazy', $image->getAttribute( 'loading' ) );
				$this->assertFalse( $image->hasAttribute( 'fetchpriority' ) );
			}
		}

		$two = $this->xpath( $this->serve( $this->archive_url( 2 ) ) );
		$this->assertSame( 1, $two->query( '//section[.//h2[normalize-space()="Copper Kettle Hour"]]//div[contains(@class,"pkiw-lineup__cover--none")]' )->length );
		$this->assertSame( 1, $two->query( '//section[.//h2[starts-with(normalize-space(),"Delta Quiet Hours")]]//img[@src="https://img.example.org/covers/delta.png"]' )->length );
		$this->assertSame( 1, $two->query( '//section[.//h2[normalize-space()="The Long Debug"]]//div[contains(@class,"pkiw-lineup__cover--none")]' )->length );
		$this->assertSame( 1, $two->query( '//section[.//h2[normalize-space()="Other"]]//div[contains(@class,"pkiw-lineup__cover--none")]' )->length );
	}

	public function test_locked_line_and_heading_ids_keep_the_privacy_and_uniqueness_contracts(): void {
		$this->seed();
		$xpath = $this->xpath( $this->serve( $this->archive_url( 3 ) ) );
		$line = $xpath->query( '//*[contains(concat(" ",@class," ")," post-' . $this->ids['H01'] . ' ")]//*[contains(concat(" ",@class," ")," pkiw-episode ")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $line );
		$markup = $line->ownerDocument->saveHTML( $line );
		$this->assertStringContainsString( 'Protected: Listened to Hidden One', $markup );
		$this->assertStringNotContainsString( 'u-listen-of', $markup );
		$this->assertStringNotContainsString( 'p-author', $markup );

		$ids = [];
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ) );
	}

	public function test_descending_section_order_reverses_named_stations(): void {
		$this->seed();
		$html = $this->serve( $this->archive_url( 1 ), '"linesPerPage":12', '"linesPerPage":12,"sectionOrder":"desc"' );
		$headings = array_keys( $this->station_map( $this->xpath( $html ) ) );

		$this->assertSame( 'The Long Debug', $headings[0] );
	}

	public function test_editor_preview_matches_the_front_end_station_and_filters_out_music(): void {
		$this->seed();
		$front = $this->xpath( $this->serve( $this->archive_url( 1 ) ) );
		$front_station = $front->query( '//section[.//h2[normalize-space()="alder street radio"]]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $front_station );

		$preview = $this->xpath( $this->editor_render( $this->ids['A01'] ) );
		$preview_station = $preview->query( '//section[.//h2[normalize-space()="alder street radio"]]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $preview_station );
		$this->assertSame( $this->line_markup( $front, $front_station ), $this->line_markup( $preview, $preview_station ) );
		$this->assertStringNotContainsString( 'Music Control', $preview->document->textContent );
	}

	public function test_block_registration_query_variation_and_template_shape_are_complete(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Listen_Archive::EPISODE_BLOCK );
		$this->assertNotNull( $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertTrue( Grouped_Archive::is_entry_block( Listen_Archive::EPISODE_BLOCK ) );
		$this->assertSame( 12, $block->attributes['linesPerPage']['default'] );
		$this->assertContains( 'pkiw-podcast-episode-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-podcast-lineup', $block->style_handles );
		$this->assertFileDoesNotExist( PKIW_PATH . 'src/blocks/podcast-episode/block.json' );

		$query = WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' );
		$this->assertNotNull( $query );
		$variations = apply_filters( 'get_block_type_variations', [], $query );
		$matches = wp_list_filter( $variations, [ 'name' => 'pkiw-kind-lineup' ] );
		$this->assertCount( 1, $matches );
		$variation = array_values( $matches )[0];
		$this->assertSame( 'pkiw-kind-lineup', $variation['attributes']['namespace'] );
		$this->assertSame( Listen_Archive::EPISODE_BLOCK, $variation['innerBlocks'][0][2][0][0] );

		$this->assertFileExists( PKIW_PATH . 'templates/taxonomy-kind-listen-podcast.html' );
		$content = (string) file_get_contents( PKIW_PATH . 'templates/taxonomy-kind-listen-podcast.html' );
		$blocks = $this->flatten( parse_blocks( $content ) );
		$this->assertCount( 1, wp_list_filter( $blocks, [ 'blockName' => 'core/query' ] ) );
		$this->assertCount( 1, wp_list_filter( $blocks, [ 'blockName' => Listen_Archive::TYPES_BLOCK ] ) );
		$this->assertCount( 1, wp_list_filter( $blocks, [ 'blockName' => Listen_Archive::EPISODE_BLOCK ] ) );
		$this->assertCount( 1, wp_list_filter( $blocks, [ 'blockName' => 'core/query-pagination' ] ) );
		$query_block = array_values( wp_list_filter( $blocks, [ 'blockName' => 'core/query' ] ) )[0];
		$this->assertTrue( $query_block['attrs']['query']['inherit'] );
	}

	private function editor_render( int $post_id ): string {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/' . Listen_Archive::EPISODE_BLOCK );
		$request->set_param( 'context', 'edit' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'pkiw_kind', 'listen' );
		$request->set_param( 'pkiw_view', 'podcast' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return (string) $response->get_data()['rendered'];
	}

	/**
	 * @return array<string, int>
	 */
	private function station_map( DOMXPath $xpath ): array {
		$map = [];
		foreach ( $xpath->query( '//section[contains(concat(" ",@class," ")," pkiw-lineup__station ")]' ) as $section ) {
			$heading = $xpath->query( './h2|./h3|./h4|./h5|./h6', $section )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $heading );
			$map[ trim( $heading->textContent ) ] = $xpath->query( './ul[contains(@class,"pkiw-lineup__episodes")]/li', $section )->length;
		}

		return $map;
	}

	private function line_time( DOMXPath $xpath, int $post_id ): DOMElement {
		$time = $xpath->query( '//*[contains(concat(" ",@class," ")," post-' . $post_id . ' ")]//time[contains(@class,"pkiw-episode__date")]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $time );

		return $time;
	}

	private function line_markup( DOMXPath $xpath, DOMElement $station ): array {
		$out = [];
		foreach ( $xpath->query( './ul/li/*[contains(concat(" ",@class," ")," pkiw-episode ")]', $station ) as $item ) {
			$out[] = trim( (string) preg_replace( '/\s+/', ' ', $item->ownerDocument->saveHTML( $item ) ) );
		}

		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$out[] = $block;
			$out = array_merge( $out, $this->flatten( $block['innerBlocks'] ?? [] ) );
		}

		return $out;
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}
}

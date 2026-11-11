<?php
/** Presentation archive entry block. */

declare(strict_types=1);

use PKIW\Presentation_Archive;
use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

final class PresentationEntryBlockTest extends WP_UnitTestCase {
	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Presentation_Archive() )->register();
		Presentation_Archive::register_entry_block();
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		$this->set_permalink_structure( '' );
		switch_theme( $this->original_stylesheet );
		parent::tear_down();
	}

	private function render_archive( int $page = 1 ): string {
		$link = get_term_link( 'presentation', Taxonomy::TAXONOMY );
		$this->assertIsString( $link );
		$this->go_to( $page > 1 ? trailingslashit( $link ) . 'page/' . $page . '/' : $link );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-presentation.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		$this->assertNotNull( $template );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = $template->content;

		return get_the_block_template_html();
	}

	/**
	 * @return string[]
	 */
	private function year_headings( string $html ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$years = [];
		foreach ( $xpath->query( '//h2[starts-with(@id, "pkiw-group-")]' ) as $heading ) {
			$years[] = trim( $heading->textContent );
		}
		return $years;
	}

	public function test_block_uses_the_frozen_engine_contract(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( Presentation_Archive::ENTRY_BLOCK );
		$this->assertNotNull( $block );
		$this->assertTrue( Grouped_Archive::is_entry_block( Presentation_Archive::ENTRY_BLOCK ) );
		$this->assertSame( 3, $block->api_version );
		$this->assertSame( 12, $block->attributes['linesPerPage']['default'] );
		$this->assertSame( 2, $block->attributes['headingLevel']['default'] );
		$this->assertSame( 'desc', $block->attributes['sectionOrder']['default'] );
		$this->assertSame( [ 'postId', 'postType', 'queryId', 'templateSlug' ], $block->uses_context );
		$this->assertFalse( $block->supports['html'] );
		$this->assertFalse( $block->supports['reusable'] );
		$this->assertSame( 12, Presentation_Archive::preview_per_page( 10, 'presentation' ) );
		$this->assertSame( 10, Presentation_Archive::preview_per_page( 10, 'note' ) );
	}

	public function test_entry_is_one_link_and_has_no_embed(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'A talk', 'post_content' => '<iframe src="https://example.com"></iframe>' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'Conference' );
		update_post_meta( $post_id, '_pkiw_presentation_event_type', 'talk' );
		$html = Presentation_Archive::entry_html( get_post( $post_id ), [ 'headingLevel' => 2 ], true );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		$this->assertStringContainsString( '<h3', $html );
		$this->assertStringContainsString( 'Conference', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_protected_entry_is_title_only(): void {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Locked', 'post_password' => 'secret' ] );
		wp_set_object_terms( $post_id, 'presentation', 'kind' );
		update_post_meta( $post_id, '_pkiw_presentation_event_name', 'Sentinel locked summit' );
		$html = Presentation_Archive::entry_html( get_post( $post_id ), [ 'headingLevel' => 2 ], true );
		$this->assertStringContainsString( 'pkiw-presentation-badge--protected', $html );
		$this->assertStringNotContainsString( 'Sentinel locked summit', $html );
		$this->assertSame( 1, substr_count( $html, '<a ' ) );
		foreach ( [ 'Sentinel locked summit', 'pkiw-presentation-badge__event', 'pkiw-presentation-badge__type', 'p-author' ] as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $html );
		}
	}

	public function test_same_title_badges_are_disambiguated_and_parse_as_one_entry(): void {
		$one = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Repeated talk', 'post_date' => '2026-08-01 12:00:00' ] );
		$two = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => ' repeated TALK ', 'post_date' => '2026-07-01 12:00:00' ] );
		foreach ( [ $one, $two ] as $id ) {
			wp_set_object_terms( $id, 'presentation', 'kind' );
			update_post_meta( $id, '_pkiw_presentation_event_name', 'Conference ' . $id );
		}
		$GLOBALS['wp_query'] = new WP_Query();
		$GLOBALS['wp_query']->posts = [ get_post( $one ), get_post( $two ) ];
		$GLOBALS['wp_query']->set( 'pkiw_group_source', Presentation_Archive::SOURCE );
		$html = Presentation_Archive::entry_html( get_post( $one ), [ 'headingLevel' => 2 ], true );
		$this->assertStringContainsString( 'aria-labelledby="pkiw-presentation-' . $one . '-title pkiw-presentation-' . $one . '-event"', $html );
		$this->assertStringNotContainsString( 'pkiw-presentation-badge__date', $html );
		$this->assertStringNotContainsString( 'Slides', $html );
		if ( function_exists( '\\Mf2\\parse' ) ) {
			$entries = array_values( array_filter( \Mf2\parse( $html, get_permalink( $one ) )['items'] ?? [], static fn( array $item ): bool => in_array( 'h-entry', $item['type'] ?? [], true ) ) );
			$this->assertCount( 1, $entries );
		}
	}

	public function test_rendered_archive_parses_one_h_entry_per_badge(): void {
		$ids = [];
		foreach ( [ 'First talk' => '2026-03-01 12:00:00', 'Second talk' => '2025-05-01 12:00:00' ] as $title => $date ) {
			$id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => $title, 'post_date' => $date ] );
			wp_set_object_terms( $id, 'presentation', Taxonomy::TAXONOMY );
			update_post_meta( $id, '_pkiw_presentation_event_name', 'Conference ' . $id );
			$ids[] = $id;
		}
		$locked = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Locked talk', 'post_password' => 'secret', 'post_date' => '2025-04-01 12:00:00' ] );
		wp_set_object_terms( $locked, 'presentation', Taxonomy::TAXONOMY );
		$ids[] = $locked;

		$html = $this->render_archive();
		$this->assertSame( 3, substr_count( $html, 'class="pkiw-presentation-badge ' ) );
		$this->assertDoesNotMatchRegularExpression( '/<li\b[^>]*\bclass="[^"]*\bwp-block-post\b[^"]*\b(?:hentry|h-entry)\b/', $html, 'The Query Loop <li> is not a second entry root.' );
		$this->assertMatchesRegularExpression( '/<li\b[^>]*\bclass="[^"]*\bkind-presentation\b/', $html, 'The kind class stays on the <li>.' );

		$entries = [];
		$collect = static function ( array $items ) use ( &$collect, &$entries ): void {
			foreach ( $items as $item ) {
				if ( in_array( 'h-entry', $item['type'] ?? [], true ) ) {
					$entries[] = $item;
				}
				$collect( $item['children'] ?? [] );
				foreach ( $item['properties'] ?? [] as $values ) {
					$collect( array_filter( $values, 'is_array' ) );
				}
			}
		};
		$collect( \Mf2\parse( $html, (string) get_term_link( 'presentation', Taxonomy::TAXONOMY ) )['items'] ?? [] );

		$this->assertCount( 3, $entries, 'One h-entry per badge, none nested.' );
		$urls = array_map( static fn( array $entry ): string => (string) ( $entry['properties']['url'][0] ?? '' ), $entries );
		sort( $urls );
		$expected = array_map( static fn( int $id ): string => (string) get_permalink( $id ), $ids );
		sort( $expected );
		$this->assertSame( $expected, $urls );
		foreach ( $entries as $entry ) {
			$this->assertNotEmpty( $entry['properties']['name'] ?? [] );
			$this->assertNotEmpty( $entry['properties']['published'] ?? [] );
		}
	}

	public function test_archive_engine_orders_year_h2s_newest_first_and_repeats_a_continued_year_on_page_two(): void {
		$newest = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Newest year', 'post_date' => '2026-01-15 12:00:00' ] );
		wp_set_object_terms( $newest, 'presentation', Taxonomy::TAXONOMY );
		foreach ( range( 1, 12 ) as $day ) {
			$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'Talk ' . $day, 'post_date' => sprintf( '2025-01-%02d 12:00:00', $day ) ] );
			wp_set_object_terms( $post_id, 'presentation', Taxonomy::TAXONOMY );
		}

		$this->assertSame( [ '2026', '2025' ], $this->year_headings( $this->render_archive() ) );
		$this->assertSame( [ '2025' ], $this->year_headings( $this->render_archive( 2 ) ) );
	}
}

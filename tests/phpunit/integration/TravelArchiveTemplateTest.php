<?php
/** Travel archive block registration and plugin templates. @package PKIW */

declare(strict_types=1);

use PKIW\Travel;
use PKIW\Travel_Archive;
use PKIW\Travel_Map;

/** @group integration */
final class TravelArchiveTemplateTest extends WP_UnitTestCase {

	private function block_names( array $blocks ): array {
		$names = [];
		foreach ( $blocks as $block ) {
			if ( is_string( $block['blockName'] ?? null ) ) {
				$names[] = $block['blockName'];
			}
			$names = array_merge( $names, $this->block_names( (array) ( $block['innerBlocks'] ?? [] ) ) );
		}
		return $names;
	}

	private function find_block( array $blocks, string $name ): ?array {
		foreach ( $blocks as $block ) {
			if ( $name === ( $block['blockName'] ?? null ) ) {
				return $block;
			}
			$found = $this->find_block( (array) ( $block['innerBlocks'] ?? [] ), $name );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	private function find_block_with_class( array $blocks, string $name, string $class_name ): ?array {
		foreach ( $blocks as $block ) {
			if ( $name === ( $block['blockName'] ?? null ) && $class_name === ( $block['attrs']['className'] ?? null ) ) {
				return $block;
			}
			$found = $this->find_block_with_class( (array) ( $block['innerBlocks'] ?? [] ), $name, $class_name );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	public function set_up(): void {
		parent::set_up();
		( new Travel_Archive() )->register();
		( new Travel_Map() )->register();
		Travel_Archive::register_blocks();
		Travel_Map::register_block();
	}

	public function test_both_entry_blocks_and_map_are_registered(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( [ Travel_Archive::TRIP_ENTRY, Travel_Archive::ITINERARY_ENTRY, Travel_Map::BLOCK ] as $name ) {
			$this->assertTrue( $registry->is_registered( $name ), $name );
		}
		$this->assertSame( 12, $registry->get_registered( Travel_Archive::TRIP_ENTRY )->attributes['linesPerPage']['default'] );
		$this->assertSame( 2, $registry->get_registered( Travel_Archive::ITINERARY_ENTRY )->attributes['headingLevel']['default'] );
	}

	public function test_both_plugin_templates_are_offered_and_parse(): void {
		$slugs = [ 'taxonomy-kind-trip', 'taxonomy-kind-itinerary' ];
		$ids = wp_list_pluck( get_block_templates( [ 'slug__in' => $slugs ] ), 'id' );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-trip', $ids );
		$this->assertContains( 'post-kinds-for-indieweb//taxonomy-kind-itinerary', $ids );

		foreach ( $slugs as $slug ) {
			$content = (string) file_get_contents( PKIW_PATH . 'templates/' . $slug . '.html' );
			$blocks  = parse_blocks( $content );
			$entry   = str_contains( $slug, 'itinerary' ) ? Travel_Archive::ITINERARY_ENTRY : Travel_Archive::TRIP_ENTRY;
			$names   = $this->block_names( $blocks );
			$query   = $this->find_block( $blocks, 'core/query' );
			$map_col = $this->find_block_with_class( $blocks, 'core/group', 'pkiw-travel-archive__map-column' );

			$this->assertSame( true, $query['attrs']['query']['inherit'] ?? null );
			$this->assertContains( Travel_Map::BLOCK, $names );
			$this->assertContains( 'core/post-template', $names );
			$this->assertContains( $entry, $names );
			$this->assertContains( 'core/query-pagination', $names );
			$this->assertContains( 'core/query-no-results', $names );
			$this->assertSame( 12, $this->find_block( $blocks, $entry )['attrs']['linesPerPage'] ?? null );
			$this->assertNotNull( $map_col );
			$this->assertSame( [ Travel_Map::BLOCK ], $this->block_names( (array) $map_col['innerBlocks'] ) );
		}
	}

	public function test_preview_page_size_is_twelve_for_both_kinds_only(): void {
		$this->assertSame( 12, Travel_Archive::preview_per_page( 24, Travel::TRIP ) );
		$this->assertSame( 12, Travel_Archive::preview_per_page( 24, Travel::ITINERARY ) );
		$this->assertSame( 24, Travel_Archive::preview_per_page( 24, 'note' ) );
	}

	public function test_layout_names_map_and_entry_areas_and_collapses_a_hidden_map(): void {
		$css = (string) file_get_contents( PKIW_PATH . 'styles/travel-archive.css' );
		$this->assertStringContainsString( 'grid-area: map;', $css );
		$this->assertStringContainsString( 'grid-area: entries;', $css );
		$this->assertStringContainsString( 'grid-template-areas: "map entries";', $css );
		$this->assertStringContainsString( ':not(:has(.pkiw-travel-map:not([hidden])))', $css );
		$this->assertStringContainsString( 'grid-template-columns: minmax(0, 1fr);', $css );
	}
}

<?php
/**
 * Inserter examples and block context declarations.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Event_Calendar;
use PKIW\Presentation;
use PKIW\Presentation_Archive;
use PKIW\Travel;
use PKIW\Travel_Archive;
use PKIW\Travel_Map;

/** @group integration */
final class BlockExamplesTest extends WP_UnitTestCase {

	/**
	 * PHP blocks that intentionally have no inserter example.
	 *
	 * @var array<string, string>
	 */
	private const NO_EXAMPLE_REASONS = [
		// The block is excluded from the inserter.
		Presentation::RECORD_BLOCK => 'inserter false',
		// Its preview would print the archive's real route pins.
		Travel_Map::BLOCK          => 'preview prints real archive pins',
		// Its preview would print My Calendar's live output.
		Event_Calendar::BLOCK      => 'preview prints My Calendar output',
		// It has no authored attributes and renders only from the post's metadata.
		Travel::STOP_BLOCK         => 'no attributes; renders post metadata',
		// It has no authored attributes and renders only from the post's metadata.
		Travel::LEG_BLOCK          => 'no attributes; renders post metadata',
	];

	/** @return array<string, mixed> */
	private function metadata( string $slug ): array {
		$content = file_get_contents( PKIW_PATH . 'src/blocks/' . $slug . '/block.json' );
		$this->assertIsString( $content, $slug );
		$metadata = json_decode( $content, true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $metadata, $slug );

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $example Block example.
	 */
	private function assert_map_stays_off( array $example, string $block ): void {
		$attributes = is_array( $example['attributes'] ?? null ) ? $example['attributes'] : [];
		if ( array_key_exists( 'showMap', $attributes ) ) {
			$this->assertFalse( $attributes['showMap'], $block );
		}
		foreach ( (array) ( $example['innerBlocks'] ?? [] ) as $inner_block ) {
			if ( is_array( $inner_block ) ) {
				$this->assert_map_stays_off( $inner_block, $block );
			}
		}
	}

	public function test_all_thirty_source_blocks_have_examples_with_declared_attributes(): void {
		$files = glob( PKIW_PATH . 'src/blocks/*/block.json' );
		$this->assertIsArray( $files );
		$this->assertCount( 30, $files );

		foreach ( $files as $file ) {
			$slug     = basename( dirname( $file ) );
			$metadata = $this->metadata( $slug );
			$this->assertArrayHasKey( 'example', $metadata, $slug );
			$block = WP_Block_Type_Registry::get_instance()->get_registered( (string) $metadata['name'] );
			$this->assertInstanceOf( WP_Block_Type::class, $block, $slug );
			$this->assertSame( $metadata['example'], $block->example, $slug . ' registered example' );
			foreach ( array_keys( (array) ( $metadata['example']['attributes'] ?? [] ) ) as $attribute ) {
				$this->assertArrayHasKey( $attribute, (array) ( $metadata['attributes'] ?? [] ), $slug . ':' . $attribute );
			}
			$this->assert_map_stays_off( $metadata['example'], $slug );
		}
	}

	public function test_map_capable_examples_explicitly_keep_maps_off(): void {
		foreach ( [ 'checkin-dashboard', 'checkins-feed', 'venue-detail' ] as $slug ) {
			$metadata = $this->metadata( $slug );
			$this->assertArrayHasKey( 'showMap', $metadata['example']['attributes'], $slug );
			$this->assertFalse( $metadata['example']['attributes']['showMap'], $slug );
		}
	}

	public function test_three_w7_entry_blocks_preview_three_lines(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		foreach ( [ Presentation_Archive::ENTRY_BLOCK, Travel_Archive::TRIP_ENTRY, Travel_Archive::ITINERARY_ENTRY ] as $name ) {
			$block = $registry->get_registered( $name );
			$this->assertInstanceOf( WP_Block_Type::class, $block, $name );
			$this->assertSame( [ 'attributes' => [ 'linesPerPage' => 3 ] ], $block->example, $name );
		}
	}

	public function test_php_blocks_without_safe_previews_have_documented_reasons(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertCount( 5, self::NO_EXAMPLE_REASONS );

		foreach ( self::NO_EXAMPLE_REASONS as $name => $reason ) {
			$block = $registry->get_registered( $name );
			$this->assertInstanceOf( WP_Block_Type::class, $block, $name );
			$this->assertNotSame( '', $reason, $name );
			$this->assertNull( $block->example, $reason );
		}
	}

	public function test_context_readers_declare_post_id(): void {
		foreach ( [ 'acquisition-card', 'wish-card' ] as $slug ) {
			$metadata = $this->metadata( $slug );
			$this->assertSame( [ 'postId' ], $metadata['usesContext'] ?? null, $slug . ' source metadata' );
			$block = WP_Block_Type_Registry::get_instance()->get_registered( (string) $metadata['name'] );
			$this->assertInstanceOf( WP_Block_Type::class, $block, $slug );
			$this->assertSame( [ 'postId' ], $block->uses_context, $slug . ' registered context' );
		}
	}
}

<?php
/**
 * Tag label projection coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Tag_Archive;

/** @group integration */
final class TagLabelSyncTest extends WP_UnitTestCase {

	private function card( array $labels ): string {
		return '<!-- wp:post-kinds-indieweb/tag-card ' . serialize_block_attributes( [ 'title' => 'Target', 'labels' => $labels ] ) . ' /-->';
	}

	public function test_save_sanitizes_deduplicates_limits_and_reorders_rows(): void {
		$id     = self::factory()->post->create();
		$labels = array_merge( [ ' One ', '', 'one', 'Two' ], array_map( static fn( int $n ): string => 'Label ' . $n, range( 3, 30 ) ) );
		Tag_Archive::sync_labels( $id, parse_blocks( $this->card( $labels ) ), true );
		$stored = get_post_meta( $id, Tag_Archive::LABEL_KEY, false );
		$this->assertCount( 20, $stored );
		$this->assertSame( [ 'One', 'Two' ], array_slice( $stored, 0, 2 ) );
		Tag_Archive::sync_labels( $id, parse_blocks( $this->card( [ 'Two', 'One' ] ) ), true );
		$this->assertSame( [ 'Two', 'One' ], get_post_meta( $id, Tag_Archive::LABEL_KEY, false ) );
		Tag_Archive::sync_labels( $id, [], true );
		$this->assertSame( [], get_post_meta( $id, Tag_Archive::LABEL_KEY, false ) );
	}

	public function test_backfill_adds_only_when_every_label_row_is_absent(): void {
		$id = self::factory()->post->create( [ 'post_content' => wp_slash( $this->card( [ 'One', 'Two' ] ) ) ] );
		delete_post_meta( $id, Tag_Archive::LABEL_KEY );
		Tag_Archive::sync_labels( $id, parse_blocks( get_post_field( 'post_content', $id ) ), false );
		$this->assertSame( [ 'One', 'Two' ], get_post_meta( $id, Tag_Archive::LABEL_KEY, false ) );
		$existing = self::factory()->post->create( [ 'post_content' => wp_slash( $this->card( [ 'One', 'Two' ] ) ) ] );
		delete_post_meta( $existing, Tag_Archive::LABEL_KEY );
		add_post_meta( $existing, Tag_Archive::LABEL_KEY, 'Existing' );
		Tag_Archive::sync_labels( $existing, parse_blocks( get_post_field( 'post_content', $existing ) ), false );
		$this->assertSame( [ 'Existing' ], get_post_meta( $existing, Tag_Archive::LABEL_KEY, false ) );
	}

	public function test_raw_reads_card_labels_before_stale_projection_rows(): void {
		$id = self::factory()->post->create( [ 'post_content' => wp_slash( $this->card( [ 'Card first', 'Card second' ] ) ) ] );
		add_post_meta( $id, Tag_Archive::LABEL_KEY, 'Stale row' );
		$this->assertSame( [ 'Card first', 'Card second' ], Tag_Archive::raw( $id )['labels'] );

		$cardless = self::factory()->post->create();
		add_post_meta( $cardless, Tag_Archive::LABEL_KEY, 'Projected row' );
		$this->assertSame( [ 'Projected row' ], Tag_Archive::raw( $cardless )['labels'] );
	}
}

<?php
/**
 * Tag label repair operation coverage.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Operations;
use PKIW\Tag_Archive;

/** @group integration */
final class TagBackfillTest extends WP_UnitTestCase {

	private function candidate( string $status = 'publish' ): int {
		$content = '<!-- wp:post-kinds-indieweb/tag-card {"targetType":"person","url":"https://person.example/","title":"Private Person","labels":["Friend"]} /-->';
		$id = (int) self::factory()->post->create( [ 'post_status' => $status, 'post_content' => wp_slash( $content ) ] );
		delete_post_meta( $id, Tag_Archive::LABEL_KEY );
		return $id;
	}

	public function test_dry_run_lists_only_missing_rows_without_person_data(): void {
		$missing = $this->candidate( 'private' );
		$exists  = $this->candidate();
		add_post_meta( $exists, Tag_Archive::LABEL_KEY, 'Existing' );
		$result = Operations::backfill_tag_labels( true, null );
		$this->assertSame( [ $missing ], wp_list_pluck( $result['candidates'], 'id' ) );
		$this->assertStringNotContainsString( 'Private Person', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'person.example', wp_json_encode( $result ) );
	}

	public function test_write_requires_ids_and_reports_exists_and_not_candidate(): void {
		$this->assertWPError( Operations::backfill_tag_labels( false, null ) );
		$candidate = $this->candidate();
		$ordinary  = self::factory()->post->create();
		$result    = Operations::backfill_tag_labels( false, [ $candidate, $ordinary ] );
		$this->assertSame( [ $candidate ], $result['written'] );
		$this->assertSame( 'not_candidate', $result['skipped'][0]['reason'] );
		$this->assertSame( [ 'Friend' ], get_post_meta( $candidate, Tag_Archive::LABEL_KEY, false ) );
	}
}

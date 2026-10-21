<?php
/**
 * Shared operational services.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs maintenance and reporting operations for trusted callers.
 */
final class Operations {

	/**
	 * Recompute cached post surfaces or report the pending changes.
	 *
	 * @param bool $dry_run Whether to report without writing.
	 * @return array{dry_run:bool,processed:int,changed:int}
	 */
	public static function backfill_surfaces( bool $dry_run ): array {
		$report = Post_Surface::backfill_report( $dry_run );

		return [
			'dry_run'   => $dry_run,
			'processed' => $report['processed'],
			'changed'   => $report['changed'],
		];
	}

	/**
	 * Re-sync card meta or count the selected posts without writing.
	 *
	 * @param bool $dry_run Whether to report without writing.
	 * @param int  $batch   Posts per batch.
	 * @return array{dry_run:bool,processed:int,batch:int}
	 */
	public static function backfill_card_meta( bool $dry_run, int $batch = Card_Meta_Sync::BACKFILL_BATCH ): array {
		$batch     = max( 1, $batch );
		$cursor    = 0;
		$processed = 0;

		do {
			if ( $dry_run ) {
				$ids        = Card_Meta_Sync::backfill_ids( $cursor, $batch );
				$count      = count( $ids );
				$cursor     = $count > 0 ? $ids[ $count - 1 ] : $cursor;
				$done       = $count < $batch;
				$processed += $count;
				continue;
			}

			$result     = Card_Meta_Sync::backfill_batch( $cursor, $batch );
			$cursor     = $result['last_id'];
			$processed += $result['processed'];
			$done       = $result['done'];
		} while ( ! $done );

		if ( ! $dry_run ) {
			update_option( Card_Meta_Sync::BACKFILL_OPTION, Card_Meta_Sync::BACKFILL_VERSION, false );
			delete_option( Card_Meta_Sync::BACKFILL_CURSOR );
			wp_clear_scheduled_hook( Card_Meta_Sync::BACKFILL_HOOK );
		}

		return [
			'dry_run'   => $dry_run,
			'processed' => $processed,
			'batch'     => $batch,
		];
	}

	/**
	 * Report podcast candidates or update an explicit list of them.
	 *
	 * @param bool       $dry_run Whether to report without writing.
	 * @param array|null $ids     Explicit post IDs for a write run.
	 * @return array{dry_run:bool,candidates:array<int,array{id:int,status:string,password:bool,signals:string[]}>,written:int[],skipped:array<int,array{id:int,reason:string}>}|\WP_Error
	 */
	public static function backfill_listen_media_type( bool $dry_run, ?array $ids ): array|\WP_Error {
		if ( $dry_run ) {
			return [
				'dry_run'    => true,
				'candidates' => Listen_Media_Type::candidates(),
				'written'    => [],
				'skipped'    => [],
			];
		}

		if ( null === $ids ) {
			return new \WP_Error(
				'pkiw_ids_required',
				__( 'Refusing to write without an ids list. Run with dry_run first.', 'post-kinds-for-indieweb-in-block-themes' )
			);
		}

		$applied = Listen_Media_Type::apply( array_map( 'intval', $ids ) );
		$skipped = [];
		foreach ( $applied['skipped'] as $id => $reason ) {
			$skipped[] = [
				'id'     => (int) $id,
				'reason' => (string) $reason,
			];
		}

		return [
			'dry_run'    => false,
			'candidates' => [],
			'written'    => array_map( 'intval', $applied['written'] ),
			'skipped'    => $skipped,
		];
	}

	/**
	 * Set featured artwork or report what would change.
	 *
	 * @param bool $dry_run Whether to report without writing.
	 * @return array{dry_run:bool,scanned:int,updated:int,skipped:int,failed:int}
	 */
	public static function backfill_featured_artwork( bool $dry_run ): array {
		$stats = ( new Featured_Artwork() )->backfill( $dry_run );

		return [
			'dry_run' => $dry_run,
			'scanned' => $stats['scanned'],
			'updated' => $stats['updated'],
			'skipped' => $stats['skipped'],
			'failed'  => $stats['failed'],
		];
	}

	/**
	 * Count published check-ins and venue terms.
	 *
	 * @return array{checkin_posts:int,venue_terms:int}
	 */
	public static function checkin_stats(): array {
		$venue_terms = wp_count_terms(
			[
				'taxonomy'   => Venue_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			]
		);

		return [
			'checkin_posts' => get_checkin_count(),
			'venue_terms'   => is_wp_error( $venue_terms ) ? 0 : (int) $venue_terms,
		];
	}

	/**
	 * List venue terms and their stored location fields.
	 *
	 * @return array<int,array{id:int,name:string,slug:string,city:string,country:string,latitude:string,longitude:string,count:int}>|\WP_Error
	 */
	public static function venues(): array|\WP_Error {
		$terms = get_terms(
			[
				'taxonomy'   => Venue_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$venues = [];
		foreach ( $terms as $term ) {
			$venues[] = [
				'id'        => (int) $term->term_id,
				'name'      => (string) $term->name,
				'slug'      => (string) $term->slug,
				'city'      => (string) get_term_meta( $term->term_id, 'city', true ),
				'country'   => (string) get_term_meta( $term->term_id, 'country', true ),
				'latitude'  => (string) get_term_meta( $term->term_id, 'latitude', true ),
				'longitude' => (string) get_term_meta( $term->term_id, 'longitude', true ),
				'count'     => (int) $term->count,
			];
		}

		return $venues;
	}
}

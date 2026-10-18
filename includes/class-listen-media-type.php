<?php
/**
 * Listen media type storage and podcast backfill helpers.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the listen media type vocabulary and issue 236 backfill.
 *
 * @since 1.9.0
 */
final class Listen_Media_Type {

	/**
	 * Registered listen media type meta key.
	 */
	public const META = '_pkiw_listen_media_type';

	/**
	 * Podcast listen type.
	 */
	public const PODCAST = 'podcast';

	/**
	 * Music listen type.
	 */
	public const MUSIC = 'music';

	/**
	 * Sanitize a listen media type.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value, or an empty string when invalid.
	 */
	public static function sanitize( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, [ self::MUSIC, self::PODCAST ], true ) ? $value : '';
	}

	/**
	 * Read the stored meta row without applying its registered default.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string Stored listen media type, or an empty string.
	 */
	public static function stored( int $post_id ): string {
		return self::sanitize( get_metadata_raw( 'post', $post_id, self::META, true ) );
	}

	/**
	 * Find listen posts whose legacy metadata identifies a podcast.
	 *
	 * The result deliberately excludes post titles and content.
	 *
	 * @since 1.9.0
	 *
	 * @return array<int, array{id:int, status:string, password:bool, signals:string[]}>
	 */
	public static function candidates(): array {
		global $wpdb;

		$statuses     = [ 'publish', 'private', 'draft', 'pending', 'future' ];
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$sql          = "SELECT DISTINCT p.ID, p.post_status, p.post_password
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tt.taxonomy = %s
				AND t.slug = %s
				AND p.post_type = %s
				AND p.post_status IN ( {$placeholders} )
			ORDER BY p.ID ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One bounded backfill query; every value uses a placeholder.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The status list contains placeholders only.
				...array_merge( [ 'kind', 'listen', 'post' ], $statuses )
			)
		);

		$candidates = [];
		foreach ( $rows as $row ) {
			$post_id = (int) $row->ID;
			if ( '' !== self::stored( $post_id ) ) {
				continue;
			}

			$artist = trim( (string) get_post_meta( $post_id, '_pkiw_listen_artist', true ) );
			$album  = trim( (string) get_post_meta( $post_id, '_pkiw_listen_album', true ) );
			$source = trim( (string) get_post_meta( $post_id, '_pkiw_source', true ) );
			if ( '' === $artist || $artist !== $album || '' === $source ) {
				continue;
			}

			$candidates[] = [
				'id'       => $post_id,
				'status'   => (string) $row->post_status,
				'password' => '' !== (string) $row->post_password,
				'signals'  => [ 'artist-equals-album', 'source' ],
			];
		}

		return $candidates;
	}

	/**
	 * Mark selected current candidates as podcast listens.
	 *
	 * @since 1.9.0
	 *
	 * @param array<int, mixed> $ids Post IDs to update.
	 * @return array{written:int[], skipped:array<int, string>}
	 */
	public static function apply( array $ids ): array {
		$candidates = [];
		foreach ( self::candidates() as $candidate ) {
			$candidates[ $candidate['id'] ] = true;
		}

		$result = [
			'written' => [],
			'skipped' => [],
		];
		foreach ( $ids as $raw_id ) {
			$id = (int) $raw_id;
			if ( ! isset( $candidates[ $id ] ) || '' !== self::stored( $id ) ) {
				$result['skipped'][ $id ] = 'not a candidate';
				continue;
			}

			update_post_meta( $id, self::META, self::PODCAST );
			$result['written'][] = $id;
			unset( $candidates[ $id ] );
		}

		return $result;
	}

	/**
	 * Format one privacy-safe candidate report line.
	 *
	 * @since 1.9.0
	 *
	 * @param array{id:int, status:string, password:bool, signals:string[]} $row Candidate row.
	 * @return string Candidate report line.
	 */
	public static function report_line( array $row ): string {
		return sprintf(
			'ID %d status %s password %s signals %s',
			$row['id'],
			$row['status'],
			$row['password'] ? 'yes' : 'no',
			implode( ',', $row['signals'] )
		);
	}

	/**
	 * Parse a comma-separated list of positive post IDs.
	 *
	 * @since 1.9.0
	 *
	 * @param string $csv Comma-separated IDs.
	 * @return int[] Positive IDs, de-duplicated in input order.
	 */
	public static function parse_ids( string $csv ): array {
		$ids = [];
		foreach ( explode( ',', $csv ) as $raw_id ) {
			$raw_id = trim( $raw_id );
			$id     = ctype_digit( $raw_id ) ? (int) $raw_id : 0;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}

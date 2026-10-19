<?php
/**
 * Classify approved top-level comments that answer a question.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single answer-counting contract shared by question surfaces.
 *
 * @since 1.9.0
 */
final class Answer_Classifier {

	/**
	 * Protocols whose ordinary comment-shaped records count as answers.
	 *
	 * @var string[]
	 */
	public const PROTOCOLS = [ 'webmention', 'social-linkback' ];

	/**
	 * Request-local results, keyed by post and core's comment change token.
	 *
	 * @var array<string, \WP_Comment[]>
	 */
	private static array $cache = [];

	/**
	 * Approved top-level answers for a post, oldest first.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Comment[]
	 */
	public static function answers( int $post_id ): array {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}

		$key = $post_id . ':' . wp_cache_get_last_changed( 'comment' );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$ids         = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The classifier has a request cache keyed by core's change token.
			$wpdb->prepare(
				"SELECT comment_ID
				FROM {$wpdb->comments}
				WHERE comment_post_ID = %d
					AND comment_approved = '1'
					AND comment_parent = 0
					AND comment_type IN ('', 'comment')
				ORDER BY comment_date_gmt ASC, comment_ID ASC",
				$post_id
			)
		);
		$comment_ids = array_map( 'intval', $ids );
		if ( [] !== $comment_ids ) {
			update_meta_cache( 'comment', $comment_ids );
		}

		$answers = [];
		foreach ( $comment_ids as $comment_id ) {
			$comment = get_comment( $comment_id );
			if ( $comment instanceof \WP_Comment && self::is_answer( $comment, $post ) ) {
				$answers[] = $comment;
			}
		}

		self::$cache[ $key ] = $answers;

		return $answers;
	}

	/**
	 * Answer comment IDs for a post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function answer_ids( int $post_id ): array {
		return array_map(
			static fn( \WP_Comment $comment ): int => (int) $comment->comment_ID,
			self::answers( $post_id )
		);
	}

	/**
	 * Number of answers for a post.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function count( int $post_id ): int {
		return count( self::answers( $post_id ) );
	}

	/**
	 * Whether one stored comment is an answer to the supplied post.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Comment $comment Comment.
	 * @param \WP_Post    $post    Parent post.
	 * @return bool
	 */
	public static function is_answer( \WP_Comment $comment, \WP_Post $post ): bool {
		if ( (int) $comment->user_id > 0 && (int) $comment->user_id === (int) $post->post_author ) {
			return false;
		}

		$source_host = self::host( (string) get_comment_meta( (int) $comment->comment_ID, 'webmention_source_url', true ) );
		$home_host   = self::host( home_url( '/' ) );
		if ( '' !== $source_host && '' !== $home_host && $source_host === $home_host ) {
			return false;
		}

		$protocol = trim( (string) get_comment_meta( (int) $comment->comment_ID, 'protocol', true ) );
		if ( '' !== $protocol ) {
			/**
			 * Filters protocols whose ordinary comment-shaped records count as answers.
			 *
			 * @since 1.9.0
			 *
			 * @param string[] $protocols Protocol names.
			 */
			$protocols = (array) apply_filters( 'pkiw_answer_protocols', self::PROTOCOLS );

			return in_array( $protocol, array_map( 'strval', $protocols ), true );
		}

		$kind = trim( (string) get_comment_meta( (int) $comment->comment_ID, 'indieblocks_webmention_kind', true ) );
		if ( '' !== $kind ) {
			return '' === (string) $comment->comment_type && 'reply' === $kind;
		}

		return true;
	}

	/**
	 * Clear request-local results after comment metadata changes.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = [];
	}

	/**
	 * Normalize a URL host for self-Webmention comparison.
	 *
	 * @param string $url URL.
	 * @return string Lowercase host without one leading www label.
	 */
	private static function host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}

		$host = strtolower( $host );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}

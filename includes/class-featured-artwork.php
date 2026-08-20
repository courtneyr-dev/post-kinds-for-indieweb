<?php
/**
 * Featured image from kind artwork.
 *
 * @package PKIW
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets a kind post's featured image from its representative artwork
 * (album cover, movie poster, book cover, game art, checkin photo)
 * when the post has none. Remote artwork is sideloaded into the media
 * library once per URL; artwork that already resolves to a local
 * attachment is reused without any network request.
 *
 * Ownership rules keep editorial choice in charge:
 *
 * - A featured image this class did not set is never replaced.
 * - Removing the auto-set image is respected — the same artwork URL is
 *   never re-applied (the attempt marker survives the removal).
 * - A failed fetch is not retried on every save; a new artwork URL
 *   resets the attempt.
 * - Disable entirely with `add_filter( 'pkiw_set_featured_from_artwork',
 *   '__return_false' )`.
 *
 * @since 1.3.0
 */
class Featured_Artwork {

	/**
	 * Meta key recording the last artwork URL attempted (set on both
	 * success and failure — it's the "don't repeat yourself" marker).
	 */
	public const SOURCE_META = '_pkiw_featured_artwork_source';

	/**
	 * Meta key recording the attachment ID this class set as the
	 * featured image (the ownership marker: only this attachment is
	 * ever replaced when artwork changes).
	 */
	public const ATTACHMENT_META = '_pkiw_featured_artwork_attachment';

	/**
	 * Constructor.
	 *
	 * Hooked after Card_Meta_Sync (25) and Book_Completion (30) so the
	 * normalized meta this reads is current for the same save.
	 */
	public function __construct() {
		add_action( 'save_post', [ $this, 'maybe_set_featured' ], 35, 2 );
	}

	/**
	 * Set the featured image from kind artwork when appropriate.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function maybe_set_featured( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}

		// Sideloading mints media library attachments, so a user-context
		// save requires the upload_files capability (a contributor can
		// edit their own drafts but must not create attachments this
		// way). System contexts — cron, WP-CLI — carry no user and are
		// deliberate operator actions. Checked before any state is
		// written so a blocked save leaves no attempt marker and a
		// capable user's later save still applies the artwork.
		if ( get_current_user_id() > 0 && ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$url = Kind_Artwork::get_representative_image_url( $post_id );
		if ( null === $url ) {
			return; // No real artwork — never invent or remove anything.
		}

		/**
		 * Filters whether kind artwork may become the featured image.
		 *
		 * @since 1.3.0
		 *
		 * @param bool   $enabled Default true.
		 * @param int    $post_id Post ID.
		 * @param string $url     Resolved artwork URL.
		 */
		if ( ! apply_filters( 'pkiw_set_featured_from_artwork', true, $post_id, $url ) ) {
			return;
		}

		$attempted      = (string) get_post_meta( $post_id, self::SOURCE_META, true );
		$our_attachment = (int) get_post_meta( $post_id, self::ATTACHMENT_META, true );
		$thumbnail_id   = (int) get_post_thumbnail_id( $post_id );

		if ( $thumbnail_id > 0 ) {
			// Replace only an image we set ourselves, and only because
			// the artwork actually changed.
			if ( $thumbnail_id !== $our_attachment || $url === $attempted ) {
				return;
			}
		} elseif ( $url === $attempted ) {
			// Same URL as the last attempt: either the fetch failed (don't
			// hammer it on every save) or the user removed the image we
			// set (respect the removal).
			return;
		}

		self::apply( $post_id, $url );
	}

	/**
	 * Apply an artwork URL as the featured image.
	 *
	 * Records the attempt first so a failing URL is only fetched once,
	 * reuses an existing local attachment when the URL is our own, and
	 * sideloads otherwise.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Validated artwork URL.
	 * @return int|\WP_Error Attachment ID, or the sideload error.
	 */
	public static function apply( int $post_id, string $url ) {
		update_post_meta( $post_id, self::SOURCE_META, $url );

		$attachment_id = attachment_url_to_postid( $url );
		if ( 0 === $attachment_id ) {
			$attachment_id = self::sideload( $post_id, $url );
		}

		if ( is_wp_error( $attachment_id ) ) {
			delete_post_meta( $post_id, self::ATTACHMENT_META );
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, self::ATTACHMENT_META, $attachment_id );

		// Give the artwork a usable alt if it has none — the post title
		// is the entity name for every kind card ("American Obituary").
		if ( '' === (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', get_the_title( $post_id ) );
		}

		return $attachment_id;
	}

	/**
	 * Sideload a remote artwork URL into the media library.
	 *
	 * @param int    $post_id Post the attachment is for.
	 * @param string $url     Remote image URL.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	private static function sideload( int $post_id, string $url ) {
		// save_post can fire on the front end (Micropub, REST); the
		// sideload stack lives in admin includes.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		return media_sideload_image( $url, $post_id, get_the_title( $post_id ), 'id' );
	}

	/**
	 * Backfill featured images for existing kind posts.
	 *
	 * Skips posts that already have a featured image and posts whose
	 * artwork URL was already attempted (so removals stay removed and
	 * dead URLs aren't re-fetched).
	 *
	 * @param bool $dry_run When true, count without writing.
	 * @return array{scanned: int, updated: int, skipped: int, failed: int} Stats.
	 */
	public function backfill( bool $dry_run = false ): array {
		$stats = [
			'scanned' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
		];

		$post_ids = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One-shot CLI backfill, not a front-end query.
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => array_keys( Kind_Artwork::KIND_IMAGE_META ),
					],
				],
			]
		);

		foreach ( $post_ids as $post_id ) {
			++$stats['scanned'];

			$url = Kind_Artwork::get_representative_image_url( $post_id );
			if ( null === $url
				|| get_post_thumbnail_id( $post_id ) > 0
				|| (string) get_post_meta( $post_id, self::SOURCE_META, true ) === $url
				|| ! apply_filters( 'pkiw_set_featured_from_artwork', true, $post_id, $url ) ) {
				++$stats['skipped'];
				continue;
			}

			if ( $dry_run ) {
				++$stats['updated'];
				continue;
			}

			$result = self::apply( $post_id, $url );
			if ( is_wp_error( $result ) ) {
				++$stats['failed'];
			} else {
				++$stats['updated'];
			}
		}

		return $stats;
	}
}

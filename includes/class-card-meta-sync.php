<?php
/**
 * Card attrs → post meta sync.
 *
 * @package PKIW
 * @since   1.2.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors the first kind-card block's attributes into _pkiw_* post
 * meta on save, so Block Bindings (and templates) can consume what the
 * card knows. Card attrs win when non-empty; existing meta survives
 * empty attrs (completion and manual edits are never erased).
 *
 * @since 1.2.0
 */
class Card_Meta_Sync {

	/**
	 * Map of block name to attribute => meta suffix (appended to
	 * Meta_Fields::PREFIX). Each supported card block is one entry here;
	 * no new sync logic is needed to add another block.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const ATTR_META_MAP = [
		'post-kinds-indieweb/read-card' => [
			'bookTitle'   => 'read_title',
			'authorName'  => 'read_author',
			'isbn'        => 'read_isbn',
			'publisher'   => 'read_publisher',
			'publishDate' => 'read_publish_date',
			'pageCount'   => 'read_pages',
			'currentPage' => 'read_progress',
			'coverImage'  => 'read_cover',
			'bookUrl'     => 'read_url',
			'readStatus'  => 'read_status',
			'rating'      => 'read_rating',
			'startedAt'   => 'read_started_at',
			'finishedAt'  => 'read_finished_at',
			'review'      => 'read_review',
		],
		// Other card blocks join this map in follow-on work; the class is
		// deliberately map-driven so each is one entry, no new code.
	];

	/**
	 * Constructor.
	 *
	 * Hooked at save_post priority 25, after Taxonomy's kind sync (which
	 * runs on wp_after_insert_post, fired later in the request than
	 * save_post regardless of priority — so this always reads the same
	 * saved post_content the kind sync sees).
	 */
	public function __construct() {
		add_action( 'save_post', [ $this, 'sync' ], 25, 2 );
	}

	/**
	 * Mirror the first matching card block's attrs into post meta.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function sync( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		foreach ( parse_blocks( $post->post_content ) as $block ) {
			$map = self::ATTR_META_MAP[ $block['blockName'] ?? '' ] ?? null;
			if ( null === $map ) {
				continue;
			}

			foreach ( $map as $attr => $suffix ) {
				$value = $block['attrs'][ $attr ] ?? null;
				if ( null === $value || '' === $value ) {
					continue; // Never erase existing meta with an empty attr.
				}

				$value = (string) $value;

				// A changed ISBN invalidates any previously-derived ASIN —
				// clear it before writing the new ISBN so
				// Book_Completion_Controller::complete_on_save() (which
				// runs after this, at save_post:30) sees a blank read_asin
				// and re-derives it from the new ISBN instead of leaving
				// the stale one (which would render the wrong book's Kindle
				// preview). Scoped to isbn/asin only: cover and publisher
				// are user-visible and directly editable, so there's no
				// invisible-staleness risk to guard against there.
				if ( 'read_isbn' === $suffix ) {
					$current_isbn = get_post_meta( $post_id, Meta_Fields::PREFIX . 'read_isbn', true );
					if ( $current_isbn !== $value ) {
						delete_post_meta( $post_id, Meta_Fields::PREFIX . 'read_asin' );
					}
				}

				update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, sanitize_text_field( $value ) );
			}

			break; // First card block wins, mirroring the kind-sync semantics.
		}
	}
}

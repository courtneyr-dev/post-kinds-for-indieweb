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
		'post-kinds-indieweb/read-card'    => [
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
		'post-kinds-indieweb/checkin-card' => [
			'venueName'       => 'checkin_name',
			'venueType'       => 'checkin_type',
			'address'         => 'checkin_address',
			'locality'        => 'checkin_locality',
			'region'          => 'checkin_region',
			'country'         => 'checkin_country',
			'latitude'        => 'geo_latitude',
			'longitude'       => 'geo_longitude',
			'locationPrivacy' => 'geo_privacy',
			'venueUrl'        => 'checkin_url',
			'osmId'           => 'checkin_osm_id',
			'photo'           => 'checkin_photo',
		],
		'post-kinds-indieweb/listen-card'  => [
			'trackTitle'    => 'listen_track',
			'artistName'    => 'listen_artist',
			'albumTitle'    => 'listen_album',
			'coverImage'    => 'listen_cover',
			'musicbrainzId' => 'listen_mbid',
			'listenUrl'     => 'listen_url',
			'rating'        => 'listen_rating',
			'releaseDate'   => 'listen_release_date',
			'listenedAt'    => 'listen_listened_at',
		],
		'post-kinds-indieweb/watch-card'   => [
			'mediaTitle'    => 'watch_title',
			'releaseYear'   => 'watch_year',
			'posterImage'   => 'watch_poster',
			'tmdbId'        => 'watch_tmdb_id',
			'watchUrl'      => 'watch_url',
			'rating'        => 'watch_rating',
			'review'        => 'watch_review',
			'isRewatch'     => 'watch_is_rewatch',
			'mediaType'     => 'watch_media_type',
			'director'      => 'watch_director',
			'imdbId'        => 'watch_imdb_id',
			'showTitle'     => 'watch_show_title',
			'seasonNumber'  => 'watch_season',
			'episodeNumber' => 'watch_episode',
			'episodeTitle'  => 'watch_episode_title',
		],
		'post-kinds-indieweb/jam-card'     => [
			'title'  => 'jam_track',
			'artist' => 'jam_artist',
			'album'  => 'jam_album',
			'cover'  => 'jam_cover',
			'url'    => 'jam_url',
		],
		'post-kinds-indieweb/play-card'    => [
			'title'       => 'play_title',
			'platform'    => 'play_platform',
			'status'      => 'play_status',
			'hoursPlayed' => 'play_hours',
			'cover'       => 'play_cover',
			'rating'      => 'play_rating',
			'review'      => 'play_review',
			'gameUrl'     => 'play_game_url',
			'officialUrl' => 'play_official_url',
			'purchaseUrl' => 'play_purchase_url',
			'steamId'     => 'play_steam_id',
			'bggId'       => 'play_bgg_id',
			'rawgId'      => 'play_rawg_id',
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

		$block = self::find_first_mapped_block( parse_blocks( $post->post_content ) );
		if ( null !== $block ) {
			$map = self::ATTR_META_MAP[ $block['blockName'] ];

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
		}
	}

	/**
	 * Depth-first search for the first card block present in
	 * ATTR_META_MAP. Recursion matters: Micropub-generated content
	 * wraps its card inside an h-entry core/group, and editors can
	 * nest cards in groups/columns too — a top-level-only walk never
	 * sees those cards at all. First match in document order wins,
	 * mirroring the kind-sync semantics.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null The first mapped block, or null.
	 */
	private static function find_first_mapped_block( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( isset( self::ATTR_META_MAP[ $block['blockName'] ?? '' ] ) ) {
				return $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_first_mapped_block( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}

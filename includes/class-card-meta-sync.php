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
 * empty attrs (completion and manual edits are never erased), except the
 * provider IDs in PROVIDER_ATTRS, which a card that names another
 * provider's ID clears.
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
		'post-kinds-indieweb/comic-card'   => [
			'title'         => 'comic_title',
			'creators'      => 'comic_creators',
			'series'        => 'comic_series',
			'volume'        => 'comic_volume',
			'issueNumber'   => 'comic_issue',
			'publisher'     => 'comic_publisher',
			'coverImage'    => 'comic_cover',
			'coverImageAlt' => 'comic_cover_alt',
			'sourceUrl'     => 'comic_url',
			'readStatus'    => 'comic_status',
			'rating'        => 'comic_rating',
			'startedAt'     => 'comic_started_at',
			'finishedAt'    => 'comic_finished_at',
			'review'        => 'comic_review',
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
		'post-kinds-indieweb/eat-card'     => [
			'restaurant'       => 'eat_restaurant',
			'restaurantUrl'    => 'eat_restaurant_url',
			'locationName'     => 'eat_location_name',
			'locationAddress'  => 'eat_location_address',
			'locationLocality' => 'eat_location_locality',
			'locationRegion'   => 'eat_location_region',
			'locationCountry'  => 'eat_location_country',
			'geoLatitude'      => 'eat_geo_latitude',
			'geoLongitude'     => 'eat_geo_longitude',
			// Menu fields (issue 230/233): the eat archive groups and lists
			// posts from these without re-parsing post content.
			'name'             => 'eat_name',
			'cuisine'          => 'eat_cuisine',
			'rating'           => 'eat_rating',
			'ateAt'            => 'eat_ate_at',
			'notes'            => 'eat_notes',
		],
		'post-kinds-indieweb/drink-card'   => [
			'venueUrl'         => 'drink_venue_url',
			'locationName'     => 'drink_location_name',
			'locationAddress'  => 'drink_location_address',
			'locationLocality' => 'drink_location_locality',
			'locationRegion'   => 'drink_location_region',
			'locationCountry'  => 'drink_location_country',
			'geoLatitude'      => 'drink_geo_latitude',
			'geoLongitude'     => 'drink_geo_longitude',
			// Menu fields (issue 230/233).
			'name'             => 'drink_name',
			'drinkType'        => 'drink_type',
			'brand'            => 'drink_brewery',
			'rating'           => 'drink_rating',
			'drankAt'          => 'drink_drank_at',
			'notes'            => 'drink_notes',
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
	 * Defaults from block.json that the card renders when an attribute is absent from
	 * the serialized comment. Mirrored only when no non-empty row is stored
	 * (checked with get_metadata_raw(), which a registered default can't
	 * mask), so the archive files a post under what its card shows without
	 * ever overwriting a stored value.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const ATTR_DEFAULTS = [
		'post-kinds-indieweb/read-card'  => [
			'readStatus' => 'reading',
		],
		'post-kinds-indieweb/comic-card' => [
			'readStatus' => 'reading',
		],
	];

	/**
	 * Defaults a save writes over a stored row when the card leaves the
	 * attribute out. The editor drops an attribute that equals its
	 * block.json default, so a To Read card switched back to Currently
	 * Reading saves with no readStatus and would keep 'to-read' in meta.
	 * Only sync() applies these: the backfill stays fill-only through
	 * ATTR_DEFAULTS, so it never changes a stored status.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const SAVE_DEFAULTS = [
		'post-kinds-indieweb/read-card' => [
			'readStatus' => 'reading',
		],
	];

	/**
	 * Provider IDs the card decides. When the card names one of them, an ID
	 * it leaves out or blank is deleted from meta, so a play switched from
	 * RAWG or Steam to BGG leaves the video group. A card that names none,
	 * like a post with no such card, keeps the IDs Quick Post or the
	 * sidebar stored. Every other attribute keeps the never-erase rule.
	 *
	 * @var array<string, string[]>
	 */
	public const PROVIDER_ATTRS = [
		'post-kinds-indieweb/play-card' => [ 'bggId', 'rawgId', 'steamId' ],
	];

	/**
	 * Card attributes the wp_after_insert_post pass writes again, once the
	 * REST controller has written the request's meta. The editor sends the
	 * meta it loaded, so a stale read status or provider ID would otherwise
	 * beat the card. Every other key, privacy settings included, keeps the
	 * request's value.
	 *
	 * @var array<string, string[]>
	 */
	public const AFTER_INSERT_ATTRS = [
		'post-kinds-indieweb/read-card' => [ 'readStatus' ],
		'post-kinds-indieweb/play-card' => self::PROVIDER_ATTRS['post-kinds-indieweb/play-card'],
	];

	/**
	 * Boolean card attributes the card always decides: on writes '1', off or
	 * absent deletes the row. ATTR_META_MAP never erases meta with an empty
	 * attr, and the block comment drops an attribute that equals its
	 * block.json default, so switching a toggle off would otherwise leave
	 * it on. Each toggle follows the first block of its own type, even when
	 * another kind's card comes first.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const ATTR_TOGGLES = [
		'post-kinds-indieweb/acquisition-card' => [
			'showCostPublicly' => 'acquisition_cost_public',
		],
	];

	/**
	 * Privacy settings, as attribute => [ meta suffix, block.json default ],
	 * whose default is written on every save when the card leaves the
	 * attribute out. The editor drops an attribute that equals its default
	 * from the block comment, so a card switched back to private would
	 * otherwise keep an older 'public' in meta. Each is read from the first
	 * card of its own type, even when a card of another kind comes first.
	 * These cards stay out of ATTR_META_MAP, so a card that only holds a
	 * privacy setting never stops a later card's fields from syncing.
	 *
	 * @var array<string, array<string, array{0: string, 1: string}>>
	 */
	public const ATTR_PRIVATE_DEFAULTS = [
		'post-kinds-indieweb/rsvp-card' => [
			'locationVisibility' => [ 'rsvp_location_privacy', 'private' ],
		],
	];

	/**
	 * Meta suffixes whose values are multi-line prose.
	 *
	 * @var string[]
	 */
	private const TEXTAREA_SUFFIXES = [ 'eat_notes', 'drink_notes', 'comic_review' ];

	/**
	 * Backfill cron hook, completion option and the version it records.
	 * Bump BACKFILL_VERSION when sync_content() writes meta existing posts
	 * need, and every site re-runs the batched backfill once. One bump per
	 * release covers every change in it, unless an earlier bump already
	 * reached main: a site that finished that version never runs the same
	 * number again. Version 3 (PR 340) re-syncs a card behind an RSVP card,
	 * which version 2 skipped while the RSVP card sat in ATTR_META_MAP.
	 * Version 4 fills the read-card status default and clears the play-card
	 * provider IDs a card switched to another provider no longer has.
	 */
	public const BACKFILL_HOOK    = 'pkiw_card_meta_backfill';
	public const BACKFILL_OPTION  = 'pkiw_card_meta_backfill';
	public const BACKFILL_CURSOR  = 'pkiw_card_meta_backfill_cursor';
	public const BACKFILL_VERSION = '4';
	public const BACKFILL_BATCH   = 50;

	/**
	 * Constructor.
	 *
	 * Hooked at save_post priority 25, after Taxonomy's kind sync (which
	 * runs on wp_after_insert_post, fired later in the request than
	 * save_post regardless of priority — so this always reads the same
	 * saved post_content the kind sync sees).
	 *
	 * The REST controller writes request meta after save_post and fires
	 * wp_after_insert_post once it has. resync_after_insert() runs there, at
	 * 5, and writes back only AFTER_INSERT_ATTRS before the priority-10
	 * callbacks read the post.
	 */
	public function __construct() {
		add_action( 'save_post', [ $this, 'sync' ], 25, 2 );
		add_action( 'wp_after_insert_post', [ $this, 'resync_after_insert' ], 5, 2 );
		add_action( self::BACKFILL_HOOK, [ self::class, 'run_backfill_event' ] );
		add_action( 'init', [ self::class, 'maybe_schedule_backfill' ], 20 );
	}

	/**
	 * Mirror the first matching card block's attrs into post meta.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function sync( int $post_id, \WP_Post $post ): void {
		if ( ! self::syncs( $post_id, $post ) ) {
			return;
		}

		self::sync_content( $post_id, $post->post_content, true );
	}

	/**
	 * Write the first mapped card's AFTER_INSERT_ATTRS again, after the REST
	 * controller has written the request's meta.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function resync_after_insert( int $post_id, \WP_Post $post ): void {
		if ( ! self::syncs( $post_id, $post ) ) {
			return;
		}

		$block = self::find_first_mapped_block( parse_blocks( $post->post_content ) );
		if ( null === $block ) {
			return;
		}

		foreach ( self::AFTER_INSERT_ATTRS[ $block['blockName'] ] ?? [] as $attr ) {
			self::sync_attr( $post_id, $block, $attr, self::ATTR_META_MAP[ $block['blockName'] ][ $attr ], true );
		}
	}

	/**
	 * Whether a save of this post syncs card meta: a post, not a revision or
	 * an autosave.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return bool
	 */
	private static function syncs( int $post_id, \WP_Post $post ): bool {
		return 'post' === $post->post_type && ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id );
	}

	/**
	 * Schedule the one-time batched backfill until it has completed for
	 * the current BACKFILL_VERSION. The cursor lives in an option, so a
	 * lost cron event is simply rescheduled from where it stopped.
	 *
	 * @return void
	 */
	public static function maybe_schedule_backfill(): void {
		if ( self::BACKFILL_VERSION === get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		if ( false === wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::BACKFILL_HOOK );
		}
	}

	/**
	 * Cron handler: run one batch from the stored cursor, then either
	 * schedule the next batch or record completion.
	 *
	 * @return void
	 */
	public static function run_backfill_event(): void {
		$result = self::backfill_batch( (int) get_option( self::BACKFILL_CURSOR, 0 ), self::BACKFILL_BATCH );

		if ( $result['done'] ) {
			delete_option( self::BACKFILL_CURSOR );
			update_option( self::BACKFILL_OPTION, self::BACKFILL_VERSION, false );
			return;
		}

		update_option( self::BACKFILL_CURSOR, $result['last_id'], false );
		wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK );
	}

	/**
	 * Re-sync card meta for one batch of posts that carry a Post Kinds
	 * block, in ID order after a cursor. Reads post_content only; never
	 * writes it, and never fires save_post, so modified dates stay put.
	 * Idempotent: the same content always produces the same meta.
	 *
	 * @param int $after_id Process posts with an ID greater than this.
	 * @param int $limit    Batch size.
	 * @return array{processed:int,last_id:int,done:bool}
	 */
	public static function backfill_batch( int $after_id = 0, int $limit = self::BACKFILL_BATCH ): array {
		global $wpdb;

		$limit = max( 1, $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off batched maintenance scan.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND ID > %d AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
				$after_id,
				'%' . $wpdb->esc_like( '<!-- wp:post-kinds-indieweb/' ) . '%',
				$limit
			)
		);

		$last_id = $after_id;
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				self::sync_content( $id, $post->post_content );
			}
			$last_id = $id;
		}

		return [
			'processed' => count( $ids ),
			'last_id'   => $last_id,
			'done'      => count( $ids ) < $limit,
		];
	}

	/**
	 * Mirror the first mapped card block in some post content into meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content.
	 * @param bool   $on_save Whether a save called this, which also writes SAVE_DEFAULTS
	 *                        over stored rows. The backfill leaves it false.
	 * @return void
	 */
	public static function sync_content( int $post_id, string $content, bool $on_save = false ): void {
		$blocks = parse_blocks( $content );
		$block  = self::find_first_mapped_block( $blocks );
		if ( null !== $block ) {
			foreach ( self::ATTR_META_MAP[ $block['blockName'] ] as $attr => $suffix ) {
				self::sync_attr( $post_id, $block, $attr, $suffix, $on_save );
			}
		}

		foreach ( self::ATTR_TOGGLES as $name => $toggles ) {
			$card = self::find_first_mapped_block( $blocks, $name );
			foreach ( $toggles as $attr => $suffix ) {
				if ( null !== $card && true === ( $card['attrs'][ $attr ] ?? null ) ) {
					update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, '1' );
				} else {
					delete_post_meta( $post_id, Meta_Fields::PREFIX . $suffix );
				}
			}
		}

		// A read card ahead of an RSVP card must not leave the RSVP's
		// location public after its toggle goes off (issue 251).
		foreach ( self::ATTR_PRIVATE_DEFAULTS as $name => $privacy ) {
			$card = self::find_first_mapped_block( $blocks, $name );
			if ( null === $card ) {
				continue;
			}

			foreach ( $privacy as $attr => [ $suffix, $default ] ) {
				$value = (string) ( $card['attrs'][ $attr ] ?? '' );
				update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, sanitize_text_field( '' === $value ? $default : $value ) );
			}
		}

		// An unchanged cover fires no meta hook, so a post stored before
		// the cover map existed gets its map here, on save or backfill.
		refresh_cover_attachments( $post_id );
	}

	/**
	 * Mirror one card attribute into its meta key. Card attrs win when
	 * non-empty; an empty attr never erases meta, except a provider ID a
	 * card that names another provider's ID no longer has.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $block   Parsed card block.
	 * @param string               $attr    Attribute name.
	 * @param string               $suffix  Meta suffix the attribute maps to.
	 * @param bool                 $on_save Whether a save is syncing.
	 * @return void
	 */
	private static function sync_attr( int $post_id, array $block, string $attr, string $suffix, bool $on_save ): void {
		$value = self::attr_value( $post_id, $block, $attr, $suffix, $on_save );

		if ( null === $value || '' === $value ) {
			self::clear_provider_id( $post_id, $block, $attr, $suffix );
			return;
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

		$clean = in_array( $suffix, self::TEXTAREA_SUFFIXES, true )
			? sanitize_textarea_field( $value )
			: sanitize_text_field( $value );

		update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $clean );
	}

	/**
	 * A card attribute's value, with its block's defaults applied when the
	 * card leaves it out or blank: a SAVE_DEFAULTS value on the save path,
	 * else an ATTR_DEFAULTS value when no non-empty row is stored.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $block   Parsed card block.
	 * @param string               $attr    Attribute name.
	 * @param string               $suffix  Meta suffix the attribute maps to.
	 * @param bool                 $on_save Whether a save is syncing.
	 * @return mixed The value, or null or '' when there's none.
	 */
	private static function attr_value( int $post_id, array $block, string $attr, string $suffix, bool $on_save ) {
		$value = $block['attrs'][ $attr ] ?? null;
		if ( null !== $value && '' !== $value ) {
			return $value;
		}

		if ( $on_save && isset( self::SAVE_DEFAULTS[ $block['blockName'] ][ $attr ] ) ) {
			return self::SAVE_DEFAULTS[ $block['blockName'] ][ $attr ];
		}

		// get_metadata_raw(): a key registered with a default reads as
		// that default through get_post_meta() when no row exists.
		if ( isset( self::ATTR_DEFAULTS[ $block['blockName'] ][ $attr ] )
			&& '' === (string) get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . $suffix, true ) ) {
			return self::ATTR_DEFAULTS[ $block['blockName'] ][ $attr ];
		}

		return $value;
	}

	/**
	 * Delete a provider ID the card left out or blank when the card names
	 * another provider's ID, so a play switched from RAWG to BGG leaves the
	 * video group. A card that names no provider ID leaves every stored one
	 * alone: Quick Post and the sidebar store them with no card. Any other
	 * attribute is left alone.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $block   Parsed card block.
	 * @param string               $attr    Attribute name.
	 * @param string               $suffix  Meta suffix the attribute maps to.
	 * @return void
	 */
	private static function clear_provider_id( int $post_id, array $block, string $attr, string $suffix ): void {
		$providers = self::PROVIDER_ATTRS[ $block['blockName'] ] ?? [];
		if ( ! in_array( $attr, $providers, true ) || ! self::names_a_provider_id( $block, $providers ) ) {
			return;
		}

		if ( '' !== trim( (string) get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . $suffix, true ) ) ) {
			delete_post_meta( $post_id, Meta_Fields::PREFIX . $suffix );
		}
	}

	/**
	 * Whether the card holds a non-blank value for any of its provider IDs.
	 *
	 * @param array<string, mixed> $block     Parsed card block.
	 * @param string[]             $providers The card's provider ID attributes.
	 * @return bool
	 */
	private static function names_a_provider_id( array $block, array $providers ): bool {
		foreach ( $providers as $provider ) {
			$value = $block['attrs'][ $provider ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
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
	 * @param string                           $name   Only match this block name; empty matches any mapped block.
	 * @return array<string, mixed>|null The first mapped block, or null.
	 */
	private static function find_first_mapped_block( array $blocks, string $name = '' ): ?array {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			if ( '' === $name ? isset( self::ATTR_META_MAP[ $block_name ] ) : $name === $block_name ) {
				return $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_first_mapped_block( $block['innerBlocks'], $name );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}

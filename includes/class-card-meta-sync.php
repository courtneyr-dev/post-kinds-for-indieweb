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
		'post-kinds-indieweb/comic-card' => [
			'readStatus' => 'reading',
		],
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
	 * Card privacy settings whose stored row a second control writes too:
	 * REST meta, the update-post-meta and create-post abilities,
	 * wp_insert_post() meta_input, WP-CLI or another plugin (issue 358).
	 * Every write keeps the stricter of the card's value and the row, so a
	 * card can't loosen a stricter stored value and loosening needs both.
	 * In the block editor the card's control sets the row too, so a save
	 * after a change there sends both and loosens both.
	 * 'values' runs loosest to strictest. 'default' is the block.json
	 * default, the card's value when its comment leaves the attribute out,
	 * and how any value outside 'values' reads, as Meta_Fields reads it.
	 * The check-in setting follows the first mapped card, as ATTR_META_MAP
	 * does; the RSVP setting follows the first RSVP card. Cost visibility
	 * isn't here: Meta_Fields::COST_PUBLIC_KEY has no writer but the card.
	 *
	 * @var array<string, array{attr: string, suffix: string, default: string, values: string[]}>
	 */
	public const STRICTER_PRIVACY = [
		'post-kinds-indieweb/checkin-card' => [
			'attr'    => 'locationPrivacy',
			'suffix'  => 'geo_privacy',
			'default' => 'approximate',
			'values'  => [ 'public', 'approximate', 'private' ],
		],
		'post-kinds-indieweb/rsvp-card'    => [
			'attr'    => 'locationVisibility',
			'suffix'  => 'rsvp_location_privacy',
			'default' => 'private',
			'values'  => [ 'public', 'private' ],
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
	 * need, and every site re-runs the batched backfill once. Version 3
	 * re-syncs a card behind an RSVP card, which version 2 skipped while
	 * the RSVP card sat in ATTR_META_MAP.
	 */
	public const BACKFILL_HOOK    = 'pkiw_card_meta_backfill';
	public const BACKFILL_OPTION  = 'pkiw_card_meta_backfill';
	public const BACKFILL_CURSOR  = 'pkiw_card_meta_backfill_cursor';
	public const BACKFILL_VERSION = '3';
	public const BACKFILL_BATCH   = 50;

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
		add_action( self::BACKFILL_HOOK, [ self::class, 'run_backfill_event' ] );
		add_action( 'init', [ self::class, 'maybe_schedule_backfill' ], 20 );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'keep_stricter_privacy' ], 5, 3 );
		}
		add_action( 'before_delete_post', [ self::class, 'mark_deleting' ] );
		add_action( 'deleted_post', [ self::class, 'unmark_deleting' ] );
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

		self::sync_content( $post_id, $post->post_content );
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
	 * @return void
	 */
	public static function sync_content( int $post_id, string $content ): void {
		$blocks = parse_blocks( $content );
		$stored = self::stored_privacy( $post_id );
		$block  = self::find_first_mapped_block( $blocks );
		if ( null !== $block ) {
			$map      = self::ATTR_META_MAP[ $block['blockName'] ];
			$defaults = self::ATTR_DEFAULTS[ $block['blockName'] ] ?? [];

			foreach ( $map as $attr => $suffix ) {
				$value = $block['attrs'][ $attr ] ?? null;

				// get_metadata_raw(): a key registered with a default reads as
				// that default through get_post_meta() when no row exists.
				if ( ( null === $value || '' === $value ) && isset( $defaults[ $attr ] )
					&& '' === (string) get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . $suffix, true ) ) {
					$value = $defaults[ $attr ];
				}

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

				$clean = in_array( $suffix, self::TEXTAREA_SUFFIXES, true )
					? sanitize_textarea_field( $value )
					: sanitize_text_field( $value );

				update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $clean );
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

		// The writes above never leave a privacy row looser than it was (issue 358).
		self::apply_stricter_privacy( $post_id, $blocks, $stored );

		// An unchanged cover fires no meta hook, so a post stored before
		// the cover map existed gets its map here, on save or backfill.
		refresh_cover_attachments( $post_id );
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

	/**
	 * True while keep_stricter() writes a row, so the meta hooks that write
	 * fires leave it alone.
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * Posts wp_delete_post() is deleting, keyed by ID. Their rows go with them.
	 *
	 * @var array<int, true>
	 */
	private static array $deleting = [];

	/**
	 * Hold a STRICTER_PRIVACY row at least as strict as the post's card
	 * after a write or delete the card sync didn't make: REST meta,
	 * meta_input, an ability, WP-CLI or another plugin's update_post_meta()
	 * (issue 358). On added_, updated_ and deleted_post_meta at 5, so the
	 * row is held as each write lands, and a REST request that fails after
	 * writing it is held too. A missing row reads as the setting's default,
	 * so a deleted row gets the card's value back only when the card is
	 * stricter than that default: a Private check-in card.
	 * Posts only, as sync() is. Reads the card from the posts table, so
	 * meta_input saved with new content is held to the new card.
	 *
	 * @param int|int[] $meta_id   Meta ID, or IDs on delete. Unused.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 * @return void
	 */
	public static function keep_stricter_privacy( $meta_id, $object_id, $meta_key ): void {
		global $wpdb;

		$post_id = (int) $object_id;
		if ( self::$writing || $post_id <= 0 || isset( self::$deleting[ $post_id ] ) ) {
			return;
		}

		foreach ( self::STRICTER_PRIVACY as $name => $setting ) {
			$key = Meta_Fields::PREFIX . $setting['suffix'];
			if ( $key !== $meta_key ) {
				continue;
			}

			// wp_insert_post() writes meta_input before it clears the post
			// cache, so get_post() can still hold the content it replaced.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The cached post can be stale here.
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
			if ( ! is_object( $post ) || 'post' !== $post->post_type ) {
				return;
			}

			$card = self::privacy_card( parse_blocks( (string) $post->post_content ), $name );
			if ( null !== $card ) {
				self::keep_stricter( $post_id, $setting, $card, get_metadata_raw( 'post', $post_id, $key, true ) );
			}
		}
	}

	/**
	 * Note a post wp_delete_post() is about to delete, so deleting its rows
	 * doesn't write the card's setting back.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function mark_deleting( int $post_id ): void {
		self::$deleting[ $post_id ] = true;
	}

	/**
	 * Forget a post once wp_delete_post() has deleted it.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function unmark_deleting( int $post_id ): void {
		unset( self::$deleting[ $post_id ] );
	}

	/**
	 * Each STRICTER_PRIVACY row as stored now. get_metadata_raw() gives
	 * null for a missing row, where get_post_meta() gives the registered
	 * default.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Rows keyed by meta suffix.
	 */
	private static function stored_privacy( int $post_id ): array {
		$stored = [];
		foreach ( self::STRICTER_PRIVACY as $setting ) {
			$stored[ $setting['suffix'] ] = get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . $setting['suffix'], true );
		}

		return $stored;
	}

	/**
	 * Write the stricter of a card's privacy setting and the row stored
	 * before, for each setting whose card the post has. Where no row was
	 * stored, the card's own write stands, so a new card decides alone.
	 *
	 * @param int                              $post_id Post ID.
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param array<string, mixed>             $stored  Rows from stored_privacy().
	 * @return void
	 */
	private static function apply_stricter_privacy( int $post_id, array $blocks, array $stored ): void {
		foreach ( self::STRICTER_PRIVACY as $name => $setting ) {
			$row  = $stored[ $setting['suffix'] ] ?? null;
			$card = self::privacy_card( $blocks, $name );
			if ( null !== $row && null !== $card ) {
				self::keep_stricter( $post_id, $setting, $card, $row );
			}
		}
	}

	/**
	 * The card a STRICTER_PRIVACY setting follows: the check-in setting the
	 * first mapped card, when it's a check-in card, as ATTR_META_MAP syncs
	 * it; the RSVP setting the first RSVP card.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $name   A STRICTER_PRIVACY block name.
	 * @return array<string, mixed>|null The card, or null when the post has none.
	 */
	private static function privacy_card( array $blocks, string $name ): ?array {
		$card = isset( self::ATTR_META_MAP[ $name ] )
			? self::find_first_mapped_block( $blocks )
			: self::find_first_mapped_block( $blocks, $name );

		return null !== $card && $name === $card['blockName'] ? $card : null;
	}

	/**
	 * Store the stricter of a card's setting and a row, when the row
	 * doesn't hold it already. A missing row that reads as strict as the
	 * card stays missing: writing back a looser card's value would loosen it.
	 *
	 * @param int                                                                    $post_id Post ID.
	 * @param array{attr: string, suffix: string, default: string, values: string[]} $setting A STRICTER_PRIVACY entry.
	 * @param array<string, mixed>                                                   $card    The card block.
	 * @param mixed                                                                  $row     The row to compare, null for none.
	 * @return void
	 */
	private static function keep_stricter( int $post_id, array $setting, array $card, $row ): void {
		$keep = self::stricter( $setting, $card['attrs'][ $setting['attr'] ] ?? null, $row );
		$key  = Meta_Fields::PREFIX . $setting['suffix'];
		if ( ( null === $row && $setting['default'] === $keep ) || get_metadata_raw( 'post', $post_id, $key, true ) === $keep ) {
			return;
		}

		self::$writing = true;
		try {
			update_post_meta( $post_id, $key, $keep );
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * The stricter of two values of one setting. A value outside the
	 * setting's list, an absent attribute included, counts as its default.
	 *
	 * @param array{attr: string, suffix: string, default: string, values: string[]} $setting A STRICTER_PRIVACY entry.
	 * @param mixed                                                                  $card    The card's attribute value.
	 * @param mixed                                                                  $row     The stored row.
	 * @return string
	 */
	private static function stricter( array $setting, $card, $row ): string {
		$rank = static fn( $value ): int => (int) array_search(
			in_array( $value, $setting['values'], true ) ? $value : $setting['default'],
			$setting['values'],
			true
		);

		return $setting['values'][ max( $rank( $card ), $rank( $row ) ) ];
	}
}

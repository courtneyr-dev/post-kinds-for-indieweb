<?php
/**
 * Titles generated from location data follow the location's privacy.
 *
 * The check-in importers write "Checked in at <venue>" into post_title.
 * When the venue name is hidden, that title would still print it in the
 * h1, the document title, feeds, REST and every link to the post. This
 * class records which titles were generated and swaps a hidden one for
 * "Check-in, <date>" wherever WordPress prints a title. A slug WordPress
 * derives from such a title is built from "Check-in, <date>" too, including
 * one it derived before the location was hidden.
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
 * Title provenance and the safe title.
 */
class Title_Privacy {

	/**
	 * Post meta naming what a generated title was built from.
	 */
	public const META_KEY = '_pkiw_title_source';

	/**
	 * Marker value: the title was built from location data.
	 */
	public const SOURCE_LOCATION = 'location';

	/**
	 * Venue names the importers fall back to.
	 */
	private const PLACEHOLDER_VENUES = [ 'Unknown Venue', 'Unknown venue' ];

	/**
	 * Post meta that decides whether a generated title is hidden.
	 */
	private const DECIDING_META = [
		Meta_Fields::PREFIX . 'geo_privacy',
		Meta_Fields::PREFIX . 'checkin_name',
		Meta_Fields::PREFIX . 'checkin_venue',
		'geo_public',
		self::META_KEY,
	];

	/**
	 * Posts whose slug WordPress derived from the title in this request,
	 * keyed "<blog id>:<post id>".
	 *
	 * @var array<string, true>
	 */
	private static array $derived_slugs = [];

	/**
	 * Slugs derived for posts not inserted yet, keyed "<post type>|<slug>".
	 *
	 * @var array<string, true>
	 */
	private static array $pending_slugs = [];

	/**
	 * Option naming the stored-slug pass that has run on this site.
	 */
	private const SLUG_PASS_OPTION = 'pkiw_title_slug_pass';

	/**
	 * Bump to run the stored-slug pass again on every site.
	 */
	private const SLUG_PASS_VERSION = '2';

	/**
	 * Cursor for batched stored-slug repair.
	 */
	private const SLUG_PASS_CURSOR_OPTION = 'pkiw_title_slug_pass_cursor';

	/**
	 * Lock for one stored-slug repair request at a time.
	 */
	private const SLUG_PASS_LOCK_OPTION = 'pkiw_title_slug_pass_lock';

	/**
	 * Candidate count per stored-slug repair request.
	 */
	private const SLUG_PASS_BATCH = 100;

	/**
	 * Seconds before a stored-slug repair lock is stale.
	 */
	private const SLUG_PASS_LOCK_TTL = 300;

	private const SYNC_SKIPPED = 'skipped';

	private const SYNC_REPLACED = 'replaced';

	private const SYNC_FAILED = 'failed';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'the_title', [ $this, 'filter_the_title' ], 10, 2 );
		add_filter( 'single_post_title', [ $this, 'filter_single_post_title' ], 10, 2 );
		add_action( 'post_updated', [ $this, 'clear_marker_on_retitle' ], 10, 3 );

		// Another plugin may set the oEmbed title from post_title after core.
		add_filter( 'oembed_response_data', [ $this, 'scrub_oembed_title' ], 99, 2 );

		// A slug WordPress derives from a hidden generated title uses the safe title.
		add_filter( 'wp_insert_post_data', [ $this, 'filter_derived_slug' ], 10, 2 );
		add_action( 'wp_insert_post', [ $this, 'sync_slug_after_insert' ], 10, 3 );
		add_action( 'added_post_meta', [ $this, 'sync_slug_on_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'sync_slug_on_meta' ], 10, 3 );

		// Slugs saved before a hidden location replaced them (issue 379).
		add_action( 'init', [ self::class, 'maybe_replace_stored_slugs' ], 20 );
	}

	/**
	 * Run the stored-slug pass once per pass version.
	 */
	public static function maybe_replace_stored_slugs(): void {
		if ( self::SLUG_PASS_VERSION === get_option( self::SLUG_PASS_OPTION ) ) {
			return;
		}

		$lock_token = self::acquire_slug_pass_lock();
		if ( false === $lock_token ) {
			return;
		}

		try {
			self::replace_stored_slugs();
		} finally {
			global $wpdb;

			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only this request's lock token.
				$wpdb->options,
				[
					'option_name'  => self::SLUG_PASS_LOCK_OPTION,
					'option_value' => $lock_token,
				]
			);
			self::clear_slug_pass_lock_cache();
		}
	}

	/**
	 * Process one stored-slug repair batch.
	 *
	 * @return int Number of slugs replaced.
	 */
	private static function replace_stored_slugs(): int {
		$cursor   = self::slug_pass_cursor();
		$post_ids = self::stored_slug_pass_candidates( $cursor['after'] );

		if ( null === $post_ids ) {
			return 0;
		}

		$replaced = 0;
		$failed   = false;
		foreach ( $post_ids as $post_id ) {
			$result = self::sync_slug( (int) $post_id );
			if ( self::SYNC_REPLACED === $result ) {
				++$replaced;
			} elseif ( self::SYNC_FAILED === $result ) {
				$failed = true;
			}
		}

		if ( [] !== $post_ids ) {
			$cursor['after'] = max( array_map( 'intval', $post_ids ) );
		}
		$cursor['retry'] = $cursor['retry'] || $failed;

		if ( count( $post_ids ) < self::SLUG_PASS_BATCH ) {
			if ( $cursor['retry'] ) {
				update_option(
					self::SLUG_PASS_CURSOR_OPTION,
					[
						'after' => 0,
						'retry' => false,
					],
					false
				);
			} else {
				update_option( self::SLUG_PASS_OPTION, self::SLUG_PASS_VERSION, true );
				delete_option( self::SLUG_PASS_CURSOR_OPTION );
			}

			return $replaced;
		}

		update_option( self::SLUG_PASS_CURSOR_OPTION, $cursor, false );

		return $replaced;
	}

	/**
	 * Acquire the stored-slug pass lock.
	 */
	private static function acquire_slug_pass_lock(): string|false {
		global $wpdb;

		// A timestamp for the takeover check, plus a suffix only this request holds.
		$token    = time() . ':' . wp_generate_password( 12, false );
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock acquisition.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::SLUG_PASS_LOCK_OPTION,
				$token
			)
		);
		self::clear_slug_pass_lock_cache();

		if ( 1 === $inserted ) {
			return $token;
		}

		$locked_at = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read lock directly, bypassing option cache.
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::SLUG_PASS_LOCK_OPTION
			)
		);

		$now = time();
		if ( null === $locked_at || (int) $locked_at > $now - self::SLUG_PASS_LOCK_TTL ) {
			return false;
		}

		$taken = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic stale-lock takeover.
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$token,
				self::SLUG_PASS_LOCK_OPTION,
				(string) $locked_at
			)
		);
		self::clear_slug_pass_lock_cache();

		return 1 === $taken ? $token : false;
	}

	/**
	 * Clear option cache entries touched by direct lock writes.
	 */
	private static function clear_slug_pass_lock_cache(): void {
		wp_cache_delete( self::SLUG_PASS_LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		// A lock saved with update_option() autoloads, as delete_option() handles.
		$alloptions = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $alloptions ) && isset( $alloptions[ self::SLUG_PASS_LOCK_OPTION ] ) ) {
			unset( $alloptions[ self::SLUG_PASS_LOCK_OPTION ] );
			wp_cache_set( 'alloptions', $alloptions, 'options' );
		}
	}

	/**
	 * Stored cursor for the batched pass.
	 *
	 * @return array{after:int,retry:bool}
	 */
	private static function slug_pass_cursor(): array {
		$cursor = get_option( self::SLUG_PASS_CURSOR_OPTION );
		if ( ! is_array( $cursor ) ) {
			return [
				'after' => 0,
				'retry' => false,
			];
		}

		return [
			'after' => max( 0, (int) ( $cursor['after'] ?? 0 ) ),
			'retry' => (bool) ( $cursor['retry'] ?? false ),
		];
	}

	/**
	 * Candidate IDs for the stored-slug pass, or null when the SELECT failed.
	 *
	 * @param int $after Last processed post ID.
	 * @return array<int>|null
	 */
	private static function stored_slug_pass_candidates( int $after ): ?array {
		global $wpdb;

		$wpdb->last_error = '';
		$post_ids         = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One batched repair query per request.
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.ID > %d
					AND p.post_status NOT IN ('trash','auto-draft')
					AND (
						( m.meta_key = %s AND m.meta_value = %s )
						OR m.meta_key IN ( %s, %s )
					)
				ORDER BY p.ID ASC
				LIMIT %d",
				$after,
				self::META_KEY,
				self::SOURCE_LOCATION,
				Meta_Fields::PREFIX . 'checkin_name',
				Meta_Fields::PREFIX . 'checkin_venue',
				self::SLUG_PASS_BATCH
			)
		);

		if ( '' !== self::last_db_error() ) {
			return null;
		}

		return array_map( 'intval', $post_ids );
	}

	/**
	 * The error the last database query left, read after the query ran.
	 *
	 * @return string Empty when the query succeeded.
	 */
	private static function last_db_error(): string {
		global $wpdb;

		return $wpdb->last_error;
	}

	/**
	 * Record that a post's title was generated from location data.
	 *
	 * Writing the marker also rechecks a slug WordPress derived from that
	 * title in this request, through sync_slug_on_meta().
	 *
	 * @param int $post_id Post ID.
	 */
	public static function mark_location_title( int $post_id ): void {
		update_post_meta( $post_id, self::META_KEY, self::SOURCE_LOCATION );
	}

	/**
	 * Derive the slug from the safe title when WordPress derives one.
	 *
	 * WordPress builds a slug from post_title when a post leaves draft
	 * without one. A slug the caller passed, or one the post already has,
	 * is left to sync_slug(), which replaces only one derived from the title.
	 *
	 * @param array<string, mixed> $data    Slashed post data about to be written.
	 * @param array<string, mixed> $postarr Sanitized post data passed in.
	 * @return array<string, mixed>
	 */
	public function filter_derived_slug( $data, $postarr ) {
		if ( ! is_array( $data ) || '' === (string) ( $data['post_name'] ?? '' ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$stored  = $post_id > 0 ? get_post( $post_id ) : null;

		// wp_insert_post() keeps the stored slug on an update that passes
		// none, and derives one from the title when the result is empty().
		if ( isset( $postarr['post_name'] ) ) {
			$requested = $postarr['post_name'];
		} else {
			$requested = $stored instanceof \WP_Post ? $stored->post_name : '';
		}

		if ( ! empty( $requested ) ) {
			// An author's slug, or a trash suffix, replaces one derived earlier in this request.
			if ( $stored instanceof \WP_Post && $data['post_name'] !== $stored->post_name ) {
				unset( self::$derived_slugs[ self::slug_key( $post_id ) ] );
			}
			// An insert that failed at the database left its pending key; this slug is the author's.
			if ( $post_id <= 0 ) {
				unset( self::$pending_slugs[ $data['post_type'] . '|' . $data['post_name'] ] );
			}
			return $data;
		}

		if ( $post_id <= 0 ) {
			// No post yet, so no location meta to decide by. sync_slug_after_insert() picks it up.
			self::$pending_slugs[ $data['post_type'] . '|' . $data['post_name'] ] = true;
			return $data;
		}

		self::$derived_slugs[ self::slug_key( $post_id ) ] = true;

		if ( ! $stored instanceof \WP_Post || wp_unslash( (string) $data['post_title'] ) !== $stored->post_title ) {
			// A new title clears the marker after this filter runs; sync_slug_after_insert() decides then.
			return $data;
		}

		// Publishing a draft can move its date, and the safe title names the date.
		$post            = clone $stored;
		$post->post_date = (string) $data['post_date'];

		if ( self::names_hidden_location( $post ) ) {
			$data['post_name'] = self::safe_slug( $post, (string) $data['post_status'] );
		}

		return $data;
	}

	/**
	 * Recheck a slug derived in this insert once the post and its meta exist.
	 *
	 * The importers insert a published post, so WordPress derives its slug,
	 * before they write its location, privacy and title marker.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether an existing post was updated.
	 */
	public function sync_slug_after_insert( $post_id, $post, $update ): void {
		$post_id = (int) $post_id;

		if ( ! $update && $post instanceof \WP_Post ) {
			$pending = $post->post_type . '|' . $post->post_name;
			if ( isset( self::$pending_slugs[ $pending ] ) ) {
				unset( self::$pending_slugs[ $pending ] );
				self::$derived_slugs[ self::slug_key( $post_id ) ] = true;
			}
		}

		// Ordinary saves skip the old-slug read; the meta writes and the pass cover it.
		self::sync_slug( $post_id, false );
	}

	/**
	 * Recheck a derived slug when the post's privacy, venue or title marker
	 * is written.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public function sync_slug_on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, self::DECIDING_META, true ) ) {
			self::sync_slug( (int) $object_id );
		}
	}

	/**
	 * Swap a slug WordPress derived from the stored title, in this request
	 * or an earlier one, for the safe one when the post's generated title is
	 * hidden.
	 *
	 * @param int  $post_id          Post ID.
	 * @param bool $scrub_old_slugs  Whether a post whose slug isn't derived from its title still has hidden-location old slugs removed.
	 * @return string One of the SYNC_* constants.
	 */
	private static function sync_slug( int $post_id, bool $scrub_old_slugs = true ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || '' === $post->post_name || 'trash' === $post->post_status ) {
			return self::SYNC_SKIPPED;
		}

		$derived_slug = isset( self::$derived_slugs[ self::slug_key( $post_id ) ] ) || self::is_title_slug( $post );
		if ( ! $derived_slug ) {
			if ( ! $scrub_old_slugs || ! self::names_hidden_location( $post ) ) {
				return self::SYNC_SKIPPED;
			}

			$guid    = self::scrub_title_slug_from_guid( $post, $post->post_name );
			$written = true;
			if ( $guid !== $post->guid ) {
				global $wpdb;

				$written = $wpdb->update( $wpdb->posts, [ 'guid' => $guid ], [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- clean_post_cache() follows.
				if ( false !== $written ) {
					clean_post_cache( $post_id );
				}
			}

			$cleaned = self::delete_hidden_location_old_slugs( $post );

			return false !== $written && $cleaned ? self::SYNC_SKIPPED : self::SYNC_FAILED;
		}

		if ( ! self::names_hidden_location( $post ) ) {
			return self::SYNC_SKIPPED;
		}

		$slug = self::safe_slug( $post, $post->post_status );
		if ( '' === $slug || $slug === $post->post_name ) {
			return self::delete_hidden_location_old_slugs( $post ) ? self::SYNC_SKIPPED : self::SYNC_FAILED;
		}

		// wp_insert_post() set a new post's guid to its permalink, built from
		// the slug being replaced. Feeds and REST print the guid.
		$fields = [ 'post_name' => $slug ];
		$guid   = self::replace_slug_segment_in_guid( $post->guid, $post->post_name, $slug );
		if ( $guid !== $post->guid ) {
			$fields['guid'] = $guid;
		}

		global $wpdb;

		// Written in place, as wp_insert_post() fills a missing slug, so the
		// save hooks don't run a second time mid-insert. The venue slug isn't
		// kept in _wp_old_slug, and older venue slugs are cleaned up too.
		$written = $wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- clean_post_cache() follows.
		if ( false === $written ) {
			return self::SYNC_FAILED;
		}
		clean_post_cache( $post_id );
		$cleaned = self::delete_hidden_location_old_slugs( $post, $post->post_name );

		/**
		 * Fires after a slug WordPress derived from a hidden generated title
		 * is replaced in place, without the save hooks running again. Also
		 * fires for a slug saved in an earlier request.
		 *
		 * Code that stored the post's permalink when it was saved, such as
		 * Yoast SEO's indexable, rebuilds it here.
		 *
		 * @since 1.9.0
		 *
		 * @param int    $post_id  Post ID.
		 * @param string $old_slug The slug derived from the stored title.
		 * @param string $new_slug The slug derived from "Check-in, <date>".
		 */
		do_action( 'pkiw_derived_slug_replaced', $post_id, $post->post_name, $slug );

		return $cleaned ? self::SYNC_REPLACED : self::SYNC_FAILED;
	}

	/**
	 * Replace title-derived guid segments without changing an author slug.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $slug Slug to write into the guid.
	 */
	private static function scrub_title_slug_from_guid( \WP_Post $post, string $slug ): string {
		$title_slug = sanitize_title( (string) $post->post_title );

		// A number-only slug can't name a venue, and it would match a date
		// segment or a ?p= post ID.
		if ( '' === $title_slug || ctype_digit( $title_slug ) ) {
			return $post->guid;
		}

		// Path segments only: a plain-permalink guid carries IDs, not slugs.
		return (string) preg_replace( '#(?<=/)' . preg_quote( $title_slug, '#' ) . '(?:-\d+)?(?=[/?&\#]|$)#', $slug, $post->guid );
	}

	/**
	 * Replace one slug path or query segment in a guid.
	 *
	 * @param string $guid     Guid to scrub.
	 * @param string $old_slug Slug to replace.
	 * @param string $new_slug Replacement slug.
	 */
	private static function replace_slug_segment_in_guid( string $guid, string $old_slug, string $new_slug ): string {
		return (string) preg_replace( '#(?<=[/=])' . preg_quote( $old_slug, '#' ) . '(?=[/?&\#]|$)#', $new_slug, $guid );
	}

	/**
	 * Delete old slugs that expose a hidden generated title.
	 *
	 * @param \WP_Post    $post          Post.
	 * @param string|null $replaced_slug Stored slug that was replaced.
	 * @return bool False when a delete failed.
	 */
	private static function delete_hidden_location_old_slugs( \WP_Post $post, ?string $replaced_slug = null ): bool {
		$old_slugs = get_post_meta( $post->ID, '_wp_old_slug', false );
		if ( [] === $old_slugs ) {
			return true;
		}

		$cleaned = true;

		$title_slug = sanitize_title( (string) $post->post_title );
		// delete_post_meta() removes every row with the value, so a repeated value is deleted once.
		foreach ( array_unique( array_map( 'strval', $old_slugs ) ) as $old_slug ) {
			$delete = null !== $replaced_slug && $old_slug === $replaced_slug;

			if ( '' !== $title_slug ) {
				$delete = $delete || $old_slug === $title_slug || 1 === preg_match( '/^' . preg_quote( $title_slug, '/' ) . '-\d+$/', $old_slug );
			}

			if ( $delete && ! delete_post_meta( $post->ID, '_wp_old_slug', $old_slug ) ) {
				$cleaned = false;
			}
		}

		return $cleaned;
	}

	/**
	 * Whether the post's slug is the one WordPress derives from its stored
	 * title, with the "-2" style suffix wp_unique_post_slug() adds.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function is_title_slug( \WP_Post $post ): bool {
		$base = sanitize_title( (string) $post->post_title );
		if ( '' === $base ) {
			return false;
		}

		return $post->post_name === $base || 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '-\d+$/', $post->post_name );
	}

	/**
	 * A unique slug built from the safe title.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string   $status The status the post is saved with.
	 */
	private static function safe_slug( \WP_Post $post, string $status ): string {
		return wp_unique_post_slug( sanitize_title( self::fallback_title( $post ) ), $post->ID, $status, $post->post_type, (int) $post->post_parent );
	}

	/**
	 * Key for a post in this request, so a switched multisite blog's post
	 * with the same ID isn't mistaken for it.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function slug_key( int $post_id ): string {
		return get_current_blog_id() . ':' . $post_id;
	}

	/**
	 * The title to print for a post.
	 *
	 * Decided for a visitor who can't edit the post, whoever is asking:
	 * feeds and federated records are built in the publishing editor's
	 * request and read by everyone.
	 *
	 * @param int $post_id Post ID.
	 * @return string The stored title, or "Check-in, <date>" when the stored
	 *                title was generated from a location that is hidden.
	 */
	public static function safe_title( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$title = (string) $post->post_title;

		return self::names_hidden_location( $post ) ? self::fallback_title( $post ) : $title;
	}

	/**
	 * Whether the stored title was generated from a location that is hidden.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function names_hidden_location( \WP_Post $post ): bool {
		if ( '' === trim( (string) $post->post_title ) ) {
			return false;
		}

		$marked = self::SOURCE_LOCATION === get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! $marked && ! self::matches_generated_form( $post ) ) {
			return false;
		}

		return ! Meta_Fields::get_public_location_fields( $post->ID )['name'];
	}

	/**
	 * Whether an unmarked title has a form the importers generate.
	 *
	 * Covers posts imported before the marker existed: the venue name on
	 * its own, or "Checked in at <venue>" in English or the site language.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function matches_generated_form( \WP_Post $post ): bool {
		$names = self::PLACEHOLDER_VENUES;
		foreach ( [ 'checkin_name', 'checkin_venue' ] as $key ) {
			$names[] = (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . $key, true );
		}

		$title = self::normalize( (string) $post->post_title );

		foreach ( array_filter( $names ) as $name ) {
			$forms = [
				$name,
				sprintf( 'Checked in at %s', $name ),
				/* translators: %s: venue name */
				sprintf( __( 'Checked in at %s', 'post-kinds-for-indieweb-in-block-themes' ), $name ),
			];

			foreach ( $forms as $form ) {
				if ( self::normalize( $form ) === $title ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Lowercase, single-spaced text for comparing titles.
	 *
	 * @param string $text Text.
	 */
	private static function normalize( string $text ): string {
		return strtolower( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ) );
	}

	/**
	 * "Check-in, September 12, 2026".
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function fallback_title( \WP_Post $post ): string {
		return sprintf(
			/* translators: %s: the post's date */
			__( 'Check-in, %s', 'post-kinds-for-indieweb-in-block-themes' ),
			(string) wp_date( (string) get_option( 'date_format' ), (int) get_post_timestamp( $post ) )
		);
	}

	/**
	 * Admin screens list and edit the stored title.
	 */
	private static function is_admin_screen(): bool {
		return is_admin() && ! wp_doing_ajax() && ! wp_doing_cron();
	}

	/**
	 * Swap a hidden generated title wherever get_the_title() prints one.
	 *
	 * @param string   $title   Title.
	 * @param int|null $post_id Post ID.
	 * @return string
	 */
	public function filter_the_title( $title, $post_id = null ) {
		if ( ! $post_id || self::is_admin_screen() ) {
			return $title;
		}

		$post = get_post( (int) $post_id );

		return $post instanceof \WP_Post && self::names_hidden_location( $post ) ? self::fallback_title( $post ) : $title;
	}

	/**
	 * Same swap for the document title.
	 *
	 * @param string        $title Title.
	 * @param \WP_Post|null $post  Post.
	 * @return string
	 */
	public function filter_single_post_title( $title, $post = null ) {
		return $post instanceof \WP_Post && self::names_hidden_location( $post ) ? self::fallback_title( $post ) : $title;
	}

	/**
	 * Replace a post's stored title with the safe one inside text, or
	 * inside every string of an array, that another plugin built.
	 *
	 * @param mixed $value  A string, or an array of strings and arrays.
	 * @param mixed $source What the other plugin built it for: Yoast passes
	 *                      its presentation or schema context. Without one,
	 *                      the post being viewed.
	 * @return mixed
	 */
	public static function scrub_stored_title( $value, $source = null ) {
		$post_id = 0;
		if ( is_object( $source ) ) {
			$model = $source->model ?? $source->indexable ?? null;
			if ( is_object( $model ) && 'post' === ( $model->object_type ?? 'post' ) ) {
				$post_id = (int) ( $model->object_id ?? 0 );
			}
		}

		$post = $post_id > 0 ? get_post( $post_id ) : ( is_singular() ? get_queried_object() : null );
		if ( ! $post instanceof \WP_Post || ! self::names_hidden_location( $post ) ) {
			return $value;
		}

		$stored = (string) $post->post_title;
		$needle = array_unique( [ $stored, esc_html( $stored ), wptexturize( $stored ) ] );
		$safe   = self::fallback_title( $post );

		$scrub = static function ( $item ) use ( &$scrub, $needle, $safe ) {
			if ( is_string( $item ) ) {
				return str_ireplace( $needle, $safe, $item );
			}
			return is_array( $item ) ? array_map( $scrub, $item ) : $item;
		};

		return $scrub( $value );
	}

	/**
	 * The oEmbed title another plugin may have set from post_title.
	 *
	 * @param array<string, mixed> $data oEmbed response data.
	 * @param \WP_Post|null        $post Post.
	 * @return array<string, mixed>
	 */
	public function scrub_oembed_title( $data, $post = null ) {
		if ( is_array( $data ) && $post instanceof \WP_Post && self::names_hidden_location( $post ) ) {
			$data['title'] = self::fallback_title( $post );
		}

		return $data;
	}

	/**
	 * A title changed after the import is the author's.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $after   Post after the update.
	 * @param \WP_Post $before  Post before the update.
	 */
	public function clear_marker_on_retitle( $post_id, $after, $before ): void {
		if ( $after->post_title !== $before->post_title ) {
			delete_post_meta( (int) $post_id, self::META_KEY );
		}
	}
}

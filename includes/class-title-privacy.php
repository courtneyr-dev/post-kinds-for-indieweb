<?php
/**
 * Titles generated from location data follow the location's privacy.
 *
 * The check-in importers write "Checked in at <venue>" into post_title.
 * When the venue name is hidden, that title would still print it in the
 * h1, the document title, feeds, REST and every link to the post. This
 * class records which titles were generated and swaps a hidden one for
 * "Check-in, <date>" wherever WordPress prints a title. A slug WordPress
 * derives from such a title is built from "Check-in, <date>" too.
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
	 * is never touched, so a published link doesn't change.
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

		self::sync_slug( $post_id );
	}

	/**
	 * Recheck a slug derived in this request when its privacy, venue or
	 * title marker is written.
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
	 * Swap a slug WordPress derived in this request for the safe one when
	 * the post's generated title is hidden.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function sync_slug( int $post_id ): void {
		if ( ! isset( self::$derived_slugs[ self::slug_key( $post_id ) ] ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || '' === $post->post_name || 'trash' === $post->post_status || ! self::names_hidden_location( $post ) ) {
			return;
		}

		$slug = self::safe_slug( $post, $post->post_status );
		if ( '' === $slug || $slug === $post->post_name ) {
			return;
		}

		// wp_insert_post() set a new post's guid to its permalink, built from
		// the slug being replaced. Feeds and REST print the guid.
		$fields = [ 'post_name' => $slug ];
		$guid   = (string) preg_replace( '#(?<=[/=])' . preg_quote( $post->post_name, '#' ) . '(?=[/?&\#]|$)#', $slug, $post->guid );
		if ( $guid !== $post->guid ) {
			$fields['guid'] = $guid;
		}

		global $wpdb;

		// Written in place, as wp_insert_post() fills a missing slug, so the
		// save hooks don't run a second time mid-insert. The venue slug isn't
		// kept in _wp_old_slug: a redirect from it would confirm a guessed
		// venue URL, and it existed only during this request.
		$written = $wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- clean_post_cache() follows.
		if ( false === $written ) {
			return;
		}
		clean_post_cache( $post_id );

		/**
		 * Fires after a slug WordPress derived from a hidden generated title
		 * is replaced in place, without the save hooks running again.
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

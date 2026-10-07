<?php
/**
 * Post Meta Fields Registration
 *
 * Registers all post meta fields for IndieWeb post kinds with REST API exposure.
 *
 * @package PKIW
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta Fields registration class.
 *
 * Handles registration of all post meta fields needed for post kinds,
 * with proper sanitization, REST API exposure, and Block Bindings support.
 *
 * @since 1.0.0
 */
class Meta_Fields {

	/**
	 * Meta key prefix.
	 *
	 * @var string
	 */
	public const PREFIX = '_pkiw_';

	/**
	 * Post types to register meta for.
	 *
	 * @var array<string>
	 */
	private array $post_types = [ 'post' ];

	/**
	 * Meta field definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields = [];

	/**
	 * Constructor.
	 *
	 * Sets up field definitions and hooks.
	 */
	public function __construct() {
		$this->define_fields();
		$this->register_hooks();
	}

	/**
	 * Define all meta field configurations.
	 *
	 * @return void
	 */
	private function define_fields(): void {
		$this->fields = [
			// Citation Fields (All Response Kinds).
			'cite_name'               => [
				'type'        => 'string',
				'description' => __( 'Title of the cited content.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'cite_url'                => [
				'type'        => 'string',
				'description' => __( 'URL of the cited content.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'cite_author'             => [
				'type'        => 'string',
				'description' => __( 'Author name of the cited content.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'cite_author_url'         => [
				'type'        => 'string',
				'description' => __( 'Author URL of the cited content.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'cite_photo'              => [
				'type'        => 'string',
				'description' => __( 'Featured image URL of the cited content.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'cite_summary'            => [
				'type'        => 'string',
				'description' => __( 'Summary or excerpt of the cited content.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'bookmark_embed_type'     => [
				'type'        => 'string',
				'description' => __( 'Embed type for bookmarks: auto, oembed, bookmark-card, or none.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => 'auto',
			],
			'cite_published'          => [
				'type'        => 'string',
				'description' => __( 'Publication date of the cited content (ISO 8601).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// RSVP Fields.
			'rsvp_status'             => [
				'type'        => 'string',
				'description' => __( 'RSVP status: yes, no, maybe, or interested.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rsvp_status' ],
				'default'     => '',
				'enum'        => [ '', 'yes', 'no', 'maybe', 'interested' ],
			],
			'rsvp_location_privacy'   => [
				'type'        => 'string',
				'description' => __( 'Who sees the RSVP\'s event location: private (people who can edit the post) or public.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rsvp_location_privacy' ],
				'default'     => 'private',
				'enum'        => [ 'private', 'public' ],
			],

			// Check-in Fields.
			'checkin_name'            => [
				'type'        => 'string',
				'description' => __( 'Venue or location name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_url'             => [
				'type'        => 'string',
				'description' => __( 'Venue URL.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'checkin_address'         => [
				'type'        => 'string',
				'description' => __( 'Street address.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_locality'        => [
				'type'        => 'string',
				'description' => __( 'City or locality.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_region'          => [
				'type'        => 'string',
				'description' => __( 'State, province, or region.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_country'         => [
				'type'        => 'string',
				'description' => __( 'Country name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'geo_latitude'            => [
				'type'        => 'number',
				'description' => __( 'Geographic latitude.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'geo_longitude'           => [
				'type'        => 'number',
				'description' => __( 'Geographic longitude.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'geo_privacy'             => [
				'type'        => 'string',
				'description' => __( 'Location privacy level: public, approximate, or private.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_geo_privacy' ],
				'default'     => 'approximate',
				'enum'        => [ 'public', 'approximate', 'private' ],
			],
			'checkin_osm_id'          => [
				'type'        => 'string',
				'description' => __( 'OpenStreetMap place ID.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Listen Fields.
			'listen_track'            => [
				'type'        => 'string',
				'description' => __( 'Track or episode name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_artist'           => [
				'type'        => 'string',
				'description' => __( 'Artist or creator name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_album'            => [
				'type'        => 'string',
				'description' => __( 'Album or podcast name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_cover'            => [
				'type'        => 'string',
				'description' => __( 'Album or podcast cover art URL.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'listen_mbid'             => [
				'type'        => 'string',
				'description' => __( 'MusicBrainz recording ID.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_url'              => [
				'type'        => 'string',
				'description' => __( 'URL to the track or episode.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'listen_rating'           => [
				'type'        => 'integer',
				'description' => __( 'Rating for the track (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'listen_release_date'     => [
				'type'        => 'string',
				'description' => __( 'Album release date.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_listened_at'      => [
				'type'        => 'string',
				'description' => __( 'When the track was listened to.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Watch Fields.
			'watch_title'             => [
				'type'        => 'string',
				'description' => __( 'Film or show title.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_year'              => [
				'type'        => 'string',
				'description' => __( 'Release year.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_poster'            => [
				'type'        => 'string',
				'description' => __( 'Poster image URL.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'watch_tmdb_id'           => [
				'type'        => 'string',
				'description' => __( 'TMDB ID for the film or show.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_status'            => [
				'type'        => 'string',
				'description' => __( 'Watch status: watched, watching, or abandoned.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_watch_status' ],
				'default'     => 'to-watch',
				'enum'        => [ 'to-watch', 'watching', 'watched', 'rewatching', 'abandoned' ],
			],
			'watch_spoilers'          => [
				'type'        => 'boolean',
				'description' => __( 'Whether the post contains spoilers.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => false,
			],
			'watch_url'               => [
				'type'        => 'string',
				'description' => __( 'URL to the film or show page.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'watch_rating'            => [
				'type'        => 'number',
				'description' => __( 'Rating out of 5.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'watch_review'            => [
				'type'        => 'string',
				'description' => __( 'Review or notes about the film/show.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'watch_is_rewatch'        => [
				'type'        => 'boolean',
				'description' => __( 'Whether this is a rewatch.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => false,
			],
			'watch_media_type'        => [
				'type'        => 'string',
				'description' => __( 'Media type: movie or tv.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => 'movie',
			],
			'watch_director'          => [
				'type'        => 'string',
				'description' => __( 'Director name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_imdb_id'           => [
				'type'        => 'string',
				'description' => __( 'IMDB ID for the film or show.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_show_title'        => [
				'type'        => 'string',
				'description' => __( 'TV show title (for episodes).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_season'            => [
				'type'        => 'number',
				'description' => __( 'Season number.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'watch_episode'           => [
				'type'        => 'number',
				'description' => __( 'Episode number.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'watch_episode_title'     => [
				'type'        => 'string',
				'description' => __( 'Episode title.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Read Fields.
			'read_title'              => [
				'type'        => 'string',
				'description' => __( 'Book or article title.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_author'             => [
				'type'        => 'string',
				'description' => __( 'Book or article author.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_isbn'               => [
				'type'        => 'string',
				'description' => __( 'ISBN-13 of the book.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_isbn' ],
				'default'     => '',
			],
			'read_cover'              => [
				'type'        => 'string',
				'description' => __( 'Book cover image URL.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'read_status'             => [
				'type'        => 'string',
				'description' => __( 'Reading status: to-read, reading, finished, or abandoned.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_read_status' ],
				'default'     => 'reading',
				'enum'        => [ 'to-read', 'reading', 'finished', 'abandoned' ],
			],
			'read_progress'           => [
				'type'        => 'number',
				'description' => __( 'Reading progress (percentage or page number).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'read_pages'              => [
				'type'        => 'number',
				'description' => __( 'Total number of pages.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'read_url'                => [
				'type'        => 'string',
				'description' => __( 'URL to the book or article.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'read_publisher'          => [
				'type'        => 'string',
				'description' => __( 'Publisher name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_publish_date'       => [
				'type'        => 'string',
				'description' => __( 'Publication date.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_asin'               => [
				'type'        => 'string',
				'description' => __( 'Amazon Standard Identification Number.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_rating'             => [
				'type'        => 'integer',
				'description' => __( 'Rating for the book (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'read_started_at'         => [
				'type'        => 'string',
				'description' => __( 'Date started reading.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_finished_at'        => [
				'type'        => 'string',
				'description' => __( 'Date finished reading.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_review'             => [
				'type'        => 'string',
				'description' => __( 'Review or notes about the book.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'wp_kses_post',
				'default'     => '',
			],

			// Comic Fields: a comic someone read (comic-card). A comics post with
			// no card, a strip its author drew, stores none of these.
			'comic_title'             => [
				'type'        => 'string',
				'description' => __( 'Title of the comic.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_creators'          => [
				'type'        => 'string',
				'description' => __( 'Writers and artists of the comic, as authored text.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_series'            => [
				'type'        => 'string',
				'description' => __( 'Series the comic belongs to.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_volume'            => [
				'type'        => 'string',
				'description' => __( 'Volume of the series.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_issue'             => [
				'type'        => 'string',
				'description' => __( 'Issue number within the series or volume.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_publisher'         => [
				'type'        => 'string',
				'description' => __( 'Publisher of the comic.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_cover'             => [
				'type'        => 'string',
				'description' => __( 'Comic cover image URL.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'comic_cover_alt'         => [
				'type'        => 'string',
				'description' => __( 'Alt text stored for the comic cover.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_url'               => [
				'type'        => 'string',
				'description' => __( 'URL where the comic can be found or read.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'comic_status'            => [
				'type'        => 'string',
				'description' => __( 'Reading status: to-read, reading, finished, or abandoned. Empty when the post has no comic card.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_comic_status' ],
				'default'     => '',
				'enum'        => [ '', 'to-read', 'reading', 'finished', 'abandoned' ],
			],
			'comic_rating'            => [
				'type'        => 'integer',
				'description' => __( 'Rating for the comic (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'comic_started_at'        => [
				'type'        => 'string',
				'description' => __( 'Date started reading the comic.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_finished_at'       => [
				'type'        => 'string',
				'description' => __( 'Date finished or set aside the comic.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'comic_review'            => [
				'type'        => 'string',
				'description' => __( 'Review or notes about the comic.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'wp_kses_post',
				'default'     => '',
			],

			// Event Fields.
			'event_start'             => [
				'type'        => 'string',
				'description' => __( 'Event start datetime (ISO 8601).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'event_end'               => [
				'type'        => 'string',
				'description' => __( 'Event end datetime (ISO 8601).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'event_location'          => [
				'type'        => 'string',
				'description' => __( 'Event location name or address.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'event_url'               => [
				'type'        => 'string',
				'description' => __( 'Event URL or registration link.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Review Fields.
			'review_rating'           => [
				'type'        => 'number',
				'description' => __( 'Rating value (supports decimals like 3.5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'review_best'             => [
				'type'        => 'number',
				'description' => __( 'Best possible rating (typically 5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'absint',
				'default'     => 5,
			],
			'review_item_name'        => [
				'type'        => 'string',
				'description' => __( 'Name of the reviewed item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'review_item_url'         => [
				'type'        => 'string',
				'description' => __( 'URL of the reviewed item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Recipe Fields.
			'recipe_yield'            => [
				'type'        => 'string',
				'description' => __( 'Recipe yield (e.g., "4 servings").', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'recipe_duration'         => [
				'type'        => 'string',
				'description' => __( 'Total recipe duration (ISO 8601 duration).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Favorite Fields.
			'favorite_name'           => [
				'type'        => 'string',
				'description' => __( 'Name of the favorited item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'favorite_url'            => [
				'type'        => 'string',
				'description' => __( 'URL of the favorited item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'favorite_rating'         => [
				'type'        => 'number',
				'description' => __( 'Rating for the favorited item (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],

			// Jam Fields (extends listen with "highlight" flag).
			'jam_track'               => [
				'type'        => 'string',
				'description' => __( 'Track name for jam.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'jam_artist'              => [
				'type'        => 'string',
				'description' => __( 'Artist name for jam.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'jam_album'               => [
				'type'        => 'string',
				'description' => __( 'Album name for jam.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'jam_cover'               => [
				'type'        => 'string',
				'description' => __( 'Cover art URL for jam.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'jam_url'                 => [
				'type'        => 'string',
				'description' => __( 'URL to the track.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Wish Fields.
			'wish_name'               => [
				'type'        => 'string',
				'description' => __( 'Name of the wished item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'wish_url'                => [
				'type'        => 'string',
				'description' => __( 'URL of the wished item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'wish_photo'              => [
				'type'        => 'string',
				'description' => __( 'Image URL of the wished item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'wish_type'               => [
				'type'        => 'string',
				'description' => __( 'Type of wish: book, movie, product, experience, etc.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'wish_priority'           => [
				'type'        => 'string',
				'description' => __( 'Priority level: low, medium, high.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_priority' ],
				'default'     => 'medium',
				'enum'        => [ 'low', 'medium', 'high' ],
			],

			// Mood Fields.
			'mood_emoji'              => [
				'type'        => 'string',
				'description' => __( 'Emoji representing the mood.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'mood_label'              => [
				'type'        => 'string',
				'description' => __( 'Text label for the mood (e.g., happy, tired, excited).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'mood_key'                => [
				'type'        => 'string',
				'description' => __( 'Mood identity from the plugin mood vocabulary (e.g., energized); empty for a label typed by hand.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_key',
				'default'     => '',
			],
			'mood_rating'             => [
				'type'        => 'number',
				'description' => __( 'Mood rating on 1-5 scale.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_mood_rating' ],
				'default'     => 0,
			],

			// Acquisition Fields.
			'acquisition_name'        => [
				'type'        => 'string',
				'description' => __( 'Name of the acquired item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'acquisition_url'         => [
				'type'        => 'string',
				'description' => __( 'URL of the acquired item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'acquisition_photo'       => [
				'type'        => 'string',
				'description' => __( 'Image URL of the acquired item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'acquisition_price'       => [
				'type'        => 'string',
				'description' => __( 'Price paid for the item.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'acquisition_rating'      => [
				'type'        => 'number',
				'description' => __( 'Rating for the acquired item (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],

			// Drink Fields.
			'drink_name'              => [
				'type'        => 'string',
				'description' => __( 'Name of the beverage.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_type'              => [
				'type'        => 'string',
				'description' => __( 'Type of drink: coffee, beer, wine, cocktail, tea, etc.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_brewery'           => [
				'type'        => 'string',
				'description' => __( 'Brewery, winery, or producer name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_photo'             => [
				'type'        => 'string',
				'description' => __( 'Photo URL of the drink.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'drink_rating'            => [
				'type'        => 'number',
				'description' => __( 'Rating for the drink (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'drink_location_name'     => [
				'type'        => 'string',
				'description' => __( 'Bar, cafe, or venue name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_address'  => [
				'type'        => 'string',
				'description' => __( 'Street address of the venue.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_locality' => [
				'type'        => 'string',
				'description' => __( 'City or locality.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_region'   => [
				'type'        => 'string',
				'description' => __( 'State, province, or region.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_country'  => [
				'type'        => 'string',
				'description' => __( 'Country name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_geo_latitude'      => [
				'type'        => 'number',
				'description' => __( 'Geographic latitude of the venue.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'drink_geo_longitude'     => [
				'type'        => 'number',
				'description' => __( 'Geographic longitude of the venue.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'drink_notes'             => [
				'type'        => 'string',
				'description' => __( 'Tasting notes for the drink.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'drink_drank_at'          => [
				'type'        => 'string',
				'description' => __( 'When the drink was had.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_venue_url'         => [
				'type'        => 'string',
				'description' => __( 'URL for the venue website.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Eat Fields.
			'eat_name'                => [
				'type'        => 'string',
				'description' => __( 'Name of the meal or dish.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_type'                => [
				'type'        => 'string',
				'description' => __( 'Type of meal: breakfast, lunch, dinner, snack.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_restaurant'          => [
				'type'        => 'string',
				'description' => __( 'Restaurant or venue name (deprecated, use eat_location_name).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_photo'               => [
				'type'        => 'string',
				'description' => __( 'Photo URL of the meal.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'eat_rating'              => [
				'type'        => 'number',
				'description' => __( 'Rating for the meal (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'eat_location_name'       => [
				'type'        => 'string',
				'description' => __( 'Restaurant or venue name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_address'    => [
				'type'        => 'string',
				'description' => __( 'Street address of the venue.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_locality'   => [
				'type'        => 'string',
				'description' => __( 'City or locality.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_region'     => [
				'type'        => 'string',
				'description' => __( 'State, province, or region.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_country'    => [
				'type'        => 'string',
				'description' => __( 'Country name.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_geo_latitude'        => [
				'type'        => 'number',
				'description' => __( 'Geographic latitude of the venue.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'eat_geo_longitude'       => [
				'type'        => 'number',
				'description' => __( 'Geographic longitude of the venue.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'eat_cuisine'             => [
				'type'        => 'string',
				'description' => __( 'Cuisine type (american, italian, etc.).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_notes'               => [
				'type'        => 'string',
				'description' => __( 'Notes about the meal.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'eat_ate_at'              => [
				'type'        => 'string',
				'description' => __( 'When the meal was eaten.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_restaurant_url'      => [
				'type'        => 'string',
				'description' => __( 'Restaurant website URL.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Play Fields (gaming).
			'play_title'              => [
				'type'        => 'string',
				'description' => __( 'Game title.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_platform'           => [
				'type'        => 'string',
				'description' => __( 'Platform: PC, PlayStation, Xbox, Nintendo, Mobile, Board Game, etc.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_status'             => [
				'type'        => 'string',
				'description' => __( 'Play status: playing, completed, abandoned, backlog.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_play_status' ],
				'default'     => 'playing',
				'enum'        => [ 'playing', 'completed', 'abandoned', 'backlog', 'wishlist' ],
			],
			'play_hours'              => [
				'type'        => 'number',
				'description' => __( 'Hours played.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_float' ],
				'default'     => 0,
			],
			'play_cover'              => [
				'type'        => 'string',
				'description' => __( 'Game cover art URL.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'play_igdb_id'            => [
				'type'        => 'string',
				'description' => __( 'IGDB game ID.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_steam_id'           => [
				'type'        => 'string',
				'description' => __( 'Steam app ID.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_bgg_id'             => [
				'type'        => 'string',
				'description' => __( 'BoardGameGeek game ID.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_rawg_id'            => [
				'type'        => 'string',
				'description' => __( 'RAWG.io game ID.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_rating'             => [
				'type'        => 'number',
				'description' => __( 'Rating for the game (0-5).', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'play_official_url'       => [
				'type'        => 'string',
				'description' => __( 'Official website URL for the game.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'play_purchase_url'       => [
				'type'        => 'string',
				'description' => __( 'Purchase URL for the game.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'play_review'             => [
				'type'        => 'string',
				'description' => __( 'Review or notes about the game.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'play_game_url'           => [
				'type'        => 'string',
				'description' => __( 'URL to the game page.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Syndication Opt-Out Fields.
			'syndicate_lastfm'        => [
				'type'        => 'boolean',
				'description' => __( 'Whether to syndicate to Last.fm.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => true,
			],
			'syndicate_trakt'         => [
				'type'        => 'boolean',
				'description' => __( 'Whether to syndicate to Trakt.', 'post-kinds-for-indieweb-in-block-themes' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => true,
			],
		];

		/**
		 * Filters the meta field definitions.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $fields Meta field definitions.
		 */
		$this->fields = apply_filters( 'pkiw_meta_fields', $this->fields );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		// Issue 239: acquisition cost leaves REST unless the post shows it publicly.
		add_filter( 'rest_prepare_post', [ $this, 'redact_cost_meta' ], 20, 3 );
		add_filter( 'rest_prepare_' . Post_Type::POST_TYPE, [ $this, 'redact_cost_meta' ], 20, 3 );
		// Code that reads post_content before render.php runs: display reads
		// (ActivityPub's summary) and search.
		add_filter( 'post_content', [ self::class, 'display_content_without_private_cost' ], 10, 2 );
		add_filter( 'posts_search', [ self::class, 'search_without_private_cost' ], 10, 2 );
		// R-03: location detail leaves the REST response unless the post's
		// location privacy is public or the requester can edit the post.
		add_filter( 'rest_prepare_post', [ $this, 'redact_location_meta' ], 20, 3 );
		// CPT import storage registers the same meta on the reaction post type.
		add_filter( 'rest_prepare_' . Post_Type::POST_TYPE, [ $this, 'redact_location_meta' ], 20, 3 );
		add_action( 'updated_post_meta', [ $this, 'clear_brand_source_on_rebrand' ], 10, 3 );
	}

	/**
	 * Register all meta fields with WordPress.
	 *
	 * @return void
	 */
	/**
	 * Meta keys (without prefix) that carry a precise location.
	 *
	 * @var string[]
	 */
	public const LOCATION_KEYS = [
		'checkin_url',
		'checkin_osm_id',
		'checkin_address',
		'geo_latitude',
		'geo_longitude',
		'drink_location_address',
		'drink_geo_latitude',
		'drink_geo_longitude',
		'drink_venue_url',
		'eat_location_address',
		'eat_geo_latitude',
		'eat_geo_longitude',
		'eat_restaurant_url',
	];

	/**
	 * Registered location meta keys (without prefix), mapped to the
	 * visibility tier that gates them — the tier names returned by
	 * get_visible_location_fields(). Drives redact_location_meta() so a
	 * key can't leak by being missing from a hand-rolled list.
	 *
	 * There is no field literally named "venue_id" registered anywhere in
	 * this plugin (grepped); the Foursquare place ID carried in the
	 * checkin card's `foursquareId` attribute is the closest analog to a
	 * generic venue identifier distinct from the OpenStreetMap id, so the
	 * checkin card gates it on the 'venue_id' tier. The Foursquare importers
	 * persist the venue ID to `_pkiw_checkin_venue_id`, and
	 * `_pkiw_checkin_foursquare_id` holds the check-in ID. Neither is
	 * registered meta, so neither reaches REST or the abilities, and no key
	 * maps here.
	 *
	 * @var array<string, string>
	 */
	private const LOCATION_KEY_TIERS = [
		'checkin_name'            => 'name',
		'checkin_locality'        => 'locality',
		'checkin_region'          => 'region',
		'checkin_country'         => 'country',
		'checkin_address'         => 'street',
		'checkin_url'             => 'url',
		'checkin_osm_id'          => 'osm_id',
		'geo_latitude'            => 'coordinates',
		'geo_longitude'           => 'coordinates',
		'drink_location_name'     => 'name',
		'drink_location_locality' => 'locality',
		'drink_location_region'   => 'region',
		'drink_location_country'  => 'country',
		'drink_location_address'  => 'street',
		'drink_venue_url'         => 'url',
		'drink_geo_latitude'      => 'coordinates',
		'drink_geo_longitude'     => 'coordinates',
		'eat_restaurant'          => 'name',
		'eat_location_name'       => 'name',
		'eat_location_locality'   => 'locality',
		'eat_location_region'     => 'region',
		'eat_location_country'    => 'country',
		'eat_location_address'    => 'street',
		'eat_restaurant_url'      => 'url',
		'eat_geo_latitude'        => 'coordinates',
		'eat_geo_longitude'       => 'coordinates',
	];

	/**
	 * Native Simple Location / IndieBlocks meta keys (unprefixed, no
	 * self::PREFIX), mapped to the same visibility tiers as
	 * LOCATION_KEY_TIERS. Both integrations store the same shape of data
	 * under their own keys instead of this plugin's `_pkiw_*` fields, so
	 * they are gated by the identical tier rule rather than a separate
	 * geo_public-only check.
	 *
	 * @var array<string, string>
	 */
	private const NATIVE_LOCATION_KEY_TIERS = [
		'geo_latitude'       => 'coordinates',
		'geo_longitude'      => 'coordinates',
		'geo_altitude'       => 'coordinates',
		'geo_address'        => 'street',
		'geo_street_address' => 'street',
		'geo_postal_code'    => 'postal_code',
		'geo_venue'          => 'name',
		'geo_locality'       => 'locality',
		'geo_region'         => 'region',
		'geo_country_name'   => 'country',
	];

	/**
	 * Whether a post has an identifiable venue: a check-in (by kind), or any
	 * post carrying venue-identity data from Post Kinds' own fields, the
	 * venue taxonomy, or Simple Location. Checked against stored data only
	 * — never against geo_privacy/geo_public, which govern *visibility*,
	 * not venue-ness.
	 *
	 * Keys checked (all confirmed present in this codebase):
	 * - `_pkiw_checkin_name`, `_pkiw_eat_restaurant`, `_pkiw_eat_location_name`,
	 *   `_pkiw_drink_location_name` — Post Kinds' own venue-name fields.
	 * - `_pkiw_checkin_venue_id` — the stored Foursquare venue id.
	 * - the `pkiw_venue` taxonomy (Venue_Taxonomy::TAXONOMY) term assignment.
	 * - Simple Location's `geo_venue` (venue name) meta key.
	 * - `geo_venue_id`, checked defensively as Simple Location's documented
	 *   convention for a venue-post association; not otherwise referenced
	 *   in this codebase, so treat this one key as an assumption to revisit
	 *   if it doesn't match the installed Simple Location version.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_venue( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( has_term( 'checkin', Taxonomy::TAXONOMY, $post_id ) ) {
			return true;
		}

		foreach ( [ 'checkin_name', 'eat_restaurant', 'eat_location_name', 'drink_location_name' ] as $key ) {
			if ( '' !== (string) get_post_meta( $post_id, self::PREFIX . $key, true ) ) {
				return true;
			}
		}

		if ( '' !== (string) get_post_meta( $post_id, self::PREFIX . 'checkin_venue_id', true ) ) {
			return true;
		}

		if ( has_term( '', Venue_Taxonomy::TAXONOMY, $post_id ) ) {
			return true;
		}

		foreach ( [ 'geo_venue', 'geo_venue_id' ] as $key ) {
			if ( '' !== (string) get_post_meta( $post_id, $key, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Which location fields the current user may see for a post, given its
	 * geo_privacy state. The one place this rule lives — cards, block
	 * bindings, REST meta redaction (both `_pkiw_*` and native Simple
	 * Location / IndieBlocks keys), the /checkins routes, the dashboard,
	 * and the theme all call this.
	 *
	 * One rule for every post, venue posts included (issue 224):
	 *
	 * - `_pkiw_geo_privacy` 'private' or Simple Location `geo_public` '0':
	 *   nothing.
	 * - 'public': everything.
	 * - 'approximate', which is also what an unset value means: the venue
	 *   name, locality, region and country. No street, postal code,
	 *   coordinates, map, venue URL or venue ids.
	 * - Simple Location `geo_public` '2' (Protected) narrows the result to
	 *   text: no coordinates, map or ids. The stricter of the two systems
	 *   wins per field. An empty or unrecognized `geo_public` means Simple
	 *   Location was never used on the post and restricts nothing, same as
	 *   '1' (explicit public).
	 *
	 * Until issue 224 a venue post (a check-in, or a post with a restaurant or
	 * venue name) showed everything unless it was private, so an
	 * approximate check-in printed its street, coordinates and map.
	 *
	 * @param int $post_id Post ID.
	 * @return array{name:bool,locality:bool,region:bool,country:bool,street:bool,postal_code:bool,coordinates:bool,map:bool,url:bool,osm_id:bool,venue_id:bool}
	 */
	public static function get_visible_location_fields( int $post_id ): array {
		if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
			return array_fill_keys( array_keys( self::get_public_location_fields( 0 ) ), true );
		}

		return self::get_public_location_fields( $post_id );
	}

	/**
	 * Which location fields a visitor who can't edit the post may see.
	 *
	 * The same rule as get_visible_location_fields(), without the editor
	 * override. Use it for output that leaves the request it was built in: feeds, federated
	 * records, and titles.
	 *
	 * @param int $post_id Post ID.
	 * @return array{name:bool,locality:bool,region:bool,country:bool,street:bool,postal_code:bool,coordinates:bool,map:bool,url:bool,osm_id:bool,venue_id:bool}
	 */
	public static function get_public_location_fields( int $post_id ): array {
		$all_visible  = [
			'name'        => true,
			'locality'    => true,
			'region'      => true,
			'country'     => true,
			'street'      => true,
			'postal_code' => true,
			'coordinates' => true,
			'map'         => true,
			'url'         => true,
			'osm_id'      => true,
			'venue_id'    => true,
		];
		$none_visible = array_fill_keys( array_keys( $all_visible ), false );

		if ( $post_id <= 0 ) {
			return $none_visible;
		}

		$privacy    = (string) get_post_meta( $post_id, self::PREFIX . 'geo_privacy', true );
		$geo_public = (string) get_post_meta( $post_id, 'geo_public', true );

		// Explicit private, from either system, wins outright.
		if ( 'private' === $privacy || '0' === $geo_public ) {
			return $none_visible;
		}

		// Simple Location's Protected (text only, no coordinates/map/ids).
		$text_only_visible = [
			'name'        => true,
			'locality'    => true,
			'region'      => true,
			'country'     => true,
			'street'      => true,
			'postal_code' => true,
			'coordinates' => false,
			'map'         => false,
			'url'         => true,
			'osm_id'      => false,
			'venue_id'    => false,
		];

		// The Post Kinds tier ('approximate' or unset falls back to
		// 'approximate', as sanitize_geo_privacy() and the field's own
		// registration do) combined with Simple Location's geo_public; the
		// stricter of the two wins per field.
		$pkiw_tier = 'public' === $privacy
			? $all_visible
			: [
				'name'        => true,
				'locality'    => true,
				'region'      => true,
				'country'     => true,
				'street'      => false,
				'postal_code' => false,
				'coordinates' => false,
				'map'         => false,
				'url'         => false,
				'osm_id'      => false,
				'venue_id'    => false,
			];

		// '0' is handled by the early return above. '2' (Protected) is the
		// only value that narrows visibility here: an empty geo_public
		// means Simple Location was never used on this post and must not
		// restrict anything, and '1' is explicit public — both leave the
		// Post Kinds tier as the only restriction.
		$sl_tier = '2' === $geo_public ? $text_only_visible : $all_visible;

		$visible = [];
		foreach ( $all_visible as $key => $_true ) {
			$visible[ $key ] = $pkiw_tier[ $key ] && $sl_tier[ $key ];
		}
		return $visible;
	}

	/**
	 * Whether precise location detail may be shown for a post to the current requester.
	 *
	 * Kept for existing call sites (functions-checkin.php,
	 * class-block-bindings.php); its meaning — "precise location visible"
	 * — is exactly the 'coordinates' tier of get_visible_location_fields().
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function location_visible( int $post_id ): bool {
		return self::get_visible_location_fields( $post_id )['coordinates'];
	}

	/**
	 * Post meta naming where a drink's brand came from. Left unregistered,
	 * like `_pkiw_title_source`, so REST doesn't publish it.
	 */
	public const BRAND_SOURCE_KEY = '_pkiw_drink_brand_source';

	/**
	 * Brand source value: the brand was filled from venue or Simple Location data.
	 */
	public const BRAND_SOURCE_LOCATION = 'location';

	/**
	 * Record that a drink's brand was filled from venue or Simple Location
	 * data. Any writer that copies a venue name into the brand calls this
	 * right after it saves the brand.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function mark_location_brand( int $post_id ): void {
		update_post_meta( $post_id, self::BRAND_SOURCE_KEY, self::BRAND_SOURCE_LOCATION );
	}

	/**
	 * Clear the brand source marker when the stored brand changes, so a
	 * brand the author edits after it was filled from venue data is theirs
	 * and prints. update_metadata() fires no hook when the value is
	 * unchanged, so Card_Meta_Sync's resave of the same brand keeps the
	 * marker. Only updated_post_meta runs this: the first add of the brand
	 * can come after the marker, as when a writer marks the post through
	 * meta_input and Card_Meta_Sync stores the brand at save_post.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 */
	public function clear_brand_source_on_rebrand( $meta_id, $post_id, $meta_key ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( self::PREFIX . 'drink_brewery' === $meta_key ) {
			delete_post_meta( (int) $post_id, self::BRAND_SOURCE_KEY );
		}
	}

	/**
	 * Whether the current user may see a drink's brand.
	 *
	 * A brand with no recorded source was typed by the author and always
	 * shows. One marked by mark_location_brand() names the venue, so it
	 * follows the venue name's tier of get_visible_location_fields(): hidden
	 * from visitors when `_pkiw_geo_privacy` is 'private' or Simple Location
	 * `geo_public` is '0'. The marker decides, never a comparison of the
	 * brand with the venue name.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function drink_brand_visible( int $post_id ): bool {
		if ( self::BRAND_SOURCE_LOCATION !== get_post_meta( $post_id, self::BRAND_SOURCE_KEY, true ) ) {
			return true;
		}

		return self::get_visible_location_fields( $post_id )['name'];
	}

	/**
	 * How many synced patterns deep has_rsvp_card() looks for an RSVP card.
	 *
	 * @var int
	 */
	private const SYNCED_PATTERN_MAX_DEPTH = 10;

	/**
	 * Whether an RSVP's event location may print (issue 251).
	 *
	 * Only when `_pkiw_rsvp_location_privacy` is 'public', for every request
	 * and every viewer. An unset value is private, so RSVPs saved before the
	 * setting existed keep their location to themselves. An explicit private
	 * location under the shared rule (`_pkiw_geo_privacy` 'private' or
	 * Simple Location `geo_public` '0') still wins. Editors get no front-end
	 * exception, because a plugin that caches rendered content (Markdown
	 * Alternate's md_alt_cache_{ID}) can serve their render to visitors; they
	 * see the location in the block editor and in REST meta.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function rsvp_location_visible( int $post_id ): bool {
		if ( $post_id <= 0 || 'public' !== get_post_meta( $post_id, self::PREFIX . 'rsvp_location_privacy', true ) ) {
			return false;
		}

		return self::get_public_location_fields( $post_id )['name'];
	}

	/**
	 * Whether `_pkiw_event_location` may go to someone who can't edit the
	 * post (issue 251). An event post announces its own location, so it
	 * shows, unless the post is really an RSVP (is_rsvp()): an RSVP set to
	 * Event keeps its RSVP rows. Every other post shows it only when
	 * rsvp_location_visible() says so.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function event_location_visible( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( has_term( 'event', Taxonomy::TAXONOMY, $post_id ) && ! self::is_rsvp( $post_id ) ) {
			return true;
		}

		return self::rsvp_location_visible( $post_id );
	}

	/**
	 * Whether a post has the `rsvp` term, an RSVP card or a stored RSVP row:
	 * the card's `_pkiw_rsvp_location_privacy`, the editor sidebar's
	 * `_pkiw_rsvp_status` or the `_pkiw_rsvp_value` Quick Post and the RSVP
	 * meta box write. The card counts on its own, because a card saved
	 * before the privacy row existed has no row and nothing backfills one,
	 * and a card in a synced pattern never gets one (has_rsvp_card()).
	 * Reads raw rows, because get_post_meta() returns the registered
	 * 'private' default for a post with no privacy row.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_rsvp( int $post_id ): bool {
		if ( has_term( 'rsvp', Taxonomy::TAXONOMY, $post_id ) ) {
			return true;
		}

		$seen = [];
		if ( self::has_rsvp_card( (string) get_post_field( 'post_content', $post_id ), $seen, 0 ) ) {
			return true;
		}

		foreach ( [ 'rsvp_location_privacy', 'rsvp_status', 'rsvp_value' ] as $suffix ) {
			if ( metadata_exists( 'post', $post_id, self::PREFIX . $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether content holds an RSVP card, itself or through the synced
	 * patterns (`core/block` refs) it places, followed into each referenced
	 * wp_block whatever its status. Card_Meta_Sync and has_block() read only
	 * the post's own blocks, so a card in a pattern leaves no RSVP row. A
	 * pattern already walked is skipped. Nesting deeper than
	 * SYNCED_PATTERN_MAX_DEPTH counts as an RSVP, because core renders
	 * patterns at any depth and the location fails closed.
	 *
	 * @param string           $content Block content.
	 * @param array<int, true> $seen    Pattern IDs already walked.
	 * @param int              $depth   Patterns between the post and $content.
	 * @return bool
	 */
	private static function has_rsvp_card( string $content, array &$seen, int $depth ): bool {
		if ( has_block( 'post-kinds-indieweb/rsvp-card', $content ) ) {
			return true;
		}
		if ( ! has_block( 'core/block', $content ) ) {
			return false;
		}
		if ( $depth >= self::SYNCED_PATTERN_MAX_DEPTH ) {
			return true;
		}

		foreach ( self::pattern_refs( parse_blocks( $content ) ) as $ref ) {
			if ( isset( $seen[ $ref ] ) ) {
				continue;
			}
			$seen[ $ref ] = true;
			$pattern      = get_post( $ref );
			if ( $pattern instanceof \WP_Post && 'wp_block' === $pattern->post_type
				&& self::has_rsvp_card( $pattern->post_content, $seen, $depth + 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The `ref` of every `core/block` in parsed blocks, nested ones included.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return int[]
	 */
	private static function pattern_refs( array $blocks ): array {
		$refs = [];
		foreach ( $blocks as $block ) {
			$ref = (int) ( $block['attrs']['ref'] ?? 0 );
			if ( 'core/block' === ( $block['blockName'] ?? '' ) && $ref > 0 ) {
				$refs[] = $ref;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$refs = array_merge( $refs, self::pattern_refs( $block['innerBlocks'] ) );
			}
		}

		return $refs;
	}

	/**
	 * Zero/blank out this plugin's own `_pkiw_*` location fields the
	 * post's current visibility tier hides, leaving every other key in
	 * $meta untouched. This is the LOCATION_KEY_TIERS walk shared by
	 * redact_location_meta() (REST) and the post-kinds/get-post-meta
	 * ability, so a key can't stay redacted on one path while leaking on
	 * the other.
	 *
	 * @param array<string, mixed> $meta    Meta values keyed by the full `_pkiw_`-prefixed field name.
	 * @param int                  $post_id Post the meta belongs to.
	 * @return array<string, mixed> $meta with hidden fields zeroed (numeric) or blanked (string).
	 */
	public static function redact_location_array( array $meta, int $post_id ): array {
		$visible = self::get_visible_location_fields( $post_id );

		// An RSVP's event location goes only to its editors unless it's public (issue 251).
		$event_location = self::PREFIX . 'event_location';
		if ( array_key_exists( $event_location, $meta ) && ! current_user_can( 'edit_post', $post_id )
			&& ! self::event_location_visible( $post_id ) ) {
			$meta[ $event_location ] = '';
		}

		foreach ( self::LOCATION_KEY_TIERS as $key => $tier ) {
			if ( ! empty( $visible[ $tier ] ) ) {
				continue;
			}
			$full = self::PREFIX . $key;
			if ( array_key_exists( $full, $meta ) ) {
				$meta[ $full ] = is_numeric( $meta[ $full ] ) ? 0 : '';
			}
		}

		if ( array_key_exists( self::PREFIX . 'drink_brewery', $meta ) && ! self::drink_brand_visible( $post_id ) ) {
			$meta[ self::PREFIX . 'drink_brewery' ] = '';
		}

		return $meta;
	}

	/**
	 * Strip precise location meta from REST responses the requester may not see.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function redact_location_meta( $response, $post, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}
		if ( current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $response;
		}

		$data    = $response->get_data();
		$changed = false;
		$visible = self::get_visible_location_fields( (int) $post->ID );

		if ( ! empty( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$redacted = self::redact_location_array( $data['meta'], (int) $post->ID );
			if ( $redacted !== $data['meta'] ) {
				$changed = true;
			}
			$data['meta'] = $redacted;

			// Simple Location / IndieBlocks carry the same location data
			// under their own (unprefixed) keys; gate them by the same
			// per-field tiers get_visible_location_fields() already
			// resolved above, instead of a separate geo_public-only check.
			// Stored data is untouched.
			foreach ( self::NATIVE_LOCATION_KEY_TIERS as $key => $tier ) {
				if ( ! empty( $visible[ $tier ] ) ) {
					continue;
				}
				if ( array_key_exists( $key, $data['meta'] ) && '' !== (string) $data['meta'][ $key ] ) {
					$data['meta'][ $key ] = '';
					$changed              = true;
				}
			}
		}

		if ( ! empty( $data['indieblocks_location'] ) && is_array( $data['indieblocks_location'] ) ) {
			foreach ( array_keys( $data['indieblocks_location'] ) as $key ) {
				// Match the same key-to-tier mapping used for the native
				// meta keys above; an unrecognized key name falls back to a
				// coordinate-pattern guess, then the most conservative
				// ("street") tier so an unknown field defaults to hidden
				// under the approximate/protected tiers.
				$tier = self::NATIVE_LOCATION_KEY_TIERS[ $key ]
					?? ( preg_match( '/lat|lon|geo_(?!address)/', (string) $key ) ? 'coordinates' : 'street' );
				if ( empty( $visible[ $tier ] ) ) {
					$data['indieblocks_location'][ $key ] = '';
					$changed                              = true;
				}
			}
		}

		if ( $changed ) {
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Post meta holding an acquisition's "Show cost publicly" toggle: '1'
	 * when on, no row when off. Card_Meta_Sync is its only writer. Left
	 * unregistered, like `_pkiw_title_source`, so the block editor never
	 * sends a stale copy back over the card's value on save.
	 */
	public const COST_PUBLIC_KEY = '_pkiw_acquisition_cost_public';

	/**
	 * Meta keys (without prefix) that hold an acquisition's cost.
	 *
	 * @var string[]
	 */
	private const COST_KEYS = [ 'acquisition_price' ];

	/**
	 * Whether an acquisition's cost may print publicly (issue 239).
	 *
	 * Cost is private by default and public only when the post's acquisition
	 * card has "Show cost publicly" on. There's no editor override: the card,
	 * feeds, ActivityPub and ATmosphere all build from one render, which can
	 * run in the author's own request. Editors see cost in the block editor
	 * and in REST. Themes call this before printing cost anywhere.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function acquisition_cost_visible( int $post_id ): bool {
		return $post_id > 0 && '1' === (string) get_post_meta( $post_id, self::COST_PUBLIC_KEY, true );
	}

	/**
	 * Blank this plugin's cost meta in $meta unless the requester may see it:
	 * they can edit the post, or acquisition_cost_visible() says public.
	 * Shared by redact_cost_meta() (REST) and the post-kinds/get-post-meta
	 * ability.
	 *
	 * @param array<string, mixed> $meta    Meta values keyed by the full `_pkiw_`-prefixed field name.
	 * @param int                  $post_id Post the meta belongs to.
	 * @return array<string, mixed>
	 */
	public static function redact_cost_array( array $meta, int $post_id ): array {
		if ( current_user_can( 'edit_post', $post_id ) || self::acquisition_cost_visible( $post_id ) ) {
			return $meta;
		}

		foreach ( self::COST_KEYS as $key ) {
			if ( array_key_exists( self::PREFIX . $key, $meta ) ) {
				$meta[ self::PREFIX . $key ] = '';
			}
		}

		return $meta;
	}

	/**
	 * Strip acquisition cost from REST responses when it isn't public and the
	 * requester can't edit the post. Stored data is untouched.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response
	 */
	public function redact_cost_meta( $response, $post, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}

		$data = $response->get_data();
		if ( empty( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}

		$redacted = self::redact_cost_array( $data['meta'], (int) $post->ID );
		if ( $redacted !== $data['meta'] ) {
			$data['meta'] = $redacted;
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * An acquisition card in post_content: a bare block comment, or the
	 * comment pair around the static HTML save.js stored. The attrs part
	 * is core's pattern from WP_Block_Parser::next_token().
	 */
	private const ACQUISITION_CARD_PATTERN = '#(?P<head><!--\s+wp:post-kinds-indieweb/acquisition-card\s+)(?P<attrs>\{(?:(?:[^}]+|\}+(?=\})|(?!\}\s+/?-->).)*+)?\}\s+)?(?P<tail>/-->|-->(?P<inner>.*?)<!--\s+/wp:post-kinds-indieweb/acquisition-card\s+-->)#s';

	/**
	 * The columns WP_Query search can match.
	 *
	 * @var string[]
	 */
	private const SEARCH_COLUMNS = [ 'post_title', 'post_excerpt', 'post_content' ];

	/**
	 * Whether one acquisition card's cost is private, by the rule render.php
	 * follows: public only when the card's showCostPublicly is on and the
	 * post shows cost.
	 *
	 * @param string|null $attrs       The card's block comment attributes, JSON.
	 * @param bool        $post_public acquisition_cost_visible() for the post.
	 * @return bool
	 */
	private static function card_cost_is_private( ?string $attrs, bool $post_public ): bool {
		$decoded = json_decode( (string) $attrs, true );

		return ! ( $post_public && is_array( $decoded ) && true === ( $decoded['showCostPublicly'] ?? null ) );
	}

	/**
	 * $content with each private acquisition cost taken out (issue 239).
	 *
	 * For every card whose cost is private this drops the paragraph that
	 * save.js printed cost into before issue 239: any `<p>` in the card's
	 * static HTML whose text is the card's cost, which covers
	 * `post-kinds-card__subtitle` and the earlier `acquisition-cost` and
	 * `reactions-card__subtitle`, and the subtitle itself whatever it
	 * holds. With $attributes it also blanks the `cost` attribute in the
	 * card's block comment, which search needs. Display callers leave the
	 * attribute alone, so anything that writes their result back keeps the
	 * stored cost.
	 *
	 * @param string $content    Post content.
	 * @param int    $post_id    Post the content belongs to.
	 * @param bool   $attributes Whether to blank the comment's `cost` attribute too.
	 * @return string
	 */
	public static function strip_private_cost( string $content, int $post_id, bool $attributes = false ): string {
		if ( ! str_contains( $content, 'wp:post-kinds-indieweb/acquisition-card' ) ) {
			return $content;
		}

		$post_public = self::acquisition_cost_visible( $post_id );
		$stripped    = preg_replace_callback(
			self::ACQUISITION_CARD_PATTERN,
			static function ( array $card ) use ( $post_public, $attributes ): string {
				if ( ! self::card_cost_is_private( $card['attrs'], $post_public ) ) {
					return $card[0];
				}

				$attrs = (string) $card['attrs'];
				if ( $attributes ) {
					$attrs = (string) preg_replace( '/"cost":"(?:[^"\\\\]|\\\\.)*"/', '"cost":""', $attrs );
				}

				$tail = (string) $card['tail'];
				if ( null !== $card['inner'] ) {
					$cost  = self::card_cost( $card['attrs'] );
					$inner = (string) preg_replace_callback(
						'#<p\b[^>]*>(.*?)</p>#s',
						static function ( array $paragraph ) use ( $cost ): string {
							$is_cost = str_starts_with( $paragraph[0], '<p class="post-kinds-card__subtitle">' )
								|| ( '' !== $cost && self::plain_text( $paragraph[1] ) === $cost );

							return $is_cost ? '' : $paragraph[0];
						},
						$card['inner']
					);
					$tail  = '-->' . $inner . substr( $tail, 3 + strlen( $card['inner'] ) );
				}

				return $card['head'] . $attrs . $tail;
			},
			$content,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);

		return is_string( $stripped ) ? $stripped : $content;
	}

	/**
	 * One acquisition card's `cost` attribute as plain text.
	 *
	 * @param string|null $attrs The card's block comment attributes, JSON.
	 * @return string
	 */
	private static function card_cost( ?string $attrs ): string {
		$decoded = json_decode( (string) $attrs, true );

		return is_array( $decoded ) && is_string( $decoded['cost'] ?? null ) ? self::plain_text( $decoded['cost'] ) : '';
	}

	/**
	 * Markup as the text a reader sees: tags stripped, entities decoded,
	 * whitespace collapsed and trimmed.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function plain_text( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Display-context post_content without private acquisition cost.
	 *
	 * Core's sanitize_post_field() runs the `post_content` filter for
	 * display reads, such as get_post_field( 'post_content', $post ) and
	 * ActivityPub's generate_post_summary(), which builds an Article's
	 * summary and preview from post_content and has no filter of its own
	 * (ActivityPub 9.3.1 includes/functions-post.php:363). The edit, db and
	 * raw contexts skip this filter, so the block editor, saves and exports
	 * get stored content. Only the static subtitle goes; the card keeps its
	 * cost attribute. There's no editor override, for the reason
	 * acquisition_cost_visible() gives.
	 *
	 * @param mixed $value   Post content.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public static function display_content_without_private_cost( $value, $post_id = 0 ) {
		return is_string( $value ) ? self::strip_private_cost( $value, (int) $post_id ) : $value;
	}

	/**
	 * Keep private acquisition cost from deciding search results.
	 *
	 * WP_Query matches each search term against post_content, which holds
	 * cost in the card's block comment and, for cards saved before issue
	 * 239, in its static HTML. A visitor could read a private cost one
	 * digit at a time ('Zq9 $14' hits, 'Zq9 $13' misses), or with an
	 * excluded term. For each acquisition post the requester can't edit
	 * whose content matches a term, this reruns core's term rules against
	 * the content with private cost taken out. A post that matched only
	 * through cost leaves the results, and a post that an excluded term
	 * dropped only through cost comes back. REST search runs on WP_Query,
	 * so it follows.
	 *
	 * @param mixed $search Search SQL from WP_Query::parse_search().
	 * @param mixed $query  The query.
	 * @return mixed
	 */
	public static function search_without_private_cost( $search, $query ) {
		global $wpdb;

		if ( ! is_string( $search ) || '' === $search || ! $query instanceof \WP_Query || $query->get( 'exact' ) ) {
			return $search;
		}

		$columns = $query->get( 'search_columns' );
		$columns = empty( $columns ) ? self::SEARCH_COLUMNS : (array) $columns;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, read the way WP_Query::parse_search() reads it.
		$columns = array_values( array_intersect( (array) apply_filters( 'post_search_columns', $columns, $query->get( 's' ), $query ), self::SEARCH_COLUMNS ) );
		if ( [] === $columns ) {
			$columns = self::SEARCH_COLUMNS;
		}
		if ( ! in_array( 'post_content', $columns, true ) ) {
			return $search;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, read the way WP_Query::parse_search() reads it.
		$prefix = apply_filters( 'wp_query_search_exclusion_prefix', '-' );
		$terms  = [];
		$likes  = [];
		foreach ( (array) $query->get( 'search_terms' ) as $term ) {
			$term     = (string) $term;
			$excluded = $prefix && str_starts_with( $term, (string) $prefix );
			$term     = $excluded ? substr( $term, 1 ) : $term;
			$terms[]  = [ $term, $excluded ];
			$likes[]  = '%' . $wpdb->esc_like( $term ) . '%';
		}
		if ( [] === $terms ) {
			return $search;
		}

		$term_clauses = implode( "\n\t\t\t\t\t\t\tOR ", array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Per-search lookup; $term_clauses holds only %s placeholders, filled from $likes.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'
					SELECT
						ID,
						post_title,
						post_excerpt,
						post_content,
						post_password
					FROM
						%i
					WHERE
						post_type <> %s
						AND post_content LIKE %s
						AND (
							' . $term_clauses . '
						)
				',
				array_merge(
					[
						$wpdb->posts,
						'revision',
						'%' . $wpdb->esc_like( 'wp:post-kinds-indieweb/acquisition-card' ) . '%',
					],
					$likes
				)
			)
		);
		// phpcs:enable

		$include = [];
		$exclude = [];
		foreach ( (array) $rows as $row ) {
			$id      = (int) $row->ID;
			$content = (string) $row->post_content;
			$safe    = self::strip_private_cost( $content, $id, true );
			if ( $safe === $content || current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$fields = array_intersect_key(
				[
					'post_title'   => (string) $row->post_title,
					'post_excerpt' => (string) $row->post_excerpt,
					'post_content' => $content,
				],
				array_flip( $columns )
			);

			$before                 = self::search_matches( $terms, $fields );
			$fields['post_content'] = $safe;
			$after                  = self::search_matches( $terms, $fields );

			if ( $before === $after ) {
				continue;
			}
			if ( ! $after ) {
				$exclude[] = $id;
			} elseif ( '' === (string) $row->post_password || is_user_logged_in() ) {
				$include[] = $id;
			}
		}

		if ( [] !== $exclude ) {
			$search .= " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', $exclude ) . ')';
		}
		if ( [] !== $include ) {
			$search = " AND ( ( 1 = 1{$search} ) OR {$wpdb->posts}.ID IN (" . implode( ',', $include ) . ') )';
		}

		return $search;
	}

	/**
	 * Whether text passes a search the way WP_Query's LIKE clauses decide
	 * it: each term in at least one field, and no excluded term in any.
	 *
	 * @param array<int, array{0: string, 1: bool}> $terms  Each term and whether it's excluded.
	 * @param array<string, string>                 $fields Searched text by column.
	 * @return bool
	 */
	private static function search_matches( array $terms, array $fields ): bool {
		foreach ( $terms as [ $term, $excluded ] ) {
			$found = false;
			foreach ( $fields as $text ) {
				if ( false !== mb_stripos( $text, $term ) ) {
					$found = true;
					break;
				}
			}
			if ( $found === $excluded ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Register all plugin post meta fields.
	 */
	public function register_meta_fields(): void {
		// Check if CPT mode is enabled and add reaction post type.
		$settings     = get_option( 'pkiw_settings', [] );
		$storage_mode = $settings['import_storage_mode'] ?? 'standard';

		if ( 'cpt' === $storage_mode ) {
			$this->post_types[] = \PKIW\Post_Type::POST_TYPE;
		}

		/**
		 * Filters the post types that meta fields are registered for.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string> $post_types Array of post type slugs.
		 */
		$this->post_types = apply_filters( 'pkiw_meta_post_types', $this->post_types );

		foreach ( $this->post_types as $post_type ) {
			foreach ( $this->fields as $key => $field ) {
				$meta_key = self::PREFIX . $key;

				$args = [
					'type'              => $field['type'],
					'description'       => $field['description'],
					'single'            => true,
					'default'           => $field['default'],
					'sanitize_callback' => $this->get_sanitize_callback( $field['sanitize'] ),
					'auth_callback'     => [ $this, 'auth_callback' ],
					'show_in_rest'      => $this->get_rest_schema( $field ),
				];

				register_post_meta( $post_type, $meta_key, $args );
			}
		}
	}

	/**
	 * Get the sanitize callback for a field.
	 *
	 * @param string|array<int, mixed> $sanitize Sanitize callback definition.
	 * @return callable Sanitize callback.
	 */
	private function get_sanitize_callback( string|array $sanitize ): callable {
		if ( is_array( $sanitize ) ) {
			return $sanitize;
		}

		if ( function_exists( $sanitize ) ) {
			return $sanitize;
		}

		return 'sanitize_text_field';
	}

	/**
	 * Get REST API schema for a field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return array<string, mixed> REST schema.
	 */
	private function get_rest_schema( array $field ): array {
		$schema = [
			'schema' => [
				'type'        => $field['type'],
				'description' => $field['description'],
				'default'     => $field['default'],
			],
		];

		if ( isset( $field['enum'] ) ) {
			$schema['schema']['enum'] = $field['enum'];
		}

		return $schema;
	}

	/**
	 * Authorization callback for meta field updates.
	 *
	 * @param bool   $allowed  Whether the user is allowed.
	 * @param string $meta_key Meta key being checked.
	 * @param int    $post_id  Post ID.
	 * @return bool Whether the user can edit this meta.
	 */
	public function auth_callback( bool $allowed, string $meta_key, int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Sanitize RSVP status value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_rsvp_status( mixed $value ): string {
		$valid = [ 'yes', 'no', 'maybe', 'interested' ];
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, $valid, true ) ? $value : '';
	}

	/**
	 * Sanitize an RSVP's location privacy: 'public', or 'private' for anything else.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_rsvp_location_privacy( mixed $value ): string {
		return 'public' === $value ? 'public' : 'private';
	}

	/**
	 * Sanitize watch status value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_watch_status( mixed $value ): string {
		$valid = [ 'to-watch', 'watching', 'watched', 'rewatching', 'abandoned' ];
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, $valid, true ) ? $value : 'to-watch';
	}

	/**
	 * Sanitize read status value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_read_status( mixed $value ): string {
		$valid = [ 'to-read', 'reading', 'finished', 'abandoned' ];
		$value = sanitize_text_field( (string) $value );

		// `to-read` is the safe default for invalid/unrecognised input —
		// "haven't started" is more honest than implying a book is being
		// read just because the status field had garbage in it.
		return in_array( $value, $valid, true ) ? $value : 'to-read';
	}

	/**
	 * Sanitize a comic reading status.
	 *
	 * Unlike a book read, a comics post may hold no card at all (a strip its
	 * author drew), so anything unrecognised is stored as "no status" and
	 * never as a reading state the author did not choose.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_comic_status( mixed $value ): string {
		$valid = [ 'to-read', 'reading', 'finished', 'abandoned' ];
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, $valid, true ) ? $value : '';
	}

	/**
	 * Sanitize geographic coordinate.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return float Sanitized coordinate.
	 */
	public function sanitize_coordinate( mixed $value ): float {
		$value = (float) $value;

		// Latitude range: -90 to 90.
		// Longitude range: -180 to 180.
		// We allow full range here; validation happens elsewhere.
		return round( $value, 7 );
	}

	/**
	 * Sanitize geo privacy value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_geo_privacy( mixed $value ): string {
		$valid = [ 'public', 'approximate', 'private' ];
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, $valid, true ) ? $value : 'approximate';
	}

	/**
	 * Sanitize rating value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return float Sanitized rating.
	 */
	public function sanitize_rating( mixed $value ): float {
		$value = (float) $value;

		// Clamp to 0-10 range (allows for different scales).
		$value = max( 0, min( 10, $value ) );

		// Round to nearest 0.5.
		return round( $value * 2 ) / 2;
	}

	/**
	 * Sanitize ISBN.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized ISBN.
	 */
	public function sanitize_isbn( mixed $value ): string {
		// Remove all non-numeric characters except X (for ISBN-10 check digit).
		$value = preg_replace( '/[^0-9X]/i', '', strtoupper( (string) $value ) );

		// Validate length (10 or 13 digits).
		$length = strlen( $value );
		if ( 10 !== $length && 13 !== $length ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitize priority value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_priority( mixed $value ): string {
		$valid = [ 'low', 'medium', 'high' ];
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, $valid, true ) ? $value : 'medium';
	}

	/**
	 * Sanitize mood rating value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return int Sanitized rating (1-5, or 0 for not set).
	 */
	public function sanitize_mood_rating( mixed $value ): int {
		// Use intval, NOT absint — absint silently flips negatives to
		// positives, which would treat a -5 typo as a valid 5-star rating.
		// Negative input should be rejected, not corrected.
		$value = is_numeric( $value ) ? (int) $value : 0;

		if ( $value < 1 || $value > 5 ) {
			return 0;
		}

		return $value;
	}

	/**
	 * Sanitize play status value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_play_status( mixed $value ): string {
		$valid = [ 'playing', 'completed', 'abandoned', 'backlog', 'wishlist' ];
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, $valid, true ) ? $value : 'playing';
	}

	/**
	 * Sanitize float value.
	 *
	 * Wrapper for floatval() that works with WordPress sanitize callbacks.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return float Sanitized float value.
	 */
	public function sanitize_float( mixed $value ): float {
		return (float) $value;
	}

	/**
	 * Get a meta value for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (without prefix).
	 * @return mixed Meta value.
	 */
	public function get_meta( int $post_id, string $key ): mixed {
		$meta_key = self::PREFIX . $key;

		return get_post_meta( $post_id, $meta_key, true );
	}

	/**
	 * Set a meta value for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (without prefix).
	 * @param mixed  $value   Meta value.
	 * @return bool True on success, false on failure.
	 */
	public function set_meta( int $post_id, string $key, mixed $value ): bool {
		$meta_key = self::PREFIX . $key;

		return (bool) update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Get all meta values for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> All meta values keyed by field name.
	 */
	public function get_all_meta( int $post_id ): array {
		$values = [];

		foreach ( array_keys( $this->fields ) as $key ) {
			$values[ $key ] = $this->get_meta( $post_id, $key );
		}

		return $values;
	}

	/**
	 * Get field definitions.
	 *
	 * @return array<string, array<string, mixed>> Field definitions.
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Get the meta key prefix.
	 *
	 * @return string Meta key prefix.
	 */
	public function get_prefix(): string {
		return self::PREFIX;
	}

	/**
	 * Check if a field key is valid.
	 *
	 * @param string $key Field key to check.
	 * @return bool True if valid, false otherwise.
	 */
	public function is_valid_field( string $key ): bool {
		return isset( $this->fields[ $key ] );
	}
}

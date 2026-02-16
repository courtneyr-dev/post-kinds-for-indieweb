<?php
/**
 * Post Meta Fields Registration
 *
 * Registers all post meta fields for IndieWeb post kinds with REST API exposure.
 *
 * @package PostKindsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

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
	public const PREFIX = '_postkind_';

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
				'description' => __( 'Title of the cited content.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'cite_url'                => [
				'type'        => 'string',
				'description' => __( 'URL of the cited content.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'cite_author'             => [
				'type'        => 'string',
				'description' => __( 'Author name of the cited content.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'cite_author_url'         => [
				'type'        => 'string',
				'description' => __( 'Author URL of the cited content.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'cite_photo'              => [
				'type'        => 'string',
				'description' => __( 'Featured image URL of the cited content.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'cite_summary'            => [
				'type'        => 'string',
				'description' => __( 'Summary or excerpt of the cited content.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'bookmark_embed_type'     => [
				'type'        => 'string',
				'description' => __( 'Embed type for bookmarks: auto, oembed, bookmark-card, or none.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => 'auto',
			],
			'cite_published'          => [
				'type'        => 'string',
				'description' => __( 'Publication date of the cited content (ISO 8601).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// RSVP Fields.
			'rsvp_status'             => [
				'type'        => 'string',
				'description' => __( 'RSVP status: yes, no, maybe, or interested.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rsvp_status' ],
				'default'     => '',
				'enum'        => [ '', 'yes', 'no', 'maybe', 'interested' ],
			],

			// Check-in Fields.
			'checkin_name'            => [
				'type'        => 'string',
				'description' => __( 'Venue or location name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_url'             => [
				'type'        => 'string',
				'description' => __( 'Venue URL.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'checkin_address'         => [
				'type'        => 'string',
				'description' => __( 'Street address.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_locality'        => [
				'type'        => 'string',
				'description' => __( 'City or locality.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_region'          => [
				'type'        => 'string',
				'description' => __( 'State, province, or region.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'checkin_country'         => [
				'type'        => 'string',
				'description' => __( 'Country name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'geo_latitude'            => [
				'type'        => 'number',
				'description' => __( 'Geographic latitude.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'geo_longitude'           => [
				'type'        => 'number',
				'description' => __( 'Geographic longitude.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'geo_privacy'             => [
				'type'        => 'string',
				'description' => __( 'Location privacy level: public, approximate, or private.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_geo_privacy' ],
				'default'     => 'approximate',
				'enum'        => [ 'public', 'approximate', 'private' ],
			],
			'checkin_osm_id'          => [
				'type'        => 'string',
				'description' => __( 'OpenStreetMap place ID.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Listen Fields.
			'listen_track'            => [
				'type'        => 'string',
				'description' => __( 'Track or episode name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_artist'           => [
				'type'        => 'string',
				'description' => __( 'Artist or creator name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_album'            => [
				'type'        => 'string',
				'description' => __( 'Album or podcast name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_cover'            => [
				'type'        => 'string',
				'description' => __( 'Album or podcast cover art URL.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'listen_mbid'             => [
				'type'        => 'string',
				'description' => __( 'MusicBrainz recording ID.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_url'              => [
				'type'        => 'string',
				'description' => __( 'URL to the track or episode.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'listen_rating'           => [
				'type'        => 'integer',
				'description' => __( 'Rating for the track (0-5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'listen_release_date'     => [
				'type'        => 'string',
				'description' => __( 'Album release date.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'listen_listened_at'      => [
				'type'        => 'string',
				'description' => __( 'When the track was listened to.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Watch Fields.
			'watch_title'             => [
				'type'        => 'string',
				'description' => __( 'Film or show title.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_year'              => [
				'type'        => 'string',
				'description' => __( 'Release year.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_poster'            => [
				'type'        => 'string',
				'description' => __( 'Poster image URL.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'watch_tmdb_id'           => [
				'type'        => 'string',
				'description' => __( 'TMDB ID for the film or show.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_status'            => [
				'type'        => 'string',
				'description' => __( 'Watch status: watched, watching, or abandoned.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_watch_status' ],
				'default'     => 'to-watch',
				'enum'        => [ 'to-watch', 'watching', 'watched', 'rewatching', 'abandoned' ],
			],
			'watch_spoilers'          => [
				'type'        => 'boolean',
				'description' => __( 'Whether the post contains spoilers.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => false,
			],
			'watch_url'               => [
				'type'        => 'string',
				'description' => __( 'URL to the film or show page.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'watch_rating'            => [
				'type'        => 'number',
				'description' => __( 'Rating out of 5.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'watch_review'            => [
				'type'        => 'string',
				'description' => __( 'Review or notes about the film/show.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'watch_is_rewatch'        => [
				'type'        => 'boolean',
				'description' => __( 'Whether this is a rewatch.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => false,
			],
			'watch_media_type'        => [
				'type'        => 'string',
				'description' => __( 'Media type: movie or tv.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => 'movie',
			],
			'watch_director'          => [
				'type'        => 'string',
				'description' => __( 'Director name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_imdb_id'           => [
				'type'        => 'string',
				'description' => __( 'IMDB ID for the film or show.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_show_title'        => [
				'type'        => 'string',
				'description' => __( 'TV show title (for episodes).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'watch_season'            => [
				'type'        => 'number',
				'description' => __( 'Season number.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'watch_episode'           => [
				'type'        => 'number',
				'description' => __( 'Episode number.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'watch_episode_title'     => [
				'type'        => 'string',
				'description' => __( 'Episode title.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Read Fields.
			'read_title'              => [
				'type'        => 'string',
				'description' => __( 'Book or article title.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_author'             => [
				'type'        => 'string',
				'description' => __( 'Book or article author.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_isbn'               => [
				'type'        => 'string',
				'description' => __( 'ISBN-13 of the book.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_isbn' ],
				'default'     => '',
			],
			'read_cover'              => [
				'type'        => 'string',
				'description' => __( 'Book cover image URL.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'read_status'             => [
				'type'        => 'string',
				'description' => __( 'Reading status: to-read, reading, finished, or abandoned.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_read_status' ],
				'default'     => 'reading',
				'enum'        => [ 'to-read', 'reading', 'finished', 'abandoned' ],
			],
			'read_progress'           => [
				'type'        => 'number',
				'description' => __( 'Reading progress (percentage or page number).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'read_pages'              => [
				'type'        => 'number',
				'description' => __( 'Total number of pages.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			],
			'read_url'                => [
				'type'        => 'string',
				'description' => __( 'URL to the book or article.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'read_publisher'          => [
				'type'        => 'string',
				'description' => __( 'Publisher name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_publish_date'       => [
				'type'        => 'string',
				'description' => __( 'Publication date.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_rating'             => [
				'type'        => 'integer',
				'description' => __( 'Rating for the book (0-5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'read_started_at'         => [
				'type'        => 'string',
				'description' => __( 'Date started reading.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_finished_at'        => [
				'type'        => 'string',
				'description' => __( 'Date finished reading.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'read_review'             => [
				'type'        => 'string',
				'description' => __( 'Review or notes about the book.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'wp_kses_post',
				'default'     => '',
			],

			// Event Fields.
			'event_start'             => [
				'type'        => 'string',
				'description' => __( 'Event start datetime (ISO 8601).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'event_end'               => [
				'type'        => 'string',
				'description' => __( 'Event end datetime (ISO 8601).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'event_location'          => [
				'type'        => 'string',
				'description' => __( 'Event location name or address.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'event_url'               => [
				'type'        => 'string',
				'description' => __( 'Event URL or registration link.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Review Fields.
			'review_rating'           => [
				'type'        => 'number',
				'description' => __( 'Rating value (supports decimals like 3.5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'review_best'             => [
				'type'        => 'number',
				'description' => __( 'Best possible rating (typically 5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 5,
			],
			'review_item_name'        => [
				'type'        => 'string',
				'description' => __( 'Name of the reviewed item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'review_item_url'         => [
				'type'        => 'string',
				'description' => __( 'URL of the reviewed item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Recipe Fields.
			'recipe_yield'            => [
				'type'        => 'string',
				'description' => __( 'Recipe yield (e.g., "4 servings").', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'recipe_duration'         => [
				'type'        => 'string',
				'description' => __( 'Total recipe duration (ISO 8601 duration).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],

			// Favorite Fields.
			'favorite_name'           => [
				'type'        => 'string',
				'description' => __( 'Name of the favorited item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'favorite_url'            => [
				'type'        => 'string',
				'description' => __( 'URL of the favorited item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'favorite_rating'         => [
				'type'        => 'number',
				'description' => __( 'Rating for the favorited item (0-5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],

			// Jam Fields (extends listen with "highlight" flag).
			'jam_track'               => [
				'type'        => 'string',
				'description' => __( 'Track name for jam.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'jam_artist'              => [
				'type'        => 'string',
				'description' => __( 'Artist name for jam.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'jam_album'               => [
				'type'        => 'string',
				'description' => __( 'Album name for jam.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'jam_cover'               => [
				'type'        => 'string',
				'description' => __( 'Cover art URL for jam.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'jam_url'                 => [
				'type'        => 'string',
				'description' => __( 'URL to the track.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Wish Fields.
			'wish_name'               => [
				'type'        => 'string',
				'description' => __( 'Name of the wished item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'wish_url'                => [
				'type'        => 'string',
				'description' => __( 'URL of the wished item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'wish_photo'              => [
				'type'        => 'string',
				'description' => __( 'Image URL of the wished item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'wish_type'               => [
				'type'        => 'string',
				'description' => __( 'Type of wish: book, movie, product, experience, etc.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'wish_priority'           => [
				'type'        => 'string',
				'description' => __( 'Priority level: low, medium, high.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_priority' ],
				'default'     => 'medium',
				'enum'        => [ 'low', 'medium', 'high' ],
			],

			// Mood Fields.
			'mood_emoji'              => [
				'type'        => 'string',
				'description' => __( 'Emoji representing the mood.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'mood_label'              => [
				'type'        => 'string',
				'description' => __( 'Text label for the mood (e.g., happy, tired, excited).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'mood_rating'             => [
				'type'        => 'number',
				'description' => __( 'Mood rating on 1-5 scale.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_mood_rating' ],
				'default'     => 0,
			],

			// Acquisition Fields.
			'acquisition_name'        => [
				'type'        => 'string',
				'description' => __( 'Name of the acquired item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'acquisition_url'         => [
				'type'        => 'string',
				'description' => __( 'URL of the acquired item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'acquisition_photo'       => [
				'type'        => 'string',
				'description' => __( 'Image URL of the acquired item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'acquisition_price'       => [
				'type'        => 'string',
				'description' => __( 'Price paid for the item.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'acquisition_rating'      => [
				'type'        => 'number',
				'description' => __( 'Rating for the acquired item (0-5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],

			// Drink Fields.
			'drink_name'              => [
				'type'        => 'string',
				'description' => __( 'Name of the beverage.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_type'              => [
				'type'        => 'string',
				'description' => __( 'Type of drink: coffee, beer, wine, cocktail, tea, etc.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_brewery'           => [
				'type'        => 'string',
				'description' => __( 'Brewery, winery, or producer name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_photo'             => [
				'type'        => 'string',
				'description' => __( 'Photo URL of the drink.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'drink_rating'            => [
				'type'        => 'number',
				'description' => __( 'Rating for the drink (0-5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'drink_location_name'     => [
				'type'        => 'string',
				'description' => __( 'Bar, cafe, or venue name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_address'  => [
				'type'        => 'string',
				'description' => __( 'Street address of the venue.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_locality' => [
				'type'        => 'string',
				'description' => __( 'City or locality.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_region'   => [
				'type'        => 'string',
				'description' => __( 'State, province, or region.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_location_country'  => [
				'type'        => 'string',
				'description' => __( 'Country name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'drink_geo_latitude'      => [
				'type'        => 'number',
				'description' => __( 'Geographic latitude of the venue.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'drink_geo_longitude'     => [
				'type'        => 'number',
				'description' => __( 'Geographic longitude of the venue.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'drink_notes'             => [
				'type'        => 'string',
				'description' => __( 'Tasting notes for the drink.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'drink_venue_url'         => [
				'type'        => 'string',
				'description' => __( 'URL for the venue website.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Eat Fields.
			'eat_name'                => [
				'type'        => 'string',
				'description' => __( 'Name of the meal or dish.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_type'                => [
				'type'        => 'string',
				'description' => __( 'Type of meal: breakfast, lunch, dinner, snack.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_restaurant'          => [
				'type'        => 'string',
				'description' => __( 'Restaurant or venue name (deprecated, use eat_location_name).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_photo'               => [
				'type'        => 'string',
				'description' => __( 'Photo URL of the meal.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'eat_rating'              => [
				'type'        => 'number',
				'description' => __( 'Rating for the meal (0-5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'eat_location_name'       => [
				'type'        => 'string',
				'description' => __( 'Restaurant or venue name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_address'    => [
				'type'        => 'string',
				'description' => __( 'Street address of the venue.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_locality'   => [
				'type'        => 'string',
				'description' => __( 'City or locality.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_region'     => [
				'type'        => 'string',
				'description' => __( 'State, province, or region.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_location_country'    => [
				'type'        => 'string',
				'description' => __( 'Country name.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_geo_latitude'        => [
				'type'        => 'number',
				'description' => __( 'Geographic latitude of the venue.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'eat_geo_longitude'       => [
				'type'        => 'number',
				'description' => __( 'Geographic longitude of the venue.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_coordinate' ],
				'default'     => 0,
			],
			'eat_cuisine'             => [
				'type'        => 'string',
				'description' => __( 'Cuisine type (american, italian, etc.).', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_notes'               => [
				'type'        => 'string',
				'description' => __( 'Notes about the meal.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'eat_restaurant_url'      => [
				'type'        => 'string',
				'description' => __( 'Restaurant website URL.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Play Fields (gaming).
			'play_title'              => [
				'type'        => 'string',
				'description' => __( 'Game title.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_platform'           => [
				'type'        => 'string',
				'description' => __( 'Platform: PC, PlayStation, Xbox, Nintendo, Mobile, Board Game, etc.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_status'             => [
				'type'        => 'string',
				'description' => __( 'Play status: playing, completed, abandoned, backlog.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_play_status' ],
				'default'     => 'playing',
				'enum'        => [ 'playing', 'completed', 'abandoned', 'backlog', 'wishlist' ],
			],
			'play_hours'              => [
				'type'        => 'number',
				'description' => __( 'Hours played.', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_float' ],
				'default'     => 0,
			],
			'play_cover'              => [
				'type'        => 'string',
				'description' => __( 'Game cover art URL.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'play_igdb_id'            => [
				'type'        => 'string',
				'description' => __( 'IGDB game ID.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_steam_id'           => [
				'type'        => 'string',
				'description' => __( 'Steam app ID.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_bgg_id'             => [
				'type'        => 'string',
				'description' => __( 'BoardGameGeek game ID.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_rawg_id'            => [
				'type'        => 'string',
				'description' => __( 'RAWG.io game ID.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			],
			'play_rating'             => [
				'type'        => 'number',
				'description' => __( 'Rating for the game (0-5).', 'post-kinds-for-indieweb' ),
				'sanitize'    => [ $this, 'sanitize_rating' ],
				'default'     => 0,
			],
			'play_official_url'       => [
				'type'        => 'string',
				'description' => __( 'Official website URL for the game.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'play_purchase_url'       => [
				'type'        => 'string',
				'description' => __( 'Purchase URL for the game.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],
			'play_review'             => [
				'type'        => 'string',
				'description' => __( 'Review or notes about the game.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			],
			'play_game_url'           => [
				'type'        => 'string',
				'description' => __( 'URL to the game page.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			],

			// Syndication Opt-Out Fields.
			'syndicate_lastfm'        => [
				'type'        => 'boolean',
				'description' => __( 'Whether to syndicate to Last.fm.', 'post-kinds-for-indieweb' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => true,
			],
			'syndicate_trakt'         => [
				'type'        => 'boolean',
				'description' => __( 'Whether to syndicate to Trakt.', 'post-kinds-for-indieweb' ),
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
		$this->fields = apply_filters( 'post_kinds_indieweb_meta_fields', $this->fields );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', [ $this, 'register_meta_fields' ] );
	}

	/**
	 * Register all meta fields with WordPress.
	 *
	 * @return void
	 */
	public function register_meta_fields(): void {
		// Check if CPT mode is enabled and add reaction post type.
		$settings     = get_option( 'post_kinds_indieweb_settings', [] );
		$storage_mode = $settings['import_storage_mode'] ?? 'standard';

		if ( 'cpt' === $storage_mode ) {
			$this->post_types[] = 'reaction';
		}

		/**
		 * Filters the post types that meta fields are registered for.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string> $post_types Array of post type slugs.
		 */
		$this->post_types = apply_filters( 'post_kinds_indieweb_meta_post_types', $this->post_types );

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

		return in_array( $value, $valid, true ) ? $value : 'reading';
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
		$value = absint( $value );

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

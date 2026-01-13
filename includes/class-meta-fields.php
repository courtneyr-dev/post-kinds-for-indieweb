<?php
/**
 * Post Meta Fields Registration
 *
 * Registers all post meta fields for IndieWeb post kinds with REST API exposure.
 *
 * @package ReactionsForIndieWeb
 * @since   1.0.0
 */

declare(strict_types=1);

namespace ReactionsForIndieWeb;

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
	public const PREFIX = '_reactions_';

	/**
	 * Post types to register meta for.
	 *
	 * @var array<string>
	 */
	private array $post_types = array( 'post' );

	/**
	 * Meta field definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields = array();

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
		$this->fields = array(
			// Citation Fields (All Response Kinds).
			'cite_name'        => array(
				'type'        => 'string',
				'description' => __( 'Title of the cited content.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'cite_url'         => array(
				'type'        => 'string',
				'description' => __( 'URL of the cited content.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'cite_author'      => array(
				'type'        => 'string',
				'description' => __( 'Author name of the cited content.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'cite_author_url'  => array(
				'type'        => 'string',
				'description' => __( 'Author URL of the cited content.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'cite_photo'       => array(
				'type'        => 'string',
				'description' => __( 'Featured image URL of the cited content.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'cite_summary'     => array(
				'type'        => 'string',
				'description' => __( 'Summary or excerpt of the cited content.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => '',
			),
			'cite_published'   => array(
				'type'        => 'string',
				'description' => __( 'Publication date of the cited content (ISO 8601).', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),

			// RSVP Fields.
			'rsvp_status'      => array(
				'type'        => 'string',
				'description' => __( 'RSVP status: yes, no, maybe, or interested.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_rsvp_status' ),
				'default'     => '',
				'enum'        => array( '', 'yes', 'no', 'maybe', 'interested' ),
			),

			// Check-in Fields.
			'checkin_name'     => array(
				'type'        => 'string',
				'description' => __( 'Venue or location name.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'checkin_url'      => array(
				'type'        => 'string',
				'description' => __( 'Venue URL.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'checkin_address'  => array(
				'type'        => 'string',
				'description' => __( 'Street address.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'checkin_locality' => array(
				'type'        => 'string',
				'description' => __( 'City or locality.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'checkin_region'   => array(
				'type'        => 'string',
				'description' => __( 'State, province, or region.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'checkin_country'  => array(
				'type'        => 'string',
				'description' => __( 'Country name.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'geo_latitude'     => array(
				'type'        => 'number',
				'description' => __( 'Geographic latitude.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_coordinate' ),
				'default'     => 0,
			),
			'geo_longitude'    => array(
				'type'        => 'number',
				'description' => __( 'Geographic longitude.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_coordinate' ),
				'default'     => 0,
			),
			'geo_privacy'      => array(
				'type'        => 'string',
				'description' => __( 'Location privacy level: public, approximate, or private.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_geo_privacy' ),
				'default'     => 'approximate',
				'enum'        => array( 'public', 'approximate', 'private' ),
			),
			'checkin_osm_id'   => array(
				'type'        => 'string',
				'description' => __( 'OpenStreetMap place ID.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),

			// Listen Fields.
			'listen_track'     => array(
				'type'        => 'string',
				'description' => __( 'Track or episode name.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'listen_artist'    => array(
				'type'        => 'string',
				'description' => __( 'Artist or creator name.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'listen_album'     => array(
				'type'        => 'string',
				'description' => __( 'Album or podcast name.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'listen_cover'     => array(
				'type'        => 'string',
				'description' => __( 'Album or podcast cover art URL.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'listen_mbid'      => array(
				'type'        => 'string',
				'description' => __( 'MusicBrainz recording ID.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'listen_url'       => array(
				'type'        => 'string',
				'description' => __( 'URL to the track or episode.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),

			// Watch Fields.
			'watch_title'      => array(
				'type'        => 'string',
				'description' => __( 'Film or show title.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'watch_year'       => array(
				'type'        => 'string',
				'description' => __( 'Release year.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'watch_poster'     => array(
				'type'        => 'string',
				'description' => __( 'Poster image URL.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'watch_tmdb_id'    => array(
				'type'        => 'string',
				'description' => __( 'TMDB ID for the film or show.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'watch_status'     => array(
				'type'        => 'string',
				'description' => __( 'Watch status: watched, watching, or abandoned.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_watch_status' ),
				'default'     => 'watched',
				'enum'        => array( 'watched', 'watching', 'abandoned' ),
			),
			'watch_spoilers'   => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the post contains spoilers.', 'reactions-for-indieweb' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => false,
			),
			'watch_url'        => array(
				'type'        => 'string',
				'description' => __( 'URL to the film or show page.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),

			// Read Fields.
			'read_title'       => array(
				'type'        => 'string',
				'description' => __( 'Book or article title.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'read_author'      => array(
				'type'        => 'string',
				'description' => __( 'Book or article author.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'read_isbn'        => array(
				'type'        => 'string',
				'description' => __( 'ISBN-13 of the book.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_isbn' ),
				'default'     => '',
			),
			'read_cover'       => array(
				'type'        => 'string',
				'description' => __( 'Book cover image URL.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'read_status'      => array(
				'type'        => 'string',
				'description' => __( 'Reading status: to-read, reading, finished, or abandoned.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_read_status' ),
				'default'     => 'reading',
				'enum'        => array( 'to-read', 'reading', 'finished', 'abandoned' ),
			),
			'read_progress'    => array(
				'type'        => 'number',
				'description' => __( 'Reading progress (percentage or page number).', 'reactions-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			),
			'read_pages'       => array(
				'type'        => 'number',
				'description' => __( 'Total number of pages.', 'reactions-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 0,
			),
			'read_url'         => array(
				'type'        => 'string',
				'description' => __( 'URL to the book or article.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),

			// Event Fields.
			'event_start'      => array(
				'type'        => 'string',
				'description' => __( 'Event start datetime (ISO 8601).', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'event_end'        => array(
				'type'        => 'string',
				'description' => __( 'Event end datetime (ISO 8601).', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'event_location'   => array(
				'type'        => 'string',
				'description' => __( 'Event location name or address.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'event_url'        => array(
				'type'        => 'string',
				'description' => __( 'Event URL or registration link.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),

			// Review Fields.
			'review_rating'    => array(
				'type'        => 'number',
				'description' => __( 'Rating value (supports decimals like 3.5).', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_rating' ),
				'default'     => 0,
			),
			'review_best'      => array(
				'type'        => 'number',
				'description' => __( 'Best possible rating (typically 5).', 'reactions-for-indieweb' ),
				'sanitize'    => 'absint',
				'default'     => 5,
			),
			'review_item_name' => array(
				'type'        => 'string',
				'description' => __( 'Name of the reviewed item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'review_item_url'  => array(
				'type'        => 'string',
				'description' => __( 'URL of the reviewed item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),

			// Recipe Fields.
			'recipe_yield'     => array(
				'type'        => 'string',
				'description' => __( 'Recipe yield (e.g., "4 servings").', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'recipe_duration'  => array(
				'type'        => 'string',
				'description' => __( 'Total recipe duration (ISO 8601 duration).', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),

			// Favorite Fields.
			'favorite_name'    => array(
				'type'        => 'string',
				'description' => __( 'Name of the favorited item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'favorite_url'     => array(
				'type'        => 'string',
				'description' => __( 'URL of the favorited item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'favorite_rating'  => array(
				'type'        => 'number',
				'description' => __( 'Rating for the favorited item (0-5).', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_rating' ),
				'default'     => 0,
			),

			// Jam Fields (extends listen with "highlight" flag).
			'jam_track'        => array(
				'type'        => 'string',
				'description' => __( 'Track name for jam.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'jam_artist'       => array(
				'type'        => 'string',
				'description' => __( 'Artist name for jam.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'jam_album'        => array(
				'type'        => 'string',
				'description' => __( 'Album name for jam.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'jam_cover'        => array(
				'type'        => 'string',
				'description' => __( 'Cover art URL for jam.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'jam_url'          => array(
				'type'        => 'string',
				'description' => __( 'URL to the track.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),

			// Wish Fields.
			'wish_name'        => array(
				'type'        => 'string',
				'description' => __( 'Name of the wished item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'wish_url'         => array(
				'type'        => 'string',
				'description' => __( 'URL of the wished item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'wish_photo'       => array(
				'type'        => 'string',
				'description' => __( 'Image URL of the wished item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'wish_type'        => array(
				'type'        => 'string',
				'description' => __( 'Type of wish: book, movie, product, experience, etc.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'wish_priority'    => array(
				'type'        => 'string',
				'description' => __( 'Priority level: low, medium, high.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_priority' ),
				'default'     => 'medium',
				'enum'        => array( 'low', 'medium', 'high' ),
			),

			// Mood Fields.
			'mood_emoji'       => array(
				'type'        => 'string',
				'description' => __( 'Emoji representing the mood.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'mood_label'       => array(
				'type'        => 'string',
				'description' => __( 'Text label for the mood (e.g., happy, tired, excited).', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'mood_rating'      => array(
				'type'        => 'number',
				'description' => __( 'Mood rating on 1-5 scale.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_mood_rating' ),
				'default'     => 0,
			),

			// Acquisition Fields.
			'acquisition_name' => array(
				'type'        => 'string',
				'description' => __( 'Name of the acquired item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'acquisition_url'  => array(
				'type'        => 'string',
				'description' => __( 'URL of the acquired item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'acquisition_photo' => array(
				'type'        => 'string',
				'description' => __( 'Image URL of the acquired item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'acquisition_price' => array(
				'type'        => 'string',
				'description' => __( 'Price paid for the item.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'acquisition_rating' => array(
				'type'        => 'number',
				'description' => __( 'Rating for the acquired item (0-5).', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_rating' ),
				'default'     => 0,
			),

			// Drink Fields.
			'drink_name'       => array(
				'type'        => 'string',
				'description' => __( 'Name of the beverage.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'drink_type'       => array(
				'type'        => 'string',
				'description' => __( 'Type of drink: coffee, beer, wine, cocktail, tea, etc.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'drink_brewery'    => array(
				'type'        => 'string',
				'description' => __( 'Brewery, winery, or producer name.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'drink_photo'      => array(
				'type'        => 'string',
				'description' => __( 'Photo URL of the drink.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'drink_rating'     => array(
				'type'        => 'number',
				'description' => __( 'Rating for the drink (0-5).', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_rating' ),
				'default'     => 0,
			),

			// Eat Fields.
			'eat_name'         => array(
				'type'        => 'string',
				'description' => __( 'Name of the meal or dish.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'eat_type'         => array(
				'type'        => 'string',
				'description' => __( 'Type of meal: breakfast, lunch, dinner, snack.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'eat_restaurant'   => array(
				'type'        => 'string',
				'description' => __( 'Restaurant or venue name.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'eat_photo'        => array(
				'type'        => 'string',
				'description' => __( 'Photo URL of the meal.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'eat_rating'       => array(
				'type'        => 'number',
				'description' => __( 'Rating for the meal (0-5).', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_rating' ),
				'default'     => 0,
			),

			// Play Fields (gaming).
			'play_title'       => array(
				'type'        => 'string',
				'description' => __( 'Game title.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'play_platform'    => array(
				'type'        => 'string',
				'description' => __( 'Platform: PC, PlayStation, Xbox, Nintendo, Mobile, Board Game, etc.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'play_status'      => array(
				'type'        => 'string',
				'description' => __( 'Play status: playing, completed, abandoned, backlog.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_play_status' ),
				'default'     => 'playing',
				'enum'        => array( 'playing', 'completed', 'abandoned', 'backlog' ),
			),
			'play_hours'       => array(
				'type'        => 'number',
				'description' => __( 'Hours played.', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_float' ),
				'default'     => 0,
			),
			'play_cover'       => array(
				'type'        => 'string',
				'description' => __( 'Game cover art URL.', 'reactions-for-indieweb' ),
				'sanitize'    => 'esc_url_raw',
				'default'     => '',
			),
			'play_igdb_id'     => array(
				'type'        => 'string',
				'description' => __( 'IGDB game ID.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'play_steam_id'    => array(
				'type'        => 'string',
				'description' => __( 'Steam app ID.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'play_bgg_id'      => array(
				'type'        => 'string',
				'description' => __( 'BoardGameGeek game ID.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'play_rawg_id'     => array(
				'type'        => 'string',
				'description' => __( 'RAWG.io game ID.', 'reactions-for-indieweb' ),
				'sanitize'    => 'sanitize_text_field',
				'default'     => '',
			),
			'play_rating'      => array(
				'type'        => 'number',
				'description' => __( 'Rating for the game (0-5).', 'reactions-for-indieweb' ),
				'sanitize'    => array( $this, 'sanitize_rating' ),
				'default'     => 0,
			),

			// Syndication Opt-Out Fields.
			'syndicate_lastfm' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to syndicate to Last.fm.', 'reactions-for-indieweb' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => true,
			),
			'syndicate_trakt'  => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to syndicate to Trakt.', 'reactions-for-indieweb' ),
				'sanitize'    => 'rest_sanitize_boolean',
				'default'     => true,
			),
		);

		/**
		 * Filters the meta field definitions.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $fields Meta field definitions.
		 */
		$this->fields = apply_filters( 'reactions_indieweb_meta_fields', $this->fields );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta_fields' ) );
	}

	/**
	 * Register all meta fields with WordPress.
	 *
	 * @return void
	 */
	public function register_meta_fields(): void {
		// Check if CPT mode is enabled and add reaction post type.
		$settings     = get_option( 'reactions_indieweb_settings', array() );
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
		$this->post_types = apply_filters( 'reactions_indieweb_meta_post_types', $this->post_types );

		foreach ( $this->post_types as $post_type ) {
			foreach ( $this->fields as $key => $field ) {
				$meta_key = self::PREFIX . $key;

				$args = array(
					'type'              => $field['type'],
					'description'       => $field['description'],
					'single'            => true,
					'default'           => $field['default'],
					'sanitize_callback' => $this->get_sanitize_callback( $field['sanitize'] ),
					'auth_callback'     => array( $this, 'auth_callback' ),
					'show_in_rest'      => $this->get_rest_schema( $field ),
				);

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
		$schema = array(
			'schema' => array(
				'type'        => $field['type'],
				'description' => $field['description'],
				'default'     => $field['default'],
			),
		);

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
		$valid = array( 'yes', 'no', 'maybe', 'interested' );
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
		$valid = array( 'watched', 'watching', 'abandoned' );
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, $valid, true ) ? $value : 'watched';
	}

	/**
	 * Sanitize read status value.
	 *
	 * @param mixed $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public function sanitize_read_status( mixed $value ): string {
		$valid = array( 'to-read', 'reading', 'finished', 'abandoned' );
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
		$valid = array( 'public', 'approximate', 'private' );
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
		$valid = array( 'low', 'medium', 'high' );
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
		$valid = array( 'playing', 'completed', 'abandoned', 'backlog' );
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
		$values = array();

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

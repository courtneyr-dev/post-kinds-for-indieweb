<?php
/**
 * Block Bindings Source Registration
 *
 * Registers the custom Block Bindings source for connecting core blocks to post kind metadata.
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
 * Block Bindings registration class.
 *
 * Registers the 'post-kinds-indieweb/kind-meta' binding source that allows
 * core blocks to display dynamic content from post kind metadata.
 *
 * @since 1.0.0
 */
class Block_Bindings {

	/**
	 * Binding source name.
	 *
	 * @var string
	 */
	public const SOURCE_NAME = 'post-kinds-indieweb/kind-meta';

	/**
	 * Meta fields instance.
	 *
	 * @var Meta_Fields|null
	 */
	private ?Meta_Fields $meta_fields = null;

	/**
	 * Binding key to meta key mapping.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $bindings = [];

	/**
	 * Constructor.
	 *
	 * Sets up binding definitions and hooks.
	 */
	public function __construct() {
		$this->define_bindings();
		$this->register_hooks();
	}

	/**
	 * Define all available bindings.
	 *
	 * Maps human-readable binding keys to meta keys and labels.
	 *
	 * @return void
	 */
	private function define_bindings(): void {
		$this->bindings = [
			// Citation bindings.
			'cite_name'              => [
				'label'    => __( 'Citation Title', 'post-kinds-for-indieweb' ),
				'meta_key' => 'cite_name',
				'type'     => 'string',
			],
			'cite_url'               => [
				'label'    => __( 'Citation URL', 'post-kinds-for-indieweb' ),
				'meta_key' => 'cite_url',
				'type'     => 'url',
			],
			'cite_author'            => [
				'label'    => __( 'Citation Author', 'post-kinds-for-indieweb' ),
				'meta_key' => 'cite_author',
				'type'     => 'string',
			],
			'cite_author_url'        => [
				'label'    => __( 'Citation Author URL', 'post-kinds-for-indieweb' ),
				'meta_key' => 'cite_author_url',
				'type'     => 'url',
			],
			'cite_photo'             => [
				'label'    => __( 'Citation Image', 'post-kinds-for-indieweb' ),
				'meta_key' => 'cite_photo',
				'type'     => 'url',
			],
			'cite_summary'           => [
				'label'    => __( 'Citation Summary', 'post-kinds-for-indieweb' ),
				'meta_key' => 'cite_summary',
				'type'     => 'string',
			],
			'cite_published'         => [
				'label'    => __( 'Citation Date', 'post-kinds-for-indieweb' ),
				'meta_key' => 'cite_published',
				'type'     => 'date',
			],

			// RSVP bindings.
			'rsvp_status'            => [
				'label'    => __( 'RSVP Status', 'post-kinds-for-indieweb' ),
				'meta_key' => 'rsvp_status',
				'type'     => 'string',
				'format'   => 'rsvp',
			],

			// Check-in bindings.
			'checkin_name'           => [
				'label'    => __( 'Venue Name', 'post-kinds-for-indieweb' ),
				'meta_key' => 'checkin_name',
				'type'     => 'string',
			],
			'checkin_url'            => [
				'label'    => __( 'Venue URL', 'post-kinds-for-indieweb' ),
				'meta_key' => 'checkin_url',
				'type'     => 'url',
			],
			'checkin_address'        => [
				'label'    => __( 'Venue Address', 'post-kinds-for-indieweb' ),
				'meta_key' => 'checkin_address',
				'type'     => 'string',
			],
			'checkin_full_address'   => [
				'label'    => __( 'Full Address', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'full_address',
			],
			'geo_coordinates'        => [
				'label'    => __( 'Coordinates', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'coordinates',
			],

			// Listen bindings.
			'listen_track'           => [
				'label'    => __( 'Track Name', 'post-kinds-for-indieweb' ),
				'meta_key' => 'listen_track',
				'type'     => 'string',
			],
			'listen_artist'          => [
				'label'    => __( 'Artist', 'post-kinds-for-indieweb' ),
				'meta_key' => 'listen_artist',
				'type'     => 'string',
			],
			'listen_album'           => [
				'label'    => __( 'Album', 'post-kinds-for-indieweb' ),
				'meta_key' => 'listen_album',
				'type'     => 'string',
			],
			'listen_cover'           => [
				'label'    => __( 'Album Art', 'post-kinds-for-indieweb' ),
				'meta_key' => 'listen_cover',
				'type'     => 'url',
			],
			'listen_display'         => [
				'label'    => __( 'Track by Artist', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'listen_display',
			],

			// Watch bindings.
			'watch_title'            => [
				'label'    => __( 'Title', 'post-kinds-for-indieweb' ),
				'meta_key' => 'watch_title',
				'type'     => 'string',
			],
			'watch_year'             => [
				'label'    => __( 'Year', 'post-kinds-for-indieweb' ),
				'meta_key' => 'watch_year',
				'type'     => 'string',
			],
			'watch_poster'           => [
				'label'    => __( 'Poster', 'post-kinds-for-indieweb' ),
				'meta_key' => 'watch_poster',
				'type'     => 'url',
			],
			'watch_status'           => [
				'label'    => __( 'Watch Status', 'post-kinds-for-indieweb' ),
				'meta_key' => 'watch_status',
				'type'     => 'string',
				'format'   => 'watch_status',
			],
			'watch_display'          => [
				'label'    => __( 'Title (Year)', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'watch_display',
			],

			// Read bindings.
			'read_title'             => [
				'label'    => __( 'Book Title', 'post-kinds-for-indieweb' ),
				'meta_key' => 'read_title',
				'type'     => 'string',
			],
			'read_author'            => [
				'label'    => __( 'Book Author', 'post-kinds-for-indieweb' ),
				'meta_key' => 'read_author',
				'type'     => 'string',
			],
			'read_cover'             => [
				'label'    => __( 'Book Cover', 'post-kinds-for-indieweb' ),
				'meta_key' => 'read_cover',
				'type'     => 'url',
			],
			'read_isbn'              => [
				'label'    => __( 'ISBN', 'post-kinds-for-indieweb' ),
				'meta_key' => 'read_isbn',
				'type'     => 'string',
			],
			'read_status'            => [
				'label'    => __( 'Reading Status', 'post-kinds-for-indieweb' ),
				'meta_key' => 'read_status',
				'type'     => 'string',
				'format'   => 'read_status',
			],
			'read_progress_display'  => [
				'label'    => __( 'Reading Progress', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'read_progress',
			],

			// Event bindings.
			'event_start'            => [
				'label'    => __( 'Start Date/Time', 'post-kinds-for-indieweb' ),
				'meta_key' => 'event_start',
				'type'     => 'date',
			],
			'event_end'              => [
				'label'    => __( 'End Date/Time', 'post-kinds-for-indieweb' ),
				'meta_key' => 'event_end',
				'type'     => 'date',
			],
			'event_location'         => [
				'label'    => __( 'Event Location', 'post-kinds-for-indieweb' ),
				'meta_key' => 'event_location',
				'type'     => 'string',
			],
			'event_datetime_display' => [
				'label'    => __( 'Event Date/Time', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'event_datetime',
			],

			// Review bindings.
			'review_rating'          => [
				'label'    => __( 'Rating', 'post-kinds-for-indieweb' ),
				'meta_key' => 'review_rating',
				'type'     => 'number',
			],
			'review_rating_display'  => [
				'label'    => __( 'Rating Display', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'rating_display',
			],
			'review_stars'           => [
				'label'    => __( 'Star Rating', 'post-kinds-for-indieweb' ),
				'meta_key' => null, // Computed field.
				'type'     => 'computed',
				'compute'  => 'star_rating',
			],
			'review_item_name'       => [
				'label'    => __( 'Reviewed Item', 'post-kinds-for-indieweb' ),
				'meta_key' => 'review_item_name',
				'type'     => 'string',
			],
			'review_item_url'        => [
				'label'    => __( 'Reviewed Item URL', 'post-kinds-for-indieweb' ),
				'meta_key' => 'review_item_url',
				'type'     => 'url',
			],
		];

		/**
		 * Filters the block binding definitions.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, string>> $bindings Binding definitions.
		 */
		$this->bindings = apply_filters( 'post_kinds_indieweb_block_bindings', $this->bindings );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', [ $this, 'register_block_bindings_source' ] );
	}

	/**
	 * Register the block bindings source.
	 *
	 * @return void
	 */
	public function register_block_bindings_source(): void {
		// Block Bindings API requires WordPress 6.5+.
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			self::SOURCE_NAME,
			[
				'label'              => __( 'Post Kinds for IndieWeb', 'post-kinds-for-indieweb' ),
				'get_value_callback' => [ $this, 'get_binding_value' ],
				'uses_context'       => [ 'postId', 'postType' ],
			]
		);
	}

	/**
	 * Get the value for a block binding.
	 *
	 * @param array<string, mixed> $source_args    Binding source arguments.
	 * @param \WP_Block            $block_instance Block instance.
	 * @param string               $attribute_name Block attribute name being bound.
	 * @return string|null The binding value or null if not found.
	 */
	public function get_binding_value( array $source_args, \WP_Block $block_instance, string $attribute_name ): ?string {
		// Get the binding key from source args.
		$key = $source_args['key'] ?? '';

		if ( empty( $key ) || ! isset( $this->bindings[ $key ] ) ) {
			return null;
		}

		// Get the post ID from block context.
		$post_id = $block_instance->context['postId'] ?? get_the_ID();

		if ( ! $post_id ) {
			return null;
		}

		$binding = $this->bindings[ $key ];

		// Handle computed fields.
		if ( 'computed' === $binding['type'] ) {
			return $this->compute_value( $binding['compute'], $post_id );
		}

		// Get the meta value.
		$meta_key = Meta_Fields::PREFIX . $binding['meta_key'];
		$value    = get_post_meta( $post_id, $meta_key, true );

		if ( empty( $value ) ) {
			return null;
		}

		// Format the value if needed.
		if ( isset( $binding['format'] ) ) {
			$value = $this->format_value( $value, $binding['format'] );
		}

		return (string) $value;
	}

	/**
	 * Compute a dynamic value from multiple meta fields.
	 *
	 * @param string $compute_type The type of computation.
	 * @param int    $post_id      Post ID.
	 * @return string|null The computed value.
	 */
	private function compute_value( string $compute_type, int $post_id ): ?string {
		$prefix = Meta_Fields::PREFIX;

		switch ( $compute_type ) {
			case 'full_address':
				$parts = [
					get_post_meta( $post_id, $prefix . 'checkin_address', true ),
					get_post_meta( $post_id, $prefix . 'checkin_locality', true ),
					get_post_meta( $post_id, $prefix . 'checkin_region', true ),
					get_post_meta( $post_id, $prefix . 'checkin_country', true ),
				];
				$parts = array_filter( $parts );
				return ! empty( $parts ) ? implode( ', ', $parts ) : null;

			case 'coordinates':
				$lat = get_post_meta( $post_id, $prefix . 'geo_latitude', true );
				$lng = get_post_meta( $post_id, $prefix . 'geo_longitude', true );
				if ( $lat && $lng ) {
					return sprintf( '%s, %s', $lat, $lng );
				}
				return null;

			case 'listen_display':
				$track  = get_post_meta( $post_id, $prefix . 'listen_track', true );
				$artist = get_post_meta( $post_id, $prefix . 'listen_artist', true );
				if ( $track && $artist ) {
					/* translators: 1: Track name, 2: Artist name */
					return sprintf( __( '%1$s by %2$s', 'post-kinds-for-indieweb' ), $track, $artist );
				}
				return $track ?: null;

			case 'watch_display':
				$title = get_post_meta( $post_id, $prefix . 'watch_title', true );
				$year  = get_post_meta( $post_id, $prefix . 'watch_year', true );
				if ( $title && $year ) {
					return sprintf( '%s (%s)', $title, $year );
				}
				return $title ?: null;

			case 'read_progress':
				$progress = (int) get_post_meta( $post_id, $prefix . 'read_progress', true );
				$pages    = (int) get_post_meta( $post_id, $prefix . 'read_pages', true );
				$status   = get_post_meta( $post_id, $prefix . 'read_status', true );

				if ( 'finished' === $status ) {
					return __( 'Completed', 'post-kinds-for-indieweb' );
				}

				if ( $progress && $pages ) {
					/* translators: 1: Current page, 2: Total pages */
					return sprintf( __( 'Page %1$d of %2$d', 'post-kinds-for-indieweb' ), $progress, $pages );
				}

				if ( $progress ) {
					/* translators: %d: Progress percentage */
					return sprintf( __( '%d%% complete', 'post-kinds-for-indieweb' ), $progress );
				}

				return null;

			case 'event_datetime':
				$start = get_post_meta( $post_id, $prefix . 'event_start', true );
				$end   = get_post_meta( $post_id, $prefix . 'event_end', true );

				if ( ! $start ) {
					return null;
				}

				$start_date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start ) );

				if ( $end ) {
					$end_date = wp_date( get_option( 'time_format' ), strtotime( $end ) );
					/* translators: 1: Start date/time, 2: End time */
					return sprintf( __( '%1$s – %2$s', 'post-kinds-for-indieweb' ), $start_date, $end_date );
				}

				return $start_date;

			case 'rating_display':
				$rating = (float) get_post_meta( $post_id, $prefix . 'review_rating', true );
				$best   = (int) get_post_meta( $post_id, $prefix . 'review_best', true ) ?: 5;
				if ( $rating ) {
					/* translators: 1: Rating value, 2: Maximum rating */
					return sprintf( __( '%1$s out of %2$s', 'post-kinds-for-indieweb' ), $rating, $best );
				}
				return null;

			case 'star_rating':
				$rating = (float) get_post_meta( $post_id, $prefix . 'review_rating', true );
				$best   = (int) get_post_meta( $post_id, $prefix . 'review_best', true ) ?: 5;
				return $this->generate_star_rating( $rating, $best );

			default:
				return null;
		}
	}

	/**
	 * Format a value based on its type.
	 *
	 * @param mixed  $value  The value to format.
	 * @param string $format The format type.
	 * @return string Formatted value.
	 */
	private function format_value( mixed $value, string $format ): string {
		switch ( $format ) {
			case 'rsvp':
				$labels = [
					'yes'        => __( 'Yes, attending', 'post-kinds-for-indieweb' ),
					'no'         => __( 'Not attending', 'post-kinds-for-indieweb' ),
					'maybe'      => __( 'Maybe attending', 'post-kinds-for-indieweb' ),
					'interested' => __( 'Interested', 'post-kinds-for-indieweb' ),
				];
				return $labels[ $value ] ?? (string) $value;

			case 'watch_status':
				$labels = [
					'watched'   => __( 'Watched', 'post-kinds-for-indieweb' ),
					'watching'  => __( 'Currently Watching', 'post-kinds-for-indieweb' ),
					'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb' ),
				];
				return $labels[ $value ] ?? (string) $value;

			case 'read_status':
				$labels = [
					'to-read'   => __( 'To Read', 'post-kinds-for-indieweb' ),
					'reading'   => __( 'Currently Reading', 'post-kinds-for-indieweb' ),
					'finished'  => __( 'Finished', 'post-kinds-for-indieweb' ),
					'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb' ),
				];
				return $labels[ $value ] ?? (string) $value;

			default:
				return (string) $value;
		}
	}

	/**
	 * Generate star rating HTML.
	 *
	 * @param float $rating Current rating.
	 * @param int   $best   Maximum rating.
	 * @return string Star rating string.
	 */
	private function generate_star_rating( float $rating, int $best ): string {
		if ( $rating <= 0 ) {
			return '';
		}

		$full_stars  = (int) floor( $rating );
		$half_star   = ( $rating - $full_stars ) >= 0.5;
		$empty_stars = $best - $full_stars - ( $half_star ? 1 : 0 );

		$stars = str_repeat( '★', $full_stars );

		if ( $half_star ) {
			$stars .= '½';
		}

		$stars .= str_repeat( '☆', max( 0, $empty_stars ) );

		return $stars;
	}

	/**
	 * Get all available bindings.
	 *
	 * @return array<string, array<string, string>> Binding definitions.
	 */
	public function get_bindings(): array {
		return $this->bindings;
	}

	/**
	 * Get bindings formatted for the editor.
	 *
	 * @return array<string, string> Binding key => label pairs.
	 */
	public function get_bindings_for_editor(): array {
		$editor_bindings = [];

		foreach ( $this->bindings as $key => $binding ) {
			$editor_bindings[ $key ] = $binding['label'];
		}

		return $editor_bindings;
	}

	/**
	 * Check if a binding key is valid.
	 *
	 * @param string $key Binding key to check.
	 * @return bool True if valid, false otherwise.
	 */
	public function is_valid_binding( string $key ): bool {
		return isset( $this->bindings[ $key ] );
	}
}

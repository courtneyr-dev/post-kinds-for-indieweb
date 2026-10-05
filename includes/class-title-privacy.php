<?php
/**
 * Titles generated from location data follow the location's privacy.
 *
 * The check-in importers write "Checked in at <venue>" into post_title.
 * When the venue name is hidden, that title would still print it in the
 * h1, the document title, feeds, REST and every link to the post. This
 * class records which titles were generated and swaps a hidden one for
 * "Check-in, <date>" wherever WordPress prints a title.
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
	 * Hooks.
	 */
	public function __construct() {
		add_filter( 'the_title', [ $this, 'filter_the_title' ], 10, 2 );
		add_filter( 'single_post_title', [ $this, 'filter_single_post_title' ], 10, 2 );
		add_action( 'post_updated', [ $this, 'clear_marker_on_retitle' ], 10, 3 );
	}

	/**
	 * Record that a post's title was generated from location data.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function mark_location_title( int $post_id ): void {
		update_post_meta( $post_id, self::META_KEY, self::SOURCE_LOCATION );
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

<?php
/**
 * Micropub content builder — converts h-entry properties into block markup.
 *
 * The Micropub plugin (David Shanske) creates a wp_posts row whose
 * `post_content` is the literal `content` property string. Front-end render
 * is then "whatever the user typed" — no card, no map, no venue detail.
 *
 * This bridge listens on the `after_micropub` action and rewrites
 * `post_content` to use this plugin's registered card blocks (Checkin Card,
 * Eat Card, Drink Card, Listen Card, Watch Card, Read Card, Mood Card,
 * etc.) when the incoming h-entry shape matches a known post kind. The
 * user's typed body content is preserved as an `e-content` paragraph
 * inside the same h-entry group so microformats2 + block rendering both
 * work.
 *
 * Idempotent — once a post has been auto-generated, the
 * `_pkiw_block_content_generated` post meta marker is set and subsequent
 * Micropub updates leave the (potentially user-edited) post_content alone.
 *
 * @package PostKindsForIndieWeb
 * @since   1.1.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds block-editor post_content from incoming Micropub h-entry properties.
 *
 * Hook order: this class registers at `after_micropub` priority 30, deliberately
 * AFTER any Micropub bridge that writes post meta (Outpost's bridges run at
 * priority 20). That way card-block attributes can read post meta written
 * earlier in the same request — though for the initial implementation
 * everything is read directly from the `$input` properties array.
 */
final class Micropub_Content_Builder {

	/**
	 * Post meta key indicating the bridge already generated content for this post.
	 *
	 * @var string
	 */
	private const GENERATED_META_KEY = '_pkiw_block_content_generated';

	/**
	 * Hook the bridge into Micropub's post-creation flow.
	 */
	public static function register(): void {
		add_action( 'after_micropub', array( self::class, 'apply' ), 30, 2 );
	}

	/**
	 * Replace post_content with card-block markup when the incoming Micropub
	 * request matches a known post kind. Skipped when:
	 *   - the post ID is missing (request shape malformed)
	 *   - the post already has a generated marker (re-edits don't clobber)
	 *   - the h-entry doesn't match any of the recognized kinds.
	 *
	 * @param array<string, mixed> $input Original parsed Micropub request body.
	 * @param array<string, mixed> $args  wp_insert_post args including 'ID'.
	 */
	public static function apply( $input, $args ): void {
		if ( ! is_array( $input ) || ! is_array( $args ) || empty( $args['ID'] ) ) {
			return;
		}

		$post_id = (int) $args['ID'];

		// Idempotency — once we've written block content for this post, leave it alone.
		// Users who manually edit the post in Gutenberg afterwards keep their changes.
		if ( '' !== (string) get_post_meta( $post_id, self::GENERATED_META_KEY, true ) ) {
			return;
		}

		$properties = self::extract_properties( $input );
		if ( empty( $properties ) ) {
			return;
		}

		$block_content = self::build_block_content( $properties );
		if ( null === $block_content ) {
			// Post kind didn't match any of the recognized shapes — leave the
			// Micropub plugin's plain-text post_content alone.
			return;
		}

		// Replace post_content with the block markup.
		// `wp_update_post` triggers `save_post` which can recursively fire
		// `after_micropub` filters in some edge cases; mark the post as
		// generated FIRST so the recursion short-circuits at the idempotency
		// check above.
		update_post_meta( $post_id, self::GENERATED_META_KEY, '1' );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $block_content,
			)
		);
	}

	/**
	 * Extract the `properties` array from the Micropub request shape.
	 *
	 * Form-encoded Micropub puts properties at the top level of $input.
	 * JSON Micropub nests them under `properties`. Try both.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private static function extract_properties( array $input ): array {
		if ( isset( $input['properties'] ) && is_array( $input['properties'] ) ) {
			return $input['properties'];
		}
		// Form-encoded shape — properties live at the top level. Strip the
		// non-property keys (`h`, `action`, etc.).
		$out = $input;
		unset( $out['h'], $out['action'], $out['url'], $out['type'], $out['mp-syndicate-to'] );
		return $out;
	}

	/**
	 * Map the h-entry properties to a card block + content paragraph.
	 * Returns null when no recognized post kind is detected.
	 *
	 * @param array<string, mixed> $properties
	 * @return string|null Block markup ready for post_content, or null to skip.
	 */
	private static function build_block_content( array $properties ): ?string {
		$kind = self::detect_kind( $properties );
		if ( null === $kind ) {
			return null;
		}

		$card_markup = self::card_for_kind( $kind, $properties );
		if ( null === $card_markup ) {
			return null;
		}

		$body = self::flatten_scalar( $properties, 'content' );

		return self::wrap_h_entry( $card_markup, $body );
	}

	/**
	 * Identify which post kind a property bag represents.
	 *
	 * Detection order matters — eat-of/drink-of takes precedence over
	 * `location` because Eat/Drink posts may include both. The first match
	 * wins.
	 */
	private static function detect_kind( array $properties ): ?string {
		if ( self::has_property( $properties, 'eat-of' ) ) {
			return 'eat';
		}
		if ( self::has_property( $properties, 'drink-of' ) ) {
			return 'drink';
		}
		if ( self::has_property( $properties, 'listen-of' ) ) {
			return 'listen';
		}
		if ( self::has_property( $properties, 'watch-of' ) ) {
			return 'watch';
		}
		if ( self::has_property( $properties, 'read-of' ) ) {
			return 'read';
		}
		if ( self::has_property( $properties, 'play-of' ) ) {
			return 'play';
		}
		if ( self::has_property( $properties, 'rsvp' ) ) {
			return 'rsvp';
		}
		if ( self::has_property( $properties, 'mood' ) ) {
			return 'mood';
		}
		// Checkin = location property without one of the food/drink/media of-kinds.
		if ( self::has_property( $properties, 'location' ) ) {
			return 'checkin';
		}
		return null;
	}

	/**
	 * Build the card block for a given post kind. Returns null if the
	 * kind doesn't have a corresponding card block.
	 */
	private static function card_for_kind( string $kind, array $properties ): ?string {
		switch ( $kind ) {
			case 'checkin':
				return self::checkin_card( $properties );
			case 'eat':
				return self::eat_card( $properties );
			case 'drink':
				return self::drink_card( $properties );
			case 'listen':
				return self::listen_card( $properties );
			case 'watch':
				return self::watch_card( $properties );
			case 'read':
				return self::read_card( $properties );
			case 'play':
				return self::play_card( $properties );
			case 'rsvp':
				return self::rsvp_card( $properties );
			case 'mood':
				return self::mood_card( $properties );
		}
		return null;
	}

	// --- Per-kind block builders -------------------------------------------

	private static function checkin_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'venueName' => self::flatten_scalar( $properties, 'mp-place-name' ),
				'note'      => self::flatten_scalar( $properties, 'content' ),
			)
		);
		$geo = self::parse_geo_from_location( $properties );
		if ( null !== $geo ) {
			$attrs['latitude']  = $geo['lat'];
			$attrs['longitude'] = $geo['lon'];
		}
		return self::self_closing_block( 'post-kinds-indieweb/checkin-card', $attrs );
	}

	private static function eat_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'name'         => self::flatten_scalar( $properties, 'eat-of' ),
				'locationName' => self::flatten_scalar( $properties, 'mp-place-name' ),
				'rating'       => self::flatten_numeric( $properties, 'rating' ),
				'notes'        => self::flatten_scalar( $properties, 'content' ),
			)
		);
		$geo = self::parse_geo_from_location( $properties );
		if ( null !== $geo ) {
			$attrs['geoLatitude']  = $geo['lat'];
			$attrs['geoLongitude'] = $geo['lon'];
		}
		return self::self_closing_block( 'post-kinds-indieweb/eat-card', $attrs );
	}

	private static function drink_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'name'         => self::flatten_scalar( $properties, 'drink-of' ),
				'locationName' => self::flatten_scalar( $properties, 'mp-place-name' ),
				'rating'       => self::flatten_numeric( $properties, 'rating' ),
				'notes'        => self::flatten_scalar( $properties, 'content' ),
			)
		);
		$geo = self::parse_geo_from_location( $properties );
		if ( null !== $geo ) {
			$attrs['geoLatitude']  = $geo['lat'];
			$attrs['geoLongitude'] = $geo['lon'];
		}
		return self::self_closing_block( 'post-kinds-indieweb/drink-card', $attrs );
	}

	private static function listen_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'listenUrl'  => self::flatten_scalar( $properties, 'listen-of' ),
				'trackTitle' => self::flatten_scalar( $properties, 'name' ),
				'artistName' => self::flatten_scalar( $properties, 'author' ),
				'rating'     => self::flatten_numeric( $properties, 'rating' ),
			)
		);
		return self::self_closing_block( 'post-kinds-indieweb/listen-card', $attrs );
	}

	private static function watch_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'watchUrl'   => self::flatten_scalar( $properties, 'watch-of' ),
				'mediaTitle' => self::flatten_scalar( $properties, 'name' ),
				'director'   => self::flatten_scalar( $properties, 'author' ),
				'rating'     => self::flatten_numeric( $properties, 'rating' ),
				'review'     => self::flatten_scalar( $properties, 'content' ),
			)
		);
		return self::self_closing_block( 'post-kinds-indieweb/watch-card', $attrs );
	}

	private static function read_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'bookUrl'    => self::flatten_scalar( $properties, 'read-of' ),
				'bookTitle'  => self::flatten_scalar( $properties, 'name' ),
				'authorName' => self::flatten_scalar( $properties, 'author' ),
				'readStatus' => self::flatten_scalar( $properties, 'read-status' ),
				'rating'     => self::flatten_numeric( $properties, 'rating' ),
				'review'     => self::flatten_scalar( $properties, 'content' ),
			)
		);
		return self::self_closing_block( 'post-kinds-indieweb/read-card', $attrs );
	}

	private static function play_card( array $properties ): string {
		// Play-card attributes mirror Listen/Watch's URL+name pattern.
		$attrs = self::filter_empty(
			array(
				'playUrl'   => self::flatten_scalar( $properties, 'play-of' ),
				'gameTitle' => self::flatten_scalar( $properties, 'name' ),
				'rating'    => self::flatten_numeric( $properties, 'rating' ),
			)
		);
		return self::self_closing_block( 'post-kinds-indieweb/play-card', $attrs );
	}

	private static function rsvp_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'eventUrl' => self::flatten_scalar( $properties, 'in-reply-to' ),
				'response' => self::flatten_scalar( $properties, 'rsvp' ),
				'note'     => self::flatten_scalar( $properties, 'content' ),
			)
		);
		return self::self_closing_block( 'post-kinds-indieweb/rsvp-card', $attrs );
	}

	private static function mood_card( array $properties ): string {
		$attrs = self::filter_empty(
			array(
				'mood' => self::flatten_scalar( $properties, 'mood' ),
				'note' => self::flatten_scalar( $properties, 'content' ),
			)
		);
		return self::self_closing_block( 'post-kinds-indieweb/mood-card', $attrs );
	}

	// --- Block markup primitives -------------------------------------------

	/**
	 * Render a self-closing block-comment with JSON attributes.
	 *
	 * Output: `<!-- wp:namespace/name {"attr":"value"} /-->` or
	 * `<!-- wp:namespace/name /-->` when attrs is empty.
	 */
	private static function self_closing_block( string $name, array $attrs ): string {
		if ( empty( $attrs ) ) {
			return '<!-- wp:' . $name . ' /-->';
		}
		$json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return '<!-- wp:' . $name . ' /-->';
		}
		return '<!-- wp:' . $name . ' ' . $json . ' /-->';
	}

	/**
	 * Wrap a card block + optional body content in an h-entry group so
	 * microformats2 readers see one h-entry root per post and the typed
	 * body lands inside `e-content`.
	 */
	private static function wrap_h_entry( string $card_markup, string $body ): string {
		$paragraph = '';
		if ( '' !== trim( $body ) ) {
			$escaped   = wp_kses_post( $body );
			$paragraph = "\n\t<!-- wp:group {\"className\":\"e-content\"} -->\n"
				. "\t<div class=\"wp-block-group e-content\">\n"
				. "\t\t<!-- wp:paragraph -->\n"
				. "\t\t<p>" . $escaped . "</p>\n"
				. "\t\t<!-- /wp:paragraph -->\n"
				. "\t</div>\n"
				. "\t<!-- /wp:group -->";
		}

		return "<!-- wp:group {\"className\":\"h-entry\",\"layout\":{\"type\":\"constrained\"}} -->\n"
			. "<div class=\"wp-block-group h-entry\">\n"
			. "\t" . $card_markup
			. $paragraph
			. "\n</div>\n"
			. '<!-- /wp:group -->';
	}

	// --- Property helpers --------------------------------------------------

	/**
	 * Form-encoded Micropub repeats single-value properties as
	 * arrays-of-one (`['content' => ['hello']]`); JSON Micropub uses arrays
	 * for everything (`'content' => ['hello']`). Flatten to the first
	 * scalar value, regardless of shape.
	 */
	private static function flatten_scalar( array $properties, string $key ): string {
		if ( ! isset( $properties[ $key ] ) ) {
			return '';
		}
		$value = $properties[ $key ];
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * Like `flatten_scalar` but returns an int/float when the value parses
	 * as numeric, or null. Used for `rating`, `latitude`, `longitude`.
	 */
	private static function flatten_numeric( array $properties, string $key ) {
		$raw = self::flatten_scalar( $properties, $key );
		if ( '' === $raw ) {
			return null;
		}
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		return false === strpos( $raw, '.' ) ? (int) $raw : (float) $raw;
	}

	/**
	 * Whether the property bag has a non-empty value at $key.
	 */
	private static function has_property( array $properties, string $key ): bool {
		if ( ! isset( $properties[ $key ] ) ) {
			return false;
		}
		$value = $properties[ $key ];
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return null !== $value && '' !== $value;
	}

	/**
	 * Parse `geo:lat,lon[,alt]` per RFC 5870 from the `location` property.
	 * Returns ['lat' => float, 'lon' => float] or null when not parseable.
	 */
	private static function parse_geo_from_location( array $properties ): ?array {
		$location = self::flatten_scalar( $properties, 'location' );
		if ( '' === $location ) {
			return null;
		}
		// Match `geo:lat,lon` and `geo:lat,lon,alt`. Reject anything else
		// (URLs, h-card-shaped object dumps, etc. — not coordinates).
		if ( 1 !== preg_match( '/^geo:(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $location, $matches ) ) {
			return null;
		}
		return array(
			'lat' => (float) $matches[1],
			'lon' => (float) $matches[2],
		);
	}

	/**
	 * Drop empty / null entries from an attributes array. block.json render
	 * works better when only meaningful attributes are present.
	 */
	private static function filter_empty( array $attrs ): array {
		return array_filter(
			$attrs,
			static fn( $value ): bool => null !== $value && '' !== $value && false !== $value
		);
	}
}

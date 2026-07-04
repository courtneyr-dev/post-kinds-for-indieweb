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
 * etc.) when the incoming h-entry shape matches a known post kind.
 *
 * Photo / gallery posts (Micropub `photo` property without one of the
 * specific of-kinds) emit a `core/image` (single) or `core/gallery`
 * (multi) wrapper with the photos resolved back to their attached media
 * IDs. The user's typed body content is preserved as an `e-content`
 * paragraph inside the same h-entry group so microformats2 + block
 * rendering both work.
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
		add_action( 'after_micropub', [ self::class, 'apply' ], 30, 2 );
		add_filter( 'micropub_post_content', [ self::class, 'fill_empty_content' ], 20, 2 );
	}

	/**
	 * Supply card-block content BEFORE insert when the request has none.
	 *
	 * Kind posts without a `content` property (Outpost's eat/drink/follow/
	 * weather flows) previously died inside wp_insert_post() with
	 * empty_content — while the Micropub endpoint still answered 2xx with
	 * no Location ("phantom posts"). Building the card markup on the
	 * `micropub_post_content` filter makes the post non-empty at insert
	 * time; the after_micropub pass then finds content already generated.
	 *
	 * @param string               $post_content Content Micropub derived from the request.
	 * @param array<string, mixed> $input        Original parsed Micropub request body.
	 * @return string Original content, or card-block markup when it was empty.
	 */
	public static function fill_empty_content( $post_content, $input ): string {
		$post_content = (string) $post_content;
		if ( '' !== trim( $post_content ) || ! is_array( $input ) ) {
			return $post_content;
		}

		$properties = self::extract_properties( $input );
		if ( empty( $properties ) ) {
			return $post_content;
		}

		$block_content = self::build_block_content( $properties );

		return null === $block_content ? $post_content : $block_content;
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

		// Assign the kind taxonomy term before touching content — the term
		// must land even when the content path below returns early (builder
		// yields empty markup or the kind is unrecognized).
		self::assign_kind_term( $post_id, $properties );

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
			[
				'ID'           => $post_id,
				'post_content' => $block_content,
			]
		);
	}

	/**
	 * Assign the detected kind's taxonomy term to the post.
	 *
	 * The `kind` taxonomy registers `note` as its default_term, so WP core
	 * gives every Micropub-created post kind=note — the bridge knows the
	 * real kind but never wrote it, leaving posts with the wrong badge and
	 * in the wrong kind archives. `wp_set_post_terms` (via set_post_kind)
	 * replaces the default cleanly.
	 *
	 * Kinds without a registered term (follow, weather) are skipped and
	 * keep the core default; if a site later creates those terms the
	 * assignment picks them up automatically.
	 *
	 * @param int                  $post_id    Post ID created by Micropub.
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @return void
	 */
	private static function assign_kind_term( int $post_id, array $properties ): void {
		$kind = self::detect_kind( $properties ) ?? self::detect_term_only_kind( $properties );
		if ( null === $kind ) {
			return;
		}

		$taxonomy = Plugin::get_instance()->get_taxonomy();
		if ( null === $taxonomy || ! $taxonomy->is_valid_kind( $kind ) ) {
			return;
		}

		$taxonomy->set_post_kind( $post_id, $kind );
	}

	/**
	 * Detect kinds that have taxonomy terms but no card builder.
	 *
	 * Historical fallback: like/reply/repost/bookmark now have card
	 * builders, so detect_kind() recognizes them first and this pass is
	 * dead code for those kinds — kept as a safety net should a kind ever
	 * lose its builder. Inferring `reply` from `in-reply-to` is safe here
	 * because detect_kind() has already returned null, so no `rsvp`
	 * property is present.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @return string|null Kind slug or null when nothing matches.
	 */
	private static function detect_term_only_kind( array $properties ): ?string {
		if ( self::has_property( $properties, 'like-of' ) ) {
			return 'like';
		}
		if ( self::has_property( $properties, 'repost-of' ) ) {
			return 'repost';
		}
		if ( self::has_property( $properties, 'bookmark-of' ) ) {
			return 'bookmark';
		}
		if ( self::has_property( $properties, 'in-reply-to' ) ) {
			return 'reply';
		}
		return null;
	}

	/**
	 * Extract the `properties` array from the Micropub request shape.
	 *
	 * Form-encoded Micropub puts properties at the top level of $input.
	 * JSON Micropub nests them under `properties`. Try both.
	 *
	 * @param array<string, mixed> $input Raw Micropub request body parsed to an array.
	 * @return array<string, mixed> The h-entry properties bag.
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
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @return string|null Block markup ready for post_content, or null to skip.
	 */
	private static function build_block_content( array $properties ): ?string {
		$kind = self::detect_kind( $properties );
		if ( null === $kind ) {
			return null;
		}

		$card_markup = self::card_for_kind( $kind, $properties );
		if ( null === $card_markup || '' === $card_markup ) {
			return null;
		}

		// Kinds other than pure photo posts can still carry attached photos
		// (issue #38: a Checkin with a photo dropped the image entirely).
		// Append the image/gallery markup after the kind's card.
		if ( 'photo' !== $kind && self::has_property( $properties, 'photo' ) ) {
			$photo_markup = self::photo_card( $properties );
			if ( '' !== $photo_markup ) {
				$card_markup .= "\n\n\t" . $photo_markup;
			}
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
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @return string|null Kind slug ('eat'|'drink'|'listen'|...) or null when no kind matches.
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
		if ( self::has_property( $properties, 'like-of' ) ) {
			return 'like';
		}
		if ( self::has_property( $properties, 'repost-of' ) ) {
			return 'repost';
		}
		if ( self::has_property( $properties, 'bookmark-of' ) ) {
			return 'bookmark';
		}
		// Reply must be checked AFTER rsvp — RSVP posts carry `in-reply-to`
		// as the event URL, so a reply match here means no rsvp was present.
		if ( self::has_property( $properties, 'in-reply-to' ) ) {
			return 'reply';
		}
		if ( self::has_property( $properties, 'follow-of' ) ) {
			return 'follow';
		}
		if ( self::has_property( $properties, 'mood' ) ) {
			return 'mood';
		}
		if ( self::has_property( $properties, 'weather' ) ) {
			return 'weather';
		}
		// Checkin = location property without one of the food/drink/media of-kinds.
		if ( self::has_property( $properties, 'location' ) ) {
			return 'checkin';
		}
		// Photo / gallery = `photo` property without any of the of-kinds above.
		// Single-photo and multi-photo posts both route here; photo_card()
		// emits a single core/image or a core/gallery accordingly.
		if ( self::has_property( $properties, 'photo' ) ) {
			return 'photo';
		}
		return null;
	}

	/**
	 * Build the card block for a given post kind. Returns null if the
	 * kind doesn't have a corresponding card block.
	 *
	 * @param string               $kind       Post-kind slug as returned by detect_kind().
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @return string|null Block-comment markup for the card, or null when the kind has no card.
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
			case 'like':
				return self::like_card( $properties );
			case 'repost':
				return self::repost_card( $properties );
			case 'bookmark':
				return self::bookmark_card( $properties );
			case 'reply':
				return self::reply_card( $properties );
			case 'mood':
				return self::mood_card( $properties );
			case 'follow':
				return self::follow_paragraph( $properties );
			case 'weather':
				return self::weather_paragraph( $properties );
			case 'photo':
				return self::photo_card( $properties );
		}
		return null;
	}

	// --- Per-kind block builders -------------------------------------------

	/**
	 * Build a Checkin Card block from the h-entry property bag.
	 *
	 * Recognizes Outpost's `mp-place-name` extension as the venue name and
	 * parses `geo:lat,lon` URIs from `location` into latitude/longitude
	 * attributes.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @return string Block-comment markup for the checkin-card block.
	 */
	private static function checkin_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'venueName' => self::flatten_scalar( $properties, 'mp-place-name' ),
				'note'      => self::flatten_scalar( $properties, 'content' ),
			]
		);
		$geo   = self::parse_geo_from_location( $properties );
		if ( null !== $geo ) {
			$attrs['latitude']  = $geo['lat'];
			$attrs['longitude'] = $geo['lon'];
		}
		return self::self_closing_block( 'post-kinds-indieweb/checkin-card', $attrs );
	}

	/**
	 * Build an Eat Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `eat-of`, `mp-place-name`, `rating`, `content`, `location`).
	 * @return string Block-comment markup for the eat-card block.
	 */
	private static function eat_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'name'         => self::flatten_scalar( $properties, 'eat-of' ),
				'locationName' => self::flatten_scalar( $properties, 'mp-place-name' ),
				'rating'       => self::flatten_numeric( $properties, 'rating' ),
				'notes'        => self::flatten_scalar( $properties, 'content' ),
			]
		);
		$geo   = self::parse_geo_from_location( $properties );
		if ( null !== $geo ) {
			$attrs['geoLatitude']  = $geo['lat'];
			$attrs['geoLongitude'] = $geo['lon'];
		}
		return self::self_closing_block( 'post-kinds-indieweb/eat-card', $attrs );
	}

	/**
	 * Build a Drink Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `drink-of`, `mp-place-name`, `rating`, `content`, `location`).
	 * @return string Block-comment markup for the drink-card block.
	 */
	private static function drink_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'name'         => self::flatten_scalar( $properties, 'drink-of' ),
				'locationName' => self::flatten_scalar( $properties, 'mp-place-name' ),
				'rating'       => self::flatten_numeric( $properties, 'rating' ),
				'notes'        => self::flatten_scalar( $properties, 'content' ),
			]
		);
		$geo   = self::parse_geo_from_location( $properties );
		if ( null !== $geo ) {
			$attrs['geoLatitude']  = $geo['lat'];
			$attrs['geoLongitude'] = $geo['lon'];
		}
		return self::self_closing_block( 'post-kinds-indieweb/drink-card', $attrs );
	}

	/**
	 * Build a Listen Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `listen-of`, `name`, `author`, `rating`).
	 * @return string Block-comment markup for the listen-card block.
	 */
	private static function listen_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'listenUrl'  => self::flatten_scalar( $properties, 'listen-of' ),
				'trackTitle' => self::flatten_scalar( $properties, 'name' ),
				'artistName' => self::flatten_scalar( $properties, 'author' ),
				'rating'     => self::flatten_numeric( $properties, 'rating' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/listen-card', $attrs );
	}

	/**
	 * Build a Watch Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `watch-of`, `name`, `author`, `rating`, `content`).
	 * @return string Block-comment markup for the watch-card block.
	 */
	private static function watch_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'watchUrl'   => self::flatten_scalar( $properties, 'watch-of' ),
				'mediaTitle' => self::flatten_scalar( $properties, 'name' ),
				'director'   => self::flatten_scalar( $properties, 'author' ),
				'rating'     => self::flatten_numeric( $properties, 'rating' ),
				'review'     => self::flatten_scalar( $properties, 'content' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/watch-card', $attrs );
	}

	/**
	 * Build a Read Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `read-of`, `name`, `author`, `read-status`, `rating`, `content`).
	 * @return string Block-comment markup for the read-card block.
	 */
	private static function read_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'bookUrl'    => self::flatten_scalar( $properties, 'read-of' ),
				'bookTitle'  => self::flatten_scalar( $properties, 'name' ),
				'authorName' => self::flatten_scalar( $properties, 'author' ),
				'readStatus' => self::flatten_scalar( $properties, 'read-status' ),
				'rating'     => self::flatten_numeric( $properties, 'rating' ),
				'review'     => self::flatten_scalar( $properties, 'content' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/read-card', $attrs );
	}

	/**
	 * Build a Play Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `play-of`, `name`, `rating`).
	 * @return string Block-comment markup for the play-card block.
	 */
	private static function play_card( array $properties ): string {
		// Play-card attributes mirror Listen/Watch's URL+name pattern.
		$attrs = self::filter_empty(
			[
				'gameUrl' => self::flatten_scalar( $properties, 'play-of' ),
				'title'   => self::flatten_scalar( $properties, 'name' ),
				'rating'  => self::flatten_numeric( $properties, 'rating' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/play-card', $attrs );
	}

	/**
	 * Build an RSVP Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `in-reply-to`, `rsvp`, `content`).
	 * @return string Block-comment markup for the rsvp-card block.
	 */
	private static function rsvp_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'eventUrl'   => self::flatten_scalar( $properties, 'in-reply-to' ),
				'rsvpStatus' => self::flatten_scalar( $properties, 'rsvp' ),
				'rsvpNote'   => self::flatten_scalar( $properties, 'content' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/rsvp-card', $attrs );
	}

	/**
	 * Build a Like Card block from the h-entry property bag.
	 *
	 * Contentless likes (Outpost sends just `like-of`) fall back to the
	 * liked URL as the card title — the like-card render only links the
	 * title, so without the fallback a URL-only like would render a bare
	 * "Liked" badge with no `u-like-of` link for microformats2 parsers.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `like-of`, `name`, `author`, `content`).
	 * @return string Block-comment markup for the like-card block.
	 */
	private static function like_card( array $properties ): string {
		$url   = self::flatten_scalar( $properties, 'like-of' );
		$title = self::flatten_scalar( $properties, 'name' );
		$attrs = self::filter_empty(
			[
				'title'       => '' !== $title ? $title : $url,
				'url'         => $url,
				'author'      => self::flatten_scalar( $properties, 'author' ),
				'description' => self::flatten_scalar( $properties, 'content' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/like-card', $attrs );
	}

	/**
	 * Build a Repost Card block from the h-entry property bag.
	 *
	 * Contentless reposts fall back to the reposted URL as the card
	 * title — the repost-card render only links the title, so without
	 * the fallback a URL-only repost would render a bare "Reposted"
	 * badge with no `u-repost-of` link for microformats2 parsers.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `repost-of`, `name`, `author`, `content`).
	 * @return string Block-comment markup for the repost-card block.
	 */
	private static function repost_card( array $properties ): string {
		$url   = self::flatten_scalar( $properties, 'repost-of' );
		$title = self::flatten_scalar( $properties, 'name' );
		$attrs = self::filter_empty(
			[
				'title'       => '' !== $title ? $title : $url,
				'url'         => $url,
				'author'      => self::flatten_scalar( $properties, 'author' ),
				'description' => self::flatten_scalar( $properties, 'content' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/repost-card', $attrs );
	}

	/**
	 * Build a Bookmark Card block from the h-entry property bag.
	 *
	 * The `name` property, when present, becomes the card title;
	 * contentless bookmarks fall back to the bookmarked URL as the
	 * title so the `u-bookmark-of` link always renders.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `bookmark-of`, `name`, `author`, `content`).
	 * @return string Block-comment markup for the bookmark-card block.
	 */
	private static function bookmark_card( array $properties ): string {
		$url   = self::flatten_scalar( $properties, 'bookmark-of' );
		$title = self::flatten_scalar( $properties, 'name' );
		$attrs = self::filter_empty(
			[
				'title'       => '' !== $title ? $title : $url,
				'url'         => $url,
				'author'      => self::flatten_scalar( $properties, 'author' ),
				'description' => self::flatten_scalar( $properties, 'content' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/bookmark-card', $attrs );
	}

	/**
	 * Build a Reply Card block from the h-entry property bag.
	 *
	 * Unlike like/repost/bookmark, `content` is NOT mapped to the card's
	 * description — the card is an h-cite of the post being replied to,
	 * and the reply body is the author's own words, not the cited
	 * post's. The body lands in the e-content paragraph appended by
	 * wrap_h_entry().
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `in-reply-to`, `name`, `author`).
	 * @return string Block-comment markup for the reply-card block.
	 */
	private static function reply_card( array $properties ): string {
		$url   = self::flatten_scalar( $properties, 'in-reply-to' );
		$title = self::flatten_scalar( $properties, 'name' );
		$attrs = self::filter_empty(
			[
				'title'  => '' !== $title ? $title : $url,
				'url'    => $url,
				'author' => self::flatten_scalar( $properties, 'author' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/reply-card', $attrs );
	}

	/**
	 * Build a Gallery block (or single Image block) from the photo + alt
	 * arrays in the Micropub property bag.
	 *
	 * Single-photo posts emit one `core/image` block. Multi-photo posts
	 * emit a `core/gallery` containing per-photo `core/image` children.
	 * The Micropub plugin has already sideloaded each photo URL and
	 * attached the resulting media to the post; `attachment_url_to_postid()`
	 * resolves each URL back to the attachment ID so the block carries
	 * the canonical reference (and `class="wp-image-{id}"` lets the
	 * front-end pick the right responsive srcset).
	 *
	 * Alt text comes from the parallel `mp-photo-alt[]` array sent by
	 * Outpost (and any other Micropub client that opts into the
	 * convention). Outpost's F3 bridge has already written the same
	 * values to `_wp_attachment_image_alt` post meta on each attachment;
	 * we use the request-side array directly so the bridge works even
	 * when F3 isn't installed.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `photo`, `mp-photo-alt`).
	 * @return string Block-comment markup for one image or a gallery wrapper.
	 */
	private static function photo_card( array $properties ): string {
		$photo_urls = self::flatten_string_array( $properties, 'photo' );
		$alt_texts  = self::flatten_string_array( $properties, 'mp-photo-alt' );
		if ( empty( $photo_urls ) ) {
			return '';
		}

		// Deduplicate photo URLs while preserving alt-text alignment.
		// The upstream Micropub plugin (David Shanske's `wordpress-micropub`)
		// enriches `$input['photo']` post-sideload — when an Outpost
		// gallery uploads 3 images and posts them as `photo[]=url1&...`,
		// the plugin's processing produces a 6-entry array (the original
		// URLs alongside the attached / canonical URLs, which on a
		// single-server install resolve to the SAME local URL). Without
		// dedupe, a 3-photo gallery rendered 6 image blocks: the first
		// 3 with matching alts, the last 3 with empty alts because the
		// `mp-photo-alt[]` array still had only 3 entries.
		//
		// Dedupe by URL — first occurrence wins (the occurrence that
		// also has its aligned alt-text entry).
		$seen         = [];
		$unique_urls  = [];
		$aligned_alts = [];
		foreach ( $photo_urls as $i => $url ) {
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ]   = true;
			$unique_urls[]  = $url;
			$aligned_alts[] = isset( $alt_texts[ $i ] ) ? $alt_texts[ $i ] : '';
		}
		if ( empty( $unique_urls ) ) {
			return '';
		}

		$image_blocks = [];
		foreach ( $unique_urls as $i => $url ) {
			$attachment_id  = function_exists( 'attachment_url_to_postid' )
				? (int) attachment_url_to_postid( $url )
				: 0;
			$alt            = $aligned_alts[ $i ];
			$image_blocks[] = self::image_block( $attachment_id, $url, $alt );
		}

		// Single-photo posts skip the gallery wrapper for a cleaner shape.
		if ( 1 === count( $image_blocks ) ) {
			return $image_blocks[0];
		}

		return self::gallery_block_wrapper( $image_blocks );
	}

	/**
	 * Render a single `core/image` block with the canonical Gutenberg shape.
	 *
	 * @param int    $attachment_id Resolved attachment ID, or 0 when the URL didn't resolve.
	 * @param string $url           Image src URL.
	 * @param string $alt           Alt text; rendered as `alt="..."` on the img tag.
	 * @return string Block-comment markup for the image.
	 */
	private static function image_block( int $attachment_id, string $url, string $alt ): string {
		$attrs = [
			'sizeSlug'        => 'full',
			'linkDestination' => 'none',
		];
		if ( $attachment_id > 0 ) {
			$attrs['id'] = $attachment_id;
		}
		$attrs_json = wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES );
		if ( false === $attrs_json ) {
			$attrs_json = '{}';
		}
		$img_class = $attachment_id > 0
			? ' class="wp-image-' . (int) $attachment_id . '"'
			: '';
		return '<!-- wp:image ' . $attrs_json . ' -->' . "\n"
			. '<figure class="wp-block-image size-full">'
			. '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . $img_class . '/>'
			. '</figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Wrap multiple `core/image` blocks in a `core/gallery`.
	 *
	 * Uses `linkTo: none` so clicking a gallery image doesn't open a
	 * file URL — for blog posts the post body is the destination, not
	 * the bare media file.
	 *
	 * @param string[] $image_blocks Pre-rendered `core/image` block markup strings.
	 * @return string Block-comment markup for the gallery wrapper.
	 */
	private static function gallery_block_wrapper( array $image_blocks ): string {
		$children = implode( "\n\n", $image_blocks );
		return '<!-- wp:gallery {"linkTo":"none"} -->' . "\n"
			. '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . "\n"
			. $children . "\n"
			. '</figure>' . "\n"
			. '<!-- /wp:gallery -->';
	}

	/**
	 * Build a Mood Card block from the h-entry property bag.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `mood`, `content`).
	 * @return string Block-comment markup for the mood-card block.
	 */
	private static function mood_card( array $properties ): string {
		$attrs = self::filter_empty(
			[
				'mood' => self::flatten_scalar( $properties, 'mood' ),
				'note' => self::flatten_scalar( $properties, 'content' ),
			]
		);
		return self::self_closing_block( 'post-kinds-indieweb/mood-card', $attrs );
	}

	/**
	 * Build a follow paragraph from the h-entry property bag.
	 *
	 * No dedicated card block exists for follows; emit a paragraph whose
	 * link carries the `u-follow-of` microformats2 class so parsers see
	 * the follow target.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `follow-of`).
	 * @return string Block-comment markup for the follow paragraph.
	 */
	private static function follow_paragraph( array $properties ): string {
		$url = self::flatten_scalar( $properties, 'follow-of' );
		if ( '' === $url ) {
			return '';
		}
		return '<!-- wp:paragraph -->' . "\n"
			. '<p>' . esc_html__( 'Followed', 'post-kinds-for-indieweb' )
			. ' <a class="u-follow-of" href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>' . "\n"
			. '<!-- /wp:paragraph -->';
	}

	/**
	 * Build a weather paragraph from the h-entry property bag.
	 *
	 * No dedicated card block exists for weather; emit a paragraph whose
	 * reading carries the `p-weather` microformats2 class.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag (uses `weather`).
	 * @return string Block-comment markup for the weather paragraph.
	 */
	private static function weather_paragraph( array $properties ): string {
		$reading = self::flatten_scalar( $properties, 'weather' );
		if ( '' === $reading ) {
			return '';
		}
		return '<!-- wp:paragraph -->' . "\n"
			. '<p><span class="p-weather">' . esc_html( $reading ) . '</span></p>' . "\n"
			. '<!-- /wp:paragraph -->';
	}

	// --- Block markup primitives -------------------------------------------

	/**
	 * Render a self-closing block-comment with JSON attributes.
	 *
	 * Output: `<!-- wp:namespace/name {"attr":"value"} /-->` or
	 * `<!-- wp:namespace/name /-->` when attrs is empty.
	 *
	 * @param string               $name  Block name including namespace, e.g. `post-kinds-indieweb/checkin-card`.
	 * @param array<string, mixed> $attrs Block attributes encoded as JSON in the comment.
	 * @return string Block-comment markup.
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
	 *
	 * @param string $card_markup Block-comment markup produced by one of the *_card builders.
	 * @param string $body        User's typed body content; rendered inside an `e-content` paragraph when non-empty.
	 * @return string Composed block markup ready for post_content.
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
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @param string               $key        Property name to read.
	 * @return string The first scalar value found, or '' when missing/non-scalar.
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
	 * Flatten a property to a list of strings. Used for properties that
	 * naturally repeat (`photo[]`, `mp-photo-alt[]`).
	 *
	 * Form-encoded Micropub repeats values as `['photo' => ['url1','url2']]`;
	 * JSON Micropub uses the same shape. A scalar string is treated as a
	 * single-element list. Non-scalar entries become empty strings so
	 * the index alignment between `photo` and `mp-photo-alt` is preserved.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @param string               $key        Property name to read.
	 * @return string[] List of strings (possibly with empty entries for non-scalars).
	 */
	private static function flatten_string_array( array $properties, string $key ): array {
		if ( ! isset( $properties[ $key ] ) ) {
			return [];
		}
		$value = $properties[ $key ];
		if ( is_string( $value ) ) {
			return [ $value ];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $entry ) {
			$out[] = is_string( $entry ) ? $entry : '';
		}
		return $out;
	}

	/**
	 * Like `flatten_scalar` but returns an int/float when the value parses
	 * as numeric, or null. Used for `rating`, `latitude`, `longitude`.
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @param string               $key        Property name to read.
	 * @return int|float|null Integer for whole numbers, float when a decimal point is present, null when missing/non-numeric.
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
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @param string               $key        Property name to test.
	 * @return bool True when the property exists and the first value is non-empty.
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
	 *
	 * @param array<string, mixed> $properties h-entry properties bag.
	 * @return array{lat: float, lon: float}|null Parsed coordinates or null when location is missing or not a geo URI.
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
		return [
			'lat' => (float) $matches[1],
			'lon' => (float) $matches[2],
		];
	}

	/**
	 * Drop empty / null entries from an attributes array. block.json render
	 * works better when only meaningful attributes are present.
	 *
	 * @param array<string, mixed> $attrs Attribute candidates.
	 * @return array<string, mixed> Subset of $attrs whose values are not null, empty string, or false.
	 */
	private static function filter_empty( array $attrs ): array {
		return array_filter(
			$attrs,
			static fn( $value ): bool => null !== $value && '' !== $value && false !== $value
		);
	}
}

<?php
/**
 * Standard.site document enrichment for Post Kinds.
 *
 * Hooks ATmosphere's `atmosphere_transform_document` filter — which the
 * `?atproto` preview shares with the publish path, so preview and written
 * records always agree — and only fills gaps ATmosphere cannot: a derived
 * title for intentionally untitled kinds, and the kind slug as a tag so a
 * listen, review, or check-in stays discoverable as such. Fields
 * ATmosphere already maps from native WordPress data (description,
 * textContent, coverImage, path, timestamps) are never replaced, with one
 * cut: ATmosphere's excerpt reads raw post_content, where an acquisition
 * card saved before issue 239 holds its cost as text and an RSVP card
 * saved before #32 holds its event location (issue 251). That excerpt,
 * and only that span, is rebuilt without the private text in the document
 * description, the Bluesky link card's description and the Bluesky post
 * text. The document's content is rebuilt too when the private text
 * changes what ATmosphere's parser makes of post_content, as Markpub's
 * does.
 *
 * ATmosphere's post crons run with the post they publish as the global
 * post, as a front-end render and ATmosphere's own content parser have
 * it. Its textContent and Bluesky text filter the_content with no global
 * post, so a card that reads get_the_ID() can't tell which post it's on.
 *
 * @package PKIW
 * @since   1.6.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use Atmosphere\Content_Parser\Content_Parser;
use Atmosphere\Content_Parser\Registry;
use Atmosphere\Transformer\Document;
use Atmosphere\Transformer\Post as Post_Record;
use PKIW\Meta_Fields;
use PKIW\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document-record enrichment.
 *
 * Deliberately not mapped, and why:
 * - `links`: the lexicon's links union has no interoperable members yet;
 *   a private shape would only look complete. Kind subject URLs already
 *   ride in the rendered card content.
 * - `description`: ATmosphere's excerpt mapping stands. Cited-page
 *   summaries (`_pkiw_cite_summary`) are third-party text and do not
 *   belong in a first-party record field.
 * - `contributors`: requires verified author DIDs, which WordPress users
 *   do not have.
 *
 * @since 1.6.0
 */
class Atmosphere_Document_Map {

	/**
	 * ATmosphere's cron hooks whose callbacks publish or update a post.
	 *
	 * @var string[]
	 */
	private const POST_CRONS = [ 'atmosphere_publish_post', 'atmosphere_update_post', 'atmosphere_delete_post' ];

	/**
	 * Global posts use_cron_post() replaced, most recent last.
	 *
	 * @var array<int, \WP_Post|null>
	 */
	private array $previous_posts = [];

	/**
	 * Meta that hides an RSVP's location: its own setting, and the post's
	 * location privacy, which rsvp_location_visible() lets win.
	 *
	 * @var string[]
	 */
	private const PRIVACY_META_KEYS = [ '_pkiw_rsvp_location_privacy', '_pkiw_geo_privacy' ];

	/**
	 * Meta-change hooks queue_update_on_privacy_change() runs on.
	 *
	 * @var string[]
	 */
	private const META_HOOKS = [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ];

	/**
	 * Register the record filter.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'atmosphere_transform_document', [ $this, 'enrich' ], 10, 2 );
		add_filter( 'atmosphere_post_embed', [ $this, 'embed_without_private_cost' ], 10, 2 );
		add_filter( 'atmosphere_transform_bsky_post', [ $this, 'bsky_post_without_private_cost' ], 10, 2 );
		foreach ( self::POST_CRONS as $hook ) {
			add_action( $hook, [ $this, 'use_cron_post' ], 9 );
			add_action( $hook, [ $this, 'restore_cron_post' ], 11 );
		}
		foreach ( self::META_HOOKS as $hook ) {
			add_action( $hook, [ $this, 'queue_update_on_privacy_change' ], 10, 3 );
		}
	}

	/**
	 * Remove the record filter.
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'atmosphere_transform_document', [ $this, 'enrich' ], 10 );
		remove_filter( 'atmosphere_post_embed', [ $this, 'embed_without_private_cost' ], 10 );
		remove_filter( 'atmosphere_transform_bsky_post', [ $this, 'bsky_post_without_private_cost' ], 10 );
		foreach ( self::POST_CRONS as $hook ) {
			remove_action( $hook, [ $this, 'use_cron_post' ], 9 );
			remove_action( $hook, [ $this, 'restore_cron_post' ], 11 );
		}
		foreach ( self::META_HOOKS as $hook ) {
			remove_action( $hook, [ $this, 'queue_update_on_privacy_change' ], 10 );
		}
	}

	/**
	 * Queue ATmosphere's update for a shared post when meta that hides an
	 * RSVP's location changes (issue 251). ATmosphere queues one on a
	 * status transition and, for meta, only on its own share keys
	 * (Atmosphere::on_share_meta_changed()), so a write with no save, such
	 * as the update-post-meta ability's, left the record with the location.
	 * This takes the gates and the cron on_share_meta_changed() uses:
	 * connected, auto-publish on, and atmosphere_update_post once for the
	 * post. Only a post with records ATmosphere published, by the keys its
	 * has_post_records() reads, queues one, because the update would share
	 * a post it never shared.
	 *
	 * @param int|int[] $meta_id  Meta row ID or IDs; unused.
	 * @param int       $post_id  Post the meta belongs to.
	 * @param string    $meta_key Meta key that changed.
	 * @return void
	 */
	public function queue_update_on_privacy_change( $meta_id, $post_id, $meta_key ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! in_array( $meta_key, self::PRIVACY_META_KEYS, true ) || ! function_exists( '\Atmosphere\is_auto_publish_enabled' )
			|| ! \Atmosphere\is_connected() || ! \Atmosphere\is_auto_publish_enabled() ) {
			return;
		}

		$post_id = (int) $post_id;
		$shared  = false;
		foreach ( [ Post_Record::META_TID, Post_Record::META_URI, Post_Record::META_THREAD_RECORDS, Document::META_URI ] as $key ) {
			$shared = $shared || ! empty( get_post_meta( $post_id, $key, true ) );
		}

		if ( $shared && ! wp_next_scheduled( 'atmosphere_update_post', [ $post_id ] ) ) {
			wp_schedule_single_event( time(), 'atmosphere_update_post', [ $post_id ] );
		}
	}

	/**
	 * Make the post an ATmosphere cron publishes the global post while
	 * ATmosphere's callback runs at priority 10 (issue 251). With none set,
	 * get_the_ID() gave the Event Card 0, so a plain Event post lost its
	 * location from textContent and the Bluesky text.
	 *
	 * @param int $post_id Post the cron publishes.
	 * @return void
	 */
	public function use_cron_post( $post_id ): void {
		$this->previous_posts[] = $GLOBALS['post'] ?? null;
		$post                   = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored by restore_cron_post().
		}
	}

	/**
	 * Put back the global post use_cron_post() replaced.
	 *
	 * @return void
	 */
	public function restore_cron_post(): void {
		if ( [] === $this->previous_posts ) {
			return;
		}

		$previous = array_pop( $this->previous_posts );
		if ( $previous instanceof \WP_Post ) {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post.
		} else {
			unset( $GLOBALS['post'] );
		}
	}

	/**
	 * Enrich a site.standard.document record with Post Kind knowledge.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $record The document record.
	 * @param \WP_Post             $post   The post being transformed.
	 * @return array<string, mixed> The enriched record.
	 */
	public function enrich( $record, $post ): array {
		if ( ! is_array( $record ) || ! $post instanceof \WP_Post ) {
			return is_array( $record ) ? $record : [];
		}

		if ( isset( $record['description'] ) && is_string( $record['description'] ) ) {
			$record['description'] = self::clean_excerpt( $record['description'], self::excerpt_pairs( $post ) ) ?? $record['description'];
			if ( '' === $record['description'] ) {
				unset( $record['description'] );
			}
		}

		$record = self::content_without_private_text( $record, $post );

		if ( empty( $record['title'] ) ) {
			$derived = Atmosphere_Titles::derive( $post );

			if ( '' !== $derived ) {
				$record['title'] = $derived;
			}
		}

		$kind = $this->kind_slug( $post );
		if ( null !== $kind ) {
			$record = $this->append_kind_tag( $record, $post, $kind );
		}

		return $record;
	}

	/**
	 * $record with its content parsed again from post_content without
	 * private card text (Meta_Fields::strip_private_card_text()), when that
	 * text changes what the parser makes of it. ATmosphere's Markpub parser
	 * turns a block it doesn't know into markdown from the block's saved
	 * HTML (content-parser/class-markpub.php:321-328 in checkout bf8e267),
	 * so an acquisition card saved before issue 239 hands it the cost and an
	 * RSVP card saved before #32 its location. The HTML parser reads the
	 * rendered page, and Leaflet and Pckt skip the cards, so their output
	 * doesn't change and their content stays as it is. Content whose parser
	 * isn't registered, or that holds nothing once the private text is out,
	 * goes.
	 *
	 * @param array<string, mixed> $record The document record.
	 * @param \WP_Post             $post   The post.
	 * @return array<string, mixed>
	 */
	private static function content_without_private_text( array $record, \WP_Post $post ): array {
		$type = is_array( $record['content'] ?? null ) ? ( $record['content']['$type'] ?? null ) : null;
		if ( ! is_string( $type ) ) {
			return $record;
		}

		$content  = (string) $post->post_content;
		$stripped = Meta_Fields::strip_private_card_text( $content, (int) $post->ID );
		if ( $stripped === $content ) {
			return $record;
		}

		$parsers = class_exists( Registry::class ) ? Registry::all() : [];
		$parser  = $parsers[ $type ] ?? null;
		$clean   = $parser instanceof Content_Parser ? $parser->parse( $stripped, $post ) : null;
		if ( ! is_array( $clean ) || ( $clean['$type'] ?? null ) !== $type ) {
			unset( $record['content'] );
		} elseif ( $clean !== $parser->parse( $content, $post ) ) {
			$record['content'] = $clean;
		}

		return $record;
	}

	/**
	 * Append the kind slug to the record's tags.
	 *
	 * @since 1.6.0
	 *
	 * @param array<string, mixed> $record The document record.
	 * @param \WP_Post             $post   The post.
	 * @param string               $kind   Kind slug.
	 * @return array<string, mixed>
	 */
	private function append_kind_tag( array $record, \WP_Post $post, string $kind ): array {
		/**
		 * Filters the kind tag added to a Standard.site document record.
		 *
		 * Return null or '' to add no kind tag for this post.
		 *
		 * @since 1.6.0
		 *
		 * @param string|null $tag  Tag to add. Default: the kind slug.
		 * @param \WP_Post    $post The post.
		 * @param string      $kind The post's kind slug.
		 */
		$tag = apply_filters( 'pkiw_atmosphere_document_kind_tag', $kind, $post, $kind );

		if ( ! is_string( $tag ) || '' === $tag ) {
			return $record;
		}

		$tags = isset( $record['tags'] ) && is_array( $record['tags'] ) ? $record['tags'] : [];

		$existing = array_map(
			static fn( $value ): string => is_string( $value ) ? strtolower( $value ) : '',
			$tags
		);

		if ( ! in_array( strtolower( $tag ), $existing, true ) ) {
			$tags[] = $tag;
		}

		if ( ! empty( $tags ) ) {
			$record['tags'] = $tags;
		}

		return $record;
	}

	/**
	 * ATmosphere's excerpt word counts: 30 for the Bluesky post text, 55 for
	 * the document description and the link card (transformer/class-post.php:798
	 * and :1325, class-document.php:129 in checkout bf8e267).
	 */
	private const EXCERPT_WORDS = [ 30, 55 ];

	/**
	 * What wp_trim_words() and ATmosphere's truncate_text() end a cut with.
	 */
	private const MORE = '...';

	/**
	 * ATmosphere's Bluesky post length limit, in graphemes.
	 */
	private const BLUESKY_MAX_GRAPHEMES = 300;

	/**
	 * Take private acquisition cost and RSVP location out of a Bluesky link
	 * card, whose description is ATmosphere's 55-word excerpt.
	 *
	 * @param mixed $embed The embed record, or null.
	 * @param mixed $post  The post being transformed.
	 * @return mixed
	 */
	public function embed_without_private_cost( $embed, $post ) {
		if ( ! is_array( $embed ) || ! $post instanceof \WP_Post || ! is_string( $embed['external']['description'] ?? null ) ) {
			return $embed;
		}

		$clean = self::clean_excerpt( $embed['external']['description'], self::excerpt_pairs( $post ) );
		if ( null !== $clean ) {
			$embed['external']['description'] = $clean;
		}

		return $embed;
	}

	/**
	 * Take private acquisition cost and RSVP location out of a Bluesky
	 * post's text, which ATmosphere joins with blank lines from the title,
	 * its 30-word excerpt (cut short with '...' when the post runs past 300
	 * graphemes) and the
	 * permalink. Only the excerpt changes, and the post gets no longer than
	 * the limit allows. Facets before the change keep their byte ranges,
	 * facets after it move with the text, and a facet inside it is dropped.
	 *
	 * @param mixed $record The app.bsky.feed.post record.
	 * @param mixed $post   The post being transformed.
	 * @return mixed
	 */
	public function bsky_post_without_private_cost( $record, $post ) {
		if ( ! is_array( $record ) || ! $post instanceof \WP_Post || ! is_string( $record['text'] ?? null ) ) {
			return $record;
		}

		$pairs = self::excerpt_pairs( $post );
		if ( [] === $pairs ) {
			return $record;
		}

		$text  = $record['text'];
		$title = self::plain_text( (string) get_the_title( $post ) );
		$room  = max( 0, self::BLUESKY_MAX_GRAPHEMES - self::length( $text ) );
		$at    = 0;
		foreach ( explode( "\n\n", $text ) as $i => $segment ) {
			$clean = 0 === $i && '' !== $title && $segment === $title ? null : self::clean_excerpt( $segment, $pairs );
			if ( null === $clean ) {
				$at += strlen( $segment ) + 2;
				continue;
			}

			$clean          = self::fit( $clean, self::length( $segment ) + $room );
			$record['text'] = substr_replace( $text, $clean, $at, strlen( $segment ) );
			if ( isset( $record['facets'] ) && is_array( $record['facets'] ) ) {
				[ $prefix, $suffix ] = self::common_ends( $segment, $clean );
				$record['facets']    = self::shift_facets( $record['facets'], $at + $prefix, strlen( $segment ) - $prefix - $suffix, strlen( $clean ) - $prefix - $suffix );
			}

			return $record;
		}

		return $record;
	}

	/**
	 * The excerpts ATmosphere builds from $post's raw post_content, each
	 * paired with the one it would build with private card text stripped.
	 * Empty when ATmosphere uses the post's own excerpt, or when no card's
	 * static markup holds a private cost or location, which leaves a bare
	 * block comment alone.
	 *
	 * @param \WP_Post $post The post.
	 * @return array<int, array{0: string, 1: string}> Raw and clean excerpt pairs.
	 */
	private static function excerpt_pairs( \WP_Post $post ): array {
		if ( ! empty( $post->post_excerpt ) ) {
			return [];
		}

		$content  = (string) $post->post_content;
		$stripped = Meta_Fields::strip_private_card_text( $content, (int) $post->ID );
		if ( $stripped === $content ) {
			return [];
		}

		$pairs = [];
		foreach ( self::EXCERPT_WORDS as $words ) {
			$raw   = wp_trim_words( self::plain_text( $content ), $words, self::MORE );
			$clean = wp_trim_words( self::plain_text( $stripped ), $words, self::MORE );
			if ( $raw !== $clean ) {
				$pairs[] = [ $raw, $clean ];
			}
		}

		return $pairs;
	}

	/**
	 * The clean excerpt for $text when $text is one of ATmosphere's raw
	 * excerpts, whole or cut short with '...'; null when it isn't. A cut
	 * that stops before the cost comes back unchanged.
	 *
	 * @param string                                  $text  Text ATmosphere built.
	 * @param array<int, array{0: string, 1: string}> $pairs From excerpt_pairs().
	 * @return string|null
	 */
	private static function clean_excerpt( string $text, array $pairs ): ?string {
		foreach ( $pairs as [ $raw, $clean ] ) {
			if ( $text === $raw ) {
				return $clean;
			}
		}

		if ( ! str_ends_with( $text, self::MORE ) ) {
			return null;
		}

		$kept = substr( $text, 0, -strlen( self::MORE ) );
		foreach ( $pairs as [ $raw, $clean ] ) {
			if ( str_starts_with( $raw, $kept ) ) {
				return str_starts_with( $clean, $kept ) ? $text : $clean;
			}
		}

		return null;
	}

	/**
	 * Text the way ATmosphere's sanitize_text() makes it: entities decoded,
	 * tags stripped, whitespace collapsed and trimmed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain_text( string $text ): string {
		$text      = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		$collapsed = preg_replace( '/\s+/u', ' ', $text );

		return trim( is_string( $collapsed ) ? $collapsed : $text );
	}

	/**
	 * $text cut to $graphemes, at the last space that fits, with '...'.
	 *
	 * @param string $text      Text.
	 * @param int    $graphemes Length limit.
	 * @return string
	 */
	private static function fit( string $text, int $graphemes ): string {
		if ( self::length( $text ) <= $graphemes ) {
			return $text;
		}

		$cut   = self::head( $text, max( 0, $graphemes - strlen( self::MORE ) ) );
		$space = strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = substr( $cut, 0, $space );
		}

		return $cut . self::MORE;
	}

	/**
	 * Grapheme count, as ATmosphere counts the Bluesky limit.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( string $text ): int {
		$length = function_exists( 'grapheme_strlen' ) ? grapheme_strlen( $text ) : false;

		return is_int( $length ) ? $length : mb_strlen( $text );
	}

	/**
	 * The first $graphemes graphemes of $text.
	 *
	 * @param string $text      Text.
	 * @param int    $graphemes Graphemes to keep.
	 * @return string
	 */
	private static function head( string $text, int $graphemes ): string {
		$head = function_exists( 'grapheme_substr' ) ? grapheme_substr( $text, 0, $graphemes ) : false;

		return is_string( $head ) ? $head : mb_substr( $text, 0, $graphemes );
	}

	/**
	 * Bytes $before and $after share at the start and, after that, at the end.
	 *
	 * @param string $before Old text.
	 * @param string $after  New text.
	 * @return array{0: int, 1: int}
	 */
	private static function common_ends( string $before, string $after ): array {
		$before_end = strlen( $before ) - 1;
		$after_end  = strlen( $after ) - 1;
		$max        = min( $before_end, $after_end ) + 1;
		$prefix     = 0;
		while ( $prefix < $max && $before[ $prefix ] === $after[ $prefix ] ) {
			++$prefix;
		}

		$suffix = 0;
		while ( $suffix < $max - $prefix && $before[ $before_end - $suffix ] === $after[ $after_end - $suffix ] ) {
			++$suffix;
		}

		return [ $prefix, $suffix ];
	}

	/**
	 * Facets after $removed bytes at $at became $added bytes.
	 *
	 * @param array<int|string, mixed> $facets  Bluesky facets indexed into the text by UTF-8 byte.
	 * @param int                      $at      Byte offset of the change.
	 * @param int                      $removed Bytes replaced.
	 * @param int                      $added   Bytes put in their place.
	 * @return array<int|string, mixed>
	 */
	private static function shift_facets( array $facets, int $at, int $removed, int $added ): array {
		$kept = [];
		foreach ( $facets as $facet ) {
			$start = is_array( $facet ) ? ( $facet['index']['byteStart'] ?? null ) : null;
			$end   = is_array( $facet ) ? ( $facet['index']['byteEnd'] ?? null ) : null;

			if ( ! is_int( $start ) || ! is_int( $end ) || $end <= $at ) {
				$kept[] = $facet;
			} elseif ( $start >= $at + $removed ) {
				$facet['index']['byteStart'] = $start + $added - $removed;
				$facet['index']['byteEnd']   = $end + $added - $removed;
				$kept[]                      = $facet;
			}
		}

		return $kept;
	}

	/**
	 * The post's kind slug.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string|null
	 */
	private function kind_slug( \WP_Post $post ): ?string {
		$terms = get_the_terms( $post->ID, Taxonomy::TAXONOMY );

		if ( is_array( $terms ) && isset( $terms[0]->slug ) ) {
			return (string) $terms[0]->slug;
		}

		return null;
	}
}

<?php
/**
 * Presentation classifier.
 *
 * Decides whether a post represents a talk, deck or presentation from the
 * embeds in its content and its authored presentation fields. The save hook
 * (Taxonomy::sync_kind_from_first_block()) and the backfill command read it;
 * nothing reads it at render, because the kind is a stored term that drives
 * archives, queries and feeds.
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
 * Reads presentation signals from a post.
 */
final class Presentation_Classifier {

	/**
	 * Kind slug this classifier assigns.
	 *
	 * @var string
	 */
	public const KIND = 'presentation';

	/**
	 * Embed block provider slugs that embed a slide deck.
	 *
	 * `slideshare` covers blocks saved before WordPress 6.6 dropped the
	 * provider; `notist` and `canva` are the slugs the editor derives from
	 * those services' oEmbed provider names.
	 *
	 * @var list<string>
	 */
	public const DECK_EMBED_SLUGS = [ 'speaker-deck', 'slideshare', 'notist', 'canva' ];

	/**
	 * Embed block provider slugs that embed a recorded talk.
	 *
	 * @var list<string>
	 */
	public const TALK_EMBED_SLUGS = [ 'wordpress-tv-embed', 'wordpress-tv' ];

	/**
	 * Embed block provider slugs for video that may or may not be a talk.
	 *
	 * Reported as `recording`, never enough to classify a post.
	 *
	 * @var list<string>
	 */
	public const RECORDING_EMBED_SLUGS = [ 'youtube', 'videopress', 'vimeo', 'dailymotion' ];

	/**
	 * Authored fields, any one of which marks a presentation.
	 *
	 * `_pkiw_presentation_recording_url` is left out on purpose: it is
	 * filled from YouTube embeds, which must not classify a post.
	 *
	 * @var list<string>
	 */
	public const PRESENTATION_META_KEYS = [
		'_pkiw_presentation_slides_url',
		'_pkiw_presentation_event_name',
		'_pkiw_presentation_event_url',
	];

	/**
	 * Calendar source field; marks a presentation together with an event ID.
	 *
	 * @var string
	 */
	public const CALENDAR_SOURCE_META_KEY = '_pkiw_presentation_calendar_source';

	/**
	 * Calendar event ID field.
	 *
	 * @var string
	 */
	public const CALENDAR_EVENT_META_KEY = '_pkiw_presentation_calendar_event_id';

	/**
	 * Tags whose attribute carries an embedded URL in raw markup.
	 *
	 * @var array<string, string>
	 */
	private const EMBED_URL_ATTRIBUTES = [
		'IFRAME' => 'src',
		'OBJECT' => 'data',
		'EMBED'  => 'src',
		'PARAM'  => 'value',
	];

	/**
	 * Read every presentation signal in a post.
	 *
	 * Walks the parsed blocks (inner blocks too, so a wrapper can't hide an
	 * embed), then the raw markup, so classic content and Custom HTML
	 * blocks count, then `[slideshare]` shortcodes. Links to deck hosts are
	 * never signals.
	 *
	 * @param \WP_Post $post Post to read.
	 * @return array{deck: list<string>, talk_recording: list<string>, recording: list<string>, presentation_meta: bool, listen: bool}
	 */
	public static function signals( \WP_Post $post ): array {
		$signals = [
			'deck'              => [],
			'talk_recording'    => [],
			'recording'         => [],
			'presentation_meta' => self::has_presentation_meta( (int) $post->ID ),
			'listen'            => $post->ID > 0 && has_term( 'listen', Taxonomy::TAXONOMY, $post ),
		];

		$content = (string) $post->post_content;
		if ( has_blocks( $content ) ) {
			self::collect_block_signals( parse_blocks( $content ), $signals );
		}
		self::collect_markup_signals( $content, $signals );
		self::collect_shortcode_signals( $content, $signals );

		foreach ( [ 'deck', 'talk_recording', 'recording' ] as $key ) {
			$signals[ $key ] = array_values( array_unique( $signals[ $key ] ) );
		}

		return $signals;
	}

	/**
	 * Whether a post represents a talk, deck or presentation.
	 *
	 * A deck, a WordPress.tv recording or authored presentation fields
	 * classify. A YouTube, VideoPress, Vimeo or Dailymotion embed never
	 * does on its own.
	 *
	 * @param \WP_Post $post Post to classify.
	 * @return bool
	 */
	public static function is_presentation( \WP_Post $post ): bool {
		$signals = self::signals( $post );

		// Podcast rule: a listen card or kind becomes a presentation only
		// through a deck, a talk recording or authored presentation fields.
		// It spells out its own expression on purpose, so a change to the
		// general rule below doesn't reach listen posts.
		if ( $signals['listen'] ) {
			return [] !== $signals['deck'] || [] !== $signals['talk_recording'] || $signals['presentation_meta'];
		}

		return [] !== $signals['deck'] || [] !== $signals['talk_recording'] || $signals['presentation_meta'];
	}

	/**
	 * Classify existing posts the way a save would.
	 *
	 * Candidates are posts whose automatic kind resolves to `presentation`.
	 * An eligible candidate (no kind, the `note` default, or a kind this
	 * plugin set) gets the kind term and the auto-assigned marker and
	 * nothing else: no post update, so the modified date, revisions and
	 * update-triggered syndication stay as they were. Returns early, writing
	 * nothing, when the `presentation` term is missing, because
	 * wp_set_post_terms() would create it.
	 *
	 * @param Taxonomy $taxonomy Taxonomy instance that holds the guard.
	 * @param bool     $dry_run  Report without writing.
	 * @return array{scanned: int, would_change: int, changed: int, protected: int, unchanged: int, failed: int, rows: list<array{post_id: int, title: string, kind: string, auto_marker: string, status: string, signals: array{deck: list<string>, talk_recording: list<string>, recording: list<string>, presentation_meta: bool, listen: bool}}>}
	 */
	public static function backfill( Taxonomy $taxonomy, bool $dry_run = true ): array {
		$report = [
			'scanned'      => 0,
			'would_change' => 0,
			'changed'      => 0,
			'protected'    => 0,
			'unchanged'    => 0,
			'failed'       => 0,
			'rows'         => [],
		];

		if ( ! $taxonomy->is_valid_kind( self::KIND ) ) {
			return $report;
		}

		$paged = 1;
		do {
			$query = new \WP_Query(
				[
					'post_type'           => $taxonomy->get_post_types(),
					'post_status'         => 'any',
					'posts_per_page'      => 100,
					'paged'               => $paged,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'fields'              => 'ids',
					'ignore_sticky_posts' => true,
				]
			);

			foreach ( $query->posts as $post_id ) {
				$post = get_post( (int) $post_id );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				++$report['scanned'];

				if ( self::KIND !== Taxonomy::resolve_auto_kind( $post ) ) {
					continue;
				}

				$current = $taxonomy->get_post_kind( $post->ID );
				$marker  = get_post_meta( $post->ID, Taxonomy::AUTO_KIND_META_KEY, true );
				$status  = $taxonomy->auto_kind_status( $post->ID, self::KIND );

				if ( 'eligible' === $status ) {
					if ( $dry_run ) {
						++$report['would_change'];
					} elseif ( $taxonomy->assign_auto_kind( $post->ID, self::KIND ) ) {
						$status = 'changed';
						++$report['changed'];
					} else {
						$status = 'failed';
						++$report['failed'];
					}
				} elseif ( 'protected' === $status ) {
					++$report['protected'];
				} else {
					++$report['unchanged'];
				}

				$report['rows'][] = [
					'post_id'     => $post->ID,
					'title'       => $post->post_title,
					'kind'        => $current instanceof \WP_Term ? $current->slug : '',
					'auto_marker' => is_string( $marker ) ? $marker : '',
					'status'      => $status,
					'signals'     => self::signals( $post ),
				];
			}

			++$paged;
		} while ( $paged <= (int) $query->max_num_pages );

		return $report;
	}

	/**
	 * One line of backfill output: the post, the outcome, why, and its signals.
	 *
	 * Every line names the current kind and the auto-assigned marker, the two
	 * values the guard compares, because the marker differs per environment.
	 *
	 * @param array{post_id: int, title: string, kind: string, auto_marker: string, status: string, signals: array{deck: list<string>, talk_recording: list<string>, recording: list<string>, presentation_meta: bool, listen: bool}} $row Backfill row.
	 * @return string
	 */
	public static function backfill_line( array $row ): string {
		$outcomes = [
			'eligible'  => 'would change to ' . self::KIND,
			'changed'   => 'changed to ' . self::KIND,
			'failed'    => 'failed to change to ' . self::KIND,
			'protected' => 'protected',
			'same'      => 'already ' . self::KIND,
		];

		$parts = [
			sprintf(
				'kind %s, auto marker %s',
				'' === $row['kind'] ? 'none' : $row['kind'],
				'' === $row['auto_marker'] ? 'none' : $row['auto_marker']
			),
		];
		foreach ( [ 'deck', 'talk_recording', 'recording' ] as $key ) {
			if ( [] !== $row['signals'][ $key ] ) {
				$parts[] = $key . ': ' . implode( ', ', $row['signals'][ $key ] );
			}
		}
		foreach ( [ 'presentation_meta', 'listen' ] as $key ) {
			if ( $row['signals'][ $key ] ) {
				$parts[] = $key;
			}
		}

		return sprintf(
			'#%d "%s": %s; %s',
			$row['post_id'],
			wp_strip_all_tags( $row['title'], true ),
			$outcomes[ $row['status'] ] ?? $row['status'],
			implode( '; ', $parts )
		);
	}

	/**
	 * Collect embed-block signals, descending into inner blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
	 * @param array<string, mixed>             $signals Signals, updated in place.
	 * @return void
	 */
	private static function collect_block_signals( array $blocks, array &$signals ): void {
		foreach ( $blocks as $block ) {
			if ( 'post-kinds-indieweb/listen-card' === ( $block['blockName'] ?? null ) ) {
				$signals['listen'] = true;
			}

			$slug = self::embed_provider_slug( $block );
			$url  = $block['attrs']['url'] ?? '';
			if ( null !== $slug && is_string( $url ) && '' !== trim( $url ) ) {
				$url = trim( $url );
				if ( in_array( $slug, self::DECK_EMBED_SLUGS, true ) ) {
					$signals['deck'][] = $url;
				} elseif ( in_array( $slug, self::TALK_EMBED_SLUGS, true ) ) {
					$signals['talk_recording'][] = $url;
				} elseif ( in_array( $slug, self::RECORDING_EMBED_SLUGS, true ) ) {
					$signals['recording'][] = $url;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_block_signals( $block['innerBlocks'], $signals );
			}
		}
	}

	/**
	 * Provider slug of an embed block, or null for any other block.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null
	 */
	private static function embed_provider_slug( array $block ): ?string {
		$name = (string) ( $block['blockName'] ?? '' );

		if ( 'core/embed' === $name ) {
			$slug = $block['attrs']['providerNameSlug'] ?? '';
			return is_string( $slug ) && '' !== $slug ? $slug : null;
		}

		// Embed blocks saved before the embed variations existed name the
		// provider in the block name: core-embed/speaker-deck.
		if ( str_starts_with( $name, 'core-embed/' ) ) {
			return substr( $name, strlen( 'core-embed/' ) );
		}

		return null;
	}

	/**
	 * Collect deck and talk-recording embeds from raw markup.
	 *
	 * Reads iframe src, object data, embed src and param value, plus
	 * Notist's `data-notist` embed. WP_HTML_Tag_Processor::get_attribute()
	 * decodes character references, so Canva's `https:&#x2F;&#x2F;…` src
	 * reads as a URL without a second decode. Anchor hrefs are never read.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $signals Signals, updated in place.
	 * @return void
	 */
	private static function collect_markup_signals( string $content, array &$signals ): void {
		if ( false === strpos( $content, '<' ) ) {
			return;
		}

		$processor = new \WP_HTML_Tag_Processor( $content );
		while ( $processor->next_tag() ) {
			$notist = $processor->get_attribute( 'data-notist' );
			if ( is_string( $notist ) && 1 === preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_-]+$#', trim( $notist ) ) ) {
				$signals['deck'][] = 'https://noti.st/' . trim( $notist );
			}

			$attribute = self::EMBED_URL_ATTRIBUTES[ (string) $processor->get_tag() ] ?? null;
			if ( null === $attribute ) {
				continue;
			}

			$url = $processor->get_attribute( $attribute );
			if ( ! is_string( $url ) ) {
				continue;
			}

			$url = trim( $url );
			if ( str_starts_with( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			if ( self::is_deck_url( $url ) ) {
				$signals['deck'][] = $url;
			} elseif ( self::is_talk_url( $url ) ) {
				$signals['talk_recording'][] = $url;
			}
		}
	}

	/**
	 * Collect `[slideshare id=...]` shortcodes as decks.
	 *
	 * Found in core/shortcode blocks and in paragraphs of older posts. The
	 * site needn't register the shortcode: the id still names a SlideShare
	 * deck, reported as its embed_code URL. `[[slideshare]]` is escaped
	 * text and doesn't count.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $signals Signals, updated in place.
	 * @return void
	 */
	private static function collect_shortcode_signals( string $content, array &$signals ): void {
		if ( false === stripos( $content, '[slideshare' ) ) {
			return;
		}

		if ( preg_match_all( '/(?<!\[)\[slideshare\s[^\]]*?\bid=["\']?(\d+)/i', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$signals['deck'][] = 'https://www.slideshare.net/slideshow/embed_code/' . $id;
			}
		}
	}

	/**
	 * Whether an embedded URL is a slide-deck player.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private static function is_deck_url( string $url ): bool {
		$parts = self::url_parts( $url );
		if ( null === $parts ) {
			return false;
		}
		[ $host, $path ] = $parts;

		return ( self::host_is( $host, 'speakerdeck.com' ) && str_starts_with( $path, '/player/' ) )
			|| ( self::host_is( $host, 'canva.com' ) && str_starts_with( $path, '/design/' ) && str_ends_with( rtrim( $path, '/' ), '/view' ) )
			|| ( self::host_is( $host, 'slideshare.net' ) && str_starts_with( $path, '/slideshow/embed_code/' ) )
			|| self::host_is( $host, 'slidesharecdn.com' )
			|| ( 'docs.google.com' === $host && str_starts_with( $path, '/presentation/' ) )
			|| ( self::host_is( $host, 'noti.st' ) && str_ends_with( rtrim( $path, '/' ), '/embed' ) );
	}

	/**
	 * Whether an embedded URL is a WordPress.tv recording.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private static function is_talk_url( string $url ): bool {
		$parts = self::url_parts( $url );

		return null !== $parts && self::host_is( $parts[0], 'wordpress.tv' );
	}

	/**
	 * Lowercased host and path of an http(s) URL.
	 *
	 * @param string $url URL.
	 * @return array{0: string, 1: string}|null
	 */
	private static function url_parts( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		return [ strtolower( (string) $parts['host'] ), (string) ( $parts['path'] ?? '' ) ];
	}

	/**
	 * Whether a host is a domain or one of its subdomains.
	 *
	 * @param string $host   Lowercased host.
	 * @param string $domain Domain.
	 * @return bool
	 */
	private static function host_is( string $host, string $domain ): bool {
		return $host === $domain || str_ends_with( $host, '.' . $domain );
	}

	/**
	 * Whether a post carries authored presentation fields.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function has_presentation_meta( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		foreach ( self::PRESENTATION_META_KEYS as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}

		$source = get_post_meta( $post_id, self::CALENDAR_SOURCE_META_KEY, true );

		return is_string( $source ) && '' !== trim( $source )
			&& absint( get_post_meta( $post_id, self::CALENDAR_EVENT_META_KEY, true ) ) > 0;
	}
}

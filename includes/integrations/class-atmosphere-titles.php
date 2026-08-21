<?php
/**
 * Derived Standard.site document titles for Post Kinds.
 *
 * The site.standard.document lexicon requires a title, while several Post
 * Kinds are intentionally untitled. This class derives a human-readable
 * title from the kind's own metadata — never touching the WordPress post
 * title — so records read like their pages do.
 *
 * The WordPress post title, when present, always wins: ATmosphere already
 * maps it, and derive() returns '' so the mapper leaves it alone.
 *
 * @package PKIW
 * @since   1.6.0
 */

declare(strict_types=1);

namespace PKIW\Integrations;

use PKIW\Meta_Fields;
use PKIW\Taxonomy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standard.site title derivation.
 *
 * @since 1.6.0
 */
final class Atmosphere_Titles {

	/**
	 * Lexicon cap for site.standard.document title, in graphemes.
	 *
	 * @since 1.6.0
	 *
	 * @var int
	 */
	private const MAX_GRAPHEMES = 500;

	/**
	 * Not instantiable; all behavior is static.
	 */
	private function __construct() {
	}

	/**
	 * Derive a Standard.site document title for a post.
	 *
	 * Returns '' when the post has a real title (nothing to derive — the
	 * post title wins) or when the post cannot be resolved.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string Derived title, or '' to leave ATmosphere's mapping alone.
	 */
	public static function derive( \WP_Post $post ): string {
		$kind  = self::kind_slug( $post );
		$title = '';

		if ( '' === trim( get_the_title( $post ) ) ) {
			$title = self::derive_for_kind( $post, $kind );

			if ( '' === $title ) {
				$title = self::content_summary( $post );
			}

			if ( '' === $title ) {
				$title = self::kind_label( $post );
			}

			$title = self::truncate_graphemes( $title, self::MAX_GRAPHEMES );
		}

		/**
		 * Filters the derived Standard.site document title for a post.
		 *
		 * Runs for every mapped post, including titled posts, where the
		 * derived value is '' (the WordPress title wins). Return '' to
		 * leave ATmosphere's own title mapping untouched.
		 *
		 * @since 1.6.0
		 *
		 * @param string      $title Derived title, '' when nothing derived.
		 * @param \WP_Post    $post  The post.
		 * @param string|null $kind  The post's kind slug, or null.
		 */
		$title = apply_filters( 'pkiw_atmosphere_document_title', $title, $post, $kind );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Kind-specific derivation.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post    $post The post.
	 * @param string|null $kind Kind slug.
	 * @return string Derived title or ''.
	 */
	private static function derive_for_kind( \WP_Post $post, ?string $kind ): string {
		switch ( $kind ) {
			case 'listen':
				return self::media_phrase(
					/* translators: %s: track name. */
					__( 'Listened to %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'listen_track' ),
					self::meta( $post, 'listen_artist' )
				);

			case 'jam':
				return self::media_phrase(
					/* translators: %s: track name. */
					__( 'Jam: %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'jam_track' ),
					self::meta( $post, 'jam_artist' )
				);

			case 'watch':
				return self::watch_title( $post );

			case 'read':
				return self::media_phrase(
					/* translators: %s: book title. */
					__( 'Read %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'read_title' ),
					self::meta( $post, 'read_author' )
				);

			case 'checkin':
				$venue = self::meta( $post, 'checkin_name' );
				if ( '' !== $venue && 'private' !== self::meta( $post, 'geo_privacy' ) ) {
					/* translators: %s: venue name. */
					return sprintf( __( 'Checked in at %s', 'post-kinds-for-indieweb-in-block-themes' ), $venue );
				}
				return __( 'Checked in', 'post-kinds-for-indieweb-in-block-themes' );

			case 'play':
				return self::simple_phrase(
					/* translators: %s: game name. */
					__( 'Played %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'play_title' )
				);

			case 'eat':
				return self::simple_phrase(
					/* translators: %s: food name. */
					__( 'Ate %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'eat_name' )
				);

			case 'drink':
				return self::simple_phrase(
					/* translators: %s: drink name. */
					__( 'Drank %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'drink_name' )
				);

			case 'rsvp':
				return self::simple_phrase(
					/* translators: %s: event name. */
					__( 'RSVP: %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::cite_label( $post )
				);

			case 'like':
				return self::simple_phrase(
					/* translators: %s: cited page title or domain. */
					__( 'Liked %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::cite_label( $post )
				);

			case 'reply':
				return self::simple_phrase(
					/* translators: %s: cited page title or domain. */
					__( 'Replied to %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::cite_label( $post )
				);

			case 'repost':
				return self::simple_phrase(
					/* translators: %s: cited page title or domain. */
					__( 'Reposted %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::cite_label( $post )
				);

			case 'bookmark':
				return self::simple_phrase(
					/* translators: %s: cited page title or domain. */
					__( 'Bookmarked %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::cite_label( $post )
				);

			case 'favorite':
				$name = self::meta( $post, 'favorite_name' );
				if ( '' === $name ) {
					$name = self::cite_label( $post );
				}
				return self::simple_phrase(
					/* translators: %s: favorited item name. */
					__( 'Favorited %s', 'post-kinds-for-indieweb-in-block-themes' ),
					$name
				);

			case 'follow':
				return self::simple_phrase(
					/* translators: %s: followed profile name or domain. */
					__( 'Followed %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::cite_label( $post )
				);

			case 'quote':
				return self::simple_phrase(
					/* translators: %s: quoted page title or domain. */
					__( 'Quote from %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::cite_label( $post )
				);

			case 'wish':
				return self::simple_phrase(
					/* translators: %s: wished-for item name. */
					__( 'Wish: %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'wish_name' )
				);

			case 'mood':
				return self::mood_title( $post );

			case 'acquisition':
				return self::simple_phrase(
					/* translators: %s: acquired item name. */
					__( 'Acquired %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'acquisition_name' )
				);

			case 'review':
				return self::simple_phrase(
					/* translators: %s: reviewed item name. */
					__( 'Review: %s', 'post-kinds-for-indieweb-in-block-themes' ),
					self::meta( $post, 'review_item_name' )
				);
		}

		return '';
	}

	/**
	 * "Watched …" with television awareness.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private static function watch_title( \WP_Post $post ): string {
		$show = self::meta( $post, 'watch_show_title' );

		// The media-type meta has a registered default of 'movie', so gate
		// on the show title actually being present, not on the type alone.
		if ( 'tv' === self::meta( $post, 'watch_media_type' ) && '' !== $show ) {
			$season  = self::meta( $post, 'watch_season' );
			$episode = self::meta( $post, 'watch_episode' );
			$name    = $show;

			if ( '' !== $season && '' !== $episode ) {
				$name .= sprintf( ' S%sE%s', $season, $episode );
			}

			$episode_title = self::meta( $post, 'watch_episode_title' );
			if ( '' !== $episode_title ) {
				$name .= ': ' . $episode_title;
			}

			/* translators: %s: show name with episode details. */
			return sprintf( __( 'Watched %s', 'post-kinds-for-indieweb-in-block-themes' ), $name );
		}

		return self::simple_phrase(
			/* translators: %s: film or video title. */
			__( 'Watched %s', 'post-kinds-for-indieweb-in-block-themes' ),
			self::meta( $post, 'watch_title' )
		);
	}

	/**
	 * "Mood: {emoji} {label}" from whichever parts exist.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private static function mood_title( \WP_Post $post ): string {
		$parts = array_filter(
			[
				self::meta( $post, 'mood_emoji' ),
				self::meta( $post, 'mood_label' ),
			],
			static fn( string $part ): bool => '' !== $part
		);

			if ( empty( $parts ) ) {
				return '';
			}

			/* translators: %s: mood emoji and/or label. */
			return sprintf( __( 'Mood: %s', 'post-kinds-for-indieweb-in-block-themes' ), implode( ' ', $parts ) );
	}

	/**
	 * A "{verb} {name} by {credit}" phrase, dropping the credit when absent.
	 *
	 * @since 1.6.0
	 *
	 * @param string $pattern sprintf pattern with one placeholder for the name.
	 * @param string $name    Primary name.
	 * @param string $credit  Artist/author credit.
	 * @return string
	 */
	private static function media_phrase( string $pattern, string $name, string $credit ): string {
		if ( '' === $name ) {
			return '';
		}

		if ( '' !== $credit ) {
			/* translators: 1: item name, 2: artist or author. */
			$name = sprintf( __( '%1$s by %2$s', 'post-kinds-for-indieweb-in-block-themes' ), $name, $credit );
		}

		return sprintf( $pattern, $name );
	}

	/**
	 * A one-placeholder phrase, or '' when the value is empty.
	 *
	 * @since 1.6.0
	 *
	 * @param string $pattern sprintf pattern.
	 * @param string $value   The value.
	 * @return string
	 */
	private static function simple_phrase( string $pattern, string $value ): string {
		return '' === $value ? '' : sprintf( $pattern, $value );
	}

	/**
	 * The cited page's name, falling back to its host.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private static function cite_label( \WP_Post $post ): string {
		$name = self::meta( $post, 'cite_name' );
		if ( '' !== $name ) {
			return $name;
		}

		$host = (string) wp_parse_url( self::meta( $post, 'cite_url' ), PHP_URL_HOST );

		return preg_replace( '/^www\./', '', $host ) ?? $host;
	}

	/**
	 * A short summary from the post content.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private static function content_summary( \WP_Post $post ): string {
		$text = wp_strip_all_tags( (string) $post->post_content );

		return trim( wp_trim_words( $text, 10, '…' ) );
	}

	/**
	 * The kind term's human-readable label.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private static function kind_label( \WP_Post $post ): string {
		$terms = get_the_terms( $post->ID, Taxonomy::TAXONOMY );

		if ( is_array( $terms ) && isset( $terms[0]->name ) ) {
			return (string) $terms[0]->name;
		}

		return '';
	}

	/**
	 * The post's kind slug.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string|null
	 */
	private static function kind_slug( \WP_Post $post ): ?string {
		$terms = get_the_terms( $post->ID, Taxonomy::TAXONOMY );

		if ( is_array( $terms ) && isset( $terms[0]->slug ) ) {
			return (string) $terms[0]->slug;
		}

		return null;
	}

	/**
	 * A trimmed post-meta value.
	 *
	 * @since 1.6.0
	 *
	 * @param \WP_Post $post   The post.
	 * @param string   $suffix Meta key suffix (prefix added).
	 * @return string
	 */
	private static function meta( \WP_Post $post, string $suffix ): string {
		return trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . $suffix, true ) );
	}

	/**
	 * Truncate to a grapheme cap without splitting clusters.
	 *
	 * Prefers intl grapheme functions; the mb_* code-point fallback is
	 * conservative (never exceeds the grapheme cap).
	 *
	 * @since 1.6.0
	 *
	 * @param string $text Text to cap.
	 * @param int    $max  Maximum graphemes.
	 * @return string
	 */
	private static function truncate_graphemes( string $text, int $max ): string {
		if ( $max <= 0 ) {
			return '';
		}

		if ( function_exists( 'grapheme_strlen' ) && function_exists( 'grapheme_substr' ) ) {
			$length = grapheme_strlen( $text );
			if ( null !== $length && false !== $length && $length <= $max ) {
				return $text;
			}

			$truncated = grapheme_substr( $text, 0, $max );
			if ( is_string( $truncated ) ) {
				return $truncated;
			}
		}

		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}

		return mb_substr( $text, 0, $max, 'UTF-8' );
	}
}

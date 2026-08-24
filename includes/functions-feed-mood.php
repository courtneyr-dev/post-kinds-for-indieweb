<?php
/**
 * Mood posts in syndication feeds.
 *
 * The mood card renders a badge SVG, a "Mood" kind label, and the emoji as
 * block-level markup. A feed reader strips the styling and flattens that
 * into a stack of stray lines ("Mood", the emoji, then the text). In feeds
 * the card collapses to its essence instead: the emoji inline at the head
 * of the post text ("🥳 Testing out …"), or "emoji note" as a single
 * paragraph when the card is the whole post.
 *
 * @package PKIW
 */

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suppress the mood card's markup in feed content.
 *
 * Runs on the mood-card block's render filter; feed_mood_content() re-adds
 * the mood as an inline emoji afterwards.
 *
 * @param string $content Rendered block HTML.
 * @return string Empty string in feeds, untouched HTML elsewhere.
 */
function suppress_mood_card_in_feeds( $content ) {
	return is_feed() ? '' : $content;
}
add_filter( 'render_block_post-kinds-indieweb/mood-card', __NAMESPACE__ . '\\suppress_mood_card_in_feeds' );

/**
 * The first mood-card block in a post body, as its emoji and note.
 *
 * Attributes come straight from the stored block comment. Mirroring the
 * block's own render (block.json defaults emoji to ''), an absent emoji
 * stays absent rather than gaining a default.
 *
 * @param string $post_content Raw post content.
 * @return array{emoji: string, note: string}|null Card data, or null when the body has no mood-card block.
 */
function first_mood_card( string $post_content ): ?array {
	if ( ! str_contains( $post_content, 'post-kinds-indieweb/mood-card' ) ) {
		return null;
	}

	$stack = parse_blocks( $post_content );
	while ( $stack ) {
		$block = array_shift( $stack );

		if ( 'post-kinds-indieweb/mood-card' === ( $block['blockName'] ?? '' ) ) {
			return [
				'emoji' => trim( (string) ( $block['attrs']['emoji'] ?? '' ) ),
				'note'  => trim( (string) ( $block['attrs']['note'] ?? '' ) ),
			];
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$stack = array_merge( $stack, $block['innerBlocks'] );
		}
	}

	return null;
}

/**
 * Lead a mood post's feed content with its emoji, inline.
 *
 * The card itself is suppressed in feeds; here the emoji is planted inside
 * the first paragraph so it reads on the same line as the text. A post
 * that was nothing but the card becomes a single "emoji note" paragraph.
 *
 * @param string $content Feed item content, blocks already rendered.
 * @return string Content led by the mood emoji.
 */
function feed_mood_content( $content ) {
	if ( ! is_feed() ) {
		return $content;
	}

	$post = get_post();
	if ( ! $post instanceof \WP_Post ) {
		return $content;
	}

	$card = first_mood_card( (string) $post->post_content );
	if ( null === $card ) {
		return $content;
	}

	$content = trim( (string) $content );

	if ( '' === $content ) {
		$line = trim( $card['emoji'] . ' ' . wp_strip_all_tags( $card['note'] ) );

		return '' === $line ? '' : '<p>' . esc_html( $line ) . '</p>';
	}

	if ( '' === $card['emoji'] ) {
		return $content;
	}

	$count   = 0;
	$content = preg_replace_callback(
		'/<p\b[^>]*>/',
		static function ( $matches ) use ( $card ) {
			return $matches[0] . esc_html( $card['emoji'] ) . ' ';
		},
		$content,
		1,
		$count
	);

	return ( 0 === $count ) ? esc_html( $card['emoji'] ) . ' ' . $content : $content;
}
add_filter( 'the_content_feed', __NAMESPACE__ . '\\feed_mood_content' );

/**
 * Lead a mood post's feed excerpt with its emoji.
 *
 * Covers the "For each post in a feed, include: Excerpt" reading setting,
 * where the item body is the excerpt rather than the rendered content.
 *
 * @param string $excerpt Feed item excerpt.
 * @return string Excerpt led by the mood emoji.
 */
function feed_mood_excerpt( $excerpt ) {
	if ( ! is_feed() ) {
		return $excerpt;
	}

	$post = get_post();
	if ( ! $post instanceof \WP_Post ) {
		return $excerpt;
	}

	$card = first_mood_card( (string) $post->post_content );
	if ( null === $card || '' === $card['emoji'] ) {
		return $excerpt;
	}

	$excerpt = trim( (string) $excerpt );
	if ( '' === $excerpt ) {
		$excerpt = wp_strip_all_tags( $card['note'] );
	}

	return trim( $card['emoji'] . ' ' . $excerpt );
}
add_filter( 'the_excerpt_rss', __NAMESPACE__ . '\\feed_mood_excerpt' );

<?php
/**
 * Card kind-label helper.
 *
 * The visible `.pk-kindlabel` text on every card (block templates and the
 * generic Stream card) flows through get_kind_label(), so a theme can swap
 * the kind noun ("Watch", "Read") for a status verb ("WATCHED", "FINISHED")
 * without touching markup. The label is the card badge's accessible name —
 * the badge SVG itself is decorative — so replacements must stay visible text.
 *
 * @package PKIW
 * @since 1.1.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The display text for a card's kind label, filterable per site.
 *
 * Returns the default label unchanged when nothing hooks the filter, and
 * falls back to it when a callback returns a non-string.
 *
 * @since 1.1.0
 *
 * @param string $label   Default label text (e.g. "Watch", "Purchase").
 * @param string $kind    Kind slug the card represents (watch, listen, …).
 * @param string $context Render context: the card template slug
 *                        (e.g. 'watch-card'), or 'stream-card' for the
 *                        generic Stream card.
 * @return string Label text to display (escape at output).
 */
function get_kind_label( string $label, string $kind, string $context = '' ): string {
	/**
	 * Filter the visible kind-label text on a rendered card.
	 *
	 * @since 1.1.0
	 *
	 * @param string $label   Default label text (e.g. "Watch", "Purchase").
	 * @param string $kind    Kind slug the card represents (watch, listen, …).
	 * @param string $context Render context: the card template slug
	 *                        (e.g. 'watch-card'), or 'stream-card' for the
	 *                        generic Stream card.
	 */
	$filtered = apply_filters( 'pkiw_kind_label', $label, $kind, $context );

	return is_string( $filtered ) ? $filtered : $label;
}

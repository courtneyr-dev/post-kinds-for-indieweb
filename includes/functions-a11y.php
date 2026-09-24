<?php
/**
 * Shared accessibility helpers.
 *
 * @package PKIW
 * @since 1.8.6
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visually-hidden hint appended to links that open in a new tab.
 *
 * WCAG 2.2 AA (3.2.5, "Change on Request") expects a link that opens a new
 * tab or window to warn assistive-technology users before they activate it.
 * Append this markup inside the anchor, right after its visible text.
 *
 * @return string Screen-reader-only span noting the link opens in a new tab.
 */
function pkiw_new_tab_hint(): string {
	return '<span class="pk-sr-only">' . esc_html__( ' (opens in a new tab)', 'post-kinds-for-indieweb-in-block-themes' ) . '</span>';
}

/**
 * New-tab warning as an aria-label, for links whose text is a microformats
 * property value.
 *
 * A `p-name` inside an h-entry, h-event, or h-card is read by parsers as the
 * element's full text, so a visually-hidden span there would leak
 * "(opens in a new tab)" into every consumer's copy of the name. aria-label
 * keeps the warning for assistive technology (the visible text stays first,
 * per WCAG 2.5.3) and leaves the parsed value untouched.
 *
 * @param string $visible_text The link's visible text, unescaped.
 * @return string Leading-space aria-label attribute, or '' when there is no text to label.
 */
function pkiw_new_tab_label_attr( string $visible_text ): string {
	$visible_text = trim( $visible_text );
	if ( '' === $visible_text ) {
		return '';
	}
	return ' aria-label="' . esc_attr( $visible_text . __( ' (opens in a new tab)', 'post-kinds-for-indieweb-in-block-themes' ) ) . '"';
}

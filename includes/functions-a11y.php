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

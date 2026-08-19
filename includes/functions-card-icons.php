<?php
/**
 * Per-kind card icons.
 *
 * Line-glyph SVGs (one per kind) for the card badge, matching the courtneyr.dev
 * Post Kinds card design. Icons inherit currentColor and are decorative
 * (the visible kind label carries the accessible name).
 *
 * @package PKIW
 * @since 1.3.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the inner SVG paths for a kind's badge icon.
 *
 * @param string $kind Kind slug (listen, watch, checkin, …).
 * @return string SVG inner markup, or a generic dot when the kind is unknown.
 */
function get_kind_icon_paths( string $kind ): string {
	$icons = [
		'listen'      => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
		'jam'         => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
		'watch'       => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 9l5 3-5 3z"/>',
		'video'       => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 9l5 3-5 3z"/>',
		'checkin'     => '<path d="M12 22s8-5.5 8-12a8 8 0 1 0-16 0c0 6.5 8 12 8 12z"/><circle cx="12" cy="10" r="3"/>',
		'eat'         => '<path d="M4 3v7a3 3 0 0 0 6 0V3M7 3v18M17 3c-1.5 0-3 1.5-3 5s1.5 4 3 4 3 0 3 0V3z"/>',
		'drink'       => '<path d="M6 2h12l-1 6a5 5 0 0 1-10 0zM12 13v7M8 21h8"/>',
		'mood'        => '<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01"/>',
		'play'        => '<rect x="2" y="7" width="20" height="10" rx="5"/><path d="M7 12h3M8.5 10.5v3M15 11h.01M18 13h.01"/>',
		'acquisition' => '<path d="M6 2l1.5 4.5h9L18 2M3 6.5h18l-1.5 13a2 2 0 0 1-2 1.8H6.5a2 2 0 0 1-2-1.8z"/>',
		'read'        => '<path d="M12 6.5A5 5 0 0 0 7 4H3v13h4a5 5 0 0 1 5 2.5A5 5 0 0 1 17 17h4V4h-4a5 5 0 0 0-5 2.5z"/>',
		'like'        => '<path d="M20.8 5.6a5 5 0 0 0-7.1 0L12 7.3l-1.7-1.7a5 5 0 1 0-7.1 7.1L12 21.5l8.8-8.8a5 5 0 0 0 0-7.1z"/>',
		'favorite'    => '<path d="M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z"/>',
		'reply'       => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
		'repost'      => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
		'bookmark'    => '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
		'rsvp'        => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4"/>',
		'event'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="12" cy="16" r="2"/>',
		'wish'        => '<path d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
		'audio'       => '<path d="M11 5L6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/>',
		'quote'       => '<path d="M3 21c3-1 4.5-3.5 4.5-7H4V7h7v7c0 4.5-2.5 6.5-8 7z"/><path d="M13 21c3-1 4.5-3.5 4.5-7H14V7h7v7c0 4.5-2.5 6.5-8 7z"/>',
		'tag'         => '<path d="M20.6 13.4L12 22l-9-9V4h9l8.6 8.6a1 1 0 0 1 0 .8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
		'weather'     => '<path d="M17.5 19a4.5 4.5 0 0 0 0-9 6 6 0 0 0-11.5 1.5A4 4 0 0 0 7 19z"/>',
		'exercise'    => '<path d="M6.5 6.5v11M17.5 6.5v11M3 9v6M21 9v6M6.5 12h11"/>',
		'trip'        => '<circle cx="5" cy="19" r="2"/><circle cx="19" cy="5" r="2"/><path d="M7 19h8a4 4 0 0 0 0-8H9a4 4 0 0 1 0-8h8"/>',
		'itinerary'   => '<path d="M3 8a2 2 0 0 0 0 8v4h18v-4a2 2 0 0 1 0-8V4H3z"/><path d="M13 5v2M13 11v2M13 17v2"/>',
		'follow'      => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6"/><path d="M19 8v6M16 11h6"/>',
		'issue'       => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>',
		'question'    => '<circle cx="12" cy="12" r="9"/><path d="M9 9.5a3 3 0 1 1 4 3c-.8.5-1 1-1 2M12 17.5h.01"/>',
		'sleep'       => '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/><path d="M14 4h4l-4 4h4"/>',
		'craft'       => '<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M8.1 8.1L20 20M8.1 15.9L20 4"/>',
	];

	return $icons[ $kind ] ?? '<circle cx="12" cy="12" r="4"/>';
}

/**
 * Return a complete badge SVG element for a kind.
 *
 * @param string $kind Kind slug.
 * @return string SVG element (aria-hidden; decorative).
 */
function get_kind_icon_svg( string $kind ): string {
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
		. get_kind_icon_paths( $kind )
		. '</svg>';
}

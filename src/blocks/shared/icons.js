/**
 * Block Icons
 *
 * SVG icons for reaction blocks.
 *
 * @package Reactions_For_IndieWeb
 */

import { SVG, Path, Circle, Rect } from '@wordpress/primitives';

/**
 * Listen/Music icon
 */
export const listenIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6zm-2 16c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z" />
    </SVG>
);

/**
 * Watch/Video icon
 */
export const watchIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z" />
    </SVG>
);

/**
 * Read/Book icon
 */
export const readIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 4h5v8l-2.5-1.5L6 12V4z" />
    </SVG>
);

/**
 * Checkin/Location icon
 */
export const checkinIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
    </SVG>
);

/**
 * RSVP/Calendar icon
 */
export const rsvpIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z" />
    </SVG>
);

/**
 * Star/Rating icon
 */
export const starIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
    </SVG>
);

/**
 * Star outline icon
 */
export const starOutlineIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z" />
    </SVG>
);

/**
 * Search/Lookup icon
 */
export const searchIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
    </SVG>
);

/**
 * Like/Heart icon
 */
export const likeIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
    </SVG>
);

/**
 * Reply/Comment icon
 */
export const replyIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M21.99 4c0-1.1-.89-2-1.99-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h14l4 4-.01-18zM18 14H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z" />
    </SVG>
);

/**
 * Bookmark icon
 */
export const bookmarkIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z" />
    </SVG>
);

/**
 * Repost icon
 */
export const repostIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z" />
    </SVG>
);

/**
 * Podcast icon
 */
export const podcastIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M12 1c-4.97 0-9 4.03-9 9v7c0 1.66 1.34 3 3 3h3v-8H5v-2c0-3.87 3.13-7 7-7s7 3.13 7 7v2h-4v8h3c1.66 0 3-1.34 3-3v-7c0-4.97-4.03-9-9-9z" />
    </SVG>
);

/**
 * External link icon
 */
export const externalLinkIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z" />
    </SVG>
);

/**
 * Image/Cover icon
 */
export const imageIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
    </SVG>
);

/**
 * Progress icon
 */
export const progressIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" strokeWidth="2" opacity="0.3" />
        <Path
            d="M12 2a10 10 0 0 1 0 20"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
        />
    </SVG>
);

/**
 * Block category icon
 */
export const categoryIcon = (
    <SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <Path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
    </SVG>
);

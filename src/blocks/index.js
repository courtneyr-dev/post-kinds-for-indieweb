/**
 * Reactions for IndieWeb - Block Registration
 *
 * Entry point for all custom Gutenberg blocks.
 *
 * @package Reactions_For_IndieWeb
 */

// WordPress dependencies
import { getCategories, setCategories, registerBlockCollection } from '@wordpress/blocks';

// Custom heart icon (not available in @wordpress/icons)
const heartIcon = (
	<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
	</svg>
);

// Register our custom block category before importing blocks
const categories = getCategories();
const hasCategory = categories.some( ( cat ) => cat.slug === 'reactions-indieweb' );

if ( ! hasCategory ) {
	setCategories( [
		{
			slug: 'reactions-indieweb',
			title: 'Reactions for IndieWeb',
			icon: heartIcon,
		},
		...categories,
	] );
}

// Register block collection for icon/branding in the inserter
registerBlockCollection( 'reactions-indieweb', {
	title: 'Reactions for IndieWeb',
	icon: heartIcon,
} );

// Shared styles for all card blocks
import './shared/card-editor.css';

// Import block registrations
import './listen-card';
import './watch-card';
import './read-card';
import './checkin-card';
import './rsvp-card';
import './play-card';
import './eat-card';
import './drink-card';
import './favorite-card';
import './jam-card';
import './wish-card';
import './mood-card';
import './acquisition-card';
import './checkin-dashboard';
import './star-rating';
import './media-lookup';

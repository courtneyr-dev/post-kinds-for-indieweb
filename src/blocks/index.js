/**
 * Reactions for IndieWeb - Block Registration
 *
 * Entry point for all custom Gutenberg blocks.
 *
 * @package Reactions_For_IndieWeb
 */

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

// Register block category
import { registerBlockCollection } from '@wordpress/blocks';

registerBlockCollection('reactions-for-indieweb', {
    title: 'Reactions for IndieWeb',
    icon: 'heart',
});

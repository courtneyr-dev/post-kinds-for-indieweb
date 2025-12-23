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
import './star-rating';
import './media-lookup';

// Register block category
import { registerBlockCollection } from '@wordpress/blocks';

registerBlockCollection('reactions-indieweb', {
    title: 'Reactions for IndieWeb',
    icon: 'heart',
});

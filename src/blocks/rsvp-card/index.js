/**
 * RSVP Card Block
 *
 * @package Reactions_For_IndieWeb
 */

import { registerBlockType } from '@wordpress/blocks';
import { rsvpIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the RSVP Card block.
 */
registerBlockType(metadata.name, {
    ...metadata,
    icon: rsvpIcon,
    edit: Edit,
    save: Save,
});

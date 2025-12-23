/**
 * Checkin Card Block
 *
 * @package Reactions_For_IndieWeb
 */

import { registerBlockType } from '@wordpress/blocks';
import { checkinIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Checkin Card block.
 */
registerBlockType(metadata.name, {
    ...metadata,
    icon: checkinIcon,
    edit: Edit,
    save: Save,
});

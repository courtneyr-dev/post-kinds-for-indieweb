/**
 * Star Rating Block
 *
 * @package Reactions_For_IndieWeb
 */

import { registerBlockType } from '@wordpress/blocks';
import { starIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Star Rating block.
 */
registerBlockType(metadata.name, {
    ...metadata,
    icon: starIcon,
    edit: Edit,
    save: Save,
});

/**
 * Read Card Block
 *
 * @package Reactions_For_IndieWeb
 */

import { registerBlockType } from '@wordpress/blocks';
import { readIcon } from '../shared/icons';
import Edit from './edit';
import Save from './save';
import metadata from './block.json';

/**
 * Register the Read Card block.
 */
registerBlockType(metadata.name, {
    ...metadata,
    icon: readIcon,
    edit: Edit,
    save: Save,
});
